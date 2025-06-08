<?php

namespace pz\database;

use mysqli;
use Exception;
use mysqli_result;
use PhpParser\Node\Expr\Exit_;

class Database
{
    private $db_host;
    private $db_port;
    private $db_user;
    private $db_pass;
    private $db_name;
    public $conn;

    public function __construct(?string $db_name = null)
    {

        ///// Uncomment theses lines bellow to debug unecessary database connections

        // $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        // $caller = $backtrace[1];
        // echo "Method called from: " . $caller[''] . " on line " . $caller['line'].'<br>';

        $this->db_host = $_ENV['DB_HOST'];
        $this->db_port = $_ENV['DB_PORT'];
        $this->db_user = $_ENV['DB_USERNAME'];
        $this->db_pass = $_ENV['DB_PASSWORD'];
        $this->db_name = $db_name ?? $_ENV['DB_NAME']; # Default database can be overriden by passing a database name as parameter

        $this->conn = new mysqli(
            $this->db_host,
            $this->db_user,
            $this->db_pass,
            $this->db_name,
            $this->db_port
        );

        if ($this->conn->connect_error) {
            throw new Exception("Connection failed: " . $this->conn->connect_error);
        }
    }

    /**
     * Closes the database connection when the object is destroyed.
     */
    public function __destruct()
    {
        if ($this->conn) {
            $this->conn->close();
        }
    }

    /**
     * Executes a database query with optional parameters.
     *
     * @param string $query The SQL query to execute.
     * @param string $paramTypes (optional) The parameter types for prepared statement.
     * @param mixed ...$params (optional) The parameters for prepared statement.
     * @return mysqli_result|int|string|bool The result of the executed statement.
     */
    public static function execute($query, $paramTypes = '', ...$params): mysqli_result|int|string|bool
    {
        $static = new static();

        $stmt = $static->handleQuery(
            $query,
            $paramTypes,
            ...$params
        );

        return $stmt;
    }

    /**
     * Inserts new record(s) into the specified table.
     *
     * @param string $table The name of the table to insert the record into.
     * @param array $columns An array of column names.
     * @param array $values An array of values to be inserted.
     * @return mixed The result of the insert query.
     */
    public static function insert(string $table, array $columns, array $values)
    {
        $static = new static();

        if(!is_array($values[0])) {
            $values = [$values];
        }

        $params = [];

        foreach($values as $line) {
            if(count($line) != count($values[0])) {
                throw new Exception('Wrong number of arguments in insert - All lines needs to have the same number of values.');
            }

            $params = array_merge($params, $line);
        }

        $query = "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES ";
        $query .= implode(
            ', ', 
            array_fill(
                0, 
                count($values), 
                "(" . implode(
                    ', ', 
                    array_fill(
                        0, 
                        count($values[0]),
                        '?'
                    )
                ) . ")"
            )
        );

        $stmt = $static->handleQuery(
            $query,
            str_repeat(
                's', 
                count($values) * count($values[0])
            ),
            ...$params
        );

        return $stmt;
    }

    /**
     * Exports the database structure and optionally the data to a SQL file.
     *
     * @param bool $keep_data Whether to include data in the exported SQL file.
     * @param bool $drop_tables_if_exist Whether to include DROP TABLE statements for existing tables.
     * @param bool $update_structure_file Whether to update the structure.sql file with the exported SQL.
     * @return string The filename of the exported SQL file.
     */
    public static function exportDatabase(
        bool $keep_data = false,
        bool $drop_tables_if_exist = false,
        bool $update_structure_file = true
    ): String {
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

        # Save the SQL to a file
        $filename = date('Y-m-d_H-i-s') . '_database.sql';
        $file = fopen(__DIR__ . '/../../database/backups/' . $filename, 'w');
        fwrite($file, $sql);
        fclose($file);

        # Update the structure file
        if ($update_structure_file) {
            $file = fopen(__DIR__ . '/../../database/structure.sql', 'w');
            fwrite($file, $sql);
            fclose($file);
        }

        return $filename;
    }

    /**
     * Updates the database structure from a file.
     *
     * @param string $from_file The file to update the database structure from.
     * @return void
     */
    public static function updateDatabaseStructure(?String $from_file = null): void
    {
        $db = new Database();
        if ($from_file) {
            $sql = file_get_contents($from_file);
        } else {
            $sql = file_get_contents('database/structure.sql');
        }
        $db->conn->multi_query($sql);
    }

    #############################
    # Internal helper functions 
    #############################
    /**
     * Executes a database query with optional parameter binding.
     *
     * @param string $query The SQL query to execute.
     * @param string $paramTypes (optional) The types of the parameters to bind.
     * @param mixed ...$params (optional) The parameters to bind to the query.
     * @return mysqli_result|int|string|bool The result of the query, depending on the type of query executed.
     * @throws Exception If there is an error executing the query.
     */
    protected function handleQuery($query, $paramTypes = '', ...$params): mysqli_result|int|string|bool
    {
        $db = new Database();

        try {
            $time = microtime(true);

            if (empty($params)) {
                return $db->conn->query($query);
            }

            $stmt = $db->conn->prepare($query);

            if (strlen($paramTypes) > 0) {
                $stmt = $this->bindParams($stmt, $paramTypes, $params);
            }

            $stmt->execute();

            $time = microtime(true) - $time;
            $this->logQuery($query, $time);

            // Return the result of the query
            if ($stmt->field_count > 0) {
                // SELECT queries return results
                return $stmt->get_result();
            } elseif ($stmt->insert_id > 0) {
                // INSERT queries return the inserted ID
                return $stmt->insert_id;
            } elseif ($stmt->affected_rows >= 0) {
                // UPDATE, DELETE, etc. return affected rows
                return $stmt->affected_rows;
            } else {
                // CREATE, DROP, etc. return true
                return true;
            }
        } catch (Exception $e) {
            if(isset($stmt) && $stmt->error != null) {
                throw new Exception("Error while executing query: " . $stmt->error . '<br>Query: ' . $query . '<br>Params: ' . json_encode($params));
            } else {
                throw $e;
            }
        }
    }

    /**
     * Binds parameters to a prepared statement.
     *
     * @param object $stmt The prepared statement object.
     * @param string $paramTypes A string representing the types of the parameters.
     * @param array $params An array of values to bind to the prepared statement.
     * @return object The prepared statement object with the parameters bound.
     * @throws Exception If the paramTypes string contains invalid characters or if the number of paramTypes does not match the number of parameters.
     */
    private function bindParams($stmt, $paramTypes, $params)
    {
        # Remove all spaces and commas from the paramTypes string
        $paramTypes = str_replace(' ', '', $paramTypes);
        $paramTypes = str_replace(',', '', $paramTypes);

        # Check if the paramTypes string contains only valid characters
        $validParamTypes = str_replace(['s', 'i', 'd', 'b'], '', $paramTypes);
        if (!empty($validParamTypes)) {
            throw new Exception("Invalid paramTypes: " . $validParamTypes);
        }

        # We need to throw an exception if an array is passed as a parameter because the default behavior of bind_param is to print an error message and do nothing...
        foreach($params as $param) {
            if (is_array($param)) {
                throw new Exception("Array parameters are not supported on SQL queries - tried to bind " . json_encode($param) . json_encode(debug_backtrace($limit = 2)));
            }
        }

        # Check if the number of paramTypes matches the number of parameters
        if (strlen($paramTypes) !== count($params)) {
            throw new Exception("Number of paramTypes does not match the number of parameters");
        }
        
        $stmt->bind_param($paramTypes, ...$params);

        return $stmt;
    }

    /**
     * Logs a database query along with its execution time and calling stack trace.
     *
     * @param string $query The database query to be logged.
     * @param float $time The execution time of the query.
     * @return void
     */
    private function logQuery($query, $time)
    {
        if ($time < 0.005) {
            return;
        }

        $text = date('Y-m-d H:i:s') . ' - Done in ' . $time . 's : ' . $query . PHP_EOL;
        $debug_backtrace = debug_backtrace();
        foreach ($debug_backtrace as $trace) {
            $text .= 'Called from: ' . $trace['file'] . ' on line ' . $trace['line'] . PHP_EOL;
        }
        $text .= '----------------------------------------' . PHP_EOL;
        file_put_contents($_ENV['APP_PATH'] . '/database/queries.log', $text, FILE_APPEND);
    }
}
