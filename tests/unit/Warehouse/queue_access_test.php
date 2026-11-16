<?php
declare(strict_types=1);

// Lane A test 1 (build spec 2026-11-16 s6): GET /api/warehouse/queue is hq only.

require __DIR__ . '/../../lib/harness.php';
require __DIR__ . '/wh_helpers.php';

test('WH-1: 401 without a token; 403 for hub.user and franchise.user; 200 for hq.admin', function (): void {
    $app = TestApp::start();
    assert_error($app->anon()->get('/api/warehouse/queue'), 401, 'UNAUTHENTICATED', 'anonymous GET');
    assert_error($app->request('GET', '/api/warehouse/queue', null, str_repeat('a', 64)), 401, 'UNAUTHENTICATED', 'unknown token');
    assert_error($app->client('hub.user')->get('/api/warehouse/queue'), 403, 'FORBIDDEN', 'hub GET');
    assert_error($app->client('hub2.user')->get('/api/warehouse/queue'), 403, 'FORBIDDEN', 'hub2 GET');
    assert_error($app->client('franchise.user')->get('/api/warehouse/queue'), 403, 'FORBIDDEN', 'franchise GET');
    $r = wh_queue($app->client('hq.admin'));
    assert_true(is_string($r->json['generated_at'] ?? null), 'generated_at is a string');
    hub_object($r, 'counts', 'counts');
    hub_list($r, 'orders', 'orders');
    hub_list($r, 'picklist', 'picklist');
});

test('WH-1: the queue route answers GET only', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    foreach (['post', 'put', 'patch'] as $method) {
        assert_status(405, $hq->{$method}('/api/warehouse/queue', []), strtoupper($method) . ' /api/warehouse/queue');
    }
    assert_status(405, $hq->delete('/api/warehouse/queue'), 'DELETE /api/warehouse/queue');
});

run_tests();
