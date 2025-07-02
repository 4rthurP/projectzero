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
        mixed $param2,
        mixed $param3 = null,
        ?QueryLink $param4 = null
    ) {
        $this->column = $param1;
        $link = $param4;

        // Locate the value and operator among the parameters
        if ($param2 instanceof QueryOperator) {
            $this->operator = $param2;
            $value = $param3;
        } else {
            if ($param2 != null && QueryOperator::tryFrom($param2) !== null) {
                $this->operator = QueryOperator::from($param2);
                $value = $param3;
            } else {
                $this->operator = QueryOperator::EQUALS;
                $value = $param2;
                $link = $param3;
            }
        }

        // Validate the value
        $this->testIsValidValue($value);
        $this->values = $value;

        // Set the link, defaulting to AND if not provided or invalid
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

    /**
     * Validates the value of the where clause
     * @param mixed $value
     * @return true
     * @throws TypeError if the value is not valid
     */
    private function testIsValidValue(mixed $value): true
    {
        if ($value === null || is_string($value) || is_numeric($value) || is_array($value)) {
            return true;
        }
        throw new TypeError('Invalid parameter: expected a value after a QueryOperator');
    }
}
