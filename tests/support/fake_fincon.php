<?php
declare(strict_types=1);

// Fake FinCon DataSnap server behind the tunnel - TEST USE ONLY (SPEC s10.4). Run as a php -S router:
//   php -S 127.0.0.1:<port> -t tests/support tests/support/fake_fincon.php
// Answers the read protocol with the SPEC s10.3 quantities and appends one JSON line per request to
// FAKE_FINCON_LOG: {"method","function","has_cf_id","has_cf_secret","has_basic","path"}.
// FAKE_FINCON_FORCE_STATUS=<http code> answers every request with that status (failure-mapping tests).

$quantities = ['F-SMP001' => 250, 'F-SMP002' => 8, 'F-SMP003' => 0, 'F-SMP004' => 40, 'F-SMP005' => 12];

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
$function = null;
if (preg_match('#^/datasnap/rest/FinconAPI/"?([A-Za-z0-9_]+)"?(/|$)#', $path, $m) === 1) {
    $function = $m[1];
}
$headers = array_change_key_case(function_exists('getallheaders') ? (getallheaders() ?: []) : [], CASE_LOWER);
$authorization = (string) ($headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));

$log = getenv('FAKE_FINCON_LOG');
if (is_string($log) && $log !== '') {
    $entry = [
        'method' => $method,
        'function' => $function,
        'has_cf_id' => trim((string) ($headers['cf-access-client-id'] ?? '')) !== '',
        'has_cf_secret' => trim((string) ($headers['cf-access-client-secret'] ?? '')) !== '',
        'has_basic' => stripos($authorization, 'Basic ') === 0 || isset($_SERVER['PHP_AUTH_USER']),
        'path' => $path,
    ];
    file_put_contents($log, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');

$force = (int) getenv('FAKE_FINCON_FORCE_STATUS');
if ($force > 0) {
    http_response_code($force);
    echo json_encode(['error' => 'forced status ' . $force]);
    return;
}

$stock = static function (array $itemNos) use ($quantities): string {
    $items = [];
    foreach ($itemNos as $itemNo) {
        if (array_key_exists($itemNo, $quantities)) {
            $items[] = ['ItemNo' => $itemNo, 'StockLoc' => [['LocNo' => '00', 'InStock' => $quantities[$itemNo], 'SalesOrders' => 0, 'PurchaseOrders' => 0]]];
        }
    }
    return (string) json_encode(['result' => [['Stock' => $items]]]);
};

switch ($function) {
    case 'Login':
        echo json_encode(['result' => [['Connected' => true, 'ConnectID' => 'TEST-CONNECT']]]);
        break;
    case 'GetStockQuantitiesForOpt2':
        $body = json_decode((string) file_get_contents('php://input'), true);
        $wanted = [];
        $list = (is_array($body) && isset($body['ItemList']) && is_array($body['ItemList'])) ? $body['ItemList'] : [];
        foreach ($list as $item) {
            if (is_array($item) && isset($item['ItemNo'])) {
                $wanted[] = (string) $item['ItemNo'];
            }
        }
        echo $stock($wanted);
        break;
    case 'GetStockQuantities':
        echo $stock(array_keys($quantities));
        break;
    case 'Logout':
        echo json_encode(['result' => [['Disconnected' => true]]]);
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'unknown function']);
}
