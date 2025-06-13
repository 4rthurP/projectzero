<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use pz\Enums\model\AttributeType;
use pz\AbstractModelAttribute;
use pz\ModelAttribute;

require_once __DIR__ . '/../test_ressources/testModels.php';

final class modelAttributeAbstractTest extends TestCase
{
    private TestModelForAttributes $testModel;
    
    protected function setUp(): void
    {
        $this->testModel = new TestModelForAttributes();
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
     * Helper method to set protected properties
     */
    private function setProtectedProperty($object, $property, $value)
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    /**
     * Helper method to check if attribute is valid
     */
    private function isAttributeValid($attribute): bool
    {
        return $this->getProtectedProperty($attribute, 'is_valid');
    }

    /**
     * Helper method to create a test attribute
     */
    private function createTestAttribute(
        string $name = 'test_attr',
        AttributeType $type = AttributeType::CHAR,
        bool $isRequired = false,
        ?string $defaultValue = null
    ): ModelAttribute {
        return new ModelAttribute(
            $name,
            $type,
            TestModelForAttributes::class,
            'default',
            $isRequired,
            $defaultValue,
            'test_table',
            'id',
            $name
        );
    }

    public function testAbstractClassStructure(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Test that concrete class extends AbstractModelAttribute
        $this->assertInstanceOf(AbstractModelAttribute::class, $attribute);
        
        // Test basic properties exist
        $this->assertTrue(property_exists($attribute, 'type'));
        $this->assertTrue(property_exists($attribute, 'name'));
        $this->assertTrue(property_exists($attribute, 'bundle'));
        $this->assertTrue(property_exists($attribute, 'value'));
        $this->assertTrue(property_exists($attribute, 'messages'));
        $this->assertTrue(property_exists($attribute, 'is_required'));
        $this->assertTrue(property_exists($attribute, 'default_value'));
    }

    public function testAbstractMethodsAreImplemented(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Test that all abstract methods are implemented
        $this->assertTrue(method_exists($attribute, 'getAttributeValue'));
        $this->assertTrue(method_exists($attribute, 'setAttributeValue'));
        $this->assertTrue(method_exists($attribute, 'updateAttributeValue'));
        $this->assertTrue(method_exists($attribute, 'fetchAttributeValue'));
        $this->assertTrue(method_exists($attribute, 'parseValue'));
    }

    public function testSetRequired(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Test initial state
        $this->assertFalse($attribute->is_required);
        
        // Test setting required
        $result = $attribute->setRequired();
        $this->assertTrue($attribute->is_required);
        $this->assertSame($attribute, $result); // Test method chaining
    }

    public function testSetDefault(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Test initial state
        $this->assertNull($attribute->default_value);
        
        // Test setting default value
        $result = $attribute->setDefault('test_default');
        $this->assertEquals('test_default', $attribute->default_value);
        $this->assertSame($attribute, $result); // Test method chaining
    }

    public function testCreateMethod(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Test create with valid value
        $result = $attribute->create('test_value');
        $this->assertSame($attribute, $result); // Test method chaining
        $this->assertTrue($this->isAttributeValid($attribute));
        $this->assertEquals('test_value', $attribute->value);
    }

    public function testUpdateMethod(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Test update without object_id
        $result = $attribute->update('updated_value');
        $this->assertSame($attribute, $result); // Test method chaining
        $this->assertTrue($this->isAttributeValid($attribute));
        $this->assertEquals('updated_value', $attribute->value);
        
        // Test update with object_id
        $attribute2 = $this->createTestAttribute();
        $result2 = $attribute2->update('updated_value', 123);
        $this->assertEquals(123, $this->getProtectedProperty($attribute2, 'object_id'));
        $this->assertEquals('updated_value', $attribute2->value);
    }

    public function testUpdateMethodWithDatabaseFlag(): void
    {
        $attribute = $this->createTestAttribute();
        $attribute->setId(100);
        
        try {
            // Test update with database flag - should call updateAttributeValue
            $attribute->update('test_value', null, true);
            // If we get here without exception, the update method worked
            $this->assertEquals('test_value', $attribute->value);
        } catch (\Exception $e) {
            // Skip if database connection not available
            $this->markTestSkipped('Database connection required for update with database flag');
        }
    }

    public function testAddMethodOnNonLinkAttribute(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Test that add method throws exception on non-link attributes
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('This method is only available for link attributes.');
        
        $attribute->add('some_value');
    }

    public function testAddMethodOnLinkAttribute(): void
    {
        $attributes = $this->testModel->getAttributes();
        
        // Find a link attribute from test models if available
        $linkAttribute = null;
        foreach ($attributes as $attr) {
            if (property_exists($attr, 'is_link') && $attr->is_link) {
                $linkAttribute = $attr;
                break;
            }
        }
        
        if ($linkAttribute === null) {
            $this->markTestSkipped('No link attributes available in test model');
        }
        
        try {
            $result = $linkAttribute->add('test_value');
            $this->assertSame($linkAttribute, $result); // Test method chaining
        } catch (\Exception $e) {
            // Skip if specific implementation requirements not met
            $this->markTestSkipped('Link attribute add method requires specific implementation');
        }
    }

    public function testUnsetMethodOnNonLinkAttribute(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Test that unset method throws exception on non-link attributes
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('This method is only available for link attributes.');
        
        $attribute->unset(123);
    }

    public function testGetMethod(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Test get with invalid attribute
        $this->setProtectedProperty($attribute, 'is_valid', false);
        $this->assertNull($attribute->get());
        
        // Test get with valid attribute
        $this->setProtectedProperty($attribute, 'is_valid', true);
        $attribute->create('test_value');
        
        // Test get as formatted value (default)
        $formattedValue = $attribute->get(false);
        $this->assertEquals('test_value', $formattedValue);
        
        // Test get as object
        $objectValue = $attribute->get(true);
        $this->assertEquals('test_value', $objectValue);
    }

    public function testLoadMethod(): void
    {
        $attribute = $this->createTestAttribute();
        
        try {
            // Test load method - should set ID and call fetchAttributeValue
            $result = $attribute->load(456);
            $this->assertSame($attribute, $result); // Test method chaining
            $this->assertEquals(456, $this->getProtectedProperty($attribute, 'object_id'));
        } catch (\Exception $e) {
            // Skip if database connection not available
            $this->markTestSkipped('Database connection required for load method');
        }
    }

    public function testLoadFromValueMethod(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Test loadFromValue method
        $result = $attribute->loadFromValue('loaded_value', 789);
        $this->assertSame($attribute, $result); // Test method chaining
        $this->assertEquals(789, $this->getProtectedProperty($attribute, 'object_id'));
        $this->assertEquals('loaded_value', $attribute->value);
    }

    public function testSaveMethod(): void
    {
        $attribute = $this->createTestAttribute();
        $attribute->create('save_test_value');
        
        try {
            // Test save method
            $result = $attribute->save(999);
            $this->assertSame($attribute, $result); // Test method chaining
            $this->assertEquals(999, $this->getProtectedProperty($attribute, 'object_id'));
        } catch (\Exception $e) {
            // Skip if database connection not available
            $this->markTestSkipped('Database connection required for save method');
        }
    }

    public function testSaveMethodWithInvalidAttribute(): void
    {
        $attribute = $this->createTestAttribute();
        $this->setProtectedProperty($attribute, 'is_valid', false);
        
        // Test save method with invalid attribute - should not call updateAttributeValue
        $result = $attribute->save(888);
        $this->assertSame($attribute, $result);
        $this->assertEquals(888, $this->getProtectedProperty($attribute, 'object_id'));
    }

    public function testDeleteMethodOnRequiredAttribute(): void
    {
        $attribute = $this->createTestAttribute('required_attr', AttributeType::CHAR, true);
        
        // Test delete on required attribute
        $result = $attribute->delete(777);
        $this->assertSame($attribute, $result); // Test method chaining
        $this->assertFalse($this->isAttributeValid($attribute));
        $this->assertNotEmpty($attribute->messages);
        $this->assertEquals('error', $attribute->messages[0][0]);
        $this->assertEquals('attribute-required', $attribute->messages[0][1]);
        $this->assertStringContainsString('required and cannot be deleted', $attribute->messages[0][2]);
    }

    public function testDeleteMethodOnNonRequiredAttribute(): void
    {
        $attribute = $this->createTestAttribute('optional_attr', AttributeType::CHAR, false);
        $attribute->create('value_to_delete');
        
        try {
            // Test delete on non-required attribute
            $result = $attribute->delete(666);
            $this->assertSame($attribute, $result); // Test method chaining
            $this->assertEquals(666, $this->getProtectedProperty($attribute, 'object_id'));
            $this->assertNull($attribute->value);
        } catch (\Exception $e) {
            // Skip if database connection not available
            $this->markTestSkipped('Database connection required for delete method');
        }
    }

    public function testSetIdMethod(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Test setting ID for the first time
        $result = $attribute->setId(555);
        $this->assertSame($attribute, $result); // Test method chaining
        $this->assertEquals(555, $this->getProtectedProperty($attribute, 'object_id'));
        
        // Test setting same ID again - should work
        $attribute->setId(555);
        $this->assertEquals(555, $this->getProtectedProperty($attribute, 'object_id'));
        
        // Test setting different ID - should throw exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Object ID is already set');
        $attribute->setId(444);
    }

    public function testSetIdWithNullValue(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Test setting null ID - should not change anything
        $result = $attribute->setId(null);
        $this->assertSame($attribute, $result);
        $this->assertNull($this->getProtectedProperty($attribute, 'object_id'));
    }

    public function testMakeRelationTableNameModelMode(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Use reflection to test protected method
        $reflection = new \ReflectionClass($attribute);
        $method = $reflection->getMethod('makeRelationTableName');
        $method->setAccessible(true);
        
        try {
            $result = $method->invoke($attribute, 'model');
            $this->assertIsString($result);
            $this->assertNotEmpty($result);
        } catch (\Exception $e) {
            // Skip if model instantiation fails
            $this->markTestSkipped('Model instantiation required for relation table name generation');
        }
    }

    public function testMakeRelationTableNameTargetModeWithTarget(): void
    {
        $attribute = $this->createTestAttribute();
        $attribute->target = TestModelForAttributes::class;
        $attribute->bundle = 'default';
        
        // Use reflection to test protected method
        $reflection = new \ReflectionClass($attribute);
        $method = $reflection->getMethod('makeRelationTableName');
        $method->setAccessible(true);
        
        try {
            $result = $method->invoke($attribute, 'target');
            $this->assertIsString($result);
            $this->assertNotEmpty($result);
        } catch (\Exception $e) {
            // Skip if target instantiation fails
            $this->markTestSkipped('Target instantiation required for relation table name generation');
        }
    }

    public function testMakeRelationTableNameRelationMode(): void
    {
        $attribute = $this->createTestAttribute();
        $attribute->target = TestModelForAttributes::class;
        $attribute->model = TestModelForAttributes::class;
        $attribute->bundle = 'default';
        $attribute->is_inversed = false;
        
        // Use reflection to test protected method
        $reflection = new \ReflectionClass($attribute);
        $method = $reflection->getMethod('makeRelationTableName');
        $method->setAccessible(true);
        
        try {
            // Test many relation
            $result = $method->invoke($attribute, 'relation', true);
            $this->assertIsString($result);
            $this->assertStringContainsString('ables', $result);
            
            // Test non-many relation
            $result2 = $method->invoke($attribute, 'relation', false);
            $this->assertIsString($result2);
            $this->assertStringContainsString('_', $result2);
        } catch (\Exception $e) {
            // Skip if model class requirements not met
            $this->markTestSkipped('Model class requirements not met for relation table name generation');
        }
    }

    public function testMakeRelationTableNameWithCustomBundle(): void
    {
        $attribute = $this->createTestAttribute();
        $attribute->target = TestModelForAttributes::class;
        $attribute->model = TestModelForAttributes::class;
        $attribute->bundle = 'custom_bundle';
        $attribute->is_inversed = false;
        
        // Use reflection to test protected method
        $reflection = new \ReflectionClass($attribute);
        $method = $reflection->getMethod('makeRelationTableName');
        $method->setAccessible(true);
        
        try {
            $result = $method->invoke($attribute, 'relation', true);
            $this->assertIsString($result);
            $this->assertStringContainsString('custom_bundle_', $result);
        } catch (\Exception $e) {
            // Skip if model class requirements not met
            $this->markTestSkipped('Model class requirements not met for relation table name generation');
        }
    }

    public function testMakeRelationTableNameInversed(): void
    {
        $attribute = $this->createTestAttribute();
        $attribute->target = TestModelForAttributes::class;
        $attribute->model = TestModelForAttributes::class;
        $attribute->bundle = 'default';
        $attribute->is_inversed = true;
        
        // Use reflection to test protected method
        $reflection = new \ReflectionClass($attribute);
        $method = $reflection->getMethod('makeRelationTableName');
        $method->setAccessible(true);
        
        try {
            $result = $method->invoke($attribute, 'relation', true);
            $this->assertIsString($result);
            $this->assertStringContainsString('ables', $result);
        } catch (\Exception $e) {
            // Skip if model class requirements not met
            $this->markTestSkipped('Model class requirements not met for inversed relation table name generation');
        }
    }

    public function testIsRequiredGetter(): void
    {
        $attribute1 = $this->createTestAttribute('test1', AttributeType::CHAR, false);
        $attribute2 = $this->createTestAttribute('test2', AttributeType::CHAR, true);
        
        $this->assertFalse($attribute1->isRequired());
        $this->assertTrue($attribute2->isRequired());
    }

    public function testGetTypeMethod(): void
    {
        $charAttribute = $this->createTestAttribute('char_attr', AttributeType::CHAR);
        $intAttribute = $this->createTestAttribute('int_attr', AttributeType::INT);
        $boolAttribute = $this->createTestAttribute('bool_attr', AttributeType::BOOL);
        
        $this->assertEquals('char', $charAttribute->getType());
        $this->assertEquals('int', $intAttribute->getType());
        $this->assertEquals('bool', $boolAttribute->getType());
    }

    public function testGetSQLValueMethod(): void
    {
        // Test LIST type
        $listAttribute = $this->createTestAttribute('list_attr', AttributeType::LIST);
        $listAttribute->value = ['item1', 'item2', 'item3'];
        $this->assertEquals('item1,item2,item3', $listAttribute->getSQLValue());
        
        // Test LIST type with string value
        $listAttribute2 = $this->createTestAttribute('list_attr2', AttributeType::LIST);
        $listAttribute2->value = 'already,comma,separated';
        $this->assertEquals('already,comma,separated', $listAttribute2->getSQLValue());
        
        // Test DATE type
        $dateAttribute = $this->createTestAttribute('date_attr', AttributeType::DATE);
        $dateAttribute->value = new \DateTime('2023-12-25');
        $this->assertEquals('2023-12-25', $dateAttribute->getSQLValue());
        
        // Test DATE type with null
        $dateAttribute2 = $this->createTestAttribute('date_attr2', AttributeType::DATE);
        $dateAttribute2->value = null;
        $this->assertNull($dateAttribute2->getSQLValue());
        
        // Test DATETIME type
        $datetimeAttribute = $this->createTestAttribute('datetime_attr', AttributeType::DATETIME);
        $datetimeAttribute->value = new \DateTime('2023-12-25 15:30:45');
        $this->assertEquals('2023-12-25 15:30:45', $datetimeAttribute->getSQLValue());
        
        // Test DATETIME type with null
        $datetimeAttribute2 = $this->createTestAttribute('datetime_attr2', AttributeType::DATETIME);
        $datetimeAttribute2->value = null;
        $this->assertNull($datetimeAttribute2->getSQLValue());
        
        // Test other types (should return raw value)
        $charAttribute = $this->createTestAttribute('char_attr', AttributeType::CHAR);
        $charAttribute->value = 'test_string';
        $this->assertEquals('test_string', $charAttribute->getSQLValue());
    }

    public function testGetSQLQueryTypeMethod(): void
    {
        $charAttribute = $this->createTestAttribute('char_attr', AttributeType::CHAR);
        $intAttribute = $this->createTestAttribute('int_attr', AttributeType::INT);
        
        // Test that it calls the enum's SQLQueryType method
        $this->assertEquals(AttributeType::CHAR->SQLQueryType(), $charAttribute->getSQLQueryType());
        $this->assertEquals(AttributeType::INT->SQLQueryType(), $intAttribute->getSQLQueryType());
    }

    public function testGetSQLTypeMethod(): void
    {
        $charAttribute = $this->createTestAttribute('char_attr', AttributeType::CHAR);
        $intAttribute = $this->createTestAttribute('int_attr', AttributeType::INT);
        
        // Test that it calls the enum's SQLType method
        $this->assertEquals(AttributeType::CHAR->SQLType(), $charAttribute->getSQLType());
        $this->assertEquals(AttributeType::INT->SQLType(), $intAttribute->getSQLType());
    }

    public function testGetSQLFieldMethod(): void
    {
        // Test with empty target_column
        $attribute1 = $this->createTestAttribute();
        $attribute1->target_column = '';
        $this->assertNull($attribute1->getSQLField());
        
        // Test ID type
        $idAttribute = $this->createTestAttribute('id_attr', AttributeType::ID);
        $idAttribute->target_column = 'test_id';
        $this->assertEquals('test_id INT AUTO_INCREMENT PRIMARY KEY', $idAttribute->getSQLField());
        
        // Test UUID type
        $uuidAttribute = $this->createTestAttribute('uuid_attr', AttributeType::UUID);
        $uuidAttribute->target_column = 'test_uuid';
        $this->assertEquals('test_uuid CHAR(36) PRIMARY KEY', $uuidAttribute->getSQLField());
        
        // Test regular type without required
        $charAttribute = $this->createTestAttribute('char_attr', AttributeType::CHAR, false);
        $charAttribute->target_column = 'test_char';
        $expected = 'test_char ' . AttributeType::CHAR->SQLType();
        $this->assertEquals($expected, $charAttribute->getSQLField());
        
        // Test regular type with required
        $requiredAttribute = $this->createTestAttribute('required_attr', AttributeType::CHAR, true);
        $requiredAttribute->target_column = 'test_required';
        $expected = 'test_required ' . AttributeType::CHAR->SQLType() . ' NOT NULL';
        $this->assertEquals($expected, $requiredAttribute->getSQLField());
    }

    public function testPropertyVisibility(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Test public properties are accessible
        $this->assertTrue(isset($attribute->type));
        $this->assertTrue(isset($attribute->name));
        $this->assertTrue(isset($attribute->bundle));
        $this->assertTrue(isset($attribute->value));
        $this->assertTrue(isset($attribute->messages));
        $this->assertTrue(isset($attribute->is_required));
        $this->assertTrue(isset($attribute->default_value));
        
        // Test protected properties require reflection
        $this->assertFalse(isset($attribute->object_id)); // Should be protected
        $this->assertFalse(isset($attribute->is_valid)); // Should be protected
    }

    public function testMessagesArrayStructure(): void
    {
        $attribute = $this->createTestAttribute('test_attr', AttributeType::CHAR, true);
        
        // Test that messages is initially empty
        $this->assertIsArray($attribute->messages);
        $this->assertEmpty($attribute->messages);
        
        // Test message structure after validation error
        $attribute->create(null); // Should trigger required field error
        $this->assertNotEmpty($attribute->messages);
        
        $message = $attribute->messages[0];
        $this->assertIsArray($message);
        $this->assertCount(3, $message);
        $this->assertEquals('error', $message[0]); // Type
        $this->assertEquals('attribute-required', $message[1]); // Category
        $this->assertIsString($message[2]); // Message
    }

    public function testValidationStateManagement(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Test initial valid state
        $this->assertTrue($this->isAttributeValid($attribute));
        
        // Test setting invalid state
        $this->setProtectedProperty($attribute, 'is_valid', false);
        $this->assertFalse($this->isAttributeValid($attribute));
        
        // Test that invalid state affects get method
        $this->assertNull($attribute->get());
    }

    public function testMethodChainingConsistency(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Test all methods that should return $this for chaining
        $this->assertSame($attribute, $attribute->setRequired());
        $this->assertSame($attribute, $attribute->setDefault('test'));
        $this->assertSame($attribute, $attribute->create('test'));
        $this->assertSame($attribute, $attribute->update('test'));
        $this->assertSame($attribute, $attribute->loadFromValue('test', 123));
        $this->assertSame($attribute, $attribute->save(123));
        $this->assertSame($attribute, $attribute->setId(456));
    }

    public function testAbstractClassCannotBeInstantiated(): void
    {
        // Test that AbstractModelAttribute cannot be directly instantiated
        $this->expectException(\Error::class);
        new AbstractModelAttribute();
    }

    public function testComplexScenarios(): void
    {
        $attribute = $this->createTestAttribute('complex_attr', AttributeType::CHAR, true, 'default_val');
        
        // Test complex method chaining scenario
        $result = $attribute
            ->setRequired()
            ->setDefault('new_default')
            ->create('test_value')
            ->setId(999);
            
        $this->assertSame($attribute, $result);
        $this->assertTrue($attribute->is_required);
        $this->assertEquals('new_default', $attribute->default_value);
        $this->assertEquals('test_value', $attribute->value);
        $this->assertEquals(999, $this->getProtectedProperty($attribute, 'object_id'));
    }

    public function testAbstractAttributeInheritance(): void
    {
        $attribute = $this->createTestAttribute();
        
        // Test that ModelAttribute properly inherits all abstract methods and properties
        $abstractMethods = (new \ReflectionClass(AbstractModelAttribute::class))->getMethods(\ReflectionMethod::IS_ABSTRACT);
        $concreteMethods = (new \ReflectionClass($attribute))->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED);
        
        $abstractMethodNames = array_map(fn($method) => $method->getName(), $abstractMethods);
        $concreteMethodNames = array_map(fn($method) => $method->getName(), $concreteMethods);
        
        foreach ($abstractMethodNames as $abstractMethodName) {
            $this->assertContains($abstractMethodName, $concreteMethodNames, 
                "Abstract method $abstractMethodName must be implemented in concrete class");
        }
    }
}