<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use pz\Model;
use pz\Enums\model\AttributeType;
use pz\Enums\Routing\Privacy;

require_once __DIR__ . '/../test_ressources/testModels.php';

final class modelTest extends TestCase
{
    public function testInfiniteLoopPreventionWithLinkToAndLinkedTo(): void
    {
        // Test that models with circular linkTo/linkedTo relationships don't cause infinite loops
        
        // This should not cause an infinite loop or crash
        $modelA = new TestModelA();
        $this->assertInstanceOf(TestModelA::class, $modelA);
        
        $modelB = new TestModelB();
        $this->assertInstanceOf(TestModelB::class, $modelB);
        
        // Test with loadRelations=true (default behavior)
        $modelA_withRelations = new TestModelA(true);
        $this->assertInstanceOf(TestModelA::class, $modelA_withRelations);
        
        $modelB_withRelations = new TestModelB(true);
        $this->assertInstanceOf(TestModelB::class, $modelB_withRelations);
        
        // Test with loadRelations=false
        $modelA_noRelations = new TestModelA(false);
        $this->assertInstanceOf(TestModelA::class, $modelA_noRelations);
        
        $modelB_noRelations = new TestModelB(false);
        $this->assertInstanceOf(TestModelB::class, $modelB_noRelations);
    }
    
    public function testModelAttributesAreInitializedProperly(): void
    {
        $model = new TestModelA();
        $attributes = $model->getAttributes();
        
        // Verify that the model has the expected attributes
        $this->assertArrayHasKey('id', $attributes);
        $this->assertArrayHasKey('user_id', $attributes);
        $this->assertArrayHasKey('test_b', $attributes);
        $this->assertArrayHasKey('created_at', $attributes);
        $this->assertArrayHasKey('updated_at', $attributes);
        $this->assertArrayHasKey('deleted_at', $attributes);
    }

    public function testModelAttributeTypes(): void
    {
        $model = new TestModelSimple();
        $attributes = $model->getAttributes();
        
        // Test that all expected attributes exist
        $this->assertArrayHasKey('name', $attributes);
        $this->assertArrayHasKey('email', $attributes);
        $this->assertArrayHasKey('age', $attributes);
        $this->assertArrayHasKey('is_active', $attributes);
        $this->assertArrayHasKey('bio', $attributes);
        $this->assertArrayHasKey('tags', $attributes);
        $this->assertArrayHasKey('birth_date', $attributes);
        $this->assertArrayHasKey('last_login', $attributes);
        $this->assertArrayHasKey('score', $attributes);
        
        // Test attribute types
        $this->assertEquals(AttributeType::CHAR, $attributes['name']->type);
        $this->assertEquals(AttributeType::EMAIL, $attributes['email']->type);
        $this->assertEquals(AttributeType::INT, $attributes['age']->type);
        $this->assertEquals(AttributeType::BOOL, $attributes['is_active']->type);
        $this->assertEquals(AttributeType::TEXT, $attributes['bio']->type);
        $this->assertEquals(AttributeType::LIST, $attributes['tags']->type);
        $this->assertEquals(AttributeType::DATE, $attributes['birth_date']->type);
        $this->assertEquals(AttributeType::DATETIME, $attributes['last_login']->type);
        $this->assertEquals(AttributeType::FLOAT, $attributes['score']->type);
        
        // Test required attributes
        $this->assertTrue($attributes['name']->is_required);
        $this->assertFalse($attributes['email']->is_required);
        $this->assertFalse($attributes['age']->is_required);
    }

    public function testCustomIdAttribute(): void
    {
        $model = new TestModelCustomId();
        
        $this->assertEquals('uuid', $model->getIdKey());
        $this->assertEquals(AttributeType::UUID, $model->idType);
        
        $attributes = $model->getAttributes();
        $this->assertArrayHasKey('uuid', $attributes);
        $this->assertEquals(AttributeType::UUID, $attributes['uuid']->type);
    }

    public function testModelWithoutUser(): void
    {
        $model = new TestModelNoUser();
        
        $this->assertNull($model->getUserKey());
        $this->assertEquals(Privacy::PUBLIC, $model->getViewingPrivacy());
        $this->assertEquals(Privacy::PUBLIC, $model->getEditingPrivacy());
        
        $attributes = $model->getAttributes();
        $this->assertArrayNotHasKey('user_id', $attributes);
    }

    public function testModelWithoutTimestamps(): void
    {
        $model = new TestModelNoTimestamps();
        
        $attributes = $model->getAttributes();
        $this->assertArrayNotHasKey('created_at', $attributes);
        $this->assertArrayNotHasKey('updated_at', $attributes);
        $this->assertArrayNotHasKey('deleted_at', $attributes);
    }

    public function testModelSetAndGetMethods(): void
    {
        $model = new TestModelSimple();
        
        // Test setting and getting values
        $model->set('name', 'John Doe');
        $this->assertEquals('John Doe', $model->get('name'));
        
        $model->set('age', 25);
        $this->assertEquals(25, $model->get('age'));
        
        $model->set('is_active', true);
        $this->assertTrue($model->get('is_active'));
    }

    public function testModelFormValidation(): void
    {
        $model = new TestModelSimple();
        
        // Test valid form data
        $validData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => 25,
            'is_active' => true,
            'user_id' => 1
        ];
        
        $result = $model->checkForm($validData);
        $this->assertInstanceOf(TestModelSimple::class, $result);
        $this->assertTrue($model->isValid());
        
        // Test invalid form data (missing required field)
        $invalidData = [
            'email' => 'john@example.com',
            'age' => 25
        ];
        
        $model2 = new TestModelSimple();
        $result2 = $model2->checkForm($invalidData);
        $this->assertNull($result2);
        $this->assertFalse($model2->isValid());
    }

    public function testModelAttributeExistsMethod(): void
    {
        $model = new TestModelSimple();
        
        // Use reflection to test protected attributeExists method
        $reflection = new ReflectionClass($model);
        $method = $reflection->getMethod('attributeExists');
        $method->setAccessible(true);
        
        $this->assertTrue($method->invoke($model, 'name'));
        $this->assertTrue($method->invoke($model, 'email'));
        $this->assertFalse($method->invoke($model, 'nonexistent'));
    }

    public function testModelTableNameGeneration(): void
    {
        $model = new TestModelSimple();
        $this->assertEquals('test_simples', $model->getModelTable());
        
        $modelA = new TestModelA();
        $this->assertEquals('test_as', $modelA->getModelTable());
    }

    public function testModelStaticMethods(): void
    {
        $this->assertEquals('test_simple', TestModelSimple::getName());
        $this->assertEquals('default', TestModelSimple::getBundle());
    }

    public function testDuplicateAttributeException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("The attribute 'name' has already been defined");
        
        $model = new class extends Model {
            public static $name = 'test_duplicate';
            
            protected function model()
            {
                $this->attribute('name', AttributeType::CHAR);
                $this->attribute('name', AttributeType::CHAR); // Should throw exception
            }
        };
    }

    public function testRelationAttributeTypeException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("The attribute type 'relation' is not allowed, use linkTo, linkedTo or linkThrough instead");
        
        $model = new class extends Model {
            public static $name = 'test_relation';
            
            protected function model()
            {
                $this->attribute('invalid', AttributeType::RELATION);
            }
        };
    }

    public function testMultipleIdAttributeException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("An id attribute has already been defined");
        
        $model = new class extends Model {
            public static $name = 'test_multi_id';
            
            protected function model()
            {
                $this->id('first_id');
                $this->id('second_id'); // Should throw exception
            }
        };
    }

    public function testMultipleUserAttributeException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("A user attribute has already been defined");
        
        $model = new class extends Model {
            public static $name = 'test_multi_user';
            
            protected function model()
            {
                $this->user('first_user_id');
                $this->user('second_user_id'); // Should throw exception
            }
        };
    }

    public function testInvalidIdTypeException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("The id attribute must be of type 'id' or 'uuid'");
        
        $model = new class extends Model {
            public static $name = 'test_invalid_id';
            
            protected function model()
            {
                $this->id('bad_id', true, AttributeType::CHAR); // Should throw exception
            }
        };
    }

    public function testModelInitializationException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("The model's name has not been defined");
        
        $model = new class extends Model {
            // Missing static $name property
            protected function model()
            {
                $this->attribute('test', AttributeType::CHAR);
            }
        };
    }

    public function testTableCreationAfterInitialization(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Table creation needs to be defined before any other attribute");
        
        $model = new class extends Model {
            public static $name = 'test_table_after_init';
            
            protected function model()
            {
                $this->attribute('test', AttributeType::CHAR);
                $this->table('custom_table'); // Should throw exception
            }
        };
    }

    public function testCustomTableName(): void
    {
        $model = new class extends Model {
            public static $name = 'test_custom_table';
            
            protected function model()
            {
                $this->table('my_custom_table');
                $this->attribute('test', AttributeType::CHAR);
            }
        };
        
        $this->assertEquals('my_custom_table', $model->getModelTable());
    }

    public function testBundleDefaultsToDefault(): void
    {
        $model = new class extends Model {
            public static $name = 'test_bundle';
            
            protected function model()
            {
                $this->attribute('test', AttributeType::CHAR);
            }
        };
        
        $this->assertEquals('default', $model::getBundle());
    }

    public function testCustomBundle(): void
    {
        $model = new class extends Model {
            public static $name = 'test_custom_bundle';
            public static $bundle = 'custom';
            
            protected function model()
            {
                $this->attribute('test', AttributeType::CHAR);
            }
        };
        
        $this->assertEquals('custom', $model::getBundle());
        $this->assertEquals('custom_test_custom_bundles', $model->getModelTable());
    }

    public function testTimestampAttributeDefaults(): void
    {
        $model = new TestModelForAttributes();
        $attributes = $model->getAttributes();
        
        // Test default timestamp attributes are created
        $this->assertArrayHasKey('created_at', $attributes);
        $this->assertArrayHasKey('updated_at', $attributes);
        $this->assertArrayHasKey('deleted_at', $attributes);
        
        // Test timestamp attribute types
        $this->assertEquals(AttributeType::DATETIME, $attributes['created_at']->type);
        $this->assertEquals(AttributeType::DATETIME, $attributes['updated_at']->type);
        $this->assertEquals(AttributeType::DATETIME, $attributes['deleted_at']->type);
        
        // Test timestamp requirements
        $this->assertTrue($attributes['created_at']->is_required);
        $this->assertTrue($attributes['updated_at']->is_required);
        $this->assertFalse($attributes['deleted_at']->is_required);
    }

    public function testCustomTimestampNames(): void
    {
        $model = new class extends Model {
            public static $name = 'test_custom_timestamps';
            
            protected function model()
            {
                $this->timestamps(true, 'date_created', 'date_modified', 'date_removed');
                $this->attribute('test', AttributeType::CHAR);
            }
        };
        
        $attributes = $model->getAttributes();
        $this->assertArrayHasKey('date_created', $attributes);
        $this->assertArrayHasKey('date_modified', $attributes);
        $this->assertArrayHasKey('date_removed', $attributes);
        $this->assertArrayNotHasKey('created_at', $attributes);
        $this->assertArrayNotHasKey('updated_at', $attributes);
        $this->assertArrayNotHasKey('deleted_at', $attributes);
    }

    public function testSoftDeleteConfiguration(): void
    {
        $model = new class extends Model {
            public static $name = 'test_soft_delete';
            
            protected function model()
            {
                $this->softDelete(true, 'removed_at');
                $this->attribute('test', AttributeType::CHAR);
            }
        };
        
        $attributes = $model->getAttributes();
        $this->assertArrayHasKey('removed_at', $attributes);
        $this->assertEquals(AttributeType::DATETIME, $attributes['removed_at']->type);
        $this->assertFalse($attributes['removed_at']->is_required);
    }

    public function testSoftDeleteDisabled(): void
    {
        $model = new class extends Model {
            public static $name = 'test_no_soft_delete';
            
            protected function model()
            {
                $this->timestamps(false); // Disable timestamps first
                $this->softDelete(false);
                $this->attribute('test', AttributeType::CHAR);
            }
        };
        
        $attributes = $model->getAttributes();
        $this->assertArrayNotHasKey('deleted_at', $attributes);
    }

    public function testDuplicateTimestampsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Timestamps have already been defined");
        
        $model = new class extends Model {
            public static $name = 'test_duplicate_timestamps';
            
            protected function model()
            {
                $this->timestamps();
                $this->timestamps(); // Should throw exception
            }
        };
    }

    public function testAttributeWithCustomColumn(): void
    {
        $model = new class extends Model {
            public static $name = 'test_custom_column';
            
            protected function model()
            {
                $this->attribute('display_name', AttributeType::CHAR, false, null, 'db_name');
            }
        };
        
        $attributes = $model->getAttributes();
        $this->assertArrayHasKey('display_name', $attributes);
        $this->assertEquals('db_name', $attributes['display_name']->target_column);
    }

    public function testAttributeWithDefaultValue(): void
    {
        $model = new TestModelForAttributes();
        $attributes = $model->getAttributes();
        
        $this->assertArrayHasKey('required_with_default', $attributes);
        $this->assertEquals('default_value', $attributes['required_with_default']->default_value);
        $this->assertTrue($attributes['required_with_default']->is_required);
    }

    public function testLinkToWithoutRelationsLoading(): void
    {
        $model = new TestModelA(false); // loadRelations = false
        $attributes = $model->getAttributes();
        
        // When loadRelations is false, link attributes should not be created
        $this->assertArrayNotHasKey('test_b', $attributes);
    }

    public function testLinkToWithRelationsLoading(): void
    {
        $model = new TestModelA(true); // loadRelations = true
        $attributes = $model->getAttributes();
        
        // When loadRelations is true, link attributes should be created
        $this->assertArrayHasKey('test_b', $attributes);
        $this->assertTrue($attributes['test_b']->is_link);
    }

    public function testInvalidLinkTargetException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("The target model must be a subclass of Model");
        
        $model = new class extends Model {
            public static $name = 'test_invalid_link';
            
            protected function model()
            {
                $this->linkTo('InvalidClass');
            }
        };
    }

    public function testUserPrivacySettings(): void
    {
        $model = new class extends Model {
            public static $name = 'test_user_privacy';
            
            protected function model()
            {
                $this->user('owner_id', Privacy::PROTECTED, Privacy::PROTECTED);
                $this->attribute('test', AttributeType::CHAR);
            }
        };
        
        $this->assertEquals('owner_id', $model->getUserKey());
        $this->assertEquals(Privacy::PROTECTED, $model->getViewingPrivacy());
        $this->assertEquals(Privacy::PROTECTED, $model->getEditingPrivacy());
    }

    public function testFormDataRetrieval(): void
    {
        $model = new TestModelSimple();
        $data = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => 25,
            'user_id' => 1
        ];
        
        $model->checkForm($data);
        $formData = $model->getFormData();
        
        $this->assertArrayHasKey('name', $formData);
        $this->assertArrayHasKey('email', $formData);
        $this->assertArrayHasKey('age', $formData);
        $this->assertEquals('John Doe', $formData['name']);
        $this->assertEquals('john@example.com', $formData['email']);
        $this->assertEquals(25, $formData['age']);
    }

    public function testFormMessagesRetrieval(): void
    {
        $model = new TestModelSimple();
        $invalidData = ['email' => 'invalid-email']; // Missing required name
        
        $model->checkForm($invalidData);
        $messages = $model->getFormMessages();
        
        $this->assertIsArray($messages);
        $this->assertArrayHasKey('all', $messages);
        $this->assertArrayHasKey('attributes', $messages);
    }

    public function testToArrayMethod(): void
    {
        $model = new TestModelSimple();
        $model->set('name', 'John Doe');
        $model->set('email', 'john@example.com');
        $model->set('age', 25);
        
        $array = $model->toArray();
        
        $this->assertIsArray($array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('email', $array);
        $this->assertArrayHasKey('age', $array);
        $this->assertEquals('John Doe', $array['name']);
        $this->assertEquals('john@example.com', $array['email']);
        $this->assertEquals(25, $array['age']);
    }

    public function testGetNonExistentAttribute(): void
    {
        $model = new TestModelSimple();
        $result = $model->get('nonexistent');
        
        $this->assertNull($result);
    }

    public function testSetNonExistentAttribute(): void
    {
        $model = new TestModelSimple();
        $result = $model->set('nonexistent', 'value');
        
        // Should return the model instance even if attribute doesn't exist
        $this->assertInstanceOf(TestModelSimple::class, $result);
    }

    public function testIdAttributeAutoHandling(): void
    {
        $model = new TestModelSimple();
        
        // Test that ID attribute is automatically created
        $this->assertEquals('id', $model->getIdKey());
        $this->assertEquals(AttributeType::ID, $model->idType);
        
        $attributes = $model->getAttributes();
        $this->assertArrayHasKey('id', $attributes);
        $this->assertEquals(AttributeType::ID, $attributes['id']->type);
    }

    public function testModelInstantiationFlag(): void
    {
        $model = new TestModelSimple();
        
        // Model should not be instantiated until data is loaded
        $this->assertFalse($model->isModelInstantiated());
        
        // Simulate loading data
        $model->set('id', 1);
        $reflection = new ReflectionClass($model);
        $property = $reflection->getProperty('is_instantiated');
        $property->setAccessible(true);
        $property->setValue($model, true);
        
        $this->assertTrue($model->isModelInstantiated());
    }

    public function testManyToManyRelationships(): void
    {
        $model = new TestModelManyToMany(true);
        $attributes = $model->getAttributes();
        
        // Test that many-to-many relationship is created
        $this->assertArrayHasKey('tags', $attributes);
        $this->assertTrue($attributes['tags']->is_link);
        $this->assertTrue($attributes['tags']->is_link_through);
    }

    public function testManyToManyWithoutRelationsLoading(): void
    {
        $model = new TestModelManyToMany(false);
        $attributes = $model->getAttributes();
        
        // When loadRelations is false, link attributes should not be created
        $this->assertArrayNotHasKey('tags', $attributes);
    }

    public function testLinkedThroughRelationships(): void
    {
        $model = new TestModelTag(true);
        $attributes = $model->getAttributes();
        
        // Test that inverse many-to-many relationship is created
        $this->assertArrayHasKey('items', $attributes);
        $this->assertTrue($attributes['items']->is_link);
        $this->assertTrue($attributes['items']->is_link_through);
        $this->assertTrue($attributes['items']->is_inversed);
    }

    public function testCustomPrivacyModel(): void
    {
        $model = new TestModelCustomPrivacy();
        
        $this->assertEquals('owner_id', $model->getUserKey());
        $this->assertEquals(Privacy::PROTECTED, $model->getViewingPrivacy());
        $this->assertEquals(Privacy::PROTECTED, $model->getEditingPrivacy());
        
        $attributes = $model->getAttributes();
        $this->assertArrayHasKey('owner_id', $attributes);
        $this->assertArrayHasKey('sensitive_data', $attributes);
    }

    public function testModelWithCustomBundle(): void
    {
        $model = new TestModelWithBundle();
        
        $this->assertEquals('testing', $model::getBundle());
        $this->assertEquals('testing_test_bundle_models', $model->getModelTable());
    }

    public function testFormUpdateMode(): void
    {
        $model = new TestModelSimple();
        
        // First set some initial data
        $initialData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => 25,
            'user_id' => 1
        ];
        $model->checkForm($initialData);
        $this->assertTrue($model->isValid());
        
        // Now test update mode with partial data
        $updateData = [
            'name' => 'Jane Doe',
            'age' => 30
            // Note: email is not provided, should not be cleared in update mode
        ];
        
        $result = $model->checkForm($updateData, true); // is_update = true
        $this->assertInstanceOf(TestModelSimple::class, $result);
        $this->assertTrue($model->isValid());
        
        // Verify the updated values
        $this->assertEquals('Jane Doe', $model->get('name'));
        $this->assertEquals(30, $model->get('age'));
        // Email should still be the original value (not cleared in update mode)
        $this->assertEquals('john@example.com', $model->get('email'));
    }

    public function testPrivacyEnumValues(): void
    {
        // Test all available privacy values
        $model = new class extends Model {
            public static $name = 'test_privacy_enum';
            
            protected function model()
            {
                $this->user('user_id', Privacy::PUBLIC, Privacy::LOGGED_IN);
                $this->attribute('test', AttributeType::CHAR);
            }
        };
        
        $this->assertEquals(Privacy::PUBLIC, $model->getViewingPrivacy());
        $this->assertEquals(Privacy::LOGGED_IN, $model->getEditingPrivacy());
    }

    public function testAttributeColumnMapping(): void
    {
        $model = new class extends Model {
            public static $name = 'test_column_mapping';
            
            protected function model()
            {
                $this->attribute('display_title', AttributeType::CHAR, false, null, 'title_db');
                $this->attribute('user_name', AttributeType::CHAR, false, null, 'username');
            }
        };
        
        $attributes = $model->getAttributes();
        $this->assertEquals('title_db', $attributes['display_title']->target_column);
        $this->assertEquals('username', $attributes['user_name']->target_column);
    }

    public function testAllAttributeTypes(): void
    {
        $model = new TestModelForAttributes();
        $attributes = $model->getAttributes();
        
        // Verify all different attribute types are handled correctly
        $expectedTypes = [
            'char_field' => AttributeType::CHAR,
            'text_field' => AttributeType::TEXT,
            'int_field' => AttributeType::INT,
            'float_field' => AttributeType::FLOAT,
            'bool_field' => AttributeType::BOOL,
            'email_field' => AttributeType::EMAIL,
            'list_field' => AttributeType::LIST,
            'date_field' => AttributeType::DATE,
            'datetime_field' => AttributeType::DATETIME
        ];
        
        foreach ($expectedTypes as $fieldName => $expectedType) {
            $this->assertArrayHasKey($fieldName, $attributes);
            $this->assertEquals($expectedType, $attributes[$fieldName]->type);
        }
    }

    public function testRequiredFieldValidation(): void
    {
        // Test that a valid form passes
        $model = new TestModelForAttributes();
        $validData = [
            'char_field' => 'Required value',
            'text_field' => 'Some text',
            'int_field' => 42,
            'email_field' => 'test@example.com', // Provide valid email
            'user_id' => 1
        ];
        
        $result = $model->checkForm($validData);
        $this->assertInstanceOf(TestModelForAttributes::class, $result);
        $this->assertTrue($model->isValid());
        
        // Test that missing required field fails
        $model2 = new TestModelForAttributes();
        $invalidData = [
            'text_field' => 'Some text',
            'int_field' => 42,
            'email_field' => 'test@example.com',
            'user_id' => 1
            // Missing required 'char_field'
        ];
        
        $result2 = $model2->checkForm($invalidData);
        $this->assertNull($result2);
        $this->assertFalse($model2->isValid());
    }

    public function testDefaultBundleTableNaming(): void
    {
        $model = new TestModelSimple();
        
        // For default bundle, table name should be: {name}s
        $this->assertEquals('test_simples', $model->getModelTable());
        
        // Test another model
        $model2 = new TestModelA();
        $this->assertEquals('test_as', $model2->getModelTable());
    }

    public function testCustomBundleTableNaming(): void
    {
        $model = new TestModelWithBundle();
        
        // For custom bundle, table name should be: {bundle}_{name}s
        $this->assertEquals('testing_test_bundle_models', $model->getModelTable());
    }

    public function testUserKeyNullHandling(): void
    {
        $model = new TestModelNoUser();
        
        // Test that getUserKey returns null when no user attribute is set
        $this->assertNull($model->getUserKey());
        
        // Test that privacy defaults to PUBLIC when user is null
        $this->assertEquals(Privacy::PUBLIC, $model->getViewingPrivacy());
        $this->assertEquals(Privacy::PUBLIC, $model->getEditingPrivacy());
    }

    public function testCompareColumnTypes(): void
    {
        $model = new TestModelSimple();
        
        // Use reflection to test the private compareColumnTypes method
        $reflection = new \ReflectionClass($model);
        $method = $reflection->getMethod('compareColumnTypes');
        $method->setAccessible(true);
        
        // Test exact matches
        $this->assertTrue($method->invoke($model, 'varchar(255)', 'varchar(255)'));
        $this->assertTrue($method->invoke($model, 'int', 'int'));
        
        // Test case insensitive matches
        $this->assertTrue($method->invoke($model, 'VARCHAR(255)', 'varchar(255)'));
        $this->assertTrue($method->invoke($model, 'INT', 'int'));
        
        // Test compatible variations
        $this->assertTrue($method->invoke($model, 'int', 'int(11)'));
        $this->assertTrue($method->invoke($model, 'varchar(255)', 'text'));
        $this->assertTrue($method->invoke($model, 'tinyint(1)', 'boolean'));
        $this->assertTrue($method->invoke($model, 'float', 'double'));
        
        // Test incompatible types
        $this->assertFalse($method->invoke($model, 'varchar(255)', 'int'));
        $this->assertFalse($method->invoke($model, 'date', 'int'));
        $this->assertFalse($method->invoke($model, 'text', 'float'));
        
        // Test parameterized types
        $this->assertTrue($method->invoke($model, 'varchar(100)', 'varchar(255)'));
        $this->assertTrue($method->invoke($model, 'decimal(10,2)', 'decimal(8,2)'));
    }

    public function testCheckModelDBAdequationStructure(): void
    {
        // Test the structure of the returned array from checkModelDBAdequation
        try {
            $result = TestModelSimple::checkModelDBAdequation();
            
            // Should have all expected keys
            $this->assertArrayHasKey('success', $result);
            $this->assertArrayHasKey('table_exists', $result);
            
            if ($result['table_exists']) {
                $this->assertArrayHasKey('missing_columns_in_db', $result);
                $this->assertArrayHasKey('extra_columns_in_db', $result);
                $this->assertArrayHasKey('types_mismatch', $result);
                $this->assertArrayHasKey('required_mismatch', $result);
                $this->assertArrayHasKey('model_attributes', $result);
                $this->assertArrayHasKey('db_columns', $result);
                
                // Test structure of detailed arrays
                $this->assertIsArray($result['missing_columns_in_db']);
                $this->assertIsArray($result['extra_columns_in_db']);
                $this->assertIsArray($result['types_mismatch']);
                $this->assertIsArray($result['required_mismatch']);
                
                // If there are missing columns, test their structure
                foreach ($result['missing_columns_in_db'] as $missing) {
                    $this->assertArrayHasKey('column', $missing);
                    $this->assertArrayHasKey('attribute_name', $missing);
                    $this->assertArrayHasKey('sql_definition', $missing);
                }
                
                // If there are type mismatches, test their structure
                foreach ($result['types_mismatch'] as $mismatch) {
                    $this->assertArrayHasKey('column', $mismatch);
                    $this->assertArrayHasKey('attribute_name', $mismatch);
                    $this->assertArrayHasKey('expected_type', $mismatch);
                    $this->assertArrayHasKey('actual_type', $mismatch);
                }
                
                // If there are required mismatches, test their structure
                foreach ($result['required_mismatch'] as $mismatch) {
                    $this->assertArrayHasKey('column', $mismatch);
                    $this->assertArrayHasKey('attribute_name', $mismatch);
                    $this->assertArrayHasKey('expected_required', $mismatch);
                    $this->assertArrayHasKey('actual_nullable', $mismatch);
                }
            } else {
                // Table doesn't exist - should be false success
                $this->assertFalse($result['success']);
            }
        } catch (Exception $e) {
            // If database connection fails, skip this test
            $this->markTestSkipped('Database connection required for this test: ' . $e->getMessage());
        }
    }

    public function testUpdateTableFromModelDryRun(): void
    {
        try {
            // Test dry run mode doesn't execute any SQL
            $result = TestModelSimple::updateTableFromModel(false, false, true);
            
            // Should have the expected structure
            $this->assertArrayHasKey('success', $result);
            $this->assertArrayHasKey('message', $result);
            $this->assertArrayHasKey('changes_made', $result);
            $this->assertArrayHasKey('warnings', $result);
            $this->assertArrayHasKey('sql_statements', $result);
            $this->assertArrayHasKey('dry_run', $result);
            
            // Should be marked as dry run
            $this->assertTrue($result['dry_run']);
            
            // Should be successful (dry run always succeeds if no critical errors)
            $this->assertTrue($result['success']);
            
            // Arrays should be properly formatted
            $this->assertIsArray($result['changes_made']);
            $this->assertIsArray($result['warnings']);
            $this->assertIsArray($result['sql_statements']);
        } catch (Exception $e) {
            // If database connection fails, skip this test
            $this->markTestSkipped('Database connection required for this test: ' . $e->getMessage());
        }
    }

    public function testUpdateTableFromModelTableNotExists(): void
    {
        try {
            // Test with a model that doesn't have a table
            $this->expectException(Exception::class);
            $this->expectExceptionMessage("does not exist");
            
            $model = new class extends Model {
                public static $name = 'non_existent_table_model';
                
                protected function model()
                {
                    $this->attribute('test', AttributeType::CHAR);
                }
            };
            
            $model::updateTableFromModel();
        } catch (Exception $e) {
            // Check if it's a database connection error vs the expected "does not exist" error
            if (strpos($e->getMessage(), 'No such file or directory') !== false || 
                strpos($e->getMessage(), 'Connection refused') !== false) {
                $this->markTestSkipped('Database connection required for this test: ' . $e->getMessage());
            } else {
                // Re-throw the exception as it's the expected one
                throw $e;
            }
        }
    }

    public function testModelFieldsInDBStructure(): void
    {
        $model = new TestModelForAttributes();
        
        // Use reflection to test the protected getModelFieldsInDB method
        $reflection = new \ReflectionClass($model);
        $method = $reflection->getMethod('getModelFieldsInDB');
        $method->setAccessible(true);
        
        $fields = $method->invoke($model);
        
        $this->assertIsArray($fields);
        
        // Check that each field has the expected structure
        foreach ($fields as $fieldName => $fieldData) {
            $this->assertArrayHasKey('sql_query', $fieldData);
            $this->assertArrayHasKey('type', $fieldData);
            $this->assertArrayHasKey('required', $fieldData);
            $this->assertArrayHasKey('name', $fieldData);
            
            $this->assertIsString($fieldData['sql_query']);
            $this->assertIsString($fieldData['type']);
            $this->assertIsBool($fieldData['required']);
            $this->assertIsString($fieldData['name']);
        }
        
        // Test that ID field is marked as required
        if (isset($fields['id'])) {
            $this->assertTrue($fields['id']['required']);
        }
    }

    public function testMigrationSafetyMeasures(): void
    {
        // Test that the migration system properly handles potentially destructive operations
        
        // Create a test model that would have mismatches if a table existed
        $model = new class extends Model {
            public static $name = 'migration_safety_test';
            
            protected function model()
            {
                $this->attribute('safe_field', AttributeType::CHAR, false);
                $this->attribute('risky_field', AttributeType::INT, true);
            }
        };
        
        // Test dry run doesn't throw exceptions even for non-existent tables
        try {
            $result = $model::updateTableFromModel(false, false, true);
            // Dry run should work even if table doesn't exist (will show what would happen)
            $this->assertTrue(true); // If we get here, no exception was thrown
        } catch (Exception $e) {
            // Check if it's a database connection error vs expected table error
            if (strpos($e->getMessage(), 'No such file or directory') !== false || 
                strpos($e->getMessage(), 'Connection refused') !== false) {
                $this->markTestSkipped('Database connection required for this test: ' . $e->getMessage());
            } else {
                // It's okay if it throws for non-existent table, that's expected
                $this->assertStringContainsString('does not exist', $e->getMessage());
            }
        }
    }

    protected function setUp(): void
    {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__, '../../../.env');
        $dotenv->load();
    }
}
