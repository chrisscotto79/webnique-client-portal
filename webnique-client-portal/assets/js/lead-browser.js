(() => {
  const app = document.getElementById('lf-browser-app');
  if (!app) return;
  const byId = id => document.getElementById(id);
  const pending = new Map();
  let running = false;
  let looping = false;
  let preparing = false;
  let job = null;
  let recoveryTimer = null, recoveryAttempts = 0, recoveryEpoch = 0, recovering = false;
  function stopRecovery() {
    recoveryEpoch++;clearTimeout(recoveryTimer);recoveryTimer=null;recovering=false;
  }
  function retryError(message) {return Object.assign(new Error(message),{retryable:true});}
  function scheduleRecovery(error) {
    if (!error.retryable || recoveryAttempts >= 6) return false;
    const epoch=recoveryEpoch;
    const wait=Math.min(30000,5000*Math.pow(2,recoveryAttempts++));
    recovering=true;
    byId('lf-progress').textContent=`Temporary companion interruption. Automatic recovery ${recoveryAttempts}/6 in ${wait/1000}s. Saved leads are safe; Pause cancels recovery.`;
    log(`Automatic recovery ${recoveryAttempts}/6: checking saved state before continuing.`);
    recoveryTimer=setTimeout(async()=>{
      recoveryTimer=null;
      try {
        const status=await ask('STATUS');
        if(epoch!==recoveryEpoch)return;
        if(status.working)throw retryError('Companion still finishing its previous request.');
        const expected=bulk?.run || job?.runId;
        if(!status.job || (expected && status.job.runId!==expected))throw new Error('Saved browser job changed or is missing. Review the queue before resuming.');
        show(status.job);recovering=false;
        byId('lf-extension-status').textContent='Companion recovered · continuing the saved search.';
        await run(true);
      } catch(e) {
        if(epoch!==recoveryEpoch)return;
        if(!scheduleRecovery(e)){recovering=false;byId('lf-progress').textContent='Needs attention: '+e.message;log('Automatic recovery stopped. Review the error and use Resume when ready.');}
      } finally {controls();}
    },wait);
    return true;
  }
  const bulkKey = 'wnq-lead-bulk-' + (app.dataset.user || 'staff');
  let bulk = null, review = null;
  try { bulk = JSON.parse(sessionStorage.getItem(bulkKey)); } catch (_) {}
  function keepBulk() { sessionStorage.setItem(bulkKey,JSON.stringify(bulk)); }
  async function history(operation,values = {}) {
    const body = new FormData();
    Object.entries({action:'wnq_browser_search_history',nonce:app.dataset.nonce,operation,...values}).forEach(([k,v])=>body.append(k,v));
    const response = await fetch(ajaxurl,{method:'POST',body,credentials:'same-origin'});
    const data = await response.json();
    if (!data.success) throw new Error(data.data?.message || 'Search history unavailable. No next ZIP started.');
    return data.data;
  }
  function bulkLabel() {
    byId('lf-bulk-progress').textContent = bulk ? (bulk.index >= bulk.zips.length ? `Bulk search complete: ${bulk.zips.length} ZIPs processed.` :
      `ZIP ${bulk.index + 1} of ${bulk.zips.length}: ${bulk.zips[bulk.index]} · ${bulk.keyword}`) : '';
  }
  async function nextZip() {
    if (!bulk || bulk.index >= bulk.zips.length) return false;
    if (!bulk.run) { bulk.run = crypto.randomUUID(); keepBulk(); }
    await history('begin',{run:bulk.run,keyword:bulk.keyword,zip:bulk.zips[bulk.index]});
    if(!running)return false;
    const next = (await ask('START',{keyword:bulk.keyword,zip:bulk.zips[bulk.index],runId:bulk.run,replace:true})).job;
    if (next?.runId !== bulk.run) throw new Error('Reload Chrome companion version 1.0.5 or newer before using automatic recovery.');
    bulk.started = true; keepBulk(); show(next); bulkLabel(); return true;
  }
  window.addEventListener('message', event => {
    if (event.source !== window || event.origin !== location.origin || event.data?.channel !== 'wnq-leads-response') return;
    const request = pending.get(event.data.id);
    if (!request) return;
    pending.delete(event.data.id); clearTimeout(request.timer); clearInterval(request.retry);
    event.data.response?.ok ? request.resolve(event.data.response) : request.reject(Object.assign(new Error(event.data.response?.error || 'Companion unavailable.'),{retryable:event.data.response?.retryable===true}));
  });
  function ask(action,payload = {}) {
    return new Promise((resolve,reject) => {
      const id = crypto.randomUUID();
      const send = () => window.postMessage({channel:'wnq-leads-request',id,action,payload},location.origin);
      // Only the read-only handshake is replayed. Never retry START, STEP or ACK here.
      const retry = action === 'HELLO' ? setInterval(send,500) : null;
      const timer = setTimeout(() => {
        pending.delete(id);clearInterval(retry);
        byId('lf-extension-status').textContent='Companion response delayed — connection needs checking.';
        reject(Object.assign(new Error(`${action} response timed out. Saved leads and the bulk queue are retained.`),{retryable:['STEP','ACK','STATUS','HELLO'].includes(action)}));
      }, action==='HELLO' ? 20000 : 45000);
      pending.set(id,{resolve,reject,timer,retry});
      send();
    });
  }
  function show(next) {
    job = next;
    if (!job) return;
    if (!review) { byId('lf-niche').value = bulk?.keyword || job.keyword; byId('lf-postcode').value = bulk ? bulk.zips.join(', ') : job.zip; }
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
    byId('lf-start').disabled = looping || preparing || recovering; byId('lf-resume').disabled = looping || preparing || recovering; byId('lf-pause').disabled = !running && !recovering;
    byId('lf-bulk-start').disabled = looping || preparing || recovering;
  }
  async function run(isRecovery = false) {
    if (looping) return;
    if(!isRecovery){stopRecovery();recoveryAttempts=0;}
    running = true; looping = true; controls();
    try {
      await safeCompanion();
      if(!running)return;
      if (bulk && bulk.index < bulk.zips.length && !bulk.started) await nextZip();
      if (bulk && bulk.index < bulk.zips.length && job?.runId !== bulk.run) throw new Error('The browser search does not match this saved bulk queue. Check ZIPs again to start a new batch.');
      while (running) {
        let delay = 1800; // Keep Maps polling/scroll pacing unchanged.
        const previousPhase = job?.phase;
        show((await ask('STEP')).job);
        if (previousPhase === 'collect' && job.phase === 'details') delay = 200;
        if (job.pending) {
          const row = job.pending;
          byId('lf-progress').textContent = `Checking ${row.name}'s website for email and saving the lead… No additional Chrome tabs are opened.`;
          const saveStarted = performance.now();
          const receiptKey = 'wnq-lead-receipt-' + job.runId;
          let receipt;
          try { receipt = JSON.parse(sessionStorage.getItem(receiptKey)); } catch (_) {}
          const saved = receipt?.key === mapsKey(row.maps_url) ? receipt.result : await save(row);
          // Preserve the confirmed outcome if the extension acknowledgement is interrupted.
          sessionStorage.setItem(receiptKey,JSON.stringify({key:mapsKey(row.maps_url),result:saved}));
          show((await ask('ACK',{maps_key:mapsKey(row.maps_url),outcome:saved.outcome,has_email:!!saved.email})).job);
          sessionStorage.removeItem(receiptKey);
          log(`${saved.name}: ${saved.message} · Save/check ${Math.round((performance.now()-saveStarted)/1000)}s`);
          delay = 200; // Receipt is saved and ACK completed; no load is pending.
          recoveryAttempts=0;
        }
        if (job.phase === 'done') {
          if (bulk && bulk.index < bulk.zips.length) {
            await history('finish',{run:bulk.run,stats:JSON.stringify({found:job.found,saved:job.stats.saved,emails:job.stats.email,duplicates:job.stats.duplicate,limited:job.note !== 'Google reported the end of the list.'})});
            log(`${job.keyword} in ${job.zip}: search history saved.`);
            bulk.index++;bulk.run=null;bulk.started=false;keepBulk();bulkLabel();
            if (running && bulk.index < bulk.zips.length) {await nextZip();continue;}
          }
          break;
        }
        await new Promise(resolve => setTimeout(resolve,delay));
      }
      if (!running && job?.phase !== 'done') byId('lf-progress').textContent = 'Paused. Saved leads are safe; Resume continues this search.';
    } catch (error) {
      if(!running || !scheduleRecovery(error)){byId('lf-progress').textContent = 'Needs attention: ' + error.message;log('Needs attention: ' + error.message);}
    }
    finally { running = false; looping = false; controls(); }
  }
  byId('lf-search-form').addEventListener('submit',async event => {
    event.preventDefault(); if (looping || preparing) return;
    preparing = true; controls();
    try {
      review = await history('check',{keyword:byId('lf-niche').value.trim(),zips:byId('lf-postcode').value.trim()});
      const items = byId('lf-zip-items');items.replaceChildren();
      for (const item of review.items) {
        const label=document.createElement('label');label.style.cssText='display:block;padding:10px;border-bottom:1px solid #e2e8f0';
        const box=document.createElement('input');box.type='checkbox';box.value=item.zip;box.checked=!item.previous;
        const p=item.previous;
        const status=p ? (p.status==='completed' ? `Completed ${p.finished_at} UTC · ${p.saved} new leads${p.coverage==='limited'?' · limited coverage':''}` : p.status==='legacy' ? `Previously imported ${p.started_at} · completion unknown` : `Started ${p.started_at} UTC · not recorded complete`) : 'Not previously searched';
        label.append(box,document.createTextNode(` ${item.zip} — ${status}`));items.append(label);
      }
      byId('lf-zip-review').hidden=false;
      byId('lf-progress').textContent='Review ZIP history, then start the selected ZIPs. Nothing has started yet.';
    } catch (error) {review=null;byId('lf-zip-review').hidden=true;byId('lf-progress').textContent=error.message;}
    finally {preparing=false;controls();}
  });
  byId('lf-bulk-start').addEventListener('click',async()=>{
    if(looping||preparing||!review)return;
    const zips=[...byId('lf-zip-items').querySelectorAll('input:checked')].map(e=>e.value);
    if(!zips.length){byId('lf-progress').textContent='Select at least one ZIP. Previously searched ZIPs are skipped unless selected.';return;}
    if ((job && job.phase!=='done') && !confirm('Replace the unfinished search? Saved leads and search history remain.')) return;
    preparing=true;controls();
    try {
      await safeCompanion();
      bulk={keyword:review.keyword,zips,index:0,run:null,started:false};keepBulk();review=null;byId('lf-zip-review').hidden=true;bulkLabel();await run();
    } catch(error){byId('lf-progress').textContent=error.message;}
    finally {preparing=false;controls();}
  });
  byId('lf-pause').addEventListener('click',() => {stopRecovery();running = false;controls();byId('lf-progress').textContent = 'Paused by you. Any in-flight save will finish; automatic recovery is off until Resume.';});
  byId('lf-resume').addEventListener('click',async () => {
    try {
      const status=await ask('STATUS');
      if(status.working){byId('lf-progress').textContent='The companion is still finishing the last request. Wait a moment, then Resume; no duplicate search has started.';return;}
      show(status.job);
      if (job || (bulk && bulk.index<bulk.zips.length)) await run(); else byId('lf-progress').textContent = 'No saved browser search. Enter a keyword and ZIP.';
    }
    catch (error) {byId('lf-progress').textContent = error.message;}
  });
  async function safeCompanion() {
    const hello=await ask('HELLO');
    const v=(hello.version||'0').split('.').map(Number);
    if(!(v[0]>1 || (v[0]===1 && (v[1]>0 || v[2]>=5))))throw new Error('Reload Chrome companion 1.0.5 or newer at chrome://extensions, then refresh WordPress once. This version supports automatic recovery and the one-tab limit.');
  }
  let connecting = false;
  async function connect() {
    if (connecting || looping) return;
    connecting = true;
    byId('lf-reconnect').disabled = true;
    byId('lf-extension-status').textContent = 'Checking Chrome companion…';
    try {
      const hello=await ask('HELLO');
      byId('lf-extension-status').textContent = 'Chrome companion connected' + (hello.version ? ' · v'+hello.version : '') + ' · no paid Maps API';
      byId('lf-setup').open = false;
      try {
        show((await ask('STATUS')).job);
        bulkLabel();
        if (job && job.phase !== 'done') byId('lf-progress').textContent = 'Unfinished search found. Select Resume to continue.';
      } catch (error) { byId('lf-progress').textContent = 'Companion connected, but search status could not be read: ' + error.message; }
    } catch (error) {
      byId('lf-extension-status').textContent = error.message;
      byId('lf-setup').open = true;
    } finally { connecting = false; byId('lf-reconnect').disabled = false; }
  }
  byId('lf-reconnect').addEventListener('click',connect);
  connect();
})();
