<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Console commands can only run from CLI.' . PHP_EOL);
}

define('ROOTPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('BASEPATH', ROOTPATH);

require_once ROOTPATH . 'system/Config/Config.php';
require_once ROOTPATH . 'app/Config/Config.php';
require_once ROOTPATH . 'app/Config/Constants.php';
require_once ROOTPATH . 'app/Config/Database.php';

$autoload = ROOTPATH . 'vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

use System\Core\Benchmark\ApiBenchmark;
use System\Queue\Queue;

$command = $argv[1] ?? 'list';
if ($command === 'api:bench') {
    $url = null;
    $requests = 100;
    $concurrency = 10;
    $method = 'GET';
    $body = null;
    $headers = [];
    $timeout = 10.0;
    $verifyTls = true;

    foreach (array_slice($argv, 2) as $argument) {
        if ($argument === '--insecure') {
            $verifyTls = false;
        } elseif (str_starts_with($argument, '--url=')) {
            $url = substr($argument, 6);
        } elseif (str_starts_with($argument, '--requests=')) {
            $requests = max(1, (int) substr($argument, 11));
        } elseif (str_starts_with($argument, '--concurrency=')) {
            $concurrency = max(1, (int) substr($argument, 14));
        } elseif (str_starts_with($argument, '--method=')) {
            $method = substr($argument, 9);
        } elseif (str_starts_with($argument, '--body=')) {
            $body = substr($argument, 7);
        } elseif (str_starts_with($argument, '--header=')) {
            $headers[] = substr($argument, 9);
        } elseif (str_starts_with($argument, '--timeout=')) {
            $timeout = max(0.1, (float) substr($argument, 10));
        } else {
            fwrite(STDERR, 'Unknown option: ' . $argument . PHP_EOL);
            exit(2);
        }
    }

    if ($url === null || $url === '') {
        fwrite(STDERR, 'Missing required option: --url=' . PHP_EOL);
        exit(2);
    }

    $benchmark = new ApiBenchmark();
    $start = microtime(true);
    $results = $benchmark->run($url, $requests, $concurrency, $method, $body, $headers, $timeout, $verifyTls);
    $wallTime = microtime(true) - $start;
    $report = $benchmark->summarize($results, $wallTime);

    fwrite(STDOUT, 'Benchmark: ' . $method . ' ' . $url . PHP_EOL);
    fwrite(STDOUT, sprintf('  requests=%d concurrency=%d timeout=%.1fs', $requests, $concurrency, $timeout) . PHP_EOL);
    fwrite(STDOUT, sprintf('  total=%d success=%d failed=%d wall_time=%.3fs rps=%.2f', $report['total'], $report['success'], $report['failed'], $report['wall_time'], $report['requests_per_second']) . PHP_EOL);
    fwrite(STDOUT, sprintf('  min=%.2fms avg=%.2fms median=%.2fms p90=%.2fms p95=%.2fms p99=%.2fms max=%.2fms', $report['min_ms'], $report['avg_ms'], $report['median_ms'], $report['p90_ms'], $report['p95_ms'], $report['p99_ms'], $report['max_ms']) . PHP_EOL);

    fwrite(STDOUT, '  status codes:' . PHP_EOL);
    foreach ($report['status_codes'] as $code => $count) {
        fwrite(STDOUT, sprintf('    %s: %d', $code === 0 ? 'error' : (string) $code, $count) . PHP_EOL);
    }

    if ($report['errors'] !== []) {
        fwrite(STDOUT, '  sample errors:' . PHP_EOL);
        foreach (array_slice($report['errors'], 0, 5) as $error) {
            fwrite(STDOUT, '    - ' . $error . PHP_EOL);
        }
    }

    exit(0);
}

if ($command === 'queue:work') {
    $queue = null;
    $once = false;
    $sleep = 0;
    $maxJobs = 0;
    foreach (array_slice($argv, 2) as $argument) {
        if ($argument === '--once') {
            $once = true;
        } elseif (str_starts_with($argument, '--queue=')) {
            $queue = substr($argument, 8) ?: null;
        } elseif (str_starts_with($argument, '--sleep=')) {
            $sleep = max(0, (int) substr($argument, 8));
        } elseif (str_starts_with($argument, '--max-jobs=')) {
            $maxJobs = max(0, (int) substr($argument, 11));
        } else {
            fwrite(STDERR, 'Unknown option: ' . $argument . PHP_EOL);
            exit(2);
        }
    }

    try {
        Queue::work($queue, $once, $sleep, $maxJobs);
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

fwrite(STDOUT, 'Codekop console' . PHP_EOL);
fwrite(STDOUT, '  queue:work [--queue=name] [--once] [--sleep=3] [--max-jobs=10]' . PHP_EOL);
fwrite(STDOUT, '  api:bench --url=http://host/api/health [--requests=100] [--concurrency=10]' . PHP_EOL);
fwrite(STDOUT, '            [--method=GET] [--header="Name: Value"] [--body=...] [--timeout=10] [--insecure]' . PHP_EOL);
exit($command === 'list' ? 0 : 1);
