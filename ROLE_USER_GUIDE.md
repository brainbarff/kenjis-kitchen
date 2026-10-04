# Kenji's Kitchen — Role Quick Reference & User Guide
**System Version:** v1.1.0 / 2.0  
**Target Operational Capacity:** 100 to 160 Daily Customers (911 Weekly Orders)  
**Standard Password for All Staff Accounts:** `password123` (or role-specific fallback: `<username>123`)

---

## 📋 Role Quick Reference & Login Credentials

| Role | Username | Default Password | Default Landing Page | Primary Responsibility |
|---|---|---|---|---|
| **👑 System Administrator** | `admin` | `password123` | [`/pages/dashboard.php`](http://localhost/kenjis-kitchen/pages/dashboard.php) | Overall system control, menu catalog, soft-archiving, and business KPIs |
| **💻 Cashier (POS)** | `cashier` | `password123` | [`/modules/pos/index.php`](http://localhost/kenjis-kitchen/modules/pos/index.php) | Order entry, cash & GCash collection, queue dispatch, thermal receipts |
| **🍳 Kitchen Staff** | `kitchen` | `password123` | [`/modules/kitchen/index.php`](http://localhost/kenjis-kitchen/modules/kitchen/index.php) | Kitchen Display System (KDS), meal preparation stepper, customer special instructions |
| **📦 Inventory Staff** | `inventory` | `password123` | [`/modules/inventory/index.php`](http://localhost/kenjis-kitchen/modules/inventory/index.php) | Piece-level (`pcs`) stock receiving, adjustments, audit logs, approval requests |
| **🍽️ Server / Waiter** | `server` | `password123` | [`/modules/serving/index.php`](http://localhost/kenjis-kitchen/modules/serving/index.php) | Table food delivery, pickup dispatch, customer fulfillment, customer ready TV |
| **📊 Store Manager** | `manager` | `password123` | [`/modules/reports/index.php`](http://localhost/kenjis-kitchen/modules/reports/index.php) | Financial reports, customer traffic analysis, daily auditing, payment reconciliation |
| **📱 Online Customer** | *(Public / Guest)* | *(6-Digit Email OTP)* | [`/modules/online/index.html`](http://localhost/kenjis-kitchen/modules/online/index.html) | Foodpanda-style mobile ordering, category pills, special instructions, order tracking |

---

## 🔍 In-Depth Guide for Each Role

### 1. 👑 System Administrator (`admin`)
- **Role ID:** `1`
- **Landing Page:** Executive Analytics Dashboard ([`/pages/dashboard.php`](http://localhost/kenjis-kitchen/pages/dashboard.php))
- **Role Purpose:** Full administrative, technical, and operational ownership of the entire restaurant system.
- **Key Permissions & Allowed Actions:**
  - **Real-time Analytics:** View total revenue, customer volumes (100–160 daily traffic charts), and in-store vs. online order split.
  - **Menu & Catalog Control:** Add new dishes, adjust prices, set promo pricing (`promo_price`), and filter by 8 categories (*All, Add-Ons, Bilao Favorites, Drinks, Pulutan & Pares, Silog Meals, Silog Set Meals, Student Meals*).
  - **Soft-Archiving (`is_archived`):** Direct "Archive" and "Restore" actions on menu items without permanent SQL deletes.
  - **Inventory Authority & Approvals:** Review and approve/reject Inventory Staff stock-in, stock-out, and unarchive requests via [`/modules/approvals/index.php`](http://localhost/kenjis-kitchen/modules/approvals/index.php).
  - **User & Staff Management:** Create, edit, and soft-deactivate staff accounts (`status = 'Inactive'`).
  - **Quick Storefront Launch:** Direct link to launch the Online Ordering storefront right from the dashboard and sidebar.

---

### 2. 💻 Cashier (`cashier`)
- **Role ID:** `2`
- **Landing Page:** Point of Sale (POS) & Live Queue ([`/modules/pos/index.php`](http://localhost/kenjis-kitchen/modules/pos/index.php))
- **Role Purpose:** Handle all front-of-house transactions, walk-in customer interactions, payment settlement, and queue management.
- **Key Permissions & Allowed Actions:**
  - **Order Creation:** Add items to cart with direct numeric quantity inputs or `+` / `-` buttons.
  - **Fulfillment Channels:**
    - **DINE (Dine-In):** Identify customers by Table Number (e.g. Table 5) and First Name.
    - **TAKE (Take-Out):** Enter customer First Name for calling out ready meals.
    - **ONL (Online Orders):** Review incoming online orders with customer notes and delivery/pickup preferences.
  - **Payment & Receipts:** Compute cash payments, validate 13-digit GCash references, calculate change, and print 58mm/80mm thermal slips.
  - **Live Online Order Sync:** Real-time WebSocket audio chime alerts 🔔 and pending badge counters when customers place online orders.
  - **Item Notes Visibility:** Prominently view customer special instructions (e.g. *"No onions"*, *"Extra sauce"*) under each line item in online order modal tickets.
- **Restrictions:**
  - Cannot modify menu base prices.
  - Cannot create or delete user accounts.

---

### 3. 🍳 Kitchen Staff (`kitchen`)
- **Role ID:** `3`
- **Landing Page:** Kitchen Display System ([`/modules/kitchen/index.php`](http://localhost/kenjis-kitchen/modules/kitchen/index.php))
- **Role Purpose:** Accurately prepare food tickets in chronological order.
- **Key Permissions & Allowed Actions:**
  - **Digital Ticket Rail:** View incoming food orders ordered by submission time (`kitchen_queued_at ASC`).
  - **Item-Level Special Instructions:** Prominently rendered item notes badges (`order_items.notes`) under each ordered dish (e.g. *"Less ice"*, *"Extra gravy"*, *"Well-done egg"*).
  - **3-Stage Order Status Stepper:**
    - **Pending 🟡:** New order arrived from Cashier or Online.
    - **Preparing 🍳:** Cook clicks **Start Preparing** to indicate food is on the grill/stove.
    - **Ready ✅:** Cook clicks **Mark Ready** when meal is plated and ready for dispatch.
  - **Instant Cross-Panel Sync:** Status updates broadcast in real time to Cashier POS, Server Dispatch, and Customer Ready TVs.
- **Restrictions:**
  - Cannot view payment details, sales revenues, or financial figures.
  - Cannot void or delete customer orders.

---

### 4. 📦 Inventory Staff (`inventory`)
- **Role ID:** `4`
- **Landing Page:** Inventory Management ([`/modules/inventory/index.php`](http://localhost/kenjis-kitchen/modules/inventory/index.php))
- **Role Purpose:** Maintain stock accuracy in piece units (`pcs`) to prevent ingredient shortages or overselling.
- **Key Permissions & Allowed Actions:**
  - **Piece-Level Tracking:** Track all 56+ menu items strictly by pieces (`pcs`) or packs (`menu_items.stock`).
  - **Dual-Control Approval Workflow:** Submit requests to the Admin for:
    - *Stock In:* Log new deliveries and supplier receipts.
    - *Stock Out:* Deduct spoiled, damaged, or expired items with mandatory remarks.
    - *Archive / Unarchive:* Request archiving of out-of-stock items or restoring previously archived items.
  - **Low Stock Monitoring:** Highlight items that fall below the low-stock threshold ($< 10\text{ pcs}$).
  - **Audit Trail:** Review historical stock logs showing who made changes, timestamp, action type, and reason.
- **Restrictions:**
  - Cannot process customer POS transactions.
  - Cannot view executive revenue analytics.

---

### 5. 🍽️ Server / Waiter (`server`)
- **Role ID:** `5`
- **Landing Page:** Serving & Dispatch Module ([`/modules/serving/index.php`](http://localhost/kenjis-kitchen/modules/serving/index.php))
- **Role Purpose:** Deliver prepared meals to physical dining tables and ensure take-out pickups are handed to the correct customers.
- **Key Permissions & Allowed Actions:**
  - **Ready Order Tracker:** View orders marked **Ready** by the kitchen in real time.
  - **Table Matching:** Match order numbers (e.g. `DINE-001`) with table numbers or customer first names.
  - **Order Completion:** Mark orders as **Served** once delivered to the customer, clearing them from active cooking rails.
  - **Customer-Facing Ready TV:** Manage [`/modules/serving/display.php`](http://localhost/kenjis-kitchen/modules/serving/display.php) showing ready order numbers on large overhead screens.
- **Restrictions:**
  - Cannot accept payments or modify order line items.
  - Cannot access administrative or financial settings.

---

### 6. 📊 Store Manager (`manager`)
- **Role ID:** `6`
- **Landing Page:** Reports & Financial Auditing ([`/modules/reports/index.php`](http://localhost/kenjis-kitchen/modules/reports/index.php) & [`/modules/bills/index.php`](http://localhost/kenjis-kitchen/modules/bills/index.php))
- **Role Purpose:** Review sales reports, reconcile cash and GCash drawer balances, and evaluate store performance.
- **Key Permissions & Allowed Actions:**
  - **Financial Reports:** View gross sales, net revenue, discounts given, tax/VAT calculations, and average ticket size.
  - **Channel Breakdown:** Analyze revenue split between In-Store Walk-ins (`POS`: Dine-In, Take-Out) vs. Digital Online (`ONLINE`: Delivery, Pickup).
  - **Customer Traffic Analysis:** Review daily traffic counts (including the 100–160 daily volume benchmark dataset).
  - **Shift Logs & Reconciliation:** Verify cashier shifts, cash drawer balances, and variances.
- **Restrictions:**
  - Cannot permanently delete core database records or drop tables.

---

### 7. 📱 Online Customer (`customer` / Guest)
- **Access Method:** Public Web Portal ([`/modules/online/index.html`](http://localhost/kenjis-kitchen/modules/online/index.html))
- **Role Purpose:** Allow remote customers to browse food, customize meals, and submit orders from their phones or computers.
- **Key Permissions & Allowed Actions:**
  - **Welcoming Hero Landing:** Enjoy the welcoming hero banner with authentic brand tagline (*"GOOD FOOD · FRESH INGREDIENTS · ALWAYS"*).
  - **3-Way Fulfillment Selector:** Easily toggle between **🛵 Delivery**, **🥡 Pickup**, and **🍽️ Dine-In**.
  - **Foodpanda-Style Navigation:** Smooth horizontal pill-shaped category scrolling (*All, Add-Ons, Bilao Favorites, Drinks, Pulutan & Pares, Silog Meals, Silog Set Meals, Student Meals*).
  - **Special Instructions / Notes:** Enter item-level special requests (*"No onions"*, *"Extra sauce"*, *"Less ice"*) stored in `order_items.notes` and visible to chefs on KDS tickets.
  - **6-Digit Email OTP Verification:** Secure sign-in and checkout confirmation via Nodemailer OTP (5-minute TTL, 5-attempt anti-brute-force lock).
  - **Live Order Status Tracking:** Monitor orders in real time as the Kitchen advances them from `Pending` ➔ `Preparing` ➔ `Ready`.
