<?php

use pz\Model;
use pz\Enums\model\AttributeType;
use pz\Enums\Routing\Privacy;

class TestModelA extends Model
{
    public static $name = 'test_a';
    
    protected function model()
    {
        $this->linkTo(TestModelB::class, 'test_b');
    }
}

class TestModelB extends Model
{
    public static $name = 'test_b';
    
    protected function model()
    {
        $this->linkedTo(TestModelA::class, 'test_a');
    }
}

class TestModelSimple extends Model
{
    public static $name = 'test_simple';
    
    protected function model()
    {
        $this->attribute('name', AttributeType::CHAR, true);
        $this->attribute('email', AttributeType::EMAIL, false);
        $this->attribute('age', AttributeType::INT, false);
        $this->attribute('is_active', AttributeType::BOOL, false, 'true');
        $this->attribute('bio', AttributeType::TEXT, false);
        $this->attribute('tags', AttributeType::LIST, false);
        $this->attribute('birth_date', AttributeType::DATE, false);
        $this->attribute('last_login', AttributeType::DATETIME, false);
        $this->attribute('score', AttributeType::FLOAT, false);
    }
}

class TestModelCustomId extends Model
{
    public static $name = 'test_custom_id';
    
    protected function model()
    {
        $this->id('uuid', false, AttributeType::UUID);
        $this->attribute('name', AttributeType::CHAR, true);
    }
}

class TestModelNoUser extends Model
{
    public static $name = 'test_no_user';
    
    protected function model()
    {
        $this->user(null);
        $this->attribute('public_data', AttributeType::CHAR, false);
    }
}

class TestModelForAttributes extends Model
{
    public static $name = 'test_attributes';
    
    protected function model()
    {
        $this->attribute('char_field', AttributeType::CHAR, true);
        $this->attribute('text_field', AttributeType::TEXT, false);
        $this->attribute('int_field', AttributeType::INT, false);
        $this->attribute('float_field', AttributeType::FLOAT, false);
        $this->attribute('bool_field', AttributeType::BOOL, false);
        $this->attribute('email_field', AttributeType::EMAIL, false);
        $this->attribute('list_field', AttributeType::LIST, false);
        $this->attribute('date_field', AttributeType::DATE, false);
        $this->attribute('datetime_field', AttributeType::DATETIME, false);
        $this->attribute('required_with_default', AttributeType::CHAR, true, 'default_value');
    }
}

class TestModelNoTimestamps extends Model
{
    public static $name = 'test_no_timestamps';
    
    protected function model()
    {
        $this->timestamps(false);
        $this->attribute('data', AttributeType::CHAR, false);
    }
}

class TestModelManyToMany extends Model
{
    public static $name = 'test_many_to_many';
    
    protected function model()
    {
        $this->linkThrough(TestModelTag::class, 'tags', true);
        $this->attribute('name', AttributeType::CHAR, true);
    }
}

class TestModelTag extends Model
{
    public static $name = 'test_tag';
    
    protected function model()
    {
        $this->linkedThrough(TestModelManyToMany::class, 'items', true);
        $this->attribute('name', AttributeType::CHAR, true);
    }
}

class TestModelCustomPrivacy extends Model
{
    public static $name = 'test_custom_privacy';
    
    protected function model()
    {
        $this->user('owner_id', Privacy::PROTECTED, Privacy::PROTECTED);
        $this->attribute('sensitive_data', AttributeType::CHAR, false);
    }
}

class TestModelWithBundle extends Model
{
    public static $name = 'test_bundle_model';
    public static $bundle = 'testing';
    
    protected function model()
    {
        $this->attribute('data', AttributeType::CHAR, false);
    }
}

class TestModelMinimal extends Model
{
    public static $name = 'test_minimal';
    
    protected function model()
    {
        $this->timestamps(false); // Disable timestamps first
        $this->user(null); // No user attribute
        $this->attribute('simple_field', AttributeType::CHAR, false);
    }
}

class TestModelUUID extends Model
{
    public static $name = 'test_uuid';
    
    protected function model()
    {
        $this->id('uuid', false, AttributeType::UUID, 'uuid');
        $this->attribute('name', AttributeType::CHAR, true);
    }
}

class TestModelCustomColumns extends Model
{
    public static $name = 'test_custom_columns';
    
    protected function model()
    {
        $this->attribute('display_name', AttributeType::CHAR, false, null, 'db_display_name');
        $this->attribute('internal_code', AttributeType::CHAR, false, 'DEFAULT_CODE', 'code_column');
    }
}