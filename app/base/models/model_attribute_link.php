<?php

namespace pz;

use Exception;
use DateTime;
use DateTimeZone;
use pz\database\{Database, Query, ModelQuery};
use pz\Enums\model\AttributeType;
use Throwable;

class ModelAttributeLink extends ModelAttribute
{
    public bool $is_link = true;
    public readonly bool $is_inversed;
    public readonly String|null $n_links;

    public String $model_idKey;
    public AttributeType $model_idType;
    public String $model_idSQLType;

    public String|null $target;
    public String $target_idKey;
    public AttributeType $target_idType;
    public String $target_idSQLType;
    public $target_id;
    
    public function __construct(String $name, object $model, object $target, String $model_column, String $target_column, String $n_links = 'one', bool $is_inversed = false, bool $isRequired = false, ?String $default_value = null)
    {        
        parent::__construct($name, AttributeType::RELATION, $isRequired, $default_value, $model::getName(), $model::getBundle(), $model->table, $model_column);
        
        $this->n_links = $n_links;
        $this->is_inversed = $is_inversed;
        
        if($n_links == 'many' && !($is_inversed||$this->is_link_through)) {
            throw new Exception("One-to-many relations must be inversed or handled with a LinkTrough.");
        }
        
        $this->target = get_class($target);
        $this->target_column = $target_column;
        $this->target_table = $target->table;
        
        $this->target_idKey = $target->idKey;
        $this->target_idType = $target->idType;
        $this->target_idSQLType = $this->target_idType->SQLQueryType();
        
        $this->model_idKey = $model->idKey;
        $this->model_idType = $model->idType;
        $this->model_idSQLType = $this->model_idType->SQLQueryType();
        
        return $this;
    }

    public function getAttributeValue(bool $as_object = true, bool $fetch_if_null = true, $object_id = null, $target_id = null){
        if ($this->value == null && $fetch_if_null) {
            return $this->fetchAttributeValue($as_object, $object_id, $target_id);
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
    protected function get(bool $as_object = false)
    {
        if($this->value == null) {
            return null;
        }
        
        if($as_object == true) {
            return $this->value;
        }

        return $this->value->toArray();
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
        if($this->is_inversed) {
            $value = ModelQuery::fromModel($this->target)->where($this->target_column, $this->object_id)->fetch();
            $this->setAttributeValue($value);
            return;
        } 
        
        if($this->target_id != null) {
            $value = ModelQuery::fromModel($this->target)->where($this->target_idKey, $this->target_id)->loadRelations()->fetch();
            $this->setAttributeValue($value);
            return;
        }
        
        if($this->object_id != null) {
            $value = ModelQuery::fromModel($this->target)->where($this->target_column, $this->object_id)->fetch();
            $this->setAttributeValue($value);
            return;
        }

        throw new Exception("Relation ID not passed");
        return;
    }

    public function fetchAttributeValue(bool $as_object = false, $object_id = null, $target_id = null) {
        $this->setId($object_id, $target_id);
        $this->fetch();
        return $this->get($as_object);
    }

    public function setId($object_id, $target_id = null): self {
        parent::setId($object_id);

        if($target_id != null) {
            $this->target_id = $target_id;
            if($this->n_links == 'many' && !is_array($this->target_id)) {
                $this->target_id = [$target_id];
            }
        }
        return $this;
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
    public function setAttributeValue($attribute_value, $update_in_db = false, $object_id = null) {
        $messages = [];
        $current_value = null;
        $mode = 'create';
        $this->setId($object_id);
    
        if ($this->object_id !== null) {
            $current_value = $this->value;
            $mode = 'edit';
        }
        
        #### Checking the attribute value
        $attribute_value = $attribute_value === [] ? null : $attribute_value;

        #Check if the value is required
        if($attribute_value == null) {
            if($this->is_required) {
                if($mode == 'edit' && $current_value != null) {
                    $this->messages[] = ['warning', 'old-value-user', "The old value was kept for $this->name as the new value passed was null and this attribute is required."];
                    return $this;
                }
    
                $this->messages[] = ['error', 'attribute-required', $this->name." is required but no value was provided."];
                $this->is_valid = false;
                return null;
            } else {
                $messages[] = ['info', 'attribute-missing-not-required', "No value was provided for ".$this->name];
            }
        }

        #### Unpacking the value
        if($this->n_links == 'many') {
            if(!is_array($attribute_value)) {
                $this->unpackValue($attribute_value);
            } else {
                foreach($attribute_value as $value) {
                    $this->unpackValue($value);
                }
            }
        } else {
            if(is_array($attribute_value)) {
                 if(count($attribute_value) == 1) {
                    $this->unpackValue($attribute_value[0]);
                } else {
                    $this->messages[] = ['error', 'too-many-relations', "The attribute '$this->name' is a single relation but multiple values where provided."];
                    $this->is_valid = false;
                    return null;
                }
            } else {
                $this->unpackValue($attribute_value);
            }
        }

        #### Checking that the value is valid
        if(!$this->is_valid) {
            return null;
        }

        ##### Update the value in db if needed
        if($update_in_db) {
            $this->updateAttributeValue();
        }

        return $this;
    }

    protected function unpackValue($attribute_value) {
        if($attribute_value == null) {
            return null;
        }
        if(is_object($attribute_value)) {
            if(!is_subclass_of($attribute_value, 'pz\Model')) {
                $this->messages[] = ['error', 'invalid-value', "The value provided for $this->name is not a valid model."];
                $this->is_valid = false;
                return null;
            }

            if($this->n_links == 'one') {
                $this->value = $attribute_value;
                return $this;
            }

            $this->value[$attribute_value->getId()] = $attribute_value;
            return $this;
        }
        if(is_array($attribute_value)) {
            $this->messages[] = ['error', 'invalid-value', "The value provided for $this->name is an array, it should be a model."];
            $this->is_valid = false;
            return null;
        }
        $this->target_id = $attribute_value;
        return $this;
    }

    public function removeLink($target = null, $update_in_db = true, $delete_target = false): self
    {
        if($this->n_links == 'one') {
            $this->value = null;
            if($update_in_db) {
                $this->updateAttributeValue();
                if($target == null) {
                    $this->messages[] = ['error', 'target-missing', "No target provided for the removal of the link."];
                    return $this;
                }
                if($delete_target) {
                    Database::execute('DELETE FROM '.$this->target_table.' WHERE '.$this->target_column.' = ? AND id = ?', $this->model_idSQLType.$this->target_idSQLType, $this->object_id, $target->getId());
                }
            }
            return $this;
        } else {
            if($target == null) {
                $this->messages[] = ['error', 'target-missing', "No target provided for the removal of the link."];
                return $this;
            }
            
            unset($this->value[$target->getId()]);
            
            if($update_in_db) {
                $table = $this->model_table;
                if($this->is_inversed) {
                    $table = $this->target_table;
                } 
                Database::execute("UPDATE $this->model_table SET $this->target_column = ? WHERE $this->model_column = ?", $this->target_idSQLType.$this->model_idSQLType, null, $target->getId());

                if($delete_target) {
                    $table = $this->target_table;
                    if(!$this->is_inversed) {
                        $table = $this->model_table;
                    }

                    Database::execute("DELETE FROM $this->target_table WHERE $this->target_column = ? AND  = ?", $this->getSQLQueryType(), $this->object_id, $target->getId());
                }
            }
            return $this;
        }
    }

    /**
     * This method only trigger an UPDATE query with the current attribute value
     * To change and update a value at the same time use the setAttributeValue() method with the $update_in_db param set to true
     *
     * @return self The instance of the object
     */
    protected function updateAttributeValue(): self
    {
        if($this->is_inversed) {
            if($this->n_links == 'one') {
                $query = 'UPDATE '.$this->target_table.' SET '.$this->target_column.' = ? WHERE id = ?';
                Database::execute($query, $this->model_idSQLType.$this->target_idSQLType, $this->object_id, $this->value->getId());
                return $this;
            } else {
                $list_target_ids = $this->value->map(fn($item) => $item->getId());
                
                $current_targets_in_db = ModelQuery::fromModel($this->target)->where($this->target_column, $this->object_id)->fetch();
                $current_target_ids = array_map(fn($item) => $item['id'], $current_targets_in_db);

                $to_add = array_diff($list_target_ids, $current_target_ids);
                $to_remove = array_diff($current_target_ids, $list_target_ids);

                Database::execute('DELETE FROM '.$this->target_table.' WHERE '.$this->target_column.' = ? AND id IN ('.implode(',', $to_remove).')', $this->model_idSQLType, $this->object_id);
                Database::execute('UPDATE '.$this->target_table.' SET '.$this->target_column.' = ? WHERE id IN ('.implode(',', $to_add).')', $this->model_idSQLType, $this->object_id);
                
                return $this;
            }
        }

        return parent::updateAttributeValue();
    }

    public function removeAttribute($target = null)
    {
        ///TODO: implement method = set value to null and update to db
    }

    #################################################################
    #################################################################
    ########                HELPERS METHPODS                 ########
    #################################################################
    #################################################################

    /**
     * Generates a theorical name for the relation table based on the source and target models and their cardinalities.
     *
     * @param string $mode Wether we are looking for the name of the source table or the target table.
     * @return string The name of the relation table.
     */
    protected function makeRelationTableName(String $mode = 'source'): String
    {
        if($mode != 'target') {
            return parent::makeRelationTableName();
        }

        $name = $this->bundle == 'default' ? '' : $this->bundle . '_';
        return $name . $this->target::getName().'s';
    }

    public function getSQLField() {
        $column_name = $this->model_column;
        $field = $column_name . ' ' . $this->type->SQLType();
        if($this->is_required) {
            $field .= ' NOT NULL';
        }
        return $field;
    }
}
?>