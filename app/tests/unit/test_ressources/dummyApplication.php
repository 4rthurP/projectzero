<?

namespace pz\Test\Ressources;

use pz\Application;
use pz\Routing\Route;

class DummyApplication extends Application
{
    public function getModules(): array
    {
        return $this->modules;
    }

    public function testRouteBuild(array $route): Route
    {
        return $this->buildRoute(...$route);
    }
}