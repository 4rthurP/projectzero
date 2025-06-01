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

    public function __construct(String $name, String $model, String $bundle = 'default', bool $isInversed = false, bool $is_many = true, ?String $default_value = null, ?String $model_table = null, ?String $model_id_key = null, ?String $target_column = null, ?String $target = null, ?String $target_table = null, ?String $target_id_key = null, ?String $relation_table = null, ?String $relation_model_column = null, ?String $relation_model_type = null) {
        $this->name = $name;
        $this->type = AttributeType::RELATION;
        $this->is_required = false;
        $this->is_inversed = $isInversed;
        $this->default_value = null; #TODO: we could have a default link value when the load by id is implemented
        $this->bundle = $bundle;

        $this->value = [];

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

        if($target != null) {
            $target_model = new $target();
            $this->target = $target;
            $this->target_table = $target_model->table;
            $this->target_id_key = $target_model->getIdKey();
            $this->target_id_type = $target_model->idType;
            $this->target_column = $target_column ?? $target_name . '_id';     
        } else {
            if($target_table == null) {
                throw new Exception("You need to provide at least a target object or a target table for the link attribute.");
            }
            $this->target = null;
            $this->target_table = $target_table;
            $this->target_id_key = $target_id_key ?? 'id';
            $this->target_id_type = AttributeType::ID;
            $this->target_column = $target_column;
        }

        $this->relation_table = $relation_table ?? $this->makeRelationTableName('relation', $is_many);

        if($is_many) {
            $this->relation_model_type = $relation_model_type ?? $target_name . 'able_type';
            $this->relation_model_column = $relation_model_column ?? $target_name . 'able_id';
        } else {
            $this->relation_model_type = $relation_model_type;
            $this->relation_model_column = $relation_model_column ?? $model_name . '_id';
        }
                
        return $this;
    }

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

    protected function setAttributeValue($attribute_value, bool $is_creation = false): static {
        $this->value = [];
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
    protected function updateAttributeValue(): static {
        if($this->object_id === null) {
            throw new Exception("Object ID not set");
        }
        
        $datetime = new DateTime("now", Config::tz());
        $datetime = $datetime->format('Y-m-d H:i:s');

        $found_relations = $this->findRelations();
        
        $list_of_ids = array_map(fn($item) => $item[$this->target_column], $found_relations);
        foreach($this->value as $target_id => $item) {
            if(!in_array($target_id, $list_of_ids)) {
                if($this->relation_model_type == null) {
                    Database::execute("INSERT INTO $this->relation_table ($this->relation_model_column, $this->target_column) VALUES (?, ?)", "ss", $this->object_id, $target_id);
                } else {
                    Database::execute("INSERT INTO $this->relation_table ($this->relation_model_column, $this->target_column, $this->relation_model_type) VALUES (?, ?, ?)", "sss", $this->object_id, $target_id, $this->model::getName());
                }
            } else {
                $list_of_ids = array_diff($list_of_ids, [$target_id]);
            }
        }
        foreach($list_of_ids as $item) {
            if($this->relation_model_type == null) {
                Database::execute("DELETE FROM $this->relation_table WHERE $this->relation_model_column = ? AND $this->target_column = ?", "ss", $this->object_id, $item); 
            } else {
                Database::execute("DELETE FROM $this->relation_table WHERE $this->relation_model_column = ? AND $this->target_column = ? AND $this->relation_model_type = ?", "sss", $this->object_id, $item, $this->model::getName());
            }
        }
        
        
        return $this;
    }

    protected function fetchAttributeValue() {
        $found_relations = $this->findRelations();
        if(count($found_relations) == 0) {
            return null;
        }
        
        $list_of_ids = array_map(fn($item) => $item[$this->target_column], $found_relations);
        
        $found_values = Query::from($this->target_table)->whereIn($this->target_id_key, $list_of_ids)->fetch();
        
        if(count($found_values) == 0) {
            return null;
        }
        
        return $found_values;
    }

    protected function findRelations() {
        $relation_query = Query::from($this->relation_table)->get($this->target_column)->where($this->relation_model_column, $this->object_id);
        if($this->relation_model_type != null) {
            $relation_query->where($this->relation_model_type, $this->model::getName());
        }
        return $relation_query->fetch();
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
}