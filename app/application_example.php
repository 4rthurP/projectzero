<?php
require_once 'dependencies.php';

use pz\Enums\Routing\{Privacy, Method};
use pz\Application;
use pz\Models\{User};
use pz\Controllers\{UserController, AdminController};

$application = new Application('Arpege');
$default = $application->bundle('default');
$default->view('index.php', 'index.latte', Method::GET, Privacy::PUBLIC);
$default->form('register.php', 'register.latte', UserController::class, 'register', Privacy::PUBLIC, 'index.php');
$default->action('nonce/get', UserController::class, 'get_nonce', Method::GET, Privacy::LOGGED_IN);
$default->action('user/login', UserController::class, 'login', Method::POST, Privacy::PUBLIC);
$default->action('user/logout', UserController::class, 'logout', Method::POST, Privacy::LOGGED_IN);
$default->model(User::class, UserController::class);

$admin = $application->bundle('admin', Privacy::LOGGED_IN);
$admin->view('index.php', 'index.latte', Method::GET);
$admin->view('wines.php', 'wines.latte', Method::GET);
$admin->action('wines/regenerate_all_display_names', AdminController::class, 'regenerate_all_display_names', Method::GET, Privacy::PUBLIC);
$admin->action('admin/initializeDatabase', AdminController::class, 'initialize_database', Method::POST, Privacy::LOGGED_IN);

$response = $application->run();
?>