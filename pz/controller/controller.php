<?php

namespace pz;

use Exception;

class Controller {
    protected ?Service $service = null;
    protected string $service_class = Service::class;

    public function __construct() {
    }

    public function setService(string $service_class) {
        if(!is_subclass_of($service_class, Service::class)) {
            throw new Exception(Config::env() == "DEV" ? 'Provided class is not a valid service class' : 'An error occurred');
        }
        $this->service = new $service_class();
        $this->service_class = $service_class;
    }
}