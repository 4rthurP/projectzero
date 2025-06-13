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

class ModelAttribute extends AbstractModelAttribute
{    
    public bool $is_link = false;
    public bool $is_link_through = false;

    /**
     * Creates a new ModelAttribute instance.
     *
     * @param string $name The name of the attribute
     * @param AttributeType $type The type of the attribute (e.g., CHAR, INT, DATE, etc.)
     * @param string $model The model class name this attribute belongs to
     * @param string $bundle The bundle name for organizing attributes (default: 'default')
     * @param bool $isRequired Whether this attribute is required (default: false)
     * @param string|null $default_value Default value for the attribute when not provided
     * @param string|null $model_table Database table name for this model
     * @param string|null $model_id_key Primary key column name in the database table
     * @param string|null $target_column Database column name for this attribute (defaults to attribute name)
     * @param string|null $updated_at_column Column name for tracking update timestamps
     * @return static Returns the current instance for method chaining
     */
    public function __construct(String $name, AttributeType $type, String $model, String $bundle = 'default', bool $isRequired = false, ?String $default_value = null, ?String $model_table = null, ?String $model_id_key = null, ?String $target_column = null, ?String $updated_at_column = null) {
        $this->name = $name;
        $this->type = $type;
        $this->is_required = $isRequired;
        $this->is_inversed = false;
        $this->default_value = $default_value;
        $this->model = $model;
        $this->bundle = $bundle;
        $this->model_table = $model_table;
        $this->model_id_key = $model_id_key;
        $this->target_column = $target_column ?? $name;
        $this->updated_at_column = $updated_at_column;
        
        return $this;
    }

    #################################################################
    #################################################################
    ########             CLASS SPECIFIC METHODS             #########
    #################################################################
    #################################################################
    /**
     * Retrieves the formatted attribute value based on its type.
     *
     * This method handles type-specific formatting for different attribute types:
     * - LIST: Converts arrays to comma-separated strings
     * - DATE: Formats DateTime objects to 'd/m/Y' format
     * - DATETIME: Formats DateTime objects to 'd/m/Y H:i:s' format
     * - Other types: Returns the raw value
     *
     * @return mixed The formatted attribute value or null if the value is null
     */
    protected function getAttributeValue() {
        switch ($this->type) {
            case AttributeType::LIST:
                return is_array($this->value) ? implode(',', $this->value) : $this->value;
            case AttributeType::DATE:
                return $this->value == null ? null : $this->value->format('d/m/Y');
            case AttributeType::DATETIME:
                return $this->value == null ? null : $this->value->format('d/m/Y H:i:s');
            default:
                return $this->value;
        }
    }
    /**
     * Sets and validates the attribute value for the model.
     *
     * This method handles the complete process of setting an attribute value including:
     * - Validation of required fields
     * - Application of default values when appropriate
     * - Type-specific parsing and validation
     * - Error handling and message generation
     *
     * @param mixed $attribute_value The value to set for the attribute
     * @param bool $is_creation Whether this is a creation operation (true) or update (false)
     * @return static Returns the current instance for method chaining
     * @throws Throwable If an error occurs during value parsing
     *
     * @see parseValue() for type-specific parsing logic
     */
    protected function setAttributeValue($attribute_value, bool $is_creation = false): static {
        $messages = [];
        $current_value = null;
        $mode = 'create';
        
        if(!$is_creation) {
            $current_value = $this->value;
            $mode = 'edit';
        }

        #### Checking the attribute value
        if($this->is_required && $attribute_value === null) {
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

        if(!$this->is_required && $attribute_value === null) {
            $messages[] = ['info', 'attribute-missing-not-required', "No value was provided for ".$this->name];
        }

        ##### Value parsing and setting 
        try {
            $this->value = $this->parseValue($attribute_value);
        } catch (Throwable $e) {
            $this->messages[] = ['error', 'attribute-type', $e->getMessage()];
            $this->is_valid = false;
            return $this;
        }

        return $this;
    }

    /**
     * Updates the attribute value in the database.
     *
     * Performs a database UPDATE operation to store the current attribute value.
     * Automatically updates the timestamp column if configured.
     *
     * @return static Returns the current instance for method chaining
     * @throws Exception If the object ID is not set
     *
     * @see Database::execute() for the underlying database operation
     */
    protected function updateAttributeValue(): static
    {
        if($this->object_id === null) {
            throw new Exception("Object ID not set");
        }
        
        $set_clauses = ["$this->target_column = ?"];
        $values = [$this->value];
        $types = "s";
        
        if ($this->updated_at_column) {
            $datetime = new DateTime("now", Config::tz());
            $set_clauses[] = "$this->updated_at_column = ?";
            $values[] = $datetime->format('Y-m-d H:i:s');
            $types .= "s";
        }
        
        $values[] = $this->object_id;
        $types .= "s";
        
        $set_clause = implode(", ", $set_clauses);
        Database::execute("UPDATE $this->model_table SET $set_clause WHERE $this->model_id_key = ?", $types, ...$values);

        return $this;
    }

    /**
     * Fetches the attribute value from the database.
     *
     * Retrieves the current value of this attribute from the database using the
     * configured table, column, and object ID.
     *
     * @return array The attribute value from the database
     * @throws Exception If the object ID is not set
     *
     * @see Query::from() for the query builder used
     */
    protected function fetchAttributeValue() {
        if ($this->object_id === null) {
            throw new Exception("Object ID not set");
        }

        $value = Query::from($this->model_table)->get($this->target_column)->where($this->model_id_key, $this->object_id)->fetch();
        return $value;
    }

    /**
     * Parses and validates a value according to the attribute's type.
     *
     * Handles type-specific parsing and validation for all supported attribute types:
     * - CHAR: String validation with 255 character limit
     * - TEXT: String validation without length limit
     * - BOOL: Boolean conversion from various string representations
     * - INT: Integer conversion with numeric validation
     * - FLOAT: Float conversion with numeric validation
     * - LIST: Comma-separated string to array conversion
     * - DATE/DATETIME: DateTime object creation with timezone support
     * - EMAIL: Email format validation
     * - RELATION/ID: Pass-through for relationship values
     *
     * @param mixed $value The raw value to parse and validate
     * @return mixed The parsed and validated value in the appropriate PHP type
     * @throws Exception If the value doesn't match the expected type or format
     *
     * @see AttributeType for all supported attribute types
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
            if($value == '') {
                return null;
            }
            if (!is_numeric($value)) {
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
                return (new DateTime())->createFromFormat($this->type === AttributeType::DATE ? 'd/m/Y' : 'd/m/y H:i:s', $value, Config::tz());
            }
            
            return new DateTime($value, Config::tz());
        }

        ##etc..
        throw new Exception("Type " . $this->type->value . " not supported");
    }
}
