<?php

declare(strict_types=1);

use pz\Enums\Routing\ResponseCode;
use pz\Enums\Routing\Method;
use pz\Enums\Routing\Privacy;
use pz\Routing\Route;
use pz\Routing\Request;
use pz\Test\Ressources\DummyController;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class routeTest extends TestCase
{
    public static function validRoutesProvider(): array
    {
        return [
            [
                'path' => '/test',
                'method' => Method::POST,
                'privacy' => Privacy::PUBLIC,
                'controller' => DummyController::class,
                'function' => 'good_method',
            ],
            [
                'path' => '/test',
                'method' => Method::GET,
                'privacy' => Privacy::PUBLIC,
                'controller' => DummyController::class,
                'function' => 'good_method',
            ],
            [
                'path' => '/test',
                'method' => Method::POST,
                'privacy' => Privacy::LOGGED_IN,
                'controller' => DummyController::class,
                'function' => 'good_method',
            ],
            [
                'path' => '/test',
                'method' => Method::GET,
                'privacy' => Privacy::LOGGED_IN,
                'controller' => DummyController::class,
                'function' => 'good_method',
            ],
            [
                'path' => '/test',
                'method' => Method::POST,
                'privacy' => Privacy::PROTECTED,
                'controller' => DummyController::class,
                'function' => 'good_method',
            ],
            [
                'path' => '/test',
                'method' => Method::GET,
                'privacy' => Privacy::PROTECTED,
                'controller' => DummyController::class,
                'function' => 'good_method',
            ],
        ];
    }

    public static function loggedInProvider(): array
    {
        return [
            [Privacy::LOGGED_IN],
            [Privacy::PROTECTED],
            [Privacy::ADMIN],
        ];
    }

    #[DataProvider('validRoutesProvider')]
    public function testValidRoute(
        string $path,
        Method $method,
        Privacy $privacy,
        string $controller,
        string $function
    ) {
        $route = new Route($path, $method, $privacy, $controller, $function);
        $this->assertEquals($path, $route->getPath());
        $this->assertEquals([$method], $route->getMethods());
        $this->assertEquals($privacy, $route->getPrivacy());
        $this->assertEquals($function, $route->getFunction());
    }

    public function testFunctionIsRequiredWhenAddingAController() {
        $this->expectException(InvalidArgumentException::class);
        $route = new Route('/test', Method::POST, Privacy::PUBLIC, 'controller');
    }

    public function testWrongMethodReturnsAnUnseccessfulResponse()
    {
        $route = new Route('/test', Method::GET, Privacy::PUBLIC);
        $request = new Request(Method::POST, [], '/test');

        $response = $route->check($request);
        $this->assertEquals(ResponseCode::MethodNotAllowed, $response->getResponseCode());
        $this->assertEquals(false, $response->isSuccessful());
    }

    #[DataProvider('loggedInProvider')]
    public function testRequestWithoutUserReturnAnUnsuccessfulResponseOnPrivateRoute($privacy)
    {
        $route = new Route('/test', Method::POST, $privacy);
        $request = new Request(Method::POST, [], '/test');

        $response = $route->check($request);
        $this->assertEquals(ResponseCode::Unauthorized, $response->getResponseCode());
        $this->assertEquals(false, $response->isSuccessful());
    }

    public function testValidRequestProvidesASuccessfulResponseOnCheck()
    {
        $route = new Route('/test', Method::POST, Privacy::PUBLIC);
        $request = new Request(Method::POST, [], '/test');

        $response = $route->check($request);
        $this->assertEquals(ResponseCode::Ok, $response->getResponseCode());
        $this->assertEquals(true, $response->isSuccessful());
    }

    public function testServingAPrivateRouteWithoutUserReturnsAnUnsuccessfulResponse()
    {
        $route = new Route('/test', Method::POST, Privacy::LOGGED_IN);
        $request = new Request(Method::POST, [], '/test');

        $response = $route->serve($request);
        $this->assertEquals(ResponseCode::Unauthorized, $response->getResponseCode());
        $this->assertEquals(false, $response->isSuccessful());
    }

    public function testSetPrivacyOverwrittesDefaultPrivacy()
    {
        $route = new Route('/test', Method::POST, Privacy::LOGGED_IN);
        $route->setPrivacy(Privacy::PUBLIC);
        $this->assertEquals(Privacy::PUBLIC, $route->getPrivacy());
    }

    public function testSetMethodsOverwrittesDefaultMethods()
    {
        $route = new Route('/test', Method::POST, Privacy::LOGGED_IN);
        $route->setMethods([Method::GET]);
        $this->assertEquals([Method::GET], $route->getMethods());
    }

    public function testSetMethodsThrowsExceptionOnInvalidMethod()
    {
        $this->expectException(TypeError::class);
        $route = new Route('/test', Method::POST, Privacy::LOGGED_IN);
        $route->setMethods(['INVALID']);
    }

    public function testAddMethod()
    {
        $route = new Route('/test', Method::POST, Privacy::LOGGED_IN);
        $route->addMethod(Method::GET);
        $this->assertEquals([Method::POST, Method::GET], $route->getMethods());
    }

    public function testAddMethodThrowsExceptionOnInvalidMethod()
    {
        $this->expectException(TypeError::class);
        $route = new Route('/test', Method::POST, Privacy::LOGGED_IN);
        $route->addMethod('INVALID');
    }

    public function testSetPath()
    {
        $route = new Route('/test', Method::POST, Privacy::LOGGED_IN);
        $route->setPath('/newpath');
        $this->assertEquals('/newpath', $route->getPath());
    }



}
