<?php
declare(strict_types=1);

namespace Hub\Fincon;

use Hub\Clock;
use Hub\Config;

/**
 * FinCon DataSnap REST through the existing Cloudflare Tunnel - same protocol as the reference
 * fincon_client.php (CODEBASE_MAP s4, SPEC s10.2). READ ONLY: only the functions in
 * ALLOWED_FUNCTIONS can be called. Never logs credentials, headers or the ConnectID.
 */
final class HttpFinconStockReader implements FinconStockReader
{
    /** Session handling and stock reads only. Adding a write function here is out of scope for Phase 1. */
    public const ALLOWED_FUNCTIONS = ['Login', 'GetStockQuantitiesForOpt2', 'GetStockQuantities', 'Logout'];

    private const BATCH_SIZE = 100;

    /**
     * @param array{base_url:string,cf_client_id:string,cf_client_secret:string,srv_user:string,srv_pass:string,
     *              data_id:string,user:string,pass:string,loc_no:string,timeout:int} $cfg
     */
    public function __construct(private readonly array $cfg)
    {
    }

    public static function fromConfig(): self
    {
        return new self([
            'base_url' => Config::get('FINCON_BASE_URL'),
            'cf_client_id' => Config::get('FINCON_CF_CLIENT_ID'),
            'cf_client_secret' => Config::get('FINCON_CF_CLIENT_SECRET'),
            'srv_user' => Config::get('FINCON_SRV_USER'),
            'srv_pass' => Config::get('FINCON_SRV_PASS'),
            'data_id' => Config::get('FINCON_DATA_ID'),
            'user' => Config::get('FINCON_USER'),
            'pass' => Config::get('FINCON_PASS'),
            'loc_no' => Config::get('FINCON_LOC_NO'),
            'timeout' => max(1, Config::int('FINCON_TIMEOUT_SECONDS')),
        ]);
    }

    public function readStock(array $itemNos): FinconResult
    {
        if (trim($this->cfg['base_url']) === '') {
            return FinconResult::failure(FinconResult::ERROR, 'FINCON_BASE_URL is not configured');
        }
        $itemNos = array_values(array_unique(array_filter(
            array_map('strval', $itemNos),
            static fn (string $s): bool => $s !== ''
        )));
        if ($itemNos === []) {
            return FinconResult::ok([]);
        }

        [$loginResult, $connectId] = $this->login();
        if ($connectId === null) {
            return $loginResult;
        }
        try {
            $quantities = [];
            foreach (array_chunk($itemNos, self::BATCH_SIZE) as $chunk) {
                $resp = $this->call('POST', 'GetStockQuantitiesForOpt2', [$connectId, $this->cfg['loc_no']], [
                    'ItemList' => array_map(static fn (string $no): array => ['ItemNo' => $no], $chunk),
                ]);
                $failure = $this->transportFailure($resp);
                if ($failure !== null) {
                    return $failure;
                }
                $payload = self::unwrap($resp['body']);
                if ($payload === null || !empty($payload['ErrorInfo'])) {
                    return FinconResult::failure(FinconResult::ERROR, 'FinCon returned an error or an unreadable stock response');
                }
                if (!isset($payload['Stock']) || !is_array($payload['Stock'])) {
                    return FinconResult::failure(FinconResult::ERROR, 'FinCon stock response has no Stock list');
                }
                foreach ($payload['Stock'] as $item) {
                    if (!is_array($item) || !isset($item['ItemNo'])) {
                        continue;
                    }
                    $loc = (isset($item['StockLoc']) && is_array($item['StockLoc'])) ? ($item['StockLoc'][0] ?? null) : null;
                    if (is_array($loc) && isset($loc['InStock']) && is_numeric($loc['InStock'])) {
                        $quantities[(string) $item['ItemNo']] = (float) $loc['InStock'];
                    }
                }
            }
            return FinconResult::ok($quantities);
        } finally {
            $this->logout($connectId);
        }
    }

    /** @return array{0:FinconResult,1:?string} */
    private function login(): array
    {
        $resp = $this->call('POST', 'Login', [], [
            '_parameters' => [$this->cfg['data_id'], $this->cfg['user'], $this->cfg['pass'], false],
        ]);
        $failure = $this->transportFailure($resp);
        if ($failure !== null) {
            return [$failure, null];
        }
        $payload = self::unwrap($resp['body']);
        if (is_array($payload) && !empty($payload['ErrorInfo'])) {
            return [FinconResult::failure(FinconResult::ERROR, 'FinCon refused the login'), null];
        }
        $connectId = (is_array($payload) && !empty($payload['Connected']) && isset($payload['ConnectID']) && $payload['ConnectID'] !== '')
            ? (string) $payload['ConnectID'] : null;
        if ($connectId === null) {
            $status = self::inMaintenanceWindow() ? FinconResult::MAINTENANCE : FinconResult::ERROR;
            return [FinconResult::failure($status, 'FinCon did not return a connected session'), null];
        }
        return [FinconResult::ok([]), $connectId];
    }

    /** Best effort; never throws. */
    private function logout(string $connectId): void
    {
        try {
            $this->call('GET', 'Logout', [$connectId], null);
        } catch (\Throwable) {
            // releasing the session must never break the read
        }
    }

    /** @return array{errno:int,err:string,http:int,body:string} */
    private function call(string $method, string $function, array $params, ?array $body): array
    {
        if (!in_array($function, self::ALLOWED_FUNCTIONS, true)) {
            throw new \LogicException('FinCon function is not on the read-only allowlist: ' . $function);
        }
        // Quotes wrap the method name only and are sent literally; params unquoted; trailing slash (reference).
        $url = rtrim($this->cfg['base_url'], '/') . '/datasnap/rest/FinconAPI/"' . $function . '"';
        foreach ($params as $param) {
            $url .= '/' . rawurlencode((string) $param);
        }
        $url .= '/';

        $headers = [
            'CF-Access-Client-Id: ' . $this->cfg['cf_client_id'],
            'CF-Access-Client-Secret: ' . $this->cfg['cf_client_secret'],
            'Accept: application/json',
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => (int) $this->cfg['timeout'],
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->cfg['srv_user'] . ':' . $this->cfg['srv_pass'],
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? new \stdClass()));
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $respBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['errno' => $errno, 'err' => $err, 'http' => $http, 'body' => is_string($respBody) ? $respBody : ''];
    }

    /** Reference status mapping: no response -> UNREACHABLE (MAINTENANCE in the window); 401/403 -> AUTH_FAILED. */
    private function transportFailure(array $resp): ?FinconResult
    {
        if ($resp['errno'] !== 0 || $resp['http'] === 0) {
            $status = self::inMaintenanceWindow() ? FinconResult::MAINTENANCE : FinconResult::UNREACHABLE;
            return FinconResult::failure($status, 'No response from FinCon (curl ' . $resp['errno'] . ')');
        }
        if ($resp['http'] === 401 || $resp['http'] === 403) {
            return FinconResult::failure(FinconResult::AUTH_FAILED, 'FinCon refused the credentials (HTTP ' . $resp['http'] . ')');
        }
        if ($resp['http'] < 200 || $resp['http'] >= 300) {
            return FinconResult::failure(FinconResult::UNREACHABLE, 'FinCon answered HTTP ' . $resp['http']);
        }
        return null;
    }

    /** DataSnap wraps payloads as {"result":[{...}]}; returns the inner object or null when unreadable. */
    private static function unwrap(string $body): ?array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        if (isset($data['result']) && is_array($data['result']) && isset($data['result'][0]) && is_array($data['result'][0])) {
            return $data['result'][0] + (isset($data['ErrorInfo']) ? ['ErrorInfo' => $data['ErrorInfo']] : []);
        }
        return $data;
    }

    /** FinCon's daily maintenance window, 15:25-15:30 SAST. */
    private static function inMaintenanceWindow(): bool
    {
        $hm = Clock::now()->format('H:i');
        return $hm >= '15:25' && $hm < '15:30';
    }
}
