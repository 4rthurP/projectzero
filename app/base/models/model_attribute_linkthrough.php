<?php

namespace pz;

use Exception;
use DateTime;
use DateTimeZone;
use pz\database\{Database, Query, ModelQuery};
use pz\Enums\model\AttributeType;
use Throwable;

class ModelAttributeLinkThrough extends ModelAttributeLink
{
    public bool $is_link_through = true;

    public readonly String|null $link_through_table;
    public readonly String|null $link_through_model_column;
    public readonly String|null $link_through_target_column;
    public readonly String|null $link_through_model_type;
    public readonly String|null $link_through_target_type;

    public function __construct(String $name, object $model, object $target, String $model_column, String $target_column, String $n_links = 'one', bool $is_inversed = false, ?String $link_through_table = null, ?String $link_through_model_column = null, ?String $link_through_target_column = null, ?String $link_through_model_type = null, ?String $link_through_target_type = null)
    {
        parent::__construct($name, $model, $target, $model_column, $target_column, $n_links, $is_inversed, false, null);

        $this->link_through_model_column = $link_through_model_column;
        $this->link_through_target_column = $link_through_target_column;
        $this->link_through_model_type = $link_through_model_type;
        $this->link_through_target_type = $link_through_target_type;

        $this->link_through_table = $link_through_table ?? $this->makeRelationTableName('link');

        return $this;
    }

    protected function get(bool $as_object = false)
    {
        if($as_object == true) {
            return $this->value;
        }

        return $this->value->map(fn($item) => $item->toArray());
    }

    /**
     * Loads the attribute value from the database for the specified object
     * Warning : this method creates a database call, for attributes that are stored within the model table, using the model's load function would be more efficient when laoding multiple attributes
     *
     * @param int $object_id The ID of the object.
     * @param int|null $relation_id The ID of the relation (optional).
     * @return $this The current instance of the model attribute.
     * @throws Exception If the relation ID is not passed when the attribute is inversed.
     */
    protected function fetch(): void {
        if($this->target_id === null) {
            $list_of_values = Query::from($this->link_through_table)->get($this->link_through_target_column)->where($this->link_through_model_column, $this->object_id);

            if($this->link_through_model_type !== null) {
                $list_of_values->where($this->link_through_model_type, $this->model);
            }
            if($this->link_through_target_type !== null) {
                $list_of_values->where($this->link_through_target_type, $this->target::getName());
            }
    
            $list_of_values = $list_of_values->fetchAsArray();
            $this->target_id = array_map(fn($item) => $item['id'], $list_of_values);
        }


        #TODO: test this + check that we have at most one link of 'one' relations and more than 0 for required attributes
        return;
    }

    /**
     * This method only trigger an UPDATE query with the current attribute value
     * To change and update a value at the same time use the setAttributeValue() method with the $update_in_db param set to true
     *
     * @return self The instance of the object
     */
    protected function updateAttributeValue(): self
    {
        ///TODO: add option to delete the old values
        if($this->link_through_table === null) {
            return $this;
        }

        // Get the current ressources that we need to link to this model
        $list_target_ids = $this->value->map(fn($item) => $item->id);

        // Get the real list of ressources that are already linked to this model
        $query = Query::from($this->link_through_table);
        $query->where($this->link_through_model_column, $this->object_id);

        if($this->link_through_model_type !== null) {
            $query->where($this->link_through_model_type, $this->model);
        }
        if($this->link_through_target_type !== null) {
            $query->where($this->link_through_target_type, $this->target::getName());
        }
        
        $query->get($this->link_through_target_column);

        $current_targets_in_db = $query->fetch();
        $current_target_ids = array_map(fn($item) => $item['id'], $current_targets_in_db);

        // Find the targets to add and to remove
        $targets_to_add = array_diff($list_target_ids, $current_target_ids);
        $targets_to_remove = array_diff($current_target_ids, $list_target_ids);

        // Add the new targets
        foreach($targets_to_add as $target_id) {
            Database::execute("INSERT INTO $this->link_through_table ($this->link_through_model_column, $this->link_through_target_column) VALUES (?, ?)", 
            $this->model_idSQLType.$this->target_idSQLType, $this->object_id, $target_id);
        }

        // Remove the old targets
        Database::execute('DELETE FROM $this->link_through_table WHERE $this->link_through_model_column = ? AND $this->link_through_target_column IN ('.implode(',', $targets_to_remove).')', $this->model_idSQLType, $this->object_id);

        return $this;
    }

    public function addLink($target_id, $load_target_from_db = true): self
    {
        if($load_target_from_db) {
            $target = $this->target::load($target_id);
            if($target === null) {
                throw new Exception("The target with ID $target_id does not exist.");
            }
            $this->value[$target_id] = $target;
        }
        // Add the link to the target
        Database::execute("INSERT INTO $this->link_through_table ($this->link_through_model_column, $this->link_through_target_column) VALUES (?, ?)", 
        $this->model_idSQLType.$this->target_idSQLType, $this->object_id, $target_id);
        return $this;
    }

    public function removeLink($target_id = null, $update_in_db = true, $delete_target = false): self
    {

        if($target_id === null) {
            return $this;
        }

        unset($this->value[$target_id]);

        if($update_in_db) {
            // Remove the link to the target
            $sql_query = "DELETE FROM $this->link_through_table WHERE $this->link_through_model_column = ? AND $this->link_through_target_column = ?";

            $params_type = $this->model_idSQLType.$this->target_idSQLType;
            $params = [$this->object_id, $target_id];

            if($this->link_through_model_type !== null) {
                $sql_query .= " AND $this->link_through_model_type = ?";
                $params_type .= 's';
                $params[] = $this->model;
            }

            if($this->link_through_target_type !== null) {
                $sql_query .= " AND $this->link_through_target_type = ?";
                $params_type .= 's';
                $params[] = $this->target::getName();
            }

            Database::execute($sql_query, $params_type, ...$params);
    
            if($delete_target) {
                Database::execute("DELETE FROM $this->target_table WHERE $this->target_column = ?", $this->target_idSQLType, $target_id);
            }
            return $this;
        }
    }

    /**
     * Generates a theorical name for the relation table based on the source and target models and their cardinalities.
     *
     * @param string $mode Wether we are looking for the name of the source table, the target table or the link_through table.
     * @return string The name of the relation table.
     */
    protected function makeRelationTableName(String $mode = 'source'): String
    {
        if($mode != 'link') {
            return parent::makeRelationTableName($mode);
        }

        $name = $this->bundle == 'default' ? '' : $this->bundle . '_';

        if($this->is_inversed) {
            return $name . $this->target::getName().'_'.$this->model.'s';
        }

        return $name . $this->model.'_'.$this->target::getName().'s';
    }
}
?>