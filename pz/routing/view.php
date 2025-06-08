<?php

namespace pz\Routing;

use Exception;
use pz\Enums\Routing\{Privacy, Method, ResponseCode};
use pz\Routing\{Route, Response, Request};
use pz\Log;
use pz\Config;

use Latte\Engine;

class View extends Route {
    protected $template;
    protected $latte_params;
    protected $latte_render;

    /**
     * Serves the given request and returns a response.
     *
     * This method simply uses the parent method to serve the request and then adds the data to the latte_params.
     *
     * @param Request $request The request to be served.
     * @return Response The response generated after serving the request.
     */
    public function serve(Request $request): Response {
        parent::serve($request);

        if($this->response->isSuccessful()) {
            // Views should not redirect by default, only if the response is not successful.
            $this->response->setRedirect(null);
        } else if($this->response->getResponseCode() == ResponseCode::Unauthorized && $request->user() != null) {
            // When nonces expired, views can return an Unauthorized response, to avoid that we can reload the page for the user if we know he is in fact logged in.
            foreach($request->user()->getFormMessages()['all'] as $value) {
                if($value[1] == 'expired-nonce') {
                    Log::warning('Nonce expired, reloading page for user');
                    $this->response = new Response(true, ResponseCode::Redirect, null, $_SERVER['REQUEST_URI']);
                    break;
                }
            }
        }

        if($this->response->data() != null) {
            $this->addParams($this->response->data());
        }

        return $this->response;
    }

    public function setTemplate(String $template) {
        $this->template = $template;
    }

    public function getTemplate(): ?String {
        return $this->template ?? null;
    }

    public function setParams(Array $latte_params) {
        $this->latte_params = $latte_params;
    }

    public function addParams(Array $params) {
        $this->latte_params = array_merge($this->latte_params, $params);
    }	

    public function render(Array $params): void {
        $this->addParams($params);
        $latte = new Engine;
        $latte->setTempDirectory(Config::latte_path());

        $latte->render($this->template, $this->latte_params);
        return;
    }
}