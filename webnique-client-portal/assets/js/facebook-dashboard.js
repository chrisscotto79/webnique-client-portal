/* One browser runner, multiple independently enabled company schedules. */
(() => {
    'use strict';
    if (!document.getElementById('fb-dashboard') || !window.WNQFacebook) return;
    const $ = id => document.getElementById(id), pending = new Map(), active = new Set(), notes = new Map();
    let rows = [], busy = false, starting = false, cursor = 0, epoch = 0, timer, current = null, nextAt = 0;
    const say = value => { $('fb-dash-status').textContent = value; };
    const fail = value => { $('fb-dash-error').textContent = value; $('fb-dash-error').hidden = !value; };
    window.addEventListener('message', event => {
        if (event.source !== window || event.origin !== location.origin || event.data?.source !== 'wnq-facebook-extension') return;
        const request = pending.get(event.data.id);
        if (!request) return;
        pending.delete(event.data.id); clearTimeout(request.timer);
        event.data.error ? request.reject(new Error(event.data.error)) : request.resolve(event.data.result);
    });
    function companion(op, row = {id: 'agency', name: 'Golden Web Marketing'}, job) {
        return new Promise((resolve, reject) => {
            const id = crypto.randomUUID();
            const timer = setTimeout(() => { pending.delete(id); reject(new Error('Companion response timed out. The attempted group will not be retried.')); }, op === 'publish' ? 120000 : 10000);
            pending.set(id, {resolve, reject, timer});
            window.postMessage({source: 'wnq-facebook-page', id, op, client: row.id, clientName: row.name, job}, location.origin);
        });
    }
    async function api(op, client = 'agency', values = {}) {
        const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(WNQFacebook.ajax, {method: 'POST', credentials: 'same-origin', signal: controller.signal,
                body: new URLSearchParams({action: 'wnq_facebook_publish', nonce: WNQFacebook.nonce, op, client, ...values})});
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.data?.message || 'WordPress request failed.');
            return data.data;
        } finally { clearTimeout(timeout); }
    }
    async function connect() {
        const info = await companion('ping');
        if (!info.version || info.version.localeCompare('1.2.0', undefined, {numeric: true}) < 0) throw new Error('Load Facebook companion 1.2.0 or newer, then refresh this page.');
        $('fb-dash-connection').textContent = '● Companion connected · ' + info.version;
        return info;
    }
    function render() {
        const body = $('fb-task-rows'), term = $('fb-task-search').value.toLowerCase();
        body.replaceChildren(); $('fb-task-count').textContent = '(' + rows.length + ')';
        for (const row of rows.filter(row => row.name.toLowerCase().includes(term))) {
            const tr = document.createElement('tr'); tr.dataset.client = row.id;
            const done = row.counts.submitted + row.counts.pending + row.counts.skipped;
            const values = [row.name, row.groups + ' total · ' + row.today + ' today', row.schedule + '\n' + row.timezone + (row.posting_days?.length ? '\n' + row.posting_days.join(', ') : ''),
                `${row.today ? done + '/' + row.today + ' processed' : 'No groups scheduled today'}\n${row.counts.submitted} posted · ${row.counts.pending} submitted to moderation · ${row.counts.skipped} skipped`];
            for (const value of values) { const td = document.createElement('td'); td.textContent = value; tr.append(td); }
            const cell = document.createElement('td'), badge = document.createElement('span');
            const enabled = active.has(row.id);
            badge.className = 'task-status ' + (enabled ? 'is-running' : !row.ready ? 'is-muted' : '');
            badge.textContent = current?.id === row.id ? '● Publishing' : enabled ? (notes.get(row.id) || (!row.today ? '● No groups scheduled today' : nextAt > Date.now()/1000 ? '● Interval wait' : '● Scheduled')) : !row.ready ? 'Needs setup' : row.enabled ? 'Enabled · resume browser' : 'Paused';
            cell.append(badge); tr.append(cell);
            const actions = document.createElement('td'); actions.className = 'task-row-actions';
            const button = document.createElement('button'); button.type = 'button'; button.className = 'button';
            button.textContent = enabled || row.enabled ? '■ Pause' : '▶ Start'; button.disabled = starting || !row.ready;
            button.onclick = () => (enabled || row.enabled ? pause([row.id]) : start([row.id])).catch(e => fail(e.message));
            const edit = document.createElement('a'); edit.textContent = 'Edit'; edit.href = row.edit; edit.className = 'button';
            actions.append(button, edit); tr.append(actions); body.append(tr);
        }
        if (!body.children.length) { const tr = document.createElement('tr'), td = document.createElement('td'); td.colSpan = 6; td.textContent = 'No matching companies.'; tr.append(td); body.append(tr); }
        $('fb-dash-start').disabled = starting;
        $('fb-dash-resume').disabled = starting;
        $('fb-dash-login').disabled = busy || active.size > 0;
    }
    async function refresh() {
        const data = await api('dashboard'); rows = data.campaigns; nextAt = data.next_at || 0;
        for (const id of active) if (!rows.some(row => row.id === id && row.enabled)) active.delete(id);
        render();
    }
    function schedule() { clearTimeout(timer); if (active.size) timer = setTimeout(tick, 5000); }
    async function start(ids) {
        if (starting) return;
        starting = true; const generation = epoch; fail(''); render();
        try {
            await connect();
            for (const id of ids) {
                if (generation !== epoch) break;
                const row = rows.find(item => item.id === id);
                if (!row?.ready || active.has(id)) continue;
                await api(row.enabled ? 'resume' : 'start', id);
                if (generation !== epoch) { await api('stop', id); break; }
                active.add(id); row.enabled = true; notes.delete(id);
            }
            say(active.size + ' campaign schedule(s) running on this page.');
        } finally { starting = false; render(); schedule(); }
    }
    async function pause(ids) {
        epoch++; clearTimeout(timer);
        for (const id of ids) active.delete(id);
        if (current && ids.includes(current.id)) await companion('cancel', current).catch(e => fail(e.message));
        const errors = [];
        for (const id of ids) { try { await api('stop', id); const row = rows.find(row => row.id === id); if (row) row.enabled = false; } catch(e) { errors.push(e.message); } }
        render(); schedule();
        if (errors.length) throw new Error('Browser paused locally; could not save every paused schedule: ' + errors.join(' '));
        say('Selected schedules paused. A post already clicked cannot be recalled.');
    }
    async function tick() {
        if (busy || !active.size) return;
        busy = true; let row, job;
        try {
            await connect(); await refresh();
            if (nextAt > Date.now()/1000) return;
            // Scan each enabled schedule once. A schedule outside its window cannot starve others.
            for (let attempt = 0; attempt < rows.length; attempt++) {
                row = rows[cursor++ % rows.length];
                if (!active.has(row.id)) continue;
                const next = await api('next', row.id, {mode: 'scheduled'});
                if (next.stopped) { active.delete(row.id); row.enabled = false; continue; }
                if (!next.job) {
                    notes.set(row.id, '● ' + (next.message || (next.finished ? 'No remaining eligible groups today' : 'Waiting for schedule')));
                    if (next.next_at) { nextAt = next.next_at; break; }
                    continue;
                }
                job = next.job;
                if (job.client_id !== row.id || job.client_name !== row.name || !Array.isArray(job.images) || job.images.length !== job.image_count) throw new Error('Campaign identity or image payload mismatch.');
                current = row; render();
                let result;
                if (!active.has(row.id)) result = {status: 'not_started', scope: 'group', message: 'Paused before browser dispatch.'};
                else {
                    say('Publishing for ' + row.name + ' · ' + job.url);
                    try { result = await companion('publish', row, job); }
                    catch(e) { result = {status: 'unknown', scope: 'group', message: e.message}; }
                }
                await api('result', row.id, {key: job.key, token: job.token, status: result.status, scope: result.scope || '', message: result.message || ''});
                notes.set(row.id, result.status === 'submitted' ? '● Posted · interval wait' : result.status === 'pending' ? '● Submitted · interval wait' : '● Skipped · continuing');
                say(row.name + ' — ' + (result.message || 'Attempt recorded.'));
                if (!['submitted', 'pending'].includes(result.status) && result.scope === 'account' && active.has(row.id)) {
                    await pause(rows.filter(item => item.enabled || active.has(item.id)).map(item => item.id));
                    fail('Facebook needs sign-in or account attention. ' + result.message);
                }
                break;
            }
            await refresh();
        } catch(e) {
            fail(e.message); active.clear();
            say('Browser runner paused after a connection or server error. Resume enabled campaigns after reconnecting.');
        } finally { current = null; busy = false; render(); schedule(); }
    }
    $('fb-task-search').addEventListener('input', render);
    $('fb-dash-start').onclick = () => start(rows.filter(row => row.ready).map(row => row.id)).catch(e => fail(e.message));
    $('fb-dash-resume').onclick = () => start(rows.filter(row => row.enabled && row.ready).map(row => row.id)).catch(e => fail(e.message));
    $('fb-dash-stop').onclick = () => pause(rows.filter(row => row.enabled || active.has(row.id)).map(row => row.id)).catch(e => fail(e.message));
    $('fb-dash-connect').onclick = () => connect().catch(e => { $('fb-dash-connection').textContent = '● Disconnected'; fail(e.message); });
    $('fb-dash-login').onclick = () => companion('login').catch(e => fail(e.message));
    window.addEventListener('beforeunload', event => { if (active.size || busy || starting) { event.preventDefault(); event.returnValue = ''; } });
    refresh().catch(e => fail(e.message));
    connect().catch(e => { $('fb-dash-connection').textContent = '● Companion not connected'; fail(e.message); });
})();
