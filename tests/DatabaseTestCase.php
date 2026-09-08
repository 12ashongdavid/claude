<?php
use PHPUnit\Framework\TestCase;

// Base class for tests that touch the database. Wraps each test in a
// transaction that's rolled back afterward, so tests can freely insert
// fixture rows without leaking state into other tests or permanently
// altering the seed data.
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = getDB();
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    /** Insert a tenant + room + active tenancy, returning their ids. */
    protected function makeTenantWithRoom(string $label, float $monthlyRent, string $startDate): array
    {
        $this->db->prepare("INSERT INTO users (username, password, full_name, email, phone, role) VALUES (?, 'x', ?, ?, '0200000000', 'tenant')")
            ->execute(["test_$label", "Test $label", "test_$label@example.com"]);
        $tenantId = (int) $this->db->lastInsertId();

        $this->db->prepare("INSERT INTO rooms (room_number, rental_price, status) VALUES (?, ?, 'occupied')")
            ->execute(["TST-$label", $monthlyRent]);
        $roomId = (int) $this->db->lastInsertId();

        $this->db->prepare("INSERT INTO tenancies (tenant_id, room_id, start_date, monthly_rent, status) VALUES (?, ?, ?, ?, 'active')")
            ->execute([$tenantId, $roomId, $startDate, $monthlyRent]);

        return [$tenantId, $roomId];
    }

    protected function addCompletedPayment(int $tenantId, int $roomId, string $monthCovered, float $amount): void
    {
        $this->db->prepare("INSERT INTO rent_payments (tenant_id, room_id, amount, payment_date, payment_method, month_covered, status) VALUES (?, ?, ?, CURDATE(), 'cash', ?, 'completed')")
            ->execute([$tenantId, $roomId, $amount, $monthCovered]);
    }
}
