USE kenjis_kitchen;

INSERT INTO roles (id, role_name) VALUES
(1, 'Admin'),
(2, 'Cashier'),
(3, 'Kitchen Staff'),
(4, 'Inventory Staff'),
(5, 'Server'),
(6, 'Manager')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

UPDATE users
SET role_id = 1, status = 'Active'
WHERE username = 'admin';

UPDATE users
SET role_id = 2, status = 'Active'
WHERE username = 'cashier';

UPDATE users
SET role_id = 3, status = 'Active'
WHERE username = 'kitchen';

UPDATE users
SET role_id = 4, status = 'Active'
WHERE username = 'inventory';

INSERT INTO users (full_name, username, password, role_id, status)
VALUES ('Kenji Server', 'server', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi', 5, 'Active')
ON DUPLICATE KEY UPDATE role_id = 5, status = 'Active';

INSERT INTO users (full_name, username, password, role_id, status)
VALUES ('Kenji Manager', 'manager', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi', 6, 'Active')
ON DUPLICATE KEY UPDATE role_id = 6, status = 'Active';
