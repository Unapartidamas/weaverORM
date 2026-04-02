<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\PyroSQL\Approximate\ApproximateResult;

final class ApproximateResultTest extends TestCase
{
    public function test_get_value_returns_value(): void
    {
        $result = new ApproximateResult(value: 42, errorMargin: 1.0, confidence: 95.0, sampledRows: 100, totalRows: 1000, isApproximate: true);

        self::assertSame(42, $result->getValue());
    }

    public function test_to_float_returns_float_cast(): void
    {
        $result = new ApproximateResult(value: 42, errorMargin: 1.0, confidence: 95.0, sampledRows: 100, totalRows: 1000, isApproximate: true);

        self::assertSame(42.0, $result->toFloat());
    }

    public function test_to_int_returns_int_cast(): void
    {
        $result = new ApproximateResult(value: 42.9, errorMargin: 1.0, confidence: 95.0, sampledRows: 100, totalRows: 1000, isApproximate: true);

        self::assertSame(42, $result->toInt());
    }

    public function test_to_string_shows_approx_marker_when_approximate(): void
    {
        $result = new ApproximateResult(value: 1234, errorMargin: 2.3, confidence: 95.0, sampledRows: 1000, totalRows: 5000, isApproximate: true);

        $str = (string) $result;

        self::assertStringContainsString('≈', $str);
        self::assertStringContainsString('1234', $str);
    }

    public function test_to_string_shows_exact_marker_when_not_approximate(): void
    {
        $result = new ApproximateResult(value: 1234, errorMargin: 0.0, confidence: 100.0, sampledRows: 5000, totalRows: 5000, isApproximate: false);

        self::assertStringContainsString('=', (string) $result);
    }

    public function test_confidence_and_error_margin_accessors(): void
    {
        $result = new ApproximateResult(value: 100, errorMargin: 3.5, confidence: 99.0, sampledRows: 500, totalRows: 2000, isApproximate: true);

        self::assertSame(3.5, $result->errorMargin);
        self::assertSame(99.0, $result->confidence);
    }
}
