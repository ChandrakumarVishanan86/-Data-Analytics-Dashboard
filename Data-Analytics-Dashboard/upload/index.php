<?php
/**
 * CSV upload page + importer.
 * Expected columns (header row required): product, category, amount, date, region
 * An optional "id" column is ignored; "region" is optional.
 */
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_login(false, '../index.php');

/** Normalise a header cell to one of our column keys (or null if unknown). */
function map_header(string $h): ?string
{
    $h = strtolower(trim($h));
    $h = preg_replace('/[^a-z0-9]+/', '_', $h);
    $h = trim((string) $h, '_');
    static $aliases = [
        'product' => 'product', 'product_name' => 'product', 'item' => 'product',
        'category' => 'category', 'product_category' => 'category',
        'amount' => 'amount', 'sales' => 'amount', 'sales_amount' => 'amount', 'total' => 'amount', 'revenue' => 'amount',
        'date' => 'date', 'order_date' => 'date', 'sale_date' => 'date', 'sales_date' => 'date',
        'region' => 'region', 'area' => 'region',
    ];
    return $aliases[$h] ?? null;
}

/** Parse a date string into Y-m-d or null. Accepts YYYY-MM-DD, YYYY/MM/DD, DD/MM/YYYY, DD-MM-YYYY, DD.MM.YYYY, MM/DD/YYYY. */
function parse_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $raw = preg_split('/[ T]/', $raw)[0]; // drop any time part
    foreach (['!Y-m-d', '!Y/m/d', '!d/m/Y', '!d-m-Y', '!d.m.Y', '!m/d/Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw);
        $err = DateTime::getLastErrors();
        $clean = $err === false || ($err['warning_count'] === 0 && $err['error_count'] === 0);
        if ($dt && $clean) {
            $year = (int) $dt->format('Y');
            return ($year >= 1970 && $year <= 2100) ? $dt->format('Y-m-d') : null;
        }
    }
    return null;
}

/** Parse a money value like "1,234.50" or "$99" into a float, or null. */
function parse_amount(string $raw): ?float
{
    $clean = preg_replace('/[^0-9.\-]/', '', $raw);
    if ($clean === '' || !is_numeric($clean)) {
        return null;
    }
    $v = round((float) $clean, 2);
    return abs($v) < 1e10 ? $v : null;
}

/** Excel often saves CSV as Windows-1252; MySQL (utf8mb4) would reject those bytes, so convert to UTF-8. */
function to_utf8(string $s): string
{
    if ($s === '' || preg_match('//u', $s)) {
        return $s;                                   // already valid UTF-8
    }
    return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

function upload_error_text(int $code): string
{
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        return 'The file is larger than the allowed size (check upload_max_filesize / post_max_size in php.ini).';
    }
    if ($code === UPLOAD_ERR_PARTIAL) {
        return 'The file was only partially uploaded. Please try again.';
    }
    if ($code === UPLOAD_ERR_NO_FILE) {
        return 'Please choose a CSV file first.';
    }
    return 'The upload failed (error code ' . $code . ').';
}

$error  = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_check($_POST['csrf'] ?? null)) {
            throw new RuntimeException('Your session expired. Please reload the page and try again.');
        }
        $f = $_FILES['csv'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(upload_error_text((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE)));
        }
        if ($f['size'] > MAX_UPLOAD_BYTES) {
            throw new RuntimeException('File too large. Maximum size is ' . (MAX_UPLOAD_BYTES / 1048576) . ' MB.');
        }
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            throw new RuntimeException('Only .csv files are allowed. In Excel use File > Save As > "CSV (Comma delimited)".');
        }

        $h = fopen($f['tmp_name'], 'rb');
        if ($h === false) {
            throw new RuntimeException('Could not read the uploaded file.');
        }

        // Detect delimiter from the first line, skip a UTF-8 BOM if present.
        $firstLine = (string) fgets($h);
        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
        $delims = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($delims);
        $delim = (string) array_key_first($delims);
        rewind($h);
        if (fread($h, 3) !== "\xEF\xBB\xBF") {
            rewind($h);
        }

        $header = fgetcsv($h, 0, $delim, '"', '');
        if (!$header) {
            throw new RuntimeException('The file is empty.');
        }
        $cols = [];
        foreach ($header as $i => $cell) {
            $key = map_header((string) $cell);
            if ($key !== null && !isset($cols[$key])) {
                $cols[$key] = $i;
            }
        }
        $missing = array_diff(['product', 'category', 'amount', 'date'], array_keys($cols));
        if ($missing) {
            throw new RuntimeException('Missing required column(s): ' . implode(', ', $missing) . '. Expected header: product, category, amount, date, region.');
        }

        $valid  = [];
        $errors = [];
        $skipped = 0;
        $line = 1;
        while (($row = fgetcsv($h, 0, $delim, '"', '')) !== false) {
            $line++;
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue; // blank line
            }
            if (count($valid) + $skipped >= MAX_UPLOAD_ROWS) {
                throw new RuntimeException('Too many rows. Maximum is ' . number_format(MAX_UPLOAD_ROWS) . ' per upload.');
            }
            $product  = trim(to_utf8((string) ($row[$cols['product']]  ?? '')));
            $category = trim(to_utf8((string) ($row[$cols['category']] ?? '')));
            $amount   = parse_amount((string) ($row[$cols['amount']] ?? ''));
            $date     = parse_date((string) ($row[$cols['date']] ?? ''));
            $region   = isset($cols['region']) ? trim(to_utf8((string) ($row[$cols['region']] ?? ''))) : '';

            $problem = null;
            if ($product === '' || $category === '') { $problem = 'product/category is empty'; }
            elseif ($amount === null)                { $problem = 'invalid amount "' . mb_substr((string) $row[$cols['amount']], 0, 20) . '"'; }
            elseif ($date === null)                  { $problem = 'invalid date "' . mb_substr((string) $row[$cols['date']], 0, 20) . '"'; }

            if ($problem !== null) {
                $skipped++;
                if (count($errors) < 10) {
                    $errors[] = "Row $line: $problem";
                }
                continue;
            }
            $valid[] = [
                mb_substr($product, 0, 150),
                mb_substr($category, 0, 80),
                $amount,
                $date,
                mb_substr($region !== '' ? $region : 'Unspecified', 0, 80),
            ];
        }
        fclose($h);

        if (!$valid) {
            throw new RuntimeException('No valid rows found. ' . ($errors ? implode(' · ', array_slice($errors, 0, 3)) : ''));
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if (!empty($_POST['replace'])) {
                $pdo->exec('DELETE FROM sales_data');
            }
            foreach (array_chunk($valid, 500) as $chunk) {
                $ph  = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?)'));
                $st  = $pdo->prepare("INSERT INTO sales_data (product, category, amount, `date`, region) VALUES $ph");
                $st->execute(array_merge(...$chunk));
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }

        $result = [
            'file'     => (string) $f['name'],
            'inserted' => count($valid),
            'skipped'  => $skipped,
            'errors'   => $errors,
            'replaced' => !empty($_POST['replace']),
        ];
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    } catch (Throwable $ex) {
        $error = 'Database error. ' . db_hint($ex);
    }
}

// Current stats + latest rows
$total = 0;
$recent = [];
try {
    $total  = (int) db()->query('SELECT COUNT(*) FROM sales_data')->fetchColumn();
    $recent = db()->query('SELECT id, product, category, amount, `date`, region FROM sales_data ORDER BY id DESC LIMIT 10')->fetchAll();
} catch (Throwable $ex) {
    if ($error === '') {
        $error = 'Database not reachable. ' . db_hint($ex);
    }
}

$base   = '../';
$active = 'upload';
$title  = 'Upload Data';
$extraScripts = ['assets/js/upload.js'];
include __DIR__ . '/../partials/header.php';
?>

<div class="upload-layout">
    <section class="card panel">
        <header class="panel-head">
            <h2>Import sales CSV</h2>
            <span class="muted small"><?= number_format($total) ?> rows currently stored</span>
        </header>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($result): ?>
            <div class="alert alert-success" role="status">
                <strong>Import complete.</strong>
                <?= number_format($result['inserted']) ?> rows added from <em><?= e($result['file']) ?></em><?= $result['replaced'] ? ' (existing data was replaced)' : '' ?>.
                <?php if ($result['skipped'] > 0): ?>
                    <?= number_format($result['skipped']) ?> rows skipped.
                <?php endif; ?>
                <a href="../dashboard.php">View dashboard →</a>
                <?php if ($result['errors']): ?>
                    <ul class="error-list">
                        <?php foreach ($result['errors'] as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="uploadForm">
            <?= csrf_field() ?>
            <label class="dropzone" id="dropzone" for="csv">
                <span class="dz-icon"><?= icon('upload') ?></span>
                <strong id="dzTitle">Drop your CSV here or click to browse</strong>
                <span class="muted small" id="dzSub">.csv up to <?= (int) (MAX_UPLOAD_BYTES / 1048576) ?> MB</span>
                <input type="file" id="csv" name="csv" accept=".csv,text/csv" required>
            </label>

            <label class="check">
                <input type="checkbox" name="replace" value="1">
                <span>Replace all existing sales data with this file</span>
            </label>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="uploadBtn">Upload &amp; import</button>
                <a class="btn btn-ghost" href="sample_sales.csv" download><?= icon('download') ?> Sample CSV</a>
            </div>
        </form>
    </section>

    <section class="card panel">
        <header class="panel-head"><h2>Expected format</h2></header>
        <p class="muted small">First row must be a header. Column names are case-insensitive; <code>region</code> is optional and an <code>id</code> column is ignored.</p>
        <pre class="code">product,category,amount,date,region
Wireless Earbuds,Electronics,79.99,2026-10-03,North
Yoga Mat,Sports,24.50,2026-10-04,West</pre>
        <ul class="tips">
            <li>Dates: <code>YYYY-MM-DD</code> (recommended) or <code>DD/MM/YYYY</code></li>
            <li>Amounts may include <code>$</code> or thousands commas</li>
            <li>Comma, semicolon or tab separated files are detected automatically</li>
            <li>Invalid rows are skipped and reported; valid rows are still imported</li>
        </ul>
    </section>
</div>

<section class="card panel">
    <header class="panel-head">
        <h2>Latest records</h2>
        <span class="muted small">Last 10 rows added</span>
    </header>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>ID</th><th>Product</th><th>Category</th><th class="num">Amount</th><th>Date</th><th>Region</th></tr></thead>
            <tbody>
            <?php if (!$recent): ?>
                <tr><td colspan="6" class="muted center">No data yet — upload a CSV to get started.</td></tr>
            <?php endif; ?>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td><?= e($r['product']) ?></td>
                    <td><?= e($r['category']) ?></td>
                    <td class="num"><?= number_format((float) $r['amount'], 2) ?></td>
                    <td><?= e($r['date']) ?></td>
                    <td><?= e($r['region']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
