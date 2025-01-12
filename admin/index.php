<?php
require_once '../app/application.php';
use pz\database\Database;

if($response->hasRedirect()) {
    header($response->getRedirect());
    exit();
}

$database_is_ready = Database::runQuery("SHOW TABLES LIKE 'users';")->num_rows > 0;

$model_list = $application->getModels() ;
$models = [];
foreach($model_list as $model) {
    $object = new $model();
    $models[] = ['model' => $model, 'name' => $object->getName(), 'bundle' => $object->getBundle(), 'table' => $object->getModelTable(), 'viewing' => $object->getViewingPrivacy(), 'editing' => $object->getEditingPrivacy()];
}

$page_params = [
    'actions' => $application->getActions(),
    'models' => $models,
    'model_list' => implode(',', $model_list),
    'database_is_ready' => $database_is_ready,
];

$application->render($page_params);
?>