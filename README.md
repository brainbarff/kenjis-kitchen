# Online Ordering, Sales and Inventory Management System for Kenji's Kitchen

## Kenji's Kitchen Remake

This is the remade version of the **Online Ordering, Sales and Inventory Management System for Kenji's Kitchen**, based on the updated system module specification.

The remake expands the original system to support **cross-platform access, role-based user management, Kenji's Kitchen preset menu items, Philippine Peso pricing, tax-free transactions, branded receipts, inventory tracking, Point of Sale (POS), Kitchen Display System (KDS), order serving, online ordering, reports, bills management, transaction approvals, notifications, and system monitoring.**

The project uses:

```text
HTML
CSS
Vanilla JavaScript
PHP
MySQL
PDO
```

---

# All System Modules and Features

The system follows this workflow:

```text
User Management
       ↓
Menu Management
       ↓
Inventory Management
       ↓
Point of Sale (POS)
       ↓
Kitchen Order Management / KDS
       ↓
Order Serving and Display
       ↓
Online Ordering
       ↓
Reports / Financials / Bills
       ↓
Transaction Approval
       ↓
Notification and System Monitoring
```

---

## Module 1 – Cross-Platform System Compatibility

This module documents the supported devices and planned hardware integrations.

### Supported Devices

```text
Android phones and tablets
iOS phones and tablets
Windows PCs and POS terminals
macOS laptops and desktops
Smart displays for KDS
```

### Planned Hardware Integrations

```text
Bluetooth thermal printer
USB thermal printer
Wi-Fi thermal printer
Wireless barcode reader
Kitchen display screen
```

### System Functions

* Universal device login and session syncing
* Responsive access across supported devices
* POS terminal compatibility
* KDS/smart display compatibility
* Support planning for Bluetooth, USB, and Wi-Fi devices

### Page

```text
modules/compatibility/index.php
```

> **Note:** Hardware integrations are currently represented as planned integration workflows. Actual Bluetooth printers, USB printers, Wi-Fi printers, and barcode readers require the physical hardware and appropriate drivers for testing.

---

# Module 2 – User Management

This module handles employee accounts, authentication, roles, and permissions.

### Users / Roles

```text
Admin
Cashier
Kitchen Staff
Inventory Staff
```

Manager and Server are archived legacy roles. Their accounts are inactive and cannot sign in. `Customer (Online)` is a separate account role used by the storefront.

### Functions

* Login and Logout
* Manage User Accounts
* Create employee accounts
* Assign user roles
* Assign permissions
* Restrict module access based on role
* Manage account status

### Role Access

**Admin**

* Dashboard
* User Management
* Menu Management
* Inventory Management
* POS
* Kitchen
* Reports
* Bills
* Transaction Approval
* System Monitoring

**Cashier**

* POS

**Inventory Staff**

* Inventory Management

**Kitchen Staff**

* Kitchen Display System / Kitchen Order Management

**Customer**

* Online Ordering

---

# Module 3 – Menu Management and Preset Catalog

This module manages the restaurant's menu and preset catalog.

### Main Categories

```text
Student Meals
Add-Ons
Pulutan & Pares
Silog Meals
Silog Set Meals
Bilao Favorites
```

### Functions

* Add menu items
* Edit menu items
* Delete menu items
* Manage menu categories
* Set item prices
* Update item availability
* Manage promotions
* Upload/manage menu images
* Manage dynamic pricing
* Display menu items in Philippine Peso (₱)

The menu uses **tax-free Philippine Peso pricing** based on the current project specification.

---

# Module 4 – Inventory Management

This module manages ingredients, supplies, stock movement, and inventory monitoring.

### Users

```text
Inventory Staff
Admin
```

### Functions

* Manage ingredients and supplies
* Record Stock In
* Record Stock Out
* Record deliveries
* Record waste/spoilage
* Record inventory adjustments
* Deduct inventory based on orders
* Update inventory levels
* Monitor low-stock items
* Track per-serving inventory
* Track inventory used by menu items

### Inventory Transactions

```text
Stock In
Stock Out
Delivery
Waste / Spoilage
Adjustment
Order Deduction
```

---

# Module 5 – Point of Sale (POS)

The POS handles restaurant orders, payments, discounts, and receipts.

### User

```text
Cashier
```

### Functions

* Create orders
* Customize orders
* Process payments
* Apply discounts
* Select payment method
* Print receipts
* View order details
* Calculate order totals
* Connect orders to kitchen processing

### Payment Structure

```text
Cash
Card
E-Wallet
```

### POS Requirements

```text
Tax-free pricing
Philippine Peso currency
Discount support
Branded receipt
Order number generation
Payment recording
```

### Receipt Branding

```text
KENJI'S kitchen

From Silog to Sulit Meals, Busog Ka Dito!

Florante At Laura St.

09687430373
```

---

# Module 6 – Kitchen Display System (KDS) / Kitchen Order Management

The KDS manages incoming restaurant orders and displays them to kitchen staff.

### User

```text
Kitchen Staff
```

### Functions

* View incoming orders
* View order details
* Display order number
* Display order type
* Update order status
* Organize orders using FIFO
* Display online and POS orders
* Monitor active kitchen orders

### Order Types

```text
DINE-IN
TAKE-OUT
ONLINE
```

### Order Status

```text
Pending
   ↓
Preparing
   ↓
Ready
```

### KDS Requirements

**Order Type Visibility**

The KDS must clearly display whether an order is:

```text
DINE-IN
TAKE-OUT
ONLINE
```

**Online Order Integration**

Online orders should appear on the KDS after successful payment.

```text
Online Order
     ↓
Payment Successful
     ↓
KDS
```

**FIFO Order Queue**

The KDS follows a strict **First-In, First-Out (FIFO)** system so kitchen staff can prepare orders based on the order they were received.

---

# Module 7 – Order Serving and Display

This module manages the process of delivering completed orders to customers.

### User

```text
Admin / Cashier / Kitchen Staff
```

### Functions

* View ready orders
* Display order number
* Mark orders as served
* Bump orders
* Print order slips
* Update serving status
* Monitor completed/picked-up orders

### Serving Workflow

```text
Online / POS Order
        ↓
     Kitchen
        ↓
      Ready
        ↓
  Serving staff
        ↓
Served / Picked Up
```

---

# Module 8 – Online Ordering

This module provides the customer-facing online ordering interface.

### User

```text
Customer
```

### Pages & Endpoints

```text
online-menu/index.php
online-menu/api/orders.php
online-menu/api/menu.php
online-menu/api/auth.php
online-menu/api/proof_image.php
```

### Functions

* Browse menu & categories
* Search menu items and view real-time item stock availability
* Filter by price & popular categories
* Customize orders (special instructions, packaging, bilao sizes, drink flavors)
* Add items to cart & local cart synchronization
* Checkout with Email OTP authentication
* Select payment method:
  * **Cash on Delivery (COD) / Pay on Pickup:** Automatically queues to `modules/pos/cod_approvals.php` for staff/cashier delivery assignment and cash collection tracking.
  * **GCash (QR Code Payment):** Upload payment receipt screenshot (JPG, PNG, WebP) and 13-digit reference number. Automatically feeds to `modules/pos/payment_verification.php` with duplicate reference & hash checks.
* Track order status (Pending → Preparing → Ready → Served / Picked Up / Delivered)
* View order history & digital receipt summaries

### Current Implementation

The online ordering storefront currently uses:

```text
PHP/MySQL APIs for the catalog, customer accounts, checkout, and order history
localStorage & reactive state for cart/UI state
Authenticated image streaming (proof_image.php) for uploaded payment receipts
WebSocket / Polling sync for order status updates
```

Online checkout records orders, payment proofs, and pending payments in MySQL. Finished menu-item stock is counted in pieces and deducted when an order is placed.

Within Lagro, Kenji's Kitchen manages free delivery through the Admin/Cashier dispatch queue. Outside Lagro, the customer books and pays a third-party rider separately and uses GCash for the food. The customer or staff can save the provider's HTTPS tracking link, which the customer can open from My Orders. The system records handoff and delivery status; live rider location is shown by the third-party provider.

Cashiers may request a void for a Pending order or a refund for a completed order. Admin approves or rejects these requests. Approved voids restore finished menu-item stock; approved refunds retain the stock deduction and record manual Cash or GCash repayment details.

---

# Module 9 – Reports, Financial Dashboard and Bills Management

This module provides financial and operational information for administrators.

### Users

```text
Admin
```

### Page

```text
modules/reports/index.php
```

### Functions

* Generate sales reports
* Generate inventory reports
* View transaction reports
* Monitor staff activity
* View total sales
* View paid bills
* View active bills
* View best-selling items
* View financial summaries
* Generate printable reports

### Dashboard Information

```text
Total Sales
Paid Bills
Active Bills
Best-Selling Items
Inventory Information
Transaction Information
```

---

# Module 10 – Transaction Approval, QR Verification, and System Monitoring

This module handles transaction approvals, payment proofs, delivery dispatch, and operational alerts.

### Users

```text
Admin
Cashier
```

### Transaction & Operations Workflows

1. **QR Verification (`modules/pos/payment_verification.php`):**
   * Review submitted customer GCash screenshots, declared amounts, and reference numbers.
   * Automated duplicate reference number and image hash collision checks.
   * One-click verification releases the order to the Kitchen queue (`Preparing`).
   * One-click rejection with customizable reason returns the order with resubmission attempts allowed up to 3 times before stock auto-cancellation.

2. **COD Approvals (`modules/pos/cod_approvals.php`):**
   * Inspect customer delivery addresses, past failed deliveries, and order value.
   * Approve orders to release to Kitchen, assign delivery personnel, and confirm cash handovers into the active cashier shift balance.

3. **Admin Approvals Hub (`modules/approvals/index.php`):**
   * Stock In / Stock Out / Inventory Adjustment requests from inventory personnel.
   * Category additions, deletions, and menu item addition requests with image previews.
   * Void authorization and refund approvals with audit logs.

4. **Sidebar Notification Badges (`includes/sidebar.php`):**
   * Live yellow badge counters alert staff when pending QR verifications, pending COD orders, or Admin approval requests are awaiting action.

### Notification Functions

* Low-stock alerts
* Order status notifications
* Real-time pending queue alerts
* System error notifications
* System outage messages

### Pages

```text
modules/pos/payment_verification.php
modules/pos/cod_approvals.php
modules/approvals/index.php
modules/settings/payments.php
```

---

# Database Setup

For a **fresh installation on an empty database server**, import the maintained baseline:

```text
schema.sql
```

`database/kenjis_kitchen.sql` is a legacy local export and is not the current installation baseline. Do not import either file over an operational database. Inspect the existing schema and use the reviewed migrations in `database/README_MIGRATIONS.md`.

If you already have the older database and only want to reset/update the menu catalog, run:

```text
database/remake_catalog_update.sql
```

For updating an older version of the system, you may also need:

```text
database/rbac_update.sql
database/operational_update.sql
```

---

# Demo Accounts

The fresh-install demo accounts in `schema.sql` use the same password:

```text
password123
```

### Usernames

```text
admin
cashier
kitchen
inventory
```

### Demo Role Access

```text
admin     → Admin
cashier   → Cashier
kitchen   → Kitchen Staff
inventory → Inventory Staff
```

If you need to reset or update the demo account roles, run:

```text
database/rbac_update.sql
```

in phpMyAdmin.

---

# Installation / How to Run

### 1. Open the XAMPP folder

Open:

```text
C:\xampp\
```

### 2. Open the htdocs folder

```text
C:\xampp\htdocs\
```

### 3. Paste the project folder

Copy:

```text
kenjis-kitchen-remake
```

into:

```text
C:\xampp\htdocs\
```

You may rename the folder to:

```text
kenjis-kitchen
```

### 4. Start XAMPP

Open the XAMPP Control Panel and start:

```text
Apache
MySQL
```

### 5. Import the database

Open phpMyAdmin and import:

```text
schema.sql
```

### 6. Check database configuration

Verify the database credentials in:

```text
config/db.php
```

### 7. Open the system

If the folder is named `kenjis-kitchen`, open:

```text
http://localhost/kenjis-kitchen/login.php
```

### Online Ordering

Open:

```text
http://localhost/kenjis-kitchen/online-menu/
```

Open this URL through Apache with MySQL running. Opening `online-menu/index.php` directly from the file system (`file://` or a browser `File` URL) cannot run the PHP menu API and will not display live catalog items.

---

# Important Development Notes

This project is currently under development. The menu catalog and some thesis features still require review and integration.

### For Team Members

* If you encounter an error, inform **Erika** immediately.
* Double-check the folder where you are placing files before adding or replacing anything.
* Ask first if you are unsure whether a file or feature should be added.
* If you want a module fixed or changed, inform the team before modifying it.
* Inform the team if you add a new feature or make a major system change.
* Keep the project structure organized.
* Always check whether your changes affect another module.
* The project will also be uploaded to Git for easier access by all members.

### Checkout reliability and cancelled-order refunds

Checkout saves retry confirmations atomically in `checkout_requests` (`(channel, actor_id, request_key)`), replaying the original receipt on identical retries and rejecting changed payloads reusing the same key. Cancelled paid orders support a separate Admin-approved refund without returning stock a second time. Use `php tools/check_schema.php --database=kenjis_kitchen` as a read-only schema compatibility check.

### Current Development Status

The repository currently has user management, menu management, finished menu-item inventory, POS, online ordering, kitchen, serving, reports, void/refund approval, and delivery dispatch modules. The fresh-install schema supports these modules. Online GCash orders enter the kitchen queue after staff verifies payment; cash on delivery and pay on pickup orders enter when placed. Pickup orders can be marked served after the kitchen marks them Ready. Online delivery orders use the dispatch workflow after Ready.

---

# Important Hardware Note

Some hardware integrations are currently planned rather than fully tested.

These include:

```text
Bluetooth thermal printers
USB thermal printers
Wi-Fi thermal printers
Wireless barcode readers
Physical KDS devices
```

Actual hardware testing requires the physical device, compatible drivers, browser permissions, and/or additional integration methods.

The web system is prepared for these integrations through:

* Receipt layouts
* Printing pages
* Device compatibility documentation
* Browser-based KDS screens
* Responsive POS interfaces

---

# Project Technology

```text
Frontend:
HTML
CSS
Vanilla JavaScript

Backend:
PHP

Database:
MySQL

Database Connection:
PDO

Local Development:
XAMPP

Version Control:
Git / GitHub
```

---

# Project Structure

The project uses a modular structure so each major restaurant operation can be developed and maintained separately.

```text
kenjis-kitchen/
│
├── config/
│   └── db.php
│
├── database/
│   ├── migrations/
│   ├── remake_catalog_update.sql
│   ├── rbac_update.sql
│   └── operational_update.sql
│
├── modules/
│   ├── compatibility/
│   ├── users/
│   ├── menu/
│   ├── inventory/
│   ├── pos/
│   ├── kitchen/
│   ├── serving/
│   ├── online/
│   ├── bills/
│   └── approvals/
│
└── login.php
```

> Folder names may change as development continues. Always check the latest project structure before adding new files.

---

# Final Development Reminder

This is a collaborative project. Please communicate before making major changes to the system.

If you encounter an error, especially in the active modules, report it immediately so it can be checked before additional changes are made.

```text
ERROR FOUND
     ↓
Inform Erika / Team
     ↓
Identify affected module
     ↓
Check recent changes
     ↓
Fix and test
```

The goal is to keep the Kenji's Kitchen system organized, functional, and easy for every team member to access and maintain.
