<?php
require_once __DIR__ . '/DatabaseTestCase.php';

final class TenantFinanceTest extends DatabaseTestCase
{
    public function testArrearsOwesOneMonthWhenTenancyStartedThisMonthWithNoPayments(): void
    {
        [$tenantId] = $this->makeTenantWithRoom('arr1', 1000, date('Y-m-01'));

        $result = computeTenantArrears($tenantId);

        $this->assertSame(1000.0, $result['owing']);
        $this->assertSame(1, $result['months']);
        $this->assertNull($result['last_covered']);
    }

    public function testArrearsAccumulateForEveryUnpaidMonthSinceMoveIn(): void
    {
        [$tenantId] = $this->makeTenantWithRoom('arr2', 1000, date('Y-m-d', strtotime('-3 months')));

        $result = computeTenantArrears($tenantId);

        // Move-in month plus the 3 months since = 4 months owed
        $this->assertSame(4000.0, $result['owing']);
        $this->assertSame(4, $result['months']);
    }

    public function testArrearsOnlyChargeFromTheMonthAfterTheLastCompletedPayment(): void
    {
        [$tenantId, $roomId] = $this->makeTenantWithRoom('arr3', 1000, date('Y-m-d', strtotime('-3 months')));
        $this->addCompletedPayment($tenantId, $roomId, date('Y-m', strtotime('-1 month')), 3000);

        $result = computeTenantArrears($tenantId);

        // Paid through last month, so only the current month is owed
        $this->assertSame(1000.0, $result['owing']);
        $this->assertSame(1, $result['months']);
    }

    public function testArrearsAreZeroWhenPaidThroughTheCurrentMonth(): void
    {
        [$tenantId, $roomId] = $this->makeTenantWithRoom('arr4', 1000, date('Y-m-d', strtotime('-2 months')));
        $this->addCompletedPayment($tenantId, $roomId, date('Y-m'), 3000);

        $result = computeTenantArrears($tenantId);

        $this->assertSame(0.0, $result['owing']);
        $this->assertSame(0, $result['months']);
    }

    public function testArrearsAreZeroForAnAdvancePaymentBeyondTheCurrentMonth(): void
    {
        [$tenantId, $roomId] = $this->makeTenantWithRoom('arr5', 1000, date('Y-m-01'));
        $this->addCompletedPayment($tenantId, $roomId, date('Y-m', strtotime('+2 months')), 1000);

        $result = computeTenantArrears($tenantId);

        $this->assertSame(0.0, $result['owing']);
    }

    public function testArrearsAreZeroForATenantWithNoActiveTenancy(): void
    {
        $result = computeTenantArrears(999999);

        $this->assertSame(0.0, $result['owing']);
        $this->assertSame(0, $result['months']);
        $this->assertNull($result['last_covered']);
    }

    public function testPaidThroughRangeIsNullWithNoCompletedPayments(): void
    {
        [$tenantId] = $this->makeTenantWithRoom('range1', 1000, date('Y-m-01'));

        $this->assertNull(getTenantPaidThroughRange($tenantId));
    }

    public function testPaidThroughRangeShowsASingleMonthWithoutASeparator(): void
    {
        [$tenantId, $roomId] = $this->makeTenantWithRoom('range2', 1000, date('Y-m-01'));
        $this->addCompletedPayment($tenantId, $roomId, '2026-01', 1000);

        $this->assertSame('Jan 2026', getTenantPaidThroughRange($tenantId));
    }

    // Regression test for a real bug: MIN()/MAX() came back unaliased, so
    // reading $row[0]/$row[1] under PDO's FETCH_ASSOC default silently
    // failed and this always returned null. See config/database.php.
    public function testPaidThroughRangeJoinsMultipleMonthsWithTo(): void
    {
        [$tenantId, $roomId] = $this->makeTenantWithRoom('range3', 1000, date('Y-m-01'));
        $this->addCompletedPayment($tenantId, $roomId, '2026-01', 1000);
        $this->addCompletedPayment($tenantId, $roomId, '2026-02', 1000);
        $this->addCompletedPayment($tenantId, $roomId, '2026-03', 1000);

        $range = getTenantPaidThroughRange($tenantId);

        $this->assertSame('Jan 2026 to Mar 2026', $range);
        $this->assertStringNotContainsString('-', $range);
    }

    public function testGetTenantPaidMonthsReturnsOnlyCompletedMonthsSorted(): void
    {
        [$tenantId, $roomId] = $this->makeTenantWithRoom('month1', 1000, date('Y-m-01'));
        $this->addCompletedPayment($tenantId, $roomId, '2026-03', 1000);
        $this->addCompletedPayment($tenantId, $roomId, '2026-01', 1000);
        $this->db->prepare("INSERT INTO rent_payments (tenant_id, room_id, amount, payment_date, payment_method, month_covered, status) VALUES (?, ?, 1000, CURDATE(), 'cash', '2026-02', 'failed')")
            ->execute([$tenantId, $roomId]);

        $this->assertSame(['2026-01', '2026-03'], getTenantPaidMonths($tenantId));
    }

    public function testGetTenantNextDueMonthIsCurrentMonthWhenBehindOrUnpaid(): void
    {
        [$tenantId] = $this->makeTenantWithRoom('next1', 1000, date('Y-m-01'));

        $this->assertSame(date('Y-m'), getTenantNextDueMonth($tenantId));
    }

    public function testGetTenantNextDueMonthIsAfterTheLastPaidMonth(): void
    {
        [$tenantId, $roomId] = $this->makeTenantWithRoom('next2', 1000, date('Y-m-01'));
        $futureMonth = date('Y-m', strtotime('+3 months'));
        $this->addCompletedPayment($tenantId, $roomId, $futureMonth, 1000);

        $expected = date('Y-m', strtotime($futureMonth . '-01 +1 month'));
        $this->assertSame($expected, getTenantNextDueMonth($tenantId));
    }
}
