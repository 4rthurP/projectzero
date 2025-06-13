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
use pz\Log;

class ModelAttributeLink extends AbstractModelAttribute
{
    public bool $is_link = true;
    public bool $is_link_through = false;

    /**
     * Creates a new ModelAttributeLink instance for direct relationships.
     *
     * This constructor sets up a direct link relationship between two models:
     * - Non-inversed: One-to-one or one-to-many relationship (foreign key in source table)
     * - Inversed: One-to-many relationship (foreign key in target table)
     *
     * @param string $name The name of the attribute
     * @param string $model The source model class name
     * @param string $bundle The bundle name for organizing attributes (default: 'default')
     * @param bool $isRequired Whether this attribute is required (default: false)
     * @param bool $isInversed Whether this is the inverse side of the relationship (default: false)
     * @param string|null $default_value Default value (currently not supported for link attributes)
     * @param string|null $model_table Database table name for the source model
     * @param string|null $model_id_key Primary key column name in the source model table
     * @param string|null $target_column Column name that stores the foreign key reference
     * @param string|null $target Target model class name for the relationship
     * @param string|null $target_table Database table name for the target model (required if target is null)
     * @param string|null $target_id_key Primary key column name in the target model table
     * @param string|null $updated_at_column Column name for tracking updates in source table
     * @param string|null $target_updated_at_column Column name for tracking updates in target table
     * @return static Returns the current instance for method chaining
     * @throws Exception If neither target nor target_table is provided
     */
    public function __construct(
        String $name, 
        String $model, 
        String $bundle = 'default', 
        bool $isRequired = false, 
        bool $isInversed = false, 
        ?String $default_value = null, 
        ?String $model_table = null, 
        ?String $model_id_key = null, 
        ?String $target_column = null, 
        ?String $target = null, 
        ?String $target_table = null, 
        ?String $target_id_key = null,
        ?String $updated_at_column = null,
        ?String $target_updated_at_column = null
    ) {
        $this->name = $name;
        $this->type = AttributeType::RELATION;
        $this->is_required = $isRequired;
        $this->is_inversed = $isInversed;
        $this->default_value = null; #TODO: we could have a default link value when the load by id is implemented
        $this->bundle = $bundle;

        $this->value = [];

        $this->model = $model;
        $this->model_table = $model_table ?? $this->makeRelationTableName();
        $this->model_id_key = $model_id_key;

        $this->target_column = $target_column ?? $isInversed ? $model::getName() . '_id' : $name . '_id';

        if($target != null) {
            $target_model = new $target(false);
            $this->target = $target;
            $this->target_table = $target_model->table;
            $this->target_id_key = $target_model->getIdKey();

        } else {
            if($target_table == null) {
                throw new Exception("You need to provide at least a target object or a target table for the link attribute.");
            }
            $this->target = null;
            $this->target_table = $target_table;
            $this->target_id_key = $target_id_key ?? 'id';
        }
        
        $this->updated_at_column = $updated_at_column;
        $this->target_updated_at_column = $target_updated_at_column;
                
        return $this;
    }

    /**
     * Retrieves the formatted attribute value for link relationships.
     *
     * Returns the relationship data in a format suitable for external use:
     * - For non-inversed relationships: Returns single object/array or raw value
     * - For inversed relationships: Returns array of objects converted to arrays
     * - If no target model is defined: Returns raw relationship data
     *
     * @return mixed The formatted relationship data (object, array, or null)
     */
    protected function getAttributeValue() {
        if($this->target == null || $this->value == null) {
            return $this->value;
        }
        if(!$this->is_inversed) {
            if(is_array($this->value)) {
                return $this->value;
            }
            return $this->value->toArray();
        }

        $parsed_value = [];
        foreach($this->value as $item) {
            $parsed_value[] = $item->toArray();
        }
        return $parsed_value;
    }

    /**
     * Sets and validates the attribute value for link relationships.
     *
     * Handles two different relationship types:
     * - Non-inversed (Case A): Single value relationships with validation and default value handling
     * - Inversed (Case B): Multiple value relationships for one-to-many inverse relationships
     *
     * For non-inversed relationships, performs full validation including required field checks
     * and default value application. For inversed relationships, processes arrays of objects.
     *
     * @param mixed $attribute_value The relationship data to set (object, array, or null)
     * @param bool $is_creation Whether this is a creation operation
     * @return static Returns the current instance for method chaining
     * @throws Exception If the attribute value format is invalid or required validation fails
     * @throws Throwable If an error occurs during value parsing
     *
     * @see parseValue() for individual value parsing logic
     */
    protected function setAttributeValue($attribute_value, bool $is_creation): static {
        #Case A: Single value
        if(!$this->is_inversed) {
            $messages = [];
            $current_value = null;
            $mode = 'create';
            
            if(!$is_creation) {
                $current_value = $this->value === [] ? null : $this->value;
                $mode = 'edit';
            }

            #### Checking the attribute value
            if($this->is_required && $attribute_value == null) {
                if($mode == 'create' && $this->default_value == null) {
                    $this->messages[] = ['error', 'attribute-required', $this->name." is required but no value was provided."];
                    $this->is_valid = false;
                    return $this;
                }

                if($mode == 'edit' && $current_value != null) {
                    $this->messages[] = ['warning', 'old-value-user', "The old value was used for $this->name as the new value passed was null and this attribute is required."];
                    return $this;
                }

                $attribute_value = $this->default_value;
                $this->messages[] = ['info', 'default-value-used', "The default value ($this->default_value) was used for $this->name"];
            }

            if(!$this->is_required && $attribute_value == null) {
                $messages[] = ['info', 'attribute-missing-not-required', "No value was provided for ".$this->name];
                $this->value = null;
                return $this;
            }

            ##### Value parsing and setting 
            try {
                $this->value = $this->parseValue($attribute_value);
            } catch (Throwable $e) {
                throw new Exception("Attribute value must be an object of type " . $this->target);
                $this->messages[] = ['error', 'attribute-type', $e->getMessage()];
                $this->is_valid = false;
                return $this;
            }
            return $this;
        }

        #Case B: Multiple values
        $values = null;
        if(is_object($attribute_value)) {
            $values = [$attribute_value];
        } elseif(is_array($attribute_value)) {
            if(empty($attribute_value) && !$this->is_required) {
                $values = [];
            } else if(!is_object($attribute_value[0]) && ! is_array($attribute_value[0])) {
                $values = [$attribute_value];
            } else {
                $values = $attribute_value;
            }
        } 
        if($values === null) {
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
                $this->object_id = $id;
                $this->value[] = $parsed_value;
            } catch (Throwable $e) {
                $this->messages[] = ['error', 'attribute-type', $e->getMessage()];
                $this->is_valid = false;
                return $this;
            }
        }
        return $this;
    }

    /**
     * Updates the link relationship data in the database.
     *
     * Handles two different update strategies based on relationship direction:
     * - Non-inversed: Updates foreign key in the source table
     * - Inversed: Updates foreign key references in the target table(s)
     *
     * Automatically updates timestamp columns if configured.
     *
     * @return static Returns the current instance for method chaining
     * @throws Exception If the object ID is not set
     *
     * @see updateTargetTable() for inversed relationship updates
     * @see Database::execute() for database operations
     */
    protected function updateAttributeValue(): static {
        if($this->object_id === null) {
            throw new Exception("Object ID not set");
        }

        $datetime = new DateTime("now", Config::tz());
        $datetime = $datetime->format('Y-m-d H:i:s');

        if(!$this->is_inversed) {
            if($this->target != null) {
                $target_id = $this->value->getId();
            } else {
                $target_id = $this->value[$this->target_id_key];
            }

            $set_clauses = ["$this->target_column = ?"];
            $values = [$target_id];
            $types = "s";
            
            if ($this->updated_at_column) {
                $set_clauses[] = "$this->updated_at_column = ?";
                $values[] = $datetime;
                $types .= "s";
            }
            
            $values[] = $this->object_id;
            $types .= "s";
            
            $set_clause = implode(", ", $set_clauses);
            Database::execute("UPDATE $this->model_table SET $set_clause WHERE $this->model_id_key = ?", $types, ...$values);

        } else {
            $list_of_values = Query::from($this->target_table)->get($this->target_id_key)->where($this->target_column, $this->object_id)->fetch();
            if($list_of_values != null) {
                $list_of_values = array_map(fn($item) => $item['id'], $list_of_values);
                foreach($this->value as $target_id => $item) {
                    if(!in_array($target_id, $list_of_values)) {
                        $this->updateTargetTable($this->object_id, $item->getId(), $datetime);
                    } else {
                        $list_of_values = array_diff($list_of_values, [$target_id]);
                    }
                }
                foreach($list_of_values as $item) {
                    $this->updateTargetTable(null, $item, $datetime);
                }
            } 
        }
        return $this;
    }

    /**
     * Updates a target table record for inversed relationships.
     *
     * Sets or clears the foreign key reference in the target table and updates
     * the timestamp column if configured.
     *
     * @param mixed $value The foreign key value to set (null to clear the relationship)
     * @param mixed $target_id The ID of the target record to update
     * @param string $datetime The formatted datetime string for timestamp updates
     * @return void
     *
     * @see Database::execute() for the database update operation
     */
    private function updateTargetTable($value, $target_id, $datetime): void
    {
        $set_clauses = ["$this->target_column = ?"];
        $values = [$value];
        $types = "s";
        
        if ($this->target_updated_at_column) {
            $set_clauses[] = "$this->target_updated_at_column = ?";
            $values[] = $datetime;
            $types .= "s";
        }
        
        $values[] = $target_id;
        $types .= "s";
        
        $set_clause = implode(", ", $set_clauses);
        Database::execute("UPDATE $this->target_table SET $set_clause WHERE $this->target_id_key = ?", $types, ...$values);
    }

    /**
     * Fetches the link relationship data from the database.
     *
     * Retrieves related records using different strategies based on relationship direction:
     * - Inversed: Queries target table for records that reference this object
     * - Non-inversed: Queries source table for foreign key, then fetches the referenced record
     *
     * @return array|null Array of related records, single record, or null if no relationships exist
     * @throws Exception If the object ID is not set
     *
     * @see Query::from() for building database queries
     */
    protected function fetchAttributeValue() {
        if ($this->object_id === null) {
            throw new Exception("Object ID not set");
        }
        
        if($this->is_inversed) {
            $found_values = Query::from($this->target_table)->where($this->target_column, $this->object_id)->fetch();
            return $found_values;
        } 
        $value = Query::from($this->model_table)->where($this->model_id_key, $this->object_id)->first();
        if($value != null) {
            $found_values = Query::from($this->target_table)->where($this->target_id_key, $value[$this->target_column])->first();
            return $found_values;
        }
        
        return null;
    }

    /**
     * Parses and validates a single relationship value.
     *
     * Handles different input formats based on configuration:
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

    /**
     * Retrieves the ID of the linked target object.
     *
     * Extracts the target ID from the relationship value:
     * - If target model is defined: Gets ID from model instance
     * - If no target model: Gets ID from array using target_id_key
     *
     * @return mixed The target object ID or null if no value is set
     */
    public function getTargetId() {
        if($this->value != null) {
            if($this->target != null) {
                return $this->value->getId();
            }
            return $this->value[$this->target_id_key];
        }
    }
}