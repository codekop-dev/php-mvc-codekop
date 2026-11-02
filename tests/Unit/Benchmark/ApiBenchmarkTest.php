<?php

declare(strict_types=1);

namespace Tests\Unit\Benchmark;

use PHPUnit\Framework\TestCase;
use System\Core\Benchmark\ApiBenchmark;

final class ApiBenchmarkTest extends TestCase
{
    public function testSummarizeComputesCountsAndPercentiles(): void
    {
        $benchmark = new ApiBenchmark();

        // Durations in ms: 10, 20, 30, ..., 100 (10 samples, status 200).
        $results = [];
        for ($i = 1; $i <= 10; $i++) {
            $results[] = ['status' => 200, 'time' => ($i * 10) / 1000, 'error' => null];
        }

        $report = $benchmark->summarize($results, 2.0);

        self::assertSame(10, $report['total']);
        self::assertSame(10, $report['success']);
        self::assertSame(0, $report['failed']);
        self::assertSame(5.0, $report['requests_per_second']);
        self::assertSame(10.0, $report['min_ms']);
        self::assertSame(100.0, $report['max_ms']);
        self::assertSame(55.0, $report['avg_ms']);
        self::assertSame(55.0, $report['median_ms']);
        self::assertSame(91.0, $report['p90_ms']);
        self::assertSame(95.5, $report['p95_ms']);
        self::assertSame([200 => 10], $report['status_codes']);
        self::assertSame([], $report['errors']);
    }

    public function testSummarizeSeparatesSuccessFailedAndErrors(): void
    {
        $benchmark = new ApiBenchmark();

        $results = [
            ['status' => 200, 'time' => 0.01, 'error' => null],
            ['status' => 301, 'time' => 0.02, 'error' => null],
            ['status' => 404, 'time' => 0.03, 'error' => null],
            ['status' => 500, 'time' => 0.04, 'error' => null],
            ['status' => 0, 'time' => 0.05, 'error' => 'Connection timed out'],
        ];

        $report = $benchmark->summarize($results, 1.0);

        self::assertSame(5, $report['total']);
        self::assertSame(2, $report['success']);
        self::assertSame(3, $report['failed']);
        self::assertSame(['Connection timed out'], $report['errors']);
        self::assertSame([0 => 1, 200 => 1, 301 => 1, 404 => 1, 500 => 1], $report['status_codes']);
    }

    public function testSummarizeHandlesEmptyResultsWithoutError(): void
    {
        $benchmark = new ApiBenchmark();

        $report = $benchmark->summarize([], 0.0);

        self::assertSame(0, $report['total']);
        self::assertSame(0, $report['success']);
        self::assertSame(0, $report['failed']);
        self::assertSame(0.0, $report['requests_per_second']);
        self::assertSame(0.0, $report['min_ms']);
        self::assertSame(0.0, $report['max_ms']);
        self::assertSame(0.0, $report['avg_ms']);
        self::assertSame(0.0, $report['median_ms']);
        self::assertSame([], $report['status_codes']);
        self::assertSame([], $report['errors']);
    }
}
