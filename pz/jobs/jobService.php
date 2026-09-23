<?php

namespace pz\Services;

use pz\ModelService;
use pz\Models\Job;

class JobService extends ModelService {
    protected string $model_class = Job::class;
}
