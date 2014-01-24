<?php

class SearchControllerTest extends \PHPUnit_Framework_TestCase
{
    public function testController()
    {
        $ctrl = new SearchController();
        $response = $ctrl->searchAction(new Request());

        $this->assertEquals("ProductBundle:Search:search.html.twig[]", $response->getContent());
    }

    public function testControllerWithSearch()
    {
        $request = new Request();
        $request->set('q', 'Hello');

        $ctrl = new SearchController();
        $response = $ctrl->searchAction($request);

        $this->assertEquals('ProductBundle:Search:search.html.twig{"products":{"0":{"name":"foo","description":"A foo product","price":42},"1":{"name":"bar","description":"A bar product","price":23}}}', $response->getContent());
    }

    public function testControllerWithJsonSearch()
    {
        $request = new Request();
        $request->set('q', 'Hello');
        $request->setRequestFormat('json');

        $ctrl = new SearchController();
        $response = $ctrl->searchAction($request);

        $this->assertEquals(array (
            'results' => array(
                array(
                    'name' => 'foo',
                    'description' => 'A foo product',
                    'price' => 42,
                    'url' => '/product/foo',
                ), array(
                    'name' => 'bar',
                    'description' => 'A bar product',
                    'price' => 23,
                    'url' => '/product/bar',
                ),
            ),
            'total' => 2,
            'next' => '/search',
        ), $response->getContent());
    }

    public function testControllerWithXmlHttpRequest()
    {
        $request = new Request();
        $request->set('q', 'Hello');
        $request->setRequestFormat('XmlHttpRequest');

        $ctrl = new SearchController();
        $response = $ctrl->searchAction($request);

        $this->assertEquals('ProductBundle:Search:list.html.twig{"products":{"0":{"name":"foo","description":"A foo product","price":42},"1":{"name":"bar","description":"A bar product","price":23}},"noLayout":true}', $response->getContent());
    }
}

class SearchController extends Controller
{
    /**
     * @param Request $request
     *
     * @return Response
     */
    public function searchAction(Request $req)
    {
        $typeFilter = $req->get('type');
        $tagsFilter = $req->get('tags');

        if ($req->has('q') || $typeFilter || $tagsFilter) {
            $solarium = new SolariumClient('localhost:8080');
            $select = $solarium->createSelect();

            // configure dismax
            $dismax = $select->getDisMax();
            $dismax->setQueryFields(array('name^4', 'description', 'tags', 'text', 'text_ngram', 'name_split^2'));
            $dismax->setPhraseFields(array('description'));
            $dismax->setBoostFunctions(array('log(trendiness)^10'));
            $dismax->setMinimumMatch(1);
            $dismax->setQueryParser('edismax');

            // filter by type
            if ($typeFilter) {
                $filterQueryTerm = sprintf('type:%s', $select->getHelper()->escapeTerm($typeFilter));
                $filterQuery = $select->createFilterQuery('type')->setQuery($filterQueryTerm);
                $select->addFilterQuery($filterQuery);
            }

            // filter by tags
            if ($tagsFilter) {
                $tags = array();
                foreach ((array) $tagsFilter as $tag) {
                    $tags[] = $select->getHelper()->escapeTerm($tag);
                }
                $filterQueryTerm = sprintf('tags:(%s)', implode(' AND ', $tags));
                $filterQuery = $select->createFilterQuery('tags')->setQuery($filterQueryTerm);
                $select->addFilterQuery($filterQuery);
            }

            if ($req->has('q')) {
                $escapedQuery = $select->getHelper()->escapeTerm($req->get('q'));
                $select->setQuery($escapedQuery);
            }

            $paginator = new Pagerfanta(new SolariumAdapter($solarium, $select));
            $paginator->setMaxPerPage(15);
            $paginator->setCurrentPage($req->get('page', 1), false, true);

            if ($req->getRequestFormat() === 'json') {
                try {
                    $result = array(
                        'results' => array(),
                        'total' => $paginator->getNbResults(),
                    );
                } catch (\Solarium_Client_HttpException $e) {
                    return new JsonResponse(array(
                        'status' => 'error',
                        'message' => 'Could not connect to the search server',
                    ), 500);
                }

                foreach ($paginator->getResults() as $product) {
                    $url = $this->generateUrl('view_product', array('name' => $product->name), true);

                    $result['results'][] = array(
                        'name' => $product->name,
                        'description' => $product->description ?: '',
                        'price' => $product->price,
                        'url' => $url,
                    );
                }

                if ($paginator->hasNextPage()) {
                    $params = array(
                        '_format' => 'json',
                        'q' => $req->get('q'),
                        'page' => $paginator->getNextPage()
                    );
                    if ($tagsFilter) {
                        $params['tags'] = (array) $tagsFilter;
                    }
                    if ($typeFilter) {
                        $params['type'] = $typeFilter;
                    }
                    $result['next'] = $this->generateUrl('search', $params, true);
                }

                return new JsonResponse($result);
            }

            if ($req->isXmlHttpRequest()) {
                try {
                    return $this->render('ProductBundle:Search:list.html.twig', array(
                        'products' => $paginator->getResults(),
                        'noLayout' => true,
                    ));
                } catch (\Twig_Error_Runtime $e) {
                    if (!$e->getPrevious() instanceof \Solarium_Client_HttpException) {
                        throw $e;
                    }
                    return new JsonResponse(array(
                        'status' => 'error',
                        'message' => 'Could not connect to the search server',
                    ), 500);
                }
            }

            return $this->render('ProductBundle:Search:search.html.twig', array(
                'products' => $paginator->getResults(),
            ));
        }

        if ($req->isXmlHttpRequest()) {
            return $this->render('ProductBundle:Search:search.html.twig', array(
                'noLayout' => true,
            ));
        }

        if ($req->getRequestFormat() === 'json') {
            return new JsonResponse(array('error' => 'Missing search query, example: ?q=example'), 400);
        }

        return $this->render('ProductBundle:Search:search.html.twig', array());
    }


}

abstract class Controller
{
    public function render($template, $variables)
    {
        return new Response($template . json_encode($variables));
    }

    public function generateUrl($route, $variables)
    {
        if ($route == 'search') {
            return '/search';
        }

        return '/product/' . $variables['name'];
    }
}

class Request
{
    private $variables = array();
    private $requestFormat = 'html';

    public function has($name)
    {
        return isset($this->variables[$name]);
    }

    public function get($name)
    {
        if (!$this->has($name)) {
            return null;
        }

        return $this->variables[$name];
    }

    public function set($name, $value)
    {
        $this->variables[$name] = $value;
    }

    public function getRequestFormat()
    {
        return $this->requestFormat;
    }

    public function setRequestFormat($format)
    {
        $this->requestFormat = $format;
    }

    public function isXmlHttpRequest()
    {
        return ($this->requestFormat === 'XmlHttpRequest');
    }
}

class Response
{
    public function __construct($content)
    {
        $this->content = $content;
    }

    public function getContent()
    {
        return $this->content;
    }
}

class JsonResponse extends Response
{
}

class SolariumClient
{
    public function createSelect()
    {
        return new SolariumSelect();
    }
}
class SolariumSelect
{
    public function __call($method, $args)
    {
        return $this;
    }
}

class Pagerfanta
{
    public function __call($method, $args)
    {
        return $this;
    }

    public function getNbResults()
    {
        return 2;
    }

    public function getResults()
    {
        return new ArrayIterator(array(
            new Product('foo', 'A foo product', 42),
            new Product('bar', 'A bar product', 23),
        ));
    }
}

class SolariumAdapter
{
}

class Product
{
    public $name;
    public $description;
    public $price;

    public function __construct($name, $description, $price)
    {
        $this->name = $name;
        $this->description = $description;
        $this->price = $price;
    }
}
