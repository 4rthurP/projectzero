<?php

namespace pz\Controllers;

use Exception;
use pz\ModelController;
use pz\Nonce;
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

        $user = User::authentificate($request->user(), $request->nonce(), $request->credential());
        return $user->getUserInfos();
    }

    public function login(Request $request) {
        $user = new User();
        $user->login($request->data());

        if($user->isLoggedIn() && $user->isValid()) {
            #TODO: add default route location + from header
            return new Response(true, ResponseCode::Ok, null,'logged-in', 'index.php');
        }
        
        return (new Response(false, ResponseCode::BadRequestContent, null, 'form-error'))->setFormData($user->getFormData());
    }

    public function register(Request $request): Response {
        return parent::create($request);
    }

    public function logout(): Response {
        User::logout();
        return new Response(true, ResponseCode::Ok, null, 'User logged out', 'index.php');
    }

    public function get_nonce(Request $request): Response {
        if($request->hasUser()){
            $user = User::authentificate($request->user(), null, $request->credential());

            if(!$user->isLoggedIn()) {
                return new Response(false, ResponseCode::Unauthorized, null, 'not-logged-in');
            }

            $nonce = $user->getNonce();
            if($nonce['success']) {
                return new Response(true, ResponseCode::Ok, null, 'nonce');
            }
            
        }
        
        return new Response(false, ResponseCode::Forbidden, null, 'missing-user');
    }
}