<?php

namespace pz;

use Exception;
use pz\ApplicationBase;
use pz\Enums\Routing\Privacy;
use pz\Enums\Routing\Method;
use pz\Enums\Routing\ResponseCode;
use pz\Routing\Route;
use pz\Routing\View;
use pz\Routing\Action;
use pz\Routing\Response;
use pz\Routing\Request;

use pz\Config;
use pz\Models\User;

class Application extends ApplicationBase {
    protected $user_class = User::class;
    protected Array $default_latte_params = [];
    protected bool $auto_render = true;

    protected ?Request $request;

    protected ?View $current_view;
    protected ?Route $current_action;
    protected ?Response $page_response; //Global response for the page
    protected ?Response $view_routing; //Response for the view routing
    protected ?Response $view_serving; //Response for the view serving
    protected ?Response $action_routing; //Response for the action routing
    protected ?Response $action_serving; //Response for the action serving

    protected $user_id;

    /**
     * Runs the application by initializing the necessary components, handling the user session, processing the request, and serving the appropriate response.
     *
     * @param bool|null $auto_render Whether to automatically render the view after processing the request. If null, the application's default setting is used.
     * @param array|null $request_params An associative array of additional parameters to be passed to the request.
     * @return Response The response object containing the result of the request processing.
     */
    public function run(?bool $auto_render = null, ?Array $request_params = null): Response {
        // Load the application modules and initialize the application
        $this->initialize();

        // In development mode, we check that the application is properly defined
        if(Config::env() == 'DEV') {
            $this->checkDefinition();
        }
        
        // Find the request params and action
        $this->request = new Request();

        $current_script = $this->sanitizePath($_SERVER['SCRIPT_NAME']);
        $this->request->setAction($current_script);

        $attributes_array = array_merge($_GET, $_POST);
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->request->setMethod(Method::GET);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Request method
            $this->request->setMethod(Method::POST);
        } else {
            return new Response(false, ResponseCode::InvalidRequestMethod, 'Invalid request method '.$_SERVER['REQUEST_METHOD'], 'index.php');
        }

        // Add the additional request params to the attributes array (params passed via the method params are prioritized)
        if($request_params != null) {
            $attributes_array = array_merge($attributes_array, $request_params);
        }
        
        // Request data
        $this->request->setData($attributes_array);
        // Request action
        if(isset($attributes_array['action'])) {
            $this->request->setAction($attributes_array['action']);
        }

        // Eventual redirections
        if(isset($_GET['from'])) {
            // For forms the redirection can be given in the url param so we can not simply test the attribute array
            $this->request->onSuccess($_GET['from']);
        } elseif(isset($attributes_array['from'])) {
            $this->request->onSuccess($attributes_array['from']);
        } 
        if(isset($attributes_array['error'])) {
            $this->request->onError($attributes_array['error']);
        }


        // Handle authentication
        if(isset($_SESSION['user']) || isset($_COOKIE['user_session_token'])){
            $auth = new Auth($this->request->data(), $this->user_class);
            if(isset($_SESSION['user'])) {
                $auth->loadFromSession();
            } else {
                $auth->retrieveSession($_COOKIE['user_session_token']);
            }

            if($auth->isValid()) {
                $this->user_id = $auth->getUserId();
                $this->request->setAuth($auth);
            } else {
                Auth::logout();
                return new Response(
                    false, 
                    ResponseCode::Unauthorized,
                    $auth->getErrorMessage(), 
                    '/index.php?error)' . $auth->getError(),
                );
            }
        }

        // Start processing the request
        try {
            $response = $this->handleRequest($current_script);
        } catch (Exception $e) {
            if(Config::env() == 'DEV') {
                throw $e;
            } else {
                $response = new Response(false, ResponseCode::InternalServerError, $e->getMessage(), '/');
            }
        }

        // When auto render is enabled we take care of redirections and view rendering
        $auto_render = $auto_render ?? $this->auto_render;
        if($auto_render == true) {
            // If the response has a redirection, we handle it
            if($response->hasRedirect()) {
                header($response->getRedirect());
                exit();
            }
            
            // If the response is successful and we have a view to serve, we render it
            if($this->current_view != null && isset($this->view_serving) && isset($this->view_routing)) {
                if($this->view_routing->isSuccessful() && $this->view_serving->isSuccessful()) {
                    $this->render();
                }
            }
        }
        
        # If the user was authenticated during the request, a new nonce was generated
        # and we need to set it in the response
        # This is handled by the app to avoid loosing the new nonce if the request failed for reasons other than authentication
        if($this->request->isAuthenticated()) {
            $response->setNonce($this->request->nonce(), $this->request->nonceExpiration());
        }

        return $response;
    }

    private function handleRequest(String $current_script): Response {
        $action = null;
        $view = null;
        
        // Findings if there is a view associated to this page and checking it
        if(array_key_exists($current_script, $this->views)) {
            $view_params = $this->views[$current_script];
            $view = $this->buildRoute(...$view_params);
            $view->setParams($this->default_latte_params);
            $this->current_view = $view;
        } 
        // Findings if there is an action associated to this request, if so we check and then serve it
        $action_path = $this->request->getAction();
        if($action_path != null && array_key_exists($action_path, $this->actions)) {
            $actions = $this->actions[$action_path];
            foreach($actions as $action_params) {
                $action = $this->buildRoute(...$action_params);
                if($view != null && !$action->hasMethod($this->request->getMethod())) {
                    Log::warning("WTF is this statement ???");
                    // Action routing can fail if we try to access a page with both a POST and GET method, in this case we just need to serve the view
                    $action = null;
                } else {
                    $this->current_action = $action;
                    
                    $this->action_routing = $action->check($this->request);
                    if(!$this->action_routing->success) {   
                        $this->page_response = $this->action_routing;
                        return $this->page_response;
                    }

                    // Save the latest response
                    $this->action_serving = $action->serve($this->request);

                    if(isset($this->page_response)) {
                        // Merge with previous data
                        // "keep_current_data" is set to true to prioritize the data currently in the response (ie. the latest received action response)
                        $this->action_serving->mergeData($this->page_response->data(), true);
                    }
                    // Save the global response
                    $this->page_response = $this->action_serving;
    
                    // If the action response is not successful,or has a redirection we return it
                    if($this->page_response->hasRedirect() || !$this->page_response->isSuccessful()) {
                        return $this->page_response;
                    }
                    
                    // If we have no redirection and it is not an API call, we set the current method to GET to be able to serve the view
                    $this->request->setMethod(Method::GET);
                }
            }

            if($view == null) {
                // We have no view associated to this action, we can return the current response
                return $this->page_response;
            }
        }
        
        // If we have no action nor view, there is an error in the request
        if($action == null && $view == null) {
            $message = 'No action or view found for this request';
            if(Config::env() == 'DEV') {
                // In development mode we add information about this request to the error message
                if($action_path != null) {
                    $message .= ' - Action path: ' . $action_path;
                }
                $message .= ' - Current script: ' . $current_script;
                // In development mode, we log the error to help debugging
                Log::error($message);
            }
            return new Response(false, ResponseCode::NotFound, $message, '/');
        }

        // View routing needs to be successful to continue but it will only be served last
        $this->view_routing = $view->check($this->request);
        if(!$this->view_routing->isSuccessful()) {
            $this->page_response = $this->view_routing;
            return $this->page_response;
        }
        
        // At this point all is good and we can serve the view
        $this->view_serving = $view->serve($this->request);
        if($action == null) {
            $this->page_response = $this->view_serving;
        }

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
    public function render(?Array $params = null) {
        if(!isset($this->current_view)) {
            return;
        }
        
        if($this->current_view === null) {
            return;
        }

        if($params == null) {
            $params = [];
        }
        
        try {
            $response = $this->page_response;

            if(isset($this->action_serving)) {
                $params += ['form_data' => $this->action_serving->data()];
                $params += ['form_messages' => $this->action_serving->dataMessages()];
                $response = $this->action_serving;
            }
            
            if(isset($_SERVER['HTTP_REFERER'])) {
                $params += ['previous_page_url' => $_SERVER['HTTP_REFERER']];
            }
            $params += ['page_success' => $response->isSuccessful()];
            $params += ['page_message' => $response->getAnswer()];
            
            if(Config::env() == 'DEV') {
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
     * Initializes the application by iterating through all modules and merging their views, actions, and models into the application's respective properties.
     *
     * @return void
     */
    public function initialize() {
        // Loads the application modules
        parent::initialize();

        // Check app is properly setup (ie. a lock file exists indicating that the database has been initialized)
        $this->checkSetup();
    }

    ##############################
    # Application configuration
    ##############################
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

    /**
     * Sets the user class of the application.
     *
     * @param string|null $user_class The user class to set. Can be null.
     * @return $this
     */
    public function setUserClass(?String $user_class) {
        $this->user_class = $user_class;
        return $this;
    }

    ##############################
    # Getters
    ##############################
    public function getUserClass(): ?String {
        return $this->user_class;
    }

    #TODO: remove when controllers are of use
    public function getUser() {
        return $this->user_id;
    }

    ##############################
    # Helper functions
    ##############################
    /**
     * Builds a route of the given class with the provided parameters.
     *
     * @param String $route_class The class name of the route to be built.
     * @param String $path The path of the route.
     * @param String|null $controller_class The class name of the controller associated with the route, if any.
     * @param String|null $function_name The name of the function in the controller to be called, if any.
     * @param Method|null $method The HTTP method for the route, if any.
     * @param String|null $success_location The location to redirect to on success, if any.
     * @param String|null $error_location The location to redirect to on error, if any.
     * @param Privacy|null $privacy The privacy level of the route, if any.
     * @param String|null $associated_construct The associated construct (model or template) for the route, if any.
     * 
     * @return Route|View Returns an instance of the specified route class, either a Route or a View.
     * 
     * This method is used to create easily create a route from the stored parameters in the application.
     * 
     */
    protected function buildRoute(
        String $route_class, 
        String $path, 
        ?String $controller_class,
        ?String $function_name,
        ?Method $method, 
        ?String $success_location = null,
        ?String $error_location = null,
        ?Privacy $privacy = null,
        ?String $associated_construct = null,
    ): Route|View {
        // Applications without user classes should have all endpoints set to public
        if($this->user_class == null) {
            $privacy = Privacy::PUBLIC;
        }

        // Create the route
        $route = new $route_class(
            $path, 
            $method, 
            $privacy, 
            $controller_class, 
            $function_name, 
            $success_location, 
            $error_location
        );

        // Adds the given model for actions or template for views
        if($associated_construct != null) {
            if($route_class == Action::class) {
                $route->setModel($associated_construct);
            } else if($route_class == View::class) {
                $route->setTemplate($associated_construct);
            }
        }
        return $route;
    }

    /**
     * Checks if the application is properly set up.
     *
     * This method checks for the existence of a lock file that indicates the application has been initialized.
     * If the lock file does not exist and the current script is not 'install.php', it redirects the user to 'install.php'.
     *
     * @return void
     */
    protected function checkSetup() {
        if (!file_exists(Config::app_path() . 'app/initialized.lock') && $_SERVER['SCRIPT_NAME'] != '/install.php') {
            $_SESSION['user'] = null;
            header('Location: /install.php');
            exit();
        }
    }

    /**
     * Sanitizes a given path by trimming leading and trailing slashes.
     *
     * @param String $path The path to be sanitized.
     * @return String The sanitized path.
     *
     * This method is used to ensure that paths are consistently formatted without leading or trailing slashes.
     */
    protected function sanitizePath(String $path) {
        $path = trim($path, '/');
        return $path;
    }

    /**
     * Internal function to check that the application endpoints are valid
     * This function checks that all models and views are properly defined and that the application is set up correctly.
     * It throws an exception if any of the checks fail.
     */
    protected function checkDefinition() {
        // Initialization is needed to ensure that each module's content is loaded
        if(!$this->is_initialized) {
            $this->initialize();
        }

        // Check application models 
        foreach($this->models as $model_class_name => $model_controller) {
            if(!class_exists($model_class_name)) {
                throw new Exception('The model class '.$model_class_name.' does
                not exist.');
            }

            $model_controller = $this->models[$model_class_name];
            if (!is_subclass_of($model_controller, ModelController::class) && $model_controller !== ModelController::class) {
                throw new Exception('The model controller '.$model_controller.' must be a subclass of ModelController.');
            }
        }

        // Check application views
        foreach($this->views as $view_path => $view_params) {
            $view = $this->buildRoute(...$view_params);
            if(!($view instanceof View)) {
                throw new Exception('The view '.$view_path.' is not a valid View instance.');
            }

            $template = $view->getTemplate();
            if($template == null) {
                throw new Exception('The view '.$view_path.' does not have a template defined.');
            }

            if(!file_exists($template)) {
                throw new Exception('The view template '.$template.' does not exist.');
            }

            // We do not need to check the controller class here, as it is taken care of by the Route class in __construct
        }

        // Check application actions
        foreach($this->actions as $action_path => $actions) {
            foreach($actions as $action_params) {
                $action = $this->buildRoute(...$action_params);
                if(!($action instanceof Action)) {
                    throw new Exception('The action '.$action_path.' is not a valid Action instance.');
                }

                // We do not need to check the controller class here, as it is taken care of by the Route class in __construct
            }
        }
    }   
}