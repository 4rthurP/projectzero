<?php

namespace pz;

use Exception;
use pz\Enums\Routing\{Privacy, Method, ResponseCode};
use pz\Routing\{Route, Response, Request, View, Action};
use pz\database\Database;
use pz\Models\User;
use pz\Controllers\UserController;

class Application {

    private ?String $name;

    private Array $bundles;
    private Array $views;
    private Array $actions;
    private Array $models;

    private ?Privacy $default_privacy;
    private String $path_prefix = '';
    private Array $default_latte_params = [];

    private ?View $current_view;
    private ?Action $current_action;
    private ?Response $page_response; //Global response for the page
    private ?Response $view_routing; //Response for the view routing
    private ?Response $view_serving; //Response for the view serving
    private ?Response $action_routing; //Response for the action routing
    private ?Response $action_serving; //Response for the action serving

    private $user_id;

    /**
     * Constructor for the application class.
     *
     * @param string|null $name The name of the application. If null or 'default', the path prefix will be empty.
     * @param Privacy|null $default_privacy The default privacy setting for the application.
     */
    public function __construct(?String $name, ?Privacy $default_privacy = null) 
    {
        $this->name = $name;
        $this->bundles = [];
        $this->views = [];
        $this->actions = [];
        $this->models = [];

        $this->path_prefix = ($name === null || $name === 'default') ? '' : $name.'/';

        $this->default_privacy = $default_privacy;
    }

    /**
     * Runs the application by initializing the necessary components, handling the user session, processing the request, and serving the appropriate response.
     *
     * @return Response The response object containing the result of the request processing.
     */
    public function run(): Response {
        $this->initialize();
        $request = new Request();
        $is_api_call = false;    
        
        // Handle the user session (nonce, cookies, etc.)
        if(isset($_SESSION['user'])){
            $this->user_id = $_SESSION['user']['id'];
            // $request->setUser($_SESSION['user']['id']);

            if(isset($_SESSION['user']['nonce_expiration']) && (empty($_SESSION['user']['nonce_expiration']) || time() < $_SESSION['user']['nonce_expiration'])) {
                $controller = new UserController();
                $controller->get_nonce($request);
            }

            setcookie('user_id', $_SESSION['user']['id'], $_SESSION['user']['cookie_end'], '/');
            setcookie('user_name', $_SESSION['user']['name'], $_SESSION['user']['cookie_end'], '/');
            setcookie('user_role', $_SESSION['user']['role'], $_SESSION['user']['cookie_end'], '/');
            setcookie('user_nonce', $_SESSION['user']['nonce'], $_SESSION['user']['cookie_end'], '/');
            setcookie('user_nonce_expiration', $_SESSION['user']['nonce_expiration'], $_SESSION['user']['cookie_end'], '/');
        }
        else if(isset($_COOKIE['user_id'])) {
            // In some cases the session is lost, if the user is logged in with a cookie, but the session is lost, we log him out to avoid security issues and bugs.
            User::logout();
            header('Location: /');
            exit();
        }
        
        // Find the request params and action
        $current_view = $this->sanitizePath($_SERVER['SCRIPT_NAME']);
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            // Request method and data
            $request->setData($_GET);
            $request->setMethod(Method::GET);

            // Request action
            if(isset($_GET['action'])) {
                $is_api_call = true;
                $request->setAction($_GET['action']);
            } else {
                $request->setAction($current_view);
            }

            // Eventual redirections
            if(isset($_GET['from'])) {
                $request->onSuccess($_GET['from']);
            } elseif(isset($_GET['success'])) {
                $request->onSuccess($_POST['success']);
            }
            if(isset($_GET['error'])) {
                $request->onError($_GET['error']);
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Request method and data
            $request->setData($_POST);
            $request->setMethod(Method::POST);

            // Request action
            if(isset($_POST['action'])) {
                $request->setAction($_POST['action']);
                $is_api_call = isset($_POST['api']) ? $_POST['api'] : $is_api_call;
            } else {
                $request->setAction($current_view);
            }

            // Eventual redirections
            if(isset($_GET['from'])) {
                $request->onSuccess($_GET['from']);
            } elseif(isset($_POST['from'])) {
                $request->onSuccess($_POST['from']);
            } elseif(isset($_POST['success'])) {
                $request->onSuccess($_POST['success']);
            }

            if(isset($_POST['error'])) {
                $request->onError($_POST['error']);
            }
        } else {
            return new Response(false, ResponseCode::InvalidRequestMethod, null, 'Invalid request method '.$_SERVER['REQUEST_METHOD'], 'index.php');
        }
        
        // Adding the user id to the request is done here to avoid overwriting it with the request's data
        $request->addData('user_id', $this->user_id);
        
        //Finding the route to serve, either in the actions or the views
        $view = null;
        if(array_key_exists($current_view, $this->views)) {
            $view = $this->views[$current_view];
            $this->current_view = $view;
            $view->setParams($this->default_latte_params);
        }

        
        $action_path = $request->getAction();
        if($action_path != null && array_key_exists($action_path, $this->actions)) {
            $action = $this->actions[$action_path];
            $this->current_action = $action;
            
            $this->action_routing = $action->check($request);
            $this->page_response = $this->action_routing;
            if(!$this->page_response->success) {
                return $this->page_response;
            }
            $this->action_serving = $action->serve($request);
            $this->page_response = $this->action_serving;
            
            // If the action response has a redirection or is an API call, we return it
            if($this->page_response->hasRedirect() || $is_api_call) {
                return $this->page_response;
            }
            
            // If we have no redirection and it is not an API call, we serve the view
            $request->setMethod(Method::GET);
        }

        //At this point (action is served if needed) if no view is found, we return an error
        if($view == null) {
            $this->page_response = new Response(false, ResponseCode::NotFound, null, 'The route '.$request->getAction().' does not exist.', '/index.php');
            return $this->page_response;
        }

        $this->view_routing = $view->check($request);
        if(!$this->view_routing->isSuccessful()) {
            $this->page_response = $this->view_routing;
            return $this->page_response;
        }

        //We prioritize returning an unsuccessful view response to avoid security issues
        if(isset($this->action_serving) && !$this->action_serving->isSuccessful()) {
            return $this->action_serving;
        }

        $this->view_serving = $view->serve($request);
        $this->page_response = $this->view_serving;
        return $this->page_response;
    }

    /**
     * Renders the current view with the provided parameters.
     *
     * This method checks if the current view is set and not null. If the current view is valid,
     * it attempts to get the response from the view and adds additional parameters to the provided
     * parameters array. If the view is a form, it also adds form data to the parameters.
     * Finally, it renders the view with the updated parameters.
     *
     * @param array $params An associative array of parameters to be passed to the view.
     * 
     * @return void
     */
    public function render(Array $params) {
        if(!isset($this->current_view)) {
            return;
        }
        
        if($this->current_view === null) {
            return;
        }

        try {
            $response = $this->action_serving ?? $this->page_response;
            if(isset($_SERVER['HTTP_REFERER'])) {
                $params += ['previous_page_url' => $_SERVER['HTTP_REFERER']];
            }
            $params += ['page_success' => $response->isSuccessful()];
            $params += ['page_message' => $response->getAnswer()];
            $params += ['form_data' => $response->getFormData()];

            if($_ENV['ENV'] == 'DEV') {
                $params += ['dev_mode' => true];
                $params += ['action_request' => $this->current_action ?? null];
                $params += ['view_request' => $this->current_view ?? null];
                $params += ['response' => $this->page_response ?? null];
                $params += ['view_routing' => $this->view_routing ?? null];
                $params += ['view_serving' => $this->view_serving ?? null];
                $params += ['action_routing' => $this->action_routing ?? null];
                $params += ['action_serving' => $this->action_serving ?? null];
            }
            
            $this->current_view->render($params);
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }

    /**
     * Initializes the application by iterating through all bundles and merging their views, actions, and models into the application's respective properties.
     *
     * @return void
     */
    public function initialize() {
        foreach($this->bundles as $bundle) {
            $bundle->initialize();
            $this->views = array_merge($this->views, $bundle->getViews());
            $this->actions = array_merge($this->actions, $bundle->getActions());
            $this->models = array_merge($this->models, $bundle->getModels());
        }
    }

    ##############################
    # Application configuration
    ##############################
    public function bundle(String $bundle_name,  ?Privacy $default_privacy = null): Application {
        $bundle = new Application($bundle_name, $default_privacy);
        $this->bundles[$bundle_name] = $bundle;
        return $bundle;
    }

    public function addBundle(Application $bundle): Application {
        $this->bundles[$bundle->getName()] = $bundle;
        return $this;
    }

    public function view(String $view_path, String|null $template, Method|Array|null $view_methods = null, Privacy|null $view_privacy = null, String|null $controller = null, String|null $function = null, String|null $success_location = null, bool $isForm = false): Application {
        $view_path = $this->path_prefix.$view_path;

        if(array_key_exists($view_path, $this->views)) {
            throw new Exception('A view with the path '.$view_path.' already exists.');
        }
        
        if($view_privacy == null) {
            if($this->default_privacy == null) {
                throw new Exception('No default privacy set for the application '.$this->name.'.');
            }
            $view_privacy = $this->default_privacy;
        }

        $view = new View($view_path, $view_methods, $view_privacy, $controller, $function, $success_location);
        $view->setForm($isForm);
        if($template != null) {
            $view->setTemplate($this->path_prefix.$template);
        }
        $this->views[$view_path] = $view;

        return $this;
    }

    public function addView(View $view): Application {
        $this->views[$view->getPath()] = $view;
        return $this;
    }

    public function form(String $view_path, String|null $template, String|null $controller, String|null $function, Privacy|null $view_privacy = null, String|null $success_location = null): Application {
        return $this->view($view_path, $template, [Method::POST, Method::GET], $view_privacy, $controller, $function, $success_location, true);
    }
    
    public function latteParams(Array $latter_params) {
        $this->default_latte_params = $latter_params;
        return $this;
    }

    #TODO: add default locations
    public function action(String $action_path, String $controller_name, String $function_name, Method|Array|null $action_method = Method::GET, Privacy|null $action_privacy = Privacy::LOGGED_IN, ?String $action_model = null): Application {
        $action_path = $this->path_prefix.$action_path;

        if(array_key_exists($action_path, $this->actions)) {
            throw new Exception('An action with the path '.$action_path.' already exists.');
        }

        if($action_privacy == null) {
            if($this->default_privacy == null) {
                throw new Exception('No default privacy set for the application '.$this->name.'.');
            }
            $action_privacy = $this->default_privacy;
        }

        $action = new Action($action_path, $action_method, $action_privacy, $controller_name, $function_name);
        $this->actions[$action_path] = $action;
        if($action_model !== null) {
            $action->setModel($action_model);
        }

        return $this;
    }

    public function addAction(Action $action): Application {
        $this->actions[$action->getPath()] = $action;
        return $this;
    }

    public function model(String $model_class_name, String $model_controller = ModelController::class, Privacy|null $action_privacy = Privacy::LOGGED_IN, Method|Array|null $action_method = null): Application {
        if(!class_exists($model_class_name)) {
            throw new Exception('The model class '.$model_class_name.' does
            not exist.');
        }
        
        $controller = new $model_controller();
        $action_model = null;

        if (!is_subclass_of($model_controller, ModelController::class) && $model_controller !== ModelController::class) {
            throw new Exception('The model controller '.$model_controller.' must be a subclass of ModelController.');
        }

        // If the model controller is the default ModelController, we need to give it the model associated
        if($model_controller == ModelController::class) {
            $controller->setModel($model_class_name);
            $action_model = $model_class_name;
        }

        $this->models[] = $model_class_name;

        foreach($controller->getApiEndpoints() as $endpoint) {
            $action = $model_class_name::getName().'/'.$endpoint->value;

            $this->action($action, $model_controller, $endpoint->value, $action_method ?? $endpoint->getMethod(), $action_privacy, $action_model);
        }
        

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

    public function getModels(): Array {
        return $this->models;
    }

    public function getUser() {
        return $this->user_id;
    }

    ##############################
    # Helper functions
    ##############################
    private function sanitizePath(String $path) {
        $path = trim($path, '/');
        return $path;
    }

    private function appendURI(String $uri, String $param_name, String $param_value) {
        if (strpos($uri, '?') !== false) {
            $params = explode('&', parse_url($uri, PHP_URL_QUERY));
            foreach ($params as $param) {
                $param_parts = explode('=', $param);
                if ($param_parts[0] === $param_name) {
                    return str_replace($param, $param_name . '=' . $param_value, $uri);
                }
            }
        }
        return $uri . '&' . $param_name . '=' . $param_value;
    }    
}