<?php

namespace pz\database;

use mysqli;
use Exception;

class Database {
    private $db_host;
    private $db_port;
    private $db_user;
    private $db_pass;
    private $db_name;
    public $conn;

    public function __construct() {

        ///// Uncomment theses lines bellow to debug unecessary database connections

        // $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        // $caller = $backtrace[1];
        // echo "Method called from: " . $caller['file'] . " on line " . $caller['line'].'<br>';
        
        $this->db_host = $_ENV['DB_HOST'];
        $this->db_port = $_ENV['DB_PORT'];
        $this->db_user = $_ENV['DB_USERNAME'];
        $this->db_pass = $_ENV['DB_PASSWORD'];
        $this->db_name = $_ENV['DB_NAME'];

        $this->conn = new mysqli($this->db_host, $this->db_user, $this->db_pass, $this->db_name, $this->db_port);
        if ($this->conn->connect_error) {
            throw new Exception("Connection failed: " . $this->conn->connect_error);
        }

    }    

    public function __destruct() {
        if($this->conn) {
            $this->conn->close();
        }
    }

    public static function runQuery($query, $paramTypes = '', ...$params) {
        $db = new Database();
        if (empty($params)) {
            return $db->conn->query($query);
        } else {
            $stmt = $db->conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare statement failed: " . $db->conn->error);
            }
            $stmt->bind_param($paramTypes, ...$params);
            $stmt->execute();
            return $stmt->get_result();
        }
    }

    public static function execute($query, $paramTypes = '', ...$params) {
        $db = new Database();
        $stmt = $db->conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement failed: " . $db->conn->error);
        }

        $paramTypes = str_replace(' ', '', $paramTypes);
        $paramTypes = str_replace(',', '', $paramTypes);

        $validParamTypes = str_replace(['s', 'i', 'd', 'b'], '', $paramTypes);
        if (!empty($validParamTypes)) {
            throw new Exception("Invalid paramTypes: " . $validParamTypes);
        }

        if (strlen($paramTypes) !== count($params)) {
            throw new Exception("Number of paramTypes does not match the number of parameters");
        }
        
        $stmt->bind_param($paramTypes, ...$params);
        $stmt->execute();
        return $stmt->insert_id;
    }

    public static function exportDatabase(bool $keep_data = false, bool $drop_tables_if_exist = false, bool $update_structure_file = true): String {
        $db = new Database();
        $tables = $db->conn->query("SHOW TABLES");
        $sql = "";
        while ($table = $tables->fetch_row()) {
            $table = $table[0];
            if ($drop_tables_if_exist) {
                $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            } 
            $createTable = $db->conn->query("SHOW CREATE TABLE `$table`")->fetch_row();
            $sql .= $createTable[1] . ";\n";
            if ($keep_data) {
                $rows = $db->conn->query("SELECT * FROM `$table`");
                while ($row = $rows->fetch_assoc()) {
                    $sql .= "INSERT INTO `$table` VALUES (";
                    $first = true;
                    foreach ($row as $value) {
                        if ($first) {
                            $first = false;
                        } else {
                            $sql .= ", ";
                        }
                        $sql .= "'" . $db->conn->real_escape_string($value) . "'";
                    }
                    $sql .= ");\n";
                }
            }
        }
        
        $filename = date('Y-m-d_H-i-s') . '_database.sql';
        $file = fopen(__DIR__.'/../../database/backups/'.$filename, 'w');
        fwrite($file, $sql);
        fclose($file);

        if($update_structure_file) {
            $file = fopen(__DIR__.'/../../database/structure.sql', 'w');
            fwrite($file, $sql);
            fclose($file);
        }

        return $filename;
    }

    public static function updateDatabaseStructure(?String $from_file = null): void {
        $db = new Database();
        if($from_file) {
            $sql = file_get_contents($from_file);
        } else {
            $sql = file_get_contents('database/structure.sql');
        }
        $db->conn->multi_query($sql);
    }
}

?>