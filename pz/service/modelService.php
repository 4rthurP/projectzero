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

   /**
    * Checks if there is a loaded object and if it is valid.
    * Adds an error message and code if the object is not valid / loaded.
    *
    * @return bool
    */
   public function hasValidObject()
    {
        $valid = $this->object !== null && $this->object->isValid();
        if (!$valid) {
            $this->error("invalid-id");
        }
        return $valid;
    }

    /**
     * Resets the state of the ModelService by clearing the loaded object and its ID, as well as any error messages or codes.
     * 
     * @return void
     */
    public function resetState()
    {
        $this->object = null;
        $this->object_id = null;
        parent::resetState();
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
        if($this->object !== null) {
            if($this->object_id == $id) {
                return $this->object;
            } else {
                $this->error("model-already-loaded");
            }
        }

        if($id == null) {
            return $this->error("missing-id");
        }
        
        $object = $this->model_class::find($id, $load_relations);
        if($object == null || !$object->isModelInstantiated()) {
            return $this->error("invalid-id", ResponseCode::NotFound);
        }
        
        if($rightToCheck !== null) {
            $user_id = $user->getId();
            if(!$object->checkUserRights($rightToCheck, $user_id)) {
                return $this->error("permission-denied", ResponseCode::Forbidden);
            }
        }

        $this->object = $object;
        $this->object_id = $object->getId();
        
        return $object;
    }

    public function create(Array $data): ?Model {
        if($this->object !== null) {
            return $this->error("model-already-loaded");
        }

        $model_class = $this->model_class;
        $model = new $model_class;
        $model->create($data);
        return $model;
    }

    public function update(Array $data): ?Model {
        if(!$this->hasValidObject()) {
            return $this->error("no-model-loaded");
        }

        $this->object->update($data);

        if(!$this->object->isValid()) {
            $this->error("invalid-data");
        }
        return $this->object;
    }

    public function set(string $key, mixed $value): ?Model {
        if(!$this->hasValidObject()) {
            return $this->error("no-model-loaded");
        }

        $this->object->set($key, $value, true);
        
        if(!$this->object->isValid()) {
            $this->error("invalid-data");
        }
        return $this->object;
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