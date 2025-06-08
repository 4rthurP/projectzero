<?php

namespace pz;

use Exception;
use pz\Enums\model\AttributeType;

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

    public function setRequired(): static {
        $this->is_required = true;
        return $this;
    }

    public function setDefault($default_value): static {
        $this->default_value = $default_value;
        return $this;
    }

    #################################################################
    #################################################################
    ########             CLASS SPECIFIC METHODS             #########
    #################################################################
    #################################################################
    abstract protected function getAttributeValue();
    abstract protected function setAttributeValue($attribute_value, bool $is_creation): static;
    abstract protected function updateAttributeValue(): static;
    abstract protected function fetchAttributeValue();
    abstract protected function parseValue($value);
    
    #################################################################
    #################################################################
    ######## MODELS GENERIC METHODS (get, set, update, ...) #########
    #################################################################
    #################################################################
    /**
     * Creates a new attribute value.
     *
     * @param mixed $value The value to set for the attribute.
     * @return static Returns the current instance for method chaining.
     */
    public function create($value): static {
        $this->setAttributeValue($value, true);
        return $this;
    }

    /**
     * Updates the attribute value.
     *
     * @param mixed $value The new value to set for the attribute.
     * @param int|null $object_id The ID of the object to update. Default is null.
     * @param bool $update_in_db Whether to update the value in the database. Default is false.
     * @return static Returns the current instance for method chaining.
     */
    public function update($value, $object_id = null, $update_in_db = false): static {
        $this->setId($object_id);
        $this->setAttributeValue($value, false);
        if($update_in_db && $this->is_valid) {
            $this->updateAttributeValue();
        }
        return $this;
    }

    /**
     * Adds a value to the attribute.
     *
     * This method is only available for link attributes.
     *
     * @param mixed $value The value to add to the attribute.
     * @param bool $update_in_db Whether to update the value in the database. Default is false.
     * @return static Returns the current instance for method chaining.
     * @throws Exception If the attribute is not a link attribute.
     */    
    public function add($value, bool $update_in_db = false): static {
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
     * Removes a value from the attribute.
     *
     * This method is only available for link attributes.
     *
     * @param int $target_id The ID of the target object to remove from the attribute.
     * @param bool $update_in_db Whether to update the value in the database. Default is false.
     * @return static Returns the current instance for method chaining.
     * @throws Exception If the attribute is not a link attribute.
     */
    public function unset($target_id, bool $update_in_db = false): static {
        if(!$this->is_link) {
            throw new Exception("This method is only available for link attributes.");
        }
        if($this->is_inversed) {
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
     * Retrieves the value of the attribute.
     *
     * @param bool $as_object If true, returns the value as an object. Otherwise, returns the value as a formatted string.
     * @return mixed|null The value of the attribute, formatted based on its type, or null if the attribute is not valid.
     *
     * The returned value depends on the attribute type:
     * - For AttributeType::LIST, returns a comma-separated string of the list values.
     * - For AttributeType::DATE, returns the date formatted as 'd/m/Y'.
     * - For AttributeType::DATETIME, returns the datetime formatted as 'd/m/Y H:i:s'.
     * - For other types, returns the raw value.
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
     * Loads the model attribute with the given object ID.
     *
     * This method sets the ID of the model attribute and fetches its value from the database.
     *
     * @param int $object_id The ID of the object to load.
     * @return static Returns the current instance of the model attribute.
     */
    public function load($object_id): static {
        $this->setId($object_id)->setAttributeValue($this->fetchAttributeValue(), false);
        return $this;
    }

    /**
     * Loads the attribute from a given value and object ID.
     *
     * @param mixed $value The value to set for the attribute.
     * @param int $object_id The ID of the object to associate with the attribute.
     * @return static Returns the current instance with the updated attribute.
     */
    public function loadFromValue($value, $object_id): static {
        $this->setId($object_id)->setAttributeValue($value, false);
        return $this;
    }

    /**
     * Saves the current model attribute.
     *
     * This method updates the attribute value and returns the current instance of the model.
     *
     * @return static The current instance of the model.
     */
    public function save($object_id = null): static {
        $this->setId($object_id);
        if($this->is_valid) {
            $this->updateAttributeValue();
        }
        return $this;
    }

    /**
     * Deletes the attribute value for the given object ID.
     *
     * If the attribute is required, it will not be deleted and an error message will be added to the messages array.
     *
     * @param int|null $object_id The ID of the object whose attribute value is to be deleted. Defaults to null.
     * @return static Returns the current instance of the class.
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
     * Sets the ID of the object associated with the attribute.
     *
     * @param int $object_id The ID of the object.
     * @return static Returns the current instance of the model attribute.
     * @throws Exception If the object ID is already set.
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
     * Generates a theorical name for the relation table based on the source and target models and their cardinalities.
     *
     * @param string $mode Wether we are looking for the name of the source table, the target table or the link_through table.
     * @return string The name of the relation table.
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
    public function isRequired()
    {
        return $this->is_required;
    }
    
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