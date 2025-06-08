<?php

declare(strict_types=1);

use pz\Enums\Routing\Method;
use pz\Routing\Request;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class requestTest extends TestCase
{

    public static function validRequestSignaturesProvider(): array
    {
        return [
            [
                'method' => Method::GET,
                'data' => [
                    'key1' => 'value1',
                    'key2' => 'value2',
                    'key3' => 'value3',
                ],
                'action' => 'testAction',
            ],
            [
                'method' => Method::POST,
                'data' => [
                    'key1' => 'value1',
                    'key2' => 'value2',
                    'key3' => 'value3',
                ],
                'action' => 'testAction',
            ],
            [
                'method' => Method::PUT,
                'data' => [
                    'key1' => 'value1',
                    'key2' => 'value2',
                    'key3' => 'value3',
                ],
                'action' => 'testAction',
            ],
            [
                'method' => Method::DELETE,
                'data' => [
                    'key1' => 'value1',
                    'key2' => 'value2',
                    'key3' => 'value3',
                ],
                'action' => 'testAction',
            ],
            [
                'method' => Method::MODEL,
                'data' => [
                    'key1' => 'value1',
                    'key2' => 'value2',
                    'key3' => 'value3',
                ],
                'action' => 'testAction',
            ],
        ];
    }

    #[DataProvider('validRequestSignaturesProvider')]
    public function testRequestCreation(Method $method, array $data, string $action): void
    {
        $request = new Request($method, $data, $action);

        $this->assertInstanceOf(Request::class, $request);
        $this->assertSame($method, $request->getMethod());
        $this->assertSame($data, $request->data());
        $this->assertTrue($request->hasData('key1'));
        $this->assertFalse($request->hasData('non_existent_key'));
        $this->assertSame($action, $request->getAction());
        $this->assertSame($data['key1'], $request->getData('key1'));
        $this->assertNull($request->getData('non_existent_key'));
        $this->assertNull($request->successLocation());
        $this->assertNull($request->errorLocation());
    }

    public function testSetDataCorrectlyMergesData(): void
    {
        $request = new Request(Method::GET, ['key1' => 'value1'], 'testAction');
        $request->setData(['key2' => 'value2', 'key3' => 'value3']);

        $this->assertSame('value1', $request->getData('key1'));
        $this->assertSame('value2', $request->getData('key2'));
        $this->assertSame('value3', $request->getData('key3'));
    }

    public function testSetDataOverwritesExistingData(): void
    {
        $request = new Request(Method::GET, ['key1' => 'value1'], 'testAction');
        $request->setData(['key1' => 'newValue']);

        $this->assertSame('newValue', $request->getData('key1'));
    }

    public function testAddDataAddsNewData(): void
    {
        $request = new Request(Method::GET, ['key0' => 'value0'], 'testAction');
        $request->addData('key1', 'value1');

        $this->assertSame('value1', $request->getData('key1'));
    }

    public function testAddDataOverwritesExistingData(): void
    {
        $request = new Request(Method::GET, ['key1' => 'value1'], 'testAction');
        $request->addData('key1', 'newValue');

        $this->assertSame('newValue', $request->getData('key1'));
    }

    public function testGetDataReturnsNullForNonExistentKey(): void
    {
        $request = new Request(Method::GET, ['key1' => 'value1'], 'testAction');

        $this->assertNull($request->getData('non_existent_key'));
    }

    public function testHasDataReturnsTrueForExistingKey(): void
    {
        $request = new Request(Method::GET, ['key1' => 'value1'], 'testAction');

        $this->assertTrue($request->hasData('key1'));
    }

    public function testHasDataReturnsFalseForNonExistentKey(): void
    {
        $request = new Request(Method::GET, ['key1' => 'value1'], 'testAction');

        $this->assertFalse($request->hasData('non_existent_key'));
    }

    public function testHasDataReturnsFalseForEmptyValue(): void
    {
        $request = new Request(Method::GET, ['key1' => ''], 'testAction');

        $this->assertFalse($request->hasData('key1'));
    }

    public function testHasOrSetDataSetsDataIfNotExists(): void
    {
        $request = new Request(Method::GET, [], 'testAction');
        $request->hasOrSetData('key1', 'value1');

        $this->assertSame('value1', $request->getData('key1'));
    }

    public function testHasOrSetDataDoesNotSetDataIfExists(): void
    {
        $request = new Request(Method::GET, ['key1' => 'value1'], 'testAction');
        $request->hasOrSetData('key1', 'newValue');

        $this->assertSame('value1', $request->getData('key1'));
    }

    public function testSetActionSetsAction(): void
    {
        $request = new Request(Method::GET, [], 'testAction');
        $request->setAction('newAction');

        $this->assertSame('newAction', $request->getAction());
    }

    public function testSetMethodSetsMethod(): void
    {
        $request = new Request(Method::GET, [], 'testAction');
        $request->setMethod(Method::POST);

        $this->assertSame(Method::POST, $request->getMethod());
    }

    public function testSetSuccessLocationSetsLocation(): void
    {
        $request = new Request(Method::GET, [], 'testAction');
        $request->onSuccess('successLocation');

        $this->assertSame('successLocation', $request->successLocation());
    }

    public function testSetErrorLocationSetsLocation(): void
    {
        $request = new Request(Method::GET, [], 'testAction');
        $request->onError('errorLocation');

        $this->assertSame('errorLocation', $request->errorLocation());
    }

    public function testGetFileReturnsNullForNonExistentKey(): void
    {
        $request = new Request(Method::GET, [], 'testAction');

        $this->assertNull($request->getFile('non_existent_key'));
    }

    public function testGetFileReturnsFileForExistentKey(): void
    {
        $request = new Request(Method::GET, [], 'testAction');
        $_FILES['file1'] = [
            'name' => 'test.txt',
            'type' => 'text/plain',
            'tmp_name' => '/tmp/phpYzdqkD',
            'error' => 0,
            'size' => 123,
        ];

        $this->assertSame($_FILES['file1'], $request->getFile('file1'));
    }

    public function testMergeData(): void {
        $this->markTestIncomplete('This test has not been implemented yet.');
    }

}
