<?php

namespace pz;

use Exception;
use pz\Enums\model\AttributeType;

/**
 * Abstract base class for model attributes.
 *
 * This class provides the foundation for all model attribute types including:
 * - Basic attributes (string, int, date, etc.)
 * - Link attributes (one-to-one, one-to-many relationships)
 * - Link-through attributes (many-to-many relationships)
 *
 *
 * @package pz
 */
abstract class AbstractModelAttribute
{
    public AttributeType $type;
    public String $name; //The name of the attribute (mandatory) or the name of the relation (default: target)
    public String $bundle; //The name of the bundle where the attribute is stored (default: 'default')

    protected $object_id;
    public $value;
    public array $messages = [];
    protected bool $is_valid = true;

    public bool $is_required;
    public String|null $default_value;

    public String $model;
    public String $model_table;
    public String $model_id_key;    
    public String $target_column;    

    public ?String $target;
    public String $target_table;
    public String $target_id_key;
    public AttributeType $target_id_type;

    public String $relation_table;
    public String $relation_model_column;
    public ?String $relation_model_type;

    public bool $is_inversed;    
    public bool $is_link;
    public bool $is_link_through;
    
    public ?String $updated_at_column;
    public ?String $target_updated_at_column;

    /**
     * Marks this attribute as required.
     *
     * Required attributes must have a value and cannot be null during
     * creation or update operations.
     *
     * @return static Returns the current instance for method chaining
     */
    public function setRequired(): static {
        $this->is_required = true;
        return $this;
    }

    /**
     * Sets the default value for this attribute.
     *
     * The default value will be used when no value is provided for
     * required attributes during creation operations.
     *
     * @param mixed $default_value The default value to use
     * @return static Returns the current instance for method chaining
     */
    public function setDefault($default_value): static {
        $this->default_value = $default_value;
        return $this;
    }

    #################################################################
    #################################################################
    ########             CLASS SPECIFIC METHODS             #########
    #################################################################
    #################################################################
    /**
     * Retrieves the formatted attribute value for external use.
     *
     * Subclasses must implement this method to handle type-specific
     * formatting for display or API responses.
     *
     * @return mixed The formatted attribute value
     */
    abstract protected function getAttributeValue();

    /**
     * Sets and validates the attribute value.
     *
     * Subclasses must implement this method to handle type-specific
     * validation, parsing, and value assignment.
     *
     * @param mixed $attribute_value The value to set
     * @param bool $is_creation Whether this is a creation operation
     * @return static Returns the current instance for method chaining
     */
    abstract protected function setAttributeValue($attribute_value, bool $is_creation): static;

    /**
     * Updates the attribute value in the database.
     *
     * Subclasses must implement this method to handle database
     * update operations specific to their attribute type.
     *
     * @return static Returns the current instance for method chaining
     */
    abstract protected function updateAttributeValue(): static;

    /**
     * Fetches the attribute value from the database.
     *
     * Subclasses must implement this method to handle database
     * retrieval operations specific to their attribute type.
     *
     * @return mixed The raw attribute value from the database
     */
    abstract protected function fetchAttributeValue();

    /**
     * Parses and validates a raw value according to the attribute type.
     *
     * Subclasses must implement this method to handle type-specific
     * parsing, validation, and conversion logic.
     *
     * @param mixed $value The raw value to parse
     * @return mixed The parsed and validated value
     */
    abstract protected function parseValue($value);
    
    #################################################################
    #################################################################
    ######## MODELS GENERIC METHODS (get, set, update, ...) #########
    #################################################################
    #################################################################
    /**
     * Creates a new attribute value during model creation.
     *
     * Sets the attribute value with creation-specific validation rules,
     * including required field checks and default value application.
     *
     * @param mixed $value The value to set for the attribute
     * @return static Returns the current instance for method chaining
     */
    public function create($value): static {
        $this->setAttributeValue($value, true);
        return $this;
    }

    /**
     * Updates the attribute value with optional database persistence.
     *
     * Sets the attribute value with update-specific validation rules.
     * Optionally persists the change to the database immediately.
     *
     * @param mixed $value The new value to set for the attribute
     * @param int|null $object_id The ID of the object to update (default: null)
     * @param bool $update_in_db Whether to persist changes to database (default: false)
     * @return static Returns the current instance for method chaining
     */
    public function update($value, $object_id = null, $update_in_db = false): static {
        $this->setId($object_id);

        // Reset the value
        $this->value = null;
        if($this->is_inversed || $this->is_link_through) {
            $this->value = [];
        }

        // Set the new value
        $this->setAttributeValue($value, false);
        if($update_in_db && $this->is_valid) {
            $this->updateAttributeValue();
        }
        return $this;
    }

    /**
     * Adds a value to link attributes (relationships).
     *
     * Appends a new relationship value to the existing collection.
     * Only available for link and link-through attributes.
     *
     * @param Model $value The target resource to add to the relationship.
     * @param bool $update_in_db Whether to persist changes to database (default: false)
     * @return static Returns the current instance for method chaining
     * @throws Exception If the attribute is not a link attribute
     */    
    public function add(Model $value, bool $update_in_db = false): static {
        if(!$this->is_link) {
            throw new Exception("This method is only available for link attributes.");
        }
        $this->setAttributeValue($value, false);

        if($update_in_db && $this->is_valid) {
            $this->updateAttributeValue();
        }
        return $this;
    }

    /**
     * Removes a value from link attributes (relationships).
     *
     * Removes a specific relationship by target ID. For non-inversed relationships,
     * clears the entire relationship. For inversed relationships, removes only
     * the specified target from the collection.
     *
     * @param Model value The target ressource to remove from the relationship.
     * @param bool $update_in_db Whether to persist changes to database (default: false)
     * @return static Returns the current instance for method chaining
     * @throws Exception If the attribute is not a link attribute
     */
    public function remove(Model $value, bool $update_in_db = false): static {
        if(!$this->is_link) {
            throw new Exception("This method is only available for link attributes.");
        }

        if($this->is_inversed || $this->is_link_through) {
            $target_id = $value->getId();
            if(!isset($this->value[$target_id])) {
                Log::debug("ho");
                Log::debug(print_r($this->value));
                Log::debug("ho");
                $this->messages[] = ['error', 'attribute-remove-target-not-found', "Target ID $target_id not found in attribute {$this->name}."];
                $this->is_valid = false;
                return $this;
            } 
            unset($this->value[$target_id]);
        } else {
            $this->value = null;
        }
        
        if($update_in_db && $this->is_valid) {
            $this->updateAttributeValue();
        }
        return $this;
    }

    /**
     * Retrieves the attribute value in the requested format.
     *
     * Returns either the raw object value or a formatted representation
     * suitable for display or API responses.
     *
     * @param bool $as_object If true, returns raw value; if false, returns formatted value
     * @return mixed|null The attribute value or null if invalid
     *
     * Formatted output varies by type:
     * - LIST: Comma-separated string
     * - DATE: 'd/m/Y' format
     * - DATETIME: 'd/m/Y H:i:s' format
     * - Others: Raw value
     */
    public function get(bool $as_object = false) {
        if(!$this->is_valid) {
            return null;
        }

        if ($as_object == true) {
            return $this->value;
        }

        return $this->getAttributeValue();
    }

    /**
     * Loads the attribute value from the database using an object ID.
     *
     * Fetches the current value from the database and initializes the attribute
     * with the retrieved data.
     *
     * @param int $object_id The ID of the object to load
     * @return static Returns the current instance for method chaining
     */
    public function load($object_id): static {
        $this->setId($object_id)->setAttributeValue($this->fetchAttributeValue(), false);
        return $this;
    }

    /**
     * Loads the attribute from a provided value and object ID.
     *
     * Initializes the attribute with a known value and associates it
     * with the specified object ID.
     *
     * @param mixed $value The value to set for the attribute
     * @param int $object_id The ID of the object to associate with
     * @return static Returns the current instance for method chaining
     */
    public function loadFromValue($value, $object_id): static {
        $this->setId($object_id)->setAttributeValue($value, false);
        return $this;
    }

    /**
     * Persists the current attribute value to the database.
     *
     * Updates the database with the current attribute value if the
     * attribute is in a valid state.
     *
     * @param mixed $object_id Optional object ID to set before saving
     * @return static Returns the current instance for method chaining
     */
    public function save($object_id = null): static {
        $this->setId($object_id);
        if($this->is_valid) {
            $this->updateAttributeValue();
        }
        return $this;
    }

    /**
     * Deletes the attribute value from the database.
     *
     * Sets the attribute value to null and persists the change.
     * Required attributes cannot be deleted and will generate an error.
     *
     * @param int|null $object_id The ID of the object to update (default: null)
     * @return static Returns the current instance for method chaining
     */
    public function delete($object_id = null): static {
        if($this->is_required) {
            $this->messages[] = ['error', 'attribute-required', $this->name." is required and cannot be deleted."];
            $this->is_valid = false;
            return $this;
        }
        $this->setId($object_id)->setAttributeValue(null, false)->updateAttributeValue();
        return $this;
    }
    
    /**
     * Sets the object ID for this attribute instance.
     *
     * Associates this attribute with a specific object instance.
     * Prevents changing the ID once it's been set to maintain data integrity.
     *
     * @param int $object_id The ID of the object to associate with
     * @return static Returns the current instance for method chaining
     * @throws Exception If attempting to change an already set object ID
     */
    public function setId($object_id): static {
        if($object_id !== null) {
            if($this->object_id !== null && $this->object_id != $object_id) {
                throw new Exception('Object ID is already set');
            }
            $this->object_id = $object_id;
        }
        return $this;
    }

    /**
     * Generates conventional table names for relationships.
     *
     * Creates standardized table names based on model names, bundle configuration,
     * and relationship type. Supports different naming conventions for various
     * relationship patterns.
     *
     * @param string $mode The table type to generate ('model', 'target', or 'relation')
     * @param bool $is_many Whether this is a many-to-many relationship (default: true)
     * @return string The generated table name
     */
    protected function makeRelationTableName(String $mode = 'relation', bool $is_many = true): String
    {
        if($mode == 'model') {
            $model_object = new $this->model();
            return $model_object->table;
        } 
        if($mode == 'target') {
            if($this->target != null) {
                $target_object = new $this->target();
                return $target_object->table;
            }
            $name = $this->bundle == 'default' ? '' : $this->bundle . '_';
            return $name . $this->target::getName().'s';
        }

        $name = $this->bundle == 'default' ? '' : $this->bundle . '_';

        if($this->is_inversed) {
            $target_name = $this->model::getName();
            $model_name = $this->target::getName();
        } else {
            $target_name = $this->target::getName();
            $model_name = $this->model::getName();
        }

        if($is_many) {
            return $name . $target_name . 'ables';
        } else {
            return $name . $model_name . '_' . $target_name .'s';
        }
    }

    #################################################################
    #################################################################
    ########                     GETTERS                     ########
    #################################################################
    #################################################################
    /**
     * Checks if this attribute is required.
     *
     * @return bool True if the attribute is required, false otherwise
     */
    public function isRequired()
    {
        return $this->is_required;
    }
    
    /**
     * Gets the string representation of the attribute type.
     *
     * @return string The attribute type as a string value
     */
    public function getType()
    {
        return $this->type->value;
    }

    /**
     * Get the SQL representation of the attribute value based on its type.
     *
     * @return mixed The SQL formatted value of the attribute.
     *               - For AttributeType::LIST, returns a comma-separated string of the list values.
     *               - For AttributeType::DATE, returns the date formatted as 'Y-m-d' or null if the value is null.
     *               - For AttributeType::DATETIME, returns the datetime formatted as 'Y-m-d H:i:s' or null if the value is null.
     *               - For other types, returns the raw value.
     */
    public function getSQLValue() {
        switch ($this->type) {
            case AttributeType::LIST:
                return is_array($this->value) ? implode(',', $this->value) : $this->value;
            case AttributeType::DATE:
                return $this->value == null ? null : $this->value->format('Y-m-d');
            case AttributeType::DATETIME:
                return $this->value == null ? null : $this->value->format('Y-m-d H:i:s');
            default:
                return $this->value;
        }

    }

    /**
     * Returns the SQL type (s, i, d or b) corresponding to the attribute type. This allow for easier binding of parameters in SQL queries.
     * Leverages the enums method SQLType().
     *
     * @return string The SQL type.
     */
    public function getSQLQueryType()
    {
        return $this->type->SQLQueryType();
    }

    /**
     * Get the SQL representation of the attribute field based on its type.
     *
     * @return string The SQL formatted field of the attribute.
     */
    public function getSQLType() {
        return $this->type->SQLType();
    }

    /**
     * Get the SQL representation of the attribute field based on its type.
     *
     * @return string The SQL formatted field of the attribute.
     */
    public function getSQLField() {
        // Return null if the target column is not set
        if(empty($this->target_column)) {
            return null;
        }
        
        if($this->type === AttributeType::ID) {
            return $this->target_column .' INT AUTO_INCREMENT PRIMARY KEY';
        } 
        if($this->type === AttributeType::UUID) {
            return $this->target_column .' CHAR(36) PRIMARY KEY';
        }

        $field = $this->target_column . ' ' . $this->type->SQLType();
        if($this->is_required) {
            $field .= ' NOT NULL';
        }
        return $field;
    }
}