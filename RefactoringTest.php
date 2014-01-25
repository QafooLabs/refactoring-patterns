<?php

require_once __DIR__ . '/Refactoring.php';

class SearchControllerTest extends \PHPUnit_Framework_TestCase
{
    public function createSolariumClient()
    {
        return new SolariumClient('localhost:8000');
    }

    public function createSearchAdapter()
    {
        return new SearchAdapter($this->createSolariumClient());
    }

    public function createController()
    {
        return new SearchController($this->createSearchAdapter());
    }

    public function testController()
    {
        $ctrl = $this->createController();
        $response = $ctrl->searchAction(new Request());

        $this->assertEquals("ProductBundle:Search:search.html.twig[]", $response->getContent());
    }

    public function testControllerWithSearch()
    {
        $request = new Request();
        $request->set('q', 'Hello');

        $ctrl = $this->createController();
        $response = $ctrl->searchAction($request);

        $this->assertEquals('ProductBundle:Search:search.html.twig{"products":{"0":{"name":"foo","description":"A foo product","price":42},"1":{"name":"bar","description":"A bar product","price":23}}}', $response->getContent());
    }

    public function testControllerWithXmlHttpRequest()
    {
        $request = new Request();
        $request->set('q', 'Hello');
        $request->setRequestFormat('XmlHttpRequest');

        $ctrl = $this->createController();
        $response = $ctrl->searchAction($request);

        $this->assertEquals('ProductBundle:Search:list.html.twig{"products":{"0":{"name":"foo","description":"A foo product","price":42},"1":{"name":"bar","description":"A bar product","price":23}},"noLayout":true}', $response->getContent());
    }

    public function testSearchContorllerWithoutSolarium()
    {
        $request = new Request();

        $ctrl = $this->createController();
        $response = $ctrl->searchAction($request);
    }
}

