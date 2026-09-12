const vm = require('node:vm');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const status = {}, scanStatus = {}, events = {}, storage = new Map(), timers = [], calls = [];
const root = {dataset:{url:'https://fixture.invalid/ajax',nonce:'fixture'},querySelector:s=>s==='[data-progress]'?status:scanStatus};
let scanning = 0, active = 0, maxActive = 0;
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../assets/js/lead-ghl-auto.js'),'utf8'), {
    document:{getElementById:()=>root}, window:{addEventListener:(name,fn)=>events[name]=fn},
    sessionStorage:{getItem:k=>storage.get(k),setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},
    URLSearchParams, setTimeout:fn=>timers.push(fn),
    fetch:async (_url,args)=>{
        active++; maxActive=Math.max(maxActive,active); calls.push(args.body);
        const scan=args.body.get('operation')==='collect_backlog';
        const data=scan?{after:++scanning*100,upper:200,queued:2,done:scanning===2}:{sent:4,queued:2,processing:0,review:1,failed:0,held:0};
        active--; return {ok:true,status:200,json:async()=>({success:true,data})};
    }
});
(async()=>{
    const flush=()=>new Promise(resolve=>setImmediate(resolve));
    await flush();
    assert(status.textContent.includes('4 tagged'));
    events['wnq-search-start']();
    await timers.shift()(); await flush();
    assert(scanStatus.textContent.includes('2 newly queued'));
    await timers.shift()(); await flush();
    assert(scanStatus.textContent.includes('complete: 4 newly queued'));
    assert.equal(storage.size,0);
    assert.equal(maxActive,1);
    assert(calls.every(c=>c.get('action')==='wnq_ghl_drain'&&c.get('_ajax_nonce')==='fixture'));
    console.log('PASS: Find-page runner starts on search, scans multiple backlog chunks, reports progress and serializes transfers.');
})().catch(e=>{console.error(e);process.exitCode=1;});
