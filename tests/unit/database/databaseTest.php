<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DataProvider;

use pz\database\Database;

final class databaseTest extends TestCase
{
    private Database $db;

    public function testDatabaseConnectionIsWorking(): void
    {
        $database = new Database($_ENV['TEST_DB_NAME']);
        $this->assertInstanceOf(Database::class, $database);
    }

    public function testNonExistingDatabaseRaisesAnException(): void
    {
        $this->expectException(Exception::class);
        $database = new Database('azertftyguijouyvcghfxdfctuyguio');
    }

    public function testInsertQueriesWorkAndReturnTheLastInsertedId()
    {
        $database = $this->db;

        $query = $database->execute('INSERT INTO nonces (user_id, nonce, expiration) VALUES (?, ?, ?)', 'iss', 1, 'test_nonce', '2021-01-01 00:00:00');

        $this->assertIsInt($query);

        return $query;
    }

    #[Depends('testInsertQueriesWorkAndReturnTheLastInsertedId')]
    public function testInsertQueriesAddedALineInTheDatabase(int $lastInsertedId): void
    {
        $database = $this->db;

        $query = $database->execute('SELECT * FROM nonces WHERE id = ?', 'i', $lastInsertedId);

        $query_results = $query->fetch_all(MYSQLI_ASSOC);

        $this->assertIsArray($query_results);
        $this->assertNotEmpty($query);
    }

    #[Depends('testInsertQueriesWorkAndReturnTheLastInsertedId')]
    public function testNonAllowedParamTypesRaiseAnException(int $lastInsertedId): void
    {
        $this->expectException(Exception::class);
        $database = $this->db;
        $database->execute('SELECT * FROM nonces WHERE id = ?', 'q', $lastInsertedId);
    }

    #[Depends('testInsertQueriesWorkAndReturnTheLastInsertedId')]
    public function testSupernumeraryParamsTypesRaiseAnException(int $lastInsertedId): void
    {
        $this->expectException(Exception::class);
        $database = $this->db;
        $database->execute('SELECT * FROM nonces WHERE id = ?', 'ii', $lastInsertedId);
    }

    #[Depends('testInsertQueriesWorkAndReturnTheLastInsertedId')]
    public function testLackingParamsTypesRaiseAnException(int $lastInsertedId): void
    {
        $this->expectException(Exception::class);
        $database = $this->db;
        $database->execute('SELECT * FROM nonces WHERE id = ? AND nonce = ?', 'i', $lastInsertedId, 'test_nonce');
    }

    #[Depends('testInsertQueriesWorkAndReturnTheLastInsertedId')]
    public function testMissingParamsTypesRaiseAnException(int $lastInsertedId): void
    {
        $this->expectException(Exception::class);
        $database = $this->db;
        $database->execute('SELECT * FROM nonces WHERE id = ?');
    }

    #[Depends('testInsertQueriesWorkAndReturnTheLastInsertedId')]
    public function testSurnumerairesParamsRaiseAnException(int $lastInsertedId): void
    {
        $this->expectException(Exception::class);
        $database = $this->db;
        $database->execute('SELECT * FROM nonces WHERE id = ?', 'i', $lastInsertedId, 2);
    }

    #[Depends('testInsertQueriesWorkAndReturnTheLastInsertedId')]
    public function testMissingParamsRaiseAnException(int $lastInsertedId): void
    {
        $this->expectException(Exception::class);
        $database = $this->db;
        $database->execute('SELECT * FROM nonces WHERE id = ?');
    }


    #[Depends('testInsertQueriesWorkAndReturnTheLastInsertedId')]
    public function testParamsPassedAsArrayRaiseAnException(int $lastInsertedId): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/^Array parameters are not supported on SQL queries/');

        $database = $this->db;
        $database->execute('SELECT * FROM nonces WHERE id = ? AND user_id = ?', 'ii', [1, 2]);
    }

    #[Depends('testInsertQueriesWorkAndReturnTheLastInsertedId')]
    public function testUpdateQueriesWorkAndReturnTheNumberOfAffectedRows(int $lastInsertedId): void
    {
        $database = $this->db;

        $query = $database->execute('UPDATE nonces SET nonce = ? WHERE id = ?', 'si', 'test_nonce_updated', $lastInsertedId);

        $this->assertIsInt($query);
        $this->assertEquals(1, $query);
    }

    #[Depends('testInsertQueriesWorkAndReturnTheLastInsertedId')]
    public function testDeleteQueriesWorkAndReturnTheNumberOfAffectedRows(int $lastInsertedId): void
    {
        $database = $this->db;

        $query = $database->execute('DELETE FROM nonces WHERE id = ?', 'i', $lastInsertedId);

        $this->assertIsInt($query);
        $this->assertEquals(1, $query);
    }

    public function testExportDatabaseCreatesAFile(): void
    {
        $database = $this->db;

        $filename = $database->exportDatabase(false, false, false);
        $file = __DIR__ . '/../../../database/backups/' . $filename;

        $this->assertIsString($filename);
        $this->assertStringEndsWith('.sql', $filename);
        $this->assertFileExists($file);

        unlink($file);
    }

    public function testInsertMethodWorksWithOneElement() {
        $database = $this->db;

        $insert_result = $database->insert(
            'nonces',
            ['user_id', 'nonce', 'expiration'], 
            ['1', 'evzudve', '2021-01-01 00:00:00'],
        );

        $this->assertIsInt($insert_result);
    }

    public function testInsertMethodWorksWithMultipleElements() {
        $database = $this->db;

        $insert_result = $database->insert(
            'nonces',
            ['user_id', 'nonce', 'expiration'], 
            [
                ['1', 'evzudve', '2021-01-01 00:00:00'],
                ['1', 'evzudve', '2021-01-01 00:00:00'],
                ['1', 'evzudve', '2021-01-01 00:00:00'],
            ]
        );

        $this->assertIsInt($insert_result);
    }

    public function testInsertMethodRaiseAnExceptionIfElementsHaveVariousElementsSize() {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/^Wrong number of arguments in insert/');

        $database = $this->db;

        $database->insert(
            'nonces',
            ['user_id', 'nonce', 'expiration'], 
            [
                ['1', 'evzudve', '2021-01-01 00:00:00'],
                ['1', 'evzudve'],
                ['1', 'evzudve', '2021-01-01 00:00:00'],
            ]
        );
    }

    protected function setUp(): void
    {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__, '../../../.env');
        $dotenv->load();
        $this->db = new Database($_ENV['TEST_DB_NAME']);
    }
}