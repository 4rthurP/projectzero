<?php

namespace pz;

use Exception;
use DateTime;
use DateTimeZone;

use pz\Config;
use pz\Log;
use pz\{
    ModelAttribute,
    ModelAttributeLink, 
    ModelAttributeLinkThrough
};
use pz\database\Database;
use pz\database\Query;
use pz\Enums\Routing\Privacy;
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
        $attribute = new ModelAttribute($name, $type, get_class($this), static::$bundle, $required, $default_value, $this->table, $this->idKey, $column);

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
        $attribute = new ModelAttribute($name, $idType, get_class($this), static::$bundle, false, null, $this->table, $name, $column);

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

        $attribute = new ModelAttribute($userKey, AttributeType::INT, get_class($this), static::$bundle, true, null, $this->table, $this->idKey, $userKey);

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
    protected function timestamps(bool $hasTimestamps = true, ?String $timestampCreatedAtName = 'created_at', ?String $timestampUpdatedAtName = 'updated_at', String $timestampDeletedAtName = 'deleted_at'): self
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
                $this->attribute($timestampCreatedAtName, AttributeType::DATETIME, true, null,  $timestampCreatedAtName);
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
                $this->attribute($timestampDeletedAtName, AttributeType::DATETIME, false, null,  $timestampDeletedAtName);
            }
            $this->timestampDeletedAtName = $timestampDeletedAtName;
            $this->is_soft_delete = true;
        } else {
            if($this->attributeExists($timestampDeletedAtName)) {
                $this->unsetAttribute($timestampDeletedAtName);
            }
            $this->timestampDeletedAtName = null;
            $this->is_soft_delete = false;
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
    protected function linkTo($target, ?String $name = null, bool $is_required = false, ?String $target_column = null): ModelAttributeLink|null
    {
        if(!$this->load_relations) {
            return null; 
        }
        if (!$this->is_initialized) {
            $this->initialize();
        }

        if (!is_subclass_of($target, Model::class)) {
            throw new Exception("The target model must be a subclass of Model");
        }

        if(isset($this->objects_to_links_dict[$target])) {
            throw new Exception("The link to the target model has already been defined");
        }

        $default_value = null;
        $name = $name ?? $target::getName();

        $attribute = new ModelAttributeLink($name, get_class($this), static::$bundle, $is_required, false, $default_value, $this->table, $this->idKey, $target_column, $target);

        $this->attributes[$name] = $attribute;
        $this->objects_to_links_dict[$target] = $name;

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
    protected function linkedTo($target, ?String $name = null, ?String $target_column = null): ModelAttributeLink|null
    {
        if(!$this->load_relations) {
            return null; 
        }
        if (!$this->is_initialized) {
            $this->initialize();
        }

        if (!is_subclass_of($target, Model::class)) {
            throw new Exception("The target model must be a subclass of Model");
        }

        if(isset($this->objects_to_links_dict[$target])) {
            throw new Exception("The link to the target model has already been defined");
        }


        $name = $name ?? $target::getName();
        $default_value = null;

        $$attribute = new ModelAttributeLink($name, get_class($this), static::$bundle, false, false, $default_value, $this->table, $this->idKey, $target_column, $target);

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
    protected function linkThrough($target, ?String $name = null, bool $is_many = true, ?String $target_column = null, ?String $relation_table = null, ?String $relation_model_column = null, ?String $relation_model_type = null, bool $is_inversed = false): ModelAttributeLinkThrough|null
    {
        if(!$this->load_relations) {
            return null; 
        }
        if (!$this->is_initialized) {
            $this->initialize();
        }

        if (!is_subclass_of($target, Model::class)) {
            throw new Exception("The target model must be a subclass of Model");
        }

        if(isset($this->objects_to_links_dict[$target])) {
            throw new Exception("The link to the target model has already been defined");
        }

        $name = $name ?? $target;
        $default_value = null;
     
        $attribute = new ModelAttributeLinkThrough($name, get_class($this), static::$bundle, $is_inversed, $is_many, $default_value, $this->table, $this->idKey, $target_column, $target, null, null, $relation_table, $relation_model_column, $relation_model_type);

        $this->attributes[$name] = $attribute;
        $this->objects_to_links_dict[$target] = $name;

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
     * @return static The created record.
     */
    public function create(null|array $attributes_array = null): null|static
    {
        if ($attributes_array != null) {
            $this->checkForm($attributes_array);
        }

        if (!$this->is_valid) {
            return $this;
        }

        $creation_date = new DateTime("now", Config::tz());
        $link_attributes = [];

        $attributes = [];
        $params = [];
        $values = [];
        $types = [];

        foreach ($this->attributes as $key => $attribute) {
            //Attributes are not saved in three cases
            // - When they are (numerical) IDs, note that UUID still needs to be set before or provided
            // - When they are attributes stored in another table (done later)
            // - When they are inversed relations (done later)
            if ($attribute->type === AttributeType::ID) {
                continue;
            }

            if($attribute->is_link) {
                if(!$attribute->is_inversed && !$attribute->is_link_through) {
                    $attributes[] = $attribute->target_column;
                    $params[] = "?";
                    $values[] = $attribute->getTargetId();
                    $types[] = $attribute->getSQLQueryType();
                }  
                continue;
            }

            //The created_at and updated_at attributes are set to the current date
            //TODO: either add a test to keep the value if it is not empty or remove the possibility inside checkForm 
            if ($attribute->name === $this->timestampCreatedAtName || $attribute->name === $this->timestampUpdatedAtName) {
                $attribute->create($creation_date);
            }

            $attributes[] = $attribute->target_column;
            $params[] = "?";
            $values[] = $attribute->getSQLValue();
            $types[] = $attribute->getSQLQueryType();
        }

        $attributes = implode(", ", $attributes);
        $params = implode(", ", $params);
        $types = implode("", $types);

        $request = Database::execute("INSERT INTO $this->table ($attributes) VALUES ($params)", $types, ...$values);

        $this->id = $request;
        $this->setIdInProperties();
        $this->is_instantiated = true;

        foreach($link_attributes as $link_attribute) {
            $link_attribute->setId($this->id);
        }

        return $this;
    }

    public function update(null|array $attributes_array = null): static
    {
        if ($attributes_array != null) {
            $this->checkForm($attributes_array, true);
        }

        if (!$this->is_valid) {
            return $this;
        }

        $update_date = new DateTime("now", Config::tz());

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
                // For link attributes we only save links that are not linked through and inversed (the information is stored in another table)
                if($attribute->is_inversed || $attribute->is_link_through) {
                    continue;
                }

                // When updating we not always have loaded the target, if the id is not present in the attributes array we assume we did not want to change it
                $target_id = $attribute->getTargetId();
                if($target_id === null) {
                    if(!isset($attributes_array[$attribute->target_column])) {
                        continue;
                    }
                    
                    $target_id = $attributes_array[$attribute->target_column];
                }

                // Setting the SQL query for the link attribute
                $params[] = "$attribute->target_column = ?";
                $values[] = $target_id;
                $types[] = $attribute->getSQLQueryType();
                continue;
            }

            //The updated_at attribute is set to the current date
            if ($attribute->name === $this->timestampUpdatedAtName) {
                $attribute->update($update_date);
            }

            $params[] = "$attribute->target_column = ?";
            $values[] = $attribute->getSQLValue();
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
     * Loads the model attributes from an array.
     * Used to load the model attributes from a database query result or an array of attributes to avoid running unnecessary queries.
     * As such, it is also used by the normal load method itself.
     *
     * @param array $attributes_array The array containing the attributes.
     * @return void
     */
    public function loadFromArray(array $attributes_array, bool $loadRelations = false): static
    {
        if (!isset($attributes_array[$this->idKey])) {
            throw new Exception("The array does not contain the model's ID attribute");
        }
        $this->id = $attributes_array[$this->idKey];

        foreach ($this->attributes as $attribute) {
            if(!$attribute->is_link) {
                $this->set($attribute->name, $attributes_array[$attribute->name]);
            } elseif ($loadRelations) {
                $attribute->load($this->id);
            } 
        }

        $this->is_instantiated = true;
        return $this;
    }

    public function checkForm(Array $attributes_array, bool $is_update = false): null|static
    {
        $attributes_array = $this->checkFormCustomPre($attributes_array);
        $errors_count = 0;

        foreach ($this->attributes as $attribute) {
            # Do not process this attributes that are handled by the class and should not be updated
            if(
                $attribute->is_link_through 
                || $attribute->is_inversed 
                || $attribute->type === AttributeType::ID 
                || $attribute->name == $this->timestampCreatedAtName 
                || $attribute->name == $this->timestampUpdatedAtName 
                || $attribute->name == $this->timestampDeletedAtName
            ) {
                continue;
            }
            
            $value = null;
            # Retrieve the value from the attributes array
            if (isset($attributes_array[$attribute->name])) {
                $value = $attributes_array[$attribute->name];
            } 
            # Link attributes are provided in the attributes array with the target column name
            else if (isset($attributes_array[$attribute->target_column])) {
                $value = $attributes_array[$attribute->target_column];

                //If we try to update a link attribute with an id, we need to fetch the object
                if($attribute->is_link) {
                    $value = Query::from($attribute->target_table)->where($attribute->target_id_key, $value)->first();
                }
            } else if ($is_update) {
                // If no value is provided in the attributes array, we are not updating it
                // Loading it should happen in the load method if it is needed
                continue;
            }

            # Applies custom processing implemented by the model subclasses
            $value = $this->checkFormCustomLoop($value);
            
            # The attribute model handles checking the value and parsing it
            if($is_update) {
                $attribute->update($value, $this->id); # On update the attribute will retrieve and keep the old value if the is required and the new value is empty
            } else {
                $attribute->create($value);
            }
            
            #Applies custom processing implemented by the model subclasses
            $attribute = $this->checkFormCustomPost($attribute);
            
            # Checks on the messages send back by the attribute that there are no errors
            foreach ($attribute->messages as $message) {
                if ($message[0] == 'error') {
                    $this->is_valid = false;
                    $errors_count++;
                }
            }
            $this->messages[$attribute->name] = $attribute->messages;

            # Adds the parsed value to the original form data
            $this->form_data[$attribute->name] = $value;
        }

        # If the form has at least one non valid component, we do not return the model
        if ($errors_count > 0) {
            return null;
        }

        return $this;
    }
    
    /**
     * Aimed to be overriden by the child class to perform custom processing on the form attributes
     * before the loop that processes each attribute.
     *
     * @param array $attributes_array The array of form attributes.
     * @return array The processed array of form attributes.
     */
    protected function checkFormCustomPre(Array $attributes_array): Array {
        return $attributes_array;
    }
    /**
     * Aimed to be overriden by the child class to perform custom processing on each individual attribute
     * before the default processing.
     *
     * @param $value The value passed in the attributes_array for the attribute.
     * @return $value The processed value.
     */
    protected function checkFormCustomLoop($value) {
        return $value;
    }
    /**
     * Aimed to be overriden by the child class to perform custom processing on each individual attribute
     * after the default processing.
     *
     * @param AbstractModelAttribute $attribute The form attribute to process.
     * @return AbstractModelAttribute The processed form attribute.
     */
    protected function checkFormCustomPost(AbstractModelAttribute $attribute): AbstractModelAttribute {
        return $attribute;
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
    public function get(String $attribute_name, bool $as_object = false)
    {
        if ($this->attributeExists($attribute_name)) {
            return $this->attributes[$attribute_name]->get($as_object);
        }
    }

    /**
     * Sets the value of the specified attribute, this automaticaly updates the database.
     *
     * @param string $attribute_name The name of the attribute.
     * @param mixed $value The value to set for the attribute.
     * @return self|null 
     */
    public function set(String $attribute_name, $value, bool $update_in_db = false): static
    {
        if ($this->attributeExists($attribute_name)) {
            $this->attributes[$attribute_name]->update($value, $this->id, $update_in_db);
        }
        return $this;
    }

    public function link($linked_object, bool $update_in_db = true): static
    {
        $object_class_name = get_class($linked_object);
        if(!isset($this->objects_to_links_dict[$object_class_name])) {
            throw new Exception("The link to the target model has not been defined");
        }

        $attribute = $this->attributes[$this->objects_to_links_dict[$object_class_name]];
        $attribute->add($linked_object, $update_in_db);

        $errors_count = 0;
        $this->messages[$attribute->name] = $attribute->messages;
        foreach ($attribute->messages as $message) {
            if ($message[0] == 'error') {
                $this->is_valid = false;
                $errors_count++;
            }
        }
        if($errors_count > 0) {
            $this->is_valid = false;
        }

        return $this;
    }

    public function removeLink($linked_object, bool $update_in_db = true): static
    {
        $object_class_name = get_class($linked_object);
        if(!isset($this->objects_to_links_dict[$object_class_name])) {
            throw new Exception("The link to the target model has not been defined");
        }

        $attribute = $this->attributes[$this->objects_to_links_dict[$object_class_name]];
        $attribute->unset($linked_object, $update_in_db);

        $errors_count = 0;
        $this->messages[$attribute->name] = $attribute->messages;
        foreach ($attribute->messages as $message) {
            if ($message[0] == 'error') {
                $this->is_valid = false;
                $errors_count++;
            }
        }
        if($errors_count > 0) {
            $this->is_valid = false;
        }

        return $this;
    }

    #TODO: add loadRelations method

    /**
     * Sets the ID in each attribute's properties.
     *
     * This method iterates over the attributes of the current object and sets
     * the ID of each attribute to the ID of the current object.
     *
     * @return void
     */
    public function setIdInProperties(): static
    {
        foreach ($this->attributes as $attribute) {
            $attribute->setId($this->id);
        }
        return $this;
    }

    /**
     * Fetches the specified attribute from the database.
     *
     * @param string $attribute The name of the attribute to fetch.
     * @return void
     */
    public function fetch($attribute): static
    {
        if ($this->attributeExists($attribute)) {
            $this->attributes[$attribute]->load($this->id);
        }
        return $this;
    }

    /**
     * Checks if an attribute exists.
     *
     * @param string $name The name of the attribute.
     * @return bool Returns true if the attribute exists, false otherwise.
     */
    protected function attributeExists($name): bool
    {
        return isset($this->attributes[$name]);
    }

    /**
     * Retrieve all the model's attributes.
     *
     * @return array The attributes of the model.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Unsets the specified attribute from the model.
     *
     * @param string $name The name of the attribute to unset.
     * @return static The updated model instance.
     */
    protected function unsetAttribute($name): static
    {
        unset($this->attributes[$name]);
        return $this;
    }

    ###############################
    # Getters
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
     * Get the user key of the model.
     *
     * @return mixed The user key of the model.
     */
    public function getUserKey()
    {
        return $this->userKey;
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
     * Retrieves the form messages of the model.
     *
     * @return array The form messages of the model.
     */
    public function getFormMessages()
    {
        return $this->messages;
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

    ###############################
    # Model querying methods
    ###############################
    public static function query(array $where_args = [], string $mode = 'object', bool $load_relations = false, array $order_by_args = [], ?String $distinct = null, ?int $limit = null, ?int $offset = null): array
    {
        if($mode == 'raw' && $load_relations) {
            throw new Exception('Cannot load relations in raw mode: you either need to use the object or array mode.');
        }

        // Create the query
        $ressource = new static();
        $query = $ressource->startQuery();

        // Adds the where arguments to the query
        foreach($where_args as $key => $where_arg) {
            // Where argument can either be passed as an array containing the signature or a WhereClause object
            if(is_array($where_arg)) {
                $query->where($key, ...$where_arg);
            } else {
                $query->where($key, $where_arg);
            }
        }
        
        foreach($order_by_args as $key => $order) {
            $query->order($key, $order);
        }
        if($distinct !== null) {
            $query->distinct($distinct);
        }
        $results = $query->fetch($limit, $offset);

        // In raw mode simply return the SQL results
        if($mode == 'raw') {
            return $results;
        }

        // Otherwise, load the results into the model
        $ressources = [];
        foreach($results as $result) {
            $res = new static();
            $res->loadFromArray($result, $load_relations);
            if($mode == 'object') {
                $ressources[] = $res;
            } else {
                $ressources[] = $res->toArray();
            }
        }
        return $ressources;
    }

    /**
     * Loads a model instance from the database based on the given ID.
     *
     * @param $id The ID of the model to load.
     * @param bool $loadRelations Whether to load the model's relations or not.
     * @param string $mode The mode in which to return the model ('object', 'array', or 'raw').
     * @return ?static The loaded model instance or nothing.
     */
    public static function find($id, bool $loadRelations = false, string $mode = 'object'): static|array|null
    {
        // Create the query
        $ressource = new static();
        $query = $ressource->startQuery();
        $query->where($ressource->idKey, $id);
        $found = $query->first();

        // Exit early if the query did not return a result
        if($found === null || count($found) == 0) {
            return null;
        }

        // Parse the result according to the $mode argument
        if($mode == 'raw') {
            return $found;
        }
    
        $ressource->loadFromArray($found, $loadRelations);
        if($mode == 'array') {
            return $ressource->toArray();
        } 
        return $ressource;
    }

    /**
     * Finds a model based on the provided attributes, or creates it if it does not exist.
     *
     * @param array $attributes_to_find The attributes to find.
     * @param array $attributes_to_create The attributes to create if the model does not exist.
     * @return static The found or created model.
     */
    public static function findOrCreate(array $attributes_to_find, array $attributes_to_create = [], bool $load_relations_if_found = false): static {
        $ressource = static::query($attributes_to_find, 'object', $load_relations_if_found);

        if($ressource !== []) {
            return $ressource[0];
        }
        
        $object = new static();
        if($object->getViewingPrivacy() == Privacy::PROTECTED) {
            $attributes_to_find[$object->getUserKey()] = $_SESSION['user']['id'];
        }
        $object->create(array_merge($attributes_to_find, $attributes_to_create));
        return $object;
    }

    /**
     * Retrieve a list of records from the model.
     *
     * @return array The array of records.
     */
    public static function list($as_object = false, $limit = null, $offset = null)
    {
        // Create the query
        $ressource = new static();
        $query = $ressource->startQuery();
        $results = $query->fetch($limit, $offset);
        
        // Parse the results according to the $as_object argument
        if($as_object) {
            $ressources = [];
            foreach($results as $result) {
                $ressources[] = $ressource->loadFromArray($result);
            }
            return $ressources;
        }
        return $results;
    }

    /**
     * Counts the number of resources associated with a user.
     *
     * @param int $user_id The ID of the user.
     * @return int|null The number of resources associated with the user, or null if an error occurred.
     */
    public static function count(array $where_args = []): int|null
    {
        $model = new static();
        $query = $model->startQuery();

        // If where arguments were passed, we check each one and add the adequate where clauses
        if($where_args !== []) {
            foreach($where_args as $key => $where_arg) {
                // Where args can be passed either as arrays or WhereClause objects
                if(is_array($where_arg)) {
                    $query->where($key, ...$where_arg);
                } else {
                    $query->where($key, $where_arg);
                }
            }
        }


        return $query->count();
    }

    /**
     * Initializes a secure select query for the model 
     * Checks the user's permissions
     * Handles softDeletion is needed
     * 
     * @return Query The newly initialized query
     */
    protected function startQuery(): Query
    {
        // Initializes a select query in the model's table
        $query = Query::from($this->table);

        // Checks if the model requires rights to access the ressources
        if($this->canView->requiresLogin()) {
            if(!isset($_SESSION['user']['id'])) {
                Log::warning('Tried accessing a private model, '. $this->getName() . ', without being logged in.');
                throw new Exception('Tried accessing a private ressource without being logged in.');
            }
            
            // If the model is private, we only query ressources belonging to the user
            if($this->canView->requiresAuth()) {
                $query->where($this->userKey, $_SESSION['user']['id']);
            }
        }

        // Makes sure we only query ressources that were not soft deleted
        if($this->is_soft_delete) {
            $query->where($this->timestampDeletedAtName, null);
        }

        return $query;
    }


    ###############################
    # Utility classes
    ###############################
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
        foreach ($this->attributes as $attribute) {
            $column = $attribute->target_column;
            if($attribute->is_link && !$attribute->is_inversed) {
                $column = $attribute->name;
            }
            $attributes[$column] = $attribute->get(false);
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
            if ($attribute->is_link_through) {
                $result = $db->conn->query("SHOW TABLES LIKE '$attribute->relation_table'");
                if ($result->num_rows > 0) {
                    $db->conn->query("DROP TABLE `$attribute->relation_table`");
                }                

                $sql = "CREATE TABLE `$attribute->relation_table` (";
                //Putting the id key first
                if ($model->idType === AttributeType::ID) {
                    $sql .= $attribute->relation_model_column . ' INT NOT NULL, ';
                } else {
                    $sql .= $attribute->relation_model_column . ' CHAR(36) NOT NULL, ';
                }

                if ($attribute->target_id_type === AttributeType::ID) {
                    $sql .= $attribute->target_column . ' INT NOT NULL, ';
                } else {
                    $sql .= $attribute->target_column . ' CHAR(36) NOT NULL, ';
                }

                if ($attribute->relation_model_type !== null) {
                    $sql .= $attribute->relation_model_type . ' char(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL, ';
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

        foreach ($model->attributes as $attribute) {
            if($attribute->is_link_through || $attribute->is_inversed) {
                continue;
            }

            $key = $attribute->target_column;
            if($attribute->is_link) {
                $key = $attribute->target_column;
            }

            $column_name = null;

            foreach ($columns as $column) {
                if($column[0] !== $key) {
                    continue;
                }

                $column_name = $column[0];
                $column_type = $column[1];
                $column_required = $column[2] === 'NO' ? true : false;
                $columns_in_db[] = $column_name;

                $model_field_type = $attribute->getSQLType();
                $model_field_required = $attribute->is_required;

                if (strtolower($model_field_type) !== strtolower($column_type)) {
                    $types_mismatch[] = $column_name . ' (' . $model_field_type . ' vs ' . $column_type . ')';
                }

                if ($model_field_required !== $column_required && !$attribute->name === $model->idKey) {
                    $required_mismatch[] = $column_name;
                }
            }

            if ($column_name === null) {
                $model_attributes[] = $key;
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
