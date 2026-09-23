<?php

namespace pz\Models;

use pz\Model;
use pz\Enums\model\AttributeType;

class Job extends Model {
    public static $name = 'job';

    protected function model() {
        $this->attribute('kind', AttributeType::CHAR, true);             // 'scheduled_task' | 'ad_hoc_job'
        $this->attribute('type', AttributeType::CHAR, true);             // e.g. 'marge.isbn_sync', 'cellr.daily_stats'
        $this->attribute('handler_controller', AttributeType::CHAR, true);
        $this->attribute('handler_method', AttributeType::CHAR, true);
        $this->attribute('status', AttributeType::CHAR, true, 'pending'); // pending | running | completed | failed
        $this->attribute('payload', AttributeType::TEXT);                // handler's own state; json_encode/decode by hand
        $this->attribute('total', AttributeType::INT);                   // nullable: not always known up front
        $this->attribute('processed', AttributeType::INT, true, '0');
        $this->attribute('message', AttributeType::CHAR);                // human progress text, e.g. "Book 2/12: The Hobbit"
        $this->attribute('error_message', AttributeType::TEXT);
        $this->attribute('attempts', AttributeType::INT, true, '0');
        $this->attribute('started_at', AttributeType::DATETIME);
        $this->attribute('locked_at', AttributeType::DATETIME);
        $this->attribute('finished_at', AttributeType::DATETIME);
    }
}
