<?php

namespace pz\Enums\database;

enum QueryType: string {
    case SELECT = 'SELECT';
    case INSERT = 'INSERT';
    case UPDATE = 'UPDATE';
    case DELETE = 'DELETE';
    case INNER_JOIN = 'INNER JOIN';
    case LEFT_JOIN = 'LEFT JOIN';
    case RIGHT_JOIN = 'RIGHT JOIN';
    case UNION = 'UNION';
    case UNION_ALL = 'UNION ALL';
}

?>