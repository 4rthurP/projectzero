<?php

namespace pz\Routing;

use Exception;
use pz\Enums\Routing\{Privacy, Method, ResponseCode};
use pz\Routing\{Route, Response, Request};
use pz\Models\User;


class Action extends Route {
    /**
     * Checks the request and validates the route and authentication.
     *
     * @param Request $request The incoming request to be checked.
     * 
     * @return Response The response object indicating the result of the check.
     * 
     * The method performs the following checks:
     * 1. Calls the parent check method to validate the parent route.
     * 2. If the parent check is successful, it returns the parent check response.
     * 3. If the privacy level requires authentication:
     *    - Checks if the request has authentication.
     *    - If not authenticated, returns an unauthorized response.
     *    - Authenticates the user using the provided credentials.
     *    - If the user is not logged in, returns an unauthorized response.
     * 4. If all checks pass, returns a successful response.
     */
    public function check(Request $request): Response {
        // Check if the parent route is valid
        $parent_check = parent::check($request);
        if($parent_check->success) {
            return $parent_check;
        }

        // Check if the privacy level requires authentication
        if($this->privacy->requiresAuth()){
            if(!$request->hasAuth()) {
                return new Response(false, ResponseCode::Unauthorized, null, 'Unauthorized request');
            }
            
            $user = User::authentificate($request->user(), $request->nonce(), $request->credential());
    
            if(!$user->isLoggedIn()) {
                return new Response(false, ResponseCode::Unauthorized, null, 'not-logged-in');
            }
        } 

        return new Response(true, ResponseCode::Ok);
    }

    /**
     * Serve the request using the parent serve method.
     * Since this is an action, we also set the nonce if the user is logged in.
     *
     * @param Request $request The incoming request.
     * @return Response The response with a nonce set if the user is logged in.
     */
    public function serve(Request $request): Response {
        $response = parent::serve($request);
        if(isset($_SESSION['user'])) {
            $response->setNonce($_SESSION['user']);
        }

        return $response;
    }
}