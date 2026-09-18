/* Browser-hosted scheduler. No Facebook credentials or content HTML are received. */
(() => {
    'use strict';
    const status = document.getElementById('fb-status');
    if (!status || !window.WNQFacebook) return;
    const clientId = WNQFacebook.client || 'agency';
    const clientName = WNQFacebook.clientName || 'Golden Web Marketing';
    const requests = new Map();
    let running = false, busy = false, mode = 'scheduled', timer, generation = 0, historyWeek = null, dirty = false;
    const show = text => { status.textContent = clientName + ' — ' + text; };
    const errorBox = document.getElementById('fb-errors');
    const error = text => { errorBox.textContent = text; errorBox.hidden = !text; };
    function controls() {
        for (const id of ['fb-client', 'fb-switch']) { const node = document.getElementById(id); if (node) node.disabled = busy || running; }
        for (const id of ['fb-start', 'fb-test', 'fb-resume', 'fb-login', 'fb-image', 'fb-image-clear']) document.getElementById(id).disabled = busy || running;
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
            window.postMessage({source: 'wnq-facebook-page', id, op, job, client: clientId, clientName}, location.origin);
        });
    }
    async function api(op, fields = {}) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(WNQFacebook.ajax, {method: 'POST', credentials: 'same-origin', signal: controller.signal,
                body: new URLSearchParams({action: 'wnq_facebook_publish', nonce: WNQFacebook.nonce, op, ...fields, client: clientId})});
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.data?.message || 'WordPress session expired or request failed.');
            return result.data;
        } finally { clearTimeout(timeout); }
    }
    async function progress() {
        const week = historyWeek;
        const data = await api('progress', {mode, ...(week ? {week} : {})});
        if (!week && data.week && document.getElementById('fb-history-week')) document.getElementById('fb-history-week').value = data.week.replace('-', '-W');
        if (data.client && data.client !== clientId) throw new Error('Client response mismatch. Nothing else will be posted.');
        const c = data.counts;
        document.getElementById('fb-progress').textContent = `${c.submitted + c.pending + c.skipped} of ${c.total} processed · ${c.submitted} submitted · ${c.pending} in Facebook moderation · ${c.skipped} skipped`;
        const labels = {submitted: 'Submitted — Facebook confirmed', pending: 'Submitted to Facebook moderation', skipped: 'Skipped — no confirmed post', review: 'Unconfirmed — skipped', reserved: 'In progress', waiting: 'Not posted yet'};
        const history = document.getElementById('fb-history-results');
        if (history) {
            history.replaceChildren();
            for (const item of data.rows) {
                const line = document.createElement('p'), link = document.createElement('a');
                link.href = item.url; link.textContent = item.url; link.target = '_blank'; link.rel = 'noopener noreferrer';
                const label = item.status === 'submitted' && item.confirmation !== 'browser' ? 'Posted — manual or legacy confirmation' : (labels[item.status] || item.status);
                line.append(link, ' — ' + label + (item.message ? ' · ' + item.message : '') + (item.updated_at ? ' · ' + new Date(item.updated_at * 1000).toLocaleString() : ''));
                history.append(line);
            }
        }
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

    }
    async function tick() {
        if (!running || busy) return;
        busy = true;
        controls();
        try {
            const connection = await companion('ping');
            if (!connection.version || connection.version.localeCompare('1.2.0', undefined, {numeric: true}) < 0) throw new Error('Update and reload Facebook companion 1.2.0 or newer before publishing.');
            const next = await api('next', {mode});
            if (next.waiting || next.finished || next.stopped) {
                show((next.message || 'Waiting for the saved daily start time.') + (next.next_at ? ' Next attempt after ' + new Date(next.next_at * 1000).toLocaleTimeString() : ''));
                if (next.stopped || (mode === 'test' && next.finished)) running = false;
                if (mode === 'test' && next.finished) error(next.message);
            } else if (next.job) {
                const job = next.job;
                if (job.client_id !== clientId || job.client_name !== clientName) throw new Error('Client changed or job identity mismatch. Reload this client before posting.');
                if (!Array.isArray(job.images) || job.images.length !== job.image_count) throw new Error('Campaign image payload is incomplete. No post dispatched.');
                show('Publishing ' + job.image_count + ' image(s): ' + job.url);
                if (!running) {
                    await api('result', {...job, status: 'not_started'});
                    return;
                }
                let result;
                try { result = await companion('publish', job); }
                catch (e) { result = {status: 'unknown', scope: 'group', message: e.message}; }
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
        if (dirty) return error('Save this client’s changed settings before starting or testing.');
        historyWeek = null;
        if (!confirm(clientName + ': ' + (nextMode === 'test' ? 'Test sends a REAL post to the first saved group, subject to the cutoff, one-minute interval and duplicate protection. Continue?' : 'Start/resume the saved weekly schedule at one post every one minute? Only continue if these groups allow your message.'))) return;
        busy = true; controls(); error('');
        const started = ++generation;
        try { await api(nextMode === 'test' ? 'stop' : action); if (started === generation) { mode = nextMode; running = true; } }
        catch (e) { error(e.message); }
        finally { busy = false; controls(); }
        clearTimeout(timer); await tick();
    }
    document.getElementById('fb-start').onclick = () => start('scheduled');
    document.getElementById('fb-resume').onclick = () => start('scheduled', 'resume');
    document.getElementById('fb-test').onclick = () => start('test');
    document.getElementById('fb-stop').onclick = async () => {
        generation++; running = false; clearTimeout(timer); controls();
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
    document.getElementById('fb-client-form')?.addEventListener('submit', event => {
        if (running || busy) { event.preventDefault(); error('Stop the current campaign before switching clients.'); return; }
        if (dirty && !confirm('Switch clients without saving these changes?')) { event.preventDefault(); document.getElementById('fb-client').value = clientId; }
    });
    document.querySelector('form[method="post"]')?.addEventListener('input', () => { dirty = true; });
    const clientSelect = document.getElementById('fb-client');
    if (clientSelect) clientSelect.onchange = () => {
        if (running || busy) { clientSelect.value = clientId; return; }
        document.getElementById('fb-client-form').requestSubmit();
    };
    const historyButton = document.getElementById('fb-history-load');
    if (historyButton) historyButton.onclick = () => { historyWeek = document.getElementById('fb-history-week').value.replace('-W', '-'); progress().catch(e => error(e.message)); };
    const imageButton = document.getElementById('fb-image');
    const imageSaveStatus = document.getElementById('fb-image-save-status');
    async function saveImages(images) {
        if (running || busy) return error('Stop publishing before changing images.');
        if (images.length > 4) return error('Choose up to four images.');
        busy = true; controls();
        if (imageSaveStatus) imageSaveStatus.textContent = 'Saving images for ' + clientName + '…';
        try {
            const ids = images.map(image => String(image.id));
            const saved = await api('save_images', {image_ids: ids.join(',')});
            if (saved.client !== clientId || JSON.stringify(saved.image_ids.map(String)) !== JSON.stringify(ids)) throw new Error('Image save response did not match this client. Reload before publishing.');
            document.getElementById('fb-image-id').value = ids.join(',');
            document.getElementById('fb-image-label').textContent = images.length ? images.map(image => image.filename || image.title || ('Image ' + image.id)).join(', ') : 'No images';
            const previews = document.getElementById('fb-image-previews');
            if (previews) {
                previews.replaceChildren();
                for (const image of images) {
                    const src = image.sizes?.thumbnail?.url || image.url;
                    if (!src || !/^https?:\/\//i.test(src)) continue;
                    const img = document.createElement('img'); img.src = src; img.alt = image.title || 'Saved campaign image';
                    img.style.cssText = 'width:96px;height:96px;object-fit:cover';
                    previews.append(img);
                }
            }
            if (imageSaveStatus) imageSaveStatus.textContent = images.length + ' image(s) saved for ' + clientName + '. They will be attached to new posts.';
            error('');
        } catch (e) {
            if (imageSaveStatus) imageSaveStatus.textContent = 'Images were not saved: ' + e.message + ' Your previous selection is unchanged.';
            error(e.message);
        } finally { busy = false; controls(); }
    }
    if (imageButton) imageButton.onclick = () => {
        if (running || busy) return;
        try {
            if (typeof window.wp?.media !== 'function') throw new Error('WordPress Media Library did not load. Refresh this page.');
            const picker = wp.media({frame: 'select', state: 'library', title: clientName + ' — posting images', button: {text: 'Use these images'}, library: {type: 'image'}, multiple: true});
            picker.on('open', () => {
                const selection = picker.state().get('selection');
                for (const id of document.getElementById('fb-image-id').value.split(',').filter(id => /^[1-9][0-9]*$/.test(id))) selection.add(wp.media.attachment(Number(id)));
            });
            picker.on('select', () => { saveImages(picker.state().get('selection').toJSON()); });
            picker.open();
        } catch (e) { error('Image picker: ' + e.message); }
    };
    const clearImage = document.getElementById('fb-image-clear');
    if (clearImage) clearImage.onclick = () => saveImages([]);
    document.querySelector('form[method="post"]')?.addEventListener('submit', event => {
        if (busy || running) { event.preventDefault(); error('Wait for the image save to finish and stop the campaign before saving the plan.'); }
    });
    connect();
    progress().catch(e => error(e.message));
})();
