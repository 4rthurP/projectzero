<?php
require_once 'app/application.php';

if($response->hasRedirect()) {
    header($response->getRedirect());
    exit();
}

if($application->getUser() !== null) {
    if($_GET['FROM'] ?? null) {
        header('Location: /' . $_GET['FROM']);
    } 
    
    header('Location: /');
    exit();
}

$page_params = [
    'messages' => $response->getMessages(),
    'from' => $_GET['FROM'] ?? null
];

$application->render($page_params);
?>