<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

$base   = '';
$active = 'dashboard';
$title  = 'Dashboard';
$extraScripts = ['assets/vendor/chart.umd.js', 'assets/js/dashboard.js'];

include __DIR__ . '/partials/header.php';
?>
<script>window.APP = { currency: <?= json_encode(CURRENCY) ?>, api: 'api/data.php' };</script>

<!-- Filters -->
<form class="toolbar" id="filterForm" autocomplete="off">
    <div class="toolbar-fields">
        <label class="field inline">
            <span>From</span>
            <input type="date" id="fromDate" name="from">
        </label>
        <label class="field inline">
            <span>To</span>
            <input type="date" id="toDate" name="to">
        </label>
        <button type="submit" class="btn btn-primary">Apply</button>
        <button type="button" class="btn btn-ghost" id="resetBtn">Reset</button>
    </div>
    <div class="segmented" id="presets" role="group" aria-label="Quick ranges (relative to latest data)">
        <button type="button" data-range="all" class="active">All time</button>
        <button type="button" data-range="30">Last 30 days</button>
        <button type="button" data-range="90">Last 90 days</button>
        <button type="button" data-range="ytd">Year to date</button>
    </div>
</form>

<div class="alert alert-error" id="errorBox" hidden></div>

<!-- KPI cards -->
<section class="cards" id="cards">
    <article class="card kpi">
        <div class="kpi-icon blue"><?= icon('cart') ?></div>
        <div class="kpi-body">
            <span class="kpi-label">Total Sales</span>
            <strong class="kpi-value" id="kpiSales">—</strong>
            <span class="kpi-sub">orders in period</span>
        </div>
    </article>
    <article class="card kpi">
        <div class="kpi-icon teal"><?= icon('users') ?></div>
        <div class="kpi-body">
            <span class="kpi-label">Total Users</span>
            <strong class="kpi-value" id="kpiUsers">—</strong>
            <span class="kpi-sub">registered customers</span>
        </div>
    </article>
    <article class="card kpi">
        <div class="kpi-icon amber"><?= icon('dollar') ?></div>
        <div class="kpi-body">
            <span class="kpi-label">Revenue</span>
            <strong class="kpi-value" id="kpiRevenue">—</strong>
            <span class="kpi-sub">sum of sales amount</span>
        </div>
    </article>
    <article class="card kpi">
        <div class="kpi-icon green" id="kpiGrowthIcon"><?= icon('trend') ?></div>
        <div class="kpi-body">
            <span class="kpi-label">Growth</span>
            <strong class="kpi-value" id="kpiGrowth">—</strong>
            <span class="kpi-sub" id="kpiGrowthSub">month over month</span>
        </div>
    </article>
</section>

<div class="empty-state card" id="emptyState" hidden>
    <h3>No sales data for this period</h3>
    <p class="muted">Upload a CSV file or widen the date range.</p>
    <a class="btn btn-primary" href="upload/">Upload CSV</a>
</div>

<!-- Charts -->
<section class="grid" id="charts">
    <article class="card panel span-2">
        <header class="panel-head">
            <h2>Monthly Sales</h2>
            <span class="muted small">Orders per month</span>
        </header>
        <div class="chart-box"><canvas id="barChart"></canvas></div>
    </article>

    <article class="card panel">
        <header class="panel-head">
            <h2>Sales by Category</h2>
            <span class="muted small">Share of revenue</span>
        </header>
        <div class="chart-box"><canvas id="pieChart"></canvas></div>
    </article>

    <article class="card panel span-3">
        <header class="panel-head">
            <h2>Revenue Trend</h2>
            <span class="muted small" id="trendNote"></span>
        </header>
        <div class="chart-box"><canvas id="lineChart"></canvas></div>
    </article>

    <article class="card panel span-3">
        <header class="panel-head">
            <h2>Top Products</h2>
            <span class="muted small">By revenue</span>
        </header>
        <div class="table-wrap">
            <table class="table" id="topTable">
                <thead>
                    <tr><th>#</th><th>Product</th><th class="num">Orders</th><th class="num">Revenue</th></tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </article>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>
