<?php

declare(strict_types=1);

namespace Test\VitexSoftware\PohodaZabbix;

use PHPUnit\Framework\TestCase;
use VitexSoftware\PohodaZabbix\DigestFeeder;

class DigestFeederTest extends TestCase
{
    public function testParsePeriodMonthReturnsLastThirtyDays(): void
    {
        $period = DigestFeeder::parsePeriod('month');
        $days = $period->getStartDate()->diff($period->getEndDate())->days;

        $this->assertGreaterThanOrEqual(29, $days);
        $this->assertLessThanOrEqual(31, $days);
    }

    public function testParsePeriodEmptyStringDefaultsToMonth(): void
    {
        $period = DigestFeeder::parsePeriod('');
        $this->assertInstanceOf(\DatePeriod::class, $period);
        $this->assertGreaterThanOrEqual(29, $period->getStartDate()->diff($period->getEndDate())->days);
    }

    public function testParsePeriodYearStartsJanuaryFirst(): void
    {
        $period = DigestFeeder::parsePeriod('year');
        $this->assertSame('01-01', $period->getStartDate()->format('m-d'));
        $this->assertSame((int) date('Y'), (int) $period->getStartDate()->format('Y'));
    }

    public function testParsePeriodSpecificYear(): void
    {
        $period = DigestFeeder::parsePeriod('2024');
        $this->assertSame('2024-01-01', $period->getStartDate()->format('Y-m-d'));
        $this->assertSame('2024-12-31', $period->getEndDate()->format('Y-m-d'));
    }

    public function testParsePeriodSpecificYearSpans365Or366Days(): void
    {
        $period = DigestFeeder::parsePeriod('2024');
        $days = $period->getStartDate()->diff($period->getEndDate())->days;
        $this->assertSame(365, $days); // 2024 is a leap year: Jan 1 to Dec 31 = 365 days diff
    }

    public function testParsePeriodInvalidSpecThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DigestFeeder::parsePeriod('quarterly');
    }

    public function testParsePeriodCaseInsensitive(): void
    {
        $this->assertInstanceOf(\DatePeriod::class, DigestFeeder::parsePeriod('MONTH'));
        $this->assertInstanceOf(\DatePeriod::class, DigestFeeder::parsePeriod('Year'));
    }

    public function testParsePeriodIntervalIsOneDay(): void
    {
        $period = DigestFeeder::parsePeriod('month');
        $interval = $period->getDateInterval();
        $this->assertSame(1, $interval->d);
        $this->assertSame(0, $interval->m);
    }
}
