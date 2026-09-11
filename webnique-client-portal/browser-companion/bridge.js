/* No WordPress nonce or GHL credential enters extension storage. */
(() => {
  if (new URL(location.href).searchParams.get('page') !== 'wnq-lead-finder') return;
  const allowed = new Set(['HELLO','START','STATUS','STEP','ACK']);
  window.addEventListener('message', event => {
    const msg = event.data;
    if (event.source !== window || event.origin !== location.origin || msg?.channel !== 'wnq-leads-request'
      || !allowed.has(msg.action) || typeof msg.id !== 'string') return;
    try { chrome.runtime.sendMessage({action:msg.action, payload:msg.payload || {}}, response => {
      const error = chrome.runtime.lastError;
      window.postMessage({channel:'wnq-leads-response',id:msg.id,
        response:error ? {ok:false,error:'Chrome closed the companion message channel. Select Resume / retry to check the saved search. If the extension was reloaded, refresh this WordPress page once.'} : (response || {ok:false,error:'Companion returned no result. Select Resume / retry; saved leads are retained.'})}, location.origin);
    }); } catch (_) {
      window.postMessage({channel:'wnq-leads-response',id:msg.id,response:{ok:false,
        error:'Extension was reloaded or disconnected. Refresh this WordPress tab to reconnect.'}},location.origin);
    }
  });
})();
