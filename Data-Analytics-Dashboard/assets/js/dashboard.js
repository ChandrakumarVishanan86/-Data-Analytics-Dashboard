/* Dashboard: fetch analytics JSON and render KPI cards, 3 Chart.js charts and the top-products table */
(() => {
    const cfg = window.APP || { currency: 'USD', api: 'api/data.php' };

    const money = new Intl.NumberFormat(undefined, { style: 'currency', currency: cfg.currency, maximumFractionDigits: 0 });
    const moneyCompact = new Intl.NumberFormat(undefined, { style: 'currency', currency: cfg.currency, notation: 'compact', maximumFractionDigits: 1 });
    const int = new Intl.NumberFormat();

    // Categorical palette (distinct hues, readable on white)
    const PALETTE = ['#2563eb', '#0d9488', '#f59e0b', '#e11d48', '#7c3aed', '#0891b2', '#65a30d', '#ea580c'];
    const PRIMARY = '#2563eb';

    const $ = (id) => document.getElementById(id);
    const els = {
        form: $('filterForm'), from: $('fromDate'), to: $('toDate'), reset: $('resetBtn'), presets: $('presets'),
        error: $('errorBox'), empty: $('emptyState'), charts: $('charts'), cards: $('cards'),
        sales: $('kpiSales'), users: $('kpiUsers'), revenue: $('kpiRevenue'),
        growth: $('kpiGrowth'), growthSub: $('kpiGrowthSub'), growthIcon: $('kpiGrowthIcon'),
        trendNote: $('trendNote'), table: $('topTable').querySelector('tbody'),
    };

    const state = { bounds: { min: null, max: null }, charts: {} };

    /* ---------- helpers ---------- */
    const pad = (n) => String(n).padStart(2, '0');
    const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    const parseISO = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d || 1); };
    const fmtMonth = (ym) => parseISO(ym + '-01').toLocaleDateString(undefined, { month: 'short', year: '2-digit' });
    const fmtDay = (s) => parseISO(s).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });

    function setActivePreset(name) {
        els.presets.querySelectorAll('button').forEach((b) => b.classList.toggle('active', b.dataset.range === name));
    }

    function showError(msg) {
        els.error.textContent = msg;
        els.error.hidden = !msg;
    }

    /* ---------- filters / URL state ---------- */
    function readUrl() {
        const p = new URLSearchParams(location.search);
        els.from.value = p.get('from') || '';
        els.to.value = p.get('to') || '';
        if (els.from.value || els.to.value) setActivePreset('custom');
    }

    function writeUrl() {
        const p = new URLSearchParams();
        if (els.from.value) p.set('from', els.from.value);
        if (els.to.value) p.set('to', els.to.value);
        const qs = p.toString();
        history.replaceState(null, '', qs ? `?${qs}` : location.pathname);
    }

    function applyPreset(name) {
        const max = state.bounds.max ? parseISO(state.bounds.max) : new Date();
        let from = '', to = '';
        if (name === '30' || name === '90') {
            const start = new Date(max);
            start.setDate(start.getDate() - (Number(name) - 1));
            from = iso(start);
            to = iso(max);
        } else if (name === 'ytd') {
            from = `${max.getFullYear()}-01-01`;
            to = iso(max);
        }
        els.from.value = from;
        els.to.value = to;
        setActivePreset(name);
        load();
    }

    /* ---------- rendering ---------- */
    function renderCards(c) {
        els.sales.textContent = int.format(c.total_sales);
        els.users.textContent = int.format(c.total_users);
        els.revenue.textContent = money.format(c.revenue);

        els.growthIcon.classList.remove('green', 'red', 'gray');
        if (c.growth === null) {
            els.growth.textContent = '—';
            els.growthIcon.classList.add('gray');
        } else {
            const up = c.growth >= 0;
            els.growth.textContent = `${up ? '+' : ''}${c.growth.toFixed(1)}%`;
            els.growthIcon.classList.add(up ? 'green' : 'red');
        }
        els.growthSub.textContent = c.growth_label;
    }

    function makeChart(key, canvasId, config) {
        if (state.charts[key]) state.charts[key].destroy();
        state.charts[key] = new Chart($(canvasId), config);
    }

    const baseOptions = () => ({
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 450 },
        plugins: { legend: { display: false } },
    });
    const gridColor = 'rgba(100,116,139,.15)';
    const tickColor = '#64748b';

    function renderBar(monthly) {
        makeChart('bar', 'barChart', {
            type: 'bar',
            data: {
                labels: monthly.map((m) => fmtMonth(m.month)),
                datasets: [{
                    label: 'Orders',
                    data: monthly.map((m) => m.sales),
                    backgroundColor: PRIMARY,
                    borderRadius: 6,
                    maxBarThickness: 44,
                }],
            },
            options: {
                ...baseOptions(),
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ` ${int.format(ctx.parsed.y)} orders`,
                            afterLabel: (ctx) => ` Revenue: ${money.format(monthly[ctx.dataIndex].revenue)}`,
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: tickColor } },
                    y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor, precision: 0 } },
                },
            },
        });
    }

    function renderPie(categories) {
        const total = categories.reduce((s, c) => s + c.revenue, 0);
        makeChart('pie', 'pieChart', {
            type: 'pie',
            data: {
                labels: categories.map((c) => c.category),
                datasets: [{
                    data: categories.map((c) => c.revenue),
                    backgroundColor: categories.map((_, i) => PALETTE[i % PALETTE.length]),
                    borderColor: '#fff',
                    borderWidth: 2,
                }],
            },
            options: {
                ...baseOptions(),
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, color: '#334155', padding: 14 } },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const pct = total ? (ctx.parsed / total * 100).toFixed(1) : '0.0';
                                return ` ${ctx.label}: ${money.format(ctx.parsed)} (${pct}%)`;
                            },
                        },
                    },
                },
            },
        });
    }

    function renderLine(trend) {
        const g = trend.granularity;
        const label = (p) => (g === 'month' ? fmtMonth(p.slice(0, 7)) : fmtDay(p));
        els.trendNote.textContent = { day: 'Daily', week: 'Weekly (week starting Monday)', month: 'Monthly' }[g];

        const canvas = $('lineChart');
        const ctx = canvas.getContext('2d');
        const grad = ctx.createLinearGradient(0, 0, 0, 320);
        grad.addColorStop(0, 'rgba(37,99,235,.28)');
        grad.addColorStop(1, 'rgba(37,99,235,0)');

        makeChart('line', 'lineChart', {
            type: 'line',
            data: {
                labels: trend.points.map((p) => label(p.period)),
                datasets: [{
                    label: 'Revenue',
                    data: trend.points.map((p) => p.revenue),
                    borderColor: PRIMARY,
                    backgroundColor: grad,
                    fill: true,
                    tension: 0.35,
                    borderWidth: 2.5,
                    pointRadius: trend.points.length > 40 ? 0 : 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: PRIMARY,
                    pointBorderWidth: 2,
                }],
            },
            options: {
                ...baseOptions(),
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (c) => ` Revenue: ${money.format(c.parsed.y)}` } },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: tickColor, maxTicksLimit: 10, maxRotation: 0 } },
                    y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor, callback: (v) => moneyCompact.format(v) } },
                },
            },
        });
    }

    function renderTable(rows) {
        els.table.replaceChildren();
        if (!rows.length) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 4;
            td.className = 'muted center';
            td.textContent = 'No products';
            tr.appendChild(td);
            els.table.appendChild(tr);
            return;
        }
        rows.forEach((r, i) => {
            const tr = document.createElement('tr');

            const rank = document.createElement('td');
            rank.className = 'rank';
            rank.textContent = i + 1;

            const name = document.createElement('td');
            const strong = document.createElement('div');
            strong.className = 'cell-title';
            strong.textContent = r.product;                // textContent: CSV data is never parsed as HTML
            const cat = document.createElement('div');
            cat.className = 'muted small';
            cat.textContent = r.category;
            const bar = document.createElement('div');
            bar.className = 'share';
            const fill = document.createElement('span');
            fill.style.width = Math.min(100, r.share) + '%';
            bar.appendChild(fill);
            name.append(strong, cat, bar);

            const orders = document.createElement('td');
            orders.className = 'num';
            orders.textContent = int.format(r.orders);

            const rev = document.createElement('td');
            rev.className = 'num';
            const amt = document.createElement('div');
            amt.className = 'cell-title';
            amt.textContent = money.format(r.revenue);
            const pct = document.createElement('div');
            pct.className = 'muted small';
            pct.textContent = r.share.toFixed(1) + '%';
            rev.append(amt, pct);

            tr.append(rank, name, orders, rev);
            els.table.appendChild(tr);
        });
    }

    /* ---------- data loading ---------- */
    let reqId = 0;
    async function load() {
        const id = ++reqId;
        const p = new URLSearchParams();
        if (els.from.value) p.set('from', els.from.value);
        if (els.to.value) p.set('to', els.to.value);
        writeUrl();
        els.cards.classList.add('loading');
        showError('');

        try {
            const res = await fetch(`${cfg.api}?${p}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (res.status === 401) { location.href = 'index.php'; return; }
            const data = await res.json();
            if (id !== reqId) return;                       // a newer request superseded this one
            if (!res.ok || data.error) throw new Error(data.error || 'Request failed');

            state.bounds = data.bounds;
            if (data.bounds.min) { els.from.min = els.to.min = data.bounds.min; }
            if (data.bounds.max) { els.from.max = els.to.max = data.bounds.max; }

            renderCards(data.cards);
            const empty = data.cards.total_sales === 0;
            els.empty.hidden = !empty;
            els.charts.hidden = empty;
            if (!empty) {
                renderBar(data.monthly);
                renderPie(data.categories);
                renderLine(data.trend);
                renderTable(data.top_products);
            }
        } catch (err) {
            showError(err.message || 'Something went wrong while loading the dashboard.');
        } finally {
            if (id === reqId) els.cards.classList.remove('loading');
        }
    }

    /* ---------- events ---------- */
    els.form.addEventListener('submit', (e) => {
        e.preventDefault();
        setActivePreset(els.from.value || els.to.value ? 'custom' : 'all');
        load();
    });
    els.reset.addEventListener('click', () => applyPreset('all'));
    els.presets.addEventListener('click', (e) => {
        const b = e.target.closest('button[data-range]');
        if (b) applyPreset(b.dataset.range);
    });

    readUrl();
    load();
})();
