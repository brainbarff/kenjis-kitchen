/**
 * secret-logo.js
 * Hidden staff access triggered by rapid clicks on designated logos.
 * 
 * Requirements:
 * - Listens on the whole document (delegated event listener; supports plain HTML & React).
 * - Reads `data-secret-logo="<login url>"` on the clicked element or its ancestors.
 * - Reads `data-clicks` (allowed range 3-6, default fallback: 5).
 * - Rapid clicks within 4 seconds window trigger navigation to the specified staff login URL.
 * - Single click does nothing special; default link behaviors remain intact.
 * - Keeps click counter and timer state in sessionStorage.
 */

(function () {
  'use strict';

  var WINDOW_MS = 4000; // 4 seconds time window
  var STORAGE_COUNT_KEY = 'kk_secret_logo_clicks';
  var STORAGE_TIME_KEY = 'kk_secret_logo_start_time';

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-secret-logo]');
    if (!trigger) {
      return;
    }

    var targetUrl = trigger.getAttribute('data-secret-logo');
    if (!targetUrl) {
      return;
    }

    // Determine target clicks required (3 to 6; default: 5)
    var rawClicks = parseInt(trigger.getAttribute('data-clicks'), 10);
    var requiredClicks = (rawClicks >= 3 && rawClicks <= 6) ? rawClicks : 5;

    var now = Date.now();
    var firstClickTime = parseInt(sessionStorage.getItem(STORAGE_TIME_KEY) || '0', 10);
    var currentCount = parseInt(sessionStorage.getItem(STORAGE_COUNT_KEY) || '0', 10);

    // If outside the 4-second window or invalid, restart count
    if (!firstClickTime || (now - firstClickTime) > WINDOW_MS) {
      currentCount = 1;
      firstClickTime = now;
    } else {
      currentCount += 1;
    }

    sessionStorage.setItem(STORAGE_COUNT_KEY, String(currentCount));
    sessionStorage.setItem(STORAGE_TIME_KEY, String(firstClickTime));

    if (currentCount >= requiredClicks) {
      // Clear counter so subsequent clicks start fresh
      sessionStorage.removeItem(STORAGE_COUNT_KEY);
      sessionStorage.removeItem(STORAGE_TIME_KEY);

      // Prevent default navigation (e.g. going to index.php) and redirect to staff login
      event.preventDefault();
      event.stopPropagation();
      window.location.href = targetUrl;
    }
  }, true);
})();
