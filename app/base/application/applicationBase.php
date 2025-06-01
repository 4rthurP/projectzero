<?php

namespace pz;

use pz\ApplicationModule;
use pz\Enums\Routing\Privacy;
use pz\Routing\View;

class ApplicationBase {

    protected ?String $name;

    protected Array $modules;
    protected Array $views;
    protected Array $actions;
    protected Array $models;

    protected bool $is_initialized = false;
    protected ?Privacy $default_privacy;

    protected Array $default_latte_params = [];
    // protected bool $auto_render = true;

    ##############################################
    # Application initialization & configuration
    ##############################################
    /**
     * Constructor for the application class.
     *
     * @param string|null $name The name of the application. If null or 'default', the path prefix will be empty.
     * @param Privacy|null $default_privacy The default privacy setting for the application.
     */
    public function __construct(?String $name, ?Privacy $default_privacy = null) 
    {
        $this->name = $name;
        $this->modules = [];
        $this->views = [];
        $this->actions = [];
        $this->models = [];

        $this->default_privacy = $default_privacy;
    }

    /**
     * Initializes the application by iterating through all modules and merging their views, actions, and models into the application's respective properties.
     *
     * @return void
     */
    public function initialize() {
        if($this->is_initialized) {
            return;
        }

        foreach($this->modules as $module) {
            $module->initialize(true);
            $this->views = array_merge($this->views, $module->getViews());
            $this->actions = array_merge($this->actions, $module->getActions());
            $this->models = array_merge($this->models, $module->getModels());
        }

        $this->is_initialized = true;
    }

    ##############################
    # Application configuration
    ##############################
    /**
     * Adds a view to the application.
     *
     * @param String $name The name of the view.
     * @param String|null $file The file path of the view. If null, the name will be used as the file path.
     * @param Privacy|null $privacy The privacy setting for the view. If null, the default privacy will be used.
     * @return ApplicationModule Returns the module instance for method chaining.
     */
    public function module(String $module_name,  ?Privacy $default_privacy = null): ApplicationModule {
        $module = new ApplicationModule(
            $module_name, 
            $default_privacy ?? $this->default_privacy,
        );
        $this->modules[$module_name] = $module;
        return $module;
    }

    /**
     * Adds a module to the application.
     *
     * @param ApplicationModule $module The module to be added.
     * @return $this Returns the current instance for method chaining.
     */
    public function addModule(ApplicationModule $module): self {
        $this->modules[$module->getName()] = $module;
        return $this;
    }

    /**
     * Sets the default parameters for Latte templates.
     *
     * @param array $latte_params An associative array of parameters to be used in Latte templates.
     * @return $this Returns the current instance for method chaining.
     */
    public function latteParams(Array $latte_params) {
        $this->default_latte_params = $latte_params;
        return $this;
    }

    ##############################
    # Getters
    ##############################
    public function getName(): String {
        return $this->name;
    }

    public function getViews(): Array {
        return $this->views;
    }

    public function getActions(): Array {
        return $this->actions;
    }

    /**
     * Returns the list of models registered in the application as a named array of [$model_name => $model_controller].
     *
     * @return Array An named array of model class names.
     */
    public function getModels(): Array {
        return $this->models;
    }

    public function getPrivacy(): ?Privacy {
        return $this->default_privacy;
    } 
}