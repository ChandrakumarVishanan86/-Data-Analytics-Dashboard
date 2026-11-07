# Data-Analytics-Dashboard

A responsive sales analytics dashboard built with **PHP, MySQL, JavaScript and Chart.js**. Log in, upload a CSV of sales data, filter by date and watch KPI cards, charts and a top-products table update instantly.

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white)
![Chart.js](https://img.shields.io/badge/Chart.js-4.4-FF6384?logo=chartdotjs&logoColor=white)
![Platform](https://img.shields.io/badge/Platform-WAMP%20%7C%20XAMPP%20%7C%20LAMP-lightgrey)

<!-- Add screenshots here, e.g. ![Dashboard](docs/dashboard.png) -->

## Features

- Secure login (bcrypt password hashes, CSRF protection, login throttling, session regeneration)
- 4 KPI cards: **Total Sales**, **Total Users**, **Revenue**, **Growth %**
- 4 views: **Monthly Sales** bar chart, **Category** pie chart, **Revenue** line chart, **Top Products** table
- **Date filter** with From/To inputs and quick ranges (all time, 30 days, 90 days, year to date)
- **CSV upload** into MySQL: drag and drop, auto-detects delimiter, validates every row, skips and reports bad rows, optional "replace existing data"
- Responsive modern UI with collapsible sidebar on mobile
- Chart.js bundled locally, so it works offline
- All queries use PDO prepared statements, and CSV text is rendered with `textContent` (no XSS)

## Tech stack

| Layer | Tech |
|---|---|
| Frontend | HTML, CSS, vanilla JavaScript, Chart.js 4.4 |
| Backend | PHP 7.4+ (PDO) |
| Database | MySQL / MariaDB (`analytics_db`) |

## Quick start (WAMP)

1. Clone or download into your web root:
   ```bash
   cd C:\wamp64\www
   git clone https://github.com/<your-username>/Data-Analytics-Dashboard.git
   ```
2. Start WAMP (tray icon must be **green**).
3. Open **http://localhost/Data-Analytics-Dashboard/setup.php** and click **Install database now**. It checks PHP, MySQL and tables, then creates `analytics_db` with sample data.
   (Prefer phpMyAdmin? Import `database/analytics_db.sql` instead.)
4. Open **http://localhost/Data-Analytics-Dashboard/** and sign in.

DB settings (host, port, user, password) are at the top of `config.php`. WAMP defaults: `root` with an empty password; ports 3306-3308 are tried automatically.

**Demo login:** `admin` / `admin123` (change it before deploying anywhere public, see below)

## Project structure

```
Data-Analytics-Dashboard/
├── index.php             Login page
├── dashboard.php         Cards, charts, table, date filter
├── setup.php             One-click installer + connection check
├── logout.php            Ends session (POST + CSRF)
├── config.php            DB settings, currency, upload limits, helpers
├── auth.php              Login guard + CSRF helpers
├── upload/
│   ├── index.php         CSV upload page + importer
│   └── sample_sales.csv  Test file (Oct to early Nov 2026, 163 rows)
├── api/
│   └── data.php          JSON endpoint for the dashboard
├── partials/             Shared header / footer / sidebar
├── assets/
│   ├── css/style.css
│   ├── js/               app.js, dashboard.js, upload.js
│   └── vendor/           chart.umd.js (Chart.js 4.4.7)
└── database/
    └── analytics_db.sql  Schema + seed data
```

## Database

| Table | Purpose |
|---|---|
| `sales_data` | `id, product, category, amount, date, region` (CSV uploads go here) |
| `customers` | Feeds the **Total Users** card |
| `users` | Login accounts (bcrypt hashes) |

## CSV format

```csv
product,category,amount,date,region
Wireless Earbuds,Electronics,79.99,2026-10-03,North
Yoga Mat,Sports,24.50,2026-10-04,West
```

- Header row required; `region` is optional; an `id` column is ignored
- Dates: `YYYY-MM-DD` (recommended) or `DD/MM/YYYY`
- Comma, semicolon or tab separated; max 5 MB and 50,000 rows per upload
- Try it with `upload/sample_sales.csv`

## How the numbers are calculated

| Card | Definition |
|---|---|
| Total Sales | Number of orders (rows) in the selected range |
| Total Users | Customers registered up to the range end date |
| Revenue | `SUM(amount)` in the selected range |
| Growth % | Latest **complete** month's revenue vs the month before it (a half-finished month is ignored) |

The revenue line switches between daily, weekly and monthly points based on the range length.

## Change the admin password

```php
<?php echo password_hash('your-new-password', PASSWORD_BCRYPT);
```

Paste the output into `users.password_hash` for `admin` in phpMyAdmin.

## Troubleshooting

- **Database error on the login page**: open `setup.php`; it shows exactly which check fails (MySQL off, wrong port/password, missing tables) and fixes missing tables in one click
- **Charts missing**: confirm `assets/vendor/chart.umd.js` exists and check the browser console
- **Large upload fails**: raise `upload_max_filesize` and `post_max_size` in `php.ini`

## License

MIT. Use it freely for learning and your own projects.
