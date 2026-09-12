/* Sequential handoff runner alongside collection; no token and no approval requests. */
(() => {
    const root = document.getElementById('lf-ghl-auto');
    if (!root) return;
    const status = root.querySelector('[data-progress]');
    const key = 'wnq-ghl-backlog';
    let backlog = null;
    try { backlog = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (_) {}
    window.addEventListener('wnq-search-start', () => {
        backlog = {after:0, upper:0, queued:0};
        sessionStorage.setItem(key, JSON.stringify(backlog));
    });
    async function run() {
        let delay = 1500;
        try {
            if (backlog) {
                const current = backlog;
                const scan = await fetch(root.dataset.url, {method:'POST', credentials:'same-origin', body:new URLSearchParams({action:'wnq_ghl_drain', _ajax_nonce:root.dataset.nonce, operation:'collect_backlog', after:current.after, upper:current.upper})});
                const result = await scan.json();
                if (!scan.ok || !result.success) {
                    root.querySelector('[data-backlog]').textContent = result.data?.message || 'Saved lead scan delayed; retrying.';
                    throw new Error('scan');
                }
                if (backlog === current) {
                    const data = result.data;
                    backlog = data.done ? null : {...data, queued:current.queued + data.queued};
                    root.querySelector('[data-backlog]').textContent = `${data.done ? 'Saved lead scan complete' : 'Scanning saved leads'}: ${current.queued + data.queued} newly queued. Existing handoffs and invalid emails skipped.`;
                    if (backlog) sessionStorage.setItem(key, JSON.stringify(backlog)); else sessionStorage.removeItem(key);
                }
            }
            const response = await fetch(root.dataset.url, {
                method: 'POST', credentials: 'same-origin',
                body: new URLSearchParams({action: 'wnq_ghl_drain', _ajax_nonce: root.dataset.nonce})
            });
            if (response.status === 401 || response.status === 403) {
                status.textContent = 'Handoff session expired. Refresh WordPress; saved jobs are retained.';
                return;
            }
            if (!response.ok) throw new Error('network');
            const result = await response.json();
            if (!result.success) throw new Error('response');
            const c = result.data;
            status.textContent = `${c.sent} tagged in GHL · ${c.queued} waiting · ${c.processing} transferring · ${c.review + c.failed + c.held} held. Counts cover all searches. GHL controls email delivery.`;
            if (!c.queued && !c.processing) delay = 5000;
        } catch (_) {
            status.textContent = 'GHL handoff connection delayed; retrying in 15 seconds. Saved jobs are retained.';
            delay = 15000;
        }
        setTimeout(run, delay);
    }
    run();
})();
