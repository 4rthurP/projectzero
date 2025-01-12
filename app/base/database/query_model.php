<?php

namespace pz\database;

use Exception;
use pz\database\Query;

class ModelQuery extends Query {

    private $model;

    private $load_relations = false;

    public function __construct($model) {
        $this->model = new $model;
        $this->table = $this->model->getModelTable();
    }
    
    /**
     * Does nothing on ModelQuery instances: models are always loaded entirely.
     *
     * @param array|string $columns The columns to be retrieved.
     * @return $this The current instance of the query model.
     */
    public function get($columns) {
        return $this;
    }

    public static function fromModel($modelName): ModelQuery {
        ///TODO: add a where clause to filter out soft deleted items when the model has a soft delete column
        $modelQuery = new ModelQuery($modelName);
        return $modelQuery;
    }
    
    public function findOrCreate() {
        throw new Exception('Not implemented');
    }

    public function findOrNew() {
        throw new Exception('Not implemented');
    }

    public function first() {
        $results = parent::fetch(1);
        if(count($results) == 0) {
            return null;
        }
        $ressource = $this->model::load($results[0]['id']);
        return $ressource;
    }

    public function fetch(?int $limit = null, ?int $offset = null): Array {
        $results = parent::fetch($limit, $offset);
        if($this->isAggregate) {
            return $results;
        }
        
        $ressources_array = [];

        foreach($results as $line) {
            $ressource = new $this->model;
            $ressource->loadFromArray($line, $this->load_relations);
            $ressources_array[] = $ressource;
        }

        return $ressources_array;
    }

    public function fetchAsArray(?bool $simplified = false, ?int $limit = null, ?int $offset = null): Array {
        parent::fetch($limit, $offset);

        if($simplified) {
            return $this->parsed_results;
        }

        $ressources_array = [];
        foreach($this->results as $line) {
            $ressource = new $this->model;
            $ressource->loadFromArray($line, $this->load_relations);
            $ressources_array[] = $ressource->toArray();
        }

        return $ressources_array;
    }

    public function loadRelations($load_relations = true) {
        $this->load_relations = $load_relations;
        return $this;
    }

}