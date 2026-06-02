<?php
// Seed script for local testing (XAMPP).
// Usage:
//   C:\xampp\php\php.exe c:\xampp\htdocs\HMS\scripts\seed.php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/demo_seed.php';

$db = hms_db();

function ensureHostel(PDO $db, string $name, string $location, ?string $description, ?int $managedBy): int
{
    $stmt = $db->prepare('SELECT id FROM hostels WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if ($row) {
        $hostelId = (int)$row['id'];
        $y = (int)date('Y');
        $defaultStart = sprintf('%d-09-01', $y);
        $defaultEnd = sprintf('%d-05-31', $y + 1);
        $db->prepare('
            UPDATE hostels
            SET location = ?, description = ?, managed_by = ?, is_active = 1,
                rent_period_start = COALESCE(rent_period_start, ?),
                rent_period_end = COALESCE(rent_period_end, ?)
            WHERE id = ?
        ')->execute([$location, $description, $managedBy, $defaultStart, $defaultEnd, $hostelId]);
        return $hostelId;
    }

    $y = (int)date('Y');
    $defaultStart = sprintf('%d-09-01', $y);
    $defaultEnd = sprintf('%d-05-31', $y + 1);
    $stmt = $db->prepare('
        INSERT INTO hostels (name, location, description, is_active, managed_by, rent_period_start, rent_period_end)
        VALUES (?, ?, ?, 1, ?, ?, ?)
    ');
    $stmt->execute([$name, $location, $description, $managedBy, $defaultStart, $defaultEnd]);
    return (int)$db->lastInsertId();
}

function ensureRoom(PDO $db, int $hostelId, string $roomNumber, int $capacity, string $gender, float $monthlyFee): void
{
    $stmt = $db->prepare('SELECT id FROM rooms WHERE hostel_id = ? AND room_number = ? LIMIT 1');
    $stmt->execute([$hostelId, $roomNumber]);
    $row = $stmt->fetch();
    if ($row) {
        $db->prepare('UPDATE rooms SET capacity = ?, gender = ?, monthly_fee = ? WHERE id = ?')
            ->execute([$capacity, $gender, $monthlyFee, (int)$row['id']]);
        return;
    }

    $db->prepare('
        INSERT INTO rooms (hostel_id, room_number, capacity, current_occupancy, gender, monthly_fee)
        VALUES (?, ?, ?, 0, ?, ?)
    ')->execute([$hostelId, $roomNumber, $capacity, $gender, $monthlyFee]);
}

$accounts = demo_seed_demo_accounts($db);
echo "Ensured demo accounts (passwords reset).\n";
echo "  University Admin: university.admin@hms.local / Admin12345\n";
echo "  Warden: warden@hms.local / Warden12345\n";

$wardenId = $accounts['warden_id'];

$hostelCount = (int)($db->query('SELECT COUNT(*) AS c FROM hostels')->fetch()['c'] ?? 0);
if ($hostelCount === 0) {
    $hostelId = ensureHostel(
        $db,
        "Ndejje Campus Hostel Block A",
        "Kampala (Near Campus)",
        "Sample hostel for system testing.",
        $wardenId
    );

    ensureRoom($db, $hostelId, '101', 2, 'male', 150000);
    ensureRoom($db, $hostelId, '102', 2, 'male', 150000);
    ensureRoom($db, $hostelId, '201', 3, 'female', 170000);
    ensureRoom($db, $hostelId, '202', 3, 'female', 170000);
    ensureRoom($db, $hostelId, '301', 2, 'mixed', 160000);

    echo "Seeded sample hostels and rooms.\n";
} else {
    echo "Hostels already exist; skipping sample room seeding.\n";
}

echo "Done.\n";
