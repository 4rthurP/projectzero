<?php

namespace pz\Services;

use pz\Service;
use pz\Models\User;

class UserService extends Service {
    static protected string $model_class = User::class;

}