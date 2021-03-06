<?php

use Midweste\SlimRedirects\RedirectController;
use Midweste\SlimRedirects\RedirectRule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\UriFactory;
use Slim\Http\Uri;

class SlimRedirectsTest extends TestCase
{

    private $scheme = 'http';
    private $port = 80;
    private $host = 'localhost';
    private $path = '/';
    private $query = 'one=value&another=value';

    private function loadRedirects()
    {
        $redirects = json_decode(file_get_contents(__DIR__ . '/slimredirects.json'))->redirects;
        return $redirects;
    }

    private function loadOptions()
    {
        return json_decode(file_get_contents(__DIR__ . '/slimoptions.json'), true);
    }

    private function mockRequest(UriInterface $uri, array $params = []): ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        //$serverRequest = $factory->createFromGlobals();
        $serverRequest = $factory->createServerRequest('GET', $uri, $params);
        return $serverRequest;
    }

    private function mockResponse(): ResponseInterface
    {
        $factory = new ResponseFactory();
        $response = $factory->createResponse();
        return $response;
    }

    private function slimRedirectController($uri = null, ?array $redirects = [], ?array $options = []): RedirectController
    {
        $testUri = (!empty($uri)) ? $uri : $this->scheme . '://' . $this->host . ':' . $this->port . $this->path . '?' . $this->query;
        $uri = (new UriFactory())->createUri($testUri);

        $server['SERVER_PROTOCOL'] = $uri->getScheme();
        $server['SERVER_PORT'] = $uri->getPort();
        $server['HTTP_HOST'] = $uri->getHost();
        $server['REQUEST_URI'] = $uri->getPath();
        $server['QUERY_STRING'] = $uri->getQuery();
        // $server['REMOTE_ADDR'] = '127.0.0.1';
        // $server['REQUEST_METHOD'] = 'GET';
        $server['HTTP_USER_AGENT'] = 'phpunit';
        foreach ($server as $key => $value) {
            $_SERVER[$key] = $value;
        }

        $options = (!empty($options)) ? $options : $this->loadOptions();
        $request = $this->mockRequest($uri, $server);
        $controller = new RedirectController($request, $this->mockResponse(), $redirects, $options);
        return $controller;
    }

    private function slimRedirectWithController(RedirectController $controller): object
    {
        $request = $controller->getRequest();
        $response = $controller->redirectProcess();
        $responseStatus = ($response) ? $response->getStatusCode() : null;
        $location = (!is_null($response) && $response->hasHeader('location')) ? (new UriFactory())->createUri($response->getHeaderLine('location')) : null;
        $locationUri = (!is_null($location)) ? (string) $location : null;
        $redirects = $controller->getRedirects();
        $options = $controller->getOptions();

        $result = new \stdClass();
        $result->request = $request;
        $result->controller = $controller;
        $result->responseStatus = $responseStatus;
        $result->response = $response;
        $result->locationUri = $location;
        $result->location = $locationUri;
        $result->redirects = $redirects;
        $result->options = $options;

        return $result;
    }

    private function slimRedirect($uri = null, ?array $redirects = [], ?array $options = []): object
    {
        $controller = $this->slimRedirectController($uri, $redirects, $options);
        return $this->slimRedirectWithController($controller);
    }

    /**
     * Tests
     */

    public function testCreation()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "path",
            "destination" => "/root",
            "httpStatus" => 302,
            "active" => 1
        ];

        $controller = $this->slimRedirectController('http://localhost/');
        $this->assertInstanceOf(RedirectController::class, $controller);

        $controller = $this->slimRedirectController('http://localhost/', [$rule]);
        $this->assertInstanceOf(RedirectController::class, $controller);

        $controller = $this->slimRedirectController('http://localhost/', [$rule], $this->loadOptions());
        $this->assertInstanceOf(RedirectController::class, $controller);
    }

    public function testCreateFactory()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "path",
            "destination" => "/root",
            "httpStatus" => 302,
            "active" => 1
        ];
        $controller = $this->slimRedirectController('https://localhost/nomatch?query=string');
        $instance = $controller::factory($controller->getRequest(), $controller->getResponse(), [$rule]);
        $cachedInstance = $controller::factory($controller->getRequest(), $controller->getResponse(), [$rule]);
        $this->assertEquals($instance, $cachedInstance);
    }

    public function testFullUrlPathRedirect()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "path",
            "destination" => "https://example.com?new=querystring",
            "httpStatus" => 302,
            "active" => 1
        ];
        $result = $this->slimRedirect('https://localhost', [$rule]);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals($result->location, 'https://example.com/?new=querystring');
    }

    public function testFullUrlCombinedQs()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "path",
            "destination" => "https://example.com?new=querystring",
            "httpStatus" => 302,
            "active" => 1
        ];
        $result = $this->slimRedirect('https://localhost/?query=string', [$rule]);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals($result->location, 'https://example.com/?new=querystring&query=string');
    }

    public function testOptionDisabled()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "path",
            "destination" => "/root",
            "httpStatus" => 302,
            "active" => 1
        ];
        $options = $this->loadOptions();
        $options['enabled'] = false;
        $result = $this->slimRedirect('http://localhost/', [$rule], $options);
        $this->assertEquals(null, $result->locationUri);
        $this->assertEquals(null, $result->responseStatus);
    }

    public function testOptionForceHttps()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "path",
            "destination" => "/root",
            "httpStatus" => 302,
            "active" => 1
        ];
        $options = $this->loadOptions();
        $options['forcehttps'] = true;
        $result = $this->slimRedirect('http://localhost/', [$rule], $options);
        $this->assertEquals('https', $result->locationUri->getScheme());
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
    }

    public function testSetForceHttps()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "path",
            "destination" => "/root",
            "httpStatus" => 302,
            "active" => 1
        ];
        $options = $this->loadOptions();
        $options['forcehttps'] = false;

        $controller = $this->slimRedirectController('http://localhost/', [], $options);
        $result = $this->slimRedirectWithController($controller);
        $this->assertEquals(null, $result->location);
        $this->assertEquals(null, $result->responseStatus);

        $controller->setForceHttps(true);
        $result = $this->slimRedirectWithController($controller);
        $this->assertEquals('https', $result->locationUri->getScheme());
        $this->assertEquals(302, $result->responseStatus);
    }

    public function testOptionNonExistant()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "path",
            "destination" => "/root",
            "httpStatus" => 302,
            "active" => 1
        ];

        $controller = $this->slimRedirectController('https://localhost/nomatch?query=string');
        $this->expectException(Exception::class);
        $controller->getOption('nonexistant');
    }

    public function testTypeHandlerInvalid()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "path",
            "destination" => "/root",
            "httpStatus" => 302,
            "active" => 1
        ];

        $controller = $this->slimRedirectController('https://localhost/nomatch?query=string');
        $this->expectException(Exception::class);
        $controller->getTypeHandler('wontfind');
    }

    public function testRedirectNoRedirects()
    {
        $result = $this->slimRedirect('https://localhost/nomatch?query=string');
        $this->assertEquals(null, $result->responseStatus);
        $this->assertEquals(null, $result->location);
    }

    public function testRedirectFilterNonType()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "notsupported",
            "destination" => "/root",
            "httpStatus" => 302,
            "active" => 1
        ];
        $result = $this->slimRedirect('https://localhost/nomatch?query=string', [$rule]);
        $this->assertEquals(null, $result->responseStatus);
        $this->assertEquals(null, $result->location);
    }

    public function testRedirectFilterNonActive()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "notsupported",
            "destination" => "/root",
            "httpStatus" => 302,
            "active" => 0
        ];
        $result = $this->slimRedirect('https://localhost/nomatch?query=string', [$rule]);
        $this->assertEquals(null, $result->responseStatus);
        $this->assertEquals(null, $result->location);
    }

    public function testHookNewHandler()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "handler",
            "destination" => "/root",
            "httpStatus" => 302,
            "active" => 1
        ];
        $controller = $this->slimRedirectController('https://localhost/nomatch?query=string', [$rule]);
        $controller->setTypeHandler('handler', function ($request) {
            return $request;
        });
        $result = $this->slimRedirectWithController($controller);
        $this->assertEquals(null, $result->responseStatus);
        $this->assertEquals(null, $result->location);
    }

    public function testExcludes()
    {
        $rule = [
            "id" => "1",
            "source" => "/excluded",
            "type" => "path",
            "destination" => "/root",
            "httpStatus" => 302,
            "active" => 1
        ];
        $controller = $this->slimRedirectController('https://localhost/nomatch?query=string', [$rule]);
        $controller->setExcludes(['/excluded']);

        $result = $this->slimRedirectWithController($controller);
        $this->assertEquals(null, $result->responseStatus);
        $this->assertEquals(null, $result->location);
    }

    public function testRedirectNonMatch()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "path",
            "destination" => "/root",
            "httpStatus" => 302,
            "active" => 1
        ];
        $result = $this->slimRedirect('https://localhost/nomatch?query=string', [$rule]);
        $this->assertEquals(null, $result->responseStatus);
        $this->assertEquals(null, $result->location);
    }

    public function testRedirectRootRedirect()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "path",
            "destination" => "/root?new=querystring",
            "httpStatus" => 302,
            "active" => 1
        ];
        $result = $this->slimRedirect('https://localhost/?query=string', [$rule]);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals($result->location, 'https://localhost/root?new=querystring&query=string');
    }

    public function testTrailingSlash()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "path",
            "destination" => "/root/?new=querystring",
            "httpStatus" => 302,
            "active" => 1
        ];
        $result = $this->slimRedirect('https://localhost/?query=string', [$rule]);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals($result->location, 'https://localhost/root/?new=querystring&query=string');

        $rule = [
            "id" => "1",
            "source" => "/wild/card/*/?old=querystring",
            "type" => "path",
            "destination" => "/root/?new=querystring",
            "httpStatus" => 302,
            "active" => 1
        ];
        $result = $this->slimRedirect('https://localhost/wild/card/random?query=string', [$rule]);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals($result->location, 'https://localhost/root/?new=querystring&query=string');
    }

    public function testOnlyDestinationHasQs()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "path",
            "destination" => "/root/?key=newvalue",
            "httpStatus" => 302,
            "active" => 1
        ];
        $result = $this->slimRedirect('https://localhost', [$rule]);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals($result->location, 'https://localhost/root/?key=newvalue');
    }

    public function testCombinedQsOverwrite()
    {
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "path",
            "destination" => "/root/?key=newvalue",
            "httpStatus" => 302,
            "active" => 1
        ];
        $result = $this->slimRedirect('https://localhost/?key=value&other=value', [$rule]);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals($result->location, 'https://localhost/root/?key=newvalue&other=value');
    }

    public function testTrailingSlashOnSource()
    {
        $rule = [
            "id" => "1",
            "source" => "/trailing/",
            "type" => "path",
            "destination" => "/root/?new=querystring",
            "httpStatus" => 302,
            "active" => 1
        ];
        $result = $this->slimRedirect('https://localhost/trailing?query=string', [$rule]);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals($result->location, 'https://localhost/root/?new=querystring&query=string');

        $rule = [
            "id" => "1",
            "source" => "/trailing",
            "type" => "path",
            "destination" => "/root/?new=querystring",
            "httpStatus" => 302,
            "active" => 1
        ];
        $result = $this->slimRedirect('https://localhost/trailing/?query=string', [$rule]);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals($result->location, 'https://localhost/root/?new=querystring&query=string');
    }

    public function testRedirectWildcardPath()
    {
        // relative wildcard with token placement
        $rule =
            [
                "id" => 2,
                "source" => "/wild/*/card",
                "type" => "path",
                "destination" => "/wildcard/*",
                "httpStatus" => 302,
                "active" => 1
            ];
        $result = $this->slimRedirect('https://localhost/wild/test/card?query=string', [$rule]);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals('https://localhost/wildcard/test?query=string', $result->location);

        // wilcard with host and token placement
        $rule = [
            "id" => 1,
            "source" => "/fullurl/wild/*/card",
            "type" => "path",
            "destination" => "https://example.com/wildcard/*",
            "httpStatus" => 302,
            "active" => 1
        ];
        $result = $this->slimRedirect('https://localhost/fullurl/wild/test/card?query=string', [$rule]);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals('https://example.com/wildcard/test?query=string', $result->location);


        // wildcard with host and no wildcard placement
        $rule = [
            "id" => 3,
            "source" => "/nowc/wild/*/card",
            "type" => "path",
            "destination" => "https://example.com/wildcard",
            "httpStatus" => 302,
            "active" => 1
        ];
        $result = $this->slimRedirect('https://localhost/nowc/wild/test/card?query=string', [$rule]);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals('https://example.com/wildcard?query=string', $result->location);

        // wildcard skip when request doesnt match up until first *
        $rules = [
            [
                "id" => 3,
                "source" => "/nomatch/wild/*/card",
                "type" => "path",
                "destination" => "https://example.com/wildcard",
                "httpStatus" => 302,
                "active" => 1
            ],
            [
                "id" => 4,
                "source" => "/nowc/wild/*/card",
                "type" => "path",
                "destination" => "https://example.com/wildcard",
                "httpStatus" => 302,
                "active" => 1
            ]
        ];
        $result = $this->slimRedirect('https://localhost/nowc/wild/test/card?query=string', $rules);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals('https://example.com/wildcard?query=string', $result->location);
    }

    public function testNoCacheOn302()
    {
        // 'Cache-Control', 'no-store,no-cache'
        // 'Pragma', 'no-cache'
        // 'Expires', strtotime('Yesterday')
        $rule = [
            "id" => "1",
            "source" => "/",
            "type" => "path",
            "destination" => "/root",
            "httpStatus" => 302,
            "active" => 1
        ];
        $result = $this->slimRedirect('https://localhost/?query=string', [$rule]);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals($result->location, 'https://localhost/root?query=string');
        $this->assertEquals('no-store,no-cache', $result->response->getHeaderLine('Cache-Control'));
        $this->assertEquals('no-cache', $result->response->getHeaderLine('Pragma'));
        $this->assertGreaterThanOrEqual(strtotime('Yesterday'), strtotime($result->response->getHeaderLine('Expires')));
    }

    public function testParseDestination()
    {
        $rule = [
            "id" => "1",
            "source" => "/wild/*/card",
            "type" => "path",
            "destination" => "/wildcard",
            "httpStatus" => 302,
            "active" => 1
        ];
        $controller = $this->slimRedirectController('https://localhost/wild/test/card?query=string', [$rule]);
        $result = $this->slimRedirectWithController($controller);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals('https://localhost/wildcard?query=string', $result->location);
    }

    public function testRegressionPort80RemainedWhenForcingHttps()
    {
        $rule = [
            "id" => "1",
            "source" => "/wild/*/card",
            "type" => "path",
            "destination" => "/wildcard",
            "httpStatus" => 302,
            "active" => 1
        ];
        $controller = $this
            ->slimRedirectController('http://localhost:80/wild/test/card?query=string', [$rule])
            ->setForceHttps(true);
        $this->assertEquals(true, $controller->getForceHttps());

        $result = $this->slimRedirectWithController($controller);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals('https://localhost/wildcard?query=string', $result->location);
    }

    public function testRegressionForceHttpsButNoMatches()
    {
        $rule = [
            "id" => "1",
            "source" => "/wild/*/card",
            "type" => "path",
            "destination" => "/wildcard",
            "httpStatus" => 302,
            "active" => 1
        ];
        $controller = $this
            ->slimRedirectController('http://localhost/notmatched?query=string', [$rule])
            ->setForceHttps(true);
        $this->assertEquals(true, $controller->getForceHttps());

        $result = $this->slimRedirectWithController($controller);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals('https://localhost/notmatched?query=string', $result->location);
    }

    public function testGetAvailableHooks()
    {
        $rule = [
            "id" => "1",
            "source" => "/wild/*/card",
            "type" => "path",
            "destination" => "/wildcard",
            "httpStatus" => 302,
            "active" => 1
        ];
        $controller = $this->slimRedirectController('http://localhost:80/wild/test/card?query=string', [$rule]);
        $definedHooks = $controller->getHooksAvailable();
        $this->assertArrayHasKey('pre_redirect_filter', $definedHooks);
    }

    public function testCreateRequestFromGlobals()
    {
        $request = RedirectController::createRequestFromGlobals();
        $this->assertInstanceOf(RequestInterface::class, $request);
    }

    public function testCreateResponse()
    {
        $request = RedirectController::createResponse();
        $this->assertInstanceOf(ResponseInterface::class, $request);
    }


    /**
     * @runInSeparateProcess
     */
    public function testRedirectEmitReponse()
    {
        $rule = [
            "id" => "1",
            "source" => "/wild/*/card",
            "type" => "path",
            "destination" => "/wildcard/*",
            "httpStatus" => 302,
            "active" => 1
        ];
        $controller = $this
            ->slimRedirectController('https://localhost/wild/test/card?query=string', [$rule])
            ->setHook('pre_redirect_filter', function ($request) {
                return $request;
            });

        $result = $this->slimRedirectWithController($controller);
        $this->assertEquals($rule['httpStatus'], $result->responseStatus);
        $this->assertEquals($result->location, 'https://localhost/wildcard/test?query=string');

        $result = $controller->emitResponse($result->response);
        $this->assertTrue($result);
    }
}
