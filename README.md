# -Data-Analytics-Dashboard
A responsive sales analytics dashboard built with *PHP, MySQL, JavaScript and Chart.js*. Log in, upload a CSV of sales data, filter by date and watch KPI cards, charts and a top-products table update instantly.


## Features

- Secure login (bcrypt password hashes, CSRF protection, login throttling, session regeneration)
- 4 KPI cards: *Total Sales, **Total Users, **Revenue, **Growth %*
- 4 views: *Monthly Sales* bar chart, *Category* pie chart, *Revenue* line chart, *Top Products* table
- *Date filter* with From/To inputs and quick ranges (all time, 30 days, 90 days, year to date)
- *CSV upload* into MySQL: drag and drop, auto-detects delimiter, validates every row, skips and reports bad rows, optional "replace existing data"
- Responsive modern UI with collapsible sidebar on mobile
- Chart.js bundled locally, so it works offline
- All queries use PDO prepared statements, and CSV text is rendered with textContent (no XSS)

## Tech stack

| Layer | Tech |
|---|---|
| Frontend | HTML, CSS, vanilla JavaScript, Chart.js 4.4 |
| Backend | PHP 8+ (PDO) |
| Database | MySQL / MariaDB (analytics_db) |

## Quick start (WAMP)

1. Clone or download into your web root:
   bash
   cd C:\wamp64\www
   git clone https://github.com/<your-username>/Data-Analytics-Dashboard.git
   
2. Start WAMP, open *phpMyAdmin* (http://localhost/phpmyadmin) → *Import* → select database/analytics_db.sql → *Go*.
3. Check config.php (WAMP defaults: user root, empty password).
4. Open *http://localhost/Data-Analytics-Dashboard/*

*Demo login:* admin / admin123 (change it before deploying anywhere public, see below)

## Project structure


Data-Analytics-Dashboard/
├── index.php             Login page
├── dashboard.php         Cards, charts, table, date filter
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


## Database

| Table | Purpose |
|---|---|
| sales_data | id, product, category, amount, date, region (CSV uploads go here) |
| customers | Feeds the *Total Users* card |
| users | Login accounts (bcrypt hashes) |

## CSV format

csv
product,category,amount,date,region
Wireless Earbuds,Electronics,79.99,2026-10-03,North
Yoga Mat,Sports,24.50,2026-10-04,West


- Header row required; region is optional; an id column is ignored
- Dates: YYYY-MM-DD (recommended) or DD/MM/YYYY
- Comma, semicolon or tab separated; max 5 MB and 50,000 rows per upload
- Try it with upload/sample_sales.csv

## How the numbers are calculated

| Card | Definition |
|---|---|
| Total Sales | Number of orders (rows) in the selected range |
| Total Users | Customers registered up to the range end date |
| Revenue | SUM(amount) in the selected range |
| Growth % | Latest month's revenue vs the previous month inside the range |

The revenue line switches between daily, weekly and monthly points based on the range length.

## Change the admin password

php
<?php echo password_hash('your-new-password', PASSWORD_BCRYPT);


Paste the output into users.password_hash for admin in phpMyAdmin.

## Troubleshooting

- *"Cannot reach the database"*: SQL not imported, or config.php credentials differ
- *Charts missing*: confirm assets/vendor/chart.umd.js exists and check the browser console
- *Large upload fails*: raise upload_max_filesize and post_max_size in php.ini

## License

MIT. 



## Author
Chandrakumar Vishanan , < SLIATE > HNDIT

