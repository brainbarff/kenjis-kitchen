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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            width: 100%;
            min-height: 100%;
            background: #ffffff;
        }

        body.display-page {
            min-height: 100vh;
            background: #ffffff;
            color: #111111;
            font-family: Arial, Helvetica, sans-serif;
        }

        .display-board {
            min-height: 100vh;
            box-sizing: border-box;
            padding: 24px;
            background: #ffffff;
        }

        .display-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding-bottom: 18px;
            border-bottom: 1px solid #e5e7eb;
        }

        .display-brand {
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .display-brand-mark {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: #F2C12E;
            color: #111111;
            font-size: 1.35rem;
            font-weight: 900;
        }

        .display-head h1 {
            margin: 0;
            color: #111111;
            font-size: clamp(1.9rem, 3vw, 3rem);
            line-height: 1;
            font-weight: 900;
        }

        .display-head p {
            margin: 4px 0 0;
            color: #6b7280;
            font-size: 0.82rem;
            font-weight: 600;
        }

        .display-clock {
            color: #111111;
            font-size: clamp(1.35rem, 2vw, 2rem);
            font-weight: 800;
            white-space: nowrap;
        }

        .display-summary {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 18px;
        }

        .display-column {
            min-width: 0;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #ffffff;
            overflow: hidden;
        }

        .display-column-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 17px;
            border-bottom: 1px solid #e5e7eb;
            background: #f8fafc;
        }

        .display-column-head h2 {
            margin: 0;
            color: #111111;
            font-size: 1rem;
            font-weight: 900;
        }

        .display-column-count {
            min-width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 7px;
            background: #F2C12E;
            color: #111111;
            font-size: 0.72rem;
            font-weight: 900;
        }

        .display-list {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            padding: 12px;
        }

        .display-order {
            min-height: 138px;
            padding: 13px;
            box-sizing: border-box;
            border: 1px solid #e5e7eb;
            border-radius: 11px;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            text-align: center;
        }

        .display-order > div:first-child {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            text-align: center;
        }

        .display-order-queue {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 62px;
            height: 40px;
            margin: 0 auto;
            padding: 0 9px;
            border-radius: 9px;
            background: #F2C12E;
            color: #111111;
            font-size: 1.25rem;
            font-weight: 900;
        }

        .display-order-destination {
            width: 100%;
            margin-top: 9px;
            color: #111111;
            font-size: clamp(1.2rem, 1.9vw, 1.9rem);
            line-height: 1;
            font-weight: 900;
            text-align: center;
            letter-spacing: 0.2px;
        }

        .display-order-type {
            margin-top: 5px;
            color: #6b7280;
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            text-align: center;
        }


        .display-online {
            display: inline-flex;
            width: fit-content;
            margin-top: 7px;
            padding: 3px 6px;
            border-radius: 5px;
            background: #F2C12E;
            color: #111111;
            font-size: 0.56rem;
            font-weight: 900;
        }

        .display-empty {
            grid-column: 1 / -1;
            min-height: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 18px;
            text-align: center;
            color: #777777;
            font-size: 0.8rem;
        }

        @media (max-width: 1100px) {
            .display-list {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 850px) {
            .display-summary {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 600px) {
            .display-board {
                padding: 14px;
            }

            .display-head {
                align-items: flex-start;
            }

            .display-list {
                grid-template-columns: 1fr;
            }

            .display-order {
                min-height: 125px;
            }
        }
    </style>
</head>

<body class="display-page">
    <main class="display-board">
        <header class="display-head">
            <div class="display-brand">
                <div class="display-brand-mark">K</div>

                <div>
                    <h1>Now Serving</h1>
                    <p>Kenji's Kitchen</p>
                </div>
            </div>

            <strong
                class="display-clock"
                id="displayClock"
            ></strong>
        </header>

        <section class="display-summary">
            <article class="display-column">
                <div class="display-column-head">
                    <h2>Dine-In Ready</h2>

                    <span
                        class="display-column-count"
                        id="dineCount"
                    >
                        0
                    </span>
                </div>

                <div
                    class="display-list"
                    id="dineDisplay"
                ></div>
            </article>

            <article class="display-column">
                <div class="display-column-head">
                    <h2>Pickup Ready</h2>

                    <span
                        class="display-column-count"
                        id="pickupCount"
                    >
                        0
                    </span>
                </div>

                <div
                    class="display-list"
                    id="pickupDisplay"
                ></div>
            </article>
        </section>
    </main>

    <script src="<?= BASE_URL ?>/assets/js/serving-display.js?v=<?= time() ?>"></script>
</body>
</html>