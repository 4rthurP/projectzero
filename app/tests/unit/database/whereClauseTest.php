<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use pz\Enums\database\QueryOperator;
use pz\Enums\database\QueryLink;
use pz\database\WhereClause;
final class whereClauseTest extends TestCase
{
    public static function validWhereClauseSignaturesProvider(): array {
        return [
            [
                'params' =>[
                    'id',
                    '!=',
                    '1',
                    null
                ],
                'link' => 'AND',
                'expected' => [
                    'clause' => ' id != ?',
                    'param' => 's',
                    'values' => ['1'],
                ]
            ],
            [
                'params' =>[
                    'id',
                    '!=',
                    null,
                    null
                ],
                'link' => 'AND',
                'expected' => [
                    'clause' => ' id IS NOT NULL',
                    'param' => null,
                    'values' => null,
                ]
            ],
            [
                'params' =>[
                    'id',
                    null,
                    null,
                    null
                ],
                'link' => 'AND',
                'expected' => [
                    'clause' => ' id IS NULL',
                    'param' => null,
                    'values' => null,
                ]
            ]
        ];
    }

    public static function invalidWhereClauseSignaturesProvider(): array {
        return [
            [
                'params' =>[
                    'id',
                    '!=',
                    '1',
                    null
                ]
            ],
        ];
    }
    
    #[DataProvider('validWhereClauseSignaturesProvider')]
    public function testWhereClauseSignature($params = null, $link = null, $expected = null): void {
        $whereClause = new WhereClause(...$params);
        $this->assertEquals($link, $whereClause->getLink()->value);
        $this->assertEquals($expected['clause'], $whereClause->buildWhereClause(true)['clause']);
        $this->assertEquals($expected['param'], $whereClause->buildWhereClause(true)['param']);
        $this->assertEquals($expected['values'], $whereClause->buildWhereClause(true)['values']);
    }

    #[DataProvider('invalidWhereClauseSignaturesProvider')]
    public function testInvalidWhereClauseSignature($params = null): void {
        $this->markTestIncomplete(
            'This test has not been implemented yet.',
        );

        $this->expectException(Exception::class);
        new WhereClause(...$params);
    }

    

}
