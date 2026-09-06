# Kenji's Kitchen

Online Ordering, Sales and Inventory Management System
for Kenji's Kitchen

## Included Modules

1. User Management
2. Menu Management
3. Inventory Management
4. Point of Sale
5. Kitchen Display System
6. Order Serving and Display
7. Online Ordering
8. Reports
9. Transaction Approval
10. Notification and System Monitoring

## Setup

1. Open XAMPP folder.
2. Paste the `kenjis-kitchen` folder inside `htdocs`.
3. Open XAMPP app and start **Apache** and **MySQL**.
4. Open phpMyAdmin.
5. Import `database/kenjis_kitchen.sql` in MySQL.
6. If the database already exists, run:
   - `database/rbac_update.sql`
   - `database/operational_update.sql`
7. Check the database credentials in `config/db.php`.
8. Open the system:

```text
http://localhost/kenjis-kitchen/login.php
```

For the Online Ordering:

```text
http://localhost/kenjis-kitchen/modules/online/index.html
```
## Demo Accounts

All demo accounts use this password:

password

Usernames:

admin
cashier
kitchen
inventory
server

## Role Access

- Admin: Full access to all modules
- Cashier: POS and Serving
- Kitchen Staff: Kitchen Display System only
- Inventory Staff: Inventory Management only
- Server: Serving module only

If a user tries to open a module that is not allowed for their role, the system will redirect them to the Access Denied page.

Run database/rbac_update.sql if you need to reset the demo account roles.

## NOTE

- Mag sabi kay Erika if may error na ma-encounter.
- Double check lagi yung folder kung saan ilalagay yung files.
- Make sure na naka-start ang Apache and MySQL before opening the system.
- If may na-add or nabago sa system, sabihin agad.
- If may module na gusto i-fix or baguhin, sabihin agad.
- Module 7 is currently frontend only and gumagamit pa ng mock data and `localStorage`.
- Make sure na na-import yung database bago mag-test ng system.
- Lalagay ko rin to sa Git para easy access sa lahat ng members

## All Module and its Features

## System Modules

### Module 1 – User Management
**Users:** Admin  
**Functions:**
* Login and Logout
* Manage User Accounts
* Assign User Roles and Permissions

### Module 2 – Menu Management
**Users:** Admin  
**Functions:**
* Add, Edit, and Delete Menu Items
* Manage Menu Categories
* Set Item Prices
* Update Item Availability
* Manage Promotions

### Module 3 – Inventory Management
**Users:** Inventory Staff, Admin  
**Functions:**
* Manage Ingredients and Supplies
* Record Stock In and Stock Out
* Update Inventory Levels
* Record Deliveries
* Monitor Low Stock Items

### Module 4 – Point of Sale (POS)
**Users:** Cashier  
**Functions:**
* Create Orders
* Customize Orders
* Process Payments
* Apply Discounts
* Print Receipts

### Module 5 – Kitchen Order Management
**Users:** Kitchen Staff  
**Functions:**
* View Incoming Orders
* Update Order Status (Preparing, Ready)
* View Order Details

**KDS Feature Requirements:**
* **Order Type Visibility:** Display whether an order is Dine-In or To-Go.
* **Online Orders Integration:** Online orders automatically sync and appear on the KDS once payment is successful.
* **Order Queueing:** Strict First-In, First-Out (FIFO) queueing system.

### Module 6 – Order Serving and Display
**Users:** Server  
**Functions:**
* View Ready Orders
* Mark Orders as Served (Bump Order)
* Display Order Number
* Print Order Slip
* Update Serving Status
* Bump Order from the Serving Display

### Module 7 – Online Ordering
**Users:** Customer  
**Functions:**
* Browse Menu
* Customize Orders
* Add Items to Cart
* Checkout
* Make Online Payments
* Track Order Status
* View Order History

### Module 8 – Reports & Dashboard
**Users:** Admin, Manager (Optional)  
**Functions:**
* Generate Sales and Inventory Reports
* View Transaction Reports & Monitor Staff Activity
* View Sales Graphs and Charts
* Filter Reports by Year or Month
* Compare Sales Performance Across Periods

**Dashboard Requirements:**
* Sales Overview Dashboard
* Sales Graphs and Charts
* Monthly & Yearly Sales Summaries
* Transaction and Inventory Summaries

### Module 9 – Transaction Approval
**Users:** Admin, Manager (Optional)  
**Functions:**
* Approve Refund Requests
* Approve Void Transactions
* View Transaction History & Details
* Monitor Approved and Rejected Transactions

### Module 10 – Notification and System Monitoring
**Users:** Admin, Manager (Optional), Customer  
**Functions:**
* Receive Low Stock Alerts
* Receive Order Status Notifications
* Receive System Error Notifications
* Display System Outage Messages

---

## Additional Features

### Bills Management
**Functions:**
* View, Create, and Manage Bills
* View Bill Details & Track Bill Status
* View Payment Status & Manage Outstanding Bills

### Bills Dashboard
**Functions:**
* Display Total, Paid, Unpaid, and Pending Bills
* Display Total Amount Collected & Outstanding Balance
* View Recent Transactions

### Reports with Graphs and Analytics
**Functions:**
* View Sales Reports (Yearly, Monthly, Daily)
* View Sales and Monthly/Yearly Trends
* Display Sales and Transaction Graphs
* Filter Reports by Date Range
* Compare Sales Performance

## Order Status Flow

.The current order flow is:

```text
Pending
   ↓
Preparing
   ↓
Ready
   ↓
Served / Picked Up
```

Other status values are also prepared for future improvements:

```text
Cancelled
Refunded
Voided
```
## Order Lifecycle

The system also records different timestamps for the order:

```text
paid_at
kitchen_queued_at
preparing_at
ready_at
served_at
served_by
cancelled_at
cancel_reason
```

This is used to track when the order was paid, entered the kitchen, started preparing, became ready, and was finally served or picked up.

## Inventory Movement

The inventory module supports these movement types:

```text
Stock In
Stock Out
Adjustment
Order Deduction
Delivery
Waste/Spoilage
```

This makes it easier to track where the ingredients are coming from and where the stock is being used.


## Database Files

For fresh installation, import:

```text
database/kenjis_kitchen.sql
```

For existing database, run:

```text
database/rbac_update.sql
database/operational_update.sql
```

`rbac_update.sql`

- Resets the correct role assignments.

`operational_update.sql`

- Adds the new operational fields.
- Adds order status values.
- Adds the server account.
- Adds cashier shift table.
- Adds inventory log types.


### Testing POS to Kitchen to Serving

1. Login as `cashier`.
2. Create an order.
3. Select the order type.
4. Process and pay the order.
5. Login as `kitchen`.
6. Open the Kitchen Display System.
7. Check the incoming order.
8. Mark the order as `Preparing`.
9. Mark the order as `Ready`.
10. Login as `server`.
11. Open the Serving module.
12. Check the ready order.
13. Click `Bump / Served`.
14. Check if the order changes to `Served` or `Picked Up`.
15. Login as `inventory`.
16. Check the inventory stock and movement logs.

### Testing Now Serving Display

1. Login as `server`.
2. Open the Serving module.
3. Click `Now Serving Display`.
4. Open the display in another tab.
5. In the Kitchen module, mark an order as `Ready`.
6. Wait a few seconds.
7. Check if the order appears on the Now Serving display.

The display automatically refreshes using JavaScript polling.

### Testing Print Slip

1. Open the Serving module.
2. Find a ready order.
3. Click `Print Slip`.
4. Check the order slip preview.
5. Click `Print Slip`.
6. The print layout is designed for an 80mm thermal paper style receipt.

### Testing Online Ordering

1. Open:

```text
http://localhost/kenjis-kitchen/modules/online/index.html
```

2. Select a menu category.
3. Search for a menu item.
4. Try the price filter.
5. Click a food item.
6. Customize the portion or extras.
7. Add the item to cart.
8. Change the quantity or remove an item.
9. Proceed to checkout.
10. Fill in the customer details.
11. Select a payment method.
12. Place the order.
13. Check the order tracking.
14. Open order history.
15. Try the reorder button.

## Important Note

The system currently has the complete structure for Modules 1 to 10, but some features are still prepared for future development.

For example, Module 7 is currently frontend only and uses mock data and `localStorage`. The PHP and MySQL integration can be added later so online orders can be saved and automatically sent to the Kitchen Display System.

The file below is also kept as an administrative fallback tool:

```text
reset_all.php
```

I did not modify it because it can still be useful if the demo passwords need to be reset during testing.

## System Workflow

This version follows this workflow:

```text
User Management
      ↓
Menu Management
      ↓
Inventory Management
      ↓
Point of Sale (POS)
      ↓
Kitchen Display System
      ↓
Order Serving and Display
      ↓
Online Ordering
      ↓
Reports
      ↓
Transaction Approval
      ↓
Notification and System Monitoring
```
