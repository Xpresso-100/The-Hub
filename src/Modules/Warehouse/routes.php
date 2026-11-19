<?php
declare(strict_types=1);

// Warehouse queue routes (build spec 2026-11-16 s6). Role checks live in the controller.

use Hub\Modules\Warehouse\QueueController;

return [
    ['GET', '/api/warehouse/queue', [QueueController::class, 'show']],
];
