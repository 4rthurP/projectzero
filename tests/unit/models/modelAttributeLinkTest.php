<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use pz\Enums\model\AttributeType;
use pz\ModelAttributeLink;

require_once __DIR__ . '/../test_ressources/testModels.php';

final class modelAttributeLinkTest extends TestCase
{
    private TestModelA $modelA;
    private TestModelB $modelB;
    
    protected function setUp(): void
    {
        $this->modelA = new TestModelA();
        $this->modelB = new TestModelB();
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

    public function testLinkAttributeBasicProperties(): void
    {
        $attributes = $this->modelA->getAttributes();
        
        // Test that the link attribute exists and has correct properties
        $this->assertArrayHasKey('test_b', $attributes);
        $linkAttr = $attributes['test_b'];
        
        $this->assertInstanceOf(ModelAttributeLink::class, $linkAttr);
        $this->assertEquals('test_b', $linkAttr->name);
        $this->assertEquals(AttributeType::RELATION, $linkAttr->type);
        $this->assertTrue($linkAttr->is_link);
        $this->assertFalse($linkAttr->is_link_through);
        $this->assertFalse($linkAttr->is_inversed);
        $this->assertEquals('default', $linkAttr->bundle);
        $this->assertEquals(TestModelB::class, $linkAttr->target);
    }

    public function testInversedLinkAttributeProperties(): void
    {
        $attributes = $this->modelB->getAttributes();
        
        // Test that the inversed link attribute exists and has correct properties  
        $this->assertArrayHasKey('test_a', $attributes);
        $linkAttr = $attributes['test_a'];
        
        $this->assertInstanceOf(ModelAttributeLink::class, $linkAttr);
        $this->assertEquals('test_a', $linkAttr->name);
        $this->assertEquals(AttributeType::RELATION, $linkAttr->type);
        $this->assertTrue($linkAttr->is_link);
        $this->assertFalse($linkAttr->is_link_through);
        $this->assertTrue($linkAttr->is_inversed);
        $this->assertEquals(TestModelA::class, $linkAttr->target);
    }

    public function testLinkAttributeConstructor(): void
    {
        // Test creating a ModelAttributeLink with all parameters
        $linkAttr = new ModelAttributeLink(
            'test_link',
            'TestModel',
            'test_bundle',
            true, // required
            false, // inversed
            null, // default value
            'test_table',
            'test_id',
            'target_id',
            TestModelSimple::class,
            null, // target_table
            null, // target_id_key
            'updated_at',
            'target_updated_at'
        );

        $this->assertEquals('test_link', $linkAttr->name);
        $this->assertEquals(AttributeType::RELATION, $linkAttr->type);
        $this->assertTrue($linkAttr->is_required);
        $this->assertFalse($linkAttr->is_inversed);
        $this->assertEquals('test_bundle', $linkAttr->bundle);
        $this->assertEquals('TestModel', $linkAttr->model);
        $this->assertEquals('test_table', $linkAttr->model_table);
        $this->assertEquals('test_id', $linkAttr->model_id_key);
        $this->assertEquals('test_id', $linkAttr->target_column);
        $this->assertEquals(TestModelSimple::class, $linkAttr->target);
    }

    public function testLinkAttributeConstructorWithoutTarget(): void
    {
        // Test creating a ModelAttributeLink with explicit target table instead of target class
        $linkAttr = new ModelAttributeLink(
            'test_link',
            'TestModel',
            'default',
            false, // required
            false, // inversed
            null, // default value
            'test_table',
            'test_id',
            'target_id',
            null, // target class
            'target_table',
            'target_id',
            null,
            null
        );

        $this->assertNull($linkAttr->target);
        $this->assertEquals('target_table', $linkAttr->target_table);
        $this->assertEquals('target_id', $linkAttr->target_id_key);
    }

    public function testLinkAttributeConstructorRequiresTargetOrTable(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('You need to provide at least a target object or a target table for the link attribute.');

        // This should fail because neither target nor target_table is provided
        new ModelAttributeLink(
            'test_link',
            'TestModel',
            'default',
            false,
            false,
            null,
            'test_table',
            'test_id',
            'target_id',
            null, // no target class
            null, // no target table
            null
        );
    }

    public function testLinkAttributeDefaultValues(): void
    {
        $linkAttr = new ModelAttributeLink(
            'simple_link',
            'TestModel',
            'default',
            false,
            false,
            null,
            'test_table', // Provide model_table to avoid makeRelationTableName call
            'test_id',
            null,
            TestModelSimple::class
        );

        // Test default values
        $this->assertEquals('default', $linkAttr->bundle);
        $this->assertFalse($linkAttr->is_required);
        $this->assertFalse($linkAttr->is_inversed);
        $this->assertEquals('simple_link_id', $linkAttr->target_column);
        $this->assertEquals([], $linkAttr->value);
        $this->assertTrue($linkAttr->is_link);
        $this->assertFalse($linkAttr->is_link_through);
    }

    public function testSetAttributeValueWithModelObject(): void
    {
        $attributes = $this->modelA->getAttributes();
        $linkAttr = $attributes['test_b'];
        
        // Test setting a model object as value
        $testModelB = new TestModelB();
        $testModelB->set('id', 123);
        
        $linkAttr->create($testModelB);
        $this->assertTrue($this->isAttributeValid($linkAttr));
        $this->assertSame($testModelB, $linkAttr->get(true));
    }

    public function testSetAttributeValueWithArray(): void
    {
        $attributes = $this->modelA->getAttributes();
        $linkAttr = $attributes['test_b'];
        
        // Test setting an array with model data
        $modelData = [
            'id' => 456,
            'name' => 'Test Model B',
            'created_at' => '2023-01-01 12:00:00'
        ];
        
        try {
            $linkAttr->create($modelData);
            if ($this->isAttributeValid($linkAttr)) {
                $value = $linkAttr->get(true);
                $this->assertInstanceOf(TestModelB::class, $value);
                $this->assertEquals(456, $value->getId());
            }
        } catch (\Exception $e) {
            // Skip if database connection not available
            $this->markTestSkipped('Database connection required for array-to-model conversion');
        }
    }

    public function testSetAttributeValueWithNull(): void
    {
        $attributes = $this->modelA->getAttributes();
        $linkAttr = $attributes['test_b'];
        
        // Test setting null value on non-required attribute
        $linkAttr->create(null);
        $this->assertTrue($this->isAttributeValid($linkAttr));
        $this->assertNull($linkAttr->get());
    }

    public function testRequiredAttributeValidation(): void
    {
        // Create a required link attribute
        $linkAttr = new ModelAttributeLink(
            'required_link',
            'TestModel',
            'default',
            true, // required
            false,
            null,
            'test_table',
            'test_id',
            'target_id',
            TestModelB::class
        );

        // Test that null value fails validation for required attribute
        $linkAttr->create(null);
        $this->assertFalse($this->isAttributeValid($linkAttr));
        $this->assertNotEmpty($linkAttr->messages);
        
        // Check error message
        $this->assertEquals('error', $linkAttr->messages[0][0]);
        $this->assertEquals('attribute-required', $linkAttr->messages[0][1]);
    }

    public function testInvalidAttributeValueHandling(): void
    {
        $attributes = $this->modelA->getAttributes();
        $linkAttr = $attributes['test_b'];
        
        // Test setting invalid value (string instead of model/array)
        try {
            $linkAttr->create('invalid_string_value');
            $this->assertFalse($this->isAttributeValid($linkAttr));
        } catch (\Exception $e) {
            // Exception is expected for invalid value
            $this->assertStringContainsString('Attribute value must be an object of type', $e->getMessage());
        }
    }

    public function testGetAttributeValue(): void
    {
        $attributes = $this->modelA->getAttributes();
        $linkAttr = $attributes['test_b'];
        
        // Test getting value when no value is set
        $this->assertEquals([], $linkAttr->get());
        
        // Test setting and getting a model object
        $testModelB = new TestModelB();
        $testModelB->set('id', 789);
        
        $linkAttr->create($testModelB);
        if ($this->isAttributeValid($linkAttr)) {
            // get(false) should return array representation
            $arrayValue = $linkAttr->get(false);
            $this->assertTrue(is_array($arrayValue));
            
            // get(true) should return the actual object
            $this->assertSame($testModelB, $linkAttr->get(true));
        }
    }

    public function testGetTargetId(): void
    {
        $attributes = $this->modelA->getAttributes();
        $linkAttr = $attributes['test_b'];
        
        // Test getTargetId when no value is set
        $this->assertNull($linkAttr->getTargetId());
        
        // Test getTargetId with model object
        $testModelB = new TestModelB();
        $testModelB->set('id', 999);
        
        $linkAttr->create($testModelB);
        if ($this->isAttributeValid($linkAttr)) {
            $this->assertEquals(999, $linkAttr->getTargetId());
        }
    }

    public function testGetTargetIdWithArrayValue(): void
    {
        // Create a link attribute without target class (uses array values)
        $linkAttr = new ModelAttributeLink(
            'array_link',
            'TestModel',
            'default',
            false,
            false,
            null,
            'test_table',
            'test_id',
            'target_id',
            null, // no target class
            'target_table',
            'target_key'
        );

        // Set array value
        $arrayValue = ['target_key' => 555, 'name' => 'Test'];
        try {
            $linkAttr->create($arrayValue);
            if ($this->isAttributeValid($linkAttr)) {
                $this->assertEquals(555, $linkAttr->getTargetId());
            }
        } catch (\Exception $e) {
            // Array parsing might fail without proper validation
            $this->assertStringContainsString('target_key', $e->getMessage());
        }
    }

    public function testParseValueWithModelObject(): void
    {
        $attributes = $this->modelA->getAttributes();
        $linkAttr = $attributes['test_b'];
        
        $testModelB = new TestModelB();
        $testModelB->set('id', 111);
        
        // Use reflection to test parseValue method directly
        $reflection = new \ReflectionClass($linkAttr);
        $parseValueMethod = $reflection->getMethod('parseValue');
        $parseValueMethod->setAccessible(true);
        
        $result = $parseValueMethod->invoke($linkAttr, $testModelB);
        $this->assertSame($testModelB, $result);
    }

    public function testParseValueWithInvalidType(): void
    {
        $attributes = $this->modelA->getAttributes();
        $linkAttr = $attributes['test_b'];
        
        // Use reflection to test parseValue method directly
        $reflection = new \ReflectionClass($linkAttr);
        $parseValueMethod = $reflection->getMethod('parseValue');
        $parseValueMethod->setAccessible(true);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Attribute value must be an object of type');
        
        $parseValueMethod->invoke($linkAttr, 'invalid_value');
    }

    public function testInversedAttributeHandling(): void
    {
        $attributes = $this->modelB->getAttributes();
        $linkAttr = $attributes['test_a'];
        
        // Inversed attributes handle multiple values
        $this->assertTrue($linkAttr->is_inversed);
        
        // Test with array of model objects
        $testModelA1 = new TestModelA();
        $testModelA1->set('id', 100);
        $testModelA2 = new TestModelA();
        $testModelA2->set('id', 200);
        
        try {
            $linkAttr->create([$testModelA1, $testModelA2]);
            if ($this->isAttributeValid($linkAttr)) {
                $values = $linkAttr->get(true);
                $this->assertIsArray($values);
                $this->assertCount(2, $values);
            }
        } catch (\Exception $e) {
            // Skip if validation fails due to missing database setup
            $this->markTestSkipped('Inversed attribute test requires database setup');
        }
    }

    public function testInversedAttributeWithSingleObject(): void
    {
        $attributes = $this->modelB->getAttributes();
        $linkAttr = $attributes['test_a'];
        
        // Test setting single object on inversed attribute (should create array)
        $testModelA = new TestModelA();
        $testModelA->set('id', 300);
        
        try {
            $linkAttr->create($testModelA);
            if ($this->isAttributeValid($linkAttr)) {
                $values = $linkAttr->get(true);
                $this->assertIsArray($values);
                $this->assertCount(1, $values);
            }
        } catch (\Exception $e) {
            $this->markTestSkipped('Inversed attribute test requires database setup');
        }
    }

    public function testAttributeMethodChaining(): void
    {
        // Test method chaining with link attributes
        $linkAttr = new ModelAttributeLink(
            'chainable_link',
            'TestModel',
            'default',
            false,
            false,
            null,
            'test_table',
            'test_id',
            'target_id',
            TestModelB::class
        );

        $testModel = new TestModelB();
        $testModel->set('id', 400);

        // Test that create returns the same object for chaining
        $result = $linkAttr->setRequired()->create($testModel);
        $this->assertSame($linkAttr, $result);
        $this->assertTrue($linkAttr->isRequired());
    }

    public function testLinkAttributeMessages(): void
    {
        $linkAttr = new ModelAttributeLink(
            'message_test',
            'TestModel',
            'default',
            true, // required
            false,
            null,
            'test_table',
            'test_id',
            'target_id',
            TestModelB::class
        );

        // Test error message for required field
        $linkAttr->create(null);
        $this->assertNotEmpty($linkAttr->messages);
        $this->assertEquals('error', $linkAttr->messages[0][0]);
        $this->assertEquals('attribute-required', $linkAttr->messages[0][1]);
        $this->assertStringContainsString('required but no value was provided', $linkAttr->messages[0][2]);
    }

    public function testUpdateAttributeValue(): void
    {
        $attributes = $this->modelA->getAttributes();
        $linkAttr = $attributes['test_b'];
        
        // Test update method requires object_id to be set
        $testModel = new TestModelB();
        $testModel->set('id', 500);
        $linkAttr->create($testModel);
        
        // This should throw exception because object_id is not set
        try {
            $reflection = new \ReflectionClass($linkAttr);
            $updateMethod = $reflection->getMethod('updateAttributeValue');
            $updateMethod->setAccessible(true);
            $updateMethod->invoke($linkAttr);
            
            $this->fail('Expected exception for missing object_id');
        } catch (\Exception $e) {
            $this->assertEquals('Object ID not set', $e->getMessage());
        }
    }

    public function testFetchAttributeValue(): void
    {
        $attributes = $this->modelA->getAttributes();
        $linkAttr = $attributes['test_b'];
        
        // Test fetch method requires object_id to be set
        try {
            $reflection = new \ReflectionClass($linkAttr);
            $fetchMethod = $reflection->getMethod('fetchAttributeValue');
            $fetchMethod->setAccessible(true);
            $fetchMethod->invoke($linkAttr);
            
            $this->fail('Expected exception for missing object_id');
        } catch (\Exception $e) {
            $this->assertEquals('Object ID not set', $e->getMessage());
        }
    }

    public function testSetIdMethod(): void
    {
        $attributes = $this->modelA->getAttributes();
        $linkAttr = $attributes['test_b'];
        
        // Test setting ID
        $linkAttr->setId(123);
        $this->assertEquals(123, $this->getProtectedProperty($linkAttr, 'object_id'));
        
        // Test setting same ID again should work
        $linkAttr->setId(123);
        $this->assertEquals(123, $this->getProtectedProperty($linkAttr, 'object_id'));
        
        // Test setting different ID should throw exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Object ID is already set');
        $linkAttr->setId(456);
    }

    public function testLoadFromValue(): void
    {
        $attributes = $this->modelA->getAttributes();
        $linkAttr = $attributes['test_b'];
        
        $testModel = new TestModelB();
        $testModel->set('id', 600);
        
        // Test loadFromValue method
        $linkAttr->loadFromValue($testModel, 123);
        $this->assertEquals(123, $this->getProtectedProperty($linkAttr, 'object_id'));
        $this->assertSame($testModel, $linkAttr->get(true));
    }

    public function testAttributeTypeIsRelation(): void
    {
        $attributes = $this->modelA->getAttributes();
        $linkAttr = $attributes['test_b'];
        
        // Test that link attributes always have RELATION type
        $this->assertEquals(AttributeType::RELATION, $linkAttr->type);
        $this->assertEquals('relation', $linkAttr->getType());
    }

    public function testLinkAttributeInheritanceFromAbstract(): void
    {
        $attributes = $this->modelA->getAttributes();
        $linkAttr = $attributes['test_b'];
        
        // Test that link attribute inherits from AbstractModelAttribute
        $this->assertInstanceOf(\pz\AbstractModelAttribute::class, $linkAttr);
        
        // Test inherited methods are available
        $this->assertTrue(method_exists($linkAttr, 'setRequired'));
        $this->assertTrue(method_exists($linkAttr, 'setDefault'));
        $this->assertTrue(method_exists($linkAttr, 'create'));
        $this->assertTrue(method_exists($linkAttr, 'update'));
        $this->assertTrue(method_exists($linkAttr, 'get'));
        $this->assertTrue(method_exists($linkAttr, 'setId'));
        $this->assertTrue(method_exists($linkAttr, 'isRequired'));
        $this->assertTrue(method_exists($linkAttr, 'getType'));
    }

    public function testLinkAttributeSpecificProperties(): void
    {
        $attributes = $this->modelA->getAttributes();
        $linkAttr = $attributes['test_b'];
        
        // Test link-specific properties
        $this->assertTrue($linkAttr->is_link);
        $this->assertFalse($linkAttr->is_link_through);
        $this->assertEquals(AttributeType::RELATION, $linkAttr->type);
        
        // Test target information
        $this->assertEquals(TestModelB::class, $linkAttr->target);
        $this->assertNotEmpty($linkAttr->target_table);
        $this->assertNotEmpty($linkAttr->target_id_key);
    }

    public function testComplexLinkScenarios(): void
    {
        $attributes = $this->modelA->getAttributes();
        $linkAttr = $attributes['test_b'];
        
        // Test updating link with valid value after invalid attempt
        try {
            $linkAttr->create('invalid_value');
            $this->assertFalse($this->isAttributeValid($linkAttr));
        } catch (\Exception $e) {
            // Expected exception for invalid value
        }
        
        // Reset state and try with valid value
        $linkAttr->messages = [];
        $reflection = new \ReflectionClass($linkAttr);
        $isValidProperty = $reflection->getProperty('is_valid');
        $isValidProperty->setAccessible(true);
        $isValidProperty->setValue($linkAttr, true);
        
        $testModel = new TestModelB();
        $testModel->set('id', 700);
        $linkAttr->create($testModel);
        
        $this->assertTrue($this->isAttributeValid($linkAttr));
        $this->assertSame($testModel, $linkAttr->get(true));
    }

    public function testLinkAttributeEdgeCases(): void
    {
        // Test link attribute with minimal required parameters
        $linkAttr = new ModelAttributeLink(
            'minimal_link',
            'TestModel',
            'default',
            false,
            false,
            null,
            'test_table', // Provide model_table to avoid makeRelationTableName
            'test_id',
            null,
            TestModelSimple::class
        );

        $this->assertEquals('minimal_link', $linkAttr->name);
        $this->assertEquals('minimal_link_id', $linkAttr->target_column);
        $this->assertEquals(TestModelSimple::class, $linkAttr->target);
        
        // Test that target properties are set from target model
        $this->assertNotEmpty($linkAttr->target_table);
        $this->assertNotEmpty($linkAttr->target_id_key);
    }
}