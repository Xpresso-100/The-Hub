<?php
declare(strict_types=1);

use Hub\Modules\Recipes\BatchController;
use Hub\Modules\Recipes\RecipeController;

// M2-recipes routes (SPEC s8).
return [
    ['GET', '/api/recipes', [RecipeController::class, 'list']],
    ['POST', '/api/recipes', [RecipeController::class, 'create']],
    ['GET', '/api/recipes/{id}', [RecipeController::class, 'show']],
    ['PUT', '/api/recipes/{id}', [RecipeController::class, 'update']],
    ['POST', '/api/batches', [BatchController::class, 'create']],
    ['GET', '/api/batches/{id}', [BatchController::class, 'show']],
];
