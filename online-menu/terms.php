<?php
require_once dirname(__DIR__, 1) . '/config/config.php';
require_once dirname(__DIR__, 1) . '/includes/functions.php';

$lastUpdated = 'November 18, 2026';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Terms of Use | Kenji's Kitchen</title>
  <link rel="icon" type="image/png" href="../assets/img/logo.png">
  <link rel="stylesheet" href="../assets/css/ol.css?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/css/ol.css') ?: '20261118' ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <script>
    (function () {
      try {
        if (localStorage.getItem('kk_theme') === 'dark') {
          document.documentElement.classList.add('dark-mode-init');
        }
      } catch (_) {}
    })();
  </script>
  <style>
    html {
      scroll-behavior: smooth;
    }
    html.dark-mode-init body {
      background-color: #252B33;
      color: #E7E9EC;
    }
    body {
      padding-bottom: 56px;
      background-color: #ffffff;
      color: #18181b;
    }
    .legal-link-btn {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 8px 13px;
      border-radius: 10px;
      border: 1px solid #e4e4e7;
      background: #ffffff;
      color: #111111;
      font-weight: 700;
      font-size: 0.84rem;
      text-decoration: none;
      cursor: pointer;
      transition: background-color 0.16s ease, border-color 0.16s ease, transform 0.16s ease;
    }
    .legal-link-btn:hover {
      background: #fef9c3;
      border-color: #F2C12E;
      transform: translateY(-1px);
    }
    body.dark-mode .legal-link-btn {
      background: #303943;
      border-color: #454F5B;
      color: #E7E9EC;
    }
    body.dark-mode .legal-link-btn:hover {
      background: #3B4652;
      border-color: #F59E0B;
      color: #F59E0B;
    }

    /* Editorial Hero Banner */
    .legal-hero {
      background: #111113;
      color: #ffffff;
      padding: 52px 20px 46px;
      border-bottom: 4px solid #F2C12E;
    }
    body.dark-mode .legal-hero {
      background: #303943;
      border-bottom: 4px solid #F59E0B;
    }
    .legal-hero-inner {
      max-width: 1080px;
      margin: 0 auto;
    }
    .legal-hero h1 {
      font-size: clamp(2rem, 4.2vw, 3rem);
      font-weight: 800;
      line-height: 1.12;
      letter-spacing: -0.02em;
      margin: 0 0 14px;
      color: #ffffff;
    }
    body.dark-mode .legal-hero h1 {
      color: #F1F3F5;
    }
    .legal-hero-lead {
      max-width: 660px;
      font-size: 0.98rem;
      line-height: 1.65;
      color: #e4e4e7;
      margin: 0 0 16px;
    }
    body.dark-mode .legal-hero-lead {
      color: #E7E9EC;
    }
    .legal-hero-meta {
      font-size: 0.84rem;
      color: #a1a1aa;
      margin: 0;
    }
    body.dark-mode .legal-hero-meta {
      color: #B0B8C2;
    }

    /* Two Column Editorial Layout */
    .legal-layout {
      max-width: 1080px;
      margin: 0 auto;
      padding: 46px 20px 24px;
      display: grid;
      grid-template-columns: 270px minmax(0, 1fr);
      gap: 52px;
      align-items: start;
    }
    .legal-sidebar {
      position: sticky;
      top: 86px;
    }
    .legal-toc-title {
      font-size: 0.76rem;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: #71717a;
      margin: 0 0 12px;
      padding-left: 0;
    }
    body.dark-mode .legal-toc-title {
      color: #B0B8C2;
    }
    .legal-toc {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .legal-toc-link {
      display: flex;
      align-items: flex-start;
      gap: 9px;
      padding: 5px 0;
      background: transparent;
      border: none;
      text-decoration: none;
      font-size: 0.88rem;
      line-height: 1.45;
      color: #52525b;
      transition: color 0.16s ease, transform 0.16s ease;
    }
    .legal-toc-num {
      flex-shrink: 0;
      min-width: 22px;
      font-variant-numeric: tabular-nums;
      font-weight: 600;
      color: inherit;
    }
    .legal-toc-link:hover {
      color: #e5ad06;
      background: transparent;
      transform: translateX(3px);
    }
    .legal-toc-link.is-active {
      color: #e5ad06;
      font-weight: 700;
      background: transparent;
    }
    .legal-toc-link.is-active .legal-toc-num {
      color: #e5ad06;
      font-weight: 800;
    }
    body.dark-mode .legal-toc-link {
      color: #B0B8C2;
      background: transparent;
    }
    body.dark-mode .legal-toc-link:hover {
      color: #F59E0B;
      background: transparent;
      transform: translateX(3px);
    }
    body.dark-mode .legal-toc-link.is-active {
      color: #F59E0B;
      background: transparent;
    }
    body.dark-mode .legal-toc-link.is-active .legal-toc-num {
      color: #F59E0B;
    }

    /* Content Sections */
    .legal-content {
      min-width: 0;
      max-width: 740px;
    }
    .legal-section {
      scroll-margin-top: 88px;
      padding: 0 0 28px;
      margin: 0 0 28px;
      border-bottom: 1px solid #e4e4e7;
    }
    .legal-section:last-child {
      border-bottom: none;
      margin-bottom: 0;
      padding-bottom: 8px;
    }
    body.dark-mode .legal-section {
      border-bottom-color: #454F5B;
    }
    .legal-section h2 {
      font-size: 1.24rem;
      font-weight: 800;
      line-height: 1.35;
      color: #111111;
      margin: 0 0 12px;
    }
    body.dark-mode .legal-section h2 {
      color: #F1F3F5;
    }
    .legal-section p,
    .legal-section li {
      font-size: 0.95rem;
      line-height: 1.72;
      color: #3f3f46;
      margin: 0 0 11px;
    }
    .legal-section p:last-child,
    .legal-section ul:last-child {
      margin-bottom: 0;
    }
    body.dark-mode .legal-section p,
    body.dark-mode .legal-section li {
      color: #E7E9EC;
    }
    .legal-section ul {
      padding-left: 20px;
      margin: 6px 0 12px;
    }
    .legal-section a {
      color: #111111;
      font-weight: 700;
      text-decoration: underline;
      text-decoration-color: #F2C12E;
      text-decoration-thickness: 2px;
      text-underline-offset: 3px;
    }
    .legal-section a:hover {
      background-color: #fef08a;
    }
    body.dark-mode .legal-section a {
      color: #F59E0B;
      text-decoration-color: rgba(245, 158, 11, 0.7);
    }
    body.dark-mode .legal-section a:hover {
      background-color: rgba(245, 158, 11, 0.16);
    }

    .legal-bottom-bar {
      max-width: 1080px;
      margin: 18px auto 0;
      padding: 20px 20px 0;
      border-top: 1px solid #e4e4e7;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;
      font-size: 0.85rem;
      color: #71717a;
    }
    body.dark-mode .legal-bottom-bar {
      border-top-color: #454F5B;
      color: #B0B8C2;
    }

    @media (max-width: 860px) {
      .legal-layout {
        grid-template-columns: 1fr;
        gap: 28px;
        padding-top: 28px;
      }
      .legal-sidebar {
        position: static;
        background: #f4f4f5;
        border: 1px solid #e4e4e7;
        border-radius: 14px;
        padding: 16px 14px;
      }
      body.dark-mode .legal-sidebar {
        background: #303943;
        border-color: #454F5B;
      }
      .legal-toc-title {
        padding-left: 0;
      }
      .legal-toc {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 4px;
      }
    }
    @media (max-width: 600px) {
      .legal-hero {
        padding: 36px 16px 32px;
      }
      .legal-layout {
        padding: 24px 16px 16px;
      }
      .legal-toc {
        grid-template-columns: 1fr;
      }
      .navbar-top {
        flex-wrap: wrap;
        gap: 10px;
      }
      .nav-actions {
        flex-wrap: wrap;
        gap: 8px;
      }
      .legal-link-btn {
        padding: 7px 10px;
        font-size: 0.8rem;
        white-space: nowrap;
      }
    }
  </style>
</head>
<body>
  <script>
    (function () {
      try {
        if (localStorage.getItem('kk_theme') === 'dark') {
          document.body.classList.add('dark-mode');
        }
      } catch (_) {}
    })();
  </script>

  <header class="navbar">
    <div class="navbar-top">
      <a href="index.php" class="brand-wrapper">
        <img src="../assets/img/logo.png" alt="Kenji's Kitchen Logo" class="brand-icon" style="width: 38px; height: 38px; border-radius: 8px; object-fit: cover;">
        <div class="brand-info">
          <h1>Kenji's Kitchen</h1>
          <span>Online Ordering Terms of Use</span>
        </div>
      </a>
      <div class="nav-actions">
        <button type="button" id="legalThemeToggle" class="nav-btn theme-toggle-btn" aria-label="Toggle Day/Night Mode" title="Toggle Day/Night Mode">
          <i class="bi bi-moon"></i>
        </button>
        <a href="privacy.php" class="legal-link-btn"><i class="bi bi-shield-check"></i> Privacy Policy</a>
        <a href="index.php" class="legal-link-btn"><i class="bi bi-arrow-left"></i> Back to Storefront</a>
      </div>
    </div>
  </header>

  <section class="legal-hero">
    <div class="legal-hero-inner">
      <h1>Terms of Use</h1>
      <p class="legal-hero-lead">
        These Terms of Use govern your access to Kenji's Kitchen online ordering, customer accounts, reservations, payments, and delivery services through kenjiskitchen.online.
      </p>
      <p class="legal-hero-meta">Last updated: <?= htmlspecialchars($lastUpdated, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  </section>

  <main class="legal-layout">
    <aside class="legal-sidebar">
      <p class="legal-toc-title">On this page</p>
      <nav class="legal-toc" aria-label="Terms of Use sections">
        <a href="#section-1" class="legal-toc-link is-active" data-section="section-1">
          <span class="legal-toc-num">1.</span>
          <span>Agreement to these Terms</span>
        </a>
        <a href="#section-2" class="legal-toc-link" data-section="section-2">
          <span class="legal-toc-num">2.</span>
          <span>Store Hours and Coverage</span>
        </a>
        <a href="#section-3" class="legal-toc-link" data-section="section-3">
          <span class="legal-toc-num">3.</span>
          <span>Accounts and Verification</span>
        </a>
        <a href="#section-4" class="legal-toc-link" data-section="section-4">
          <span class="legal-toc-num">4.</span>
          <span>Deactivation and Archive Policy</span>
        </a>
        <a href="#section-5" class="legal-toc-link" data-section="section-5">
          <span class="legal-toc-num">5.</span>
          <span>Menu, Stock, and Minimum Orders</span>
        </a>
        <a href="#section-6" class="legal-toc-link" data-section="section-6">
          <span class="legal-toc-num">6.</span>
          <span>Bilao Advance Reservations</span>
        </a>
        <a href="#section-7" class="legal-toc-link" data-section="section-7">
          <span class="legal-toc-num">7.</span>
          <span>Pickup and Delivery Coverage</span>
        </a>
        <a href="#section-8" class="legal-toc-link" data-section="section-8">
          <span class="legal-toc-num">8.</span>
          <span>Payments and GCash Verification</span>
        </a>
        <a href="#section-9" class="legal-toc-link" data-section="section-9">
          <span class="legal-toc-num">9.</span>
          <span>Tracking and Digital Receipts</span>
        </a>
        <a href="#section-10" class="legal-toc-link" data-section="section-10">
          <span class="legal-toc-num">10.</span>
          <span>Cancellations, Voids, and Refunds</span>
        </a>
        <a href="#section-11" class="legal-toc-link" data-section="section-11">
          <span class="legal-toc-num">11.</span>
          <span>Acceptable Use</span>
        </a>
        <a href="#section-12" class="legal-toc-link" data-section="section-12">
          <span class="legal-toc-num">12.</span>
          <span>Store Contact and Support</span>
        </a>
      </nav>
    </aside>

    <article class="legal-content">
      <section id="section-1" class="legal-section">
        <h2>1. Agreement to these Terms</h2>
        <p>By browsing our storefront, registering a customer account, or placing an order through kenjiskitchen.online, you agree to follow these Terms of Use and our Privacy Policy. If you do not agree with these store rules, please do not use the online ordering service.</p>
      </section>

      <section id="section-2" class="legal-section">
        <h2>2. Store Hours and Coverage</h2>
        <p>Kenji's Kitchen operates at Blk 79 Lot 34 Florante at Laura St., Brgy. Greater Lagro, Quezon City, Philippines. Our store is open from Monday to Saturday, 9:00 AM to 3:00 AM the next day, and is closed on Sundays.</p>
        <p>Online order placement, advance reservation dates, and scheduled store pickup slots follow active operating hours and store kitchen availability.</p>
      </section>

      <section id="section-3" class="legal-section">
        <h2>3. Accounts and Verification</h2>
        <p>To submit online orders, post customer reviews, track order status, download receipts, or save favorite menu items, you must register a customer account and verify your email using the 6 digit verification code sent to your inbox.</p>
        <p>You agree to keep your profile details accurate, including your full name, active email address, 11 digit Philippine mobile number starting with 09, and delivery address. You are responsible for keeping your password confidential. If you forget your password, Customer Password Recovery lets you reset it via email verification code and signs out prior active sessions.</p>
      </section>

      <section id="section-4" class="legal-section">
        <h2>4. Deactivation and Archive Policy</h2>
        <p>You may deactivate your online customer account anytime inside My Account by entering your current password. Deactivation signs you out immediately and stops further sign ins or orders unless restored by a store Administrator.</p>
        <p>Kenji's Kitchen enforces a strict archive policy across the system. Customer accounts, completed or cancelled orders, receipts, and payment logs are never permanently deleted.</p>
      </section>

      <section id="section-5" class="legal-section">
        <h2>5. Menu, Stock, and Minimum Orders</h2>
        <p>Displayed menu items, Bilao platter sizes (Small, Medium, Large, and Extra Large), Tray and Bilao packaging choices, 1 liter drink selections, and promotional prices reflect current store inventory and active schedules.</p>
        <p>Placing items in your Food Basket does not hold kitchen stock. Live stock counts and final item totals are verified by our server when you submit your order.</p>
        <p>Every online order for both Delivery and Pickup requires at least 2 items with a ₱100.00 food subtotal, or a single item priced at ₱100.00 or more. Special instructions entered per item or per order are subject to character limits and kitchen feasibility, and written remarks cannot modify menu prices.</p>
      </section>

      <section id="section-6" class="legal-section">
        <h2>6. Bilao Advance Reservations</h2>
        <p>Medium (M), Large (L), and Extra Large (XL) Bilao Favorites platters require reservation 1 to 2 days in advance before your selected delivery or pickup date so our kitchen can prepare fresh ingredients accordingly.</p>
      </section>

      <section id="section-7" class="legal-section">
        <h2>7. Pickup and Delivery Coverage</h2>
        <p>At checkout, you must manually choose either Delivery or Pickup. Neither fulfillment option is preselected by default.</p>
        <p>For Store Pickup at Blk 79 Lot 34 Florante at Laura St., Brgy. Greater Lagro, Quezon City, select an available time slot for today or tomorrow with at least 30 minutes preparation notice during store hours.</p>
        <p>For Delivery, provide your complete House, Block, Lot, and Street information and place your pin on the checkout map. Our server checks your coordinates against the Barangay Greater Lagro boundary (OpenStreetMap Relation 1783169, ODbL 1.0) together with street and barangay checks:</p>
        <ul>
          <li>Within Greater Lagro (Free Store Delivery, ₱0.00): Confirmed map pins and written addresses inside Barangay Greater Lagro qualify for free direct delivery by Kenji's Kitchen staff. Typing Lagro without a verified boundary pin does not unlock free store delivery.</li>
          <li>Outside Greater Lagro (Outside Courier Required): Confirmed addresses outside Barangay Greater Lagro require an outside courier such as Lalamove or GrabExpress, with courier charges paid separately from your food total. Choose at checkout whether you will book the courier yourself and submit the HTTPS tracking link in My Orders before handoff, or ask our store to book the courier so staff can quote the actual delivery fee in My Orders for separate GCash payment and tracking upload.</li>
          <li>Boundary Edge or Incomplete Addresses: Pins sitting on the boundary border, missing house or street numbers, or conflicting addresses and map pins must be fixed before placing an order.</li>
        </ul>
      </section>

      <section id="section-8" class="legal-section">
        <h2>8. Payments and GCash Verification</h2>
        <p>Kenji's Kitchen accepts Cash and GCash only. Other digital wallets and bank transfers are not supported, and neither payment option is preselected at checkout.</p>
        <p>Store Pickup and Within Greater Lagro Delivery orders accept both Cash (with no Cash on Delivery maximum amount) and GCash. Cash on Delivery orders wait for cashier confirmation before moving to kitchen preparation. Outside Greater Lagro orders require GCash only for the food total because Cash on Delivery is not available for outside courier trips.</p>
        <p>When paying through GCash, send the exact amount shown at checkout to the official store GCash account, type the 13 digit GCash reference number, and upload a valid screenshot image (JPG, PNG, or WebP up to 5 MB). Staff manually confirm each payment before queueing your order. Unverified GCash orders that exceed the 30 minute payment verification timeout are automatically cancelled and reserved stock is returned.</p>
      </section>

      <section id="section-9" class="legal-section">
        <h2>9. Tracking and Digital Receipts</h2>
        <p>Each submitted order receives a unique order number and receipt reference, shows an estimated preparation time, and displays live status updates in My Orders. Confirmed paid orders display an Official Receipt, while pending payment orders display an Order Summary until verified.</p>
      </section>

      <section id="section-10" class="legal-section">
        <h2>10. Cancellations, Voids, and Refunds</h2>
        <p>You may cancel an online order yourself only while it remains in Pending status before kitchen preparation begins. Once preparation starts, or after the order reaches Ready, Out for Delivery, or Completed status, self cancellation closes.</p>
        <p>Order voids, failed delivery cancellations, and Cash or GCash refunds on paid orders are reviewed by store personnel and require Administrator approval.</p>
      </section>

      <section id="section-11" class="legal-section">
        <h2>11. Acceptable Use</h2>
        <p>You agree not to place fraudulent orders, submit duplicate or altered GCash reference numbers, manipulate address fields to bypass area rules, or post abusive reviews. Accounts with repeated failed Cash on Delivery deliveries may be limited to GCash or Store Pickup, and accounts linked to payment tampering may be archived.</p>
      </section>

      <section id="section-12" class="legal-section">
        <h2>12. Store Contact and Support</h2>
        <p>If you need help with an order, advance Bilao booking, GCash verification, or refund status, reach Kenji's Kitchen from Monday to Saturday between 9:00 AM and 3:00 AM:</p>
        <ul>
          <li>Store Address: Blk 79 Lot 34 Florante at Laura St., Brgy. Greater Lagro, Quezon City, Philippines</li>
          <li>Phone: <a href="tel:+639687430373">0968 743 0373</a></li>
          <li>Email: <a href="mailto:kenjiskitchen.online@gmail.com">kenjiskitchen.online@gmail.com</a></li>
          <li>Facebook: <a href="https://www.facebook.com/profile.php?id=61578450361358" target="_blank" rel="noopener">Kenji's Kitchen Official Page</a></li>
        </ul>
      </section>
    </article>
  </main>

  <footer class="legal-bottom-bar">
    <span>Kenji's Kitchen, Brgy. Greater Lagro, Quezon City</span>
    <span>Read our <a href="privacy.php" style="color: inherit; font-weight: 700;">Privacy Policy</a></span>
  </footer>

  <script>
    (function () {
      var toggleBtn = document.getElementById('legalThemeToggle');

      function syncThemeUi() {
        var isDark = document.body.classList.contains('dark-mode');
        if (toggleBtn) {
          var icon = toggleBtn.querySelector('i');
          if (icon) {
            icon.className = isDark ? 'bi bi-sun' : 'bi bi-moon';
          }
          var label = isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode';
          toggleBtn.setAttribute('aria-label', label);
          toggleBtn.setAttribute('title', label);
        }
      }

      syncThemeUi();

      if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
          var nextDark = !document.body.classList.contains('dark-mode');
          document.body.classList.toggle('dark-mode', nextDark);
          document.documentElement.classList.toggle('dark-mode-init', nextDark);
          try {
            localStorage.setItem('kk_theme', nextDark ? 'dark' : 'light');
          } catch (_) {}
          syncThemeUi();
        });
      }

      var tocLinks = Array.prototype.slice.call(document.querySelectorAll('.legal-toc-link'));
      var sections = tocLinks
        .map(function (link) {
          var id = link.getAttribute('data-section');
          return id ? document.getElementById(id) : null;
        })
        .filter(Boolean);

      function setActiveLink(activeId) {
        tocLinks.forEach(function (link) {
          var match = link.getAttribute('data-section') === activeId;
          link.classList.toggle('is-active', match);
          if (match) {
            link.setAttribute('aria-current', 'location');
          } else {
            link.removeAttribute('aria-current');
          }
        });
      }

      var manualLockUntil = 0;

      tocLinks.forEach(function (link) {
        link.addEventListener('click', function () {
          var targetId = link.getAttribute('data-section');
          if (targetId) {
            manualLockUntil = Date.now() + 800;
            setActiveLink(targetId);
          }
        });
      });

      var ticking = false;
      function updateActiveOnScroll() {
        ticking = false;
        if (!sections.length || Date.now() < manualLockUntil) return;

        var currentId = sections[0].id;
        for (var i = 0; i < sections.length; i++) {
          var rect = sections[i].getBoundingClientRect();
          if (rect.top <= 140) {
            currentId = sections[i].id;
          } else {
            break;
          }
        }

        var lastSection = sections[sections.length - 1];
        var lastRect = lastSection.getBoundingClientRect();
        var nearBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 16;
        if (nearBottom && lastRect.top <= window.innerHeight * 0.45) {
          currentId = lastSection.id;
        }
        setActiveLink(currentId);
      }

      window.addEventListener('scroll', function () {
        if (!ticking) {
          ticking = true;
          window.requestAnimationFrame(updateActiveOnScroll);
        }
      }, { passive: true });
    })();
  </script>
</body>
</html>
