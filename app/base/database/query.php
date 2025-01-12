<?php

namespace pz\database;

use Exception;
use mysqli_result;
use pz\database\Database;
use pz\Enums\database\QueryOperator;
use pz\Enums\database\QueryLink;
use pz\Enums\database\QueryType;
use Stringable;

###Methods ideas: firstWhere, findOrCreate, findOrNew, rawExpressions, union
###Aggregates ideas: count, sum, avg, min, max, exists, doesntExist

class Query {
    protected string $table;

    protected Array $whereGroups = [];
    protected Array $columns = [];
    protected Array $orderBy = [];
    protected Array $groupBy = [];
    protected Array $joins = [];
    protected Array $distinct = [];
    protected bool $isAggregate = false;
    protected int $limit = 50;
    protected int $offset = 0;

    protected String $query;
    protected String $queryParams = '';
    protected Array $queryValues = [];

    protected bool $found = false;
    protected bool $fail_if_not_found = false;
    protected ?String $fail_message = null;
    protected $fail_closure = null;

    protected mysqli_result $results;
    protected Array $parsed_results;

    /**
     * Creates a new query object for the specified table by running 'Query::from($table)'.
     * This method is the entry point for creating a new query object, other methods can then be chained to this method.
     *
     * @param string $table The name of the table to query.
     * @return Query The query object.
     */
    public static function from(String $table) {
        $query = new Query();
        $query->table = $table;
        return $query;
    }

    ######################################
    # Query Execution Methods
    ######################################

    public function fetch(?int $limit = null, ?int $offset = null) {
        $this->buildQuery($limit, $offset);
        $this->runQuery();
        return $this->found ? $this->parsed_results : [];
    }

    public function fetchAsArray() {
        return $this->fetch();
    }
    
    public function first() {
        $this->buildQuery(1, 0);
        $this->runQuery();
        return $this->found ? $this->parsed_results[0] : [];
    }

    public function firstWhere(String $column, String $value, ?QueryOperator $operator = QueryOperator::EQUALS) {
        if($this->whereGroups !== []) {
            throw new  Exception('The firstWhere method cannot be used when there are already where clauses defined');
        }

        $this->where($column, $operator, $value);

        return $this->first();
    }
    
    public function fetchOrFail(?String $fail_message = null, ?int $limit = null, ?int $offset = null) {
        $this->fail_if_not_found = true;
        $this->fail_message = $fail_message;
        return $this->fetch($limit, $offset);
    }

    public function fetchOr(?callable $closure = null, ?int $limit = null, ?int $offset = null) {
        $this->fail_closure = $closure;
        return $this->fetch($limit, $offset);
    }

    public function find($id, String $idKey = 'id') {
        $this->where($idKey, $id);
        return $this->first();
    }

    public function count() {
        $this->columns = ['COUNT(*)'];
        $this->isAggregate = true;
        $this->buildQuery();
        $this->runQuery();
        return isset($this->parsed_results[0]['COUNT(*)']) ? $this->parsed_results[0]['COUNT(*)'] : null;
    }

    ######################################
    # Query Builder Methods
    ######################################

    //All the methods below are chainable and return the Query object itself.

    /**
     * Adds the specified columns to the current query.
     *
     * @param array|string $columns The columns to add to the query. Can be an array of column names or a comma-separated string of column names.
     * @return $this Returns the current instance for method chaining.
     */
    public function get($columns) {
        if(!is_array($columns)) {
            $columns = explode(',', $columns);
        }

        $this->columns = array_merge($this->columns, $columns);

        return $this;
    }

    /**
     * Adds a WHERE clause to the query.
     * This method can be called multiple times to add multiple WHERE clauses to the query.
     * It accepts several ways to define new where clause(s)
     * - where('column', 'value') : Adds a new WHERE clause to the query, where 'column' is equal to 'value'.
     * - where('column', QueryOperator, 'value') : Adds a new WHERE clause to the query, with the given operator.
     * - where([['column1', 'value1', ?QueryOperator], ['column2', 'value2', ?QueryOperator], ...], ?QueryLink) : Adds a new WHERE clause for each line in the array
     *
     * @param mixed $param1 The first parameter of the WHERE clause. Can be an array for grouped WHERE clauses.
     * @param mixed $param2 The second parameter of the WHERE clause. If $param1 is an array, this represents the link between the clauses.
     * @param mixed|null $param3 The third parameter of the WHERE clause. Optional.
     * @param mixed|null $param4 The fourth parameter of the WHERE clause. Optional.
     * @return Query The updated Query object.
     * @throws Exception If the $param2 is null and $param1 is not an array, or if $param2 is not an instance of QueryLink when $param1 is an array.
     */
    public function where($param1, $param2 = null, $param3 = null, $param4 = null): Query  {
        if(is_array($param1)) {
            if($param2 === null) {
                $param2 = QueryLink::AND;
            }
            if(!$param2 instanceof QueryLink) {
                throw new Exception('Invalid QueryLink : Where clauses must be linked by a valid QueryLink');
            }
            $this->whereGroup($param1, $param2);
            return $this;
        }

        $whereClause = new WhereClause($param1, $param2, $param3, $param4);
        $this->whereGroups[] = new WhereGroup($whereClause, QueryLink::AND, $whereClause->getLink());
        return $this;
    }

    /**
     * Adds an "OR" condition to the query's WHERE clause.
     * As with the where method, this method can be called multiple times to add multiple OR WHERE clauses to the query and accepts the same three ways to define new where clause(s).
     * 
     *
     * @param mixed $param1 The column name or an array of clauses.
     * @param mixed $param2 The comparison operator or the value to compare.
     * @param mixed $param3 The value to compare (optional).
     * @return Query The updated Query object.
     * @throws Exception If an invalid parameter is provided.
     */
    public function orWhere($param1, $param2 = null, $param3 = null): Query {
        if(is_array($param1)) {
            $this->whereGroup($param1, QueryLink::OR);
            return $this;
        }

        if($param2 === null) {
            throw new Exception('Invalid parameter: a where clause must have at least 2 parameters');
        }

        $whereClause = new WhereClause($param1, $param2, $param3, QueryLink::OR);
        $this->whereGroups[] = new WhereGroup($whereClause, QueryLink::AND, QueryLink::OR);
        return $this;
    }

    /**
     * Adds a group of WHERE conditions to the query.
     * The clauses are defined in an array, where each line is a WHERE clause with two or three parameters, similar to the where and orWhere methods.
     * This method is used to group multiple WHERE conditions together, and can be used to create complex WHERE clauses.
     *
     * @param array $queries The array of WHERE conditions.
     * @param QueryLink $link The logical operator to link the conditions within the group (default: QueryLink::AND).
     * @param QueryLink $linkToPrevious The logical operator to link the group to the previous conditions (default: QueryLink::AND).
     * @return Query The updated Query object.
     */
    public function whereGroup(Array $queries, QueryLink $link = QueryLink::AND, QueryLink $linkToPrevious = QueryLink::AND): Query {
        $whereGroup = new WhereGroup($queries, $link, $linkToPrevious);
        $this->whereGroups[] = $whereGroup;
        return $this;
    }

    public function whereIn(String $column, Array $values): Query {
        $whereClause = new WhereClause($column, QueryOperator::IN, $values);
        $this->whereGroups[] = new WhereGroup($whereClause, QueryLink::AND, $whereClause->getLink());
        return $this;
    }

    public function order(String $order_by, bool $ascend = true) {
        $this->orderBy[] = ['column' => $order_by, 'ascend' => $ascend];
        return $this;
    }

    public function orderDesc(String $order_by) {
        return $this->order($order_by, false);
    }
    
    public function take(?int $limit = null, ?int $offset = null) {
        if($limit !== null) {
            $this->limit = $limit;
        }
        if($offset !== null) {
            $this->offset = $offset;
        }
        return $this;
    }

    public function skip(int $offset) {
        $this->offset = $offset;
        return $this;
    }

    public function groupBy(Array|String $columns): Query {
        if(!is_array($columns)) {
            $columns = explode(',', $columns);
        }

        $this->groupBy = array_merge($this->groupBy, $columns);

        return $this;
    }

    public function distinct(Array|String $columns): Query {
        if(!is_array($columns)) {
            $columns = explode(',', $columns);
        }

        $this->distinct = array_merge($this->distinct, $columns);

        return $this;
    }

    public function join(String $table, String $column1, String $column2, QueryType $type = QueryType::INNER_JOIN) {
        $this->joins[] = ['table' => $table, 'column1' => $column1, 'column2' => $column2, 'type' => $type];
        return $this;
    }

    public function leftJoin(String $table, String $column1, String $column2) {
        return $this->join($table, $column1, $column2, QueryType::LEFT_JOIN);
    }

    public function rightJoin(String $table, String $column1, String $column2) {
        return $this->join($table, $column1, $column2, QueryType::RIGHT_JOIN);
    }

    public function union(Query $query) {
        throw new Exception('Not implemented : the union method is not yet implemented');
    }

    ######################################
    # Query Helper Methods
    ######################################

    /**
     * Builds a SQL query based on the specified parameters.
     * This method **does not** call the query, it only generates the SQL query and stores it inside the 'query' property.
     * The query can be run by calling the runQuery method.
     *
     * @param int|null $limit The maximum number of rows to return.
     * @param int|null $offset The number of rows to skip before starting to return data.
     * @return string The generated SQL query.
     */
    private function buildQuery(?int $limit = null, ?int $offset = null) {

        $this->take($limit, $offset);
        
        if($this->columns === []) {
            $this->columns = ['*'];
        }

        $columns = implode(', ', $this->columns);
        
        $query = "SELECT $columns FROM $this->table";

        foreach ($this->joins as $join) {
            $query .= $join['type']." ".$join['table']." ON ".$this->table.".".$join['column1']." = ".$join['table'].".".$join['column2'];
        }

        if (count($this->whereGroups) > 0) {
            $query .= " WHERE ";
            $first = true;# The first where clause can not have a link, this is used to avoid that.
            foreach ($this->whereGroups as $whereGroup) {
                $where = $whereGroup->buildWhereGroup($first);
                $query .= $where['clause'];

                $this->queryParams .= $where['params'];
                
                $this->queryValues = array_merge($this->queryValues, $where['values']);
                
                $first = false;
            }
        }

        if (count($this->orderBy) > 0) {
            $query .= " ORDER BY ";
            foreach ($this->orderBy as $order) {
                $query .= $order['column'] . ' ' . ($order['ascend'] ? 'ASC' : 'DESC') . ', ';
            }
            $query = rtrim($query, ', ');
        }

        if($this->limit !== null) {
            $query .= " LIMIT $this->limit";
            if($this->offset !== null) {
                $query .= " OFFSET $this->offset";
            }
        }
        
        $this->query = $query;
        return $query;
    }

    /**
     * Runs the query and returns the parsed results.
     * This method also handles what to do if no results were found (nothing, fail, call a closure).
     *
     * @return array|null The parsed results of the query, or null if no results were found.
     * @throws Exception If no results were found and fail_if_not_found is set to true.
     */
    protected function runQuery() {
        $results = Database::runQuery($this->query, $this->queryParams, ...$this->queryValues);

        $this->results = $results;

        //For aggregate queries, we are only looking to return the raw results
        if($this->isAggregate) {
            $this->found = true;
            $this->parsed_results = $results->fetch_all(MYSQLI_ASSOC);
            return;
        }

        //For normal queries, we resolve the potential failure of the request (no results returned) and parse the results
        $this->found = $results->num_rows > 0;

        if(!$this->found) {
            if($this->fail_if_not_found) {
                if($this->fail_message !== null) {
                    throw new Exception($this->fail_message ? $this->fail_message : 'No results found were found for the query: '.$this->query);
                }
                if($this->fail_closure !== null) {
                    $closure = $this->fail_closure;
                    $closure();
                }
                return null;
            }
        }

        $this->parsed_results = $results->fetch_all(MYSQLI_ASSOC);

        if($this->distinct !== []) {
            $this->parsed_results = $this->findDistinct();
        }

        return $this->parsed_results;
    }

    /**
     * Find distinct results based on specified columns.
     * Careful: to accomodate for the lack of a real DISTINCT clause in mySQL, this method does not rely on a SQL query but rather filters the results in PHP.
     * This means that this method should be used with caution on large datasets.
     *
     * @return array The distinct results.
     */
    private function findDistinct() {
        $kept_results = $this->results;

        foreach($this->distinct as $column) {
            $distinct_values = [];
            $distinct_results = [];
            foreach($kept_results as $result) {
                if(!in_array($result[$column], $distinct_values)) {
                    $distinct_results[] = $result;
                    $distinct_values[] = $result[$column];
                }
            }
            $kept_results = $distinct_results;
        }

        return $kept_results;
    }

    ######################################
    # Misc Methods
    ######################################

    public function debuger() {
        return [
            'query' => $this->query,
            'params' => $this->queryParams, 
            'values' => $this->queryValues
        ];
    }

}
?>