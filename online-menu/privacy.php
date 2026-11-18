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
  <title>Privacy Policy | Kenji's Kitchen</title>
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
          <span>Online Ordering Privacy Policy</span>
        </div>
      </a>
      <div class="nav-actions">
        <button type="button" id="legalThemeToggle" class="nav-btn theme-toggle-btn" aria-label="Toggle Day/Night Mode" title="Toggle Day/Night Mode">
          <i class="bi bi-moon"></i>
        </button>
        <a href="terms.php" class="legal-link-btn"><i class="bi bi-file-earmark-text"></i> Terms of Use</a>
        <a href="index.php" class="legal-link-btn"><i class="bi bi-arrow-left"></i> Back to Storefront</a>
      </div>
    </div>
  </header>

  <section class="legal-hero">
    <div class="legal-hero-inner">
      <h1>Privacy Policy</h1>
      <p class="legal-hero-lead">
        This Privacy Policy explains how Kenji's Kitchen collects, uses, stores, and protects personal information on kenjiskitchen.online and in our store POS system under Republic Act No. 10173.
      </p>
      <p class="legal-hero-meta">Last updated: <?= htmlspecialchars($lastUpdated, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  </section>

  <main class="legal-layout">
    <aside class="legal-sidebar">
      <p class="legal-toc-title">On this page</p>
      <nav class="legal-toc" aria-label="Privacy Policy sections">
        <a href="#section-1" class="legal-toc-link is-active" data-section="section-1">
          <span class="legal-toc-num">1.</span>
          <span>Guests and Storefront Visitors</span>
        </a>
        <a href="#section-2" class="legal-toc-link" data-section="section-2">
          <span class="legal-toc-num">2.</span>
          <span>Account and Contact Details</span>
        </a>
        <a href="#section-3" class="legal-toc-link" data-section="section-3">
          <span class="legal-toc-num">3.</span>
          <span>Delivery Pins and Map Tiles</span>
        </a>
        <a href="#section-4" class="legal-toc-link" data-section="section-4">
          <span class="legal-toc-num">4.</span>
          <span>Orders, GCash Proof, and Reviews</span>
        </a>
        <a href="#section-5" class="legal-toc-link" data-section="section-5">
          <span class="legal-toc-num">5.</span>
          <span>How We Use Information</span>
        </a>
        <a href="#section-6" class="legal-toc-link" data-section="section-6">
          <span class="legal-toc-num">6.</span>
          <span>Staff Access and Role Controls</span>
        </a>
        <a href="#section-7" class="legal-toc-link" data-section="section-7">
          <span class="legal-toc-num">7.</span>
          <span>Deactivation and Archive Policy</span>
        </a>
        <a href="#section-8" class="legal-toc-link" data-section="section-8">
          <span class="legal-toc-num">8.</span>
          <span>How We Protect Your Data</span>
        </a>
        <a href="#section-9" class="legal-toc-link" data-section="section-9">
          <span class="legal-toc-num">9.</span>
          <span>Your Privacy Rights</span>
        </a>
        <a href="#section-10" class="legal-toc-link" data-section="section-10">
          <span class="legal-toc-num">10.</span>
          <span>Contacting Kenji's Kitchen</span>
        </a>
      </nav>
    </aside>

    <article class="legal-content">
      <section id="section-1" class="legal-section">
        <h2>1. Guests and Storefront Visitors</h2>
        <p>You can browse the Kenji's Kitchen menu, prices, store hours, and delivery map without creating an account. Standard browser storage remembers only local settings such as Light or Dark mode and items kept in your Food Basket while you browse.</p>
      </section>

      <section id="section-2" class="legal-section">
        <h2>2. Account and Contact Details</h2>
        <p>When you sign up or manage an online customer account with Kenji's Kitchen, we collect only the details needed to verify your identity and reach you about active orders:</p>
        <ul>
          <li>Account Profile Details: Full name, email address, username, optional profile picture, email verification timestamp, 6 digit email verification records, and cryptographically hashed passwords.</li>
          <li>Contact Details: Your 11 digit Philippine mobile number starting with 09 and email address used for verification codes, digital receipts, and delivery coordination.</li>
        </ul>
      </section>

      <section id="section-3" class="legal-section">
        <h2>3. Delivery Pins and Map Tiles</h2>
        <p>When you place a Delivery order, we record your written House, Block, Lot, and Street details, checkout map pin coordinates, delivery area check result, outside courier selection, and submitted HTTPS tracking links.</p>
        <p>Kenji's Kitchen never tracks your device location in the background. Your browser requests a single location reading only if you press Use my location to help center your map pin at checkout.</p>
        <p>Our server checks your map pin directly against the Barangay Greater Lagro boundary (OpenStreetMap Relation 1783169, ODbL 1.0) without sending your written street address to external geocoding services. Background map tiles load from OpenStreetMap.</p>
      </section>

      <section id="section-4" class="legal-section">
        <h2>4. Orders, GCash Proof, and Reviews</h2>
        <p>When you submit an order or review, we store ordered items, unit prices at checkout, cooking or packaging notes, chosen pickup time, receipt number, selected payment method (Cash or GCash only), 13 digit GCash reference number, uploaded screenshot proof, and any void or refund audit entries.</p>
        <p>If you submit a customer review, it is shown publicly alongside your first name and your profile picture if you choose to upload one.</p>
      </section>

      <section id="section-5" class="legal-section">
        <h2>5. How We Use Information</h2>
        <p>We use customer information strictly to operate Kenji's Kitchen and fulfill your orders:</p>
        <ul>
          <li>Sending 6 digit email verification codes for sign up, account sign in, and password recovery.</li>
          <li>Preparing and fulfilling Dine In, Take Out, Store Pickup, Within Greater Lagro Delivery, and Outside Greater Lagro Courier orders.</li>
          <li>Checking 13 digit GCash reference numbers and payment screenshots for food totals and store booked courier fees, and generating printable or PDF digital receipts.</li>
          <li>Maintaining daily sales summaries, inventory logs, cashier shift records, Cash on Delivery reliability status, and approval logs.</li>
          <li>Powering live tracking in My Orders, saved Favorite menu items, and customer review submissions.</li>
        </ul>
      </section>

      <section id="section-6" class="legal-section">
        <h2>6. Staff Access and Role Controls</h2>
        <p>Employee access in Kenji's Kitchen is divided by role so staff only see the records needed for their duties:</p>
        <ul>
          <li>Cashiers and Dispatch Staff view customer name, mobile number, delivery address, pin coordinates, tracking link, order items, and GCash payment references to verify payments and handle delivery.</li>
          <li>Kitchen and Serving Staff view order items, cooking and packaging instructions, pickup schedules, and customer display names needed to prepare and release orders.</li>
          <li>Administrators view financial reports, approval requests, payment audits, and archived accounts. Administrators cannot alter a customer's saved Default Delivery Address from Admin User Management.</li>
        </ul>
      </section>

      <section id="section-7" class="legal-section">
        <h2>7. Deactivation and Archive Policy</h2>
        <p>You can deactivate your online customer account anytime inside My Account after confirming your current password. Deactivation signs you out immediately and blocks new sign ins or orders unless restored by an Administrator.</p>
        <p>To protect receipt records, sales reporting, and audit trails, Kenji's Kitchen enforces an archive policy where customer accounts, orders, receipts, and payment logs are never permanently deleted.</p>
        <p>Uploaded GCash screenshot files follow the store retention setting of 90 days by default, while GCash reference numbers and payment audit entries remain recorded.</p>
      </section>

      <section id="section-8" class="legal-section">
        <h2>8. How We Protect Your Data</h2>
        <p>Passwords and email verification codes are stored using cryptographic hashing and are never stored in plain text. Uploaded GCash screenshots are checked for valid image formats (JPG, PNG, or WebP up to 5 MB), stored under randomized filenames inside a protected folder with directory listing disabled, and restricted to the order owner and authorized store personnel.</p>
        <p>Order tracking, receipt access, and account actions validate server sessions, prepared database queries, ownership checks, CSRF tokens, and rate limits.</p>
      </section>

      <section id="section-9" class="legal-section">
        <h2>9. Your Privacy Rights</h2>
        <p>In alignment with the Philippine Data Privacy Act of 2012 (Republic Act No. 10173) and subject to store financial record requirements, you may inspect your profile and receipts inside My Orders, edit customer controlled account details, deactivate your account, or submit privacy questions to our store team.</p>
      </section>

      <section id="section-10" class="legal-section">
        <h2>10. Contacting Kenji's Kitchen</h2>
        <p>For questions about this Privacy Policy or how Kenji's Kitchen handles customer records, contact us from Monday to Saturday between 9:00 AM and 3:00 AM:</p>
        <ul>
          <li>Store Address: Blk 79 Lot 34 Florante at Laura St., Brgy. Greater Lagro, Quezon City, Philippines</li>
          <li>Email: <a href="mailto:kenjiskitchen.online@gmail.com">kenjiskitchen.online@gmail.com</a></li>
          <li>Phone: <a href="tel:+639687430373">0968 743 0373</a></li>
          <li>Facebook: <a href="https://www.facebook.com/profile.php?id=61578450361358" target="_blank" rel="noopener">Kenji's Kitchen Official Page</a></li>
        </ul>
      </section>
    </article>
  </main>

  <footer class="legal-bottom-bar">
    <span>Kenji's Kitchen, Brgy. Greater Lagro, Quezon City</span>
    <span>Read our <a href="terms.php" style="color: inherit; font-weight: 700;">Terms of Use</a></span>
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
