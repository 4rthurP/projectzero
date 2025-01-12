<?php

include_once 'dependencies.php';
use pz\Controllers\UserController;

if(isset($_COOKIE['user_id'])) {
    header('Location: /index.php');
    exit();
}

$controller = new UserController();

try {
    $creation_result = $controller->register($_POST);
} catch(Exception $e) {
    $creation_result = ['success' => false, 'exception' => $e->getMessage()];
}

if($creation_result['success']) {
    if(isset($creation_result['header'])) {
        header($creation_result['header']);
        exit;
    } 

    header('Location: /index.php');
    exit;
    
} else {
    if(isset($creation_result['header'])) {
        header($creation_result['header']);
        exit;
    }
    if(isset($creation_result['message'])) { 
        header('Location: /register.php?error=' . $creation_result['message']);
        exit;
    }
    if(isset($creation_result['exception'])) {
        header('Location: /register.php?error=exception&message=' . $creation_result['exception']);
        exit;
    }
    
    header('Location: /register.php?error=unknown');
    exit;
}

?>