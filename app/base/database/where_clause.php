<?php

namespace pz\database;

use Exception;
use Error;
use pz\Enums\database\QueryOperator;
use pz\Enums\database\QueryLink;

class WhereClause {

    private string $column;
    private ?Array $values;
    private QueryOperator $operator;
    private QueryLink $link;

    // public function __construct(string $column, $param1, $param2, QueryOperator $operator = QueryOperator::EQUALS) {
    //     $this->column = $column;
    //     $this->operator = $operator;
    //     $this->value = $param1;
    // }
    
    public function __construct($param1, $param2, $param3 = null, $param4 = null) {
        $this->column = $param1;
        
        $link = $param4;
        
        
        if($param2 instanceof QueryOperator) {
            $this->operator = $param2;
            $this->values[0] = $param3;
        } else {
            if($param2 != null && QueryOperator::tryFrom($param2) !== null) {
                $this->operator = QueryOperator::from($param2);
                $this->values[0] = $param3;

            } else {
                $this->operator = QueryOperator::EQUALS;
                $this->values[0] = $param2;
                $link = $param3;
            }
        }

        if($link === null) {
            $this->link = QueryLink::AND;
        } elseif ($link instanceof QueryLink) {
            $this->link = $link;
        } else {
            throw new Exception('Invalid parameter: a where clause must have a valid QueryLink');
        }
    }

    public function getLink(): QueryLink {
        return $this->link;
    }

    public function buildWhereClause(bool $isFirst = false): Array {
        $link = $isFirst ? '' : $this->link->value;

        $clause = $link . ' ' . $this->column . ' ';

        if($this->operator == QueryOperator::IN) {
            $clause .= 'IN (';
            $param = '';
            $value = [];
            foreach($this->values as $val) {
                $clause .= '?, ';
                $param .= 's';
                $value[] = $val;
            }
            $clause = substr($clause, 0, -2) . ')';
            return ['clause' => $clause, 'param' => $param, 'values' => $value];
        }

        if($this->operator == QueryOperator::IS_NULL || ($this->operator == QueryOperator::EQUALS && $this->values[0] === null)) {
            $clause .= 'IS NULL';
            return ['clause' => $clause, 'param' => null, 'values' => null];
        }
        if($this->operator == QueryOperator::IS_NOT_NULL || ($this->operator == QueryOperator::NOT_EQUALS && $this->values[0] === null)) {
            $clause .= 'IS NOT NULL';
            return ['clause' => $clause, 'param' => null, 'values' => null];
        }
        
        $clause .= $this->operator->value . ' ?';

        $param = 's';
        $value = $this->values[0];

        return ['clause' => $clause, 'param' => $param, 'values' => [$value]];
    }

}

?>