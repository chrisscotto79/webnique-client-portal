(() => {
    if (new URL(location.href).searchParams.get('page') !== 'wnq-facebook-groups') return;
    window.addEventListener('message', event => {
        const data = event.data;
        if (event.source !== window || event.origin !== location.origin || data?.source !== 'wnq-facebook-page' ||
            typeof data.id !== 'string' || !['ping', 'login', 'publish'].includes(data.op)) return;
        chrome.runtime.sendMessage({op: data.op, job: data.job}).then(result => {
            window.postMessage({source: 'wnq-facebook-extension', id: data.id, ...result}, location.origin);
        }).catch(() => window.postMessage({source: 'wnq-facebook-extension', id: data.id, error: 'Extension disconnected. Reload it and this page; check any pending post before retrying.'}, location.origin));
    });
})();
