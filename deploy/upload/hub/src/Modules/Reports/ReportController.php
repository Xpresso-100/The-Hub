<?php
declare(strict_types=1);

namespace Hub\Modules\Reports;

use Hub\Auth\Auth;
use Hub\Clock;
use Hub\Http\HttpError;
use Hub\Http\Request;
use Hub\Http\Response;

/** SPEC s9.1: GET /api/reports/finished-goods - "finished goods for HQ" (JSON or CSV). */
final class ReportController
{
    public static function finishedGoods(Request $req, array $params = []): Response
    {
        $user = Auth::requireRole($req, 'hq', 'hub');
        $errors = [];
        $from = self::date($req, 'from', Clock::firstOfMonth(), $errors);
        $to = self::date($req, 'to', Clock::today(), $errors);
        if ($from !== null && $to !== null && $from > $to) {
            $errors['from'] = 'must not be after to';
        }
        $format = $req->query('format', 'json');
        if ($format !== 'json' && $format !== 'csv') {
            $errors['format'] = 'must be json or csv';
        }
        $locationId = self::locationScope($req, $user, $errors);
        if ($errors !== []) {
            throw HttpError::validation($errors);
        }

        $report = ReportService::finishedGoods((string) $from, (string) $to, $locationId);
        if ($format === 'csv') {
            return Response::csv(ReportService::finishedGoodsCsv($report), 'finished-goods_' . $from . '_' . $to . '.csv');
        }
        return Response::ok(ReportService::publicReport($report));
    }

    /**
     * A YYYY-MM-DD query value; $default when absent. Returns null (and records an error) when invalid.
     * @param array<string,string> $errors
     */
    public static function date(Request $req, string $key, ?string $default, array &$errors): ?string
    {
        $value = $req->query($key);
        if ($value === null) {
            return $default;
        }
        if (!Clock::isDate($value)) {
            $errors[$key] = 'must be a date YYYY-MM-DD';
            return null;
        }
        return $value;
    }

    /**
     * Location filter under the SPEC s3 scope rule: hub -> always its own location (any location_id
     * sent is ignored); hq -> ?location_id= if given (positive integer of an existing location), else null = all.
     * @param array<string,string> $errors
     */
    public static function locationScope(Request $req, array $user, array &$errors): ?int
    {
        if ($user['role'] === 'hub') {
            return (int) $user['location_id'];
        }
        $id = self::positiveInt($req, 'location_id', $errors);
        if ($id !== null && !ReportService::locationExists($id)) {
            $errors['location_id'] = 'unknown location';
            return null;
        }
        return $id;
    }

    /**
     * An optional positive-integer query value; absent or empty -> null.
     * @param array<string,string> $errors
     */
    public static function positiveInt(Request $req, string $key, array &$errors): ?int
    {
        $value = $req->query($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^[1-9][0-9]{0,9}$/', $value) !== 1 || (int) $value > 2147483647) {
            $errors[$key] = 'must be a positive integer';
            return null;
        }
        return (int) $value;
    }
}
