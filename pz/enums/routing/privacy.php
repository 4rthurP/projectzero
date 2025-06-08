<?php

namespace pz\Enums\Routing;

enum Privacy: string {
    case PUBLIC = 'public'; //Everyone can access
    case LOGGED_IN = 'logged_in'; //Only logged in users can access
    case PROTECTED = 'protected'; //Only users owning the ressource and with a valid nonce can access
    case ADMIN = 'admin'; //Only admins can access

    public function requiresLogin() {
        return $this == Privacy::LOGGED_IN || $this == Privacy::PROTECTED || $this == Privacy::ADMIN;
    }

    public function requiresAuth() {
        return $this == Privacy::PROTECTED || $this == Privacy::ADMIN;
    }
}

?>