<?php

namespace pz;

use Exception;
use pz\Enums\Routing\Privacy;
use pz\Enums\Routing\Method;
use pz\Routing\View;
use pz\Routing\Action;
use pz\Config;

class ApplicationModule extends ApplicationBase {
    protected String $path_prefix = '';
    // protected Array $default_latte_params = [];

    /**
     * Constructor for the application class.
     *
     * @param string|null $name The name of the application. If null or 'default', the path prefix will be empty.
     * @param Privacy|null $default_privacy The default privacy setting for the application.
     */
    public function __construct(?String $name, ?Privacy $default_privacy = null) 
    {
        parent::__construct($name, $default_privacy);
        $this->path_prefix = ($name === null || $name === 'default') ? '' : $name.'/';
    }

    ##############################
    # Module configuration
    ##############################
    
    /**
     * Adds a page to the application module.
     * 
     * @param String $page The name of the page, used to find the template file and folder.
     * @param String|null $controller The controller class name or method name to handle the page request. If null, no controller is associated with the page.
     * @param Array|string|null $methods The methods to handle the page request. If null, defaults to GET for views and POST for actions.
     * @param String|null $folder The folder where the page template is located. If null, defaults to the page name.
     * @param String|null $uri The URI for the page. If null, defaults to the page name with .php extension.
     * @param String|null $template The template file name. If null, it will be determined automatically based on the folder contents.
     * @param Privacy|null $privacy The privacy setting for the page. If null, the default privacy of the application will be used.
     * 
     * @return self Returns the current instance for method chaining.
     * 
     * Accepted formats for methods: 
     *   - 'get_method_name'
     *   - [GET_CONTROLLER]
     *   - [GET_CONTROLLER, POST_CONTROLLER]
     *   - [GET_CONTROLLER, POST_CONTROLLER_1, POST_CONTROLLER_2, ...]
     *   - ["GET" => GET_CONTROLLER, "POST" => POST_CONTROLLER]
     *   - ["GET" => GET_CONTROLLER, "POST" => [POST_CONTROLLER_1, POST_CONTROLLER_2, ...]]
     * XXX_CONTROLLER can either be
     *   - A single method name (string) if a default controller is provided 
     *   - An array containing controller infos in this order: method_name, ?function, ?model, ?success_location, ?error_location (if a default controller is provided)
     *   - An array containing controller infos in this order: controller_name, method_name, ?function, ?model, ?success_location, ?error_location (when no default controller is provided)
     */
    public function page(
        String $page,
        null|String $controller = null,
        Array|string|null $methods = null,
        null|String $folder = null,
        null|String $uri = null,
        null|String $template = null,
        Privacy|null $privacy = null,
    ): self {
        # Find the folder
        $folder = $folder ?? $page;
        if(substr($folder, -1) === "/")
            $folder = substr($folder, -1);
        # TODO: add support for more folders locations, not only modules
        $page_folder = Config::modules_path() . $this->path_prefix . $folder;

        # Find the template
        if($template == null) {
            # List all templates in the folder
            $found_templates = $this->findInFolder('/\.latte/i', $page_folder . '/');

            if(count($found_templates) == 1) {
                $template = array_values($found_templates)[0];
            } else {
                # If there is multiple templates we can default to the one with the page name
                foreach($found_templates as $template_name) {
                    if($template_name === $page . ".latte") {
                        $template = $template_name;
                        break;
                    }
                }
                if($template == null) {
                    # Otherwise raise an exception
                    throw new Exception("Found " . count($found_templates) . " templates in " . $page_folder . ". Only one is expected, otherwise you need to specifiy the templates name.");
                }
            }
        } else if(substr($template, -6) !== ".latte") {
            $template .= ".latte";
        }

        $template_path = $page_folder . '/' . $template;
        if(!is_file($template_path)) {
            throw new Exception("Template " . $template . " was not found in " . $page_folder . " or is not a file.");
        }

        # Handle the methods
        if(!is_array($methods)) {
            $methods = [
                "GET" => $methods,
                "POST" => [],
            ];
        }
        if(!array_key_exists("GET", $methods) && !array_key_exists("POST", $methods)) {
            $methods = [
                "GET" => $methods[0],
                "POST" => array_slice($methods, 1),
            ];
        }
        $uri = $uri ?? $page . '.php';
        
        # Handle the view
        $view_controller = $methods["GET"] ?? null;
        # If no method nor controller was specified, the view simply is not expecting any controller
        if($view_controller !== null || $controller !== null) {
            if($view_controller === null) {
                # The app can assume that a page view is tied to a default method named "page_<page_name>"
                if(class_exists($controller) && method_exists($controller, "page_" . $page)) {
                    $view_controller = "page_" . $page;
                }
                # Otherwise we simply do not have a controller associated with this view
            }
            
            if(is_string($view_controller)) {
                # The view method expects a list of parameters for the view
                $view_controller = [$view_controller];
            }
            if($controller !== null && $view_controller !== null) {
                # If a controller was specified we add it at the begining of the parameter array
                $view_controller = array_merge([$controller], $view_controller);
            }
        }
        $this->view(
            $uri, 
            $template_path, 
            $view_controller[0] ?? null,
            $view_controller[1] ?? null,
            $privacy, 
            $view_controller[2] ?? null,
            $view_controller[3] ?? null,
        );    
        
        # Handle the action(s)
        $action_controllers = $methods["POST"] ?? [];
        if(is_string($action_controllers)) {
            # The view method expects a list of parameters for the view
            $action_controllers = [[$action_controllers]];
        }
        if(!is_array($action_controllers[0] ?? []) && $action_controllers !== []) {
            # The action method expects a list of parameters for the view
            $action_controllers = [$action_controllers];
        }
        foreach($action_controllers as $action) {
            if($controller != null) {
                if(is_string($action)) {
                    # In case no controller was specified, the action method can be passed as only a string
                    $action = [$action];
                }
                # If a controller was specified we add it at the begining of the parameter array
                $action = array_merge([$controller], $action);
            }
            $this->action(
                $uri, 
                $action[0],
                $action[1],
                Method::POST, 
                $privacy,
                $action[2] ?? null,
                $action[3] ?? null,
            );
        }

        return $this;
    }

    /**
     * Simple wrapper around the addRoute method for views routing
     * 
     * @param String $path The path for the view, relative to the application root.
     * @param String $template The path to the Latte template file, relative to the application root.
     * @param String|null $view_controller The controller class name or method name to handle the view request. If null, the view will not expect a controller.
     * @param String|null $view_function The function name in the controller to handle the view request. If null, the default function will be used.
     * @param Privacy|null $privacy The privacy setting for the view. If null, the default privacy of the application will be used.
     * @param String|null $success_location The location to redirect to on success. If null, no redirection will occur.
     * @param String|null $error_location The location to redirect to on error. If null, no redirection will occur.
     * 
     * @return self Returns the current instance for method chaining.w.
     */
    public function view(
        String $path, 
        String $template, 
        ?String $view_controller,
        ?String $view_function,
        ?Privacy $privacy = null,
        ?String $success_location = null,
        ?String $error_location = null,
    ): self {
        return $this->addRoute(
            View::class,
            $path,
            $view_controller,
            $view_function,
            Method::GET,
            $success_location,
            $error_location,
            $privacy,
            $template,
        );
    }

    /**
     * Simple wrapper around the addRoute method for actions routing
     * 
     * @param String $sub_path The sub path for the actions, relative to the application root.
     * @param String $action_controller The controller class name or method name to handle the action requests.
     * @param Array $endpoints An associative array where keys are action names and values are the corresponding controller methods.
     * @param Method $method The HTTP method to use for the actions. Default is POST.
     * @param Privacy|null $privacy The privacy setting for the actions. If null, the default privacy of the application will be used.
     * @param String|null $success_location The location to redirect to on success. If null, no redirection will occur.
     * @param String|null $error_location The location to redirect to on error. If null, no redirection will occur.
     * 
     * @return self Returns the current instance for method chaining.
     */
    public function actions(
        String $sub_path,
        String $action_controller,
        Array $endpoints,
        Method $method = Method::POST, 
        ?Privacy $privacy = null, 
        ?String $success_location = null,
        ?String $error_location = null,
    ): self {
        // Make sure the sub path ends with a slash
        if(substr($sub_path, -1) !== '/') 
            $sub_path .= '/';

        foreach($endpoints as $action) {
            // Endpoints is an associative array with the path as key and the function associated to this path as value
            $this->addRoute(
                Action::class,
                $sub_path . $action,
                $action_controller,
                $endpoints[$action],
                $method,
                $success_location,
                $error_location,
                $privacy,
            );
        }

        return $this;
    }

    /**
     * Simple wrapper around the addRoute method for actions routing
     * 
     * @param String $path The path for the action, relative to the application root.
     * @param String $action_controller The controller class name or method name to handle the action request.
     * @param String $action_function The function name in the controller to handle the action request.
     * @param Method $method The HTTP method to use for the action. Default is POST.
     * @param Privacy|null $privacy The privacy setting for the action. If null, the default privacy of the application will be used.
     * @param String|null $success_location The location to redirect to on success. If null, no redirection will occur.
     * @param String|null $error_location The location to redirect to on error. If null, no redirection will occur.
     * 
     * @return self Returns the current instance for method chaining.
     */
    public function action(
        String $path, 
        String $action_controller,
        String $action_function,
        Method $method = Method::POST, 
        ?Privacy $privacy = null, 
        ?String $success_location = null,
        ?String $error_location = null,
    ): self {
        return $this->addRoute(
            Action::class,
            $path,
            $action_controller,
            $action_function,
            $method,
            $success_location,
            $error_location,
            $privacy,
        );
    }


    /**
     * Simple wrapper around the addRoute method for API routing
     * It automatically sets the method to GET. Privacy is set to LOGGED_IN by default, but can be overridden.
     * 
     * @param String $path The path for the action, relative to the application root.
     * @param String $action_controller The controller class name or method name to handle the action request.
     * @param String $action_function The function name in the controller to handle the action request.
     * @param Privacy $privacy The privacy setting for the action. Default is LOGGED_IN.
     * 
     * @return self Returns the current instance for method chaining.
     */
    public function api(
        String $path, 
        String $action_controller,
        String $action_function,
        Privacy $privacy = Privacy::LOGGED_IN,
    ): self {
        $method = $method ?? Method::POST;

        return $this->addRoute(
            Action::class,
            $path,
            $action_controller,
            $action_function,
            Method::GET,
            null,
            null,
            $privacy,
        );
    }

    /**
     * Simple wrapper around the addRoute method for public API routing
     * It automatically sets the method to GET and the privacy to PUBLIC.
     * 
     * @param String $path The path for the action, relative to the application root.
     * @param String $action_controller The controller class name or method name to handle the action request.
     * @param String $action_function The function name in the controller to handle the action request.
     * @param Method $method The HTTP method to use for the action. Default is POST.
     * 
     * @return self Returns the current instance for method chaining.
     */
    public function public_api(
        String $path, 
        String $action_controller,
        String $action_function,
    ): self {
        $method = $method ?? Method::POST;

        return $this->addRoute(
            Action::class,
            $path,
            $action_controller,
            $action_function,
            Method::GET,
            null,
            null,
            Privacy::PUBLIC,
        );
    }

    /**
     * Adds multiple models to the application module.
     * 
     * @param Array $models An array of model class names or arrays containing model class name and controller class name.
     * 
     * @return self Returns the current instance for method chaining.
     */
    public function models(Array $models): self {
        foreach($models as $model) {
            if(is_array($model)) {
                $this->model(...$model);
            } else {
                $this->model($model);
            }
        }
        return $this;
    }
    
    /**
     * Adds a model to the application module.
     * 
     * @param String $model_class_name The class name of the model.
     * @param String $model_controller The controller class name to handle the model requests. Default is ModelController::class.
     * @param Privacy|null $privacy The privacy setting for the model. If null, the default privacy of the application will be used.
     * 
     * @return self Returns the current instance for method chaining.
     */
    public function model(
        String $model_class_name, 
        String $model_controller  = ModelController::class, 
        Privacy|null $privacy = null
    ): self {
        $this->models[$model_class_name] = $model_controller;

        foreach($model_controller::getApiEndpoints() as $endpoint) {
            $this->addRoute(
                Action::class,
                $model_class_name::getName().'/'.$endpoint->value,
                $model_controller,
                $endpoint->value,
                $endpoint->getMethod(),
                null,
                null,
                $privacy,
                $model_class_name,
            );
        }
        

        return $this;
    }

    /**
     * Adds a route to the application module in the action or view properties 
     * Takes care of providing the bundle's path prefix to the given path and assigning default privacy
     * Used to lazy load route only at run time by calling buildRoute with the same params.
     * 
     * @param String $route_class The class name of the route (Route or View).
     * @param String $path The path for the route, relative to the application root.
     * @param String|null $controller_class The controller class name to handle the route request. If null, no controller is associated with the route.
     * @param String|null $function_name The function name in the controller to handle the route request. If null, the default function will be used.
     * @param Method|null $method The HTTP method to use for the route. If null, defaults to GET for views and POST for actions.
     * @param String|null $success_location The location to redirect to on success. If null, no redirection will occur.
     * @param String|null $error_location The location to redirect to on error. If null, no redirection will occur.
     * @param Privacy|null $privacy The privacy setting for the route. If null, the default privacy of the application will be used.
     * @param String|null $associated_construct The associated construct (model or template) for the route. If null, no construct is associated with the route.
     * 
     * @return self Returns the current instance for method chaining.
     */
    protected function addRoute(
        String $route_class, 
        String $path, 
        ?String $controller_class,
        ?String $function_name,
        ?Method $method, 
        ?String $success_location = null,
        ?String $error_location = null,
        ?Privacy $privacy = null,
        ?String $associated_construct = null,
    ): self {
        $path = $this->path_prefix.$path;
        $privacy = $privacy ?? $this->default_privacy;

        $route_params = [
            $route_class,
            $path,
            $controller_class,
            $function_name,
            $method,
            $success_location,
            $error_location,
            $privacy,
            $associated_construct,
        ];

        if($route_class == View::class) {
            $this->views[$path] = $route_params;
        } else {
            $this->actions[$path][] = $route_params;
        }

        return $this;
    }

    ##############################
    # Helper functions
    ##############################
    protected function findInFolder(string $pattern, string $folder): array {
        $files = scandir($folder);
        if ($files === false) {
            throw new Exception("Could not read directory: " . $folder);
        }
        
        $matches = array_filter($files, function($file) use ($pattern, $folder) {
            return is_file($folder . '/' . $file) && preg_match($pattern, $file);
        });

        return $matches;
    }
}