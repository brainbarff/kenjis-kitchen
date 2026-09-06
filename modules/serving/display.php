<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Now Serving - Kenji's Kitchen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Serif:wght@400;500;600;700&family=Old+Standard+TT:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="display-page">
    <main class="display-board">
        <header class="display-head">
            <div>
                <span class="brand-mark">K</span>
                <h1>Now Serving</h1>
                <p>Kenji's Kitchen</p>
            </div>
            <strong id="displayClock"></strong>
        </header>

        <section class="display-columns">
            <article>
                <h2>Ready for Pickup</h2>
                <div class="display-list" id="pickupDisplay"></div>
            </article>
            <article>
                <h2>Dine-In Ready</h2>
                <div class="display-list" id="dineDisplay"></div>
            </article>
        </section>
    </main>
    <script src="<?= BASE_URL ?>/assets/js/serving-display.js"></script>
</body>
</html>
