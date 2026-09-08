<?php
require_once __DIR__ . '/DatabaseTestCase.php';

final class RoomTest extends DatabaseTestCase
{
    public function testRoomWithAnActiveTenancyIsFlagged(): void
    {
        [, $roomId] = $this->makeTenantWithRoom('roomA', 1000, date('Y-m-01'));

        $this->assertTrue(roomHasActiveTenant($roomId));
    }

    public function testVacantRoomWithNoTenancyIsNotFlagged(): void
    {
        $this->db->prepare("INSERT INTO rooms (room_number, rental_price, status) VALUES ('TST-vac', 1000, 'available')")->execute();
        $roomId = (int) $this->db->lastInsertId();

        $this->assertFalse(roomHasActiveTenant($roomId));
    }

    public function testRoomWithOnlyATerminatedTenancyIsNotFlagged(): void
    {
        [$tenantId, $roomId] = $this->makeTenantWithRoom('roomB', 1000, date('Y-m-01'));
        $this->db->prepare("UPDATE tenancies SET status = 'terminated' WHERE tenant_id = ? AND room_id = ?")
            ->execute([$tenantId, $roomId]);

        $this->assertFalse(roomHasActiveTenant($roomId));
    }

    public function testNonExistentRoomIsNotFlagged(): void
    {
        $this->assertFalse(roomHasActiveTenant(999999));
    }
}
