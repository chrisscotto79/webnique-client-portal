(() => {
    if (new URL(location.href).searchParams.get('page') !== 'wnq-facebook-groups') return;
    let invalidated = false;
    window.addEventListener('message', async event => {
        const data = event.data;
        if (event.source !== window || event.origin !== location.origin || data?.source !== 'wnq-facebook-page' ||
            typeof data.id !== 'string' || !['ping', 'login', 'publish', 'cancel'].includes(data.op)) return;
        const reply = result => window.postMessage({source: 'wnq-facebook-extension', id: data.id, ...result}, location.origin);
        try {
            // Reloading an unpacked extension invalidates scripts in already-open
            // pages. sendMessage can throw BEFORE returning a Promise.
            if (invalidated || !chrome.runtime?.id) throw new Error('Extension context invalidated');
            const result = await chrome.runtime.sendMessage({op: data.op, job: data.job, client: data.client, clientName: data.clientName});
            if (!result || (!result.result && !result.error)) throw new Error('Companion returned no response');
            reply(result);
        } catch (error) {
            invalidated = invalidated || /context invalidated/i.test(error?.message || '');
            reply({error: invalidated
                ? 'The extension was reloaded or updated. Refresh this WordPress tab, then Check connection. Do not reload the extension again first. Check any pending Facebook post before resuming.'
                : 'Facebook companion is not responding. Check that it is enabled, then refresh this WordPress tab. Check any pending Facebook post before resuming.'});
        }
    });
})();
