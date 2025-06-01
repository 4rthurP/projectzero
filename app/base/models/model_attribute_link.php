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

class ModelAttributeLink extends AbstractModelAttribute
{
    public bool $is_link = true;
    public bool $is_link_through = false;

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
        ?String $target_id_key = null
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
        $this->target_column = $target_column ?? $name . '_id';

        if($target != null) {
            $target_model = new $target();
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
                
        return $this;
    }

    protected function getAttributeValue() {
        if($this->target == null) {
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
            }

            ##### Value parsing and setting 
            try {
                $this->value = $this->parseValue($attribute_value);
            } catch (Throwable $e) {
                var_dump($attribute_value);
                throw new Exception("Attribute value must be an object of type " . $this->target);
                $this->messages[] = ['error', 'attribute-type', $e->getMessage()];
                $this->is_valid = false;
                return $this;
            }
            return $this;
        }

        #Case B: Multiple values
        if(is_object($attribute_value)) {
            $values = [$attribute_value];
        } elseif(is_array($attribute_value)) {
            if(!is_object($attribute_value[0]) && ! is_array($attribute_value[0])) {
                $values = [$attribute_value];
            } else {
                $values = $attribute_value;
            }
        } else {
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
                $this->value[] = $parsed_value;
            } catch (Throwable $e) {
                $this->messages[] = ['error', 'attribute-type', $e->getMessage()];
                $this->is_valid = false;
                return $this;
            }
        }
        return $this;
    }
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

            Database::execute("UPDATE $this->model_table SET $this->target_column = ?, updated_at = ? WHERE $this->model_id_key = ?", "sss", $target_id, $datetime, $this->object_id);

        } else {
            $list_of_values = Query::from($this->target_table)->get($this->target_id_key)->where($this->target_column, $this->object_id)->fetch();
            if($list_of_values != null) {
                $list_of_values = array_map(fn($item) => $item['id'], $list_of_values);
                foreach($this->value as $target_id => $item) {
                    if(!in_array($target_id, $list_of_values)) {
                        Database::execute("UPDATE $this->target_table SET $this->target_column = ?, updated_at = ? WHERE $this->target_id_key = ?", "sss", $this->object_id, $datetime, $item->getId());
                    } else {
                        $list_of_values = array_diff($list_of_values, [$target_id]);
                    }
                }
                foreach($list_of_values as $item) {
                    Database::execute("UPDATE $this->target_table SET $this->target_column = ?, updated_at = ? WHERE $this->target_id_key = ?", "sss", null, $datetime, $item);
                }
            } 
        }
        return $this;
    }

    protected function fetchAttributeValue() {
        if ($this->object_id === null) {
            throw new Exception("Object ID not set");
        }

        if($this->is_inversed) {
            $found_values = Query::from($this->target_table)->where($this->target_column, $this->object_id);
            return $found_values;
        } 
        $value = Query::from($this->model_table)->where($this->model_id_key, $this->object_id)->first();
        if($value != null) {
            $found_values = Query::from($this->target_table)->where($this->target_id_key, $value[$this->target_column])->first();
            return $found_values;
        }
        
        return null;
    }

    protected function parseValue($value) {
        if($this->target != null) {
            if(is_a($value, $this->target)) {
                return $value;
            } elseif(is_array($value) && array_key_exists($this->target_id_key, $value)) {
                $target = new $this->target();
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

    public function getTargetId() {
        if($this->value != null) {
            if($this->target != null) {
                return $this->value->getId();
            }
            return $this->value[$this->target_id_key];
        }
    }
}