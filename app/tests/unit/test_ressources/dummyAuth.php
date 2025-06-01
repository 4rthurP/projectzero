<?

namespace pz\Test\Ressources;

use pz\Auth;

class DummyAuth extends Auth
{
    public function setIp(string $ip): void
    {
        $this->ip = $ip;
    }
}