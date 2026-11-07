<?php
/**
 * JSON endpoint feeding the dashboard.
 * GET ?from=YYYY-MM-DD&to=YYYY-MM-DD   (both optional)
 */
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_login(true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function valid_date(?string $d): ?string
{
    if ($d === null || $d === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}

function rows(PDO $pdo, string $sql, array $params = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

try {
    $from = valid_date($_GET['from'] ?? null);
    $to   = valid_date($_GET['to'] ?? null);
    if ($from !== null && $to !== null && $from > $to) {
        [$from, $to] = [$to, $from];
    }

    $conds  = [];
    $params = [];
    if ($from !== null) { $conds[] = '`date` >= ?'; $params[] = $from; }
    if ($to   !== null) { $conds[] = '`date` <= ?'; $params[] = $to; }
    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

    $pdo = db();

    // ---- KPI totals -------------------------------------------------------
    $tot = rows($pdo, "SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS r, MIN(`date`) AS d0, MAX(`date`) AS d1
                       FROM sales_data $where", $params)[0];

    // Users: cumulative customers registered up to the end of the range
    $uSql = 'SELECT COUNT(*) AS c FROM customers';
    $uPar = [];
    if ($to !== null) {
        $uSql .= ' WHERE DATE(created_at) <= ?';
        $uPar[] = $to;
    }
    $totalUsers = (int) rows($pdo, $uSql, $uPar)[0]['c'];

    // ---- Monthly sales (bar) ---------------------------------------------
    $monthly = rows($pdo, "SELECT DATE_FORMAT(`date`, '%Y-%m') AS ym, COUNT(*) AS sales, SUM(amount) AS revenue
                           FROM sales_data $where GROUP BY ym ORDER BY ym", $params);
    $monthly = array_map(fn($r) => [
        'month'   => $r['ym'],
        'sales'   => (int) $r['sales'],
        'revenue' => (float) $r['revenue'],
    ], $monthly);

    // ---- Growth: latest month vs previous month in range ------------------
    // Only COMPLETE months are compared, otherwise a half-finished month would show a fake drop.
    $growth = null;
    $growthLabel = 'needs 2 complete months';
    $rangeStart = $from;                                   // null = start of the data
    $rangeEnd   = $to ?? $tot['d1'];                       // null = no data at all
    $full = array_values(array_filter($monthly, function ($m) use ($rangeStart, $rangeEnd) {
        $first = $m['month'] . '-01';
        $lastD = date('Y-m-t', strtotime($first));
        return ($rangeStart === null || $first >= $rangeStart)
            && ($rangeEnd !== null && $lastD <= $rangeEnd);
    }));
    $n = count($full);
    if ($n >= 2) {
        $last = $full[$n - 1];
        $prev = $full[$n - 2];
        if ($prev['revenue'] > 0) {
            $growth = round(($last['revenue'] - $prev['revenue']) / $prev['revenue'] * 100, 1);
            $m1 = DateTime::createFromFormat('!Y-m', $last['month'])->format('M Y');
            $m0 = DateTime::createFromFormat('!Y-m', $prev['month'])->format('M Y');
            $growthLabel = "$m1 vs $m0";
        }
    }

    // ---- Category split (pie) --------------------------------------------
    $categories = rows($pdo, "SELECT category, SUM(amount) AS revenue, COUNT(*) AS orders
                              FROM sales_data $where GROUP BY category ORDER BY revenue DESC", $params);
    $categories = array_map(fn($r) => [
        'category' => $r['category'],
        'revenue'  => (float) $r['revenue'],
        'orders'   => (int) $r['orders'],
    ], $categories);

    // ---- Revenue trend (line): day / week / month depending on span ------
    $granularity = 'day';
    if ($tot['d0'] !== null) {
        $span = (int) ((new DateTime($tot['d1']))->diff(new DateTime($tot['d0']))->days);
        $granularity = $span <= 62 ? 'day' : ($span <= 300 ? 'week' : 'month');
    }
    if ($granularity === 'day') {
        $periodExpr = '`date`';
    } elseif ($granularity === 'week') {
        $periodExpr = 'DATE_SUB(`date`, INTERVAL WEEKDAY(`date`) DAY)';
    } else {
        $periodExpr = "DATE_FORMAT(`date`, '%Y-%m-01')";
    }
    $trend = rows($pdo, "SELECT $periodExpr AS period, SUM(amount) AS revenue
                         FROM sales_data $where GROUP BY period ORDER BY period", $params);
    $trend = array_map(fn($r) => [
        'period'  => $r['period'],
        'revenue' => (float) $r['revenue'],
    ], $trend);

    // ---- Top products (table) --------------------------------------------
    $top = rows($pdo, "SELECT product, category, COUNT(*) AS orders, SUM(amount) AS revenue
                       FROM sales_data $where GROUP BY product, category
                       ORDER BY revenue DESC LIMIT 10", $params);
    $revenueTotal = (float) $tot['r'];
    $top = array_map(fn($r) => [
        'product'  => $r['product'],
        'category' => $r['category'],
        'orders'   => (int) $r['orders'],
        'revenue'  => (float) $r['revenue'],
        'share'    => $revenueTotal > 0 ? round((float) $r['revenue'] / $revenueTotal * 100, 1) : 0,
    ], $top);

    // ---- Overall data bounds (for the date pickers / presets) ------------
    $bounds = rows($pdo, 'SELECT MIN(`date`) AS d0, MAX(`date`) AS d1 FROM sales_data')[0];

    echo json_encode([
        'filter' => ['from' => $from, 'to' => $to],
        'bounds' => ['min' => $bounds['d0'], 'max' => $bounds['d1']],
        'cards'  => [
            'total_sales'  => (int) $tot['c'],
            'total_users'  => $totalUsers,
            'revenue'      => $revenueTotal,
            'growth'       => $growth,
            'growth_label' => $growthLabel,
        ],
        'monthly'     => $monthly,
        'categories'  => $categories,
        'trend'       => ['granularity' => $granularity, 'points' => $trend],
        'top_products' => $top,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $ex) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not load analytics. ' . db_hint($ex)]);
}
