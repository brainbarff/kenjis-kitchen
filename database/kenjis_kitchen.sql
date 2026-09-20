CREATE DATABASE IF NOT EXISTS kenjis_kitchen;
USE kenjis_kitchen;

CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role_id INT NOT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(80) NOT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    item_name VARCHAR(100) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    image VARCHAR(255),
    availability ENUM('Available','Unavailable') NOT NULL DEFAULT 'Available',
    promo_price DECIMAL(10,2) NULL,
    promo_start DATE NULL,
    promo_end DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

CREATE TABLE inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ingredient_name VARCHAR(100) NOT NULL,
    unit VARCHAR(30) NOT NULL,
    current_stock DECIMAL(10,2) NOT NULL DEFAULT 0,
    low_stock DECIMAL(10,2) NOT NULL DEFAULT 0,
    supplier VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE menu_ingredients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    menu_item_id INT NOT NULL,
    inventory_id INT NOT NULL,
    qty_needed DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE inventory_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT NOT NULL,
    user_id INT NOT NULL,
    action ENUM('Stock In','Stock Out','Adjustment','Order Deduction','Delivery','Waste/Spoilage') NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    remarks VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(30) NOT NULL UNIQUE,
    queue_no INT NOT NULL,
    user_id INT NULL,
    customer_name VARCHAR(100),
    order_type ENUM('DINE-IN','TAKE-OUT','ONLINE') NOT NULL,
    table_no VARCHAR(20),
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax DECIMAL(10,2) NOT NULL DEFAULT 0,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('Pending','Preparing','Ready','Served','Picked Up','Cancelled','Refunded','Voided') NOT NULL DEFAULT 'Pending',
    paid_at TIMESTAMP NULL,
    kitchen_queued_at TIMESTAMP NULL,
    preparing_at TIMESTAMP NULL,
    ready_at TIMESTAMP NULL,
    served_at TIMESTAMP NULL,
    served_by INT NULL,
    cancelled_at TIMESTAMP NULL,
    cancel_reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (served_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    item_name VARCHAR(100) NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    notes VARCHAR(255),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
) ENGINE=InnoDB;

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',
    amount_paid DECIMAL(10,2) NOT NULL,
    change_amount DECIMAL(10,2) NOT NULL,
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    receipt_no VARCHAR(30) NOT NULL UNIQUE,
    printed_at TIMESTAMP NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE cashier_shifts (
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

INSERT INTO roles (role_name) VALUES
('Admin'), ('Cashier'), ('Kitchen Staff'), ('Inventory Staff'), ('Server'), ('Manager');

INSERT INTO users (full_name, username, password, role_id) VALUES
('System Administrator', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi', 1),
('Kenji Cashier', 'cashier', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi', 2),
('Kitchen Staff', 'kitchen', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi', 3),
('Inventory Staff', 'inventory', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi', 4),
('Kenji Server', 'server', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi', 5),
('Kenji Manager', 'manager', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi', 6);

INSERT INTO categories (category_name) VALUES
('Student Meals'), ('Add-Ons'), ('Pulutan & Pares'), ('Silog Meals'), ('Silog Set Meals'), ('Bilao Favorites');

INSERT INTO menu_items (category_id, item_name, description, price, image, availability, promo_price, promo_start, promo_end) VALUES
(1, 'Burgersteak Rice', 'Student meal with burgersteak and rice.', 35.00, NULL, 'Available', NULL, NULL, NULL),
(1, 'Pancit Canton', 'Budget-friendly pancit canton meal.', 35.00, NULL, 'Available', NULL, NULL, NULL),
(1, 'Pastil Rice', 'Sulit pastil rice meal.', 30.00, NULL, 'Available', NULL, NULL, NULL),
(1, 'Pastilsilog', 'Pastil rice with silog add-ons.', 65.00, NULL, 'Available', NULL, NULL, NULL),
(1, 'Shanghai Rice', 'Shanghai rolls served with rice.', 46.00, NULL, 'Available', NULL, NULL, NULL),
(1, 'Siomai Rice', 'Siomai served with rice.', 40.00, NULL, 'Available', NULL, NULL, NULL),
(1, 'Siomaisilog', 'Siomai rice with egg.', 55.00, NULL, 'Available', NULL, NULL, NULL),
(2, 'Garlic Rice', 'Extra garlic rice.', 20.00, NULL, 'Available', NULL, NULL, NULL),
(2, 'Plain Rice', 'Extra plain rice.', 15.00, NULL, 'Available', NULL, NULL, NULL),
(2, 'Fried Egg', 'Extra fried egg.', 15.00, NULL, 'Available', NULL, NULL, NULL),
(3, 'Bagnet Pares', 'Pares meal with crispy bagnet.', 65.00, NULL, 'Available', NULL, NULL, NULL),
(3, 'Beef Pares', 'Classic beef pares.', 65.00, NULL, 'Available', NULL, NULL, NULL),
(3, 'Mami Pares', 'Pares mami noodles.', 75.00, NULL, 'Available', NULL, NULL, NULL),
(3, 'Mami Pares w/ Egg', 'Mami pares with egg.', 85.00, NULL, 'Available', NULL, NULL, NULL),
(3, 'Mami Pares w/ Rice', 'Mami pares with rice.', 90.00, NULL, 'Available', NULL, NULL, NULL),
(3, 'Mami Pares Bagnet', 'Mami pares with bagnet.', 75.00, NULL, 'Available', NULL, NULL, NULL),
(3, 'Papaitan', 'Savory papaitan bowl.', 85.00, NULL, 'Available', NULL, NULL, NULL),
(3, 'Bagnet', 'Crispy bagnet pulutan.', 250.00, NULL, 'Available', NULL, NULL, NULL),
(3, 'Chicken Fillet', 'Chicken fillet pulutan.', 140.00, NULL, 'Available', NULL, NULL, NULL),
(3, 'Dynamite', 'Spicy dynamite rolls.', 150.00, NULL, 'Available', NULL, NULL, NULL),
(3, 'Fried Bangus', 'Fried bangus plate.', 120.00, NULL, 'Available', NULL, NULL, NULL),
(3, 'Shanghai', 'Shanghai platter.', 140.00, NULL, 'Available', NULL, NULL, NULL),
(3, 'Sisig', 'Sizzling sisig.', 160.00, NULL, 'Available', NULL, NULL, NULL),
(3, 'Sizzling Hotdog', 'Sizzling hotdog plate.', 160.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Baconsilog', 'Bacon, sinangag, and egg.', 100.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Bagnetsilog', 'Bagnet, sinangag, and egg.', 150.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Bangsilog', 'Bangus, sinangag, and egg.', 100.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Chicksilog', 'Chicken, sinangag, and egg.', 135.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Filletsilog', 'Fillet, sinangag, and egg.', 115.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Hamsilog', 'Ham, sinangag, and egg.', 100.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Hotsilog', 'Hotdog, sinangag, and egg.', 95.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Hungariansilog', 'Hungarian sausage, sinangag, and egg.', 115.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Longsilog', 'Longganisa, sinangag, and egg.', 100.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Malingsilog', 'Maling, sinangag, and egg.', 100.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Porkchopsilog', 'Porkchop, sinangag, and egg.', 115.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Sisigsilog', 'Sisig, sinangag, and egg.', 120.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Spamsilog', 'Spam, sinangag, and egg.', 120.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Tapsilog', 'Tapa, sinangag, and egg.', 155.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Tinapasilog', 'Tinapa, sinangag, and egg.', 85.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Tocilog', 'Tocino, sinangag, and egg.', 100.00, NULL, 'Available', NULL, NULL, NULL),
(4, 'Tortasilog', 'Torta, sinangag, and egg.', 100.00, NULL, 'Available', NULL, NULL, NULL),
(5, 'Set Meal A', '2 Pastil Rice, Ham, and Egg.', 135.00, NULL, 'Available', NULL, NULL, NULL),
(5, 'Set Meal B', '2 Pastil Rice, Shanghai, and Egg.', 130.00, NULL, 'Available', NULL, NULL, NULL),
(5, 'Set Meal C', '2 Pastil Rice, Longganisa, and Egg.', 130.00, NULL, 'Available', NULL, NULL, NULL),
(5, 'Set Meal D', '2 Pastil Rice, Hotdog, and Egg.', 140.00, NULL, 'Available', NULL, NULL, NULL),
(5, 'Set Meal E', '2 Pastil Rice, Maling, and Egg.', 135.00, NULL, 'Available', NULL, NULL, NULL),
(5, 'Set Meal F', '2 Pastil Rice, Longganisa, Shanghai, and Egg.', 155.00, NULL, 'Available', NULL, NULL, NULL),
(5, 'Set Meal G', '2 Pastil Rice, Hotdog, Shanghai, and Egg.', 165.00, NULL, 'Available', NULL, NULL, NULL),
(5, 'Set Meal H', '2 Pastil Rice, Maling, Shanghai, and Egg.', 165.00, NULL, 'Available', NULL, NULL, NULL),
(5, 'Set Meal I', '2 Pastil Rice, Ham, Shanghai, and Egg.', 170.00, NULL, 'Available', NULL, NULL, NULL),
(6, 'Spaghetti Bilao Small', 'Good for sharing, small tray.', 500.00, NULL, 'Available', NULL, NULL, NULL),
(6, 'Spaghetti Bilao Medium', 'Good for sharing, medium tray.', 850.00, NULL, 'Available', NULL, NULL, NULL),
(6, 'Spaghetti Bilao Large', 'Good for sharing, large tray.', 1200.00, NULL, 'Available', NULL, NULL, NULL),
(6, 'Spaghetti Bilao XL', 'Good for sharing, extra large tray.', 1550.00, NULL, 'Available', NULL, NULL, NULL),
(6, 'Palabok Bilao Small', 'Palabok bilao small tray.', 650.00, NULL, 'Available', NULL, NULL, NULL),
(6, 'Bihon Bilao Small', 'Bihon bilao small tray.', 550.00, NULL, 'Available', NULL, NULL, NULL);

INSERT INTO inventory (ingredient_name, unit, current_stock, low_stock, supplier) VALUES
('Rice', 'kg', 25, 5, 'Local Supplier'),
('Chicken', 'kg', 12, 3, 'Fresh Meat Dealer'),
('Pork', 'kg', 8, 3, 'Fresh Meat Dealer'),
('Noodles', 'packs', 20, 5, 'Noodle House'),
('Iced Tea Mix', 'packs', 15, 4, 'Beverage Hub');

INSERT INTO menu_ingredients (menu_item_id, inventory_id, qty_needed) VALUES
(1, 1, 0.20), (2, 4, 1.00), (3, 1, 0.15), (4, 1, 0.20), (5, 1, 0.20), (6, 1, 0.20), (7, 1, 0.20);
