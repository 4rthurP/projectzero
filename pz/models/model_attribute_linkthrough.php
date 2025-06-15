<?php

namespace pz;

use Exception;
use DateTime;
use DateTimeZone;
use pz\Config;
use pz\database\Database;
use pz\database\Query;
use pz\Enums\model\AttributeType;
use Throwable;

class ModelAttributeLinkThrough extends AbstractModelAttribute
{
    public bool $is_link = true;
    public bool $is_link_through = true;

    /**
     * Creates a new ModelAttributeLinkThrough instance for many-to-many relationships.
     *
     * This constructor sets up a link-through relationship that uses an intermediate table
     * to connect two models. It supports both polymorphic and regular many-to-many relationships.
     *
     * @param string $name The name of the attribute
     * @param string $model The source model class name
     * @param string $bundle The bundle name for organizing attributes (default: 'default')
     * @param bool $isInversed Whether this is the inverse side of the relationship (default: false)
     * @param bool $is_many Whether this is a many-to-many relationship (default: true)
     * @param string|null $default_value Default value (currently not supported for link attributes)
     * @param string|null $model_table Database table name for the source model
     * @param string|null $model_id_key Primary key column name in the source model table
     * @param string|null $target_column Column name in the relation table that references the target
     * @param string|null $target Target model class name for the relationship
     * @param string|null $target_table Database table name for the target model (required if target is null)
     * @param string|null $target_id_key Primary key column name in the target model table
     * @param string|null $relation_table Name of the intermediate/junction table
     * @param string|null $relation_model_column Column name in the relation table that references the source model
     * @param string|null $relation_model_type Column name for polymorphic type (for polymorphic relationships)
     * @return static Returns the current instance for method chaining
     * @throws Exception If neither target nor target_table is provided
     */
    public function __construct(
        String $name, 
        String $model, 
        String $bundle = 'default', 
        bool $isInversed = false, 
        bool $is_many = true, 
        ?String $default_value = null, 
        ?String $model_table = null, 
        ?String $model_id_key = null, 
        ?String $target_column = null, 
        ?String $target = null, 
        ?String $target_table = null, 
        ?String $target_id_key = null, 
        ?String $relation_table = null, 
        ?String $relation_model_column = null, 
        ?String $relation_model_type = null
    ) {
        $this->name = $name;
        $this->type = AttributeType::RELATION;
        $this->is_required = false;
        $this->is_inversed = $isInversed;
        $this->default_value = null; #TODO: we could have a default link value when the load by id is implemented
        $this->bundle = $bundle;

        $this->value = [];

        // Handle model table
        $this->model = $model;
        $this->model_table = $model_table ?? $this->makeRelationTableName();
        $this->model_id_key = $model_id_key;

        if($this->is_inversed) {
            $target_name = $model::getName();
            $model_name = $target::getName();
        } else {
            $target_name = $target::getName();
            $model_name = $model::getName();
        }

        // Handle target table
        if($target != null) {
            $target_model = new $target(false);
            $this->target = $target;
            $this->target_table = $target_model->table;
            $this->target_id_key = $target_model->getIdKey();
            $this->target_id_type = $target_model->idType;
        } else {
            if($target_table == null) {
                throw new Exception("You need to provide at least a target object or a target table for the link attribute.");
            }
            $this->target = null;
            $this->target_table = $target_table;
            $this->target_id_key = $target_id_key ?? 'id';
            $this->target_id_type = AttributeType::ID;
        }

        // Handle pivot table
        $this->relation_table = $relation_table ?? $this->makeRelationTableName('relation', $is_many);
        
        if($is_many && !$this->is_inversed) {
            $this->relation_model_type = $relation_model_type ?? $target_name . 'able_type';
            $this->relation_model_column = $relation_model_column ?? $target_name . 'able_id';
            $this->target_column = $target_column ?? $target_name . '_id';     
        } else if ($this->is_inversed) {
            $this->relation_model_type = $relation_model_type ?? $target_name . 'able_type';
            $this->relation_model_column = $relation_model_column ?? $target_name . '_id';
            $this->target_column = $target_column ?? $target_name . 'able_id';     
        } else {
            $this->target_column = $target_column ?? $target_name . '_id';
        }
                
        return $this;
    }

    /**
     * Retrieves the formatted attribute value for link-through relationships.
     *
     * Returns the relationship data in a format suitable for external use:
     * - If target model is defined: Returns array of model objects converted to arrays
     * - If no target model: Returns raw relationship data
     *
     * @return array The formatted relationship data
     */
    protected function getAttributeValue() {
        if($this->target == null) {
            return $this->value;
        }

        $parsed_value = [];
        foreach($this->value as $item) {
            $parsed_value[] = $item->toArray();
        }
        return $parsed_value;
    }

    /**
     * Sets and validates the attribute value for link-through relationships.
     *
     * Processes relationship data which can be:
     * - A single object or array
     * - An array of objects or arrays
     * - null or empty array (clears the relationship)
     *
     * Each value is parsed and validated, then stored with its ID as the key.
     *
     * @param mixed $attribute_value The relationship data to set (object, array, or null)
     * @param bool $is_creation Whether this is a creation operation (default: false)
     * @return static Returns the current instance for method chaining
     * @throws Exception If the attribute value format is invalid
     * @throws Throwable If an error occurs during value parsing
     *
     * @see parseValue() for individual value parsing logic
     */
    protected function setAttributeValue($attribute_value, bool $is_creation = false): static {
        if($attribute_value === null || $attribute_value === []) {
            return $this;
        }
        
        if(is_object($attribute_value)) {
            $values = [$attribute_value];
        } elseif(is_array($attribute_value)) {
            if(!is_object($attribute_value[0]) && ! is_array($attribute_value[0])) {
                $values = [$attribute_value];
            } else {
                $values = $attribute_value;
            }
        } else {
            #TODO: add the possibiltiy to load from ID ?
            throw new Exception("Attribute value must be an object or an array.");
        }
        
        foreach($values as $value) {
            try {
                $parsed_value = $this->parseValue($value);
                if($this->target != null) {
                    $id = $parsed_value->getId();
                } else {
                    $id = $parsed_value[$this->target_id_key];
                }
                $this->value[$id] = $parsed_value;
            } catch (Throwable $e) {
                $this->messages[] = ['error', 'attribute-type', $e->getMessage()];
                $this->is_valid = false;
                return $this;
            }
        }
        return $this;
    }

    /**
     * Updates the link-through relationship data in the database.
     *
     * Synchronizes the relationship by:
     * 1. Finding existing relationships in the junction table
     * 2. Adding new relationships that don't exist
     * 3. Removing relationships that are no longer present
     *
     * Supports both regular and polymorphic relationships based on configuration.
     *
     * @return static Returns the current instance for method chaining
     * @throws Exception If the object ID is not set
     *
     * @see findRelations() for finding existing relationships
     * @see Database::execute() for database operations
     */
    protected function updateAttributeValue(): static {
        if($this->object_id === null) {
            throw new Exception("Object ID not set");
        }
        
        $datetime = new DateTime("now", Config::tz());
        $datetime = $datetime->format('Y-m-d H:i:s');

        $found_relations = $this->findRelations();
        
        $list_of_ids = array_map(fn($item) => $item[$this->target_column], $found_relations);

        // Sets the value of each item in the value array to the target ID
        foreach($this->value as $target_id => $item) {
            if(!in_array($target_id, $list_of_ids)) {
                $columns = "$this->relation_model_column, $this->target_column";
                $placeholders = "?, ?";
                $types = "ss";
                $values = [$this->object_id, $target_id];

                if($this->relation_model_type != null) {
                    $columns .= ", $this->relation_model_type";
                    $placeholders .= ", ?";
                    $types .= "s";

                    $model = $this->is_inversed ? $this->target : $this->model;
                    $values[] = $model::getName();
                }

                Database::execute(
                    "INSERT INTO $this->relation_table ($columns) VALUES ($placeholders)", 
                    $types, 
                    ...$values
                );
            } else {
                $list_of_ids = array_diff($list_of_ids, [$target_id]);
            }
        }

        // Deletes the items that are not linked anymore
        foreach($list_of_ids as $item) {
            if($this->relation_model_type == null) {
                Database::execute(
                    "DELETE FROM $this->relation_table WHERE $this->relation_model_column = ? AND $this->target_column = ?",
                    "ss", 
                    $this->object_id, 
                    $item
                ); 
            } else {
                $model = $this->is_inversed ? $this->target : $this->model;
                Database::execute(
                    "DELETE FROM $this->relation_table WHERE $this->relation_model_column = ? AND $this->target_column = ? AND $this->relation_model_type = ?", 
                    "sss", 
                    $this->object_id, 
                    $item, 
                    $model::getName()
                );
            }
        }
        
        
        return $this;
    }

    /**
     * Fetches the link-through relationship data from the database.
     *
     * Retrieves related records by:
     * 1. Finding relationship records in the junction table
     * 2. Extracting target IDs from the relationships
     * 3. Fetching the actual target records using those IDs
     *
     * @return array|null Array of related records or null if no relationships exist
     *
     * @see findRelations() for finding junction table records
     * @see Query::from() for building the target data query
     */
    protected function fetchAttributeValue() {
        // Finds existing relationships in the junction table
        $found_relations = $this->findRelations();
        if(count($found_relations) == 0) {
            return null;
        }
        
        // Extracts the target IDs from the found relationships
        $list_of_ids = array_map(fn($item) => $item[$this->target_column], $found_relations);
        
        // Query the target table to find the actual linked records
        $found_values = (
            Query::from($this->target_table)
            ->whereIn($this->target_id_key, $list_of_ids)
            ->fetch()
        );
        
        // Sanitize the return
        if(count($found_values) == 0) {
            return null;
        }
        return $found_values;
    }

    /**
     * Finds existing relationship records in the junction table.
     *
     * Queries the intermediate table to find all relationships for the current object.
     * Handles both polymorphic and regular relationships based on configuration.
     *
     * @return array Array of relationship records from the junction table
     *
     * @see Query::from() for building the database query
     */
    protected function findRelations() {
        // Query the link table to find existing relationships
        $relation_query = (
            Query::from($this->relation_table)
            ->get($this->target_column)
            ->where($this->relation_model_column, $this->object_id)
        );

        // Adds the target type if it is a polymorphic relationship
        if($this->relation_model_type != null) {
            $model = $this->is_inversed ? $this->target : $this->model;
            $relation_query->where($this->relation_model_type, $model::getName());
        }

        // Execute the query and fetch the results
        return $relation_query->fetch();
    }

    /**
     * Parses and validates a single relationship value.
     *
     * Handles different input formats:
     * - If target model is defined:
     *   - Accepts instances of the target model class
     *   - Accepts arrays with target ID key, converts to model instance
     * - If no target model:
     *   - Accepts arrays with the required ID key
     *
     * @param mixed $value The value to parse (object or array)
     * @return mixed The parsed relationship value (model instance or array)
     * @throws Exception If the value format is invalid or missing required keys
     *
     * @see loadFromArray() for creating model instances from array data
     */
    protected function parseValue($value) {
        if($this->target != null) {
            if(is_a($value, $this->target)) {
                return $value;
            } elseif(is_array($value) && array_key_exists($this->target_id_key, $value)) {
                $target = new $this->target(false);
                $target->loadFromArray($value);
                if($target->isValid()) {
                    return $target;
                }
                return null;
            }
            throw new Exception("Attribute value must be an object of type " . $this->target);
        } 
        if(!in_array($this->target_id_key, array_keys($value))) {
            throw new Exception("The attribute value must have a key named $this->target_id_key");
        }
        return $value;
    }
}