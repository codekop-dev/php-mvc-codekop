<?php
declare(strict_types=1);

namespace System\Core\Benchmark;

/**
 * Concurrent HTTP load generator used to benchmark an API endpoint (own
 * project routes or any external URL) via curl_multi, plus a pure
 * summarizer that turns raw timings into a report.
 */
final class ApiBenchmark
{
    /**
     * @param list<string> $headers Raw header lines, e.g. "Authorization: Bearer xyz".
     * @return list<array{status:int,time:float,error:?string}>
     */
    public function run(
        string $url,
        int $requests = 100,
        int $concurrency = 10,
        string $method = 'GET',
        ?string $body = null,
        array $headers = [],
        float $timeout = 10.0,
        bool $verifyTls = true
    ): array {
        $requests = max(1, $requests);
        $concurrency = max(1, min($concurrency, $requests));
        $method = strtoupper($method);
        $remaining = $requests;
        $results = [];

        $spawn = static function () use ($url, $method, $body, $headers, $timeout, $verifyTls): \CurlHandle {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
                CURLOPT_SSL_VERIFYPEER => $verifyTls,
                CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            if ($body !== null && $method !== 'GET' && $method !== 'HEAD') {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
            }
            return $handle;
        };

        $multi = curl_multi_init();
        /** @var array<int,\CurlHandle> $active */
        $active = [];

        for ($i = 0; $i < $concurrency && $remaining > 0; $i++) {
            $handle = $spawn();
            curl_multi_add_handle($multi, $handle);
            $active[spl_object_id($handle)] = $handle;
            $remaining--;
        }

        do {
            do {
                $status = curl_multi_exec($multi, $stillRunning);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($stillRunning) {
                curl_multi_select($multi, 1.0);
            }

            while (($info = curl_multi_info_read($multi)) !== false) {
                $handle = $info['handle'];
                unset($active[spl_object_id($handle)]);

                $results[] = [
                    'status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
                    'time' => (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME),
                    'error' => curl_errno($handle) !== 0 ? curl_error($handle) : null,
                ];

                curl_multi_remove_handle($multi, $handle);

                if ($remaining > 0) {
                    $next = $spawn();
                    curl_multi_add_handle($multi, $next);
                    $active[spl_object_id($next)] = $next;
                    $remaining--;
                }
            }
        } while ($active !== [] || $remaining > 0);

        curl_multi_close($multi);

        return $results;
    }

    /**
     * Pure summarizer: turns raw run() results into a report. Kept separate
     * from run() so it can be unit tested without real network calls.
     *
     * @param list<array{status:int,time:float,error:?string}> $results
     * @return array{
     *   total:int,
     *   success:int,
     *   failed:int,
     *   wall_time:float,
     *   requests_per_second:float,
     *   min_ms:float,
     *   max_ms:float,
     *   avg_ms:float,
     *   median_ms:float,
     *   p90_ms:float,
     *   p95_ms:float,
     *   p99_ms:float,
     *   status_codes:array<int,int>,
     *   errors:list<string>
     * }
     */
    public function summarize(array $results, float $wallTime): array
    {
        $total = \count($results);
        $durationsMs = [];
        $statusCodes = [];
        $errors = [];
        $success = 0;

        foreach ($results as $result) {
            $durationsMs[] = $result['time'] * 1000;
            $statusCodes[$result['status']] = ($statusCodes[$result['status']] ?? 0) + 1;

            if ($result['error'] !== null) {
                $errors[] = $result['error'];
                continue;
            }

            if ($result['status'] >= 200 && $result['status'] < 400) {
                $success++;
            }
        }

        sort($durationsMs);
        ksort($statusCodes);

        return [
            'total' => $total,
            'success' => $success,
            'failed' => $total - $success,
            'wall_time' => round($wallTime, 4),
            'requests_per_second' => $wallTime > 0 ? round($total / $wallTime, 2) : 0.0,
            'min_ms' => $durationsMs === [] ? 0.0 : round($durationsMs[0], 2),
            'max_ms' => $durationsMs === [] ? 0.0 : round($durationsMs[\count($durationsMs) - 1], 2),
            'avg_ms' => $durationsMs === [] ? 0.0 : round(array_sum($durationsMs) / \count($durationsMs), 2),
            'median_ms' => self::percentile($durationsMs, 50.0),
            'p90_ms' => self::percentile($durationsMs, 90.0),
            'p95_ms' => self::percentile($durationsMs, 95.0),
            'p99_ms' => self::percentile($durationsMs, 99.0),
            'status_codes' => $statusCodes,
            'errors' => $errors,
        ];
    }

    /** @param list<float> $sortedMs */
    private static function percentile(array $sortedMs, float $percentile): float
    {
        $count = \count($sortedMs);
        if ($count === 0) {
            return 0.0;
        }

        $rank = ($percentile / 100) * ($count - 1);
        $lowIndex = (int) floor($rank);
        $highIndex = (int) ceil($rank);

        if ($lowIndex === $highIndex) {
            return round($sortedMs[$lowIndex], 2);
        }

        $weight = $rank - $lowIndex;
        $value = $sortedMs[$lowIndex] + ($sortedMs[$highIndex] - $sortedMs[$lowIndex]) * $weight;

        return round($value, 2);
    }
}
