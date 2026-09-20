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
Server
Manager
```

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

**Manager**

* Dashboard
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

**Server**

* Order Serving and Display

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
Server
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
      Server
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

### Page

```text
modules/online/index.html
```

### Functions

* Browse menu
* Browse categories
* Search menu items
* Filter by price
* Customize orders
* Add items to cart
* Checkout
* Select payment method
* Make online payment
* Track order status
* View order history
* Reorder previous orders

### Current Implementation

The online ordering frontend currently uses:

```text
Mock Data
localStorage
Frontend UI
```

The current version is primarily frontend-based. Full backend payment processing and production online-order integration can be added in future development.

---

# Module 9 – Reports, Financial Dashboard and Bills Management

This module provides financial and operational information for administrators and managers.

### Users

```text
Admin
Manager
```

### Page

```text
modules/bills/index.php
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

# Module 10 – Transaction Approval, Notification and System Monitoring

This module handles transaction approvals, alerts, and system monitoring.

### Users

```text
Admin
Manager
Customer
```

### Transaction Approval Functions

* Approve refund requests
* Approve void transactions
* View transaction history
* Authorize void transactions
* Review refund requests

### Notification Functions

* Low-stock alerts
* Order status notifications
* System error notifications
* System outage messages

### System Monitoring

The system prepares workflows for:

```text
Void Authorization
Refund Approval
Low Inventory Alerts
Order Status Monitoring
System Outage / Error Alerts
```

### Page

```text
modules/approvals/index.php
```

---

# Database Setup

For a **fresh remake installation**, import:

```text
database/kenjis_kitchen.sql
```

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

All demo accounts use the same password:

```text
password
```

### Usernames

```text
admin
cashier
kitchen
inventory
server
manager
```

### Demo Role Access

```text
admin     → Admin
cashier   → Cashier
kitchen   → Kitchen Staff
inventory → Inventory Staff
server    → Server
manager   → Manager
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
database/kenjis_kitchen.sql
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
http://localhost/kenjis-kitchen/modules/online/index.html
```

---

# Important Development Notes

This project is currently under development.

The first completed/active system modules are being developed progressively, while the menu catalog and other system features are still being updated.

### For Team Members

* If you encounter an error, inform **Erika** immediately.
* Double-check the folder where you are placing files before adding or replacing anything.
* Ask first if you are unsure whether a file or feature should be added.
* If you want a module fixed or changed, inform the team before modifying it.
* Inform the team if you add a new feature or make a major system change.
* Keep the project structure organized.
* Always check whether your changes affect another module.
* The project will also be uploaded to Git for easier access by all members.

### Current Development Status

```text
Module 1 – Cross-Platform Compatibility
Module 2 – User Management
Module 3 – Menu Management
Module 4 – Inventory Management
Module 5 – POS
```

The remaining modules are part of the updated system specification and will continue to be developed and integrated.

The menu catalog is also still being updated.

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
│   ├── kenjis_kitchen.sql
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
