<?php

namespace pz;

use pz\Controller;
use pz\Routing\Request;
use pz\Routing\Response;
use pz\Enums\Routing\ModelEndpoint;
use pz\Enums\Routing\ResponseCode;

class ModelController extends Controller {
    protected $model;
    protected ?Array $api_endpoints = [ModelEndpoint::LIST, ModelEndpoint::GET, ModelEndpoint::SET, ModelEndpoint::CREATE, ModelEndpoint::DELETE, ModelEndpoint::UPDATE];

    public function __construct() {
        parent::__construct();
    }

    public function setModel($model_class) {
        $this->model = $model_class;
    }

    ###############################
    # Controller methods
    ###############################
    public function getModel() {
        return $this->model;
    }

    public function getApiEndpoints() {
        return $this->api_endpoints;
    }

    public function hasApiEndpoint(String $endpoint) {
        return in_array($endpoint, $this->api_endpoints);
    }

    protected function setApiEndpoints(Array $endpoints) {
        $this->api_endpoints = $endpoints;
    }

    protected function addApiEndpoint(String $endpoint) {
        if($this->hasApiEndpoint($endpoint)) {
            return;
        }
        $this->api_endpoints[] = $endpoint;
    }

    protected function removeApiEndpoint(String $endpoint) {
        if(!$this->hasApiEndpoint($endpoint)) {
            return;
        }
        $this->api_endpoints = array_diff($this->api_endpoints, [$endpoint]);
    }

    ###############################
    # Model Endpoints
    ###############################

    #TODO: add a 'form' layer to the responses messages (best would be to have the form's id) as to avoid confusion on which form we are giving feeback if the page as multiple forms
    
    public function list(Request $request): Response
    {
        $user_id = $request->user();
        $as_object = $request->getData('as_object') ?? true;
        
        $response_content = $this->model::listUserRessources($user_id, $as_object);
        
        return new Response(true, ResponseCode::Ok, $response_content, 'list-'.$this->model->name);
    }
    
    public function count(Request $request): Response
    {
        $user_id = $request->user();
        $response_content = $this->model::countUserRessources($user_id);
        return new Response(true, ResponseCode::Ok, $response_content, 'count-'.$this->model->name);
    }

    public function get(Request $request): Response {
        $user_id = $request->user();

        if(!$request->hasData('id')) {
            return new Response(false, ResponseCode::BadRequestContent, null, 'missing-id');
        }
        $ressource_id = $request->getData('id');
        $model = $this->model::load($ressource_id);

        if(!$model->isModelInstantiated()) {
            return new Response(false, ResponseCode::NotFound, null, 'ressource-not-found');
        }

        if(!$model->checkUserRights('view', $user_id)) {
            return new Response(false, ResponseCode::Forbidden, null, 'permission-denied');
        }

        $response_content = $model->toArray();
        return new Response(true, ResponseCode::Ok, $response_content, 'get-'.$model->getName());
    }

    public function get_attribute(Request $request): Response {
        $user_id = $request->user();

        if(!$request->hasData('id')) {
            return new Response(false, ResponseCode::BadRequestContent, null, 'missing-id');
            // return ["success" => false, "error" => "No id provided"];
        }
        $ressource_id = $request->getData('id');
        
        if(!$request->hasData('attribute')) {
            return new Response(false, ResponseCode::BadRequestContent, null, 'missing-id');
            // return ["success" => false, "error" => "No id provided"];
        }
        $requested_attribute = $request->getData('attribute');

        $model = $this->model::load($ressource_id);

        if(!$model->checkUserRights('view', $user_id)) {
            return new Response(false, ResponseCode::Forbidden, null, 'permission-denied');
            // return ["success" => false, "error" => "permission", "response" => "You don't have the rights to create this ressource"];
        }

        if(!$model->attributeExists($requested_attribute)) {
            return new Response(false, ResponseCode::NotFound, null, 'attribute-not-found');
            // return ["success" => false, "error" => "attribute-not-found", "response" => "The requested attribute does not exist"];
        }

        $response_content = $model->get($requested_attribute);
        return new Response(true, ResponseCode::Ok, $response_content, 'get-'.$model->name.'-'.$requested_attribute);
    }

    public function set(Request $request): Response {
        $user_id = $request->user();

        if(!$request->hasData('id')) {
            return new Response(false, ResponseCode::BadRequestContent, null, 'missing-id');
            // return ["success" => false, "error" => "No id provided"];
        }
        $ressource_id = $request->getData('id');
        
        if(!$request->hasData('attribute')) {
            return new Response(false, ResponseCode::BadRequestContent, null, 'missing-id');
            // return ["success" => false, "error" => "No id provided"];
        }
        $requested_attribute = $request->getData('attribute');
        
        $attribute_value = $request->getData('value');

        $model = $this->model::load($ressource_id);

        if(!$model->checkUserRights('edit', $user_id)) {
            return new Response(false, ResponseCode::Forbidden, null, 'permission-denied');
            // return ["success" => false, "error" => "permission", "response" => "You don't have the rights to create this ressource"];
        }

        $model->set($requested_attribute, $attribute_value);

        return new Response(true, ResponseCode::Ok, null, 'set-'.$model->name.'-'.$requested_attribute);
        // return ["success" => true, "success_message" => "changed-".$requested_attribute, "response" => "Name change to ".$model->get('name')];
    }

    public function create(Request $request): Response {
        $model = new $this->model;

        $model->checkForm($request->data())?->create();
        
        if($model->isValid()) {
            $response = new Response(true, ResponseCode::Ok, $model->toArray(), 'created-'.$model::$name);
            if($model->page_url != '') {
                $response->setRedirect($model->page_url.'?id='.$model->getId());
            }
            return $response;
        }
        
        return (new Response(false, ResponseCode::BadRequestContent, null, 'form-error'))->setFormData($model->getFormData());
    }

    public function delete(Request $request): Response {
        $user_id = $request->user();

        if(!$request->hasData('id')) {
            return new Response(false, ResponseCode::BadRequestContent, null, 'missing-id');
            // return ["success" => false, "error" => "No id provided"];
        }
        $ressource_id = $request->getData('id');

        $model = $this->model::load($ressource_id);

        if(!$model->checkUserRights('edit', $user_id)) {
            return new Response(false, ResponseCode::Forbidden, null, 'permission-denied');
            // return ["success" => false, "error" => "permission", "response" => "You don't have the rights to create this ressource"];
        }

        $model->delete();
        return new Response(true, ResponseCode::Ok, null, 'deleted-'.$model->name);
        // return ["success" => true, "success_message" => "deleted-".$model->name, "response" => "model deleted"];
    }

    public function update(Request $request): Response {
        $user_id = $request->user();

        if(!$request->hasData('id')) {
            return new Response(false, ResponseCode::BadRequestContent, null, 'missing-id');
            // return ["success" => false, "error" => "No id provided"];
        }
        $ressource_id = $request->getData('id');

        $model = $this->model::load($ressource_id);

        if(!$model->checkUserRights('edit', $user_id)) {
            return new Response(false, ResponseCode::Forbidden, null, 'permission-denied');
            // return ["success" => false, "error" => "permission", "response" => "You don't have the rights to create this ressource"];
        }

        $model->checkForm($request->data())?->update();

        if($model->isValid()) {
            return new Response(true, ResponseCode::Ok, $model->toArray(), 'updated-'.$model->getName());
        }
        
        return (new Response(false, ResponseCode::BadRequestContent, null, 'form-error'))->setFormData($model->getFormData());
    }

    public function getModelPrivacy(Request $request): Response {
        $model = new $this->model;

        if(!$request->hasData('right')) {
            return new Response(false, ResponseCode::BadRequestContent, null, 'missing-right');
        }
        $right = $request->getData('right');
        if($right == 'view') {
            $response_content = $model->getViewingPrivacy();
        } else if($right == 'edit') {
            $response_content = $model->getEditingPrivacy();
        } else {
            return new Response(false, ResponseCode::BadRequestContent, null, 'invalid-right-requested');
        }

        return new Response(true, ResponseCode::Ok, $response_content, 'privacy-'.$model->name.'-'.$right);
    }
}