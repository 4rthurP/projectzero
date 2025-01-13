<?php

namespace pz\Controllers;

use pz\Controller;
use pz\database\Database;
use pz\Routing\Request;
use pz\Routing\Response;
use pz\Enums\Routing\ResponseCode;

class BaseAdminController extends Controller {
    public function initialize_database(Request $request): Response {
        $model_list = $request->getData('models');
        $result = $this->generateApplicationDatabase(explode(',', $model_list));
        return new Response(true, ResponseCode::Ok, ['result' => $result], 'The database has been initialized.');
    }

    public function check_database(Request $request): Response {
        $model_list = $request->getData('models');
        $generate_if_not_successful = $request->getData('generate_if_not_successful');
        $result = $this->checkApplicationDBAdequation(explode(',', $model_list), $generate_if_not_successful ?? false);
        return new Response(true, ResponseCode::Ok, ['result' => $result], 'Database check completed.');
    }

    ##############################
    # Static methods to handle the database
    ##############################
    private function generateApplicationDatabase(Array $model_list, bool $update_table_if_exist = true, $drop_tables_if_exist = false, $force_table_update = false): String {
        //First we initialize the database with the framework's internal tables
        $db = new Database();
        $sql = file_get_contents(__DIR__.'/database/pz_database.sql');
        $db->conn->multi_query($sql);

        //Then we generate the tables for all the models used in the project (we disables the update_table_if_exist option to only do it once at the end)
        foreach($model_list as $model) {
            $model::generateTableForModel(false, $update_table_if_exist, $drop_tables_if_exist, $force_table_update);
        }

        //Finally we backup the database structure
        return Database::exportDatabase();
    }

    private function checkApplicationDBAdequation(Array $model_list, bool $generate_if_not_successful = false): Array {
        $result = [];
        $success = true;
        foreach($model_list as $model) {
            $model_adequation = $model::checkModelDBAdequation();
            $success = $success === false ? $success : $model_adequation['success'];
            $result[$model] = $model_adequation;
        }

        if($generate_if_not_successful && !$success) {
            $result['generation'] = $this->generateApplicationDatabase($model_list);
        }

        return $result;
    }
}
?>