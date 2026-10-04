USE kenjis_kitchen;

ALTER TABLE inventory_logs
MODIFY action ENUM('Stock In','Stock Out','Adjustment','Order Deduction','Delivery','Waste/Spoilage') NOT NULL;

ALTER TABLE orders
MODIFY status ENUM('Pending','Preparing','Ready','Served','Picked Up','Cancelled','Refunded','Voided') NOT NULL DEFAULT 'Pending';

ALTER TABLE orders
ADD COLUMN paid_at TIMESTAMP NULL AFTER status,
ADD COLUMN kitchen_queued_at TIMESTAMP NULL AFTER paid_at,
ADD COLUMN preparing_at TIMESTAMP NULL AFTER kitchen_queued_at,
ADD COLUMN ready_at TIMESTAMP NULL AFTER preparing_at,
ADD COLUMN served_at TIMESTAMP NULL AFTER ready_at,
ADD COLUMN served_by INT NULL AFTER served_at,
ADD COLUMN cancelled_at TIMESTAMP NULL AFTER served_by,
ADD COLUMN cancel_reason VARCHAR(255) AFTER cancelled_at;

CREATE TABLE IF NOT EXISTS cashier_shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    opening_cash DECIMAL(10,2) NOT NULL DEFAULT 0,
    closing_cash DECIMAL(10,2) NULL,
    expected_cash DECIMAL(10,2) NULL,
    variance DECIMAL(10,2) NULL,
    status ENUM('Open','Closed') NOT NULL DEFAULT 'Open',
    opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL,
    remarks VARCHAR(255),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT INTO roles (id, role_name) VALUES
(5, 'Server')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

INSERT INTO users (full_name, username, password, role_id, status)
VALUES ('Kenji Server', 'server', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi', 5, 'Active')
ON DUPLICATE KEY UPDATE role_id = 5, status = 'Active';

UPDATE orders
SET paid_at = created_at,
    kitchen_queued_at = created_at
WHERE paid_at IS NULL
  AND status IN ('Pending','Preparing','Ready','Served');
