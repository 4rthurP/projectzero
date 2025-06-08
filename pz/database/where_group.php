<?php

namespace pz\database;


use Exception;
use pz\Enums\database\QueryLink;

class WhereGroup {
    private Array $whereClauses = [];
    private QueryLink $link = QueryLink::AND;
    private QueryLink $linkToPrevious = QueryLink::AND;

    public function __construct(
        Array|WhereClause $queries, 
        ?QueryLink $link = QueryLink::AND, 
        ?QueryLink $linkToPrevious = QueryLink::AND
    ) {
        if(is_array($queries)) {
            foreach($queries as $query) {
                $this->addQuery($query, $link);
            }
        } else {
            $this->addClause($queries);
        }
        
        $this->link = $link;
        $this->linkToPrevious = $linkToPrevious;
    }

    /**
     * Adds a new where clause to the group
     * @param WhereClause $query
     * @return static
     */
    public function addClause(WhereClause $query): static {
        $this->whereClauses[] = $query;
        return $this;
    }

    /**
     * Constructs and add a new where clause to the group
     * @param Array $query
     * @param QueryLink $link
     * @return static
     */
    public function addQuery(Array $query, QueryLink $link): static {
        if(count($query) < 2) {
            throw new Exception('Invalid parameter: a where clause must have at least 2 parameters');
        }

        if(isset($query[2])) {
            $param2 = $query[2];
            $param3 = $link;
        } else {
            $param2 = $link;
            $param3 = null;
        }

        $this->whereClauses[] = new WhereClause($query[0], $query[1], $param2, $param3);
        
        return $this;
    }

    /**
     * Builds the query associated with the group
     * @param bool $isFirst is it the first where in the query
     * @return Array
     */
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