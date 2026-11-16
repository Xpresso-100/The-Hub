<?php
declare(strict_types=1);

// Lane C account routes (build spec 2026-11-16 s8). Every route is hq only; role checks live in the controller.

use Hub\Modules\Accounts\AccountController;

return [
    ['GET', '/api/admin/locations', [AccountController::class, 'locations']],
    ['GET', '/api/admin/users', [AccountController::class, 'list']],
    ['POST', '/api/admin/users', [AccountController::class, 'create']],
    ['PATCH', '/api/admin/users/{id}', [AccountController::class, 'setActive']],
];
