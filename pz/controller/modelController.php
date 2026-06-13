<?php

namespace pz;

use Exception;

use pz\Controller;
use pz\Routing\Request;
use pz\Routing\Response;
use pz\Enums\Routing\ModelEndpoint;
use pz\Enums\Routing\ResponseCode;
class ModelController extends Controller
{
    protected string $model;
    protected string $model_class;
    protected string $service_class = ModelService::class;
    protected ModelService $model_service; # Used for type hinting

    static protected ?array $api_endpoints = [
        ModelEndpoint::LIST ,
        ModelEndpoint::GET,
        ModelEndpoint::SET,
        ModelEndpoint::COUNT,
        ModelEndpoint::CREATE,
        ModelEndpoint::DELETE,
        ModelEndpoint::UPDATE
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function setService(string $service_class)
    {
        if (!is_subclass_of($service_class, ModelService::class)) {
            throw new Exception(Config::env() == "DEV" ? 'Provided class is not a valid service class' : 'An error occurred');
        }
        $this->service = new $service_class();
        $this->model_service = $this->service;
        $this->service_class = $service_class;
        $this->model = $this->service->class;
    }

    public function setModel(string $model_class) {
        if (!is_subclass_of($model_class, Model::class)) {
            throw new Exception(Config::env() == "DEV" ? 'Provided class is not a valid model class' : 'An error occurred');
        }
        $this->model_class = $model_class;
        $this->service = new $this->service_class($model_class);
        $this->model_service = $this->service;
    }

    /**
     * Retrieves the API endpoints associated with the model controller.
     *
     * @return array The API endpoints.
     */
    public static function getApiEndpoints()
    {
        return self::$api_endpoints;
    }

    /**
     * Checks if the specified API endpoint exists in the model controller.
     *
     * @param string $endpoint The API endpoint to check.
     * @return bool Returns true if the API endpoint exists, false otherwise.
     */
    public function hasApiEndpoint(string $endpoint)
    {
        return in_array($endpoint, $this::$api_endpoints);
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
    public function get(Request $request): Response
    {
        $object = $this->loadModel($request, 'view');
        if ($object == null) {
            return $this->service->makeResponse();
        }

        return new Response(true, ResponseCode::Ok, 'get-' . $object->getName(), null, $object->toArray());
    }

    /**
     * Creates a new model based on the given request data.
     *
     * @param Request $request The request object containing the data.
     * @return Response The response object indicating the result of the create operation.
     */
    public function create(Request $request): Response
    {
        $model = $this->model_service->create($request->data());

        if ($model->isValid()) {
            $response = new Response(true, ResponseCode::Ok, 'created-' . $model::$name, null, $model->toArray());
            if ($model->page_url != '') {
                $response->setRedirect($model->page_url . '?id=' . $model->getId());
            }
            return $response;
        }

        return new Response(false, ResponseCode::BadRequestContent, 'form-error', null, $model->getFormData(), $model->getFormMessages());
    }

    public function update(Request $request): Response
    {
        $object = $this->loadModel($request, 'edit');
        if ($object == null) {
            return $this->model_service->makeResponse();
        }

        $object->update($request->data());
        if ($object->isValid()) {
            $response = new Response(true, ResponseCode::Ok, 'updated-' . $object->getName(), null, $object->toArray());
            if ($object->page_url != '') {
                $response->setRedirect($object->page_url . '?id=' . $object->getId());
            }
            return $response;
        }

        return new Response(false, ResponseCode::BadRequestContent, 'form-error', null, $object->getFormData(), $object->getFormMessages());
    }

    /**
     * Deletes a model resource.
     *
     * @param Request $request The request object.
     * @return Response The response object.
     */
    public function delete(Request $request): Response
    {
        $object = $this->loadModel($request, 'edit');
        if ($object == null) {
            return $this->model_service->makeResponse();
        }

        $object->delete();

        return new Response(true, ResponseCode::Ok, 'deleted-' . $object::$name . '-' . $request->data($object->idKey));
    }

    /**
     * Sets the attribute value of a model based on the provided request.
     *
     * @param Request $request The request object containing the necessary data.
     * @return Response The response object indicating the success or failure of the operation.
     */
    public function set(Request $request): Response
    {
        $requested_attribute = $request->data('attribute');
        if ($requested_attribute == null) {
            return new Response(false, ResponseCode::BadRequestContent, 'missing-id');
        }

        $object = $this->loadModel($request, 'edit');
        if ($object == null) {
            return $this->model_service->makeResponse();
        }

        $object->set($requested_attribute, $request->data('value'));

        return new Response(true, ResponseCode::Ok, 'set-' . $object::$name . '-' . $requested_attribute);
    }

    /**
     * Retrieves the value of a specific attribute from the model.
     *
     * @param Request $request The request object containing the attribute to retrieve.
     * @return Response The response object containing the result of the attribute retrieval.
     */
    public function get_attribute(Request $request): Response
    {
        $requested_attribute = $request->data('attribute');
        if ($requested_attribute == null) {
            return new Response(false, ResponseCode::BadRequestContent, 'missing-id');
        }

        $object = $this->loadModel($request, 'edit');
        if ($object == null) {
            return $this->model_service->makeResponse();
        }

        if (!$object->attributeExists($requested_attribute)) {
            return new Response(false, ResponseCode::NotFound, 'attribute-not-found');
        }

        $response_content = $object->get($requested_attribute);
        return new Response(true, ResponseCode::Ok, 'get-' . $object::$name . '-' . $requested_attribute, null, $response_content);
    }

    /**
     * Retrieves a list of models.
     *
     * @param Request $request The request object.
     * @return Response The response object.
     */
    public function list(Request $request): Response
    {
        $mode = $request->data('mode', 'array');
        $limit = $request->data('limit');
        $offset = $request->data('offset');

        $order_by = $request->data('order_by');
        $order_desc = $request->data('order_desc', false);
        $order_desc = $order_desc === 'true' || $order_desc === true || $order_desc === 'yes';

        $load_relations = $request->data('load_relations', false);
        $load_relations = $load_relations === 'true' || $load_relations === true || $load_relations === 'yes';

        $response_content = $this->model_class::query(
            where_args: [],
            load_relations: $load_relations,
            mode: $mode,
            order_by: $order_by ? [$order_by => !$order_desc] : [],
            limit: $limit,
            offset: $offset,
        );

        return new Response(true, ResponseCode::Ok, 'list-' . $this->model_class::$name, null, $response_content);
    }

    /**
     * Counts the number of records in the model.
     *
     * @param Request $request The request object.
     * @return Response The response object.
     */
    public function count(Request $request): Response
    {
        $response_content = $this->model_class::count();
        return new Response(true, ResponseCode::Ok, 'count-' . $this->model::$name, null, ["count" => $response_content]);
    }

    /**
     * Retrieves the privacy settings for a specific model based on the requested right.
     *
     * @param Request $request The request object containing the data.
     * @return Response The response object containing the privacy settings.
     */
    public function getModelPrivacy(Request $request): Response
    {
        if (!$request->hasData('right')) {
            return new Response(false, ResponseCode::BadRequestContent, 'missing-right');
        }
        $right = $request->data('right');

        $has_right = $this->model_service->checkModelRight($right);
        if (!$has_right) {
            return $this->model_service->makeResponse();
        }

        return new Response(true, ResponseCode::Ok, 'privacy-' . $this->model::$name . '-' . $right, null, ["mode" => $has_right->value]);
    }

    ###############################
    # Controller methods
    ###############################
    /**
     * Retrieves the model associated with the controller.
     *
     * @return mixed The model associated with the controller.
     */
    public function getModel()
    {
        return $this->model;
    }

    protected function loadModel(Request $request, ?string $rightToCheck = null, ?string $id_key = null): ?Model
    {
        $load_relations = $request->data('load_relations', false);
        if ($id_key === null) {
            $id_key = $this->service->idKey;
        }

        return $this->service->loadModel(
            $request->data($id_key ?? $this->service->idKey),
            $request->user(),
            $load_relations or $load_relations === 'true',
            $rightToCheck,
        );
    }

}
