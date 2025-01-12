<?php

namespace pz\Controllers;

use pz\database\ModelQuery;
use pz\Models\{CellrWineData};
use pz\Routing\Request;
use pz\Routing\Response;
use pz\Enums\Routing\ResponseCode;

class AdminController extends BaseAdminController {
    public function regenerate_all_display_names(Request $request): Response {
        $n_wines = $this->count_wines_data();
        $n_loops = ceil($n_wines / 100);
        for ($i = 0; $i < $n_loops; $i++) {
            $wines = ModelQuery::fromModel(CellrWineData::class)->take(100, $i * 100)->fetchAsArray(true);
            foreach ($wines as $wine_data) {
                $wine = new CellrWineData();
                $wine->loadFromArray($wine_data);
                $new_display_name = $wine->createWineDisplayName($wine_data);
                $wine->set('display_name', $new_display_name, true);
            }
        }

        return new Response(true, ResponseCode::Ok, null, 'All display names have been regenerated.');
    }

    public function count_wines_data() {
        return ModelQuery::fromModel(CellrWineData::class)->count();
    }
}
?>