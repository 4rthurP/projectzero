<?php

namespace pz\Controllers;

use Exception;

use pz\Auth;
use pz\ModelController;
use pz\Models\User;
use pz\Routing\Request;
use pz\Routing\Response;
use pz\Enums\Routing\ResponseCode;

class UserController extends ModelController
{

    protected $login_default_success_location = '/index.php';
    protected $login_default_failure_location = '/index.php';
    protected $register_default_success_location = '/index.php';
    protected $register_default_failure_location = '/register.php';

    public function __construct()
    {
        parent::__construct();
        $this->setModel(User::class);
    }

    public function get_user_infos(Request $request)
    {
        if(!$request->hasUser()) {
            throw new Exception($_ENV['ENV'] == "DEV" ? 'User id is not set in the session' : 'User id is not set in the session');
        }

        return $request->user()->toArray();
    }

    public function login(Request $request) {
        $auth = new Auth($request->data());
        $auth->login();

        if($auth->isLoggedIn()) {
            #TODO: add default route location + from header
            return new Response(true, ResponseCode::Ok,'logged-in', 'index.php');
        }
        
        return new Response(
            false,
            ResponseCode::Unauthorized,
            $auth->getError(),
        );
    }

    public function register(Request $request): Response {
        $response = parent::create($request);
        if(!$response->success) {
            return $response;
        }
        
        //Auto login after registration
        return $this->login($request);
    }

    public function get_nonce(Request $request): Response {
        $auth = new Auth($request->data());
        $auth->retrieveSession($request->getData('credential'));
        
        if(!$auth->isLoggedIn()) {
            return new Response(
                false,
                ResponseCode::Unauthorized,
                'Failed login attempt',
                data: ['credential' => $request->getData('credential')],
            );
        }
        
        return new Response(
            true,
            ResponseCode::Ok,
            'Nonce retrieved successfully',
            null,
            ['nonce' => $auth->nonce()],
        );
    }

    public function logout(): Response {
        Auth::logout();
        return new Response(true, ResponseCode::Ok, 'User logged out', 'index.php');
    }
}