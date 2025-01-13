<?php
require_once 'app/application.php';

use pz\Controllers\BaseAdminController;
use pz\Routing\Request;
use pz\database\Database;

if($response->hasRedirect()) {
    header($response->getRedirect());
    exit();
}


$user_class = $application->getUserClass();
if($user_class != null) {
    $user_table_name = (new $user_class())->getModelTable();
    $user_table_defined = Database::runQuery("SHOW TABLES LIKE '$user_table_name'")->num_rows > 0;
    if($user_table_defined) {
        header('Location: /index.php');
    }

    $admin_controller = new BaseAdminController();
    $database_request = new Request();
    $database_request->addData('models', implode(',' ,$application->getModels()));
    $database_response = $admin_controller->initialize_database($database_request);
    header('Location: /register.php');
} else {
    header('Location: /index.php');
}

$page_params = [];
$application->render($page_params);
?>