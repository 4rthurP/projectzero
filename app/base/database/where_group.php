<?php

namespace pz\database;


use Exception;
use pz\Enums\database\QueryLink;

class WhereGroup {
    private Array $whereClauses = [];
    private QueryLink $link = QueryLink::AND;
    private QueryLink $linkToPrevious = QueryLink::AND;

    public function __construct(Array|WhereClause $queries, QueryLink $link = QueryLink::AND, QueryLink $linkToPrevious = QueryLink::AND) {
        if(is_array($queries)) {
            foreach($queries as $query) {
                $this->add($query);
            }
        } else {
            $this->add($queries);
        }
        
        $this->link = $link;
        $this->linkToPrevious = $linkToPrevious;
    }

    public function add(Array|WhereClause $query) {
        if ($query instanceof WhereClause) {
            $this->whereClauses[] = $query;
            return $this;
        } 
        
        if(count($query) < 2) {
            throw new Exception('Invalid parameter: a where clause must have at least 2 parameters');
        }

        $param1 = $query[0];
        $param2 = $query[1];
        $param3 = $query[2] ?? null;
        $param4 = $query[3] ?? null;        

        $this->whereClauses[] = new WhereClause($param1, $param2, $param3, $param4);
        
        return $this;
    }

    public function buildWhereGroup(bool $isFirst = false): Array {
        $whereGroup = $isFirst ? '' : ' '.$this->linkToPrevious->value;
        $params = '';
        $values = [];

        $firstClause = true; # The first clause in a group can not have a link to the previous clause, this is used to avoid that.

        foreach($this->whereClauses as $whereClause) {
            $clause = $whereClause->buildWhereClause($firstClause);
            $whereGroup .= $clause['clause']; 
            $params .= $clause['param'] ? $clause['param'] : '';
            if($clause['values'] !== null) {
                $values = array_merge($values, $clause['values']);
            }
            $firstClause = false;
        }

        return ['clause' => $whereGroup, 'params' => $params, 'values' => $values];
    }
}