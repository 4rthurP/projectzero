<?php

namespace pz\Routing;

use Exception;
use pz\Enums\Routing\{Privacy, Method, ResponseCode};
use pz\Routing\{Route, Response, Request};

use Latte\Engine;

class View extends Route {
    protected $template;
    protected $latte_params;
    protected $latte_render;
    protected $isForm;

    public function isForm() {
        return $this->isForm;
    }
    
    public function setForm($is_form) {
        $this->isForm = $is_form;
    }

    public function setTemplate(String $template) {
        $this->template = __DIR__.'/../../views/'.$template;
    }

    public function setParams(Array $latte_params) {
        $this->latte_params = $latte_params;
    }

    public function serve(Request $request = null): Response {
        $response = new Response(true, ResponseCode::Ok, null, 'View served.');
        if($this->controller !== null && $request->getMethod() == Method::POST) {
            $response = parent::serve($request);
        }

        return $response;
    }

    public function render(Array $params): void {
        $this->setParams($params);
        $latte = new Engine;
        $latte->setTempDirectory(__DIR__.'/../../views/latte');

        $latte->render($this->template, $this->latte_params);
        return;
    }
}