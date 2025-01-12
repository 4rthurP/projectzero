<?php

namespace pz\Enums\model;

use Attribute;

enum AttributeType: string {
    case ID = 'id';
    case UUID = 'uuid';
    case EMAIL = 'email';
    case RELATION = 'relation';
    case CHAR = 'char';
    case TEXT = 'text';
    case LIST = 'list';
    case BOOL = 'bool';
    case INT = 'int';
    case FLOAT = 'float';
    case DATE = 'date';
    case DATETIME = 'datetime';

    public function SQLQueryType(): string {
        return match($this) {
            AttributeType::ID => 'i',
            AttributeType::UUID => 's',
            AttributeType::EMAIL => 's',
            AttributeType::RELATION => null,
            AttributeType::CHAR => 's',
            AttributeType::TEXT => 's',
            AttributeType::LIST => 's',
            AttributeType::BOOL => 'i',
            AttributeType::INT => 'i',
            AttributeType::FLOAT => 'd',
            AttributeType::DATE => 's',
            AttributeType::DATETIME => 's',
        };
    }

    public function SQLType(): string {
        return match($this) {
            AttributeType::ID => 'INT',
            AttributeType::UUID => 'CHAR(36)',
            AttributeType::EMAIL => 'CHAR(255)',
            AttributeType::RELATION => 'INT',
            AttributeType::CHAR => 'CHAR(255)',
            AttributeType::TEXT => 'TEXT',
            AttributeType::LIST => 'TEXT',
            AttributeType::BOOL => 'TINYINT(1)',
            AttributeType::INT => 'INT',
            AttributeType::FLOAT => 'FLOAT',
            AttributeType::DATE => 'DATE',
            AttributeType::DATETIME => 'DATETIME',
        };
    }
}