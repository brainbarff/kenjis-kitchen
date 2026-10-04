# CHANGELOG

All notable changes to the **Kenji's Kitchen Multi-Role Online Ordering and Management System** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [v1.1.0] - 2026-10-03

### Summary of Release
Consolidated, debugged, and enhanced release unifying File A (v1 Base/Realtime architecture) and File B (v2 Direct-stock, Approval Workflow, and Expanded Management architecture) into a production-ready application. Featuring real-time inventory deductions (`menu_items.stock`), foodpanda-style mobile-first online ordering interface, dual-channel POS & KDS synchronization, robust Nodemailer OTP authentication with brute-force protection, and strict soft-archiving with zero destructive `DELETE` operations.

---

### Added
- **Welcome Page / Hero Section Design (`modules/online/index.html`, `styles.css`)**:
  - Full-width hero landing section welcoming customers to Kenji's Kitchen Menu.
  - Tagline: "GOOD FOOD · FRESH INGREDIENTS · ALWAYS".
  - Warm playful typography with "Welcome!" and subheading: "Craving something delicious? You're in the right place!".
  - Call-to-Action Card: "Order your favorites now and enjoy our tasty meals from the comfort of your home!" with interactive order button.
  - Feature badges: 🍲 Freshly Cooked, ❤️ Quality Ingredients, 🏠 Your Favorite Meals, Online.
  - Side Quote badges: "Sarap na, Online pa! ♡" and "Good Food Brings People Together ♡".
  - Device-adaptive responsiveness: Desktop (>1024px multi-column showcase), Tablet (768px-1023px stacked layout), and Mobile (<767px compact vertical layout with \(\ge 48\text{px}\) touch targets).
- **Foodpanda-Style Mobile UI & Experience (`modules/online/index.html`, `styles.css`)**:
  - Sticky Top Header with search bar, delivery/pickup toggle, and location/table indicator.
  - Smooth horizontal scrollable pill navigation with 8 categories: All, Add-Ons, Bilao Favorites, Drinks, Pulutan & Pares, Silog Meals, Silog Set Meals, and Student Meals.
  - Food Item Cards in responsive 2-column mobile grid (expanding to 3/4 columns on desktop) displaying item photo, title, price, promo tags, live stock badge (`pcs left`), and interactive `+` Add button.
  - Bottom-fixed Floating Cart Bar / Sheet displaying item counts and subtotal, expanding into a smooth checkout drawer.
  - Touch targets calibrated to \(\ge 48\text{px}\) for rapid mobile interaction.
- **Item-Level Special Instructions (`notes`)**:
  - Added "Special Instructions / Note" text area to each item modal and cart drawer (e.g. "No onions", "Extra sauce", "Less ice").
  - Persistent item notes stored in database table `order_items.notes`.
  - Prominent item notes badge and label rendered across Cashier POS (`fetch_online_orders.php`) and Kitchen Display System (`fetch_orders.php`, `kitchen.js`).
- **Nodemailer OTP Authentication Service (`otpService.js`)**:
  - Secure 6-digit cryptographically random OTP generation.
  - 5-minute Time-To-Live (TTL) expiration window.
  - Anti-brute-force rate limiting: maximum 5 verification attempts before invalidating the token.
  - Resend cooldown mechanism (60 seconds) to prevent email spamming.
  - HTML email template styled with Kenji's Kitchen branding.
- **Consolidated Node.js & Express Realtime Backend (`server.js`)**:
  - Native WebSocket server (`/ws/pos-monitor`) with HMAC-SHA256 JWT authorization for live cashier carts and instant KDS updates.
  - Express REST API endpoints for:
    - Menu management (`GET /api/menu/categories`, `GET /api/menu/items`).
    - Soft-delete & restoration (`PATCH /api/menu/items/:id/archive`, `PATCH /api/menu/items/:id/restore`).
    - Atomic order placement with stock check & real-time deduction (`POST /api/orders`).
    - Real-time stock status sync (`GET /api/inventory/stock`).
    - OTP dispatch and verification (`POST /api/auth/otp/send`, `POST /api/auth/otp/verify`).
    - Internal webhook publish endpoint (`POST /internal/publish`) for PHP backend notifications.
- **Drinks Category & Seed Data (`schema.sql`)**:
  - Added Category #7: `Drinks`.
  - Seeded Coca-Cola, Sprite, Royal, Mountain Dew, and Bottled Soda @ ₱20.00 each with initial stock of 50 units.
- **Frontend Templates & Unified Application Script (`app.js`)**:
  - Unified client-side logic supporting dynamic menu catalog loading, search, category filtering, cart management, item note capture, OTP modal verification flow, checkout wizard, and live order tracking.

---

### Changed
- **Inventory Deduction Architecture**:
  - Standardized operational source of truth strictly on `menu_items.stock` (eliminating ambiguous multi-table ingredient discrepancies during online checkout).
  - Pre-order stock validation: guarantees stock availability before order creation.
  - Atomic stock decrement with concurrency protection (`UPDATE menu_items SET stock = stock - ? WHERE id = ? AND stock >= ?`).
  - Automatic `inventory_logs` recording with action `'Order Deduction'`.
- **Order Status Synchronization**:
  - Kitchen Display System transitions (`Pending` \(\to\) `Preparing` \(\to\) `Ready`) now trigger immediate notifications via WebSocket broadcasts (`kitchen:status_change`) and atomic database updates.
  - POS Online Orders panel and Admin Dashboard update immediately via real-time WebSocket events and fallback polling.
- **Database Schema Upgrades (`schema.sql`)**:
  - Integrated `is_archived TINYINT(1) DEFAULT 0` on `menu_items`.
  - Added `notes VARCHAR(255)` on `order_items` for item-level customer notes.
  - Added `payment_reference` and `payment_proof` to `orders` and `payments`.
  - Created `approval_requests` table for Inventory Staff \(\to\) Admin dual-control stock and archive requests.
  - Created `otp_verifications` table for tracking token life cycles.

---

### Fixed
- **Elimination of Destructive DELETE Queries (Soft-Archiving)**:
  - Replaced hard `DELETE FROM users WHERE id = ?` in `modules/users/index.php` with soft-archiving status flag update (`status = 'Inactive'`).
  - Ensured `is_archived = 1` across all menu and inventory routes, maintaining historical integrity for reports, receipts, and order logs.
- **Admin Approvals Display**:
  - Resolved issue where pending Inventory Staff stock movements (Stock In, Stock Out, Adjustment, Archive) were not rendering properly in the Admin Approvals view.
- **GCash Reference Validation**:
  - Enforced strict 13-digit numeric validation for GCash transactions across both mobile online ordering and POS cashier checkout.
- **Kitchen Item Notes Visibility**:
  - Fixed KDS ticket template to display `item.notes` clearly beneath each ordered item, allowing chefs to immediately view customer instructions.
