<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/db.php';
require_role(['admin', 'manager']);

$page = 'dashboard';
$title = 'Dashboard';
$heading = 'Dashboard';

// KPI Cards
$sales = $conn->query("SELECT COALESCE(SUM(total),0) total FROM orders WHERE DATE(created_at) = CURDATE() AND status <> 'Cancelled'")->fetch();
$orders = $conn->query("SELECT COUNT(*) total FROM orders WHERE DATE(created_at) = CURDATE()")->fetch();
$low = $conn->query("SELECT COUNT(*) total FROM inventory WHERE current_stock <= low_stock")->fetch();
$ready = $conn->query("SELECT COUNT(*) total FROM orders WHERE status = 'Ready'")->fetch();

// 1. 7-Day Trend
$trend_7d = $conn->query("
    SELECT DATE(created_at) AS d, COALESCE(SUM(total), 0) AS total
    FROM orders
    WHERE status <> 'Cancelled' AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
")->fetchAll(PDO::FETCH_KEY_PAIR);

$labels_7d = [];
$data_7d = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $labels_7d[] = date('M d', strtotime($date));
    $data_7d[] = (float)($trend_7d[$date] ?? 0);
}

// 2. 30-Day Trend
$trend_30d = $conn->query("
    SELECT DATE(created_at) AS d, COALESCE(SUM(total), 0) AS total
    FROM orders
    WHERE status <> 'Cancelled' AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(created_at)
")->fetchAll(PDO::FETCH_KEY_PAIR);

$labels_30d = [];
$data_30d = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $labels_30d[] = date('M d', strtotime($date));
    $data_30d[] = (float)($trend_30d[$date] ?? 0);
}

// 3. This Year (Monthly Breakdown)
$trend_year = $conn->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COALESCE(SUM(total), 0) AS total
    FROM orders
    WHERE status <> 'Cancelled' AND YEAR(created_at) = YEAR(CURDATE())
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
")->fetchAll(PDO::FETCH_KEY_PAIR);

$labels_year = [];
$data_year = [];
$current_year = date('Y');
for ($m = 1; $m <= 12; $m++) {
    $month_key = sprintf('%s-%02d', $current_year, $m);
    $labels_year[] = date('M', mktime(0, 0, 0, $m, 1));
    $data_year[] = (float)($trend_year[$month_key] ?? 0);
}

// Recent Orders
$recent = $conn->query("
    SELECT order_no, order_type, total, status, created_at
    FROM orders
    ORDER BY created_at DESC
    LIMIT 8
")->fetchAll();

include ROOT_PATH . '/includes/header.php';
?>
<section class="grid grid-4">
    <article class="card stat"><div><span class="muted">Today's Sales</span><strong><?= money($sales['total']) ?></strong></div><i class="bi bi-cash-stack"></i></article>
    <article class="card stat"><div><span class="muted">Today's Orders</span><strong><?= e($orders['total']) ?></strong></div><i class="bi bi-bag-check"></i></article>
    <article class="card stat"><div><span class="muted">Low Stock</span><strong><?= e($low['total']) ?></strong></div><i class="bi bi-exclamation-triangle"></i></article>
    <article class="card stat"><div><span class="muted">Ready Orders</span><strong><?= e($ready['total']) ?></strong></div><i class="bi bi-bell"></i></article>
</section>

<!-- Visual Analytics Card with Range Filter -->
<section class="card" style="margin-top:24px">
    <div class="page-head" style="margin-bottom:16px; flex-wrap:wrap; gap:12px;">
        <div>
            <h2>Sales Overview</h2>
            <p class="muted" id="chartSubtitle">Daily revenue performance over the last 7 days.</p>
        </div>
        <div style="display:flex; gap:6px; background:var(--soft); padding:4px; border-radius:12px;">
            <button type="button" class="btn chart-filter active" data-range="7d" style="min-height:36px; padding:6px 14px; font-size:13px;">7 Days</button>
            <button type="button" class="btn chart-filter" data-range="30d" style="min-height:36px; padding:6px 14px; font-size:13px;">30 Days</button>
            <button type="button" class="btn chart-filter" data-range="year" style="min-height:36px; padding:6px 14px; font-size:13px;">This Year</button>
        </div>
    </div>
    <div style="height:280px; position:relative;">
        <canvas id="salesChart"></canvas>
    </div>
</section>

<section class="card" style="margin-top:24px">
    <div class="page-head">
        <div>
            <h2>Recent Orders</h2>
            <p class="muted">Latest transactions from POS and online ordering.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Order No.</th><th>Type</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
                <?php foreach ($recent as $row): ?>
                <?php
                    $status = $row['status'] ?: 'Pending';
                    $badgeClass = match($status) {
                        'Served', 'Completed' => 'badge-success',
                        'Ready' => 'badge-primary',
                        'Preparing' => 'badge-info',
                        'Cancelled' => 'badge-danger',
                        default => 'badge-warning',
                    };
                ?>
                <tr>
                    <td><?= e($row['order_no']) ?></td>
                    <td><?= e($row['order_type']) ?></td>
                    <td><?= money($row['total']) ?></td>
                    <td><span class="badge <?= $badgeClass ?>"><?= e($status) ?></span></td>
                    <td><?= e(date('M d, Y h:i A', strtotime($row['created_at']))) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const el = document.getElementById('salesChart');
    if (!el) return;

    const datasets = {
        '7d': {
            labels: <?= json_encode($labels_7d) ?>,
            data: <?= json_encode($data_7d) ?>,
            subtitle: 'Daily revenue performance over the last 7 days.'
        },
        '30d': {
            labels: <?= json_encode($labels_30d) ?>,
            data: <?= json_encode($data_30d) ?>,
            subtitle: 'Daily revenue performance over the last 30 days.'
        },
        'year': {
            labels: <?= json_encode($labels_year) ?>,
            data: <?= json_encode($data_year) ?>,
            subtitle: 'Monthly revenue performance for <?= $current_year ?>.'
        }
    };

    const ctx = el.getContext('2d');
    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: datasets['7d'].labels,
            datasets: [{
                label: 'Revenue',
                data: datasets['7d'].data,
                borderColor: '#F2C12E',
                backgroundColor: 'rgba(242, 193, 46, 0.12)',
                borderWidth: 3,
                fill: true,
                tension: 0.35,
                pointBackgroundColor: '#232323',
                pointBorderColor: '#F2C12E',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return ' Sales: ₱' + Number(context.parsed.y).toLocaleString('en-US', {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            });
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(val) { return '₱' + Number(val).toLocaleString(); },
                        font: { family: 'Inter' }
                    },
                    grid: { color: '#EAEAEA' }
                },
                x: {
                    ticks: { font: { family: 'Inter' } },
                    grid: { display: false }
                }
            }
        }
    });

    // Handle filter buttons
    const filterButtons = document.querySelectorAll('.chart-filter');
    const subtitle = document.getElementById('chartSubtitle');

    filterButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            filterButtons.forEach(b => {
                b.classList.remove('active');
                b.style.background = 'transparent';
                b.style.color = 'var(--text)';
            });

            this.classList.add('active');
            this.style.background = 'var(--primary)';
            this.style.color = 'var(--dark)';

            const range = this.getAttribute('data-range');
            const target = datasets[range];

            chart.data.labels = target.labels;
            chart.data.datasets[0].data = target.data;
            chart.update();

            subtitle.textContent = target.subtitle;
        });
    });

    // Initialize first button active state styling
    const activeBtn = document.querySelector('.chart-filter.active');
    if (activeBtn) {
        activeBtn.style.background = 'var(--primary)';
        activeBtn.style.color = 'var(--dark)';
    }
});
</script>

<?php include ROOT_PATH . '/includes/footer.php'; ?>