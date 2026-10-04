# Kenji's Kitchen — Setup & Deployment Guide (v1.1.0)

Follow this step-by-step guide to run the **Kenji's Kitchen Multi-Role Online Ordering and Management System** on your local machine using XAMPP (Apache + MySQL).

---

## Step 1: Open phpMyAdmin
1. Open your XAMPP Control Panel and verify **Apache** and **MySQL** are both running (green status).
2. Open your web browser and navigate to:
   👉 **http://localhost/phpmyadmin/**

---

## Step 2: Drop the Old Database (For a Clean Start)
Look at the left sidebar in phpMyAdmin to see if `kenjis_kitchen` is listed:
- **If it is listed:**
  1. Click on `kenjis_kitchen` in the left sidebar.
  2. Click the **Operations** tab in the top navigation bar.
  3. Scroll down to the bottom and click the red link: **Drop the database (DROP)**.
  4. Click **OK** on the confirmation popup.
- **If it is not listed** (or you already dropped it), proceed directly to Step 3.

---

## Step 3: Import the All-in-One SQL File
1. Click the **Import** tab in the top navigation bar of phpMyAdmin.
2. Under **File to import**, click the **Choose File** (or Browse) button.
3. In the file browser, navigate to and select either:
   - **Recommended (Complete Production Schema with 911 Sample Sales & Orders):**
     `C:\xampp\htdocs\kenjis-kitchen\database\kenjis_kitchen_full_seed.sql`
     *(or `schema.sql` at the root folder for fresh clean installation)*
4. Scroll down to the bottom of the page.
5. Click the **Import** (or **Go**) button.
6. Wait a few seconds until the green success banner appears:
   ✅ *"Import has been successfully finished..."*

### What gets created automatically:
- **Database:** `kenjis_kitchen`
- **Tables:** All essential tables (`users`, `menu_items`, `categories`, `orders`, `order_items`, `payments`, `receipts`, `inventory`, `cashier_shifts`, `otp_verifications`, `approval_requests`, etc.)
- **Menu Items & Categories:** 56+ items across 8 categories including **Drinks** (Coca-Cola, Sprite, Royal, Mountain Dew, Bottled Soda @ ₱20)
- **Active Staff Accounts:** Pre-configured staff accounts with password `password123`
- **Historical Sales Data:** 911 Customer Orders with full payment and receipt records (ready for Dashboard charts and analytics)

---

## Step 4: Open & Log In to the Staff Management System
Once the import finishes, navigate to the staff login portal:
👉 **http://localhost/kenjis-kitchen/login.php**

Log in using these default credentials:

| Role | Username | Password |
|---|---|---|
| **System Administrator** | `admin` | `password123` |
| **Cashier (POS)** | `cashier` | `password123` |
| **Kitchen Staff (KDS)** | `kitchen` | `password123` |
| **Inventory Staff** | `inventory` | `password123` |

When you log in as `admin`, the **Admin Dashboard** will immediately display:
- Weekly sales performance charts
- Peak customer traffic hours
- Top-selling food items and silogs
- Full audit and transaction history

---

## Step 5: Open the Customer Online Ordering Storefront
The public customer-facing storefront is located at:
👉 **http://localhost/kenjis-kitchen/modules/online/index.html**

This modern Foodpanda-style portal allows customers to:
- Enjoy the **Welcoming Hero Banner** and interactive feature badges
- Toggle between **🛵 Delivery**, **🥡 Pickup**, and **🍽️ Dine-In**
- Filter food with smooth horizontal category pills (*All, Add-Ons, Bilao Favorites, Drinks, Pulutan & Pares, Silog Meals, Silog Set Meals, Student Meals*)
- Add **Special Instructions / Notes** (e.g. *"No onions", "Extra gravy", "Less ice"*) to any order item
- Verify identity with the **6-digit Email OTP** system
- Track order status in real time as the Kitchen moves it from `Pending` → `Preparing` → `Ready`

---

## Step 6: Configure Email OTP and Start the Realtime Server
Customer registration accepts any valid email address. Email delivery needs working SMTP credentials in the private `.env` file.

- For Gmail, set `EMAIL_SERVICE=gmail`, `EMAIL_USER` to the sending Gmail account, and `EMAIL_PASS` to its App Password.
- For a custom-domain sender, set `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `EMAIL_USER`, `EMAIL_PASS`, and `EMAIL_FROM` to the values from your email provider. The provider must authorize the sender address; domain SPF/DKIM records may also be required.
- Restart the Node.js service after changing `.env`.

Start the Node.js service for OTP email delivery and live ticket updates on KDS/POS:
1. Open PowerShell / Command Prompt and navigate to your project folder:
   ```cmd
   cd C:\xampp\htdocs\kenjis-kitchen
   ```
2. Start the Node.js server:
   ```cmd
   npm start
   ```
   *Live server runs at `http://127.0.0.1:8787`.*

## Receipt Printer
Install the printer and its driver in Windows, then use the POS **Kitchen + Print Receipt** button after an order is completed. The browser print dialog lets the cashier choose the installed printer and paper size. Set the printer as the Windows default to make it the preselected printer. Direct silent printing requires a printer-specific local print bridge.
