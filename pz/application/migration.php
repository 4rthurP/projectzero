<?php

namespace pz;

use pz\database\Database;
use pz\Config;
use pz\Log;
use pz\Version;
use pz\Model;
use pz\AbstractModelAttribute;

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

/**
 * Model-aware migration helper.
 *
 * Sits on top of DbMigration and speaks in model attribute names instead of raw
 * columns. Column DDL is always derived from the attribute's own getSQLField(),
 * so a migration cannot drift from the model definition.
 */
class ModelMigration
{
    protected Model $model;
    protected DbMigration $db;
    public string $table;

    public function __construct(Model $model)
    {
        $this->model = $model;
        $this->table = $model->getModelTable();
        $this->db = new DbMigration($this->table);
    }

    /**
     * Creates the model's table from its attribute definitions.
     * The id column is declared first, mirroring Model::generateTableForModel().
     */
    public function createTable(): void
    {
        $id_key = $this->model->getIdKey();
        $fields = [];

        $id_attribute = $this->resolveColumn($id_key);
        $fields[] = $id_attribute->getSQLField();

        foreach ($this->model->getAttributes() as $name => $attribute) {
            if ($name === $id_key || !$this->isStoredColumn($attribute)) {
                continue;
            }
            $fields[] = $attribute->getSQLField();
        }

        $columns = implode(', ', $fields);
        $this->db->runSql("CREATE TABLE IF NOT EXISTS `{$this->table}` ($columns) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    /**
     * Drops the model's table.
     */
    public function dropTable(): void
    {
        $this->db->dropTable();
    }

    /**
     * Adds the column backing a model attribute, using the attribute's own SQL definition.
     *
     * @param string $name The model attribute name.
     * @param string|null $after Optional column to place the new column after.
     */
    public function addAttribute(string $name, ?string $after = null): void
    {
        $attribute = $this->resolveColumn($name);
        $sql = "ALTER TABLE `{$this->table}` ADD COLUMN {$attribute->getSQLField()}";
        if ($after !== null) {
            $sql .= " AFTER `$after`";
        }
        $this->db->runSql($sql . ';');
    }

    /**
     * Drops the column backing a model attribute.
     */
    public function removeAttribute(string $name): void
    {
        $attribute = $this->resolveColumn($name);
        $this->db->dropColumn($attribute->target_column);
    }

    /**
     * Renames the column backing a model attribute to match the current model definition.
     * The attribute (under its new name) must already exist in the model, as its current
     * SQL definition is used to restate the column type required by CHANGE COLUMN.
     *
     * @param string $oldColumn The existing column name in the database.
     * @param string $newName The model attribute name to rename the column to.
     */
    public function renameAttribute(string $oldColumn, string $newName): void
    {
        $attribute = $this->resolveColumn($newName);
        $this->db->runSql("ALTER TABLE `{$this->table}` CHANGE COLUMN `$oldColumn` {$attribute->getSQLField()};");
    }

    /**
     * Changes an existing column to match the model attribute's current type/constraints.
     */
    public function changeAttributeType(string $name): void
    {
        $attribute = $this->resolveColumn($name);
        $this->db->runSql("ALTER TABLE `{$this->table}` MODIFY COLUMN {$attribute->getSQLField()};");
    }

    /**
     * Resolves a model attribute by name and ensures it maps to a real column in this table.
     *
     * @throws \Exception If the attribute is unknown or is not stored in this table
     *                    (e.g. inversed or link-through relations).
     */
    protected function resolveColumn(string $name): AbstractModelAttribute
    {
        $attributes = $this->model->getAttributes();
        if (!isset($attributes[$name])) {
            throw new \Exception("The attribute '$name' does not exist on model " . $this->model::getName());
        }

        $attribute = $attributes[$name];
        if (!$this->isStoredColumn($attribute)) {
            throw new \Exception("The attribute '$name' is not stored as a column in table `{$this->table}`");
        }
        return $attribute;
    }

    /**
     * Whether the attribute is backed by a column in this model's own table
     * (mirrors the filtering in Model::getModelFieldsInDB()).
     */
    protected function isStoredColumn(AbstractModelAttribute $attribute): bool
    {
        if ($attribute->model_table !== $this->table) {
            return false;
        }
        return !($attribute->is_link && ($attribute->is_inversed || $attribute->is_link_through));
    }
}