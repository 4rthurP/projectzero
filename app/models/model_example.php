<?php

namespace pz\Models;

use pz\Model;
use pz\Enums\model\AttributeType;

class Example extends Model {
    
    public static $name = "example";
    public static $bundle = "default";

    protected function model() {
        $this->table('cellr_news');
        $this->attribute("title", AttributeType::CHAR, true);
    }    
}

?>