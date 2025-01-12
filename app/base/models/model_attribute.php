<?php

namespace pz;

use Exception;
use DateTime;
use DateTimeZone;
use pz\database\{Database, Query, ModelQuery};
use pz\Enums\model\AttributeType;
use Throwable;

class ModelAttribute
{
    public AttributeType $type;
    public String $name; //The name of the attribute (mandatory) or the name of the relation (default: target)
    public String $bundle; //The name of the bundle where the attribute is stored (default: 'default')

    protected $object_id;
    public $value;
    public array $messages = [];
    protected bool $is_valid = true;

    public readonly bool $is_required;
    public readonly String|null $default_value;

    public readonly String $model;
    public readonly String $model_table;
    public readonly String $model_column;    
    
    public String|null $target_table;
    public String|null $target_column;

    public bool $is_link = false;
    public bool $is_link_through = false;

    public function __construct(String $name, AttributeType $type, bool $isRequired, ?String $default_value, String $model, String $bundle = 'default', String $model_table = null, String $model_column = 'id', String $target_column = null)
    {
        $this->name = $name;
        $this->type = $type;
        $this->is_required = $isRequired;
        $this->default_value = $default_value;
        $this->model = $model;
        $this->bundle = $bundle;
        $this->model_table = $model_table ?? $this->makeRelationTableName();
        $this->model_column = $model_column;

        $this->target_column = $target_column ?? $name;
        $this->target_table = $model_table;
        
        return $this;
    }

    public function setRequired(): ModelAttribute {
        $this->is_required = true;
        return $this;
    }

    public function setDefault($default_value): ModelAttribute {
        $this->default_value = $default_value;
        return $this;
    }

    public function setId($object_id): self {
        if($object_id !== null) {
            if($this->object_id !== null && $this->object_id != $object_id) {
                throw new Exception('Object ID is already set');
            }
            $this->object_id = $object_id;
        }
        return $this;
    }

    #################################################################
    #################################################################
    ######## MODELS GENERIC METHODS (get, set, update, ...) #########
    #################################################################
    #################################################################
    public function getAttributeValue(bool $as_object = false, bool $fetch_if_null = false, $object_id = null){
        if ($this->value == null && $fetch_if_null) {
            return $this->fetchAttributeValue($as_object, $object_id);
        } 

        return $this->get($as_object);
    }

    /**
     * This method acts as a basic getter for the attribute's value.
     * If this value is not know yet, a fetch can be forced with the fetch_if_null argument.
     *
     * @param bool $fetch_if_null Whether to fetch the attribute value if it is null.
     * @return mixed|null The value of the attribute, or null if it is null and $fetch_if_null is false.
     * @throws Exception If the object ID is not set.
     */
    protected function get(bool $as_object = false) {
        if ($as_object == true) {
            return $this->value;
        }

        switch ($this->type) {
            case AttributeType::LIST:
                return implode(',', $this->value);
            case AttributeType::DATE:
                return $this->value == null ? null : $this->value->format('Y-m-d');
            case AttributeType::DATETIME:
                return $this->value == null ? null : $this->value->format('Y-m-d H:i:s');
            default:
                return $this->value;
        }
    }

    public function fetchAttributeValue(bool $as_object = false, $object_id = null) {
        $this->setId($object_id);
        $this->fetch();
        return $this->get($as_object);
    }

    /**
     * Loads the attribute value from the database for the specified object
     * Warning : this method creates a database call, for attributes that are stored within the model table, using the model's load function would be more efficient when laoding multiple attributes
     *
     */
    protected function fetch(): void {
        if ($this->object_id === null) {
            throw new Exception("Object ID not set");
        }

        $value = Query::from($this->model_table)->get($this->target_column)->where($this->model_column, $this->object_id)->fetchAsArray();
        $this->setAttributeValue($value[$this->target_column], false);
        return;
    }

    /**
     * Instantiates an attribute with the given value.
     * Used when creating or loading a ressource, this method throws exceptions, if you are not sure of the provided value (user provided values) prefer the checkAttribute method
     *
     * @param mixed $value The value to set for the attribute.
     * @param int|null $object_id The ID of the object associated with the attribute.
     * @return void
     * @throws Exception If the attribute is a relation and cannot be instantiated.
     * @throws Exception If the object ID is not set and not provided.
     * @throws Exception If the object ID is already set and provided.
     */
    public function setAttributeValue($attribute_value, $update_in_db = false, $object_id = null)
    {
        $messages = [];
        $current_value = null;
        $mode = 'create';
        $this->setId($object_id);
    
        if ($this->object_id !== null) {
            $current_value = $this->value;
            $mode = 'edit';
        }

        #### Checking the attribute value
        if($this->is_required && $attribute_value == null) {
            if($mode == 'create' && $this->default_value == null) {
                $this->messages[] = ['error', 'attribute-required', $this->name." is required but no value was provided."];
                $this->is_valid = false;
                return null;
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
        }

        ##### Value parsing and setting 
        try {
            $this->value = $this->parseValue($attribute_value);
        } catch (Throwable $e) {
            $this->messages[] = ['error', 'attribute-type', $e->getMessage()];
            $this->is_valid = false;
            return null;
        }

        
        ##### Update the value in db if needed
        if($update_in_db) {
            $this->updateAttributeValue();
        }

        return $this;
    }

    /**
     * This method only trigger an UPDATE query with the current attribute value
     * To change and update a value at the same time use the setAttributeValue() method with the $update_in_db param set to true
     *
     * @return self The instance of the object
     */
    protected function updateAttributeValue(): self
    {
        if($this->object_id === null) {
            throw new Exception("Object ID not set");
        }
        $datetime = new DateTime("now", new DateTimeZone($_ENV['TZ']));
        $datetime = $datetime->format('Y-m-d H:i:s');
        
        Database::execute("UPDATE $this->model_table SET $this->target_column = ?, updated_at = ? WHERE $this->model_column = ?", "sss", $this->value, $datetime, $this->object_id);

        return $this;
    }

    /**
     * Remove the value of the attribute, which equals to
     *  - For attributes : setting the value to null (or a default value)
     *  - For relations : deleting the corresponding row(s) in the db
     *
     * @param mixed $target The target attribute to remove. If null, all attributes will be removed.
     * @throws Exception If the object ID is not set or if the target is not set.
     * @return void
     */
    public function removeAttribute($target = null)
    {
        if ($this->object_id === null) {
            throw new Exception("Object ID not set");
        }

        $datetime = new DateTime("now", new DateTimeZone($_ENV['TZ']));
        $datetime = $datetime->format('Y-m-d H:i:s');
    }

    #################################################################
    #################################################################
    ########                     GETTERS                     ########
    #################################################################
    #################################################################

    public function getType()
    {
        return $this->type->value;
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

    public function getSQLType() {
        return $this->type->SQLType();
    }

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

    public function isRequired()
    {
        return $this->is_required;
    }

    // public function isNullable() {
    //     return $this->is_nullable;
    // }

    public function getAttributeTable()
    {
        return $this->model_table;
    }


    #################################################################
    #################################################################
    ########                HELPERS METHPODS                 ########
    #################################################################
    #################################################################

    /**
     * Generates a theorical name for the relation table based on the source and target models and their cardinalities.
     *
     * @return string The name of the relation table.
     */
    protected function makeRelationTableName(): String
    {
        $name = $this->bundle == 'default' ? '' : $this->bundle . '_';
        return $name . $this->model.'s';
    }

    /**
     * Parses the given value based on the type of the model attribute.
     *
     * @param mixed $value The value to be parsed.
     * @return mixed The parsed value.
     * @throws Exception If the value does not match the expected type.
     */
    protected function parseValue($value)
    {
        $generic_exception_message = is_string($value) ? "The attribute '$this->name' needs to be a ".$this->type->value." but the value provided '".$value."' is not." : "The attribute '$this->name' needs to be a ".$this->type->value." but the value provided is not.";

        ### Specific cases
        if ($this->type === AttributeType::RELATION || $this->type === AttributeType::ID) {
            return $value;
        }

        if($this->type === AttributeType::EMAIL) {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("The attribute '$this->name' needs to be an email but the value provided is not.");
            }
            return $value;
        }

        ### General types
        if ($this->type === AttributeType::CHAR) {
            if ($value === null) {
                return null;
            }

            if (strlen($value) > 255) {
                throw new Exception("The attribute '$this->name' can't exceed 255 characters. The given value is too long.");
            }

            return $value;
        }

        if ($this->type === AttributeType::TEXT) {
            if ($value === null) {
                return null;
            }

            return $value;
        }

        if ($this->type === AttributeType::BOOL) {
            if($value === 'true' || $value === 'on'  || $value == 1) {
                $value = true;
            } else if($value === 'false' || $value === 'off'  || $value == 0) {
                $value = false;
            }
            if (!is_bool($value) && $value != null) {
                throw new Exception($generic_exception_message);
            }

            return $value;
        }

        if ($this->type === AttributeType::INT) {
            if (!is_numeric($value) && $value != null) {
                throw new Exception($generic_exception_message);
            }

            return intval($value);
        }

        if ($this->type === AttributeType::FLOAT) {
            if (!is_numeric($value) && $value != null) {
                throw new Exception($generic_exception_message);
            }

            return floatval($value);
        }

        if ($this->type === AttributeType::LIST) {
            if ($value === null) {
                return [];
            }
            return explode(',', $value);
        }

        if ($this->type === AttributeType::DATE || $this->type === AttributeType::DATETIME) {
            if ($value === null) {
                return null;
            }
            if ($value instanceof DateTime) {
                return $value;
            }

            if (strpos($value, '/') !== false) {
                return (new DateTime())->createFromFormat($this->type === AttributeType::DATE ? 'd/m/Y' : 'd/m/y H:i:s', $value, new DateTimeZone($_ENV['TZ']));
            }
            
            return new DateTime($value, new DateTimeZone($_ENV['TZ']));
        }

        ##etc..
        throw new Exception("Type " . $this->type->value . " not supported");
    }
}
