<?php

namespace pz\Routing;

use pz\Routing\Route;

class Action extends Route {
    public function serve(Request $request): Response
    {
        $this->request = $request;

        if ($this->privacy->requiresLogin() && !$request->isAuthenticated()) {
            $authorized = $this->request->authentificateUser();
            if (!$authorized) {
                return $this->respondWithUnauthorized();
            }
        }

        return parent::serve($request);
    }
}