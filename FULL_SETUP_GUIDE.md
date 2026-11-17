# 🍜 Kenji's Kitchen – Unified Ordering & Management System
## Full Step-by-Step Setup & Operational Guide

> **Single Source of Truth:** One backend, one database (`MySQL`), two faces: **Customer Ordering** & **Staff Operations**.

---

### Step 1: Open phpMyAdmin

1. Open your **XAMPP Control Panel**.
2. Click **Start** on both **Apache** and **MySQL** (ensure both modules highlight green).
3. Open your web browser and navigate to:  
   👉 [http://localhost/phpmyadmin/](http://localhost/phpmyadmin/)

---

### Step 2: Existing vs. Fresh Database Setup

For an existing operational database, preserve its data and verify schema compatibility with the read-only preflight (`C:\xampp\php\php.exe tools/check_schema.php --database=kenjis_kitchen`). Apply only missing table/column/enum definitions directly after inspecting the live schema. Do not drop the database or import a full export over it.

### Step 3: Fresh installations only

On an empty development/test server, use phpMyAdmin Import to load the root
`schema.sql`. It creates 36 application tables, demo staff, and the catalog,
without historical sales. Configure `.env` for that database, change demo
passwords, assign cashier PINs, and configure merchant/email details before use.
Legacy exports are not maintained installation scripts.

---

### Step 4: Open & Log In to the Staff Management System

Navigate to the staff login portal:  
👉 [http://localhost/kenjis-kitchen/login.php](http://localhost/kenjis-kitchen/login.php)

Log in using any of the staff credentials:

| Role | Username | Password | Default Landing Page |
| :--- | :--- | :--- | :--- |
| **System Administrator** | `admin` | `kenjisadmin123!` *(or `password123` on seed)* | `/pages/dashboard.php` |
| **Cashier (POS)** | `cashier` or `cashier1` | `kenjiscashier1123!` *(or `password123`)* | `/modules/pos/index.php` |
| **Kitchen Staff (KDS)** | `kitchen` | `kenjiskitchen123!` *(or `password123`)* | `/modules/kitchen/index.php` |
| **Inventory Staff** | `inventory` | `kenjisinventory123!` *(or `password123`)* | `/modules/inventory/index.php` |
| **Server / Dining Room** | `server` | `password123` | `/modules/serving/index.php` |

#### Features on the Admin Screen:
* **Admin Logout Button:** Placed at the **top-right** of the top bar for easy one-click access.
* **Online Ordering Storefront ↗:** Direct quick-launch button in the header and sidebar navigation.
* **KPI Metrics:** Today's Sales, Orders, Online Orders Today, Low Stock alerts, and Pending Orders.
* **Sales Overview:** Interactive revenue performance chart (7 Days, 30 Days, This Year).
* **User Management:** Admin can add new staff users directly from the UI with custom roles and passwords.

---

### Step 5: Open the Customer Online Ordering Storefront

The customer-facing storefront is accessed at:  
👉 [http://localhost/kenjis-kitchen/online-menu/](http://localhost/kenjis-kitchen/online-menu/)  
*(or via the clean root redirect: [http://localhost/kenjis-kitchen/](http://localhost/kenjis-kitchen/))*

#### Customer Capabilities:
* **Welcome Hero Banner:** Displays the official branding graphics with the Brand Yellow button *"Explore Our Menu"*.
* **Fulfillment Modes:** Toggle for **🛵 Delivery** (Within Greater Lagro Free, or Outside Lagro Customer-Booked) or **🥡 Pickup**.
* **Payment Methods:**
  * **Cash on Delivery (COD) / Pay on Pickup:** Automatically queues to **COD Approvals** in the POS/Staff panel for cashier or manager review and dispatch.
  * **GCash (QR Payment):** Upload payment receipt screenshot (JPG, PNG, WebP up to 5 MB) and 13-digit reference number. Automatically feeds to **QR Verification** with full-size image review before orders move to the Kitchen.
* **Category Pills:** Smooth horizontal category filtering (*All, Add-Ons, Bilao Favorites, Drinks, Pulutan & Pares, Silog Meals, Silog Set Meals, Student Meals*). Active pill highlights in **Brand Yellow (`#F2C12E`)** with dark, readable text (`#1F2328`).
* **Special Instructions:** Add item notes (e.g., *"No onions"*, *"Extra gravy"*, *"Less ice"*).
* **Cart Badge:** Live yellow counter displaying the quantity of items selected.
* **Email OTP Verification:** 6-digit code verification for registration and secure checkout.
* **Live Ticket Sync:** Completed online orders receive an `ONL-` prefix (e.g., `ONL-20261112-0001`) and instantly appear on the Cashier POS queue, COD/QR queues, and Kitchen Display System.

---

### Step 6: Staff Approval & Verification Workflows

1. **QR Verification Queue (`/modules/pos/payment_verification.php`):**
   * Review submitted customer GCash screenshots, declared amounts, and reference numbers.
   * Duplicate detection checks against reference numbers and image file hashes.
   * One-click **Verify & Release to Kitchen** or **Reject Proof** (with reason).
   * Badge counters in the sidebar notify staff of pending verifications in real-time.

2. **COD Approvals Queue (`/modules/pos/cod_approvals.php`):**
   * Inspect customer delivery addresses, past failed deliveries, and order value.
   * Approve orders to release them to the Kitchen, assign delivery staff/riders, and record cash handovers into the cashier's active shift.

3. **Menu Item Photos & Category Management (`/modules/menu/index.php`):**
   * Add menu items and upload photos (JPG, PNG, WebP up to 3 MB).
   * Category upload folders are dynamically created and sanitized, allowing custom categories without upload failures.
   * Inventory staff submissions are routed to **Admin Approvals** (`/modules/approvals/index.php`) before going live on the menu.

---

### Step 7: Database Maintenance & Crash Recovery

If MySQL encounters an unexpected crash or corrupted tables (e.g. from an abrupt shutdown):
1. Open Command Prompt / PowerShell as Administrator.
2. Run the MySQL repair command:
   ```cmd
   C:\xampp\mysql\bin\mysqlcheck.exe -u root --repair --all-databases
   ```
3. Start MySQL via the **XAMPP Control Panel** or run `Launch_Kenjis_Kitchen.bat`.
4. Verify table health:
   ```cmd
   C:\xampp\mysql\bin\mysqlcheck.exe -u root --check --all-databases
   ```

---

### Step 8: Start the Real-time WebSocket & OTP Server (Optional)

To enable instant WebSocket order pushes and automated email OTP verification:

1. Open your terminal / command prompt.
2. Navigate to the project root:
   ```cmd
   cd C:\xampp\htdocs\kenjis-kitchen
   ```
3. Start the Node.js server:
   ```cmd
   npm start
   ```
   * *Server listens on port `8787` for live WebSocket broadcasts.*

---

### Step 9: System Architecture Reference

```
                             ┌─────────────────────────────────┐
                             │       MySQL DATABASE            │
                             │       (kenjis_kitchen)          │
                             │     Single Source of Truth      │
                             └───────────────┬─────────────────┘
                                             │
                      ┌──────────────────────┴──────────────────────┐
                      │                                             │
           ┌──────────▼──────────┐                       ┌──────────▼──────────┐
           │     FACE 1:         │                       │     FACE 2:         │
           │  CUSTOMER ORDERING  │                       │   STAFF OPERATIONS  │
           └──────────┬──────────┘                       └──────────┬──────────┘
                      │                                             │
           • Online Storefront                           • Cashier POS & Shift Logs
           • Responsive Menu & Cart                      • QR Verification Queue
           • GCash & COD Checkout                        • COD Approvals & Handovers
           • Email OTP verification                      • Kitchen Display (KDS)
           • Live Order Tracking                         • Admin Approvals & Reports
```
