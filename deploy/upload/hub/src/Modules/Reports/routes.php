<?php
declare(strict_types=1);

use Hub\Modules\Reports\AdminListController;
use Hub\Modules\Reports\ReportController;

// M3-reports (SPEC s9). Read only.
return [
    ['GET', '/api/reports/finished-goods', [ReportController::class, 'finishedGoods']],
    ['GET', '/api/admin/recipes', [AdminListController::class, 'recipes']],
    ['GET', '/api/admin/batches', [AdminListController::class, 'batches']],
];
