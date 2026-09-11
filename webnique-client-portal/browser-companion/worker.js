importScripts('collector.js');
const busy = new Set();
function authorized(sender) {
  try {
    const u = new URL(sender.url);
    return sender.frameId === 0 && Number.isInteger(sender.tab?.id) && u.protocol === 'https:'
      && ['goldenwebmarketing.com','www.goldenwebmarketing.com'].includes(u.hostname)
      && u.pathname === '/wp-admin/admin.php' && u.searchParams.get('page') === 'wnq-lead-finder';
  } catch (_) { return false; }
}
function mapsKey(value) {
  try {
    const u = new URL(value);
    if (u.origin !== 'https://www.google.com' || !u.pathname.startsWith('/maps/place/')) return '';
    return decodeURIComponent((u.pathname.match(/!1s([^!\/]+)/) || [])[1] || u.pathname);
  } catch (_) { return ''; }
}
const put = (key, job) => chrome.storage.session.set({[key]:job});
// Only IDs created by this companion are eligible for cleanup. Never close a
// tab that the operator has since navigated away from Maps.
async function closeOwned(job) {
  for (const id of new Set([job?.mapsId, job?.detailId].filter(Number.isInteger))) {
    const tab = await chrome.tabs.get(id).catch(() => null);
    if (tab?.url?.startsWith('https://www.google.com/maps/')) await chrome.tabs.remove(id);
  }
}
async function saveJob(key, job) {
  await put(key,job); // Persist the receipt before closing tabs.
  if (job.phase === 'done') {
    await closeOwned(job);
    const owner = (await chrome.storage.session.get('wnq_active_owner')).wnq_active_owner;
    if (key === 'wnq_' + owner) await chrome.storage.session.set({wnq_active_owner:null});
  }
}
function summary(job) {
  if (!job) return {ok:true,job:null};
  return {ok:true,job:{runId:job.runId,keyword:job.keyword,zip:job.zip,phase:job.phase,found:job.rows.length,index:job.index,
    stats:job.stats,note:job.note || '',pending:job.pending || null}};
}
async function snapshot(id, mode) {
  const tab = await chrome.tabs.get(id);
  if (tab.status !== 'complete') return {ready:false};
  if (!tab.url?.startsWith('https://www.google.com/maps/')) throw new Error('Maps tab was closed, redirected or navigated away. Check the tab before resuming.');
  // A delayed injection must not hold the worker's collection lock indefinitely.
  // Only this read/scroll operation is bounded; never race storage or tab writes.
  let timer;
  let result;
  try {
    result = await Promise.race([
      chrome.scripting.executeScript({target:{tabId:id},injectImmediately:true,func:collectMapsPage,args:[mode]}),
      new Promise(resolve => { timer=setTimeout(()=>resolve(null),8000); })
    ]);
  } finally { clearTimeout(timer); }
  if (!result) return {ready:false};
  const data = result?.[0]?.result;
  if (!data) throw new Error('Maps could not be read. Open its tab and retry.');
  if (data.blocked) throw new Error(data.error);
  return data;
}
async function handle(msg, sender) {
  const key = 'wnq_' + sender.tab.id;
  if (msg.action === 'HELLO') return {ok:true,version:'1.0.4',working:busy.size > 0};
  if (msg.action === 'STATUS') return {...summary((await chrome.storage.session.get(key))[key]),working:busy.size > 0};
  if (busy.size) return {ok:false,error:'A collection step is still running. Wait a moment, then resume.'};
  busy.add(key);
  try {
    let job = (await chrome.storage.session.get(key))[key];
    if (msg.action === 'STATUS') return summary(job);
    const p = msg.payload || {};
    if (msg.action === 'START') {
      if (p.runId && !/^[a-f0-9-]{36}$/.test(p.runId)) throw new Error('Invalid bulk run identifier.');
      if (!/^[0-9]{5}$/.test(p.zip || '') || typeof p.keyword !== 'string' || !p.keyword.trim() || p.keyword.length > 100) throw new Error('Enter a keyword and five-digit ZIP.');
      if (p.runId && job?.runId === p.runId) {
        if (job.keyword !== p.keyword.trim() || job.zip !== p.zip) throw new Error('Run identity mismatch.');
        return summary(job);
      }
      if (job && job.phase !== 'done' && !p.replace) throw new Error('Resume the existing search or confirm starting a new one.');
      const owner = (await chrome.storage.session.get('wnq_active_owner')).wnq_active_owner;
      if (owner && owner !== sender.tab.id) {
        const portal = await chrome.tabs.get(owner).catch(() => null);
        if (portal) return {ok:false,error:'Another Lead Finder tab owns a search. Finish that search or close its WordPress tab before starting here.'};
        await closeOwned((await chrome.storage.session.get('wnq_' + owner))['wnq_' + owner]);
      }
      await closeOwned(job); // Also cleans up the previous version's second tab.
      await chrome.storage.session.set({wnq_active_owner:sender.tab.id});
      const tab = await chrome.tabs.create({url:'https://www.google.com/maps/search/' + encodeURIComponent(p.keyword.trim() + ' in ' + p.zip) + '?hl=en',active:false});
      job = {runId:p.runId || crypto.randomUUID(),keyword:p.keyword.trim(),zip:p.zip,phase:'collect',mapsId:tab.id,rows:[],round:0,stable:0,index:0,waits:0,
        stats:{saved:0,email:0,duplicate:0}};
      await saveJob(key,job); return summary(job);
    }
    if (!job) throw new Error('No search in this Chrome tab. Start a new search.');
    const owner = (await chrome.storage.session.get('wnq_active_owner')).wnq_active_owner;
    if (job.phase !== 'done' && owner && owner !== sender.tab.id) return {ok:false,error:'Another Lead Finder tab owns the active batch. Resume there instead.'};
    if (job.phase !== 'done' && !owner) await chrome.storage.session.set({wnq_active_owner:sender.tab.id});
    if (msg.action === 'ACK') {
      if (!job.pending || p.maps_key !== mapsKey(job.pending.maps_url)) throw new Error('Listing acknowledgement mismatch; resume to reconcile.');
      if (!['saved','duplicate'].includes(p.outcome)) throw new Error('Listing must be saved before proceeding.');
      job.stats[p.outcome]++;
      if (p.outcome === 'saved' && p.has_email) job.stats.email++;
      job.pending = null; job.index++; job.waits = 0;
      if (job.index >= job.rows.length) job.phase = 'done';
      await saveJob(key,job); return summary(job);
    }
    if (msg.action !== 'STEP') throw new Error('Unknown collection operation.');
    if (job.phase === 'done') { await saveJob(key,job); return summary(job); }
    if (job.pending) return summary(job);
    if (job.phase === 'collect') {
      const data = await snapshot(job.mapsId, 'search');
      if (!data.ready) {
        job.waits++; await put(key,job);
        if (job.waits >= 15) throw new Error('Maps results have not loaded. Open the Maps tab, check any prompts, then resume.');
        return summary(job);
      }
      job.waits = 0;
      const known = new Set(job.rows.map(r => mapsKey(r.maps_url)));
      let added = 0;
      for (const row of data.rows) {
        const id = mapsKey(row.maps_url);
        if (!id || known.has(id) || job.rows.length >= 100) continue;
        known.add(id); job.rows.push(row); added++;
      }
      job.round++; job.stable = added ? 0 : job.stable + 1;
      if (data.end || job.rows.length >= 100 || job.stable >= 8 || job.round >= 80) {
        job.note = data.end ? 'Google reported the end of the list.' : 'Collection limit or no new listings reached; results may be incomplete.';
        job.phase = job.rows.length ? 'details' : 'done';
      }
      await saveJob(key,job); return summary(job);
    }
    const row = job.rows[job.index];
    if (job.detailFor !== job.index) {
      // Search results are already saved in session storage. Reuse that same
      // tab for every listing instead of opening another heavy Maps page.
      const tab = await chrome.tabs.get(job.mapsId).catch(() => null);
      if (!tab?.url?.startsWith('https://www.google.com/maps/')) throw new Error('Owned Maps tab unavailable; start a replacement search.');
      if (job.detailId && job.detailId !== job.mapsId) await closeOwned({detailId:job.detailId});
      job.detailId = job.mapsId;
      await chrome.tabs.update(job.detailId,{url:row.maps_url});
      job.detailFor = job.index; job.waits = 0; await put(key,job); return summary(job);
    }
    const data = await snapshot(job.detailId,'detail');
    if (!data.ready) {
      job.waits++; await put(key,job);
      if (job.waits < 15) return summary(job);
      job.pending = {...row,detail_warning:true};
    } else {
      // Never combine evidence from an unexpected listing with this business.
      if (mapsKey(data.row.maps_url) !== mapsKey(row.maps_url)) {
        throw new Error('Maps opened a different listing. Check its tab; the business has not been saved.');
      }
      if (data.row.name.trim().toLowerCase() !== row.name.trim().toLowerCase()) {
        throw new Error('Listing name has not matched the search result. Wait for the detail page, then resume.');
      }
      job.pending = {...row,...data.row,maps_url:row.maps_url};
      for (const field of ['website','phone']) if (!job.pending[field]) job.pending[field] = row[field] || '';
    }
    await put(key,job); return summary(job);
  } finally { busy.delete(key); }
}
chrome.runtime.onMessage.addListener((msg,sender,reply) => {
  if (!authorized(sender) || !['HELLO','START','STATUS','STEP','ACK'].includes(msg?.action)) return false;
  handle(msg,sender).then(reply).catch(() => reply({ok:false,error:'Collection paused. Open the Maps tab to check prompts or page changes, then resume. No unsaved listing was acknowledged.'}));
  return true;
});
