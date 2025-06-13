<?php

namespace pz;

use pz\Controller;
use pz\Routing\Request;
use pz\Routing\Response;
use pz\Enums\Routing\ModelEndpoint;
use pz\Enums\Routing\ResponseCode;

class ModelController extends Controller {
    protected $model;
    protected $modelIdKey;
    static protected ?Array $api_endpoints = [
        ModelEndpoint::LIST, 
        ModelEndpoint::GET, 
        ModelEndpoint::SET, 
        ModelEndpoint::CREATE, 
        ModelEndpoint::DELETE, 
        ModelEndpoint::UPDATE
    ];

    public function __construct() {
        parent::__construct();
    }

    public function setModel($model_class) {
        $this->model = $model_class;

        $model = new $this->model;
        $this->modelIdKey = $model->getIdKey();
    }

    ###############################
    # Model Endpoints
    ###############################
    /**
     * Retrieves a model resource based on the provided request.
     *
     * @param Request $request The request object containing user and data information.
     * @return Response The response object containing the result of the operation.
     */
    public function get(Request $request): Response {
        $loadModelResponse = $this->loadModel($request, 'view');
        if(!$loadModelResponse->isSuccessful()) {
            return $loadModelResponse;
        }

        $model = $loadModelResponse->data('model');

        $response_content = $model->toArray();
        return new Response(true, ResponseCode::Ok, 'get-'.$model->getName(), null, $response_content);
    }

    /**
     * Creates a new model based on the given request data.
     *
     * @param Request $request The request object containing the data.
     * @return Response The response object indicating the result of the create operation.
     */
    public function create(Request $request): Response {
        $model = new $this->model;
        $model->create($request->data());
        
        if($model->isValid()) {
            $response = new Response(true, ResponseCode::Ok, 'created-'.$model::$name, null, $model->toArray());
            if($model->page_url != '') {
                $response->setRedirect($model->page_url.'?id='.$model->getId());
            }
            return $response;
        }
        
        return new Response(false, ResponseCode::BadRequestContent, 'form-error', null, $model->getFormData(), $model->getFormMessages());
    }

    public function update(Request $request): Response {
        $loadModelResponse = $this->loadModel($request, 'edit');
        if(!$loadModelResponse->isSuccessful()) {
            return $loadModelResponse;
        }

        $model = $loadModelResponse->data('model');
        $model->update($request->data());

        if($model->isValid()) {
            $response =  new Response(true, ResponseCode::Ok, 'updated-'.$model->getName(), null, $model->toArray());
            if($model->page_url != '') {
                $response->setRedirect($model->page_url.'?id='.$model->getId());
            }
            return $response;
        }
        
        return new Response(false, ResponseCode::BadRequestContent, 'form-error', null, $model->getFormData(), $model->getFormMessages());
    }

    /**
     * Deletes a model resource.
     *
     * @param Request $request The request object.
     * @return Response The response object.
     */
    public function delete(Request $request): Response {
        $loadModelResponse = $this->loadModel($request, 'edit');
        if(!$loadModelResponse->isSuccessful()) {
            return $loadModelResponse;
        }

        $model = $loadModelResponse->data('model');
        $model->delete();

        return new Response(true, ResponseCode::Ok, 'deleted-'.$model::$name.'-'.$request->getData($model->idKey));
    }

    /**
     * Sets the attribute value of a model based on the provided request.
     *
     * @param Request $request The request object containing the necessary data.
     * @return Response The response object indicating the success or failure of the operation.
     */
    public function set(Request $request): Response {        
        $requested_attribute = $request->getData('attribute');
        if($requested_attribute == null) {
            return new Response(false, ResponseCode::BadRequestContent, 'missing-id');
        }

        $loadModelResponse = $this->loadModel($request, 'edit');
        if(!$loadModelResponse->isSuccessful()) {
            return $loadModelResponse;
        }

        $model = $loadModelResponse->data('model');   
        $model->set($requested_attribute, $request->getData('value'));

        return new Response(true, ResponseCode::Ok, 'set-'.$model::$name.'-'.$requested_attribute);
    }

    /**
     * Retrieves the value of a specific attribute from the model.
     *
     * @param Request $request The request object containing the attribute to retrieve.
     * @return Response The response object containing the result of the attribute retrieval.
     */
    public function get_attribute(Request $request): Response {
        $requested_attribute = $request->getData('attribute');
        if($requested_attribute == null) {
            return new Response(false, ResponseCode::BadRequestContent, 'missing-id');
        }

        $loadModelResponse = $this->loadModel($request, 'view');
        if(!$loadModelResponse->isSuccessful()) {
            return $loadModelResponse;
        }

        $model = $loadModelResponse->data('model');
        if(!$model->attributeExists($requested_attribute)) {
            return new Response(false, ResponseCode::NotFound, 'attribute-not-found');
        }

        $response_content = $model->get($requested_attribute);
        return new Response(true, ResponseCode::Ok, 'get-'.$model::$name.'-'.$requested_attribute, null, $response_content);
    }

    /**
     * Retrieves a list of models.
     *
     * @param Request $request The request object.
     * @return Response The response object.
     */
    public function list(Request $request): Response
    {
        $as_object = $request->getData('as_object') ?? true;
        $limit = $request->getData('limit');
        $offset = $request->getData('offset');
        
        $response_content = $this->model::list($as_object, $limit, $offset);
        
        return new Response(true, ResponseCode::Ok, 'list-'.$this->model->name, null, $response_content);
    }
    
    /**
     * Counts the number of records in the model.
     *
     * @param Request $request The request object.
     * @return Response The response object.
     */
    public function count(Request $request): Response
    {
        $response_content = $this->model::count();
        return new Response(true, ResponseCode::Ok, 'count-'.$this->model->name, null, $response_content);
    }

    /**
     * Retrieves the privacy settings for a specific model based on the requested right.
     *
     * @param Request $request The request object containing the data.
     * @return Response The response object containing the privacy settings.
     */
    public function getModelPrivacy(Request $request): Response {
        $model = new $this->model;

        if(!$request->hasData('right')) {
            return new Response(false, ResponseCode::BadRequestContent, 'missing-right');
        }
        $right = $request->getData('right');
        if($right == 'view') {
            $response_content = $model->getViewingPrivacy();
        } else if($right == 'edit') {
            $response_content = $model->getEditingPrivacy();
        } else {
            return new Response(false, ResponseCode::BadRequestContent, 'invalid-right-requested');
        }

        return new Response(true, ResponseCode::Ok, 'privacy-'.$model::$name.'-'.$right, null, $response_content);
    }

    ###############################
    # Controller methods
    ###############################
    /**
     * Retrieves the model associated with the controller.
     *
     * @return mixed The model associated with the controller.
     */
    public function getModel() {
        return $this->model;
    }

    /**
     * Loads a model based on the provided request and checks user rights if specified.
     *
     * @param Request $request The request object.
     * @param string|null $rightToCheck The right to check for user permissions (optional).
     * @return Response The response object containing the loaded model or an error message.
     */
    protected function loadModel(Request $request, ?string $rightToCheck = null): Response {
        $ressource_id = $request->getData($this->modelIdKey);
        if($ressource_id == null) {
            return new Response(false, ResponseCode::BadRequestContent, 'missing-id');
        }

        $load_relations = $request->getData('load_relations') ?? false;
        $load_relations = $load_relations === 'true' || $load_relations === true;
        
        $model = $this->model::find($ressource_id, $load_relations);
        if($model == null || !$model->isModelInstantiated()) {
            return new Response(false, ResponseCode::NotFound, 'ressource-not-found');
        }
        
        if($rightToCheck !== null) {
            $user_id = $request->user()->getId();
            if(!$model->checkUserRights($rightToCheck, $user_id)) {
                return new Response(false, ResponseCode::Forbidden, 'permission-denied');
            }
        }
        
        return new Response(true, ResponseCode::Ok, null, null, ['model' => $model]);
    }

    /**
     * Retrieves the API endpoints associated with the model controller.
     *
     * @return array The API endpoints.
     */
    public static function getApiEndpoints() {
        return self::$api_endpoints;
    }

    /**
     * Checks if the specified API endpoint exists in the model controller.
     *
     * @param string $endpoint The API endpoint to check.
     * @return bool Returns true if the API endpoint exists, false otherwise.
     */
    public function hasApiEndpoint(String $endpoint) {
        return in_array($endpoint, $this::$api_endpoints);
    }
}