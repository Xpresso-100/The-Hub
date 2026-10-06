<?php
declare(strict_types=1);

// M1-ordering routes (SPEC s7). Role checks live in the controllers.

use Hub\Modules\Ordering\IngredientController;

return [
    ['GET', '/api/ingredients', [IngredientController::class, 'list']],
    ['PUT', '/api/admin/ingredients/{sku}', [IngredientController::class, 'put']],
];
