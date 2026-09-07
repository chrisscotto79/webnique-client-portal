(function ($) {
    'use strict';
    $(function () {
        const root = document.getElementById('wnq-activity-feeds');
        if (!root || !window.wnqAnalytics || !wnqAnalytics.clientId) return;
        const titles = { ads_calls: 'Google Ads call records', phone_events: 'Phone-click activity · GA4' };
        let generation = 0;
        const requests = {};
        function node(tag, text, className) {
            const el = document.createElement(tag);
            if (text !== undefined) el.textContent = text;
            if (className) el.className = className;
            return el;
        }
        function render(provider, report) {
            const card = document.getElementById('wnq-feed-' + provider);
            card.replaceChildren();
            const header = node('header', undefined, 'wnq-feed-header');
            header.append(node('h3', titles[provider]), node('span', report.status === 'available' ? 'Ready' : report.status === 'partial' ? 'Incomplete coverage' : report.status === 'loading' ? 'Loading…' : report.status === 'not_linked' ? 'Not connected' : 'Unavailable', 'wnq-feed-status'));
            card.append(header);
            if (report.period) card.append(node('p', report.period.start + ' – ' + report.period.end + ' · ' + (report.timezone || 'Provider reporting timezone') + ' · Recent data may be incomplete.', 'wnq-feed-period'));
            card.append(node('p', report.message || 'Fetching this source independently…', 'wnq-feed-note'));
            if (!['available', 'partial'].includes(report.status)) return;
            const rows = Array.isArray(report.rows) ? report.rows : [];
            if (!rows.length) {
                card.append(node('p', 'No records reported for these dates. This does not prove tracking is installed or that no leads occurred.', 'wnq-feed-empty'));
                return;
            }
            if (provider === 'phone_events') {
                const totals = { 'Google Ads': 0, 'Organic search': 0, 'Other': 0, 'Unknown': 0 };
                rows.forEach(row => { totals[row.source] = (totals[row.source] || 0) + Number(row.count || 0); });
                const summary = node('div', undefined, 'wnq-source-counts');
                Object.entries(totals).forEach(([source, count]) => {
                    const box = node('div'); box.append(node('strong', count.toLocaleString()), node('span', source + ' · phone clicks')); summary.append(box);
                });
                card.append(summary);
            } else card.append(node('p', rows.length + ' reported calls · Not deduplicated across sources', 'wnq-feed-period'));
            const controls = node('div', undefined, 'wnq-feed-controls');
            const label = node('label', 'Source ');
            const filter = node('select');
            filter.setAttribute('aria-label', 'Filter ' + titles[provider] + ' by source');
            ['All sources', ...new Set(rows.map(row => row.source))].forEach(value => {
                const option = node('option', value); option.value = value; filter.append(option);
            });
            label.append(filter);
            const search = node('input'); search.type = 'search'; search.placeholder = 'Search dates or evidence'; search.setAttribute('aria-label', 'Search ' + titles[provider]);
            const status = node('span'); status.setAttribute('role', 'status');
            const previous = node('button', 'Previous', 'button'); previous.type = 'button';
            const next = node('button', 'Next', 'button'); next.type = 'button';
            controls.append(label, search, status, previous, next);
            const details = node('details', undefined, 'wnq-feed-evidence'); details.open = true;
            details.append(node('summary', 'Activity evidence'));
            const scroll = node('div', undefined, 'wnq-feed-scroll'); scroll.tabIndex = 0; scroll.setAttribute('role', 'region'); scroll.setAttribute('aria-label', titles[provider] + ' evidence table; scroll for more columns');
            const table = node('table'); const head = node('thead'); const tr = node('tr');
            const columns = provider === 'phone_events' ? [['time','Date / minute'],['source','Attribution'],['event','Event'],['device','Device'],['count','Phone clicks'],['key_events','GA4 key events'],['channel','Reported channel']]
                : [['time','Call start'],['source','Source'],['campaign','Search campaign'],['status','Call status'],['duration','Duration (seconds)']];
            columns.forEach(([, title]) => { const th = node('th', title); th.scope = 'col'; tr.append(th); }); head.append(tr);
            const body = node('tbody'); table.append(head, body); scroll.append(table);
            const empty = node('p', 'No records match these filters.', 'wnq-feed-empty');
            details.append(controls, scroll, empty); card.append(details);
            let page = 0;
            function draw() {
                const text = search.value.trim().toLowerCase();
                const matches = rows.filter(row => (filter.value === 'All sources' || row.source === filter.value) && (!text || columns.some(([key]) => String(row[key] ?? '').toLowerCase().includes(text))));
                page = Math.max(0, Math.min(page, Math.ceil(matches.length / 25) - 1));
                body.replaceChildren();
                matches.slice(page * 25, page * 25 + 25).forEach(row => {
                    const rowEl = node('tr');
                    columns.forEach(([key]) => rowEl.append(node('td', String(row[key] ?? 'Unavailable'))));
                    body.append(rowEl);
                });
                status.textContent = matches.length ? (page * 25 + 1) + '–' + Math.min(page * 25 + 25, matches.length) + ' of ' + matches.length : '0 records';
                previous.disabled = page === 0; next.disabled = (page + 1) * 25 >= matches.length;
                empty.hidden = matches.length !== 0; scroll.hidden = !matches.length;
            }
            filter.addEventListener('change', () => { page = 0; draw(); });
            search.addEventListener('input', () => { page = 0; draw(); });
            previous.addEventListener('click', () => { page--; draw(); });
            next.addEventListener('click', () => { page++; draw(); }); draw();
        }
        Object.keys(titles).forEach(provider => {
            const card = node('article', undefined, 'wnq-feed-card'); card.id = 'wnq-feed-' + provider; root.append(card);
        });
        function load(refresh) {
            const current = ++generation;
            Object.keys(titles).forEach(provider => {
                if (requests[provider]) requests[provider].abort();
                render(provider, { status: 'loading' });
                requests[provider] = $.ajax({
                    url: wnqAnalytics.ajaxUrl, type: 'POST', timeout: 120000,
                    data: { action: 'wnq_get_analytics_activity', nonce: wnqAnalytics.nonce, client_id: wnqAnalytics.clientId, provider, date_range: $('#wnq-date-range').val(), refresh: refresh ? 1 : 0 },
                    success: response => { if (current === generation) render(provider, response && response.success ? response.data : { status: 'unavailable', message: 'Could not load this source. Check access and refresh.' }); },
                    error: (_, state) => { if (current === generation && state !== 'abort') render(provider, { status: 'unavailable', message: 'This source did not respond. Other reports remain available. Try Refresh.' }); }
                });
            });
        }
        $('#wnq-date-range').on('change', () => load(false));
        $('#wnq-refresh-data').on('click', () => load(true));
        load(false);
    });
})(jQuery);
