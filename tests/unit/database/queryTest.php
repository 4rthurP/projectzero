<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use pz\Config;
use pz\database\Database;
use pz\database\Query;
use pz\Enums\database\QueryLink;
use pz\Enums\database\QueryOperator;

final class queryTest extends TestCase
{

    private Database $db;
    private array $inserted_elements;

    public static function validWhereClauseSignaturesProvider(): array {
        return [
            ['nonce', 'evzudve'],
            [
                [
                    ['nonce', 'evzudve'],
                    ['user_id', '1'],
                ]
            ],
            [
                [
                    ['nonce', 'evzudve'],
                    ['user_id', '1'],
                ],
                 QueryLink::OR
                ],
            [
                [
                    ['nonce', 'evzudve'],
                    ['nonce', 'ooo'],
                ], 
                QueryLink::AND
            ],
            ['nonce', '=', 'evzudve'],
            ['nonce', '=', 'evzudve', QueryLink::OR],
            ['nonce', '=', 'evzudve', QueryLink::AND],
            ['nonce', QueryOperator::EQUALS, 'evzudve'],
            ['nonce', QueryOperator::EQUALS, 'evzudve', QueryLink::OR],            
        ];
    }

    public static function invalidWhereClauseSignaturesProvider(): array {
        return [
            ['nonce', QueryLink::OR],
            [['nonce', 'id'], QueryOperator::EQUALS],
            [['nonce', 'id'], QueryOperator::EQUALS, [1, 2]],
            ['nonce', null, 'evzudve'],
            ['nonce', QueryOperator::EQUALS, QueryLink::OR],
        ];
    }

    public static function basicWhereClauseProvider(): array {
        $total_elements = 6;
        return [
            ['nonce', QueryOperator::EQUALS, '=', 'evzudve', 'evzudve', 1],
            ['nonce', QueryOperator::NOT_EQUALS, '!=', 'evzudve', '679', $total_elements -1],
            ['user_id', QueryOperator::GREATER_THAN, '>', '1', '2', $total_elements - 3],
            ['user_id', QueryOperator::LESS_THAN, '<', '2', '1', 3],
            ['user_id', QueryOperator::GREATER_THAN_OR_EQUAL, '>=', '2', '2', $total_elements - 3],
            ['user_id', QueryOperator::LESS_THAN_OR_EQUAL, '<=', '2', '2', 4],
            ['nonce', QueryOperator::LIKE, 'LIKE', '%evzu%', 'evzudve', 1],
            ['nonce', QueryOperator::NOT_LIKE, 'NOT LIKE', '%evzu%', '679', $total_elements -1],
            ['user_id', QueryOperator::IN, 'IN', ['1', '2'], '2', 4],
            // ['user_id', QueryOperator::NOT_IN, 'NOT IN', ['1', '2'], '3', 1],
            ['user_id', QueryOperator::IS_NULL, 'IS NULL', '', '', 0],
            ['user_id', QueryOperator::IS_NOT_NULL, 'IS NOT NULL', '', '2', $total_elements],
        ];
    }

    public static function orWhereQueriesProvider(): array {
        return [
            [
                [''],0
            ],
        ];
    }

    public static function whereGroupeQueriesProvider(): array {
        return [
            [
                [''],''
            ],
        ];
    }

    public function testFromReturnsAQueryInstance(): Query
    {
        $query = Query::from('nonces');
        $this->assertInstanceOf(Query::class, $query);
        
        return $query;
    }

    #[DataProvider('validWhereClauseSignaturesProvider')]
    public function testWhereMethodSignature($param1 = null, $param2 = null, $param3 = null, $param4 = null) {
        $query = Query::from('nonces');
        $where_query = $query->where($param1, $param2, $param3, $param4);
        $query_result = $where_query->fetch();

        $this->assertInstanceOf(Query::class, $where_query);
        $this->assertIsArray($query_result);
    }

    #[DataProvider('invalidWhereClauseSignaturesProvider')]
    public function testWhereMethodSignatureRaiseExceptionWithInvalidArguments($param1 = null, $param2 = null, $param3 = null, $param4 = null) {
        $this->expectException(TypeError::class);

        $query = Query::from('nonces');
        $where_query = $query->where($param1, $param2, $param3, $param4);
    }
    

    #[DataProvider('basicWhereClauseProvider')]
    public function testFetchingWithBasicQueryOperators(string $column, QueryOperator $operator, string $str_operator, string|array $value, string $expected_value, int $expected_count): void
    {
        $query_with_operator = Query::from('nonces');
        $where_query_with_operator = $query_with_operator->where($column, $operator, $value);
        $query_result_with_operator = $where_query_with_operator->fetch();

        $query_with_string = Query::from('nonces');
        $where_query_with_string = $query_with_string->where($column, $str_operator, $value);
        $query_result_with_string = $where_query_with_string->fetch();

        $this->assertInstanceOf(Query::class, $where_query_with_operator);
        $this->assertIsArray($query_result_with_operator);
        $this->assertEquals($expected_value, $query_result_with_operator[0][$column]);
        $this->assertCount($expected_count, $query_result_with_operator);

        $this->assertInstanceOf(Query::class, $where_query_with_string);
        $this->assertIsArray($query_result_with_string);
        $this->assertEquals($expected_value, $query_result_with_string[0][$column]);
        $this->assertCount($expected_count, $query_result_with_string);

        $this->assertEquals($query_result_with_operator, $query_result_with_string);
    }

    #[DataProvider(('orWhereQueriesProvider'))]
    public function testOrWhereMethodReturnExpectedLines(array $orWhereParams, int $expected_count) {
        $this->markTestIncomplete(
            'This test has not been implemented yet.',
        );

        $query = Query::from('nonces');
        $where_query = $query->where('user_id', '1')->orWhere(...$orWhereParams);
        $query_result = $where_query->fetch();

        $this->assertInstanceOf(Query::class, $where_query);
        $this->assertIsArray($query_result);
        $this->assertCount($expected_count, $query_result);
        
    }

    #[DataProvider(('whereGroupeQueriesProvider'))]
    public function testWhereGroupMethodReturnExpectedLines($whereGroupParams, $expected_count) {
        $this->markTestIncomplete(
            'This test has not been implemented yet.',
        );

        $query = Query::from('nonces');
        $where_query = $query->whereGroup($whereGroupParams);
        $query_result = $where_query->fetch();

        $this->assertInstanceOf(Query::class, $where_query);
        $this->assertIsArray($query_result);
        $this->assertCount($expected_count, $query_result);
        
    }

    public function testComplicatedWhereQueries() {
        $query_1 = Query::from('nonces');
        $query_2 = Query::from('nonces');
        $query_3 = Query::from('nonces');
        $query_4 = Query::from('nonces');
        $query_5 = Query::from('nonces');

        $where_query_1 = $query_1->where('user_id', '1')->where('nonce', 'evzudve');
        $where_query_2 = $query_2->where('user_id', '1')->orWhere('nonce', 'evzudve');
        $where_query_3 = $query_3->where('user_id', '1')->whereGroup([
            ['nonce', 'evzudve'],
            ['nonce', 'eeee'],
        ]);
        $where_query_4 = $query_4->where('user_id', '1')->whereGroup([
            ['nonce', 'evzudve'],
            ['nonce', 'eeee'],
        ], QueryLink::OR);
        $where_query_5 = $query_5->whereGroup([
            ['user_id', '1'],
            ['nonce', 'evzudve'],
        ]);

        $query_result_1 = $where_query_1->fetch();
        $query_result_2 = $where_query_2->fetch();
        $query_result_3 = $where_query_3->fetch();
        $query_result_4 = $where_query_4->fetch();
        $query_result_5 = $where_query_5->fetch();

        $this->assertInstanceOf(Query::class, $where_query_1);
        $this->assertIsArray($query_result_1);
        $this->assertCount(1, $query_result_1);

        $this->assertInstanceOf(Query::class, $where_query_2);
        $this->assertIsArray($query_result_2);
        $this->assertCount(3, $query_result_2);

        $this->assertInstanceOf(Query::class, $where_query_3);
        $this->assertIsArray($query_result_3);
        $this->assertCount(0, $query_result_3);

        $this->assertInstanceOf(Query::class, $where_query_4);
        $this->assertIsArray($query_result_4);
        $this->assertCount(2, $query_result_4);

        $this->assertInstanceOf(Query::class, $where_query_5);
        $this->assertIsArray($query_result_5);
        $this->assertCount(1, $query_result_5);
    }

    #[DataProvider('basicWhereClauseProvider')]
    public function testFirstMethodOnlyReturnsOneOrNoResult(string $column, QueryOperator $operator, string $str_operator, string|array $value, string $expected_value, int $expected_count): void
    {
        
        $query_with_operator = Query::from('nonces');
        $where_query_with_operator = $query_with_operator->where($column, $operator, $value);
        $query_result_with_operator = $where_query_with_operator->first();
        
        $query_with_string = Query::from('nonces');
        $where_query_with_string = $query_with_string->where($column, $str_operator, $value);
        $query_result_with_string = $where_query_with_string->first();
        
        $this->assertInstanceOf(Query::class, $where_query_with_operator);
        $this->assertInstanceOf(Query::class, $where_query_with_string);
        $this->assertEquals($query_result_with_operator, $query_result_with_string);
        
        if($expected_count == 0) {
            return;
        }
        
        $this->assertIsArray($query_result_with_operator);
        $this->assertCount(4, array_keys($query_result_with_operator)); # Array keys should not work if a list of results is returned instead of a single one
        
        $this->assertIsArray($query_result_with_string); 
        $this->assertCount(4, array_keys($query_result_with_string)); # Array keys should not work if a list of results is returned instead of a single one
    }

    #[DataProvider('basicWhereClauseProvider')]
    public function testGetOnlyReturnsTheDesiredColumn(string $column, QueryOperator $operator, string $str_operator, string|array $value, string $expected_value, int $expected_count): void
    {
        $query_with_operator = Query::from('nonces');
        $where_query_with_operator = $query_with_operator->where($column, $operator, $value);
        $query_result_with_operator = $where_query_with_operator->get('user_id')->first();
        
        $query_with_string = Query::from('nonces');
        $where_query_with_string = $query_with_string->where($column, $str_operator, $value);
        $query_result_with_string = $where_query_with_string->get('user_id')->first();
        
        $this->assertInstanceOf(Query::class, $where_query_with_operator);
        $this->assertInstanceOf(Query::class, $where_query_with_string);
        $this->assertEquals($query_result_with_operator, $query_result_with_string);
        
        if($expected_count == 0) {
            return;
        }
        
        $this->assertEquals(['user_id'], array_keys($query_result_with_operator));
        $this->assertIsArray($query_result_with_operator);

        $this->assertEquals(['user_id'], array_keys($query_result_with_operator));
        $this->assertIsArray($query_result_with_string);
    }

    

    protected function setUp(): void
    {
        $this->db = new Database(Config::get('TEST_DB_NAME'));
        
        $this->inserted_elements = [
            ['2', '679', '2021-01-01 00:00:00'],
            ['1', 'evzudve', '2021-01-01 00:00:00'], 
            ['1', 'eeee', '2021-01-01 00:00:00'],
            ['1', 'udve', '2021-01-01 00:00:00'],
            ['3', 'oooo', '2021-01-01 00:00:00'],
            ['4', 'oooo', '2021-01-01 00:00:00'],
        ];

        $this->db->execute('DELETE FROM nonces');
        $this->db->insert(
            'nonces', 
            ['user_id', 'nonce', 'expiration'], 
            $this->inserted_elements,
        );
    }

    protected function tearDown(): void
    {
        $this->db->execute('TRUNCATE `nonces`');
    }
}
