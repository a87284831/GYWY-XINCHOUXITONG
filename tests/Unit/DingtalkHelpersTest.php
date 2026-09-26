<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DingtalkHelpersTest extends TestCase
{
    private function parseHireDate(mixed $raw): ?string
    {
        $raw = (int) ($raw ?: 0);
        $ts = intdiv($raw, 1000);
        return ($raw > 0 && $ts > 946684800) ? date('Y-m-d', $ts) : null;
    }

    public function test_parseHireDate_valid_timestamp(): void
    {
        $this->assertEquals('2023-06-15', $this->parseHireDate(1686787200000));
    }

    public function test_parseHireDate_zero_returns_null(): void
    {
        $this->assertNull($this->parseHireDate(0));
    }

    public function test_parseHireDate_empty_string_returns_null(): void
    {
        $this->assertNull($this->parseHireDate(''));
    }

    public function test_parseHireDate_null_returns_null(): void
    {
        $this->assertNull($this->parseHireDate(null));
    }

    public function test_parseHireDate_before_2000_returns_null(): void
    {
        $this->assertNull($this->parseHireDate(946598400000));
    }

    public function test_parseHireDate_exactly_2000_returns_null(): void
    {
        $this->assertNull($this->parseHireDate(946684800000));
    }

    public function test_parseHireDate_just_after_2000_returns_date(): void
    {
        $this->assertEquals('2000-01-02', $this->parseHireDate(946771200000));
    }
}
