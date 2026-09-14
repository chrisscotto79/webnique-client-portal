/* Browser-hosted scheduler. No Facebook credentials or content HTML are received. */
(() => {
    'use strict';
    const status = document.getElementById('fb-status');
    if (!status || !window.WNQFacebook) return;
    const requests = new Map();
    let running = false, busy = false, mode = 'scheduled', timer;
    const show = text => { status.textContent = text; };
    const errorBox = document.getElementById('fb-errors');
    const error = text => { errorBox.textContent = text; errorBox.hidden = !text; };
    function controls() {
        for (const id of ['fb-start', 'fb-test', 'fb-resume', 'fb-login']) document.getElementById(id).disabled = busy || running;
    }
    window.addEventListener('message', event => {
        if (event.source !== window || event.origin !== location.origin || event.data?.source !== 'wnq-facebook-extension') return;
        const pending = requests.get(event.data.id);
        if (!pending) return;
        clearTimeout(pending.timer); requests.delete(event.data.id);
        event.data.error ? pending.reject(new Error(event.data.error)) : pending.resolve(event.data.result);
    });
    function companion(op, job) {
        return new Promise((resolve, reject) => {
            const id = crypto.randomUUID();
            const timer = setTimeout(() => { requests.delete(id); reject(new Error('Companion response timed out. Check Facebook before retrying; a reserved submission is not automatically repeated.')); }, op === 'publish' ? 120000 : 10000);
            requests.set(id, {resolve, reject, timer});
            window.postMessage({source: 'wnq-facebook-page', id, op, job}, location.origin);
        });
    }
    async function api(op, fields = {}) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(WNQFacebook.ajax, {method: 'POST', credentials: 'same-origin', signal: controller.signal,
                body: new URLSearchParams({action: 'wnq_facebook_publish', nonce: WNQFacebook.nonce, op, ...fields})});
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.data?.message || 'WordPress session expired or request failed.');
            return result.data;
        } finally { clearTimeout(timeout); }
    }
    async function progress() {
        const data = await api('progress', {mode});
        const c = data.counts;
        document.getElementById('fb-progress').textContent = `${c.submitted + c.pending + c.skipped + c.review} of ${c.total} processed · ${c.submitted} submitted · ${c.pending} awaiting approval · ${c.skipped} skipped · ${c.review} need review`;
        const labels = {submitted: 'Submitted — Facebook confirmed', pending: 'Awaiting group approval', skipped: 'Not posted — skipped', review: 'Unconfirmed — review needed', reserved: 'In progress', waiting: 'Not posted yet'};
        for (const node of document.querySelectorAll('[data-fb-group]')) {
            const row = data.rows.find(item => item.url === node.dataset.fbGroup);
            if (row) {
                let label = labels[row.status] || row.status;
                let detail = row.message;
                if (row.status === 'submitted' && row.confirmation !== 'browser') {
                    label = row.confirmation === 'manual' ? 'Posted — manually confirmed' : 'Marked submitted — confirmation source not recorded';
                    if (row.confirmation !== 'manual') detail = 'Older record; check Facebook to verify. Duplicate protection remains active.';
                }
                node.textContent = label + (detail ? ' · ' + detail : '');
            }
        }
        if (running && mode === 'scheduled' && !data.enabled) { running = false; show('Weekly schedule stopped. Select Resume to continue.'); }
        if (!running && !busy && data.enabled) show('Schedule is enabled but this page is idle. Select Resume to continue.');
        const panel = document.getElementById('fb-review');
        panel.replaceChildren();
        for (const item of data.review) {
            const row = document.createElement('p'), link = document.createElement('a');
            link.href = item.url; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.textContent = 'Check Facebook group'; row.append(link);
            if (item.skipped) { row.append(' — ' + item.message); panel.append(row); continue; }
            for (const [resolution, label] of [['submitted', 'Already posted'], ['skipped', 'Definitely not posted — skip']]) {
                const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = label;
                button.onclick = async () => {
                    if (busy || running) return show('Pause the run before resolving a submission.');
                    if (!confirm('Have you checked this group in Facebook? This marks it “' + label + '” and does not publish another post.')) return;
                    button.disabled = true;
                    try { await api('resolve', {key: item.key, resolution}); await progress(); show('Review saved. No post was sent.'); }
                    catch (e) { error(e.message); button.disabled = false; }
                }; row.append(' ', button);
            }
            panel.append(row);
        }
    }
    async function tick() {
        if (!running || busy) return;
        busy = true;
        controls();
        try {
            const connection = await companion('ping');
            if (!connection.version || connection.version.localeCompare('1.0.7', undefined, {numeric: true}) < 0) throw new Error('Update and reload Facebook companion 1.0.7 or newer before publishing.');
            const next = await api('next', {mode});
            if (next.waiting || next.finished || next.stopped) {
                show((next.message || 'Waiting for the saved daily start time.') + (next.next_at ? ' Next attempt after ' + new Date(next.next_at * 1000).toLocaleTimeString() : ''));
                if (next.stopped || (mode === 'test' && next.finished)) running = false;
                if (mode === 'test' && next.finished) error(next.message);
            } else if (next.job) {
                const job = next.job;
                show('Publishing: ' + job.url);
                if (!running) {
                    await api('result', {...job, status: 'not_started'});
                    return;
                }
                const result = await companion('publish', job);
                await api('result', {key: job.key, token: job.token, status: result.status, scope: result.scope || 'account', message: result.message || ''});
                show(mode === 'test' ? (result.status === 'submitted' ? 'Test passed: Facebook confirmed submission.' : result.status === 'pending' ? 'Test submitted for approval — not publicly posted yet.' : 'Test did not confirm a post.') : result.message);
                if (!['submitted', 'pending'].includes(result.status)) error(job.url + ' — ' + result.message);
                if ((!['submitted', 'pending'].includes(result.status) && result.scope !== 'group') || mode === 'test') running = false;
            }
            await progress();
        } catch (e) { running = false; error(e.message); show('Stopped due to an error. Check details below, then Resume.'); await api('stop').catch(() => {}); }
        finally { busy = false; controls(); if (running) timer = setTimeout(tick, 10000); }
    }
    async function start(nextMode, action = 'start') {
        if (busy || running) return;
        if (!confirm(nextMode === 'test' ? 'Test sends a REAL post to the first saved group, subject to the cutoff, six-minute interval and duplicate protection. Continue?' : 'Start/resume the saved weekly schedule at one post every six minutes? Only continue if these groups allow your message.')) return;
        busy = true; controls(); error('');
        try { await api(nextMode === 'test' ? 'stop' : action); mode = nextMode; running = true; }
        catch (e) { error(e.message); }
        finally { busy = false; controls(); }
        clearTimeout(timer); await tick();
    }
    document.getElementById('fb-start').onclick = () => start('scheduled');
    document.getElementById('fb-resume').onclick = () => start('scheduled', 'resume');
    document.getElementById('fb-test').onclick = () => start('test');
    document.getElementById('fb-stop').onclick = async () => {
        running = false; clearTimeout(timer); controls();
        show('Stopping the weekly schedule. A post already submitted cannot be recalled.');
        try { await Promise.all([api('stop'), companion('cancel')]); show('Weekly schedule stopped. Select Resume to continue.'); }
        catch (e) { error('Stop could not be fully confirmed: ' + e.message); }
    };
    document.getElementById('fb-login').onclick = async () => {
        if (running || busy) return show('Pause publishing before opening the login page.');
        try { await companion('login'); show('Sign in directly on Facebook, then return here.'); } catch (e) { error(e.message); }
    };
    const connect = async () => {
        try { const info = await companion('ping'); document.getElementById('fb-connection').textContent = 'Companion connected · ' + info.version; } catch (e) { document.getElementById('fb-connection').textContent = 'Companion disconnected'; error(e.message); }
    };
    document.getElementById('fb-connect').onclick = connect;
    window.addEventListener('beforeunload', event => { if (running || busy) { event.preventDefault(); event.returnValue = ''; } });
    connect();
    progress().catch(e => error(e.message));
})();
