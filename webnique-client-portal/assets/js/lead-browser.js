(() => {
  const app = document.getElementById('lf-browser-app');
  if (!app) return;
  const byId = id => document.getElementById(id);
  const pending = new Map();
  let running = false;
  let looping = false;
  let job = null;
  window.addEventListener('message', event => {
    if (event.source !== window || event.origin !== location.origin || event.data?.channel !== 'wnq-leads-response') return;
    const request = pending.get(event.data.id);
    if (!request) return;
    pending.delete(event.data.id); clearTimeout(request.timer);
    event.data.response?.ok ? request.resolve(event.data.response) : request.reject(new Error(event.data.response?.error || 'Companion unavailable.'));
  });
  function ask(action,payload = {}) {
    return new Promise((resolve,reject) => {
      const id = crypto.randomUUID();
      const timer = setTimeout(() => {pending.delete(id);reject(new Error('Chrome companion did not respond. Check setup, then refresh this page.'));},20000);
      pending.set(id,{resolve,reject,timer});
      window.postMessage({channel:'wnq-leads-request',id,action,payload},location.origin);
    });
  }
  function show(next) {
    job = next;
    if (!job) return;
    byId('lf-niche').value = job.keyword; byId('lf-postcode').value = job.zip;
    for (const [key,value] of Object.entries({found:job.found,...job.stats})) byId('lf-count-'+key).textContent = value;
    byId('lf-progress').textContent = job.phase === 'done' ? 'Search complete. ' + job.note :
      job.phase === 'collect' ? `Collecting Maps listings for ${job.keyword} in ${job.zip}…` :
      `Checking listing ${job.index + 1} of ${job.found}, then its website for email…`;
  }
  function log(message) {
    const li = document.createElement('li'); li.textContent = message;
    byId('lf-activity').prepend(li);
    while (byId('lf-activity').children.length > 15) byId('lf-activity').lastChild.remove();
  }
  async function save(row) {
    const body = new FormData();
    Object.entries({action:'wnq_browser_lead_save',nonce:app.dataset.nonce,row:JSON.stringify(row),keyword:job.keyword,zip:job.zip}).forEach(([k,v]) => body.append(k,v));
    const response = await fetch(ajaxurl,{method:'POST',body,credentials:'same-origin'});
    const data = await response.json();
    if (!data.success) throw new Error(data.data?.message || 'WordPress could not save this listing. Resume to retry.');
    return data.data;
  }
  function mapsKey(value) {const u = new URL(value);return decodeURIComponent((u.pathname.match(/!1s([^!\/]+)/) || [])[1] || u.pathname);}
  function controls() {
    byId('lf-start').disabled = looping; byId('lf-resume').disabled = looping; byId('lf-pause').disabled = !running;
  }
  async function run() {
    if (looping) return;
    running = true; looping = true; controls();
    try {
      while (running) {
        show((await ask('STEP')).job);
        if (job.pending) {
          const row = job.pending;
          const receiptKey = 'wnq-lead-receipt-' + job.runId;
          let receipt;
          try { receipt = JSON.parse(sessionStorage.getItem(receiptKey)); } catch (_) {}
          const saved = receipt?.key === mapsKey(row.maps_url) ? receipt.result : await save(row);
          // Preserve the confirmed outcome if the extension acknowledgement is interrupted.
          sessionStorage.setItem(receiptKey,JSON.stringify({key:mapsKey(row.maps_url),result:saved}));
          show((await ask('ACK',{maps_key:mapsKey(row.maps_url),outcome:saved.outcome,has_email:!!saved.email})).job);
          sessionStorage.removeItem(receiptKey);
          log(`${saved.name}: ${saved.message}`);
        }
        if (job.phase === 'done') break;
        await new Promise(resolve => setTimeout(resolve,1800));
      }
      if (!running && job?.phase !== 'done') byId('lf-progress').textContent = 'Paused. Saved leads are safe; Resume continues this search.';
    } catch (error) { byId('lf-progress').textContent = error.message; log('Paused: ' + error.message); }
    finally { running = false; looping = false; controls(); }
  }
  byId('lf-search-form').addEventListener('submit',async event => {
    event.preventDefault(); if (looping) return;
    const replace = job && job.phase !== 'done';
    if (replace && !confirm('Start a different search? Saved leads remain, but the unfinished search will be replaced.')) return;
    byId('lf-start').disabled = true;
    try { show((await ask('START',{keyword:byId('lf-niche').value.trim(),zip:byId('lf-postcode').value.trim(),replace:!!replace})).job); await run(); }
    catch (error) { byId('lf-progress').textContent = error.message; controls(); }
  });
  byId('lf-pause').addEventListener('click',() => {running = false;controls();byId('lf-progress').textContent = 'Pausing after the current request…';});
  byId('lf-resume').addEventListener('click',async () => {
    try { show((await ask('STATUS')).job); if (job) await run(); else byId('lf-progress').textContent = 'No saved browser search. Enter a keyword and ZIP.'; }
    catch (error) {byId('lf-progress').textContent = error.message;}
  });
  ask('HELLO').then(async () => {
    byId('lf-extension-status').textContent = 'Chrome companion connected · no paid Maps API';
    show((await ask('STATUS')).job);
    if (job && job.phase !== 'done') byId('lf-progress').textContent = 'Unfinished search found. Select Resume to continue.';
  }).catch(() => {byId('lf-extension-status').textContent = 'Install the Chrome companion once to start collecting.';byId('lf-setup').open = true;});
})();
