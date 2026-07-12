<?php

namespace pz\Controllers;

use Exception;
use pz\Auth;
use pz\Config;
use pz\Enums\Routing\ResponseCode;
use pz\ModelController;
use pz\Routing\Request;
use pz\Routing\Response;
use pz\Services\UserService;

class UserController extends ModelController
{
    protected $login_default_success_location = '/index.php';
    protected $login_default_failure_location = '/index.php';
    protected $register_default_success_location = '/index.php';
    protected $register_default_failure_location = '/register.php';

    public function __construct()
    {
        parent::__construct();
        $this->setService(UserService::class);
    }

    public function get_user_infos(Request $request)
    {
        if (!$request->hasUser()) {
            throw new Exception('User id is not set in the session');
        }

        return $request->user()->toArray();
    }

    public function login(Request $request)
    {
        $auth = new Auth($request->data());
        $auth->loginFromForm();

        if ($auth->isLoggedIn()) {
            return new Response(true, ResponseCode::Ok, 'logged-in', 'index.php', [
                'user_id' => $auth->user_id,
                'session_token' => $auth->getSessionToken(),
            ]);
        }

        return new Response(false, ResponseCode::Unauthorized, $auth->getError());
    }

    public function register(Request $request): Response
    {
        $response = parent::create($request);
        if (!$response->success) {
            return $response;
        }

        //Auto login after registration
        return $this->login($request);
    }

    public function get_nonce(Request $request): Response
    {
        // Auth was passed as Bearer token and was taken care of by the app
        if ($request->isLoggedIn()) {
            $auth = $request->getAuth();
        }
        // Auth was passed as a credential in the request body, we need to handle it here
        else {
            $credential = $request->data('credential');
            if (!$credential) {
                return new Response(
                    false,
                    ResponseCode::Unauthorized,
                    'missing-credential',
                    message: 'No credential provided',
                );
            }

            $auth = new Auth($request->data());
            $auth->loginFromSessionToken($credential);
            if (!$auth->isLoggedIn()) {
                return new Response(false, ResponseCode::Unauthorized, $auth->getError());
            }
        }

        $auth->retrieveUserNonce();
        $request->setAuth($auth);

        // Route will see the request is authenticated and will send a new nonce back to the client for the next request.
        return new Response(true, ResponseCode::Ok, 'retrieved-nonce', message: 'Nonce retrieved successfully');
    }

    public function logout(): Response
    {
        Auth::logout();
        return new Response(true, ResponseCode::Ok, 'User logged out', 'index.php');
    }
}
