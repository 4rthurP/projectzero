<?php

namespace pz;

use pz\Service;
use pz\Model;
use pz\Models\User;
use pz\Enums\Routing\ResponseCode;
use pz\Enums\Routing\Privacy;

class ModelService extends Service {
    public string $idKey;
    protected string | int | null $object_id = null;
    protected ?Model $object = null;
    protected string $model_class;

    public string | int | null $id {
        get => $this->object_id;
    }

    public string $class {
        get => $this->model_class;
    }

    public function __construct(?string $model_class = null)
    {
        if($model_class !== null) {
            $this->model_class = $model_class;
        }
        $object = new $this->model_class;
        $this->idKey = $object->getIdKey();
    }

   public function hasValidObject()
    {
        $valid = $this->object !== null && $this->object->isValid();
        if (!$valid) {
            $this->error("invalid-id");
        }
        return $valid;
    }

    /**
     * Loads a model based on the provided request and checks user rights if specified.
     *
     * @param int|string $id The ID of the model to load.
     * @param User $user The user for whom to check permissions.
     * @param bool $load_relations Whether to load related models (default: false).
     * @param string|null $rightToCheck The right to check for user permissions (optional).
     * @return Model|null The loaded model if successful, or null if an error occurred.
     */
    public function loadModel(int | string $id, User $user, bool $load_relations = false, ?string $rightToCheck = null): null | Model {
        $service = new static;
        if($id == null) {
            return $service->error("missing-id");
        }
        
        $object = $service->model_class::find($id, $load_relations);
        if($object == null || !$object->isModelInstantiated()) {
            return $service->error("invalid-id", ResponseCode::NotFound);
        }
        
        if($rightToCheck !== null) {
            $user_id = $user->getId();
            if(!$object->checkUserRights($rightToCheck, $user_id)) {
                return $service->error("permission-denied", ResponseCode::Forbidden);
            }
        }

        $this->object = $object;
        $this->object_id = $object->getId();
        
        return $object;
    }

    public function create(Array $data): Model {
        $model_class = $this->model_class;
        $model = new $model_class;
        $model->create($data);
        return $model;
    }

    public function checkModelRight(string $right): ?Privacy {
        $object = new $this->model_class;
        if($right == 'view') {
            return $object->getViewingPrivacy();
        }
        if($right == 'edit') {
            return $object->getEditingPrivacy();
        }
        return $this->error("invalid-right-requested");
    }

}