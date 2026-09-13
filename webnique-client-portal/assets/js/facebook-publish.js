/* Browser-hosted scheduler. No Facebook credentials or content HTML are received. */
(() => {
    'use strict';
    const status = document.getElementById('fb-status');
    if (!status || !window.WNQFacebook) return;
    const requests = new Map();
    let running = false, busy = false, mode = 'scheduled', timer;
    const show = text => { status.textContent = text; };
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
    async function tick() {
        if (!running || busy) return;
        busy = true;
        try {
            await companion('ping');
            const next = await api('next', {mode});
            if (next.waiting || next.finished) {
                show(next.message || 'Waiting for the saved daily start time.');
                if (mode !== 'scheduled') running = false;
            } else if (next.job) {
                const job = next.job;
                show('Publishing: ' + job.url);
                if (!running) {
                    await api('result', {...job, status: 'not_started'});
                    return;
                }
                const result = await companion('publish', job);
                await api('result', {key: job.key, token: job.token, status: result.status});
                show(result.message);
                if (!['submitted', 'pending'].includes(result.status) || mode === 'test') running = false;
            }
        } catch (error) { running = false; show('Paused: ' + error.message); }
        finally { busy = false; if (running) timer = setTimeout(tick, 60000); }
    }
    async function start(nextMode) {
        if (busy || running) return;
        if (!confirm('Publish your SAVED message to ' + (nextMode === 'test' ? 'the FIRST saved Facebook group now' : nextMode === 'today' ? 'today’s saved groups now' : 'each daily batch at the saved time while this page stays open') + '? Only continue if these groups allow your post.')) return;
        mode = nextMode; running = true; clearTimeout(timer); await tick();
    }
    document.getElementById('fb-start').onclick = () => start('scheduled');
    document.getElementById('fb-now').onclick = () => start('today');
    document.getElementById('fb-test').onclick = () => start('test');
    document.getElementById('fb-stop').onclick = () => { running = false; clearTimeout(timer); show(busy ? 'Pausing after the current request. A post already being submitted cannot be recalled.' : 'Paused.'); };
    document.getElementById('fb-login').onclick = async () => {
        if (running || busy) return show('Pause publishing before opening the login page.');
        try { await companion('login'); show('Sign in directly on Facebook, then return here.'); } catch (e) { show(e.message); }
    };
    const connect = async () => {
        try { await companion('ping'); show('Facebook companion connected. Save your plan, sign in, then publish.'); } catch (e) { show('Install or reload facebook-companion, then refresh this WordPress tab.'); }
    };
    document.getElementById('fb-connect').onclick = connect;
    window.addEventListener('beforeunload', event => { if (running || busy) { event.preventDefault(); event.returnValue = ''; } });
    connect();
})();
