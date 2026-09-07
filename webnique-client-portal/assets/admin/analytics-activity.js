(function ($) {
    'use strict';
    $(function () {
        const root = document.getElementById('wnq-activity-feeds');
        if (!root || !window.wnqAnalytics || !wnqAnalytics.clientId) return;
        const titles = { lead_summary: 'Lead Summary', ads_calls: 'Google Ads call records', phone_events: 'Phone-click activity · GA4' };
        let generation = 0;
        const requests = {};
        function node(tag, text, className) {
            const el = document.createElement(tag);
            if (text !== undefined) el.textContent = text;
            if (className) el.className = className;
            return el;
        }
        function value(value) { return value === null || value === undefined ? 'Unavailable' : Number(value).toLocaleString(); }
        function metric(label, rawValue, help, emphasis, periodText) {
            const box = node('div', undefined, 'wnq-lead-metric' + (emphasis ? ' wnq-lead-metric-primary' : ''));
            const heading = node('div', undefined, 'wnq-lead-metric-label'); heading.append(node('span', label));
            const tip = node('button', '?', 'wnq-metric-help'); tip.type = 'button'; tip.title = help; tip.setAttribute('aria-label', label + ': ' + help); heading.append(tip);
            box.append(heading, node('strong', value(rawValue)), node('small', rawValue === null || rawValue === undefined ? 'Provider unavailable' : (periodText || 'Selected reporting period')));
            return box;
        }
        function renderSummary(card, report) {
            card.replaceChildren();
            const header = node('header', undefined, 'wnq-feed-header'); header.append(node('h3', 'Lead Summary'), node('span', report.status === 'available' ? 'Ready' : report.status === 'partial' ? 'Partial coverage' : 'Unavailable', 'wnq-feed-status')); card.append(header);
            if (report.period) card.append(node('p', report.period.start + ' – ' + report.period.end + ' · ' + (report.period.timezone || 'Provider reporting timezone'), 'wnq-feed-period'));
            const periodText = report.period ? report.period.start + ' – ' + report.period.end : 'Selected reporting period';
            const metrics = node('div', undefined, 'wnq-lead-metrics');
            metrics.append(metric('Total Verified Leads', report.total_verified_leads, 'Verified Calls plus confirmed GA4 Form Leads and confirmed GA4 Email Leads. Phone clicks are never included.', true, periodText));
            metrics.append(metric('Verified Calls', report.verified_calls, 'Unique Google Ads call records at or above the configured minimum duration (' + (report.threshold_seconds ?? 20) + ' seconds). Source: Google Ads call_view.', false, periodText));
            metrics.append(metric('Form Leads', report.form_leads, 'GA4 events configured as form lead events and reported as key events. Raw events are not treated as confirmed leads.', false, periodText));
            metrics.append(metric('Email Leads', report.email_leads, 'GA4 events configured as email lead events and reported as key events. Source: GA4 Data API.', false, periodText));
            metrics.append(metric('Website Phone Clicks', report.website_phone_clicks, 'GA4 phone-click interactions. These are not confirmed calls and are excluded from Verified Calls.', false, periodText));
            card.append(metrics);
            const sentence = report.total_verified_leads === null || report.total_verified_leads === undefined ? 'Lead totals are unavailable until each required provider reports successfully.' : 'Google Ads generated ' + value(report.verified_calls) + ' verified phone calls, ' + value(report.form_leads) + ' form submissions, and ' + value(report.email_leads) + ' email leads ' + (report.period_label || 'in this reporting period') + ', for ' + value(report.total_verified_leads) + ' verified leads total.';
            card.append(node('p', sentence, 'wnq-lead-sentence'));
            const callDiff = node('div', undefined, 'wnq-call-difference'); callDiff.append(node('strong', 'All Recorded Calls: ' + value(report.all_recorded_calls)), node('strong', 'Verified Calls: ' + value(report.verified_calls)), node('span', 'Recorded calls include short calls; only calls meeting the threshold count as verified.')); card.append(callDiff);
            if (report.warning) { const warning = node('div', report.warning, 'wnq-lead-warning'); warning.setAttribute('role', 'alert'); card.append(warning); }
            const details = node('details', undefined, 'wnq-lead-breakdown'); details.append(node('summary', 'Tracking Breakdown · phone clicks are not calls'));
            const breakdown = node('div', undefined, 'wnq-breakdown-grid'); const labels = {
                google_ads_recorded_calls: ['Google Ads recorded calls', 'All unique call_view records.'], google_ads_verified_calls: ['Google Ads verified calls', 'Records meeting the duration threshold.'], ga4_ads_phone_clicks: ['GA4 Google Ads phone clicks', 'Phone-click interactions attributed to the linked Ads ID.'], ga4_organic_phone_clicks: ['GA4 organic phone clicks', 'Phone-click interactions attributed to Organic Search.'], ga4_other_phone_clicks: ['GA4 Other phone clicks', 'Phone-click interactions without confident Ads attribution.'], ga4_unknown_phone_clicks: ['GA4 Unknown phone clicks', 'Phone-click interactions without a usable channel.'], forms: ['Forms', 'Confirmed GA4 form key events.'], emails: ['Emails', 'Confirmed GA4 email key events.']
            }; Object.entries(labels).forEach(([key, info]) => { const item = node('div', undefined, 'wnq-breakdown-item'); item.title = info[1]; item.setAttribute('aria-label', info[0] + ': ' + info[1]); item.append(node('span', info[0]), node('strong', value(report.breakdown?.[key])), node('small', info[1])); breakdown.append(item); }); details.append(breakdown); card.append(details);
            card.append(node('p', report.message || 'Each provider is checked independently; unavailable data is not presented as zero.', 'wnq-feed-note'));
        }
        function render(provider, report) {
            const card = document.getElementById('wnq-feed-' + provider);
            card.replaceChildren();
            if (provider === 'lead_summary') { renderSummary(card, report); return; }
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
