<?php

declare(strict_types=1);

namespace pz\Test;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use pz\Test\Ressources\DummyController;
use pz\Test\Ressources\DummyApplication;

use pz\ApplicationModule;
use pz\Enums\Routing\Method;
use pz\Routing\View;
use pz\Routing\Action;
use pz\Enums\Routing\Privacy;

final class ApplicationTest extends TestCase
{
    private DummyApplication $application;

    public static function validPageSignaturesProvider(): array
    {
        // 'page', 'controller', 'method', 'folder', 'uri', 'template', 'privacy'
        return [
            ['index', DummyController::class, null, '../app/tests/unit/test_ressources'],
            ['index', DummyController::class, 'good_method', '../app/tests/unit/test_ressources'],
            ['index', DummyController::class, 'bad_method', '../app/tests/unit/test_ressources', 'index.php', 'index.latte'],
            ['index', null, [[DummyController::class, 'good_method']], '../app/tests/unit/test_ressources', null, 'index.latte', Privacy::PUBLIC],
            ['index', null, [[DummyController::class, 'good_method'], [DummyController::class, 'bad_method']], '../app/tests/unit/test_ressources', 'index.php', 'index.latte', Privacy::PUBLIC],
            ['index', null, ["GET" => [DummyController::class, 'good_method'], "POST" => [DummyController::class, 'bad_method']], '../app/tests/unit/test_ressources', null, 'index.latte', Privacy::PUBLIC],
            ['index', null, ["GET" => [DummyController::class, 'good_method'], "POST" => [[DummyController::class, 'bad_method'], [DummyController::class, 'good_method']]], '../app/tests/unit/test_ressources'],
            ['index', DummyController::class, ["GET" => ['good_method'], "POST" => ['bad_method', 'good_method']], '../app/tests/unit/test_ressources'],
            ['index', DummyController::class, ['good_method', ['bad_method', 'good_method']], '../app/tests/unit/test_ressources'],
            ['index', DummyController::class, ['good_method', 'bad_method'], '../app/tests/unit/test_ressources'],
            ['index', DummyController::class, ['good_method', 'bad_method', 'good_method'], '../app/tests/unit/test_ressources'],
            ['index', DummyController::class, [['good_method'], ['bad_method', 'good_method']], '../app/tests/unit/test_ressources'],
        ];
    }
    
    public static function validPagesWithActionProvider(): array {
        // 'page', 'controller', 'method', 'folder', 'uri', 'template', 'privacy'
        return [
            ['index', null, ["GET" => [DummyController::class, 'good_method'], "POST" => [DummyController::class, 'bad_method']], '../app/tests/unit/test_ressources', null, 'index.latte', Privacy::PUBLIC],
            ['index', null, ["GET" => [DummyController::class, 'good_method'], "POST" => [[DummyController::class, 'bad_method'], [DummyController::class, 'good_method']]], '../app/tests/unit/test_ressources'],
            ['index', DummyController::class, ["GET" => ['good_method'], "POST" => ['bad_method', 'good_method']], '../app/tests/unit/test_ressources'],
            ['index', DummyController::class, ['good_method', ['bad_method', 'good_method']], '../app/tests/unit/test_ressources'],
            ['index', DummyController::class, ['good_method', 'bad_method'], '../app/tests/unit/test_ressources'],
            ['index', DummyController::class, ['good_method', 'bad_method', 'good_method'], '../app/tests/unit/test_ressources'],
            ['index', DummyController::class, [['good_method'], ['bad_method', 'good_method']], '../app/tests/unit/test_ressources'],
        ];

    }

    public function testApplicationCanAddModules(): void
    {
        $this->application->module('default');

        $new_module = new ApplicationModule('admin', Privacy::LOGGED_IN);
        $this->application->addModule($new_module);

        $app_modules = $this->application->getModules();

        // Assert both modules are present
        $this->assertCount(2, $app_modules);

        // Assert both modules are instances of ApplicationModule
        $this->assertInstanceOf(ApplicationModule::class, $app_modules['admin']);
        $this->assertInstanceOf(ApplicationModule::class, $app_modules['default']);

        // Assert privacy levels are set correctly
        $this->assertEquals(Privacy::LOGGED_IN, $app_modules['admin']->getPrivacy());
        $this->assertEquals(null, $app_modules['default']->getPrivacy());
    }
    
    public function testPagesAddition(): void
    {
        $default_module = $this->application->module('default', Privacy::PUBLIC);

        // Minimal page signature
        $default_module->page('index', DummyController::class, folder: '../app/tests/unit/test_ressources');

        $app_actions = $default_module->getActions();
        $app_views = $default_module->getViews();

        // Assert the page was added
        $this->assertArrayHasKey('index.php', $app_views);
        $this->assertArrayNotHasKey('index.php', $app_actions); // No controller method was specified, so it should not be in actions

        $page_params = $app_views['index.php'];
        $page = $this->application->testRouteBuild($page_params);
        $this->assertInstanceOf(View::class, $page);
        $this->assertEquals(Privacy::PUBLIC, $page->getPrivacy());
        $this->assertEquals([Method::GET], $page->getMethods());
        $this->assertTrue($page->hasMethod(Method::GET));
        $this->assertEquals('index.php', $page->getPath());   
        $this->assertInstanceOf(DummyController::class, $page->getController());
        $this->assertEquals('page_index', $page->getFunction()); 
    }

    #[DataProvider('validPageSignaturesProvider')]
    #[DataProvider('validPagesWithActionProvider')]
    public function testValidPageSignatures(
        string $page_name,
        ?string $controller = null,
        mixed $method = null,
        ?string $folder = null,
        ?string $uri = null,
        ?string $template = null,
        ?Privacy $privacy = null
    ): void {
        $default_module = $this->application->module('default', Privacy::PUBLIC);

        // Add the page with the provided parameters
        $default_module->page($page_name, $controller, $method, $folder, $uri, $template, $privacy);

        // Assert the page was added correctly
        $app_views = $default_module->getViews();
        $this->assertArrayHasKey("{$page_name}.php", $app_views);
        $view = $this->application->testRouteBuild($app_views["{$page_name}.php"]);
        $this->assertInstanceOf(View::class, $view);
        $this->assertEquals("{$page_name}.php", $view->getPath());
        $this->assertEquals(Privacy::PUBLIC, $view->getPrivacy());
        $this->assertInstanceOf(DummyController::class, $view->getController());
    }

    #[DataProvider('validPagesWithActionProvider')]
    public function testValidPageSignaturesWithAction(
        string $page_name,
        ?string $controller = null,
        mixed $method = null,
        ?string $folder = null,
        ?string $uri = null,
        ?string $template = null,
        ?Privacy $privacy = null
    ): void {
        $default_module = $this->application->module('default', Privacy::PUBLIC);

        // Add the page with the provided parameters
        $default_module->page($page_name, $controller, $method, $folder, $uri, $template, $privacy);

        // Assert at least one action was added
        $app_actions = $default_module->getActions();
        $page_actions = $app_actions["{$page_name}.php"] ?? null;
        $this->assertNotNull($page_actions);
        $this->assertGreaterThan(0, count($page_actions));

        $action = $this->application->testRouteBuild($page_actions[0]);
        $this->assertInstanceOf(Action::class, $action);
        $this->assertInstanceOf(DummyController::class, $action->getController());
        $this->assertNotNull($action->getFunction());
    }

    public function testApplicationActionsWrappers(): void 
    {
        $default_module = $this->application->module('default', Privacy::PUBLIC);

        $default_module->action('test_action_light', DummyController::class, 'good_method', Method::GET);
        $default_module->action('test_action_complete', DummyController::class, 'bad_method', Method::POST, Privacy::LOGGED_IN, 'success.php', 'error.php');

        $app_actions = $default_module->getActions();

        $this->assertArrayHasKey('test_action_light', $app_actions);
        $action = $this->application->testRouteBuild($app_actions['test_action_light'][0]);
        $this->assertInstanceOf(Action::class, $action);
        $this->assertEquals('good_method', $action->getFunction());
        $this->assertEquals([Method::GET], $action->getMethods());
        $this->assertInstanceOf(DummyController::class, $action->getController());
        $this->assertEquals(Privacy::PUBLIC, $action->getPrivacy());
        $this->assertEquals('test_action_light', $action->getPath());

        $this->assertArrayHasKey('test_action_complete', $app_actions);
        $action = $this->application->testRouteBuild($app_actions['test_action_complete'][0]);
        $this->assertInstanceOf(Action::class, $action);
        $this->assertEquals('bad_method', $action->getFunction());
        $this->assertEquals([Method::POST], $action->getMethods());
        $this->assertInstanceOf(DummyController::class, $action->getController());
        $this->assertEquals(Privacy::LOGGED_IN, $action->getPrivacy());
        $this->assertEquals('test_action_complete', $action->getPath());
    }

    public function testApplicationAPIWrappers(): void 
    {
        $default_module = $this->application->module('default', Privacy::PUBLIC);
        $default_module->api('search_light', DummyController::class, 'good_method');
        $default_module->api('search_complete', DummyController::class, 'good_method', Privacy::PUBLIC);

        $default_module->public_api('search_public', DummyController::class, 'good_method');

        $app_actions = $default_module->getActions();

        $this->assertArrayHasKey('search_light', $app_actions);
        $action = $this->application->testRouteBuild($app_actions['search_light'][0]);
        $this->assertInstanceOf(Action::class, $action);
        $this->assertEquals('good_method', $action->getFunction());
        $this->assertEquals([Method::GET], $action->getMethods());
        $this->assertInstanceOf(DummyController::class, $action->getController());
        $this->assertEquals(Privacy::LOGGED_IN, $action->getPrivacy());
        $this->assertEquals('search_light', $action->getPath());

        $this->assertArrayHasKey('search_complete', $app_actions);
        $action = $this->application->testRouteBuild($app_actions['search_complete'][0]);
        $this->assertInstanceOf(Action::class, $action);
        $this->assertEquals('good_method', $action->getFunction());
        $this->assertEquals([Method::GET], $action->getMethods());
        $this->assertInstanceOf(DummyController::class, $action->getController());
        $this->assertEquals(Privacy::PUBLIC, $action->getPrivacy());
        $this->assertEquals('search_complete', $action->getPath());

        $this->assertArrayHasKey('search_public', $app_actions);
        $action = $this->application->testRouteBuild($app_actions['search_public'][0]);
        $this->assertInstanceOf(Action::class, $action);
        $this->assertEquals('good_method', $action->getFunction());
        $this->assertEquals([Method::GET], $action->getMethods());
        $this->assertInstanceOf(DummyController::class, $action->getController());
        $this->assertEquals(Privacy::PUBLIC, $action->getPrivacy());
        $this->assertEquals('search_public', $action->getPath());
    }

    public function testApplicationViewsWrappers(): void 
    {
        $default_module = $this->application->module('default', Privacy::PUBLIC);

        $default_module->view('view_test', 'test.latte', null, null);
        $default_module->view('view_test_controller', 'test.latte', DummyController::class, 'good_method');
        $default_module->view('view_test_complete', 'test.latte', DummyController::class, 'good_method', Privacy::LOGGED_IN, 'success.php', 'error.php');

        $app_views = $default_module->getViews();

        $this->assertArrayHasKey('view_test', $app_views);
        $view = $this->application->testRouteBuild($app_views['view_test']);
        $this->assertInstanceOf(View::class, $view);
        $this->assertEquals('view_test', $view->getPath());
        $this->assertEquals(Privacy::PUBLIC, $view->getPrivacy());
        $this->assertNull($view->getController());
        $this->assertNull($view->getFunction());

        $this->assertArrayHasKey('view_test_controller', $app_views);
        $view = $this->application->testRouteBuild($app_views['view_test_controller']);
        $this->assertInstanceOf(View::class, $view);
        $this->assertEquals('view_test_controller', $view->getPath());
        $this->assertEquals(Privacy::PUBLIC, $view->getPrivacy());
        $this->assertInstanceOf(DummyController::class, $view->getController());
        $this->assertEquals('good_method', $view->getFunction());
        
        $this->assertArrayHasKey('view_test_complete', $app_views);
        $view = $this->application->testRouteBuild($app_views['view_test_complete']);
        $this->assertInstanceOf(View::class, $view);
        $this->assertEquals('view_test_complete', $view->getPath());
        $this->assertEquals(Privacy::LOGGED_IN, $view->getPrivacy());
        $this->assertInstanceOf(DummyController::class, $view->getController());
        $this->assertEquals('good_method', $view->getFunction());
    }

    public function testApplicationSubModulesPath(): void {
        $this->markTestIncomplete(
            'This test has not been implemented yet.',
        );
    }

    public function testCanAddMultipleActionsToSamePath(): void
    {
        $default_module = $this->application->module('default', Privacy::PUBLIC);

        // Add multiple actions to the same path
        $default_module->action('test_multiple_actions', DummyController::class, 'good_method', Method::GET);
        $default_module->action('test_multiple_actions', DummyController::class, 'bad_method', Method::POST);

        $app_actions = $default_module->getActions();

        // Assert the actions were added correctly
        $this->assertArrayHasKey('test_multiple_actions', $app_actions);
        $this->assertCount(2, $app_actions['test_multiple_actions']);

        foreach ($app_actions['test_multiple_actions'] as $action) {
            $action_instance = $this->application->testRouteBuild($action);
            $this->assertInstanceOf(Action::class, $action_instance);
            $this->assertInstanceOf(DummyController::class, $action_instance->getController());
        }
    }

    public function testCannotAddPageWithUnknownDirectory(): void
    {
        $default_module = $this->application->module('default');

        // Attempt to add a page with an unknown directory
        $this->expectException(\Exception::class);
        $default_module->page('unknown_page', null, null, 'unknown_directory');
    }

    public function testCannotAddPageWithUnknownTemplate(): void
    {
        $default_module = $this->application->module('default');

        // Attempt to add a page with an unknown template
        $this->expectException(\Exception::class);
        $default_module->page('unknown_page', template: 'unknown_template.latte', folder: '../app/tests/unit/test_ressources');
    }
    
    protected function setUp(): void
    {
        $this->application = new DummyApplication("Test Application");
    }
}
