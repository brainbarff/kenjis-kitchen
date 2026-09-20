<?php
// database/seed_users.php
require_once __DIR__ . '/../config/db.php';

// Define each user with their corresponding <username>123 password
$accounts = [
    [
        'full_name' => 'System Administrator',
        'username'  => 'admin',
        'password'  => 'admin123',
        'role_name' => 'Admin'
    ],
    [
        'full_name' => 'Kenji Cashier',
        'username'  => 'cashier',
        'password'  => 'cashier123',
        'role_name' => 'Cashier'
    ],
    [
        'full_name' => 'Kitchen Staff',
        'username'  => 'kitchen',
        'password'  => 'kitchen123',
        'role_name' => 'Kitchen Staff'
    ],
    [
        'full_name' => 'Inventory Staff',
        'username'  => 'inventory',
        'password'  => 'inventory123',
        'role_name' => 'Inventory Staff'
    ]
];

try {
    $conn->beginTransaction();

    foreach ($accounts as $acc) {
        $roleStmt = $conn->prepare("SELECT id FROM roles WHERE role_name = ? LIMIT 1");
        $roleStmt->execute([$acc['role_name']]);
        $role = $roleStmt->fetch();

        if (!$role) {
            echo "Skipped: Role '{$acc['role_name']}' not found.<br>";
            continue;
        }

        $hashedPassword = password_hash($acc['password'], PASSWORD_BCRYPT);

        $stmt = $conn->prepare("
            INSERT INTO users (full_name, username, password, role_id, status)
            VALUES (?, ?, ?, ?, 'Active')
            ON DUPLICATE KEY UPDATE
                password = VALUES(password),
                role_id = VALUES(role_id),
                status = 'Active'
        ");

        $stmt->execute([
            $acc['full_name'],
            $acc['username'],
            $hashedPassword,
            $role['id']
        ]);

        echo "User <strong>{$acc['username']}</strong> set with password: <code>{$acc['password']}</code><br>";
    }

    $conn->commit();
    echo "<br><strong>All accounts synchronized successfully!</strong>";

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "Seeding failed: " . htmlspecialchars($e->getMessage());
}