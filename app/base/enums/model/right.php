<?php

namespace pz\Enums\model;

enum Right: string {
    case PUBLIC = 'public';
    case GROUP = 'group';
    case OWNER = 'owner';
}

?>