<?php
declare(strict_types=1);

// M1-ordering routes (SPEC s7). Role checks live in the controllers.

use Hub\Modules\Ordering\IngredientController;
use Hub\Modules\Ordering\OrderController;
use Hub\Modules\Ordering\StockController;

return [
    ['GET', '/api/stock', [StockController::class, 'show']],
    ['GET', '/api/ingredients', [IngredientController::class, 'list']],
    ['PUT', '/api/admin/ingredients/{sku}', [IngredientController::class, 'put']],
    ['POST', '/api/orders', [OrderController::class, 'create']],
    ['GET', '/api/orders', [OrderController::class, 'list']],
    ['GET', '/api/orders/{id}', [OrderController::class, 'show']],
    ['PATCH', '/api/orders/{id}/status', [OrderController::class, 'updateStatus']],
];
