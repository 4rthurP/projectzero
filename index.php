<?php
require_once 'app/application.php';

if($response->hasRedirect()) {
    header($response->getRedirect());
    exit();
}

$page_params = [];
$application->render($page_params);
?>