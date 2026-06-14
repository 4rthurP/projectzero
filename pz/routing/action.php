<?php

namespace pz\Routing;

use Exception;

use pz\Routing\Route;

class Action extends Route {
    public function serve(Request $request): Response
    {
        if(!$this->was_checked) {
            throw new Exception('Route : ' . $this->path . ' - The route must be checked before serving the request. Call the check() method first.');
        }

        $this->request = $request;

        // Above requiring login (verified in check step), an action can require authentication for certain methods (POST, PUT, DELETE)
        // i.e., the use of a nonce to avoid CSRF attacks. 
        if ($this->requires_authentication && !$request->isAuthenticated()) {
            $authorized = $this->request->authentificateUser();
            if (!$authorized) {
                return $this->respondWithUnauthorized();
            }
        }

        return $this->serve_request();
    }
}