# Kenji's Kitchen POS — XAMPP Setup Guide

> **System:** Restaurant POS + Online Ordering  
> **Stack:** PHP 8.x · MariaDB 10.4+ · Apache · XAMPP  
> **Folder name inside htdocs:** `kenjis-kitchen`

---

## ✅ Requirements

| Software | Version | Download |
|----------|---------|----------|
| XAMPP | 8.2+ | [apachefriends.org](https://www.apachefriends.org) |
| PHP | 8.1 or 8.2 | Included with XAMPP |
| MariaDB | 10.4+ | Included with the tested XAMPP installation |
| Apache | 2.4+ | Included with XAMPP |
| Browser | Chrome / Edge (latest) | — |

> [!IMPORTANT]
> PHP 8.0 or lower will **not** work. The system uses `match()`, `str_starts_with()`, and named arguments that require PHP 8.1+.

---

## 📁 Step 1 — Copy Project Files

1. Open your XAMPP installation folder (usually `C:\xampp\`)
2. Go into the `htdocs` folder
3. Copy the entire project folder here and **rename it to `kenjis-kitchen`**

```
C:\xampp\htdocs\kenjis-kitchen\
├── assets/
├── config/
├── database/
├── includes/
├── modules/
├── online-menu/
├── pages/
├── uploads/
├── .env
├── index.php
└── login.php
```

> [!WARNING]
> The folder **must** be named exactly `kenjis-kitchen` (lowercase, with hyphen). If you use a different name, update `APP_URL` in the `.env` file to match.

---

## 🗄️ Step 2 — Create the Database

1. Open **XAMPP Control Panel** → Start **Apache** and **MySQL**
2. Open your browser and go to: `http://localhost/phpmyadmin`
3. Click **New** (left sidebar)
4. Create a new database:
   - **Database name:** `kenjis_kitchen`
   - **Collation:** `utf8mb4_unicode_ci`
   - Click **Create**

---

## 📥 Step 3 — Import the Database

1. In phpMyAdmin, click on the `kenjis_kitchen` database (left sidebar)
2. Click the **Import** tab at the top
3. Click **Choose File**
4. Navigate to your project root folder
5. For a fresh installation on an empty database server, select the root **`schema.sql`**.
6. Click **Go / Import**
7. Wait for the green success message

> [!NOTE]
> The import may take 30–60 seconds. The file contains the full schema + all seed data including bilao products, sample orders, users, categories, and system settings.

---

## ⚙️ Step 4 — Configure the `.env` File

Open `C:\xampp\htdocs\kenjis-kitchen\.env` in a text editor (Notepad++, VS Code).

Update these values for **local XAMPP**:

```env
# Application
APP_URL=/kenjis-kitchen

# Database — XAMPP defaults
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=kenjis_kitchen
DB_USER=root
DB_PASS=
```

> [!TIP]
> If your XAMPP MySQL has a **root password**, fill it in `DB_PASS=yourpassword`. Most default XAMPP installs have no root password (leave it blank).

### Email Settings (Optional)
For local testing, you can disable emails entirely:
```env
MAIL_DISABLE=true
MAIL_DELIVERY_MODE=sink
TEST_ENVIRONMENT=true
```

---

## 📂 Step 5 — Create Upload Folders

Make sure these folders exist and are writable. Create them manually if missing:

```
kenjis-kitchen/
└── uploads/
    ├── menu/
    │   └── bilao-favorites/     ← bilao product images
    ├── payments/                 ← GCash QR code images
    └── proofs/                   ← payment proof uploads
```

> [!TIP]
> Right-click each folder → Properties → Security → give **Full Control** to the user running Apache (usually `Everyone` on local XAMPP).

---

## 🚀 Step 6 — Start the System

1. Open **XAMPP Control Panel**
2. Click **Start** next to **Apache**
3. Click **Start** next to **MySQL**
4. Open your browser and go to:

```
http://localhost/kenjis-kitchen
```

You should see the **Login page**.

---

## 👥 Default Accounts

### Admin / Staff Accounts

| Role | Full Name | Username | Password | PIN |
|------|-----------|----------|----------|-----|
| Admin | System Administrator | `admin` | `Admin@1234` | — |
| Cashier (POS Login) | POS Cashier | `pos` | `Pos@1234` | — |
| Cashier 1 (POS Auth) | Cashier 1 | `C1` | — | `0000` |
| Cashier 2 (POS Auth) | Cashier 2 | `C2` | — | `1111` |
| Kitchen | Kitchen Staff | `kitchen` | `Kitchen@1234` | — |
| Inventory | Inventory Staff | `inventory` | `Inventory@1234` | — |

> [!IMPORTANT]
> **Two-step POS authentication:**
> 1. The `pos` account logs into the system via username + password on the **Login page**
> 2. Once inside the POS, **C1** or **C2** identifies themselves using their **username + 4-digit PIN** in the Cashier Sign-In modal
> 3. This separates system access from cashier shift tracking

### Online Customer Accounts
Customers register themselves via the **Online Menu** at:
```
http://localhost/kenjis-kitchen/online-menu/
```

---

## 🖥️ System Pages

| Page | URL |
|------|-----|
| Login | `http://localhost/kenjis-kitchen/login.php` |
| Dashboard | `http://localhost/kenjis-kitchen/pages/dashboard.php` |
| **POS Terminal** | `http://localhost/kenjis-kitchen/modules/pos/` |
| Menu Management | `http://localhost/kenjis-kitchen/modules/menu/` |
| Inventory | `http://localhost/kenjis-kitchen/modules/inventory/` |
| Reports | `http://localhost/kenjis-kitchen/modules/reports/` |
| Staff Accounts | `http://localhost/kenjis-kitchen/modules/users/` |
| Kitchen Display | `http://localhost/kenjis-kitchen/modules/kitchen/` |
| Online Menu | `http://localhost/kenjis-kitchen/online-menu/` |
| Settings → Payments | `http://localhost/kenjis-kitchen/modules/settings/payments.php` |

---

## 🏷️ POS Daily Workflow

```
1. Open XAMPP → Start Apache + MySQL
2. Go to http://localhost/kenjis-kitchen
3. Log in as: pos / Pos@1234
4. Click POS in the sidebar
5. Cashier Sign-In modal appears → Enter C1 or C2 + PIN
6. Open Shift modal appears → Enter opening cash → Start Shift
7. Process orders throughout the day
8. At end of shift → Click "End Shift" (purple button) → Enter counted cash
9. Cashier sign-in reappears for the next cashier
```

---

## 🔧 Troubleshooting

### ❌ Blank page / 500 error
- Check `C:\xampp\php\logs\php_error_log` for details
- Make sure PHP version is 8.1+ in XAMPP settings

### ❌ Database connection error
- Confirm MySQL is running in XAMPP Control Panel
- Check `DB_NAME`, `DB_USER`, `DB_PASS` in `.env` match your phpMyAdmin setup

### ❌ Images not showing
- Confirm the `uploads/` folder exists and contains the `menu/bilao-favorites/` subfolder
- Check folder permissions — Apache needs read/write access

### ❌ "Access Denied" on login
- Use the exact credentials from the Default Accounts table above
- Passwords are case-sensitive

### ❌ Online menu not loading styles
- Confirm `APP_URL=/kenjis-kitchen` matches your folder name exactly
- Clear browser cache (Ctrl+Shift+R)

### ❌ Emails not sending
- For local testing, set `MAIL_DISABLE=true` in `.env`
- For live email, configure Brevo (Sendinblue) SMTP credentials in `.env`

---

## 📦 Database Files Reference

| File | Purpose |
|------|---------|
| `schema.sql` | ✅ **Fresh install baseline** — 36 application tables + demo catalog/staff |
| `tools/check_schema.php` | Read-only schema compatibility check for existing databases |
| `database/README_MIGRATIONS.md` | Historical migration notes and existing-DB alignment guidance |

> [!CAUTION]
> Do **not** import `schema.sql` or legacy SQL dumps (`database/kenjis_kitchen*.sql`) over an existing operational database. Use `C:\xampp\php\php.exe tools/check_schema.php --database=kenjis_kitchen` to inspect an existing database without modifying data.

---

## 🔒 Security Notes (for Production/Live Deployment)

> [!WARNING]
> The following settings are **not secure for a live/public server**. Only skip them for local XAMPP testing.

- Change all default passwords before going live
- Set a strong MySQL root password and update `DB_PASS` in `.env`
- Configure real SMTP credentials for email delivery
- Enable HTTPS (SSL certificate)
- Remove `seed_*.php` files from the root before going live
- Set `TEST_ENVIRONMENT=false` and `MAIL_DISABLE=false` in `.env`

---

*Last updated: November 12, 2026 — Kenji's Kitchen POS v2.0*
