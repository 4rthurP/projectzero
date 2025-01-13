<?php
require_once 'app/application.php';

use pz\database\Database;

if($response->hasRedirect()) {
    header($response->getRedirect());
    exit();
}

$page_params = [];
$application->render($page_params);
?>