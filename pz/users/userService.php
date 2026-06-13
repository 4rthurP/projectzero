<?php

namespace pz\Services;

use pz\ModelService;
use pz\Models\User;

class UserService extends ModelService {
    protected string $model_class = User::class;

}