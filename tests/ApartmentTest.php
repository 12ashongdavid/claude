<?php
require_once __DIR__ . '/DatabaseTestCase.php';

final class ApartmentTest extends DatabaseTestCase
{
    public function testApartmentWithAnActiveTenancyIsFlagged(): void
    {
        [, $apartmentId] = $this->makeTenantWithApartment('aptA', 1000, date('Y-m-01'));

        $this->assertTrue(apartmentHasActiveTenant($apartmentId));
    }

    public function testVacantApartmentWithNoTenancyIsNotFlagged(): void
    {
        $this->db->prepare("INSERT INTO apartments (apartment_number, rental_price, status) VALUES ('TST-vac', 1000, 'available')")->execute();
        $apartmentId = (int) $this->db->lastInsertId();

        $this->assertFalse(apartmentHasActiveTenant($apartmentId));
    }

    public function testApartmentWithOnlyATerminatedTenancyIsNotFlagged(): void
    {
        [$tenantId, $apartmentId] = $this->makeTenantWithApartment('aptB', 1000, date('Y-m-01'));
        $this->db->prepare("UPDATE tenancies SET status = 'terminated' WHERE tenant_id = ? AND apartment_id = ?")
            ->execute([$tenantId, $apartmentId]);

        $this->assertFalse(apartmentHasActiveTenant($apartmentId));
    }

    public function testNonExistentApartmentIsNotFlagged(): void
    {
        $this->assertFalse(apartmentHasActiveTenant(999999));
    }
}
