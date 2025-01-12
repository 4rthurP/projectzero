<?php

require_once __DIR__ . '/vendor/autoload.php';

session_start();

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__, '../conf.env');
$dotenv->load();

require_once 'base/enums/database/query_links.php';
require_once 'base/enums/database/query_operators.php';
require_once 'base/enums/database/query_type.php';
require_once 'base/enums/model/right.php';
require_once 'base/enums/model/attribute_type.php';
require_once 'base/enums/routing/privacy.php';
require_once 'base/enums/routing/method.php';
require_once 'base/enums/routing/model_endpoint.php';
require_once 'base/enums/routing/response_code.php';

require_once 'base/database/database.php';
require_once 'base/database/query.php';
require_once 'base/database/query_model.php';
require_once 'base/database/where_clause.php';
require_once 'base/database/where_group.php';

require_once 'base/routing/route.php';
require_once 'base/routing/action.php';
require_once 'base/routing/view.php';
require_once 'base/routing/request.php';
require_once 'base/routing/response.php';

require_once 'base/application.php';
require_once 'base/controller.php';
require_once 'base/admin_controller.php';
require_once 'base/models/model.php';
require_once 'base/models/model_attribute.php';
require_once 'base/models/model_attribute_link.php';
require_once 'base/models/model_attribute_linkthrough.php';
require_once 'base/models/model_controller.php';

require_once 'base/users/nonce.php';
require_once 'base/users/user.php';
require_once 'base/users/user_controller.php';

$controllersPath = __DIR__ . '/controllers';
$controllerFiles = glob($controllersPath . '/*.php');
foreach ($controllerFiles as $file) {
    require_once $file;
}

$modelsPath = __DIR__ . '/models';
$modelsFiles = glob($modelsPath . '/*.php');
foreach ($modelsFiles as $file) {
    require_once $file;
}

require_once('application.php');
?>