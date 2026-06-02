<?php



/**

 * Creates or resets demo accounts (university admin + warden).

 * Used by scripts/seed.php and public/seed_demo.php.

 */



function demo_seed_find_user_id_by_email(PDO $db, string $email): ?int

{

    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');

    $stmt->execute([$email]);

    $row = $stmt->fetch();

    return $row ? (int)$row['id'] : null;

}



function demo_seed_create_user(PDO $db, string $name, string $email, string $password, string $role, ?string $regNo = null, ?string $nin = null, ?string $phone = null, ?string $institution = null): int

{

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare('

        INSERT INTO users (name, email, password_hash, role, reg_no, nin, phone, institution)

        VALUES (?, ?, ?, ?, ?, ?, ?, ?)

    ');

    $stmt->execute([$name, $email, $hash, $role, $regNo, $nin, $phone, $institution]);

    return (int)$db->lastInsertId();

}



function demo_seed_ensure_user(PDO $db, string $name, string $email, string $password, string $role, ?string $regNo = null, ?string $nin = null, ?string $phone = null, ?string $institution = null): int

{

    $existingId = demo_seed_find_user_id_by_email($db, $email);

    $hash = password_hash($password, PASSWORD_DEFAULT);



    if ($existingId) {

        $stmt = $db->prepare('

            UPDATE users

            SET name = ?, password_hash = ?, role = ?, reg_no = ?, nin = ?, phone = ?, institution = ?

            WHERE id = ?

        ');

        $stmt->execute([$name, $hash, $role, $regNo, $nin, $phone, $institution, $existingId]);

        return $existingId;

    }



    return demo_seed_create_user($db, $name, $email, $password, $role, $regNo, $nin, $phone, $institution);

}



/**

 * @return array{admin_id:int, warden_id:int}

 */

function demo_seed_demo_accounts(PDO $db): array

{

    $adminEmail = 'university.admin@hms.local';

    $adminPass = 'Admin12345';

    $adminName = 'University Admin';



    $wardenEmail = 'warden@hms.local';

    $wardenPass = 'Warden12345';

    $wardenName = 'Hostel Warden';



    $adminId = demo_seed_ensure_user($db, $adminName, $adminEmail, $adminPass, 'university_admin', null, null, null, null);

    $wardenId = demo_seed_ensure_user($db, $wardenName, $wardenEmail, $wardenPass, 'warden', null, 'CM960882EB01', null, null);



    return ['admin_id' => $adminId, 'warden_id' => $wardenId];

}

