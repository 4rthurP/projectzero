<?php

namespace pz;

use pz\database\Database;
use pz\Config;
use pz\Log;
use pz\Version;

class Migration
{

    public readonly Version $version;
    protected string $backup_path;

    public function __construct(Version $version, string $migration_path)
    {
        $this->version = $version;
        $this->backup_path = $migration_path . '/backups/' . $version->version;
    }

    public function up()
    {
        // This method should be implemented by each migration to apply the changes
    }

    public function down()
    {
        // This method can be implemented by each migration to revert the changes (optional)
    }

    protected function step(string $function_name)
    {
        if (method_exists($this, $function_name)) {
            $start_time = microtime(true);
            Log::info("Running migration step: $function_name");
            $this->$function_name();
            $end_time = microtime(true);
            $execution_time = $end_time - $start_time;
            Log::info("Migration step '$function_name' completed in " . number_format($execution_time, 4) . " seconds.");

        } else {
            throw new \Exception("Migration step '$function_name' does not exist in " . get_class($this));
        }
    }

    protected function saveFile(string $filename, string $content)
    {
        $full_path = $this->backup_path . '/' . $filename;
        if (!is_dir(dirname($full_path))) {
            mkdir(dirname($full_path), 0755, true);
        }
        file_put_contents($full_path, $content);
    }

    protected function loadFile(string $filename): string
    {
        $full_path = $this->backup_path . '/' . $filename;
        if (!file_exists($full_path)) {
            throw new \Exception("Backup file not found: $full_path");
        }
        return file_get_contents($full_path);
    }
}

class DbMigration
{
    protected Database $db;
    public string $table;

    public function __construct(string $table)
    {
        $this->db = new Database();
        $this->table = $table;
    }

    public function createTable(array $columns)
    {
        $sql_query = "CREATE TABLE IF NOT EXISTS `{$this->table}` (";
        foreach ($columns as $name => $type) {
            $sql_query .= "`$name` $type,";
        }
        $sql_query = rtrim($sql_query, ',') . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';
        $this->db->execute($sql_query);
    }

    public function dropTable()
    {
        $sql_query = "DROP TABLE IF EXISTS `{$this->table}`;";
        $this->db->execute($sql_query);
    }

    public function addColumn(string $name, string $type, ?string $after = null)
    {
        $sql_query = "ALTER TABLE `{$this->table}` ADD COLUMN `$name` $type";
        if ($after !== null) {
            $sql_query .= " AFTER `$after`";
        }
        $sql_query .= ";";
        $this->db->execute($sql_query);
    }

    public function dropColumn(string $name)
    {
        $sql_query = "ALTER TABLE `{$this->table}` DROP COLUMN `$name`;";
        $this->db->execute($sql_query);
    }

    public function renameColumn(string $oldName, string $newName, string $type)
    {
        $sql_query = "ALTER TABLE `{$this->table}` CHANGE COLUMN `$oldName` `$newName` $type;";
        $this->db->execute($sql_query);
    }

    public function modifyColumn(string $name, string $newType)
    {
        $sql_query = "ALTER TABLE `{$this->table}` MODIFY COLUMN `$name` $newType;";
        $this->db->execute($sql_query);
    }

    public function addIndex(string $name, array $columns)
    {
        $columns_list = implode('`, `', $columns);
        $sql_query = "ALTER TABLE `{$this->table}` ADD INDEX `$name` (`$columns_list`);";
        $this->db->execute($sql_query);
    }

    public function dropIndex(string $name)
    {
        $sql_query = "ALTER TABLE `{$this->table}` DROP INDEX `$name`;";
        $this->db->execute($sql_query);
    }
    public function addForeignKey(string $name, string $column, string $referencedTable, string $referencedColumn, string $onDelete = 'CASCADE', string $onUpdate = 'CASCADE')
    {
        $sql_query = "ALTER TABLE `{$this->table}` ADD CONSTRAINT `$name` FOREIGN KEY (`$column`) REFERENCES `$referencedTable`(`$referencedColumn`) ON DELETE $onDelete ON UPDATE $onUpdate;";
        $this->db->execute($sql_query);
    }

    public function dropForeignKey(string $name)
    {
        $sql_query = "ALTER TABLE `{$this->table}` DROP FOREIGN KEY `$name`;";
        $this->db->execute($sql_query);
    }

    public function runSql(string $sql)
    {
        $this->db->execute($sql);
    }

    public function backupTableContent()
    {
        $n_rows = $this->db->execute("SELECT COUNT(*) AS count FROM `{$this->table}`")->fetch_assoc()['count'];
        $batch_size = 1000;
        for ($offset = 0; $offset < $n_rows; $offset += $batch_size) {
            $result = $this->db->execute("SELECT * FROM `{$this->table}` LIMIT $offset, $batch_size");
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return json_encode($rows);
    }

    public function restoreTableContent(string $json)
    {
        $rows = json_decode($json, true);
        foreach ($rows as $row) {
            $columns = array_keys($row);
            $values = array_values($row);
            $columns_list = implode('`, `', $columns);
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $sql_query = "INSERT INTO `{$this->table}` (`$columns_list`) VALUES ($placeholders);";
            $this->db->execute($sql_query, $values);
        }
    }
}