<?php

namespace pz;

use Attribute;
use Exception;
use DateTime;
use DateTimeZone;

use pz\{ModelAttribute, ModelAttributeLink, ModelAttributeLinkThrough};
use pz\database\{Database, ModelQuery, Query};
use pz\Enums\Routing\{Privacy};
use pz\Enums\model\AttributeType;

class Model
{
    public static $name;
    public static $bundle;
    public String $table;
    public ?String $page_url = null;

    public String $idKey = 'id';
    public AttributeType $idType = AttributeType::ID;
    protected bool $idAutoIncrement = true;
    protected bool $hasDefinedIdAttribute = false;

    protected ?String $userKey = 'user_id';
    protected Privacy $canView = Privacy::PROTECTED;
    protected Privacy $canEdit = Privacy::PROTECTED;
    protected bool $hasDefinedUserAttribute = false;

    protected bool $hasTimeStamps = true;
    protected bool $hasTimestampsAttributes = false;
    protected bool $is_soft_delete = true;
    protected ?String $timestampCreatedAtName;
    protected ?String $timestampUpdatedAtName;
    protected ?String $timestampDeletedAtName;

    protected array $attributes = [];
    protected array $objects_to_links_dict = [];

    protected $id;
    protected bool $is_initialized = false;
    protected bool $is_instantiated = false;
    protected readonly bool $load_relations;

    public array $messages = ['all' => [], 'attributes' => []];
    protected array $form_data = [];
    protected bool $is_valid = true;

    public function __construct(bool $loadRelations = true)
    {
        //Not loading the relations is critical to allow to look for linked model infos during link creations without creating loading loops of relations
        $this->load_relations = $loadRelations;
        $this->model();

        if (!$this->hasDefinedIdAttribute) {
            $this->id();
        }
        if (!$this->hasDefinedUserAttribute) {
            $this->user();
        }
        if (!$this->hasTimestampsAttributes) {
            $this->timestamps();
        }
    }

    /**
     * Initializes the model.
     * This method is called by the first attribute declared to make sure that we have a table name before defining the attributes.
     *
     * @throws Exception if the model's name has not been defined.
     */
    protected function initialize()
    {
        if ($this->is_initialized) {
            return;
        }

        if (!isset(static::$name)) {
            throw new Exception("The model's name has not been defined");
        }
        if (!isset(static::$bundle)) {
            static::$bundle = 'default';
        }

        if (!isset($this->table)) {
            $this->makeModelTableName();
        }
        $this->is_initialized = true;
    }

    /**
     * This method is used as a placeholder for declaring attributes in child classes.
     * It is automatically called by the constructor
     */
    protected function model() {
        //Empty method, the children classes declares their attributes by supplementing this method
    }

    ###############################
    # Model definition methods
    ###############################
    /**
     * Sets the table name for the model.
     *
     * @param string $table The name of the table.
     * @return void
     */
    protected function table(String $table)
    {
        if ($this->is_initialized) {
            throw new Exception('Table creation needs to be defined before any other attribute');
        }
        $this->table = $table;
    }

    /**
     * Defines a new attribute for the model.
     *
     * @param string $name The name of the attribute.
     * @param AttributeType $type The type of the attribute.
     * @param bool $required Indicates if the attribute is required.
     * @param string|null $default_value The default value of the attribute.
     * @param string|null $column The column name in the database.
     * @return ModelAttribute The created ModelAttribute object.
     * @throws Exception If the attribute with the same name already exists.
     */
    protected function attribute(String $name, AttributeType $type = AttributeType::CHAR, bool $required = false, ?String $default_value = null, ?String $column = null): ModelAttribute
    {
        if (!$this->is_initialized) {
            $this->initialize();
        }

        if ($this->attributeExists($name)) {
            throw new Exception("The attribute '$name' has already been defined");
        }

        if($type === AttributeType::RELATION) {
            throw new Exception("The attribute type 'relation' is not allowed, use linkTo, linkedTo or linkThrough instead");
        }

        if ($type === AttributeType::ID || $type === AttributeType::UUID) {
            return $this->id($name, $type === AttributeType::ID, $type);
        }

        $column = $column ?? $name;
        $attribute = new ModelAttribute($name, $type, $required, $default_value, static::$name, static::$bundle, $this->table, $this->idKey, $column);

        $this->attributes[$name] = $attribute;
        return $attribute;
    }

    /**
     * Helper function to define an id attribute for the model.
     *
     * @param string $name The name of the attribute.
     * @param bool $auto_increment Whether the id attribute should auto increment.
     * @param AttributeType $idType The type of the id attribute.
     * @param string $column The column name in the database.
     * @return ModelAttribute The created id attribute.
     * @throws Exception If the attribute with the given name already exists.
     * @throws Exception If an id attribute has already been defined.
     * @throws Exception If the id attribute type is not 'id' or 'uuid'.
     */
    protected function id(String $name = 'id', bool $auto_increment = true, AttributeType $idType = AttributeType::ID, String $column = 'id'): self
    {
        if (!$this->is_initialized) {
            $this->initialize();
        }

        if ($this->attributeExists($name)) {
            throw new Exception("The attribute '$name' has already been defined");
        }

        if ($this->hasDefinedIdAttribute) {
            throw new Exception("An id attribute has already been defined");
        }

        if ($idType !== AttributeType::ID && $idType !== AttributeType::UUID) {
            throw new Exception("The id attribute must be of type 'id' or 'uuid'");
        }

        //The id attribute is always required, but is not defined as such in the attribute definition because it would cause conflicts when creating the model (requiring the id attribute to be defined before the ressource is created in the db)
        $attribute = new ModelAttribute($name, $idType, false, null, static::$name, static::$bundle, $this->table, $column);

        $this->attributes[$name] = $attribute;

        $this->idKey = $name;
        $this->idAutoIncrement = $auto_increment;
        $this->idType = $idType;
        $this->hasDefinedIdAttribute = true;

        return $this;
    }

    /**
     * Helper function to define a user attribute for the model.
     *
     * @param string|null $attribute_name The name of the attribute. If null, the user attribute will be unset.
     * @param string $userKey The key of the user attribute.
     * @param Privacy $canView The privacy level for viewing the user attribute.
     * @param Privacy $canEdit The privacy level for editing the user attribute.
     * @return ModelAttribute|null The created user attribute or null if $attribute_name is null.
     * @throws Exception If the attribute with $userKey or a user attribute has already been defined.
     */
    protected function user(?String $userKey = 'user_id', Privacy $canView = Privacy::PROTECTED, Privacy $canEdit = Privacy::PROTECTED): self
    {
        if (!$this->is_initialized) {
            $this->initialize();
        }

        if ($this->attributeExists($userKey)) {
            throw new Exception("The attribute '$userKey' has already been defined");
        }

        if ($this->hasDefinedUserAttribute) {
            throw new Exception("A user attribute has already been defined");
        }

        if ($userKey === null) {
            $this->userKey = null;
            $this->canView = Privacy::PUBLIC;
            $this->canEdit = Privacy::PUBLIC;
            $this->hasDefinedUserAttribute = true;
            return $this;
        }

        $attribute = new ModelAttribute($userKey, AttributeType::INT, true, null, static::$name, static::$bundle, $this->table, $userKey);

        $this->attributes[$userKey] = $attribute;

        $this->userKey = $userKey;
        $this->canView = $canView;
        $this->canEdit = $canEdit;
        $this->hasDefinedUserAttribute = true;

        return $this;
    }

    /**
     * Sets up timestamps for the model.
     * By default, timestamps are enabled and set to 'created_at', 'updated_at', and 'deleted_at'.
     * To disable timestamps, call this function with the first argument set to false.
     * To disable soft deletion of the model, set the timestampDeletedAtName to null (or call the dedicated softDelete function with the first argument set to false).
     *
     * @param bool $hasTimestamps Determines if timestamps should be enabled or disabled.
     * @param string $timestampCreatedAtName The name of the created_at timestamp attribute.
     * @param string $timestampUpdatedAtName The name of the updated_at timestamp attribute.
     * @param string $timestampDeletedAtName The name of the deleted_at timestamp attribute.
     * @return self Returns the model instance.
     * @throws Exception Throws an exception if timestamps have already been defined.
     */
    protected function timestamps(bool $hasTimestamps = true, String $timestampCreatedAtName = 'created_at', String $timestampUpdatedAtName = 'updated_at', String $timestampDeletedAtName = 'deleted_at'): self
    {
        if (!$this->is_initialized) {
            $this->initialize();
        }

        if ($this->hasTimestampsAttributes) {
            throw new Exception("Timestamps have already been defined");
        }

        $this->hasTimeStamps = $hasTimestamps;
        
        if($this->hasTimeStamps && $timestampCreatedAtName !== null) {
            if(!$this->attributeExists($timestampCreatedAtName)) {
                $this->attribute($timestampCreatedAtName, AttributeType::DATETIME, true, null, $timestampCreatedAtName);
            }
            $this->timestampCreatedAtName = $timestampCreatedAtName;
        } else {
            if($this->attributeExists($timestampCreatedAtName)) {
                $this->unsetAttribute($timestampCreatedAtName);
            }
            $this->timestampCreatedAtName = null;
        }

        if($this->hasTimeStamps && $timestampUpdatedAtName !== null) {
            if(!$this->attributeExists($timestampUpdatedAtName)) {
                $this->attribute($timestampUpdatedAtName, AttributeType::DATETIME, true, null, $timestampUpdatedAtName);
            }
            $this->timestampUpdatedAtName = $timestampUpdatedAtName;
        } else {
            if($this->attributeExists($timestampUpdatedAtName)) {
                $this->unsetAttribute($timestampUpdatedAtName);
            }
            $this->timestampUpdatedAtName = null;
        }

        ///Uses the dedicted function to set up softDelete
        $this->softDelete($this->hasTimeStamps, $timestampDeletedAtName);

        $this->hasTimestampsAttributes = true;
        return $this;
    }

    /**
     * Add a soft delete attribute to the model.
     * By default all models are soft deleted, simply call this function with the first argument set to false to disable soft delete (or call the timestamps function with the deleted_at name set to null)
     *
     * @param bool $is_soft_delete Whether to perform soft delete or not.
     * @param string $timestampDeletedAtName The name of the timestamp column for deleted_at.
     * @return self The updated model instance.
     */
    protected function softDelete(bool $is_soft_delete = true, String $timestampDeletedAtName = 'deleted_at'): self {
        if($is_soft_delete && $timestampDeletedAtName !== null) {
            if(!$this->attributeExists($timestampDeletedAtName)) {
                $this->attribute($timestampDeletedAtName, AttributeType::DATETIME, false, null, $timestampDeletedAtName);
            }
            $this->timestampDeletedAtName = $timestampDeletedAtName;
        } else {
            if($this->attributeExists($timestampDeletedAtName)) {
                $this->unsetAttribute($timestampDeletedAtName);
            }
            $this->timestampDeletedAtName = null;
        }

        return $this;
    }

    /**
     * Creates a link  (relational attribute) between the current model and a target model.
     *
     * @param string|class $target_model The target model class or its fully qualified name.
     * @param string|null $name The name of the link attribute. If not provided, it will default to the target model's name.
     * @param bool $is_required Indicates if the link attribute is required or not. Default is false.
     * @param string|null $column The column name in the current model's table that holds the link attribute. If not provided, it will default to the target model's name appended with '_id'.
     * @param string|null $target_column The column name in the target model's table that holds the link attribute. If not provided, it will default to 'id'.
     * @param string|null $target_table The table name of the target model. If not provided, it will default to the target model's table name.
     * @return ModelAttribute The created link attribute.
     * @throws Exception If the target model is not a subclass of Model.
     */
    protected function linkTo($target_classname, ?String $name = null, bool $is_required = false, ?String $model_column = null, ?String $target_column = null): ModelAttributeLink|null
    {
        if(!$this->load_relations) {
            return null; 
        }

        if (!$this->is_initialized) {
            $this->initialize();
        }

        if (!is_subclass_of($target_classname, Model::class)) {
            throw new Exception("The target model must be a subclass of Model");
        }

        if(isset($this->objects_to_links_dict[$target_classname])) {
            throw new Exception("The link to the target model has already been defined");
        }

        $target_name = $target_classname::getName();
        $target = new $target_classname(false);
        $name = $name ?? $target_name;

        $model_column = $model_column ?? $target_name . '_id';
        $target_column = $target_column ?? $target->idKey;

        $attribute = new ModelAttributeLink($name, $this, $target, $model_column, $target_column, 'one', false, $is_required);

        $this->attributes[$name] = $attribute;
        $this->objects_to_links_dict[$target_classname] = $name;

        return $attribute;
    }

    /**
     * Declares an inverse link (relational attribute) between the current model and a target model.
     *
     * @param string|class $target_model The target model class or its fully qualified name.
     * @param string|null $name The name of the attribute. If null, the target model's name will be used.
     * @param string $n_source The source cardinality of the relationship. Default is 'one'.
     * @param bool $is_required Determines if the attribute is required. Default is false.
     * @param string|null $target_model_column The column name in the target model's table. If null, the current model's name with '_id' suffix will be used.
     * @param string|null $target_table The target model's table name. If null, the current model's table name will be used.
     * @return ModelAttributeLink The created ModelAttributeLink object.
     * @throws Exception If the target model is not a subclass of Model.
     */
    protected function linkedTo($target_classname, ?String $name = null, String $n_source = 'one', bool $is_required = false, ?String $target_model_column = null): ModelAttributeLink|null
    {
        if(!$this->load_relations) {
            return null; 
        }

        if (!$this->is_initialized) {
            $this->initialize();
        }

        if (!is_subclass_of($target_classname, Model::class)) {
            throw new Exception("The target model must be a subclass of Model");
        }

        if(isset($this->objects_to_links_dict[$target_classname])) {
            throw new Exception("The link to the target model has already been defined");
        }

        $target_name = $target_classname::getName();
        $target = new $target_classname(false);
        $name = $name ?? $target_name;

        $model_column = $this->idKey;
        $target_column = $target_model_column ?? static::$name . '_id';

        $attribute = new ModelAttributeLink($name, $this, $target, $model_column, $target_column, $n_source, true, $is_required);

        $this->attributes[$name] = $attribute;
        $this->objects_to_links_dict[$target_classname] = $name;

        return $attribute;
    }

    /**
     * Creates a link (relational attribute) between the current model and a target model, with a liaison created through an intermediate table (useful for many-to-many relations).
     *
     * @param string|Model $target_model The target model or its class name.
     * @param string $n_links The number of links between the models. Default is 'one'.
     * @param bool $is_inversed Determines if the link is inversed. Default is false.
     * @param string|null $name The name of the link. If not provided, it will use the target model's name.
     * @param bool $is_required Determines if the link is required. Default is false.
     * @param string|null $target_model_table The table name of the target model. If not provided, it will be inferred from the target model's class name.
     * @param string|null $target_model_column The column name of the target model. If not provided, it will be inferred from the target model's class name.
     * @param string|null $link_through_table The table name of the link-through model. If not provided, it will be generated based on the current model, target model, and link direction.
     * @param string|null $link_through_model_column The column name of the link-through model that represents the current model. If not provided, it will be inferred from the current model's class name.
     * @param string|null $link_through_target_column The column name of the link-through model that represents the target model. If not provided, it will be inferred from the target model's class name.
     * @param string|null $link_through_model_type The type of the link-through model that represents the current model. If not provided, it will be inferred from the current model's class name.
     * @param string|null $link_through_target_type The type of the link-through model that represents the target model. If not provided, it will be inferred from the target model's class name.
     * @return ModelAttributeLinkThrough The created model attribute representing the link.
     * @throws Exception If the target model is not a subclass of Model.
     */
    protected function linkThrough($target_classname, String $n_links = 'many', bool $is_inversed = false, ?String $name = null, ?String $target_model_column = null, ?String $link_through_table = null, ?String $link_through_model_column = null, ?String $link_through_target_column = null, ?String $link_through_model_type = null, ?String $link_through_target_type = null): ModelAttributeLinkThrough|null
    {
        if(!$this->load_relations) {
            return null; 
        }

        if (!$this->is_initialized) {
            $this->initialize();
        }

        if (!is_subclass_of($target_classname, Model::class)) {
            throw new Exception("The target model must be a subclass of Model");
        }

        if(isset($this->objects_to_links_dict[$target_classname])) {
            throw new Exception("The link to the target model has already been defined");
        }

        $target_name = $target_classname::getName();
        $target = new $target_classname(false);
        $name = $name ?? $target_name;

        $model_column = $this->idKey;
        $target_column = $target_model_column ?? static::$name . '_id';

        if ($is_inversed == false) {
            $link_through_table = $link_through_table ?? static::$bundle . '_' . static::$name . '_' . $target_name . 's';
        } else {
            $link_through_table = $link_through_table ?? static::$bundle . '_' . $target_name . '_' . static::$name . 's';
        }
        $link_through_model_column = $link_through_model_column ?? static::$name . '_id';
        $link_through_target_column = $link_through_target_column ?? $target_name . '_id';
     
        $attribute = new ModelAttributeLinkThrough($name, $this, $target, $model_column, $target_column, $n_links, $is_inversed, $link_through_table, $link_through_model_column, $link_through_target_column, $link_through_model_type, $link_through_target_type);

        $this->attributes[$name] = $attribute;
        $this->objects_to_links_dict[$target_classname] = $name;

        return $attribute;
    }

    ###############################
    # Model methods
    ###############################
    /**
     * Saves the model in the database if the attributes values are valid.
     * The possibility to directly pass an array of attributes is kept for quicker use, but the use of checkForm($attributes_array)?->create() should be preferred when passing user inputed values
     *
     * @param array $attributes_array An array of attributes to be assigned to the record.
     * @return self The created record.
     */
    public function create(null|array $attributes_array = null): null|self
    {
        if ($attributes_array != null) {
            $this->checkForm($attributes_array);
        }

        if (!$this->is_valid) {
            return null;
        }

        $creation_date = new DateTime("now", new DateTimeZone($_ENV['TZ']));

        $attributes = [];
        $params = [];
        $values = [];
        $types = [];

        foreach ($this->attributes as $attribute) {
            //Attributes are not saved in three cases
            // - When they are (numerical) IDs, note that UUID still needs to be set before or provided
            // - When they are attributes stored in another table
            // - When they are inversed relations
            if ($attribute->type === AttributeType::ID) {
                continue;
            }

            if($attribute->is_link) {
                if(!$attribute->is_inversed && !$attribute->is_link_through && $attribute->n_links === 'one') {
                    $attributes[] = $attribute->model_column;
                    $params[] = "?";
                    $values[] = $attribute->target_id;
                    $types[] = $attribute->model_idSQLType;
                }
                continue;
            }

            //The created_at and updated_at attributes are set to the current date
            //TODO: either add a test to keep the value if it is not empty or remove the possibility inside checkForm 
            if ($attribute->name === $this->timestampCreatedAtName || $attribute->name === $this->timestampUpdatedAtName) {
                $this->set($attribute->name, $creation_date);
            }

            $attributes[] = $attribute->target_column;
            $params[] = "?";
            $values[] = $attribute->getAttributeValue(false);
            $types[] = $attribute->getSQLQueryType();
        }

        $attributes = implode(", ", $attributes);
        $params = implode(", ", $params);
        $types = implode("", $types);

        $request = Database::execute("INSERT INTO $this->table ($attributes) VALUES ($params)", $types, ...$values);

        $this->id = $request;
        $this->setIdInProperties();
        $this->is_instantiated = true;
        return $this;
    }

    public function update(null|array $attributes_array = null)
    {
        if ($attributes_array != null) {
            $this->checkForm($attributes_array);
        }

        if (!$this->is_valid) {
            return null;
        }

        $update_date = new DateTime("now", new DateTimeZone($_ENV['TZ']));

        $params = [];
        $values = [];
        $types = [];

        foreach ($this->attributes as $attribute) {
            //Attributes are not saved in four cases
            // - When they are (numerical) IDs, note that UUID still needs to be set before or provided
            // - If they correspond to the created_at timestamp
            // - When they are attributes stored in another table
            // - When they are inversed relations
            if ($attribute->type === AttributeType::ID || $attribute->name == $this->timestampCreatedAtName) {
                continue;
            }

            if($attribute->is_link) {
                if(!$attribute->is_inversed && !$attribute->is_link_through && $attribute->n_links === 'one') {
                    $params[] = "$attribute->model_column = ?";
                    $values[] = $attribute->target_id;
                    $types[] = $attribute->model_idSQLType;
                }
                continue;
            }

            //The updated_at attribute is set to the current date
            //TODO: either add a test to keep the value if it is not empty or remove the possibility inside checkForm 
            if ($attribute->name === $this->timestampUpdatedAtName) {
                $this->set($attribute->name, $update_date);
            }

            $params[] = "$attribute->target_column = ?";
            $values[] = $attribute->getAttributeValue(false);
            $types[] = $attribute->getSQLQueryType();
        }

        //To account for the where clause of the update query
        $values[] = $this->id;
        $types[] = $this->attributes[$this->idKey]->getSQLQueryType();

        $params = implode(", ", $params);
        $types = implode("", $types);

        Database::execute("UPDATE $this->table SET $params WHERE $this->idKey = ? ", $types, ...$values);

        return $this;
    }

    /**
     * Loads a model instance from the database based on the given ID.
     *
     * @param int $id The ID of the model to load.
     * @return this The loaded model instance or nothing.
     */
    public static function load($id, bool $loadRelations = false): Model|null
    {
        if($id === null) {
            throw new Exception('The ID of the model to load must be provided');
        }

        $model = new static();

        $result = Query::from($model->table)->where($model->idKey, $id)->first();
        
        if (count($result) == 0) {
            $model->id = null;
            $model->is_instantiated = false;
            return null;
        }
        
        return $model->loadFromArray($result, $loadRelations);
    }

    /**
     * Loads the model attributes from an array.
     * Used to load the model attributes from a database query result or an array of attributes to avoid running unnecessary queries.
     * As such, it is also used by the normal load method itself.
     *
     * @param array $attributes_array The array containing the attributes.
     * @return void
     */
    public function loadFromArray(array $attributes_array, bool $loadRelations = false)
    {
        if (!isset($attributes_array[$this->idKey])) {
            throw new Exception("The array does not contain the model's ID attribute");
        }
        $this->id = $attributes_array[$this->idKey];

        foreach ($this->attributes as $attribute) {
            if(!$attribute->is_link) {
                $this->set($attribute->name, $attributes_array[$attribute->target_column]);
            } elseif ($loadRelations) {
                #For inversed links the id of the linked model is stored in the same table as the model so we already have the info loaded
                if ($attribute->is_link && $attribute->is_inversed) {
                    $attribute->getAttributeValue(true, true, $this->id);
                } else {
                    $attribute->getAttributeValue(true, true, $this->id);
                }
            }
        }

        $this->is_instantiated = true;
        return $this;
    }

    public function checkForm(Array $attributes_array): null|self
    {
        $errors_count = 0;

        foreach ($this->attributes as $attribute) {
            if($attribute->type === AttributeType::ID || $attribute->name == $this->timestampCreatedAtName || $attribute->name == $this->timestampUpdatedAtName || $attribute->name == $this->timestampDeletedAtName) {
                continue;
            }
            
            $value = null;
            if (isset($attributes_array[$attribute->name])) {
                $value = $attributes_array[$attribute->name];
            } else if (isset($attributes_array[$attribute->target_column])) {
                $value = $attributes_array[$attribute->target_column];
            }

            $attribute->setAttributeValue($value, false);
            $this->messages['attributes'][$attribute->name] = $attribute->messages;
            
            $this->form_data[$attribute->name] = ['value' => $value, 'messages' => $attribute->messages];
            
            foreach ($attribute->messages as $message) {
                if ($message[0] == 'error') {
                    $this->is_valid = false;
                    $errors_count++;
                }
            }
        }

        if ($errors_count > 0) {
            return null;
        }

        return $this;
    }

    public function delete(bool $force_delete = false): bool
    {
        if($this->is_soft_delete && !$force_delete) {
            $sql_query = "UPDATE $this->table SET deleted_at = ? WHERE $this->idKey = ?";
            Database::execute($sql_query, 'si', date('Y-m-d H:i:s'), $this->id);

            return true;
        }

        $sql_query = "DELETE FROM $this->table WHERE $this->idKey = ?";
        Database::execute($sql_query, 'i', $this->id);
        return true;

        ///TODO: implement a delete function that deletes the row in the table and its relations
    }

    ###############################
    # Attributes methods
    ###############################
    /**
     * Retrieves the value of a specific attribute.
     *
     * @param string $attribute The name of the attribute to retrieve.
     * @param bool $fetch_if_null (optional) Whether to fetch the attribute value in database if it is null. Default is false.
     * @param bool $as_object (optional) Whether to return the attribute value as an object. Default is false.
     * @return mixed|null The value of the attribute if it exists, null otherwise.
     */
    public function get(String $attribute, bool $fetch_if_null = false, bool $as_object = false)
    {
        if ($this->attributeExists($attribute)) {
            return $this->attributes[$attribute]->getAttributeValue($as_object, $fetch_if_null);
        }
    }

    /**
     * Sets the value of the specified attribute, this automaticaly updates the database.
     *
     * @param string $attribute_name The name of the attribute.
     * @param mixed $value The value to set for the attribute.
     * @return self|null 
     */
    public function set(String $attribute_name, $value, bool $update_in_db = false): self|null
    {
        return $this->setAttributeValue($attribute_name, $value, $update_in_db);
    }

    public function link($linked_object, bool $update_in_db = true): self|null
    {
        $object_class_name = get_class($linked_object);
        if(!isset($this->objects_to_links_dict[$object_class_name])) {
            throw new Exception("The link to the target model has not been defined");
        }

        $attribute_name = $this->objects_to_links_dict[$object_class_name];

        return $this->setAttributeValue($attribute_name, $linked_object, $update_in_db);
    }

    public function removeLink($linked_object, bool $update_in_db = true): self|null
    {
        $object_class_name = get_class($linked_object);
        if(!isset($this->objects_to_links_dict[$object_class_name])) {
            throw new Exception("The link to the target model has not been defined");
        }

        $attribute = $this->attributes[$this->objects_to_links_dict[$object_class_name]];
        $attribute->removeLink($linked_object, $update_in_db);

        return $this;
    }

    public function removeAllLinks($attribute_name, bool $update_in_db = true): self|null
    {
        return $this->setAttributeValue($attribute_name, null, $update_in_db);
    }

    /**
     * Instantiates (= sets a value without saving it to the db) a model attribute with the given value and optional ID.
     *
     * @param string $attribute The name of the attribute to instantiate.
     * @param mixed $value The value to assign to the attribute.
     * @param int|null $id The optional ID of the ressource.
     * @return void
     */
    public function updateAttribute($attribute_name, $value)
    {
        return $this->setAttributeValue($attribute_name, $value, true);
    }

    protected function setAttributeValue(String $attribute_name, $value, bool $update_in_db = false): self|null {
        if($this->attributeExists($attribute_name)) {
            $errors_count = 0;
            
            $attribute = $this->attributes[$attribute_name];
            $attribute->setAttributeValue($value, $update_in_db, $this->id);
            
            if ($attribute->messages !== []) {
                $this->messages['attributes'][$attribute->name] = $attribute->messages;

                foreach ($attribute->messages as $message) {
                    if ($message[0] == 'error') {
                        $this->is_valid = false;
                        $errors_count++;
                    }
                }

                if ($errors_count > 0) {
                    return null;
                }
            }

            return $this;
        }

        return null;
    }

    /**
     * Sets the ID in each attribute's properties.
     *
     * This method iterates over the attributes of the current object and sets
     * the ID of each attribute to the ID of the current object.
     *
     * @return void
     */
    public function setIdInProperties()
    {
        foreach ($this->attributes as $attribute) {
            $attribute->setId($this->id);
        }
    }

    /**
     * Fetches the specified attribute from the database.
     *
     * @param string $attribute The name of the attribute to fetch.
     * @return void
     */
    public function fetch($attribute)
    {
        if ($this->attributeExists($attribute)) {
            $this->attributes[$attribute]->fetchAttribute();
        }
    }

    /**
     * Checks if an attribute exists.
     *
     * @param string $name The name of the attribute.
     * @return bool Returns true if the attribute exists, false otherwise.
     */
    protected function attributeExists($name)
    {
        return isset($this->attributes[$name]);
    }

    /**
     * Retrieve all the model's attributes.
     *
     * @return array The attributes of the model.
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /**
     * Unsets the specified attribute from the model.
     *
     * @param string $name The name of the attribute to unset.
     * @return void
     */
    protected function unsetAttribute($name)
    {
        unset($this->attributes[$name]);
    }

    ###############################
    # Getters and utility methods
    ###############################
    /**
     * Retrieves the name of the model.
     *
     * @return string The name of the model.
     */
    public static function getName()
    {
        return static::$name;
    }

    /**
     * Retrieves the model's bundle name
     *
     * @return string The name of the bundle.
     */
    public static function getBundle()
    {
        return static::$bundle;
    }
    
    /**
     * Retrieve the ID of the current instance.
     *
     * @return mixed The ID of the current instance.
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get the ID key of the model.
     *
     * @return mixed The ID key of the model.
     */
    public function getIdKey()
    {
        return $this->idKey;
    }

    /**
     * Get if the model was validated or not.
     *
     * @return bool Returns true if the model is valid, false otherwise.
     */
    public function isValid()
    {
        return $this->is_valid;
    }

    /**
     * Get the model table name.
     *
     * @return string The model table name.
     */
    public function getModelTable()
    {
        return $this->table;
    }

    /**
     * Retrieves the form data of the model.
     *
     * @return array The form data of the model.
     */
    public function getFormData()
    {
        return $this->form_data;
    }

    /**
     * Get the viewing privacy of the model.
     *
     * @return Privacy The viewing privacy of the model.
     */
    public function getViewingPrivacy(): Privacy
    {
        return $this->canView;
    }

    /**
     * Retrieves the editing privacy status of the model.
     *
     * @return Privacy The editing privacy status.
     */
    public function getEditingPrivacy(): Privacy
    {
        return $this->canEdit;
    }

    /**
     * Checks the user rights for a given permission type ('view' or 'edit' only) and user ID.
     *
     * @param string $permission_type The permission type to check (either "view" or "edit").
     * @param mixed $user_id The ID of the user to check against.
     * @return bool Returns true if the user has the specified permission, false otherwise.
     * @throws Exception Throws an exception if an invalid permission type is provided.
     */
    public function checkUserRights(String $permission_type, $user_id)
    {
        if ($this->userKey === null) {
            return true;
        }
        $ressource_user = $this->get($this->userKey);

        if ($permission_type === 'view') {
            if ($this->canView === Privacy::PUBLIC) {
                return true;
            } else {
                return $ressource_user === $user_id;
            }
        } else if ($permission_type === 'edit') {
            if ($this->canEdit === Privacy::PUBLIC) {
                return true;
            } else {
                return $ressource_user === $user_id;
            }
        } else {
            throw new Exception('Invalid permission type: the permission type must be either "view" or "edit", not ' . $permission_type);
        }
    }

    public function isModelInstantiated()
    {
        return $this->is_instantiated;
    }

    /**
     * Converts the model object to an array.
     *
     * @return array The array representation of the model object.
     */
    public function toArray()
    {
        $attributes = [];
        foreach ($this->attributes as $attribute => $value) {
            $attributes[$attribute] = $this->get($attribute);
        }
        return $attributes;
    }

    /**
     * Retrieves the model attributes that are stored in the model's table as well as their corresponding information.
     * This filters out attributes that are not stored in db and relations that are stored in other tables 
     * 
     *
     * @return array An array containing information about each model field.
     */
    protected function getModelFieldsInDB()
    {
        $fields = [];
        foreach ($this->attributes as $attribute) {
            if ($attribute->model_table != $this->table) {
                continue;
            }

            if ($attribute->is_link) {
                if($attribute->is_inversed || $attribute->is_link_through) {
                    continue;
                }
            }

            //The id attribute is always required, but is never defined as such in the attribute definition because it would cause conflicts when creating the model (requiring the id attribute to be defined before the ressource is created in the db)
            $required = $attribute->is_required;
            if ($attribute->name === $this->idKey) {
                $required = true;
            }

            $fields[$attribute->name] = [
                'sql_query' => $attribute->getSQLField(),
                'type' => $attribute->getSQLType(),
                'required' => $required,
                'name' => $attribute->target_column
            ];
        }
        return $fields;
    }

    /**
     * Generates the model table name based on the given table name or the default naming convention.
     *
     * @param string|null $table The table name to be used. If null, the default naming convention will be applied.
     * @return string The generated model table name.
     */
    private function makeModelTableName()
    {
        $table = isset($this->table) ? $this->table : null;

        if ($table === null) {
            if (static::$bundle === 'default') {
                $table = static::$name . 's';
            } else {
                $table = static::$bundle . '_' . static::$name . 's';
            }
        }

        $this->table = $table;
        return $table;
    }

    ###############################
    # Model query methods
    ###############################
    /**
     * Retrieve all records from the model.
     *
     * @return array The array of records.
     */
    public static function all()
    {
        $childModel = new static();

        $query = ModelQuery::fromModel($childModel::class, $childModel->table, $childModel->idKey, $childModel->idType, $childModel->idAutoIncrement);
        return $query->fetch();
    }

    /**
     * Retrieves a list of user resources.
     *
     * @param int $user_id The ID of the user.
     * @param bool $as_object Whether to return the resources as objects or arrays. Default is true.
     * @return mixed The list of user resources. If $as_object is true, an array of objects is returned. Otherwise, an array of arrays is returned.
     */
    public static function listUserRessources($user_id, bool $as_object = true)
    {
        $model = new static();
        $query = ModelQuery::fromModel($model::class, $model->table, $model->idKey, $model->idAutoIncrement, $model->idType);
        $query->where($model->userKey, $user_id);
        if ($as_object) {
            return $query->fetch();
        }
        return $query->fetchAsArray();
    }

    /**
     * Counts the number of resources associated with a user.
     *
     * @param int $user_id The ID of the user.
     * @return int|null The number of resources associated with the user, or null if an error occurred.
     */
    public static function countUserRessources($user_id): int|null
    {
        $model = new static();
        $count = ModelQuery::fromModel($model::class, $model->table, $model->idKey, $model->idAutoIncrement, $model->idType)->where($model->userKey, $user_id)->count();
        return $count;
    }


    ###############################
    # Factory methods
    ###############################

    /**
     * Generates a table for the model.
     *
     * @param bool $regenerate_structure_file Whether to regenerate the structure file.
     * @param bool $update_table_if_exist Whether to update the table if it already exists.
     * @param bool $drop_tables_if_exist Whether to drop tables if they already exist.
     * @param bool $force_table_update Whether to force the table update.
     * @return bool Returns true if the table is generated successfully, false otherwise.
     */
    public static function generateTableForModel(bool $regenerate_structure_file = true, bool $update_table_if_exist = false, bool $drop_tables_if_exist = false, $force_table_update = false): bool
    {
        $db = new Database();
        $model = new static();
        $table = $model->getModelTable();

        $result = $db->conn->query("SHOW TABLES LIKE '$table'");
        $tableExists = $result->num_rows > 0;
        if ($tableExists && !$update_table_if_exist) {
            return false;
        }

        if ($tableExists) {
            $differences = self::checkModelDBAdequation();
            if ($differences['success']) {
                return true;
            }

            if (!$differences['success'] && !$drop_tables_if_exist) {
                return self::updateTableFromModel($force_table_update, $regenerate_structure_file);
            }

            $db->conn->query("DROP TABLE `$table`");
        }

        $model_fields = $model->getModelFieldsInDB();
        $model_id = $model->getIdKey();

        $sql = "CREATE TABLE `$table` (";
        //Putting the id key first
        $sql .= $model_fields[$model_id]['sql_query'] . ', ';

        foreach ($model_fields as $key => $field) {
            if ($key == $model_id) {
                continue;
            }
            $sql .= $field['sql_query'] . ', ';
        }
        $sql = substr($sql, 0, -2);
        $sql .= ")";

        $db->conn->query($sql);

        foreach ($model->attributes as $attribute) {
            if ($attribute->is_link_through && !$attribute->is_inversed) {
                $result = $db->conn->query("SHOW TABLES LIKE '$attribute->link_through_table'");
                if ($result->num_rows > 0) {
                    $db->conn->query("DROP TABLE `$attribute->link_through_table`");
                }

                $sql = "CREATE TABLE `$attribute->link_through_table` (";
                //Putting the id key first
                if ($attribute->model_idType === AttributeType::ID) {
                    $sql .= $attribute->link_through_model_column . ' INT NOT NULL, ';
                } else {
                    $sql .= $attribute->link_through_model_column . ' CHAR(36) NOT NULL, ';
                }

                if ($attribute->target_idType === AttributeType::ID) {
                    $sql .= $attribute->link_through_target_column . ' INT NOT NULL, ';
                } else {
                    $sql .= $attribute->link_through_target_column . ' CHAR(36) NOT NULL, ';
                }

                if ($attribute->link_through_model_type !== null) {
                    $sql .= $attribute->link_through_model_type . ' CHAR(255) NOT NULL, ';
                }

                if ($attribute->link_through_target_type !== null) {
                    $sql .= $attribute->link_through_target_type . ' CHAR(255) NOT NULL, ';
                }
                $sql = substr($sql, 0, -2);
                $sql .= ")";

                $db->conn->query($sql);
            }
        }

        if ($regenerate_structure_file) {
            $db->exportDatabase();
        }

        return true;
    }

    /**
     * Checks the adequacy of the model with the database.
     *
     * @return array An array containing the result of the adequacy check:
     *               - 'success': Whether the model is adequate with the database (boolean)
     *               - 'table_exists': Whether the table exists in the database (boolean)
     *               - 'missing_columns_in_db': An array of column names that are missing in the database
     *               - 'extra_columns_in_db': An array of column names that are extra in the database
     *               - 'types_mismatch': An array of column names with type mismatches between the model and the database
     *               - 'required_mismatch': An array of column names with required attribute mismatches between the model and the database
     */
    public static function checkModelDBAdequation(): array
    {
        $db = new Database();

        $model = new static();
        $model_attributes = $model->getModelFieldsInDB();
        $columns_in_db = [];

        $missing_attributes = [];
        $types_mismatch = [];
        $required_mismatch = [];

        $table = $model->getModelTable();
        $result = $db->conn->query("SHOW TABLES LIKE '$table'");
        $tableExists = $result->num_rows > 0;
        if (!$tableExists) {
            return [
                'success' => false,
                'table_exists' => false
            ];
        }


        $columns = $db->conn->query("SHOW COLUMNS FROM `$table`");
        $columns = $columns->fetch_all();

        foreach ($columns as $column) {
            $column_name = $column[0];
            $column_type = $column[1];
            $column_required = $column[2] === 'NO' ? true : false;

            $columns_in_db[] = $column_name;

            if (!isset($model_attributes[$column_name])) {
                $missing_attributes[] = $column_name;
            } else {
                $model_field = $model_attributes[$column_name];
                $model_field_type = $model_field['type'];
                $model_field_required = $model_field['required'];

                if (strtolower($model_field_type) !== strtolower($column_type)) {
                    $types_mismatch[] = $column_name . ' (' . $model_field_type . ' vs ' . $column_type . ')';
                }

                if ($model_field_required !== $column_required) {
                    $required_mismatch[] = $column_name;
                }
            }
        }

        $missing_columns = array_diff(array_keys($model_attributes), $columns_in_db);
        $success = empty($missing_attributes) && empty($types_mismatch) && empty($required_mismatch) && empty($missing_columns);

        $result = [
            // 'model_fields' => array_keys($model_attributes),
            // 'table_columns' => $columns_in_db,
            'success' => $success,
            'table_exists' => true,
            'missing_columns_in_db' => $missing_columns,
            'extra_columns_in_db' => $missing_attributes,
            'types_mismatch' => $types_mismatch,
            'required_mismatch' => $required_mismatch
        ];

        return $result;
    }

    ###TODO: make this work...maybe
    public static function updateTableFromModel(bool $force_table_update = false, bool $regenerate_structure_file = true): bool
    {
        $db = new Database();
        $model = new static();
        $table = $model->getModelTable();
        $differences = self::checkModelDBAdequation();

        //Adding missing columns and removing extra columns
        $sql = "ALTER TABLE `$table` ";
        foreach ($differences['missing_columns_in_db'] as $column) {
            $sql .= "ADD COLUMN " . $model->getModelFieldsInDB()[$column]['sql_query'] . ', ';
        }
        foreach ($differences['extra_columns_in_db'] as $column) {
            $sql .= "DROP COLUMN `$column`, ";
        }
        $sql = rtrim($sql, ', ');
        $db->conn->query($sql);

        //Fixing type and required mismatches is more touchy and requires to drop and recreate the column when the type is not compatible
        foreach ($differences['types_mismatch'] as $column) {
            $success = false;
            try {
                $db->conn->query("ALTER TABLE `$table` MODIFY COLUMN " . $model->getModelFieldsInDB()[$column]['sql_query']);
                $success = true;
            } catch (Exception $e) {
                $success = false;
            }

            if (!$success && $force_table_update) {
                $db->conn->query("ALTER TABLE `$table` DROP COLUMN `$column`, ADD COLUMN " . $model->getModelFieldsInDB()[$column]['sql_query']);
            }
        }

        foreach ($differences['required_mismatch'] as $column) {
            $success = false;
            try {
                $db->conn->query("ALTER TABLE `$table` MODIFY COLUMN " . $model->getModelFieldsInDB()[$column]['sql_query']);
                $success = true;
            } catch (Exception $e) {
                $success = false;
            }

            if (!$success && $force_table_update) {
                $db->conn->query("ALTER TABLE `$table` DROP COLUMN `$column`, ADD COLUMN " . $model->getModelFieldsInDB()[$column]['sql_query']);
            }
        }


        if ($regenerate_structure_file) {
            $db->exportDatabase();
        }

        return true;
    }
}
