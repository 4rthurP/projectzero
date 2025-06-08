<?php

namespace pz\database;

use TypeError;
use Error;
use pz\Enums\database\QueryOperator;
use pz\Enums\database\QueryLink;

class WhereClause
{

    private string $column;
    private $values;
    private QueryOperator $operator;
    private QueryLink $link;

    public function __construct(
        string $param1,
        string|QueryOperator|null $param2,
        string|array|QueryLink|null $param3 = null,
        ?QueryLink $param4 = null
    ) {
        $this->column = $param1;

        $link = $param4;


        if ($param2 instanceof QueryOperator) {
            $this->operator = $param2;
            if (!is_array($param3) && !is_string($param3) && !is_null($param3)) {
                throw new TypeError('Invalid parameter: expected a value after a QueryOperator');
            }

            $this->values = $param3;
        } else {
            if ($param2 != null && QueryOperator::tryFrom($param2) !== null) {
                $this->operator = QueryOperator::from($param2);
                if (!is_array($param3) && !is_string($param3) && !is_null($param3)) {
                    throw new TypeError('Invalid parameter: expected a value after a QueryOperator');
                }

                $this->values = $param3;
            } else {
                $this->operator = QueryOperator::EQUALS;
                if (!is_array($param2) && !is_string($param2) && !is_null($param2)) {
                    throw new TypeError('Invalid parameter: expected a value after a QueryOperator');
                }

                $this->values = $param2;
                $link = $param3;
            }
        }

        if ($link === null) {
            $this->link = QueryLink::AND;
        } elseif ($link instanceof QueryLink) {
            $this->link = $link;
        } else {
            throw new TypeError('Invalid parameter: a where clause must have a valid QueryLink');
        }
    }

    /** 
     * Gets the clause's QueryLink
     * @return QueryLink
     */
    public function getLink(): QueryLink
    {
        return $this->link;
    }

    /**
     * Builds and returns the query associated with the where clause
     * @param bool $isFirst is it the first where in the query
     * @return Array
     * @throws Error
     */
    public function buildWhereClause(bool $isFirst = false): array
    {
        $link = $isFirst ? '' : ' ' . $this->link->value;

        $clause = $link . ' ' . $this->column . ' ';

        if ($this->operator == QueryOperator::IN) {
            if (!is_array($this->values) || count($this->values) == 0) {
                throw new Error('Invalid parameter: IN operator must have an array of values');
            }
            $clause .= 'IN (';
            $param = '';
            $value = [];
            foreach ($this->values as $val) {
                $clause .= '?, ';
                $param .= 's';
                $value[] = $val;
            }
            $clause = substr($clause, 0, -2) . ')';
            return ['clause' => $clause, 'param' => $param, 'values' => $value];
        }

        if ($this->operator == QueryOperator::IS_NULL || ($this->operator == QueryOperator::EQUALS && $this->values === null)) {
            $clause .= 'IS NULL';
            return ['clause' => $clause, 'param' => null, 'values' => null];
        }
        if ($this->operator == QueryOperator::IS_NOT_NULL || ($this->operator == QueryOperator::NOT_EQUALS && $this->values === null)) {
            $clause .= 'IS NOT NULL';
            return ['clause' => $clause, 'param' => null, 'values' => null];
        }

        $clause .= $this->operator->value . ' ?';

        $param = 's';
        $value = $this->values;

        return ['clause' => $clause, 'param' => $param, 'values' => [$value]];
    }
}
