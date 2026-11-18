<?php
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/storefront_bestsellers.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kenji's Kitchen - Online Ordering</title>
  <link rel="icon" type="image/png" href="../assets/img/logo.png">
  <link rel="apple-touch-icon" href="../assets/img/logo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="../assets/css/ol.css?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/css/ol.css') ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="../assets/vendor/leaflet/leaflet.css?v=1.9.4">
  <link rel="stylesheet" href="../assets/css/storefront.css?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/css/storefront.css') ?>">
</head>
<body>
  <script>
    (function() {
      try {
        var savedTheme = localStorage.getItem('kk_theme');
        if (savedTheme === 'dark') {
          document.body.classList.add('dark-mode');
        } else {
          document.body.classList.remove('dark-mode');
        }
      } catch (e) {}
    })();
  </script>

  <!-- Sticky Top Header (Foodpanda-Inspired + Integrated Navigation) -->
  <header class="navbar">
    <div class="navbar-top">
      <a href="#home" class="brand-wrapper" id="brandLogoLink" title="Kenji's Kitchen">
        <img src="../assets/img/logo.png" alt="Kenji's Kitchen Logo" class="brand-icon" id="secretStaffLogo">
        <div class="brand-info">
          <h1>KENJI'S KITCHEN</h1>
          <span>GREATER LAGRO, QUEZON CITY</span>
        </div>
      </a>

      <!-- Main Header Quick Links (Integrated Navigation) -->
      <nav class="top-nav-links" aria-label="Main Navigation">
        <a href="#home" class="top-nav-link">Home</a>
        <a href="#menu" class="top-nav-link">Menu</a>
        <a href="#about-us" class="top-nav-link">About Us</a>
        <a href="#visit-us" class="top-nav-link">Visit Us</a>
      </nav>

      <div class="nav-actions">
        <!-- Mobile Hamburger – shown on small screens -->
        <button class="mob-menu-btn" id="mobMenuBtn" aria-label="Open navigation menu" aria-expanded="false" onclick="toggleMobileNav()">
          <i class="bi bi-list" id="mobMenuIcon"></i>
        </button>
        <button class="nav-btn theme-toggle-btn" id="themeToggleBtn" onclick="toggleTheme()" title="Toggle Day/Night Mode">
          <i class="bi bi-moon" id="themeToggleIcon"></i>
        </button>
        <div id="navUserContainer">
          <button class="nav-btn" onclick="openOtpModal()" title="Customer Account"><i class="bi bi-person"></i></button>
        </div>
        <button class="nav-btn" onclick="openCartDrawer()" title="View Cart">
          <i class="bi bi-cart3"></i>
          <span class="badge-counter" id="navCartBadge">0</span>
        </button>
      </div>
    </div>

    <!-- Mobile Navigation Dropdown (shown when hamburger clicked) -->
    <nav class="mob-nav-dropdown" id="mobNavDropdown" aria-label="Mobile Navigation">
      <a href="#home" class="top-nav-link" onclick="closeMobileNav()">Home</a>
      <a href="#menu" class="top-nav-link" onclick="closeMobileNav()">Menu</a>
      <a href="#about-us" class="top-nav-link" onclick="closeMobileNav()">About Us</a>
      <a href="#visit-us" class="top-nav-link" onclick="closeMobileNav()">Visit Us</a>
    </nav>

    <!-- Search Bar -->
    <div class="search-wrapper">
      <span class="search-icon"><i class="bi bi-search"></i></span>
      <input type="text" id="searchInput" class="search-input" aria-label="Search the menu" placeholder="Search the menu">
    </div>
  </header>

  <!-- Main Food Grid -->
  <main class="main-content">
<section class="store-hero" id="home"><div class="hero-copy"><p class="hero-intro">Serving Greater Lagro since May 2022.</p><h1>Everyday meals.<br><span>Presyong sulit.</span></h1><p class="hero-description">Silog favorites, affordable student meals, and platters for sharing. Your neighborhood kitchen, now ready to order online.</p><div class="hero-actions"><a class="store-button" href="#menu">Browse the menu</a><a class="hero-visit-link" href="#visit-us">Come visit us</a></div><div class="hero-service-line" aria-label="Services offered"><p class="hero-service-label">Enjoy Kenji's your way</p><ul><li>Dine In</li><li>Takeout</li><li>Pickup</li><li>Delivery</li></ul></div></div><div class="hero-feature" id="bestsellerCarousel" role="region" aria-roledescription="carousel" aria-label="Bestselling menu items">
<figure id="bestsellerSlide"><div class="bestseller-photo"><img id="bestsellerImage" src="<?= htmlspecialchars($storeBestsellers[0]['image_url'] ?? '../uploads/menu/silog%20meals/baconsilog.png', ENT_QUOTES) ?>" alt="<?= htmlspecialchars($storeBestsellers[0]['name'] ?? 'Baconsilog with garlic rice and a fried egg', ENT_QUOTES) ?>"></div>
<figcaption><p class="store-eyebrow" id="bestsellerLabel"><?= htmlspecialchars($storeBestsellers ? $storeBestsellerPeriod : 'From our menu', ENT_QUOTES) ?></p><h2 id="bestsellerName"><?= htmlspecialchars($storeBestsellers[0]['name'] ?? 'Baconsilog', ENT_QUOTES) ?></h2><p id="bestsellerDescription"><?= htmlspecialchars($storeBestsellers[0]['description'] ?? 'Bacon, garlic rice, and a fried egg.', ENT_QUOTES) ?></p></figcaption></figure>

</div><script type="application/json" id="bestsellerData"><?= json_encode($storeBestsellers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script></section>
<div class="store-catalog">
<section id="menu" class="store-menu-heading"><div><p class="store-eyebrow">ORDER ONLINE</p><h2>Our menu</h2></div></section>  <!-- Horizontal Scrollable Category Bar (Pill-shaped) -->
  <nav class="category-strip-wrapper">
    <div class="category-strip" id="categoryStrip">
      <!-- 8 Pills rendered dynamically: All, Add-Ons, Bilao Favorites, Drinks, Pulutan & Pares, Silog Meals, Silog Set Meals, Student Meals -->
    </div>
  </nav>

    <!-- Main Food Grid -->
    <div class="food-grid" id="foodGrid">
      <!-- Responsive dynamic food cards injected by app.js -->
    </div>

</div>
    <!-- Elevated Responsive Footer -->
<section class="store-about store-section" id="about-us">
<header class="about-heading"><p class="store-eyebrow">ABOUT KENJI'S KITCHEN</p><h2>Your neighborhood kitchen.<br><span>Since May 2022.</span></h2><p>Everyday meals. Familiar faces. A place to share good food in Greater Lagro.</p></header>
<div class="about-story"><div class="about-gallery" role="region" aria-label="Photos of Kenji’s Kitchen"><button class="about-photo-stack" type="button" aria-label="Show next photo of Kenji’s Kitchen"><img src="../assets/img/about/kenjis-entrance.jpg" alt="The entrance to Kenji’s Kitchen" class="about-gallery-photo is-current"  width="1200" height="900" loading="lazy"><img src="../assets/img/about/kenjis-dining-area.jpg" alt="Tables in the covered dining area" class="about-gallery-photo" aria-hidden="true" width="1200" height="900" loading="lazy"><img src="../assets/img/about/kenjis-interview-04.jpg" alt="The dining area and menu displays" class="about-gallery-photo" aria-hidden="true" width="1200" height="900" loading="lazy"><img src="../assets/img/about/kenjis-interview-01.jpg" alt="Plants and a painted welcome at Kenji’s Kitchen" class="about-gallery-photo" aria-hidden="true" width="1200" height="900" loading="lazy"><img src="../assets/img/about/kenjis-interview-02.jpg" alt="A detail of the painted wall at Kenji’s Kitchen" class="about-gallery-photo" aria-hidden="true" width="1200" height="900" loading="lazy"></button></div><div><p class="store-eyebrow">OUR STORY</p><h3>Familiar favorites, made for everyday life.</h3><p>Since opening in May 2022, Kenji’s Kitchen has served students and neighbors in Greater Lagro, Quezon City with affordable meals and snacks. It’s a local stop for a filling meal, whether you’re sitting down with us or taking food home.</p><p>Silog favorites and combo meals sit alongside family-size platters, palabok, and spaghetti. From a meal for one to something to share, our menu keeps familiar Filipino favorites within reach.</p><p>Behind the counter, our small team shares the cooking, order taking, and service that keep the kitchen going. That hands-on approach is part of everyday life at Kenji’s.</p></div></div>
</section>
<section class="store-section" id="customerReviews">
  <div class="store-section-heading">
    <div>
      <p class="store-eyebrow">FROM OUR CUSTOMERS</p>
      <h2>Customer reviews</h2>
    </div>
    <div id="reviewSummary" class="review-summary" aria-live="polite"></div>
  </div>
  <div id="reviewList" class="store-reviews" aria-live="polite">
    <p>Loading reviews…</p>
  </div>
</section>
<section class="store-section store-visit" id="visit-us"><div><p class="store-eyebrow">COME BY OR GET IN TOUCH</p><h2>Visit Us</h2><address>Blk 79 Lot 34 Florante at Laura St.<br>Brgy. Greater Lagro, Quezon City, Philippines</address><a href="tel:+639687430373">0968 743 0373</a><a href="mailto:kenjiskitchen.online@gmail.com">kenjiskitchen.online@gmail.com</a><p id="storeOpenStatus" class="store-status" role="status"></p><h3>Store Hours</h3><p>Monday to Saturday<br><strong>9:00 AM to 3:00 AM</strong><br>Sunday: Closed</p><a href="https://www.google.com/maps/search/?api=1&amp;query=Blk+79+Lot+34+Florante+at+Laura+Greater+Lagro+Quezon+City" target="_blank" rel="noopener">Get directions</a></div><div class="store-map"><iframe title="Map showing Kenji's Kitchen in Greater Lagro" src="https://maps.google.com/maps?q=Blk%2079%20Lot%2034%20Florante%20at%20Laura%20Greater%20Lagro%20Quezon%20City&amp;output=embed" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe></div></section>
<footer class="store-footer"><div class="footer-columns"><div><h3>Quick links</h3><nav aria-label="Footer"><a href="#menu">Our Menu</a><a href="#about-us">About Us</a><a href="terms.php">Terms of Use</a><a href="privacy.php">Privacy Policy</a></nav></div><div class="footer-contact"><h3>Contact Us</h3><a href="tel:+639687430373"><i class="bi bi-telephone" aria-hidden="true"></i>0968 743 0373</a><a href="mailto:kenjiskitchen.online@gmail.com"><i class="bi bi-envelope" aria-hidden="true"></i>kenjiskitchen.online@gmail.com</a><a href="https://www.google.com/maps/search/?api=1&amp;query=Blk+79+Lot+34+Florante+at+Laura+Greater+Lagro+Quezon+City" target="_blank" rel="noopener"><i class="bi bi-geo-alt" aria-hidden="true"></i><span>Blk 79 Lot 34 Florante at Laura St.<br>Brgy. Greater Lagro, Quezon City, Philippines</span></a><a class="footer-facebook" href="https://www.facebook.com/profile.php?id=61578450361358" target="_blank" rel="noopener" aria-label="Kenji’s Kitchen on Facebook"><i class="bi bi-facebook" aria-hidden="true"></i><span>Facebook</span></a></div></div><small>&copy; <?= date('Y') ?> Kenji's Kitchen. All Rights Reserved.</small></footer>
  </main>

  <!-- Floating Cart Bar (Bottom Mobile Bar) -->
  <aside class="floating-cart-bar" id="floatingCartBar" onclick="openCartDrawer()" style="display: none;">
    <div class="cart-bar-left">
      <div class="cart-count-badge" id="floatingCartCount">0</div>
      <div class="cart-bar-title">View Your Order</div>
    </div>
    <div class="cart-bar-right">
      <div class="cart-bar-total" id="floatingCartTotal">₱0.00</div>
      <div class="cart-bar-btn">Checkout →</div>
    </div>
  </aside>

  <!-- Slide-Up Checkout Drawer / Cart Sheet -->
  <div class="drawer-backdrop" id="drawerBackdrop" onclick="closeCartDrawer()"></div>
  <section class="drawer-sheet" id="cartDrawerSheet">
    <div class="drawer-handle"></div>
    <div class="drawer-header">
      <h3>Your Food Basket</h3>
      <button type="button" id="clearBasketBtn" class="clear-basket-btn" onclick="clearFoodBasket()" disabled>Clear basket</button>
      <button class="nav-btn" style="min-width: 36px; min-height: 36px;" onclick="closeCartDrawer()">✕</button>
    </div>

    <div class="drawer-content">
      <div id="cartStockBanner" class="kk-cart-stock-banner" role="status" aria-live="polite" hidden></div>
      <div id="cartDrawerItems"></div>

      <div style="margin-top: 20px; border-top: 1px solid var(--border); padding-top: 14px;">
        <div style="display:flex; justify-content:space-between; margin-bottom: 6px; font-size: 0.9rem;">
          <span>Subtotal:</span>
          <strong id="drawerSubtotal">₱0.00</strong>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom: 6px; font-size: 0.9rem;">
          <span>Fulfillment:</span>
          <strong id="drawerDeliveryFeeText" style="color: var(--muted);">Select at checkout</strong>
        </div>
        <div style="display:flex; justify-content:space-between; font-size: 1.15rem; font-weight: 900; margin-top: 10px; border-top: 1px dashed var(--border); padding-top: 10px;">
          <span>Total Amount:</span>
          <strong id="drawerTotal" style="color: var(--dark);">₱0.00</strong>
        </div>

        <div id="cartMinOrderBanner" role="status" aria-live="polite" hidden style="margin-top: 12px; padding: 10px 12px; border-radius: 10px; background: #FFFBEB; border: 1px solid #FCD34D; color: #92400E; font-size: 0.84rem; line-height: 1.45;"></div>

        <button class="btn-primary" id="btnProceedCheckout" style="margin-top: 18px;" onclick="proceedToCheckout()">
          Proceed to Order Verification →
        </button>
      </div>
    </div>
  </section>

  <!-- Item Detail & Special Instructions Modal -->
  <div class="modal-overlay" id="itemDetailModal">
    <div class="modal-card">
      <button class="btn-close-modal" onclick="closeModal('itemDetailModal')">✕</button>
      <img src="" id="modalItemImg" class="modal-hero-img" alt="Meal">
      <div class="modal-body">
        <h2 class="modal-title" id="modalItemTitle">Food Name</h2>
        <div class="modal-price" id="modalItemPrice">₱0.00</div>
        <p style="font-size: 0.85rem; color: var(--muted); margin-bottom: 14px;" id="modalItemDesc"></p>

        <!-- Dynamic Size / Portion Picker (for Noodles, Pastas, and Multi-Size items) -->
        <div id="modalSizePickerContainer" class="size-picker-section" style="display:none; margin-bottom: 16px;">
          <label class="size-picker-title">
            <i class="bi bi-ui-radios"></i> Choose Size / Portion
          </label>
          <div class="size-options-grid" id="modalSizeOptions">
            <!-- Dynamically injected size buttons / radio pills -->
          </div>
        </div>
        <p id="bilaoReservationNotice" class="kk-inline-feedback" style="display:none; margin:0 0 14px;" role="note">
          <i class="bi bi-calendar2-event" aria-hidden="true"></i> Size M, L &amp; XL Bilao platters require <strong>1 to 2 days advance reservation</strong> for preparation.
        </p>

        <!-- Packaging Option Picker (Tray vs Bilao for Bilao Category Dishes) -->
        <div id="modalPackagingPickerContainer" class="packaging-picker-section" style="display:none; margin-bottom: 16px;">
          <label class="packaging-picker-title">
            <i class="bi bi-box-seam" aria-hidden="true"></i><span>Choose Packaging</span> <span class="packaging-required-tag">*Required</span>
          </label>
          <div class="packaging-options-grid" id="modalPackagingOptions">
            <div class="packaging-option-card" id="pkgOptionTray" onclick="selectPackaging('Tray')">
              <div class="packaging-info-col">
                <span class="packaging-name-tag"><i class="bi bi-box"></i> Tray</span>
                <span class="packaging-desc-tag">Catering pan</span>
              </div>
              <div class="packaging-radio-indicator"></div>
            </div>
            <div class="packaging-option-card" id="pkgOptionBilao" onclick="selectPackaging('Bilao')">
              <div class="packaging-info-col">
                <span class="packaging-name-tag"><i class="bi bi-circle"></i> Bilao</span>
                <span class="packaging-desc-tag">Traditional woven tray</span>
              </div>
              <div class="packaging-radio-indicator"></div>
            </div>
          </div>
          <p id="packagingHelpText" class="packaging-help-text" style="display:none; color: #EF4444; font-size: 0.8rem; margin: 6px 0 0 0; font-weight: 600;">
            Please select a packaging option (Tray or Bilao).
          </p>
        </div>

        <div id="modalDrinkFlavorContainer" class="drink-flavor-section" style="display:none; margin-bottom: 16px;">
          <label for="modalDrinkFlavor"><i class="bi bi-cup-straw"></i> Choose your 1L drink <span>*Required</span></label>
          <select id="modalDrinkFlavor" class="drink-flavor-select">
            <option value="">Select a drink</option>
          </select>
        </div>

        <!-- Customer Special Instructions / Notes -->
        <div class="instructions-container">
          <label class="instructions-label" for="modalItemNotes">
            <i class="bi bi-pencil"></i> Special Instructions / Notes
          </label>
          <textarea id="modalItemNotes" class="instructions-textarea" placeholder="e.g. No onions, extra gravy, less ice, well done egg..."></textarea>
        </div>

        <div class="modal-qty-actions-row">
          <div class="qty-pill">
            <button type="button" class="qty-btn" id="modalQtyMinus" aria-label="Decrease quantity" onclick="adjustModalQty(-1)">-</button>
            <input type="text" inputmode="numeric" pattern="[0-9]*" class="qty-value modal-qty-input" id="modalQty" value="1" aria-label="Item quantity" aria-describedby="modalStockFeedback">
            <button type="button" class="qty-btn" id="modalQtyPlus" aria-label="Increase quantity" onclick="adjustModalQty(1)">+</button>
          </div>

          <button type="button" class="btn-primary" id="modalAddOrderBtn" style="width: auto; padding: 12px 24px;" onclick="confirmAddItemFromModal()">
            Add to Order
          </button>
        </div>
        <p id="modalStockFeedback" class="kk-inline-feedback" role="status" aria-live="polite" hidden></p>
      </div>
    </div>
  </div>

  <!-- Unified Auth Modal: Sign In & Register with OTP Verification -->
  <div class="modal-overlay" id="authModal">
    <div class="modal-card auth-modal-card">
      <button class="btn-close-modal" onclick="closeModal('authModal')">✕</button>

      <!-- Dual Tab Bar -->
      <div class="auth-tabs">
        <button class="auth-tab active" id="tabSignIn" onclick="switchTab('signin')">
          <i class="bi bi-person-check"></i> Sign In
        </button>
        <button class="auth-tab" id="tabRegister" onclick="switchTab('register')">
          <i class="bi bi-person-plus"></i> Register
        </button>
      </div>

      <div class="modal-body" style="padding: 24px;">

        <!-- STEP 1: Input Forms -->
        <div id="authStep1">

          <!-- Sign In Form (Direct Email + Password Authentication) -->
          <form id="formSignIn" onsubmit="event.preventDefault(); handleDirectSignIn(); return false;" novalidate>
            <p class="auth-sub">Sign in with your registered email and password.</p>
            <div class="auth-field">
              <label for="siEmailInput">Email Address</label>
              <input type="email" id="siEmailInput" class="auth-input" placeholder="name@example.com" autocomplete="email" required>
            </div>
            <div class="auth-field">
              <label for="siPasswordInput">Password</label>
              <div class="pw-wrap">
                <input type="password" id="siPasswordInput" class="auth-input pw-input" placeholder="Enter your password" autocomplete="current-password" required>
                <button type="button" class="pw-eye-btn" aria-label="Show password" onclick="togglePwVisibility('siPasswordInput', this)">
                  <svg class="eye-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12C3.73 7.61 7.5 5 12 5s8.27 2.61 11 7c-2.73 4.39-6.5 7-11 7s-8.27-2.61-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="eye-off-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
              </div>
            </div>
            <div class="auth-recovery-row">
              <button type="button" class="auth-recovery-link" onclick="openPasswordRecovery()" aria-controls="authRecovery">Forgot Password?</button>
            </div>
            <button type="submit" class="auth-btn-primary" id="btnSignInDirect">
              <i class="bi bi-box-arrow-in-right"></i> Sign In
            </button>
            <p class="auth-legal-note" style="margin-top: 14px; font-size: 0.78rem; color: var(--muted); text-align: center; line-height: 1.5;">
              By signing in, you agree to our <a href="terms.php" target="_blank" rel="noopener noreferrer" style="color: var(--primary); font-weight: 700; text-decoration: underline;">Terms of Use</a> and acknowledge our <a href="privacy.php" target="_blank" rel="noopener noreferrer" style="color: var(--primary); font-weight: 700; text-decoration: underline;">Privacy Policy</a>.
            </p>
          </form>

          <!-- Register Form -->
          <form id="formRegister" style="display:none;" onsubmit="event.preventDefault(); sendCode('register'); return false;" novalidate>
            <p class="auth-sub">Create an account to track your orders.</p>
            <div class="auth-field">
              <label for="regNameInput">Full Name</label>
              <input type="text" id="regNameInput" class="auth-input" placeholder="Juan dela Cruz" autocomplete="name" required>
            </div>
            <p class="auth-sub auth-email-note"><i class="bi bi-envelope-check"></i> Verification codes are sent to your email inbox.</p>
            <div class="auth-field" id="fieldEmailReg">
              <label for="regEmailInput">Email Address</label>
              <input type="email" id="regEmailInput" class="auth-input" placeholder="name@example.com" autocomplete="email" required>
            </div>
            <div class="auth-field">
              <label for="regPasswordInput">Password</label>
              <div class="pw-wrap">
                <input type="password" id="regPasswordInput" class="auth-input pw-input" placeholder="Create a password (min. 6 chars)" autocomplete="new-password" required>
                <button type="button" class="pw-eye-btn" aria-label="Show password" onclick="togglePwVisibility('regPasswordInput', this)">
                  <svg class="eye-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12C3.73 7.61 7.5 5 12 5s8.27 2.61 11 7c-2.73 4.39-6.5 7-11 7s-8.27-2.61-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="eye-off-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
              </div>
            </div>
            <button type="submit" class="auth-btn-primary" id="btnSendReg">
              <i class="bi bi-send-fill"></i> Send Verification Code
            </button>
            <p class="auth-legal-note" style="margin-top: 14px; font-size: 0.78rem; color: var(--muted); text-align: center; line-height: 1.5;">
              By creating an account, you agree to our <a href="terms.php" target="_blank" rel="noopener noreferrer" style="color: var(--primary); font-weight: 700; text-decoration: underline;">Terms of Use</a> and acknowledge our <a href="privacy.php" target="_blank" rel="noopener noreferrer" style="color: var(--primary); font-weight: 700; text-decoration: underline;">Privacy Policy</a>.
            </p>
          </form>

        </div><!-- /authStep1 -->

        <!-- STEP 2: OTP Entry -->
        <div id="authStep2" style="display:none;">
          <div class="auth-otp-icon"><i class="bi bi-shield-lock-fill"></i></div>
          <h3 class="auth-otp-title">Check your <span id="authOtpChannel">email</span></h3>
          <p class="auth-sub" style="text-align:center;">
            We sent a 6-digit code to<br>
            <strong id="authOtpTarget" style="color:var(--dark);"></strong>
          </p>

          <!-- Individual digit input boxes -->
          <div class="auth-otp-boxes" id="authOtpBoxes">
            <input class="auth-otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
            <input class="auth-otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
            <input class="auth-otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
            <input class="auth-otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
            <input class="auth-otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
            <input class="auth-otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
          </div>

          <div class="auth-timer-row">
            <i class="bi bi-clock"></i>
            Expires in: <span id="authTimerDisplay" style="font-weight:800; color:var(--danger);">5:00</span>
          </div>

          <button class="auth-btn-primary" id="btnVerify" onclick="verifyCode()">
            <i class="bi bi-check-circle-fill"></i> Verify Code
          </button>
          <button class="auth-btn-back" onclick="goBackToForm()">
            <i class="bi bi-arrow-left"></i> Back
          </button>
          <p class="auth-resend-row">
            Didn't receive it?
            <button class="auth-resend-btn" id="btnResend" onclick="resendCode()" disabled>Resend code</button>
          </p>
        </div><!-- /authStep2 -->

        <section id="authRecovery" style="display:none;" aria-labelledby="authRecoveryTitle">
          <h3 id="authRecoveryTitle" class="auth-otp-title">Forgot Password?</h3>
          <p class="auth-sub" id="recoveryHelp">Enter your registered email to receive a 6-digit reset code.</p>

          <form id="recoveryEmailForm" onsubmit="event.preventDefault(); requestRecoveryCode();">
            <div class="auth-field">
              <label for="recoveryEmail">Email Address</label>
              <input type="email" id="recoveryEmail" class="auth-input" placeholder="name@example.com" autocomplete="email" required>
            </div>
            <button type="submit" id="recoverySend" class="auth-btn-primary">Send Reset Code</button>
          </form>

          <form id="recoveryResetForm" style="display:none;" onsubmit="event.preventDefault(); submitRecoveryPassword();">
            <div class="auth-field">
              <label for="recoveryCode">6-Digit Verification Code</label>
              <input type="text" id="recoveryCode" class="auth-input" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="Enter your code" required>
            </div>
            <div class="auth-timer-row">Code expires in <span id="recoveryExpiry">5:00</span></div>
            <div class="auth-field">
              <label for="recoveryPassword">New Password</label>
              <div class="pw-wrap">
                <input type="password" id="recoveryPassword" class="auth-input pw-input" minlength="6" maxlength="72" autocomplete="new-password" placeholder="At least 6 characters" required>
                <button type="button" class="pw-eye-btn" aria-controls="recoveryPassword" aria-label="Show password" onclick="togglePwVisibility('recoveryPassword', this)">
                  <svg class="eye-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12C3.73 7.61 7.5 5 12 5s8.27 2.61 11 7c-2.73 4.39-6.5 7-11 7s-8.27-2.61-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="eye-off-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
              </div>
            </div>
            <div class="auth-field">
              <label for="recoveryConfirm">Confirm New Password</label>
              <div class="pw-wrap">
                <input type="password" id="recoveryConfirm" class="auth-input pw-input" minlength="6" maxlength="72" autocomplete="new-password" placeholder="Re-enter your new password" required>
                <button type="button" class="pw-eye-btn" aria-controls="recoveryConfirm" aria-label="Show password" onclick="togglePwVisibility('recoveryConfirm', this)">
                  <svg class="eye-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12C3.73 7.61 7.5 5 12 5s8.27 2.61 11 7c-2.73 4.39-6.5 7-11 7s-8.27-2.61-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="eye-off-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
              </div>
            </div>
            <button type="submit" id="recoverySubmit" class="auth-btn-primary">Reset Password</button>
            <p class="auth-resend-row">
              <button type="button" id="recoveryResend" class="auth-resend-btn" onclick="requestRecoveryCode(true)" disabled>Resend Code (60s)</button>
            </p>
            <button type="button" class="auth-btn-back" onclick="showRecoveryEmail()">Use Another Email</button>
          </form>
          <button type="button" class="auth-btn-back" onclick="backToRecoverySignIn()"><i class="bi bi-arrow-left"></i> Back to Sign In</button>
        </section>

      </div><!-- /modal-body -->
    </div>
  </div>

  <!-- Legacy OTP Modal (backward compat) -->
  <div class="modal-overlay" id="otpModal">
    <div class="modal-card" style="max-width: 420px;">
      <button class="btn-close-modal" onclick="closeModal('otpModal')">✕</button>
      <div class="modal-body" style="padding: 24px;">
        <div id="otpStepEmail">
          <h2 style="font-size: 1.25rem; font-weight: 800; margin-bottom: 6px;">Sign In with OTP</h2>
          <p style="font-size: 0.85rem; color: var(--muted); margin-bottom: 16px;">
            We'll send a 6-digit verification code to your email to verify your order.
          </p>
          <div style="margin-bottom: 16px;">
            <label style="font-size: 0.85rem; font-weight: 700; display: block; margin-bottom: 6px;">Email Address</label>
            <input type="email" id="otpEmailInput" class="search-input" style="border-radius: var(--radius-sm);" placeholder="name@example.com" required>
          </div>
          <button class="btn-primary" id="btnRequestOtp" onclick="handleRequestOtp()">Send Verification Code</button>
        </div>
        <div id="otpStepCode" style="display: none;">
          <h2 style="font-size: 1.25rem; font-weight: 800; margin-bottom: 6px;">Enter 6-Digit Code</h2>
          <p style="font-size: 0.85rem; color: var(--muted); margin-bottom: 14px;">
            Sent to <strong id="otpTargetEmail"></strong>. Expires in: <span id="otpTimerDisplay" style="color: var(--danger); font-weight: 800;">5:00</span>
          </p>
          <div style="margin-bottom: 18px;">
            <input type="text" id="otpCodeInput" class="otp-input" maxlength="6" inputmode="numeric" placeholder="••••••">
          </div>
          <button class="btn-primary" onclick="handleVerifyOtp()">Verify &amp; Continue Order →</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Checkout Details Modal -->
  <div class="modal-overlay" id="checkoutModal">
    <div class="modal-card checkout-card">
      <div class="checkout-modal-header"><h2>Order Details &amp; Payment</h2><button type="button" class="btn-close-modal" onclick="closeModal('checkoutModal')" aria-label="Close checkout">✕</button></div>
      <div class="modal-body checkout-modal-body">

        <div id="checkoutValidationAlert" role="alert" aria-live="polite" style="display:none; margin-bottom: 12px; padding: 10px 12px; border-radius: 10px; font-size: 0.84rem; font-weight: 600; border: 1px solid rgba(217, 83, 79, 0.45); background: rgba(217, 83, 79, 0.12); color: #ef4444;"></div>

        <div style="margin-bottom: 12px;">
          <label for="custNameInput" style="font-size: 0.85rem; font-weight: 700; display: block; margin-bottom: 4px;">Full Name <span style="color:var(--danger);">*</span></label>
          <input type="text" id="custNameInput" class="checkout-input" placeholder="Juan dela Cruz" required>
        </div>

        <div style="margin-bottom: 12px;">
          <label for="custPhoneInput" style="font-size: 0.85rem; font-weight: 700; display: block; margin-bottom: 4px;">Phone Number <span style="color:var(--danger);">*</span></label>
          <input type="tel" id="custPhoneInput" class="checkout-input" maxlength="11" inputmode="numeric" pattern="09[0-9]{9}" placeholder="09171234567" required>
          <small style="color:var(--muted);font-size:0.75rem;display:block;margin-top:3px;">11-digit Philippine mobile number starting with 09 (e.g. 09171234567).</small>
        </div>

        <div style="margin-bottom: 14px;">
          <label style="font-size: 0.85rem; font-weight: 700; display: block; margin-bottom: 6px;">Fulfillment Method <span style="color:var(--danger);">*</span></label>
          <div class="fulfillment-toggle" id="checkoutFulfillmentGroup" role="radiogroup" aria-label="Fulfillment Method" style="width: 100%; display: flex; gap: 10px; padding: 4px;">
            <button type="button" class="fulfillment-btn" data-mode="delivery" style="flex: 1; justify-content: center; padding: 10px 12px;" onclick="selectCheckoutFulfillment('delivery')">
              <i class="bi bi-bicycle"></i> Delivery
            </button>
            <button type="button" class="fulfillment-btn" data-mode="pickup" style="flex: 1; justify-content: center; padding: 10px 12px;" onclick="selectCheckoutFulfillment('pickup')">
              <i class="bi bi-bag"></i> Pickup
            </button>
          </div>
          <small id="fulfillmentHelpText" style="color:var(--muted);display:block;margin-top:5px;">Select Delivery or Pickup to continue.</small>
        </div>

        <section id="pickupSchedule" class="pickup-schedule" hidden>
          <label>Pickup day</label>
          <div id="pickupDayChoices" class="pickup-day-choices"></div>
          <label id="pickupTimeLabel" for="pickupTime">Pickup time</label>
          <select id="pickupTime" class="checkout-input"><option value="">Choose a time</option></select>
          <p id="pickupScheduleHelp" role="status">Choose a time at least 30 minutes from now.</p>
          <div class="pickup-store"><strong>Pickup location · Kenji’s Kitchen</strong><span>Blk 79 Lot 34 Florante at Laura St., Greater Lagro, Quezon City</span></div>
        </section>

        <div id="addressFieldWrap" style="display: none; margin-bottom: 14px;">
          <!-- Read-only state mirrors bound strictly to verified server coverage (cannot override verification) -->
          <select id="deliveryBarangaySelect" hidden disabled aria-hidden="true" tabindex="-1">
            <option value="" selected></option>
            <option value="BRGY_GREATER_LAGRO">Within Greater Lagro</option>
            <option value="OUTSIDE_LAGRO">Outside Greater Lagro</option>
            <option value="OTHER_UNVERIFIED">Needs confirmation</option>
          </select>
          <select id="deliveryModeSelect" hidden disabled aria-hidden="true" tabindex="-1">
            <option value="" selected></option>
            <option value="LAGRO_FREE">Kenji’s staff · Free delivery</option>
            <option value="CUSTOMER_BOOKED">Third-party courier</option>
          </select>
          <input type="hidden" id="deliveryLatInput" value="">
          <input type="hidden" id="deliveryLngInput" value="">

          <div class="delivery-step-block">
            <label for="custAddressInput" style="font-size: 0.85rem; font-weight: 700; display: block; margin-bottom: 4px;">Delivery Address <span style="color:var(--danger);">*</span></label>
            <textarea rows="2" id="custAddressInput" class="checkout-input" maxlength="500" placeholder="House / Blk &amp; Lot, Street, Subdivision / Barangay" oninput="handleDeliveryAddressInput(true)" autocomplete="street-address"></textarea>
            <div class="delivery-address-assist-row">
              <small style="color:var(--muted);font-size:0.75rem;">Fills from the map pin. Add House / Blk &amp; Lot / Unit if needed.</small>
              <div class="delivery-address-assist-actions">
                <button type="button" id="btnFindAddressOnMap" class="delivery-map-chip-btn" onclick="findTypedAddressOnMap()" title="Locate your typed delivery address on the map">
                  <i class="bi bi-search" aria-hidden="true"></i> Find address on map
                </button>
                <button type="button" id="btnUsePinnedAddress" class="delivery-map-chip-btn" style="display:none;" onclick="applyPinnedAddressToInput()"></button>
                <button type="button" id="btnJumpSuggestedStreet" class="delivery-map-chip-btn" style="display:none;" onclick="jumpMapToSuggestedStreet()"></button>
              </div>
            </div>
          </div>

          <div class="delivery-map-section">
            <div class="delivery-map-header">
              <label for="deliveryCoverageMap" style="font-size: 0.85rem; font-weight: 700; margin: 0;">Pin your destination <span style="color:var(--danger);">*</span></label>
              <div class="delivery-map-actions" role="toolbar" aria-label="Map location actions">
                <button type="button" id="btnPinCenter" class="delivery-map-action-btn" onclick="placeDeliveryPinAtMapCenter()" title="Drop pin at the center of the map">
                  <i class="bi bi-geo-alt-fill" aria-hidden="true"></i> Drop pin at center
                </button>
                <button type="button" id="btnUseMyLocation" class="delivery-map-action-btn" onclick="useOneTimeDeviceLocation()" title="Use your device GPS location">
                  <i class="bi bi-crosshair" aria-hidden="true"></i> Use My Location
                </button>
                <button type="button" id="btnShowLagroZone" class="delivery-map-action-btn" onclick="resetDeliveryMapView()" title="Fit map to Barangay Greater Lagro">
                  <i class="bi bi-bounding-box-circles" aria-hidden="true"></i> Show Lagro Zone
                </button>
              </div>
            </div>
            <p class="delivery-map-instructions">Tap the map or drag the pin to set your location.</p>
            <div id="deliveryMapTileNotice" class="delivery-map-tile-notice" role="status" aria-live="polite" style="display:none;"></div>
            <div id="deliveryCoverageMap" class="delivery-coverage-map" tabindex="0" role="region" aria-label="Interactive delivery coverage map for Barangay Greater Lagro. Click to place your pin, or press Enter or Space to pin the map center."></div>
            <div class="delivery-map-legend">
              <span><i class="legend-swatch legend-lagro" aria-hidden="true"></i> Greater Lagro (Free ₱0)</span>
              <span><i class="legend-swatch legend-outside" aria-hidden="true"></i> Outside Lagro (Courier)</span>
              <span><i class="legend-swatch legend-store" aria-hidden="true"></i> Kenji’s Kitchen</span>
            </div>
          </div>

          <div id="deliveryMapCredit" class="delivery-map-credit"></div>

          <div id="deliveryAreaVerificationStatus" class="delivery-coverage-status" role="status" aria-live="polite"></div>

          <div id="courierBookingWrap" style="display:none;margin-top:12px;">
            <input type="hidden" id="courierBookingBy" value="customer">
            <fieldset class="courier-booking-fieldset">
              <legend class="courier-choice-heading">Who will book the courier? <span style="color:var(--danger);">*</span></legend>
              <div class="courier-choice-group" id="courierBookingGroup">
                <label class="courier-choice-card active" data-choice="customer">
                  <span class="courier-choice-top">
                    <input type="radio" class="courier-choice-radio" name="courierBookingChoice" value="customer" checked onchange="selectCourierBookingChoice('customer')">
                    <span class="courier-choice-title">I’ll book the rider</span>
                  </span>
                  <span class="courier-choice-desc">Use Lalamove or GrabExpress and pay the rider directly.</span>
                </label>
                <label class="courier-choice-card" data-choice="store">
                  <span class="courier-choice-top">
                    <input type="radio" class="courier-choice-radio" name="courierBookingChoice" value="store" onchange="selectCourierBookingChoice('store')">
                    <span class="courier-choice-title">Staff will book for me</span>
                  </span>
                  <span class="courier-choice-desc">We’ll book the rider and post the fee in My Orders.</span>
                </label>
              </div>
            </fieldset>
            <p id="courierBookingNote" class="checkout-delivery-note" style="display:none;"></p>
            <div id="thirdPartyTrackingWrap" class="courier-tracking-field">
              <div class="courier-tracking-label-row">
                <label for="custTrackingUrl">Courier tracking link</label>
                <span class="courier-optional-badge">Optional</span>
              </div>
              <input type="url" id="custTrackingUrl" class="checkout-input" placeholder="https://..." inputmode="url" autocomplete="url" autocorrect="off" autocapitalize="none" spellcheck="false" aria-describedby="thirdPartyTrackingHelp">
              <small id="thirdPartyTrackingHelp" class="courier-tracking-help">Paste your third-party carrier (Lalamove, GrabExpress) tracking link now, or add it later in My Orders.</small>
            </div>
          </div>
        </div>

        <div style="margin-bottom: 14px;">
          <label style="font-size: 0.85rem; font-weight: 700; display: block; margin-bottom: 4px;">Payment Method <span style="color:var(--danger);">*</span></label>
          <div style="display: flex; gap: 10px;">
            <label id="cashPaymentOption" onpointerdown="explainUnavailableCash()" style="flex:1; display:flex; align-items:center; gap:6px; padding: 10px; border: 1.5px solid var(--border); border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; cursor: pointer;">
              <input type="radio" name="paymentMethod" value="Cash" onchange="toggleOnlinePaymentMethod()"> <i class="bi bi-cash"></i> Cash
            </label>
            <label id="gcashPaymentOption" style="flex:1; display:flex; align-items:center; gap:6px; padding: 10px; border: 1.5px solid var(--border); border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; cursor: pointer;">
              <input type="radio" name="paymentMethod" value="GCash" onchange="toggleOnlinePaymentMethod()"> <i class="bi bi-phone"></i> GCash
            </label>
          </div>
          <small id="paymentMethodHelp" role="status" aria-live="polite" style="color:var(--muted);display:none;margin-top:7px;">Cash is unavailable outside Lagro. Use GCash for the food.</small>
        </div>

        <div id="onlineGcashWrap" style="display: none; margin-bottom: 14px;">
          <section class="gcash-payment-panel" aria-label="Pay by GCash QR code">
            <p class="gcash-payment-title"><i class="bi bi-qr-code-scan"></i> Scan to pay with GCash</p>
            <div class="gcash-qr-frame unavailable">
              <img class="gcash-qr-image" id="onlineGcashQr" alt="Kenji's Kitchen GCash payment QR code" loading="eager">
              <div class="gcash-qr-unavailable" role="status">
                <i class="bi bi-qr-code"></i>
                <span>No QR image is set. You can send your payment to the GCash number below.</span>
              </div>
            </div>
            <div class="gcash-account-details"><span>Account name</span><strong id="onlineGcashName">Loading payment details…</strong><span>Account number</span><strong id="onlineGcashPhone">Loading…</strong></div><div class="gcash-account-actions"><a id="onlineGcashDownload" hidden download="kenjis-kitchen-gcash.png"><i class="bi bi-download"></i> Save QR image</a><button type="button" onclick="copyGcashNumber('onlineGcashPhone', this)"><i class="bi bi-copy"></i> Copy number</button></div><p class="gcash-payment-help">Check the account name in GCash before confirming your payment.</p>
            <p class="gcash-payment-help">Pay by scanning the QR or sending to this number, then enter the 13-digit reference number and upload your payment screenshot below.</p>
          </section>
          <label for="custGcashRef" style="font-size: 0.85rem; font-weight: 700; display: block; margin-bottom: 4px;">GCash reference number <span style="color:var(--danger);">*</span></label>
          <input type="text" id="custGcashRef" class="checkout-input" style="letter-spacing: 1px;" maxlength="13" placeholder="Enter 13-digit reference" inputmode="numeric" aria-describedby="gcashReferenceHelp">
          <small id="gcashReferenceHelp" style="color: var(--muted); font-size: 0.75rem; display: block; margin-top: 3px;">Find this number on your GCash receipt.</small>

          <div style="margin-top: 12px;">
            <label for="custGcashProofInput" style="font-size: 0.85rem; font-weight: 700; display: block; margin-bottom: 4px;">Upload Payment Proof <span style="color:var(--danger);">*</span></label>
            <input type="file" id="custGcashProofInput" class="checkout-input" accept="image/jpeg,image/png,image/webp" onchange="handleGcashProofSelection(event)" style="padding: 8px;">
            <small style="color: var(--muted); font-size: 0.75rem; display: block; margin-top: 3px;">Accepted formats: JPG, PNG, or WebP (max 5 MB). Staff will verify your payment before kitchen preparation.</small>
            <div id="gcashProofPreviewWrap" style="display: none; margin-top: 10px; padding: 10px; border: 1px solid var(--border); border-radius: 10px; background: var(--soft);">
              <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px;">
                <span id="gcashProofFileName" style="font-size: 0.8rem; font-weight: 700; color: var(--dark); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></span>
                <button type="button" onclick="clearGcashProofSelection()" style="background: rgba(217,83,79,0.12); color: #ef4444; border: 1px solid rgba(217,83,79,0.35); border-radius: 6px; padding: 4px 10px; font-size: 0.76rem; font-weight: 700; cursor: pointer; flex-shrink: 0;">
                  <i class="bi bi-trash"></i> Remove / Replace
                </button>
              </div>
              <img id="gcashProofPreviewImg" src="" alt="Upload Payment Proof Preview" style="max-height: 160px; max-width: 100%; border-radius: 8px; display: block; margin: 0 auto; object-fit: contain; border: 1px solid var(--border);">
            </div>
          </div>
        </div>

        <div style="margin-bottom: 14px; padding: 10px 12px; border-radius: 10px; background: rgba(242, 193, 46, 0.12); border: 1px solid rgba(242, 193, 46, 0.4); font-size: 0.82rem; color: var(--dark); line-height: 1.45;">
          <i class="bi bi-clock-history" style="color: var(--primary);"></i>
          <strong>Estimated kitchen preparation: <span id="checkoutPrepEstimateText">~15 to 25 minutes</span></strong>
          <div id="checkoutPrepHelp" style="font-size: 0.75rem; color: var(--muted); margin-top: 2px;">This covers food preparation only. Delivery time depends on courier availability, travel distance, and traffic.</div>
        </div>

        <div style="margin-bottom: 18px;">
          <label style="font-size: 0.85rem; font-weight: 700; display: block; margin-bottom: 4px;">Order Notes (Optional)</label>
          <input type="text" id="checkoutOverallNotes" class="checkout-input" placeholder="e.g. Ring the doorbell, leave with guard">
        </div>

        <button class="btn-primary" id="btnConfirmOrder" onclick="submitOrder()">
          Place Order Now
        </button>
      </div>
    </div>
  </div>

  <!-- Order Confirmation Modal -->
  <div class="modal-overlay" id="orderConfirmationModal">
    <div class="modal-card order-confirmation-card" role="dialog" aria-modal="true" aria-labelledby="orderConfirmationTitle">
      <div class="modal-body">
        <span class="confirmation-brand">KENJI’S KITCHEN</span><div class="confirmation-check" aria-hidden="true"><i class="bi bi-check-lg"></i></div>
        <h2 id="orderConfirmationTitle">Thanks for your order!</h2>
        <p id="confRoutingStatus" style="font-size: 0.88rem; color: var(--muted); margin: 8px 0 18px 0;">
          Your order has been placed. Track it for updates.
        </p>

        <div class="confirmation-summary">
          <div style="display:flex; justify-content:space-between; margin-bottom:6px; font-size: 0.85rem;">
            <span>Order number</span>
            <strong id="confOrderNo">ONL-2026-0001</strong>
          </div>
          <div style="display:flex; justify-content:space-between; font-size: 0.85rem;">
            <span>Receipt number</span>
            <strong id="confReceiptNo">RCPT-00000</strong>
          </div>
          <div style="display:flex; justify-content:space-between; font-size: 0.85rem; margin-top: 6px;">
            <span>Estimated prep</span>
            <strong id="confPrepEstimate">~15 to 25 mins (Estimate)</strong>
          </div>
          <div style="display:flex; justify-content:space-between; font-size: 0.95rem; font-weight: 800; border-top: 1px dashed var(--border); padding-top: 6px; margin-top: 6px;">
            <span>Order total</span>
            <strong id="confTotal" style="color: var(--dark);">₱0.00</strong>
          </div>
        </div>
        <p id="confReceiptStatus" class="receipt-email-status" aria-live="polite"></p>

        <button class="btn-primary" style="margin-bottom:10px;" onclick="openOrderTrackingFromConfirmation()">
          <i class="bi bi-geo-alt"></i> Track order
        </button>
        <button type="button" class="btn-primary customer-secondary-action" style="margin-bottom:10px;" onclick="if (appState.lastOrderId) openCustomerReceiptModal(appState.lastOrderId)">
          <i class="bi bi-receipt"></i> View receipt
        </button>
        <button class="confirmation-menu-action" onclick="closeModal('orderConfirmationModal')">
          Back to Menu
        </button>
      </div>
    </div>
  </div>

  <!-- Signed-in customer tools -->
  <div class="modal-overlay" id="customerAccountModal">
    <div class="modal-card customer-tools-modal">
      <button class="btn-close-modal" aria-label="Close My Account" onclick="closeModal('customerAccountModal')">✕</button>
      <div class="modal-body">
        <div class="account-profile-header">
          <div class="account-avatar-frame" id="customerAccountAvatarPreview" aria-hidden="true"></div>
          <div class="account-profile-meta">
            <h2>My Account</h2>
            <p class="customer-account-name" id="customerAccountName"></p>
            <div class="account-avatar-controls">
              <input type="file" id="customerAvatarInput" accept="image/jpeg,image/png,image/webp" hidden>
              <button type="button" class="account-avatar-btn" id="btnUploadAvatar" onclick="triggerCustomerAvatarPicker()">Add photo</button>
              <button type="button" class="account-avatar-btn account-avatar-remove" id="btnRemoveAvatar" onclick="handleRemoveCustomerAvatar()" style="display: none;">Remove</button>
            </div>
            <p class="account-avatar-hint" id="customerAvatarStatus">JPG, PNG, or WebP, up to 5 MB.</p>
          </div>
        </div>
        <div class="customer-tools-actions">
          <button class="btn-primary" onclick="openOrdersModal()"><i class="bi bi-receipt"></i> My Orders</button>
          <button class="btn-primary customer-secondary-action" onclick="openFavoritesModal()"><i class="bi bi-heart"></i> My Favorites</button>
          <button class="customer-signout-action" onclick="handleSignOut()"><i class="bi bi-box-arrow-right"></i> Sign Out</button>
          <button type="button" class="customer-signout-action" id="btnToggleDeactivateAccount" onclick="toggleDeactivateAccountPanel()">
            <i class="bi bi-person-slash"></i> Deactivate Account
          </button>
        </div>

        <form id="deactivateAccountPanel" style="display: none;" onsubmit="event.preventDefault(); handleDeactivateCustomerAccount(); return false;" novalidate>
          <strong><i class="bi bi-exclamation-triangle-fill"></i> Deactivate Your Account</strong>
          <p>
            Deactivating your account will immediately sign you out and disable future sign-ins. Historical orders, payments, and receipts are preserved for store records.
          </p>
          <div id="deactivateAccountError" style="display: none;" role="alert"></div>
          <div>
            <label for="deactivateAccountPassword">Confirm Current Password <span>*</span></label>
            <input type="password" id="deactivateAccountPassword" class="checkout-input" placeholder="Enter your current password" autocomplete="current-password" required>
          </div>
          <label>
            <input type="checkbox" id="deactivateAccountConfirmCheck" required>
            <span>I understand that my account will be set to <strong>Inactive</strong> and I will be signed out immediately.</span>
          </label>
          <div class="deactivate-actions"><button type="button" class="btn-primary customer-secondary-action" onclick="toggleDeactivateAccountPanel(false)">Cancel</button>
            <button type="submit" id="btnConfirmDeactivateAccount" class="btn-primary">Deactivate Now</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal-overlay" id="customerOrdersModal">
    <div class="modal-card customer-tools-modal customer-orders-modal">
      <button type="button" class="btn-close-modal customer-orders-close" aria-label="Close My Orders" title="Close My Orders" onclick="closeModal('customerOrdersModal')">×</button>
      <div class="modal-body">
        <div class="customer-orders-head">
          <div>
            <h2><i class="bi bi-receipt"></i> My Orders</h2>
            <p class="muted">Track active orders live and review your past order receipts.</p>
          </div>
        </div>

        <!-- Optional customer review composer (only shown when customer chooses to review a completed order) -->
        <section id="orderReviewComposer" class="order-review-composer" style="display:none;" aria-live="polite"></section>
        
        <!-- Top: Active Order Live Tracking Panel -->
        <section id="orderTrackingPanel" class="order-tracking-panel" aria-live="polite"></section>

        <!-- Active Orders Section (shown when orders are in progress) -->
        <div id="customerActiveOrdersSection" class="customer-orders-section" style="display:none;">
          <h3 class="customer-section-heading"><i class="bi bi-clock-history"></i> Active Orders</h3>
          <div id="customerActiveOrdersList" class="customer-orders-list"></div>
        </div>

        <!-- Order History Section -->
        <div id="customerHistorySection" class="customer-orders-section">
          <h3 class="customer-section-heading" id="orderHistoryHeading"><i class="bi bi-journal-text"></i> Order History</h3>
          <div id="customerOrdersList" class="customer-orders-list">Loading orders…</div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal-overlay" id="customerFavoritesModal">
    <div class="modal-card customer-tools-modal">
      <button class="btn-close-modal" onclick="closeModal('customerFavoritesModal')">✕</button>
      <div class="modal-body">
        <h2><i class="bi bi-heart-fill"></i> My Favorites</h2>
        <p class="muted">Your saved menu items, available whenever you sign in.</p>
        <div id="customerFavoritesList" class="customer-favorites-list">Loading favorites…</div>
      </div>
    </div>
  </div>

  <!-- Customer Receipt / Order Summary Modal (View, Print, Save as PDF) -->
  <div class="modal-overlay" id="customerReceiptModal">
    <div class="modal-card receipt-modal-card" style="max-width: 440px;">
      <button type="button" class="btn-close-modal" aria-label="Close Receipt" onclick="closeModal('customerReceiptModal')">✕</button>
      <div class="modal-body" style="padding: 20px;">
        <div id="customerReceiptPrintableArea" class="customer-receipt-sheet">
          <div style="text-align: center; padding: 24px 0;">Loading receipt…</div>
        </div>
        <div class="receipt-modal-actions">
          <button type="button" class="btn-receipt-secondary" disabled data-receipt-print onclick="printCustomerReceipt(false)">
            <i class="bi bi-printer"></i> Print
          </button>
          <button type="button" class="btn-receipt-primary" disabled data-receipt-print onclick="printCustomerReceipt(true)">
            <i class="bi bi-file-earmark-pdf"></i> Save PDF
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal-overlay" id="customerCancelModal">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="customerCancelTitle" style="max-width:480px;">
      <button type="button" class="btn-close-modal" aria-label="Close cancellation" onclick="closeCustomerCancellation()">×</button>
      <div class="modal-body">
        <h2 id="customerCancelTitle">Cancel This Order?</h2>
        <p id="customerCancelDetails" style="white-space:pre-line;margin:16px 0;"></p>
        <p id="customerCancelWarning" class="muted"></p>
        <form id="customerCancelForm" onsubmit="event.preventDefault(); submitCustomerCancellation();">
          <div class="auth-field" style="margin-top:16px;">
            <label for="customerCancelReason">Cancellation reason</label>
            <select id="customerCancelReason" class="auth-input" required onchange="document.getElementById('customerCancelOther').hidden = this.value !== 'Other reason'; document.getElementById('customerCancelOther').required = this.value === 'Other reason';">
              <option value="">Select a reason</option>
              <option>Ordered the wrong items.</option><option>Changed my mind.</option><option>Accidental duplicate order.</option><option>Delivery or pickup concern.</option><option>Other reason</option>
            </select>
          </div>
          <textarea id="customerCancelOther" class="auth-input" aria-label="Explain your reason" placeholder="Please explain (8 to 480 characters)" minlength="8" maxlength="480" hidden></textarea>
          <p id="customerCancelError" role="status" style="color:var(--danger);margin:10px 0;"></p>
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" class="auth-btn-back" onclick="closeCustomerCancellation()">Keep Order</button>
            <button type="submit" id="customerCancelSubmit" class="auth-btn-primary" style="background:#b42318;color:white;" disabled>Confirm Cancellation</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Third-Party Store-Booked Courier Fee Payment Modal -->
  <div class="modal-overlay" id="courierFeeModal">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="courierFeeTitle" style="max-width:480px;">
      <button type="button" class="btn-close-modal" aria-label="Close courier fee payment" onclick="closeCourierFeeModal()">✕</button>
      <div class="modal-body" style="padding:18px 20px;">
        <h2 id="courierFeeTitle" style="margin:0 0 3px;font-size:1.12rem;font-weight:800;color:var(--dark);display:flex;align-items:center;gap:8px;">
          <i class="bi bi-truck" style="color:var(--primary);"></i> Pay Courier Delivery Fee
        </h2>
        <p class="muted" id="courierFeeSubtitle" style="margin:0 0 10px;font-size:0.81rem;">Pay the quoted courier fee via GCash QR and upload your payment receipt below.</p>

        <div class="courier-fee-summary-box">
          <div class="courier-fee-summary-row"><span>Order No.</span><strong id="courierFeeOrderNo">N/A</strong></div>
          <div class="courier-fee-summary-row"><span>Courier Service</span><strong id="courierFeeService">N/A</strong></div>
          <div class="courier-fee-summary-row total"><span>Quoted Delivery Fee</span><strong id="courierFeeAmount">₱0.00</strong></div>
        </div>

        <section class="gcash-payment-panel" aria-label="Pay courier fee via GCash QR code">
          <p class="gcash-payment-title"><i class="bi bi-qr-code-scan"></i> Send courier fee to Kenji’s Kitchen GCash</p>
          <div class="gcash-qr-frame unavailable" id="courierFeeQrFrame">
            <img class="gcash-qr-image" id="courierFeeGcashQr" alt="Kenji's Kitchen GCash payment QR code" loading="eager">
            <div class="gcash-qr-unavailable" role="status">
              <i class="bi bi-qr-code"></i>
              <span>No QR image is set. You can send the courier fee to the GCash number below.</span>
            </div>
          </div>
          <div class="gcash-account-details"><span>Account name</span><strong id="courierFeeGcashName">Loading payment details…</strong><span>Account number</span><strong id="courierFeeGcashPhone">Loading…</strong></div><div class="gcash-account-actions"><a id="courierFeeGcashDownload" hidden download="kenjis-kitchen-gcash.png"><i class="bi bi-download"></i> Save QR image</a><button type="button" onclick="copyGcashNumber('courierFeeGcashPhone', this)"><i class="bi bi-copy"></i> Copy number</button></div><p class="gcash-payment-help">Check the account name in GCash before confirming your payment.</p>
        </section>

        <form id="courierFeeForm" onsubmit="event.preventDefault(); submitCourierFeePayment(); return false;" novalidate>
          <div id="courierFeeError" class="checkout-validation-alert" style="display:none;margin-bottom:10px;" role="alert"></div>

          <div style="margin-bottom:10px;">
            <label for="courierFeeGcashRef" style="font-size:0.83rem;font-weight:700;display:block;margin-bottom:3px;">GCash Reference Number <span style="color:var(--danger);">*</span></label>
            <input type="text" id="courierFeeGcashRef" class="checkout-input" style="letter-spacing:1px;padding:8px 11px;" maxlength="13" inputmode="numeric" placeholder="Enter 13-digit reference" required>
          </div>

          <div style="margin-bottom:12px;">
            <label for="courierFeeProofInput" style="font-size:0.83rem;font-weight:700;display:block;margin-bottom:3px;">Upload Courier Fee Receipt <span style="color:var(--danger);">*</span></label>
            <input type="file" id="courierFeeProofInput" class="checkout-input" accept="image/jpeg,image/png,image/webp" onchange="handleCourierFeeProofSelection(event)" style="padding:6px 8px;">
            <small style="color:var(--muted);font-size:0.73rem;display:block;margin-top:2px;">JPG, PNG, or WebP screenshot (max 5 MB).</small>
            <div id="courierFeeProofPreviewWrap" style="display:none;margin-top:8px;padding:8px;border:1px solid var(--border);border-radius:10px;background:var(--soft);">
              <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px;">
                <span id="courierFeeProofFileName" style="font-size:0.78rem;font-weight:700;color:var(--dark);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
                <button type="button" onclick="clearCourierFeeProofSelection()" style="background:rgba(217,83,79,0.12);color:#ef4444;border:1px solid rgba(217,83,79,0.35);border-radius:6px;padding:3px 9px;font-size:0.75rem;font-weight:700;cursor:pointer;flex-shrink:0;">
                  <i class="bi bi-trash"></i> Remove
                </button>
              </div>
              <img id="courierFeeProofPreviewImg" src="" alt="Courier fee receipt preview" style="max-height:110px;max-width:100%;border-radius:8px;display:block;margin:0 auto;object-fit:contain;border:1px solid var(--border);">
            </div>
          </div>

          <div style="display:flex;gap:10px;">
            <button type="button" class="auth-btn-back" style="flex:0.8;height:auto;padding:10px;" onclick="closeCourierFeeModal()">Cancel</button>
            <button type="submit" id="btnSubmitCourierFee" class="btn-primary" style="flex:1.4;padding:10px;">Submit Fee Payment</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Interactive "About Kenji's Kitchen" Modal (Option 2) -->
  <div class="modal-overlay" id="aboutKenjiModal" onclick="if(event.target===this) closeAboutModal();">
    <div class="modal-card about-modal-card" style="max-width: 600px; padding: 0; overflow: hidden; border-radius: 20px;">
      <div style="background: var(--primary, #F2C12E); padding: 24px; position: relative; text-align: center;">
        <button type="button" class="btn-close-modal" aria-label="Close About" title="Close" onclick="closeAboutModal()" style="top: 16px; right: 16px; background: rgba(0,0,0,0.15); color: #000; border: none; width: 34px; height: 34px; border-radius: 50%; font-size: 1.2rem; cursor: pointer;">×</button>
        <img src="../assets/img/logo.png" alt="Kenji's Kitchen" style="width: 64px; height: 64px; border-radius: 12px; object-fit: cover; box-shadow: 0 4px 14px rgba(0,0,0,0.18); margin-bottom: 8px;">
        <h2 style="margin: 0; font-family: 'Playfair Display', Georgia, serif; font-size: 1.7rem; font-weight: 800; color: #1f2328;">Kenji's Kitchen</h2>
        <span style="font-size: 0.8rem; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: #4b3603;">Authentic Flavors · Fairview</span>
      </div>

      <div class="modal-body" style="padding: 24px 28px;">
        <div style="display: inline-block; background: rgba(242, 193, 46, 0.16); color: #92400e; font-size: 0.72rem; font-weight: 800; letter-spacing: 1.5px; padding: 4px 12px; border-radius: 999px; margin-bottom: 12px;">OUR STORY &amp; MISSION</div>
        <h3 style="margin: 0 0 10px; font-size: 1.25rem; font-weight: 800; color: var(--dark);">Lutong Bahay Sarap, Crafted with Passion</h3>
        <p style="font-size: 0.92rem; line-height: 1.6; color: var(--text); margin-bottom: 20px;">
          Located in Barangay Greater Lagro, Quezon City, <strong>Kenji’s Kitchen</strong> is your neighborhood home for authentic Filipino comfort meals. From our signature crispy silogs cooked fresh to order, savory simmered beef and bagnet pares, to generous party bilao trays designed for family celebrations, we take pride in serving high-quality ingredients with genuine hospitality.
        </p>

        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 24px; text-align: center;">
          <div style="background: var(--soft, #f8f9fa); padding: 14px 10px; border-radius: 12px; border: 1px solid var(--border);">
            <i class="bi bi-fire" style="font-size: 1.4rem; color: #ea580c;"></i>
            <div style="font-weight: 800; font-size: 0.85rem; margin-top: 4px; color: var(--dark);">Fresh Daily</div>
            <div style="font-size: 0.72rem; color: var(--muted); margin-top: 2px;">Cooked to Order</div>
          </div>
          <div style="background: var(--soft, #f8f9fa); padding: 14px 10px; border-radius: 12px; border: 1px solid var(--border);">
            <i class="bi bi-award-fill" style="font-size: 1.4rem; color: #d97706;"></i>
            <div style="font-weight: 800; font-size: 0.85rem; margin-top: 4px; color: var(--dark);">Quality Cuts</div>
            <div style="font-size: 0.72rem; color: var(--muted); margin-top: 2px;">Garlic Sinangag</div>
          </div>
          <div style="background: var(--soft, #f8f9fa); padding: 14px 10px; border-radius: 12px; border: 1px solid var(--border);">
            <i class="bi bi-people-fill" style="font-size: 1.4rem; color: #10b981;"></i>
            <div style="font-weight: 800; font-size: 0.85rem; margin-top: 4px; color: var(--dark);">For Sharing</div>
            <div style="font-size: 0.72rem; color: var(--muted); margin-top: 2px;">Solo to Trays</div>
          </div>
        </div>

        <div style="border-top: 1px dashed var(--border); padding-top: 16px; display: flex; flex-direction: column; gap: 8px; font-size: 0.84rem; color: var(--muted);">
          <div><i class="bi bi-geo-alt-fill" style="color:#ef4444;"></i> <strong>Location:</strong> Blk 79 Lot 34 Florante at Laura St. Brgy. Greater Lagro, Quezon City</div>
          <div><i class="bi bi-clock-fill" style="color:var(--primary);"></i> <strong>Hours:</strong> Mon to Sat: 9:00 AM to 3:00 AM (next day) | <span style="color:#ef4444;font-weight:700;">Sunday: CLOSED</span></div>
          <div><i class="bi bi-check2-circle" style="color:#10b981;"></i> <strong>Services:</strong> Dine-In · Takeout · Pickup · Delivery</div>
        </div>
      </div>
    </div>
  </div>

  <script src="../assets/js/order-sounds.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/js/order-sounds.js') ?: '20261118' ?>"></script>
  <script src="../assets/vendor/leaflet/leaflet.js?v=1.9.4"></script>
  <script src="../assets/js/checkout-retry.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/js/checkout-retry.js') ?: '20261118' ?>"></script>
  <script src="../assets/js/stock-qty.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/js/stock-qty.js') ?: '20261118' ?>"></script>
  <script src="../assets/js/ol.app.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/ol.app.js') ?: '20261118' ?>"></script>
  <script>
    document.getElementById('recoveryResetForm').addEventListener('reset', function () {
      this.querySelectorAll('.pw-wrap').forEach(function (wrap) {
        var input = wrap.querySelector('input');
        if (input.type === 'text') togglePwVisibility(input.id, wrap.querySelector('.pw-eye-btn'));
      });
    });

    function togglePwVisibility(inputId, btn) {
      var inp = document.getElementById(inputId);
      if (!inp) return;
      var eyeOn  = btn.querySelector('.eye-icon');
      var eyeOff = btn.querySelector('.eye-off-icon');
      if (inp.type === 'password') {
        inp.type = 'text';
        if (eyeOn) eyeOn.style.display  = 'none';
        if (eyeOff) eyeOff.style.display = '';
        btn.setAttribute('aria-label', 'Hide password');
      } else {
        inp.type = 'password';
        if (eyeOn) eyeOn.style.display  = '';
        if (eyeOff) eyeOff.style.display = 'none';
        btn.setAttribute('aria-label', 'Show password');
      }
    }

    function openAboutModal() {
      var modal = document.getElementById('aboutKenjiModal');
      if (modal) modal.classList.add('active');
    }

    function closeAboutModal() {
      var modal = document.getElementById('aboutKenjiModal');
      if (modal) modal.classList.remove('active');
    }

    // Secret Staff Portal Easter Egg: Click logo exactly 5 times quickly
    (function initSecretStaffAccess() {
      var logo = document.getElementById('secretStaffLogo');
      var link = document.getElementById('brandLogoLink');
      if (!logo) return;
      
      var clickCount = 0;
      var timer = null;

      function handleLogoClick(e) {
        e.preventDefault();
        e.stopPropagation();
        clickCount++;
        if (timer) clearTimeout(timer);

        logo.style.transition = 'transform 0.15s ease';

        if (clickCount >= 5) {
          clickCount = 0;
          logo.style.transform = '';

          var staffToast = null;
          if (typeof window.kkNotify === 'function') {
            staffToast = window.kkNotify('Opening Staff Portal in a new tab...', 'info', {
              title: 'Staff Access',
              dedupeKey: 'staff-portal-open',
              duration: 1200
            });
          } else {
            var toast = document.getElementById('toastBox');
            if (toast) {
              toast.textContent = 'Opening Staff Portal in a new tab...';
              toast.classList.add('show');
              setTimeout(function() { toast.classList.remove('show'); }, 1200);
            }
          }

          function dismissStaffToast() {
            if (staffToast && typeof staffToast.dismiss === 'function') {
              staffToast.dismiss();
              staffToast = null;
            }
            var fallbackToast = document.getElementById('toastBox');
            if (fallbackToast) fallbackToast.classList.remove('show');
            document.removeEventListener('visibilitychange', onVisibilityHide);
            window.removeEventListener('focus', onWindowReturn);
          }
          function onVisibilityHide() {
            if (document.hidden) {
              dismissStaffToast();
            }
          }
          function onWindowReturn() {
            dismissStaffToast();
          }
          document.addEventListener('visibilitychange', onVisibilityHide);
          setTimeout(function() {
            window.addEventListener('focus', onWindowReturn, { once: true });
          }, 150);
          setTimeout(dismissStaffToast, 1300);

          var staffTab = window.open('../login.php?staff=1', '_blank');
          if (staffTab) {
            try { staffTab.opener = null; } catch (_) {}
          } else {
            var a = document.createElement('a');
            a.href = '../login.php?staff=1';
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            document.body.appendChild(a);
            a.click();
            a.remove();
          }
          return;
        }

        // Visual subtle tap bounce while counting clicks
        logo.style.transform = 'scale(' + (1 + clickCount * 0.05) + ') rotate(' + (clickCount * 4) + 'deg)';

        timer = setTimeout(function() {
          // If clicked only once, follow regular link behavior (refresh / home)
          if (clickCount === 1) {
            window.location.href = '../index.php';
          }
          clickCount = 0;
          logo.style.transform = '';
        }, 1500);
      }

      logo.addEventListener('click', handleLogoClick);
      if (link) {
        link.addEventListener('click', function(e) {
          e.preventDefault();
        });
      }
    })();
  </script>

  <!-- Mobile Navigation Toggle -->
  <script>
    function toggleMobileNav() {
      const dropdown = document.getElementById('mobNavDropdown');
      const btn      = document.getElementById('mobMenuBtn');
      const icon     = document.getElementById('mobMenuIcon');
      const isOpen   = dropdown.classList.toggle('open');
      btn.setAttribute('aria-expanded', isOpen);
      icon.className = isOpen ? 'bi bi-x-lg' : 'bi bi-list';
    }

    function closeMobileNav() {
      const dropdown = document.getElementById('mobNavDropdown');
      const btn      = document.getElementById('mobMenuBtn');
      const icon     = document.getElementById('mobMenuIcon');
      dropdown.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      icon.className = 'bi bi-list';
    }

    // Close mobile nav when clicking outside
    document.addEventListener('click', function(e) {
      const dropdown = document.getElementById('mobNavDropdown');
      const btn      = document.getElementById('mobMenuBtn');
      if (dropdown && dropdown.classList.contains('open')) {
        if (!dropdown.contains(e.target) && !btn.contains(e.target)) {
          closeMobileNav();
        }
      }
    });

    // Close mobile nav on window resize to desktop
    window.addEventListener('resize', function() {
      if (window.innerWidth >= 1024) {
        closeMobileNav();
      }
    });
  </script>
<script src="../assets/js/storefront.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/js/storefront.js') ?: '20261118' ?>"></script>
</body>
</html>
