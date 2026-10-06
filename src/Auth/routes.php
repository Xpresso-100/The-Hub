<?php
declare(strict_types=1);

use Hub\Auth\AuthController;

// Core routes (SPEC s6). Only /api/health and /api/login are public.
return [
    ['GET', '/api/health', [AuthController::class, 'health']],
    ['POST', '/api/login', [AuthController::class, 'login']],
    ['POST', '/api/logout', [AuthController::class, 'logout']],
    ['GET', '/api/me', [AuthController::class, 'me']],
];
