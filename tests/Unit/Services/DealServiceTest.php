<?php

namespace Tests\Unit\Services;

use App\Services\DealService;
use Tests\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;

class DealServiceTest extends TestCase
{
    private DealService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Instantiate with a mock repository — we only test pure calculation methods here
        $deals         = \Mockery::mock(\App\Repositories\DealRepositoryInterface::class);
        $this->service = new DealService($deals);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    // ── calculateMonthlyPayment ───────────────────────────────────────────

    #[DataProvider('monthlyPaymentProvider')]
    public function test_calculate_monthly_payment(
        int $principal,
        float $apr,
        int $term,
        int $expected,
    ): void {
        $result = $this->service->calculateMonthlyPayment($principal, $apr, $term);

        $this->assertEqualsWithDelta($expected, $result, 5, "Monthly payment should be ~{$expected} cents");
    }

    /** @return array<string, array{int, float, int, int}> */
    public static function monthlyPaymentProvider(): array
    {
        return [
            // $20 000 at 6% for 60 months → ~$386.66/mo → 38666 cents
            '20k_6pct_60mo'  => [2_000_000, 6.0, 60, 38_665],
            // $10 000 at 0% for 36 months → $277.78/mo → 27778 cents
            '10k_0pct_36mo'  => [1_000_000, 0.0, 36, 27_778],
            // Zero principal → 0
            'zero_principal' => [0, 5.0, 60, 0],
            // Zero term → 0
            'zero_term'      => [1_000_000, 5.0, 0, 0],
        ];
    }

    public function test_zero_down_payment_gives_zero(): void
    {
        $this->assertSame(0, $this->service->calculateMonthlyPayment(0, 7.5, 48));
    }

    public function test_high_apr_increases_payment(): void
    {
        $low  = $this->service->calculateMonthlyPayment(1_500_000, 3.0, 48);
        $high = $this->service->calculateMonthlyPayment(1_500_000, 15.0, 48);

        $this->assertGreaterThan($low, $high);
    }

    public function test_longer_term_decreases_monthly_payment(): void
    {
        $short = $this->service->calculateMonthlyPayment(2_000_000, 6.0, 36);
        $long  = $this->service->calculateMonthlyPayment(2_000_000, 6.0, 72);

        $this->assertGreaterThan($long, $short);
    }
}
