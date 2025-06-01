<?php

namespace pz\database;

use Exception;
use TypeError;
use mysqli_result;
use pz\database\Database;
use pz\Enums\database\QueryOperator;
use pz\Enums\database\QueryLink;
use pz\Enums\database\QueryType;

###Methods ideas: rawExpressions, union
###Aggregates ideas: sum, avg, min, max, exists, doesntExist

class Query
{
    protected string $table;

    protected array $whereGroups = [];
    protected array $columns = [];
    protected array $orderBy = [];
    protected array $groupBy = [];
    protected array $joins = [];
    protected array $distinct = [];
    protected bool $isAggregate = false;
    protected ?int $limit = null;
    protected int $offset = 0;

    protected String $query;
    protected String $queryParams = '';
    protected array $queryValues = [];

    protected bool $found = false;
    protected bool $fail_if_not_found = false;
    protected ?String $fail_message = null;
    protected $fail_closure = null;

    protected mysqli_result $results;
    protected array $parsed_results;
    protected array $distinct_values = [];

    /**
     * Creates a new query object for the specified table by running 'Query::from($table)'.
     * This method is the entry point for creating a new query object, other methods can then be chained to this method.
     *
     * @param string $table The name of the table to query.
     * @return static The query object.
     */
    public static function from(String $table): static
    {
        $query = new Query();
        $query->table = $table;
        return $query;
    }

    ######################################
    # Query Execution Methods
    ######################################
    /**
     * Fetches the results of the query.
     *
     * @param int|null $limit The maximum number of results to fetch.
     * @param int|null $offset The number of results to skip before fetching.
     *
     * @return array The fetched results, or an empty array if no results were found.
     */
    public function fetch(?int $limit = null, ?int $offset = null): array
    {
        $this->buildQuery($limit, $offset);
        $this->runQuery();
        return $this->found ? $this->parsed_results : [];
    }

    /**
     * Retrieves the first result from the query.
     *
     * @return array|null The first result from the query, or null if no results found.
     */
    public function first(): ?array
    {
        $this->buildQuery(1, 0);
        $this->runQuery();
        return $this->found ? $this->parsed_results[0] : null;
    }

    /**
     * Find the first record in the database table that matches the specified condition.
     *
     * @param string $column The column name to search for.
     * @param string $value The value to compare against.
     * @param QueryOperator|null $operator The operator to use for the comparison. Defaults to QueryOperator::EQUALS.
     * @return mixed The first record that matches the condition.
     * @throws Exception If there are already where clauses defined.
     */
    public function firstWhere(String $column, String $value, ?QueryOperator $operator = QueryOperator::EQUALS): ?array
    {
        if ($this->whereGroups !== []) {
            throw new  Exception('The firstWhere method cannot be used when there are already where clauses defined');
        }

        $this->where($column, $operator, $value);

        return $this->first();
    }

    /**
     * Fetches the query result or throws an exception if not found.
     *
     * @param string|null $fail_message The message to be used in the exception if the result is not found.
     * @param int|null $limit The maximum number of rows to fetch.
     * @param int|null $offset The number of rows to skip before starting to fetch.
     * @return mixed The fetched query result.
     */
    public function fetchOrFail(?String $fail_message = null, ?int $limit = null, ?int $offset = null): array
    {
        $this->fail_if_not_found = true;
        $this->fail_message = $fail_message;
        return $this->fetch($limit, $offset);
    }

    /**
     * Fetches the query results with an optional closure.
     *
     * @param callable|null $closure An optional closure to be executed before fetching the results.
     * @param int|null $limit The maximum number of results to fetch.
     * @param int|null $offset The offset from where to start fetching the results.
     * @return mixed The fetched query results.
     */
    public function fetchOr(?callable $closure = null, ?int $limit = null, ?int $offset = null): array
    {
        $this->fail_closure = $closure;
        return $this->fetch($limit, $offset);
    }

    /**
     * Find a record by its ID.
     *
     * @param mixed $id The ID of the record.
     * @param string $idKey The name of the ID column. Default is 'id'.
     * @return mixed The first record that matches the given ID.
     */
    public function find($id, String $idKey = 'id'): ?array
    {
        $this->where($idKey, $id);
        return $this->first();
    }


    /**
     * Adds an aggregate function to the query.
     *
     * @param string      $aggregate The aggregate function to apply (e.g., count, sum, avg, min, max.
     * @param string|null $column    The column to apply the aggregate function on. Defaults to '*' if null.
     *
     * @throws Exception If the provided aggregate function is not valid.
     *
     * @return static Returns the current instance with the aggregate function applied.
     */
    public function addAggregate(String $aggregate, ?String $column = null, ?String $name = null): static
    {
        if (!in_array($aggregate, ['count', 'sum', 'avg', 'min', 'max'])) {
            throw new Exception('Invalid aggregate function: ' . $aggregate);
        }

        $column = $column ?? '*';
        $name = $name == null ? ($column == null ? $aggregate : $aggregate . '_' . $column) : $name;


        $this->columns = [strtoupper($aggregate) . '(' . $column . ') AS ' . $name];
        $this->isAggregate = true;
        return $this;
    }

    /**
     * Counts the number of rows in the database table.
     *
     * @return int|null The count of rows in the table, or null if an error occurred.
     */
    public function count(): ?int
    {
        $this->columns = ['COUNT(*)'];
        $this->isAggregate = true;
        $this->buildQuery();
        $this->runQuery();
        return isset($this->parsed_results[0]['COUNT(*)']) ? $this->parsed_results[0]['COUNT(*)'] + 0 : null;
    }

    /**
     * Retrieves the sum of the specified column.
     *
     * @param string $column The column to sum.
     * @return int|null The sum of the column, or null if an error occurred.
     */
    public function sum(String $column): ?int
    {
        $this->columns = ['SUM(' . $column . ') AS SUM'];
        $this->isAggregate = true;
        $this->buildQuery();
        $this->runQuery();
        return isset($this->parsed_results[0]['SUM']) ? $this->parsed_results[0]['SUM'] + 0 : null;
    }

    /**
     * Retrieves the average of the specified column.
     *
     * @param string $column The column to average.
     * @return float|null The average of the column, or null if an error occurred.
     */
    public function avg(String $column): ?float
    {
        $this->columns = ['AVG(' . $column . ') AS AVG'];
        $this->isAggregate = true;
        $this->buildQuery();
        $this->runQuery();
        return isset($this->parsed_results[0]['AVG']) ? $this->parsed_results[0]['AVG'] + 0 : null;
    }

    /**
     * Retrieves the minimum value of the specified column.
     *
     * @param string $column The column to find the minimum value of.
     * @return mixed The minimum value of the column, or null if an error occurred.
     */
    public function min(String $column): mixed
    {
        $this->columns = ['MIN(' . $column . ') AS MIN'];
        $this->isAggregate = true;
        $this->buildQuery();
        $this->runQuery();
        return isset($this->parsed_results[0]['MIN']) ? $this->parsed_results[0]['MIN'] + 0 : null;
    }

    /**
     * Retrieves the maximum value of the specified column.
     *
     * @param string $column The column to find the maximum value of.
     * @return mixed The maximum value of the column, or null if an error occurred.
     */
    public function max(String $column): mixed
    {
        $this->columns = ['MAX(' . $column . ') AS MAX'];
        $this->isAggregate = true;
        $this->buildQuery();
        $this->runQuery();
        return isset($this->parsed_results[0]['MAX']) ? $this->parsed_results[0]['MAX'] + 0 : null;
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
    public function get(array|string $columns): static
    {
        if (!is_array($columns)) {
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
     * @param string|array $param1 The column name or an array of WHERE conditions.
     * @param string|QueryOperator|QueryLink|null $param2 The value to compare against, or a QueryOperator.
     * @param string|QueryLink|null $param3 The value to compare against, or a QueryLink.
     * @param QueryLink|null $param4 The value to compare against, or a QueryLink.
     * @return $this The updated Query object.
     */
    public function where(
        string|array $param1,
        string|QueryOperator|QueryLink|null $param2 = null,
        string|array|QueryLink|null $param3 = null,
        ?QueryLink $param4 = null
    ): static {
        if (is_array($param1)) {
            if ($param2 === null) {
                $param2 = QueryLink::AND;
            }
            if (!$param2 instanceof QueryLink) {
                throw new TypeError('Invalid QueryLink : Where clauses must be linked by a valid QueryLink');
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
     *
     * @param string|array $param1 The column name or an array of column names.
     * @param string|QueryOperator $param2 The operator or QueryOperator object.
     * @param string|null $param3 The value for the condition (optional).
     * @return static The Query object for method chaining.
     * @throws Exception If the parameter count is invalid.
     */
    public function orWhere(
        string|array $param1, 
        string|QueryOperator|null $param2, 
        ?string $param3 = null
    ): static {
        if (is_array($param1)) {
            return $this->whereGroup($param1, QueryLink::OR);
        }

        if ($param2 === null) {
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
     * @return static The updated Query object.
     */
    public function whereGroup(
        array $queries, 
        QueryLink $link = QueryLink::AND, 
        QueryLink $linkToPrevious = QueryLink::AND
    ): static {
        $whereGroup = new WhereGroup($queries, $link, $linkToPrevious);
        $this->whereGroups[] = $whereGroup;
        return $this;
    }

    /**
     * Adds a WHERE IN clause to the query.
     *
     * @param string $column The column name to compare.
     * @param array $values The array of values to compare against.
     * @return Query The updated Query object.
     */
    public function whereIn(string $column, array $values): Query
    {
        $whereClause = new WhereClause($column, QueryOperator::IN, $values);
        $this->whereGroups[] = new WhereGroup($whereClause, QueryLink::AND, $whereClause->getLink());
        return $this;
    }

    /**
     * Adds an order by clause to the query.
     *
     * @param string $order_by The column to order by.
     * @param bool $ascend Whether to sort in ascending order (default: true).
     * @return static The Query object for method chaining.
     */
    public function order(string $order_by, bool $ascend = true): static
    {
        $this->orderBy[] = ['column' => $order_by, 'ascend' => $ascend];
        return $this;
    }

    /**
     * Orders the query results in descending order based on the specified column.
     *
     * @param string $order_by The column to order by.
     * @return static The updated query object.
     */
    public function orderDesc(String $order_by): static
    {
        return $this->order($order_by, false);
    }

    /**
     * Sets the limit and offset for the query.
     *
     * @param int|null $limit The maximum number of records to retrieve.
     * @param int|null $offset The number of records to skip before retrieving.
     * @return static The current instance of the query object.
     */
    public function take(?int $limit = null, ?int $offset = null): static
    {
        if ($limit !== null) {
            $this->limit = $limit;
        }
        if ($offset !== null) {
            $this->offset = $offset;
        }
        return $this;
    }

    /**
     * Sets the offset for the query.
     *
     * @param int $offset The number of rows to skip.
     * @return static The current instance of the query.
     */
    public function skip(int $offset): static
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Sets the columns to group the query results by.
     *
     * @param array|string $columns The columns to group by. Can be either an array or a comma-separated string.
     * @return $this
     */
    public function groupBy(array|String $columns): static
    {
        if (!is_array($columns)) {
            $columns = explode(',', $columns);
        }

        $this->groupBy = array_merge($this->groupBy, $columns);

        return $this;
    }

    /**
     * Sets the distinct columns for the query.
     *
     * @param array|string $columns The columns to be set as distinct.
     * @return $this
     */
    public function distinct(array|string $columns): static
    {
        if (!is_array($columns)) {
            $columns = explode(',', $columns);
        }

        $this->distinct = array_merge($this->distinct, $columns);

        return $this;
    }

    /**
     * Joins a table in the database query.
     *
     * @param string $table The name of the table to join.
     * @param string $column1 The column name from the current table to join on.
     * @param string $column2 The column name from the joined table to join on.
     * @param QueryType $type The type of join to perform. Defaults to INNER_JOIN.
     * @return static The current instance of the Query class.
     */
    public function join(string $table, string $column1, string $column2, QueryType $type = QueryType::INNER_JOIN): static
    {
        $this->joins[] = ['table' => $table, 'column1' => $column1, 'column2' => $column2, 'type' => $type];
        return $this;
    }

    /**
     * Performs a left join on the specified table using the given columns.
     *
     * @param string $table The name of the table to join.
     * @param string $column1 The name of the column from the current table to join on.
     * @param string $column2 The name of the column from the joined table to join on.
     * @return static The updated query object.
     */
    public function leftJoin(string $table, string $column1, string $column2): static
    {
        return $this->join($table, $column1, $column2, QueryType::LEFT_JOIN);
    }

    /**
     * Performs a right join with the specified table.
     *
     * @param string $table The name of the table to join.
     * @param string $column1 The column from the current table to join on.
     * @param string $column2 The column from the specified table to join on.
     * @return static The Query object for method chaining.
     */
    public function rightJoin(string $table, string $column1, string $column2): static
    {
        return $this->join($table, $column1, $column2, QueryType::RIGHT_JOIN);
    }

    /**
     * Performs a union operation with another query.
     *
     * @param Query $query The query to perform the union with.
     * @return static
     * @throws Exception When the union method is not yet implemented.
     */
    public function union(Query $query): static
    {
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
    public function buildQuery(?int $limit = null, ?int $offset = null): string
    {

        $this->take($limit, $offset);

        if ($this->columns === []) {
            $this->columns = ['*'];
        }

        $columns = implode(', ', $this->columns);

        $query = "SELECT $columns FROM $this->table";

        foreach ($this->joins as $join) {
            $query .= $join['type'] . " " . $join['table'] . " ON " . $this->table . "." . $join['column1'] . " = " . $join['table'] . "." . $join['column2'];
        }

        if (count($this->whereGroups) > 0) {
            $query .= " WHERE ";
            $first = true; # The first where clause can not have a link, this is used to avoid that.
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

        if ($this->limit !== null) {
            $query .= " LIMIT $this->limit";
            if ($this->offset !== null) {
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
    protected function runQuery(): ?array
    {
        $results = Database::execute($this->query, $this->queryParams, ...$this->queryValues);

        $this->results = $results;

        //For aggregate queries, we are only looking to return the raw results
        if ($this->isAggregate) {
            $this->found = true;
            $this->parsed_results = $results->fetch_all(MYSQLI_ASSOC);
            return null;
        }

        //For normal queries, we resolve the potential failure of the request (no results returned) and parse the results
        $this->found = $results->num_rows > 0;

        if (!$this->found) {
            if ($this->fail_if_not_found) {
                if ($this->fail_message !== null) {
                    throw new Exception($this->fail_message ? $this->fail_message : 'No results found were found for the query: ' . $this->query);
                }
                if ($this->fail_closure !== null) {
                    $closure = $this->fail_closure;
                    $closure();
                }
                return null;
            }
        }

        $this->parsed_results = $results->fetch_all(MYSQLI_ASSOC);

        if ($this->distinct !== []) {
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
    private function findDistinct(): array
    {
        $kept_results = [];
        $distinct_values = [];

        foreach ($this->results as $result) {
            $keep_result = false;
            foreach ($this->distinct as $column) {
                //We don't want to keep empty results
                if ($result[$column] == '') {
                    continue;
                }
                if (isset($distinct_values[$column])) {
                    //Column already exists so we check if the value is already in the array
                    if (in_array($result[$column], $distinct_values[$column])) {
                        //Value already exists so we skip this result
                        continue;
                    }
                    $distinct_values[$column][] = $result[$column];
                } else {
                    //Column does not exist yet so we keep the result
                    $distinct_values[$column] = [$result[$column]];
                }

                //If we reach this point, the result is kept
                $keep_result = true;
            }
            // We keep only one record per line
            if ($keep_result) {
                $kept_results[] = $result;
            }
        }

        $this->distinct_values = $distinct_values;
        return $kept_results;
    }

    ######################################
    # Misc Methods
    ######################################
    public function debuger()
    {
        return [
            'query' => $this->query,
            'params' => $this->queryParams,
            'values' => $this->queryValues
        ];
    }
}
