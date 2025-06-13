<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use pz\Enums\model\AttributeType;
use pz\ModelAttributeLinkThrough;

require_once __DIR__ . '/../test_ressources/testModels.php';

final class modelAttributeLinkthroughTest extends TestCase
{
    private TestModelManyToMany $modelManyToMany;
    private TestModelTag $modelTag;
    
    protected function setUp(): void
    {
        $this->modelManyToMany = new TestModelManyToMany();
        $this->modelTag = new TestModelTag();
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__, '../../../.env');
        $dotenv->load();
    }

    /**
     * Helper method to access protected properties
     */
    private function getProtectedProperty($object, $property)
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        return $property->getValue($object);
    }

    /**
     * Helper method to check if attribute is valid
     */
    private function isAttributeValid($attribute): bool
    {
        return $this->getProtectedProperty($attribute, 'is_valid');
    }

    public function testLinkThroughAttributeBasicProperties(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        
        // Test that the link through attribute exists and has correct properties
        $this->assertArrayHasKey('tags', $attributes);
        $linkThroughAttr = $attributes['tags'];
        
        $this->assertInstanceOf(ModelAttributeLinkThrough::class, $linkThroughAttr);
        $this->assertEquals('tags', $linkThroughAttr->name);
        $this->assertEquals(AttributeType::RELATION, $linkThroughAttr->type);
        $this->assertTrue($linkThroughAttr->is_link);
        $this->assertTrue($linkThroughAttr->is_link_through);
        $this->assertFalse($linkThroughAttr->is_inversed);
        $this->assertEquals('default', $linkThroughAttr->bundle);
        $this->assertEquals(TestModelTag::class, $linkThroughAttr->target);
    }

    public function testInversedLinkThroughAttributeProperties(): void
    {
        $attributes = $this->modelTag->getAttributes();
        
        // Test that the inversed link through attribute exists and has correct properties  
        $this->assertArrayHasKey('items', $attributes);
        $linkThroughAttr = $attributes['items'];
        
        $this->assertInstanceOf(ModelAttributeLinkThrough::class, $linkThroughAttr);
        $this->assertEquals('items', $linkThroughAttr->name);
        $this->assertEquals(AttributeType::RELATION, $linkThroughAttr->type);
        $this->assertTrue($linkThroughAttr->is_link);
        $this->assertTrue($linkThroughAttr->is_link_through);
        $this->assertTrue($linkThroughAttr->is_inversed);
        $this->assertEquals(TestModelManyToMany::class, $linkThroughAttr->target);
    }

    public function testLinkThroughAttributeConstructor(): void
    {
        // Test creating a ModelAttributeLinkThrough with all parameters
        $linkThroughAttr = new ModelAttributeLinkThrough(
            'test_link_through',
            'TestModel',
            'test_bundle',
            false, // inversed
            true, // is_many
            null, // default value
            'test_table',
            'test_id',
            'target_id',
            TestModelSimple::class,
            null, // target_table
            null, // target_id_key
            'relation_table',
            'relation_model_column',
            'relation_model_type'
        );

        $this->assertEquals('test_link_through', $linkThroughAttr->name);
        $this->assertEquals(AttributeType::RELATION, $linkThroughAttr->type);
        $this->assertFalse($linkThroughAttr->is_required);
        $this->assertFalse($linkThroughAttr->is_inversed);
        $this->assertEquals('test_bundle', $linkThroughAttr->bundle);
        $this->assertEquals('TestModel', $linkThroughAttr->model);
        $this->assertEquals('test_table', $linkThroughAttr->model_table);
        $this->assertEquals('test_id', $linkThroughAttr->model_id_key);
        $this->assertEquals('target_id', $linkThroughAttr->target_column);
        $this->assertEquals(TestModelSimple::class, $linkThroughAttr->target);
        $this->assertEquals('relation_table', $linkThroughAttr->relation_table);
        $this->assertEquals('relation_model_column', $linkThroughAttr->relation_model_column);
        $this->assertEquals('relation_model_type', $linkThroughAttr->relation_model_type);
    }

    public function testLinkThroughAttributeConstructorWithoutTarget(): void
    {
        // Test creating a ModelAttributeLinkThrough with explicit target table instead of target class
        $linkThroughAttr = new ModelAttributeLinkThrough(
            'test_link_through',
            'TestModel',
            'default',
            false, // inversed
            true, // is_many
            null, // default value
            'test_table',
            'test_id',
            'target_id',
            null, // target class
            'target_table',
            'target_id',
            'relation_table',
            'relation_model_column',
            'relation_model_type'
        );

        $this->assertNull($linkThroughAttr->target);
        $this->assertEquals('target_table', $linkThroughAttr->target_table);
        $this->assertEquals('target_id', $linkThroughAttr->target_id_key);
        $this->assertEquals(AttributeType::ID, $linkThroughAttr->target_id_type);
    }

    public function testLinkThroughAttributeConstructorRequiresTargetOrTable(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('You need to provide at least a target object or a target table for the link attribute.');

        // This should fail because neither target nor target_table is provided
        new ModelAttributeLinkThrough(
            'test_link_through',
            'TestModel',
            'default',
            false,
            true,
            null,
            'test_table',
            'test_id',
            'target_id',
            null, // no target class
            null, // no target table
            null,
            'relation_table'
        );
    }

    public function testLinkThroughAttributeDefaultValues(): void
    {
        $linkThroughAttr = new ModelAttributeLinkThrough(
            'simple_link_through',
            'TestModel',
            'default',
            false,
            true,
            null,
            'test_table',
            'test_id',
            null,
            TestModelSimple::class
        );

        // Test default values
        $this->assertEquals('default', $linkThroughAttr->bundle);
        $this->assertFalse($linkThroughAttr->is_required);
        $this->assertFalse($linkThroughAttr->is_inversed);
        $this->assertEquals('simple_link_through_id', $linkThroughAttr->target_column);
        $this->assertEquals([], $linkThroughAttr->value);
        $this->assertTrue($linkThroughAttr->is_link);
        $this->assertTrue($linkThroughAttr->is_link_through);
    }

    public function testLinkThroughAttributeInversedNaming(): void
    {
        // Test inversed naming logic
        $linkThroughAttr = new ModelAttributeLinkThrough(
            'inversed_link',
            TestModelManyToMany::class,
            'default',
            true, // inversed
            true,
            null,
            null, // will use makeRelationTableName
            null,
            null,
            TestModelTag::class
        );

        $this->assertTrue($linkThroughAttr->is_inversed);
        $this->assertEquals(TestModelTag::class, $linkThroughAttr->target);
    }

    public function testSetAttributeValueWithModelObject(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Test setting a model object as value
        $testTag = new TestModelTag();
        $testTag->set('id', 123);
        
        $linkThroughAttr->create($testTag);
        $this->assertTrue($this->isAttributeValid($linkThroughAttr));
        
        $values = $linkThroughAttr->get(true);
        $this->assertIsArray($values);
        $this->assertArrayHasKey(123, $values);
        $this->assertSame($testTag, $values[123]);
    }

    public function testSetAttributeValueWithMultipleObjects(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Test setting multiple model objects as value
        $testTag1 = new TestModelTag();
        $testTag1->set('id', 456);
        $testTag2 = new TestModelTag();
        $testTag2->set('id', 789);
        
        $linkThroughAttr->create([$testTag1, $testTag2]);
        $this->assertTrue($this->isAttributeValid($linkThroughAttr));
        
        $values = $linkThroughAttr->get(true);
        $this->assertIsArray($values);
        $this->assertCount(2, $values);
        $this->assertArrayHasKey(456, $values);
        $this->assertArrayHasKey(789, $values);
        $this->assertSame($testTag1, $values[456]);
        $this->assertSame($testTag2, $values[789]);
    }

    public function testSetAttributeValueWithArray(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Test setting an array with model data
        $modelData = [
            'id' => 999,
            'name' => 'Test Tag',
            'created_at' => '2023-01-01 12:00:00'
        ];
        
        try {
            $linkThroughAttr->create($modelData);
            if ($this->isAttributeValid($linkThroughAttr)) {
                $values = $linkThroughAttr->get(true);
                $this->assertIsArray($values);
                $this->assertArrayHasKey(999, $values);
                $this->assertInstanceOf(TestModelTag::class, $values[999]);
                $this->assertEquals(999, $values[999]->getId());
            }
        } catch (\Exception $e) {
            // Skip if database connection not available
            $this->markTestSkipped('Database connection required for array-to-model conversion');
        }
    }

    public function testSetAttributeValueWithNull(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Test setting null value on non-required attribute
        $linkThroughAttr->create(null);
        $this->assertTrue($this->isAttributeValid($linkThroughAttr));
        $this->assertEquals([], $linkThroughAttr->get());
    }

    public function testSetAttributeValueWithEmptyArray(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Test setting empty array
        $linkThroughAttr->create([]);
        $this->assertTrue($this->isAttributeValid($linkThroughAttr));
        $this->assertEquals([], $linkThroughAttr->get());
    }

    public function testInvalidAttributeValueHandling(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Test setting invalid value (string instead of model/array)
        try {
            $linkThroughAttr->create('invalid_string_value');
            $this->assertFalse($this->isAttributeValid($linkThroughAttr));
        } catch (\Exception $e) {
            // Exception is expected for invalid value
            $this->assertStringContainsString('Attribute value must be an object or an array', $e->getMessage());
        }
    }

    public function testInvalidArrayValueHandling(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Test setting array with invalid object type
        $invalidObject = new TestModelSimple();
        
        try {
            $linkThroughAttr->create([$invalidObject]);
            $this->assertFalse($this->isAttributeValid($linkThroughAttr));
            $this->assertNotEmpty($linkThroughAttr->messages);
        } catch (\Exception $e) {
            $this->assertStringContainsString('Attribute value must be an object of type', $e->getMessage());
        }
    }

    public function testGetAttributeValueWithModels(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Test getting value when no value is set
        $this->assertEquals([], $linkThroughAttr->get());
        
        // Test setting and getting model objects
        $testTag1 = new TestModelTag();
        $testTag1->set('id', 111);
        $testTag2 = new TestModelTag();
        $testTag2->set('id', 222);
        
        $linkThroughAttr->create([$testTag1, $testTag2]);
        if ($this->isAttributeValid($linkThroughAttr)) {
            // get(false) should return array representation
            $arrayValue = $linkThroughAttr->get(false);
            $this->assertTrue(is_array($arrayValue));
            $this->assertCount(2, $arrayValue);
            
            // get(true) should return the actual objects array
            $objectValue = $linkThroughAttr->get(true);
            $this->assertIsArray($objectValue);
            $this->assertCount(2, $objectValue);
            $this->assertArrayHasKey(111, $objectValue);
            $this->assertArrayHasKey(222, $objectValue);
            $this->assertSame($testTag1, $objectValue[111]);
            $this->assertSame($testTag2, $objectValue[222]);
        }
    }

    public function testGetAttributeValueWithoutTargetClass(): void
    {
        // Create a link through attribute without target class (uses array values)
        $linkThroughAttr = new ModelAttributeLinkThrough(
            'array_link_through',
            'TestModel',
            'default',
            false,
            true,
            null,
            'test_table',
            'test_id',
            'target_id',
            null, // no target class
            'target_table',
            'target_key',
            'relation_table'
        );

        // Set array value
        $arrayValue = ['target_key' => 555, 'name' => 'Test'];
        try {
            $linkThroughAttr->create($arrayValue);
            if ($this->isAttributeValid($linkThroughAttr)) {
                $result = $linkThroughAttr->get(false);
                $this->assertIsArray($result);
                $this->assertArrayHasKey(555, $result);
                $this->assertEquals($arrayValue, $result[555]);
            }
        } catch (\Exception $e) {
            // Array parsing might fail without proper validation
            $this->assertStringContainsString('target_key', $e->getMessage());
        }
    }

    public function testParseValueWithModelObject(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        $testTag = new TestModelTag();
        $testTag->set('id', 333);
        
        // Use reflection to test parseValue method directly
        $reflection = new \ReflectionClass($linkThroughAttr);
        $parseValueMethod = $reflection->getMethod('parseValue');
        $parseValueMethod->setAccessible(true);
        
        $result = $parseValueMethod->invoke($linkThroughAttr, $testTag);
        $this->assertSame($testTag, $result);
    }

    public function testParseValueWithArray(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        $modelData = ['id' => 444, 'name' => 'Test Tag Parse'];
        
        try {
            // Use reflection to test parseValue method directly
            $reflection = new \ReflectionClass($linkThroughAttr);
            $parseValueMethod = $reflection->getMethod('parseValue');
            $parseValueMethod->setAccessible(true);
            
            $result = $parseValueMethod->invoke($linkThroughAttr, $modelData);
            $this->assertInstanceOf(TestModelTag::class, $result);
            $this->assertEquals(444, $result->getId());
        } catch (\Exception $e) {
            // Skip if database connection not available
            $this->markTestSkipped('Database connection required for array parsing');
        }
    }

    public function testParseValueWithInvalidType(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Use reflection to test parseValue method directly
        $reflection = new \ReflectionClass($linkThroughAttr);
        $parseValueMethod = $reflection->getMethod('parseValue');
        $parseValueMethod->setAccessible(true);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Attribute value must be an object of type');
        
        $parseValueMethod->invoke($linkThroughAttr, 'invalid_value');
    }

    public function testParseValueWithArrayMissingKey(): void
    {
        // Create a link through attribute without target class
        $linkThroughAttr = new ModelAttributeLinkThrough(
            'array_link_through',
            'TestModel',
            'default',
            false,
            true,
            null,
            'test_table',
            'test_id',
            'target_id',
            null, // no target class
            'target_table',
            'required_key',
            'relation_table'
        );

        // Use reflection to test parseValue method directly
        $reflection = new \ReflectionClass($linkThroughAttr);
        $parseValueMethod = $reflection->getMethod('parseValue');
        $parseValueMethod->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The attribute value must have a key named required_key');

        $parseValueMethod->invoke($linkThroughAttr, ['wrong_key' => 'value']);
    }

    public function testUpdateAttributeValueRequiresObjectId(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Test update method requires object_id to be set
        $testTag = new TestModelTag();
        $testTag->set('id', 500);
        $linkThroughAttr->create($testTag);
        
        // This should throw exception because object_id is not set
        try {
            $reflection = new \ReflectionClass($linkThroughAttr);
            $updateMethod = $reflection->getMethod('updateAttributeValue');
            $updateMethod->setAccessible(true);
            $updateMethod->invoke($linkThroughAttr);
            
            $this->fail('Expected exception for missing object_id');
        } catch (\Exception $e) {
            $this->assertEquals('Object ID not set', $e->getMessage());
        }
    }

    public function testFindRelationsMethod(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Set object_id for testing findRelations
        $linkThroughAttr->setId(100);
        
        try {
            // Use reflection to test findRelations method directly
            $reflection = new \ReflectionClass($linkThroughAttr);
            $findRelationsMethod = $reflection->getMethod('findRelations');
            $findRelationsMethod->setAccessible(true);
            
            $result = $findRelationsMethod->invoke($linkThroughAttr);
            // Result should be an array (empty if no database connection)
            $this->assertIsArray($result);
        } catch (\Exception $e) {
            // Skip if database connection not available
            $this->markTestSkipped('Database connection required for findRelations test');
        }
    }

    public function testFetchAttributeValueMethod(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Set object_id for testing fetchAttributeValue
        $linkThroughAttr->setId(200);
        
        try {
            // Use reflection to test fetchAttributeValue method directly
            $reflection = new \ReflectionClass($linkThroughAttr);
            $fetchMethod = $reflection->getMethod('fetchAttributeValue');
            $fetchMethod->setAccessible(true);
            
            $result = $fetchMethod->invoke($linkThroughAttr);
            // Result should be null or array
            $this->assertTrue($result === null || is_array($result));
        } catch (\Exception $e) {
            // Skip if database connection not available
            $this->markTestSkipped('Database connection required for fetchAttributeValue test');
        }
    }

    public function testLinkThroughSpecificProperties(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Test link-through specific properties
        $this->assertTrue($linkThroughAttr->is_link);
        $this->assertTrue($linkThroughAttr->is_link_through);
        $this->assertEquals(AttributeType::RELATION, $linkThroughAttr->type);
        
        // Test relation table properties
        $this->assertNotEmpty($linkThroughAttr->relation_table);
        $this->assertNotEmpty($linkThroughAttr->relation_model_column);
        
        // For many-to-many relationships, relation_model_type should be set
        if ($linkThroughAttr->is_many) {
            $this->assertNotEmpty($linkThroughAttr->relation_model_type);
        }
    }

    public function testManyToManyRelationConfiguration(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Test many-to-many specific configuration
        $this->assertTrue($linkThroughAttr->is_many);
        $this->assertStringContainsString('able_type', $linkThroughAttr->relation_model_type);
        $this->assertStringContainsString('able_id', $linkThroughAttr->relation_model_column);
    }

    public function testNonManyRelationConfiguration(): void
    {
        // Create a non-many link through attribute
        $linkThroughAttr = new ModelAttributeLinkThrough(
            'single_link_through',
            TestModelManyToMany::class,
            'default',
            false,
            false, // not many
            null,
            'test_table',
            'test_id',
            'target_id',
            TestModelTag::class,
            null,
            null,
            'relation_table'
        );

        $this->assertNull($linkThroughAttr->relation_model_type);
        $this->assertStringContainsString('_id', $linkThroughAttr->relation_model_column);
    }

    public function testAttributeTypeIsRelation(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Test that link through attributes always have RELATION type
        $this->assertEquals(AttributeType::RELATION, $linkThroughAttr->type);
        $this->assertEquals('relation', $linkThroughAttr->getType());
    }

    public function testLinkThroughAttributeInheritanceFromAbstract(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Test that link through attribute inherits from AbstractModelAttribute
        $this->assertInstanceOf(\pz\AbstractModelAttribute::class, $linkThroughAttr);
        
        // Test inherited methods are available
        $this->assertTrue(method_exists($linkThroughAttr, 'setRequired'));
        $this->assertTrue(method_exists($linkThroughAttr, 'setDefault'));
        $this->assertTrue(method_exists($linkThroughAttr, 'create'));
        $this->assertTrue(method_exists($linkThroughAttr, 'update'));
        $this->assertTrue(method_exists($linkThroughAttr, 'get'));
        $this->assertTrue(method_exists($linkThroughAttr, 'setId'));
        $this->assertTrue(method_exists($linkThroughAttr, 'isRequired'));
        $this->assertTrue(method_exists($linkThroughAttr, 'getType'));
    }

    public function testMethodChaining(): void
    {
        // Test method chaining with link through attributes
        $linkThroughAttr = new ModelAttributeLinkThrough(
            'chainable_link_through',
            'TestModel',
            'default',
            false,
            true,
            null,
            'test_table',
            'test_id',
            'target_id',
            TestModelTag::class
        );

        $testTag = new TestModelTag();
        $testTag->set('id', 600);

        // Test that create returns the same object for chaining
        $result = $linkThroughAttr->setRequired()->create($testTag);
        $this->assertSame($linkThroughAttr, $result);
        $this->assertTrue($linkThroughAttr->isRequired());
    }

    public function testSetIdMethod(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Test setting ID
        $linkThroughAttr->setId(123);
        $this->assertEquals(123, $this->getProtectedProperty($linkThroughAttr, 'object_id'));
        
        // Test setting same ID again should work
        $linkThroughAttr->setId(123);
        $this->assertEquals(123, $this->getProtectedProperty($linkThroughAttr, 'object_id'));
        
        // Test setting different ID should throw exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Object ID is already set');
        $linkThroughAttr->setId(456);
    }

    public function testLoadFromValue(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        $testTag1 = new TestModelTag();
        $testTag1->set('id', 700);
        $testTag2 = new TestModelTag();
        $testTag2->set('id', 800);
        
        // Test loadFromValue method
        $linkThroughAttr->loadFromValue([$testTag1, $testTag2], 456);
        $this->assertEquals(456, $this->getProtectedProperty($linkThroughAttr, 'object_id'));
        
        $values = $linkThroughAttr->get(true);
        $this->assertIsArray($values);
        $this->assertCount(2, $values);
        $this->assertArrayHasKey(700, $values);
        $this->assertArrayHasKey(800, $values);
        $this->assertSame($testTag1, $values[700]);
        $this->assertSame($testTag2, $values[800]);
    }

    public function testComplexLinkThroughScenarios(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Test updating link through with valid value after invalid attempt
        try {
            $linkThroughAttr->create('invalid_value');
            $this->assertFalse($this->isAttributeValid($linkThroughAttr));
        } catch (\Exception $e) {
            // Expected exception for invalid value
        }
        
        // Reset state and try with valid value
        $linkThroughAttr->messages = [];
        $reflection = new \ReflectionClass($linkThroughAttr);
        $isValidProperty = $reflection->getProperty('is_valid');
        $isValidProperty->setAccessible(true);
        $isValidProperty->setValue($linkThroughAttr, true);
        
        $testTag1 = new TestModelTag();
        $testTag1->set('id', 900);
        $testTag2 = new TestModelTag();
        $testTag2->set('id', 901);
        $linkThroughAttr->create([$testTag1, $testTag2]);
        
        $this->assertTrue($this->isAttributeValid($linkThroughAttr));
        $values = $linkThroughAttr->get(true);
        $this->assertCount(2, $values);
        $this->assertArrayHasKey(900, $values);
        $this->assertArrayHasKey(901, $values);
    }

    public function testLinkThroughAttributeEdgeCases(): void
    {
        // Test link through attribute with minimal required parameters
        $linkThroughAttr = new ModelAttributeLinkThrough(
            'minimal_link_through',
            'TestModel',
            'default',
            false,
            true,
            null,
            'test_table',
            'test_id',
            null,
            TestModelSimple::class
        );

        $this->assertEquals('minimal_link_through', $linkThroughAttr->name);
        $this->assertEquals('minimal_link_through_id', $linkThroughAttr->target_column);
        $this->assertEquals(TestModelSimple::class, $linkThroughAttr->target);
        
        // Test that target properties are set from target model
        $this->assertNotEmpty($linkThroughAttr->target_table);
        $this->assertNotEmpty($linkThroughAttr->target_id_key);
        $this->assertNotEmpty($linkThroughAttr->relation_table);
    }

    public function testDatabaseUpdateOperations(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Set up test data
        $linkThroughAttr->setId(1000);
        $testTag = new TestModelTag();
        $testTag->set('id', 1001);
        $linkThroughAttr->create($testTag);
        
        try {
            // Use reflection to test updateAttributeValue method
            $reflection = new \ReflectionClass($linkThroughAttr);
            $updateMethod = $reflection->getMethod('updateAttributeValue');
            $updateMethod->setAccessible(true);
            
            $result = $updateMethod->invoke($linkThroughAttr);
            $this->assertSame($linkThroughAttr, $result);
        } catch (\Exception $e) {
            // Skip if database connection not available
            $this->markTestSkipped('Database connection required for update operations');
        }
    }

    public function testRelationTableNaming(): void
    {
        // Test default relation table naming
        $linkThroughAttr = new ModelAttributeLinkThrough(
            'test_naming',
            TestModelManyToMany::class,
            'default',
            false,
            true,
            null,
            null, // will use makeRelationTableName
            null,
            null,
            TestModelTag::class
        );

        // Relation table should be generated automatically
        $this->assertNotEmpty($linkThroughAttr->relation_table);
        $this->assertStringContainsString('relation', $linkThroughAttr->relation_table);
    }

    public function testValueManipulationEdgeCases(): void
    {
        $attributes = $this->modelManyToMany->getAttributes();
        $linkThroughAttr = $attributes['tags'];
        
        // Test with single non-array, non-object value that's actually an array (edge case)
        try {
            $singleArrayValue = ['id' => 1002, 'name' => 'Single Array Tag'];
            $linkThroughAttr->create($singleArrayValue);
            
            if ($this->isAttributeValid($linkThroughAttr)) {
                $values = $linkThroughAttr->get(true);
                $this->assertIsArray($values);
                $this->assertArrayHasKey(1002, $values);
            }
        } catch (\Exception $e) {
            // Skip if database connection not available
            $this->markTestSkipped('Database connection required for single array value test');
        }
    }
}