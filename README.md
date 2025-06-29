# What is projectzero ?
projectzero is a php framework I developped for personal use.
It aims to be light, with low dependencies and with an easy to write / easy to understand syntax.

# Not using projectzero
## Should I use projectzero ?
No.

## Why ?
projectzero is probably not a good framework for you for a few reasons:
- It is under active developement and breaking changes can be frequent
- Security is not guaranteed
- There is little to no documentation 

# Using projectzero
## Reasons you would want to use projectzero
- You are me (weird)
- You only want to dev a personal project where security and stability is not a key factor

## Installation
Prerequisites: PHP (>= 8.4), the latest version of composer and a MySQL (>= 8.3) databasse
Installation should be relatively easy:
1. Clone the repo in your project folder
2. Copy the example.env as .env and fill the variables
3. At the root of the project folder run the two following composer commands: ```composer install``` and ```composer dump-autoload```

The recommanded deployment method is using docker compose and nginx as a reverse proxy
```
services:
    myapp:
        build:
        context: /path/to/your/app/
        dockerfile: Dockerfile
        args:
            USER_ID: "1000"
            GROUP_ID: "1000"
            PZ_FOLDER: "pz"
        restart: unless-stopped
        tty: true
        volumes:
        - /path/to/your/app/:/var/www/pz/

    #Nginx Service
    webserver:
        image: nginx:latest
        restart: unless-stopped
        tty: true
        ports:
            - "80:80"
            - "443:443"
        volumes:
            - /path/to/your/app/:/var/www/pz/

    #MySQL Service
    db:
        image: mysql:latest
        restart: always
        tty: true
        environment:
            SERVICE_NAME: mysql
            MYSQL_ROOT_PASSWORD: 
```

## Basic setup
projectzero revolves around four basic components
- Models and their properties
- Controllers
- Views
- Application and its submodules

### Models
Models are the basic data structure of projectzero. 
They are used to represent data and their properties and handle interactions with the database.
Models extend the `pz\model\Model` class and have have properties.
Properties  are used to define the data structure of the model and can be of different types (string, integer, boolean, etc.). Most of the time, you will create them in the `model()` method of the model class usind the `attribute()` method.

Example of a model:
```php
use pz\model\Model;
class Car extends Model {
    public static $name = "car";
    public static $bundle = "myapp";

    protected function model() {
        $this->attribute("name", AttributeType::CHAR, true);
        $this->attribute("model", AttributeType::CHAR, true);
        $this->attribute("n_wheels", AttributeType::INT);
    }
}
```

### Controllers
Controllers are where you define the logic of your application.
They receive requests, process them and return a response. They should extend the `pz\controller\Controller` class.
Example of a controller:
```php
use pz\controller\Controller;
use pz\routing\Request;
use pz\routing\Response;
use pz\routing\ResponseCode;
class CarController extends Controller {
    public function findCar(Request $request): Response {
        $id = $request->get("id");
        $car = Car::find($id);
        if(!$car) {
            return new Response(false, ResponseCode::BadRequestContent, 'car-not-found', 'index.php');
        }

        return new Reponse(true, ResponseCode::Ok, 'car-found', 'index.php', $car);
    }

    public function listCars(Request $request): Response {
        $cars = Car::list();
        return new Response(true, ResponseCode::Ok, 'cars-list', 'index.php', $cars);
    }
}
```

projectzero comes with a default `ModelController` that handles basic CRUD operations for models.

### Views
Views are the presentation layer of your application. 
Right now the default and only view engine is Latte's Nette.
Views can contain HTML, CSS and JavaScript and can be used to render data from the controllers.
The only requirement is that they use the '.latte' file extension.

### Application
The application is the main entry point of your projectzero application.
It is responsible for loading the configuration, initializing the database connection, authenticating users and routing requests to the appropriate controllers.

The content of the application is defined in the `application.php` file located in the root of your project folder.
Every part of your project should be defined in a submodule of the application.
You can then add pages (using the `page()` method), views (`view()`), actions (`action()`) and API endpoints (`api()` or `public_api()`) to the module.
```php
<?php
require_once 'dependencies.php';

use pz\Application;

$application = new Application('Arpege');
$default = $application->module('cars', Privacy::PUBLIC); #Adds a module named 'cars' with public access
$default->page('index'); #Adds a page named 'index' to the module, the app will look for a view named 'index.latte' in 'modules/cars/index/', you can also specify the location and template name
$default->page('list_cars', CarController::class, 'listCars'); #Adds a page that will call the listCars method of the CarController when accessed (the data returned by the method will be passed to the view) 
$default->page('find_car', null, ["POST" => [CarController::class, 'findCar']], 'base'); # Adds a findCar page which will call the register method of the UserController when a POST request is made
$default->action('car_id/get', CarControler::class, 'car_get_id', Method::GET, Privacy::LOGGED_IN); # Adds an endpoint that will call the get_nonce method of the UserController when a GET request is made, only logged in users can access it
```

That's it, now you can run the application by simply calling the `run()` method of the application in your `index.php` file:
```php
<?php
require_once '../application.php';
$application->run();
```

We recommand putting all the php files in the `pages/submodule_name/` folder (ie. `pages/cars/index.php`).

## In depth documentation
There is no proper documentation of the framework yet, but you can look at the source code of the framework to understand how it works (it lives in the `base/` folder).
Some interesting functionnalities you may like but are not documented yet:
- Databse and queries: projectzero comes with a custom database abstraction layer and a Query builder. Look into `base/database/Database.php` and `base/database/Query.php`
- Models offer various attributes and options to customize their behavior, especially linked attributes to other models. Look into `base/model/model.php`, `base/model/model_attribute.php`, `base/model/model_attribute_link.php` and `base/model/model_attribute_linkthrough.php`
- Config: env variables are loaded and can be accessed through an easy to use interface. Look into `base/utils/config.php`
- Logging: projectzero uses the `Monolog` library to log messages. Look into `base/utils/logging.php`
- Scheduler: projeczero comes with a build-in scheduler that can be used to run tasks at a specific time or interval. Look into `base/utils/scheduler.php`
- Testing: projectzero bundles the `PHPUnit` library to run tests. 

## Run test
If you are me (weird again), you can run the tests to make sure everything is working as expected using
```
./vendor/bin/phpunit tests
```