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

    public function __construct(String $name, AttributeType $type, String $model, String $bundle = 'default', bool $isRequired = false, ?String $default_value = null, ?String $model_table = null, ?String $model_id_key = null, ?String $target_column = null) {
        $this->name = $name;
        $this->type = $type;
        $this->is_required = $isRequired;
        $this->is_inversed = false;
        $this->default_value = $default_value;
        $this->model = $model;
        $this->bundle = $bundle;
        $this->model_table = $model_table ?? $this->makeRelationTableName();
        $this->model_id_key = $model_id_key;
        $this->target_column = $target_column ?? $name;
        
        return $this;
    }

    #################################################################
    #################################################################
    ########             CLASS SPECIFIC METHODS             #########
    #################################################################
    #################################################################
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
     * Sets the attribute value for the model.
     *
     * @param mixed $attribute_value The value to set for the attribute.
     * @param bool $is_creation Indicates if the operation is a creation (true) or an edit (false).
     * @return static|null Returns the current instance of the model or null if validation fails.
     *
     * This method performs the following operations:
     * - Initializes messages and determines the mode (create/edit).
     * - Checks if the attribute value is required and handles null values accordingly.
     * - Uses the default value if provided and necessary.
     * - Parses and sets the attribute value, handling any exceptions that occur during parsing.
     *
     * @throws Throwable If an error occurs during value parsing.
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
     * This method updates the value of a specific attribute in the database table associated with the model.
     * It also updates the `updated_at` timestamp to the current date and time.
     *
     * @throws Exception If the object ID is not set.
     *
     * @return static Returns the current instance of the model.
     */
    protected function updateAttributeValue(): static
    {
        if($this->object_id === null) {
            throw new Exception("Object ID not set");
        }
        $datetime = new DateTime("now", Config::tz());
        $datetime = $datetime->format('Y-m-d H:i:s');
        
        Database::execute("UPDATE $this->model_table SET $this->target_column = ?, updated_at = ? WHERE $this->model_id_key = ?", "sss", $this->value, $datetime, $this->object_id);

        return $this;
    }

    /**
     * Fetches the attribute value from the database.
     *
     * This method retrieves the value of a specific attribute for the current object
     * from the database. It constructs a query using the model's table, target column,
     * and object ID to fetch the attribute value as an array.
     *
     * @throws Exception if the object ID is not set.
     *
     * @return array The attribute value fetched from the database.
     */
    protected function fetchAttributeValue() {
        if ($this->object_id === null) {
            throw new Exception("Object ID not set");
        }

        $value = Query::from($this->model_table)->get($this->target_column)->where($this->model_id_key, $this->object_id)->fetch();
        return $value;
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
