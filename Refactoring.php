<?php

abstract class SearchResultRenderer
{
    abstract public function listResponse($paginator);
    abstract public function formResponse();

    public function render($template, $variables)
    {
        return new Response($template . json_encode($variables));
    }
}

class XmlHttpResultRenderer extends SearchResultRenderer
{
    public function listResponse($paginator)
    {
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

    public function formResponse()
    {
        return $this->render('ProductBundle:Search:search.html.twig', array(
            'noLayout' => true,
        ));
    }
}

class HtmlSearchResultRenderer extends SearchResultRenderer
{
    public function listResponse($paginator)
    {
        return $this->render('ProductBundle:Search:search.html.twig', array(
            'products' => $paginator->getResults(),
        ));
    }

    public function formResponse()
    {
        return $this->render('ProductBundle:Search:search.html.twig', array());
    }
}

class SearchAdapter
{
    private $solarium;

    public function __construct(SolariumClient $client)
    {
        $this->solarium = $client;
    }

    protected function createPagerfanta($select)
    {
        $paginator = new Pagerfanta(
            new SolariumAdapter(
                $this->solarium, $select
            )
        );

        return $paginator;
    }

    public function search($req, $typeFilter, $tagsFilter)
    {
        $solarium = $this->solarium;
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

        $paginator = $this->createPagerfanta($select);
        $paginator->setMaxPerPage(15);

        return $paginator;
    }
}

class SearchController extends Controller
{
    private $search;

    public function __construct(SearchAdapter $search = null)
    {
        $this->search = $search;
    }

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
            $paginator = $this->search->search($req, $typeFilter, $tagsFilter);

            $paginator->setCurrentPage($req->get('page', 1), false, true);

            return $this->respondTo($req)->listResponse($paginator);
        }

        return $this->respondTo($req)->formResponse();
    }

    protected function respondTo($req)
    {
        if ($req->isXmlHttpRequest()) {
            return new XmlHttpResultRenderer();
        }

        return new HtmlSearchResultRenderer();
    }

    protected function createSearchXmlHttpResponse($paginator)
    {
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

    protected function createSearchXmlHttpFormResponse()
    {
        return $this->render('ProductBundle:Search:search.html.twig', array(
            'noLayout' => true,
        ));
    }

    protected function createSearchHtmlResponse($paginator)
    {
        return $this->render('ProductBundle:Search:search.html.twig', array(
            'products' => $paginator->getResults(),
        ));
    }

    protected function createSearchHtmlFormResponse()
    {
        return $this->render('ProductBundle:Search:search.html.twig', array());
    }
}

abstract class Controller
{
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
