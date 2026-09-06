<?php

namespace pz\Test\Ressources;

use pz\Models\User;

/**
 * Test-only User variant that hashes its password at bcrypt cost 4 instead of the production
 * default (cost 10, PASSWORD_DEFAULT) 
 */
class FastHashUser extends User
{
    protected function initialize()
    {
        parent::initialize();
        $this->setPasswordHashingOptions(PASSWORD_BCRYPT, ['cost' => 4]);
    }
}
