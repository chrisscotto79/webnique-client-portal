const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const puppeteer = require('puppeteer');
const root = path.join(__dirname,'..');
const maps = 'https://www.google.com/maps/place/Business/data=!1sabc:123';
const row = {name:'Business',maps_url:maps,website:'https://business.example',phone:'407-555-0123'};
let checks = 0;
function check(value,label) {assert(value,label);checks++;}
async function workerTests() {
  const storage = {}, tabs = new Map(); let listener, injected = 0, nextId = 0, peak = 0, stalled = false;
  const sender = {frameId:0,tab:{id:20},url:'https://goldenwebmarketing.com/wp-admin/admin.php?page=wnq-lead-finder'};
  const chrome = {
    storage:{session:{get:async key => ({[key]:structuredClone(storage[key])}),set:async obj => Object.assign(storage,structuredClone(obj))}},
    tabs:{create:async options => {const t={id:++nextId,status:'complete',...options};tabs.set(t.id,t);peak=Math.max(peak,tabs.size);return t;},
      remove:async id => {tabs.delete(id);},
      get:async id => {if(!tabs.has(id)) throw Error('closed');return tabs.get(id);},update:async(id,data) => Object.assign(tabs.get(id),data)},
    scripting:{executeScript:async options => {injected++;check(options.injectImmediately===true,'Maps read does not wait for document idle');if(stalled)return new Promise(()=>{});return [{result:options.args[0]==='search'
      ? {ready:true,rows:[row,row],end:true} : {ready:true,row}}];}},
    runtime:{onMessage:{addListener:fn => {listener=fn;}}}
  };
  const boot = () => {
    const ctx = vm.createContext({chrome,URL,Set,setTimeout:(fn,ms)=>setTimeout(fn,ms===8000?30:ms),clearTimeout,crypto:require('node:crypto').webcrypto,console});
    ctx.importScripts = name => vm.runInContext(fs.readFileSync(path.join(root,'browser-companion',name),'utf8'),ctx);
    vm.runInContext(fs.readFileSync(path.join(root,'browser-companion/worker.js'),'utf8'),ctx);
  };
  boot();
  const call = (action,payload={}) => new Promise(resolve => listener({action,payload},sender,resolve));
  check(listener({action:'START',payload:{}},{...sender,url:'https://evil.example/'},()=>{})===false,'Other origins rejected');
  check(listener({action:'START',payload:{}},{...sender,frameId:2},()=>{})===false,'Subframes rejected');
  check(!(await call('START',{keyword:'Plumbers',zip:'bad'})).ok,'ZIP validated inside extension');
  let r=await call('START',{keyword:'Plumbers',zip:'32825'});
  check(r.ok && tabs.get(1).url.includes('Plumbers%20in%2032825'),'Search uses exact keyword and ZIP');
  check(!(await call('START',{keyword:'Other',zip:'32825'})).ok,'Unfinished job cannot be silently replaced');
  r=await call('STEP');check(r.job.found===1,'Repeated Maps cards deduplicated');
  await call('STEP');r=await call('STEP');check(r.job.pending.name==='Business','Detail listing ready for WordPress');
  const before=injected;await call('STEP');check(injected===before,'Pending result never advanced before save acknowledgement');
  boot();r=await call('STATUS');check(r.job.pending.name==='Business','Worker restart preserves pending result');
  check(!(await call('ACK',{maps_key:'wrong',outcome:'saved'})).ok,'Incorrect acknowledgement rejected');
  r=await call('ACK',{maps_key:'abc:123',outcome:'saved',has_email:true});
  check(r.job.phase==='done' && r.job.stats.saved===1 && r.job.stats.email===1,'Save acknowledgement advances once');
  check(!(await call('ACK',{maps_key:'abc:123',outcome:'saved'})).ok,'Repeated acknowledgement cannot double-count');
  check(tabs.size===0,'Completed ZIP closes its owned Maps tab');
  for(let i=0;i<100;i++) {
    await call('START',{keyword:'Plumbers',zip:String(32000+i)});
    await call('STEP');await call('STEP');await call('STEP');
    const result=await call('ACK',{maps_key:'abc:123',outcome:'saved'});
    check(result.job.phase==='done' && tabs.size===0,'Bulk ZIP cleanup '+i);
  }
  check(peak===1,'100 ZIP stress run never exceeds one Maps tab');
  await call('START',{keyword:'Plumbers',zip:'32825'});
  const other={...sender,tab:{id:99}}; tabs.set(20,{id:20,url:sender.url});
  const blocked=await new Promise(resolve=>listener({action:'START',payload:{keyword:'Other',zip:'32826'}},other,resolve));
  check(!blocked.ok,'Second portal cannot start concurrent batch');
  tabs.delete(20);
  await call('START',{keyword:'Plumbers',zip:'32826',replace:true});
  check(tabs.size===1,'Replacing unfinished ZIP closes prior owned tab');
  stalled=true;
  const step=call('STEP');
  await new Promise(resolve=>setTimeout(resolve,5));
  check((await call('STATUS')).working===true,'STATUS remains available during hung Maps read');
  check((await step).ok,'Stalled read returns not-ready instead of permanent lock');
  check((await call('STATUS')).working===false,'Read timeout releases collection lock');
  stalled=false;
  check((await call('STEP')).job.found===1,'Next step recovers without reloading extension');
}
async function browserTests() {
  const browser = await puppeteer.launch({headless:true});
  try {
    const page = await browser.newPage();
    // Actual collector running against an offline Maps DOM, never Google itself.
    const collector = fs.readFileSync(path.join(root,'browser-companion/collector.js'),'utf8');
    await page.setContent(`<div role="feed"><div role="article"><a href="${maps}" aria-label="Business"></a><span class="fontHeadlineSmall">Business</span><span role="img" aria-label="4.8 stars 321 reviews"></span><a aria-label="Website" href="https://business.example">Website</a><p>407-555-0123</p><p>You've reached the end of the list.</p></div></div>`);
    await page.addScriptTag({content:collector});
    let result=await page.evaluate(() => collectMapsPage('search'));
    check(result.rows[0].phone==='407-555-0123' && result.rows[0].reviews===321,'Collector reads listing fields');
    check(result.rows[0].website==='https://business.example/','Only website control used');
    await page.setContent('<p>Our systems have detected unusual traffic</p>');
    check((await page.evaluate(() => collectMapsPage('search'))).blocked,'Google prompt detected without bypass');
    const html = execFileSync('php',[path.join(__dirname,'lead-browser-regression.php'),'--render'],{encoding:'utf8'});
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.setRequestInterception(true);
    let saves=0;
    page.on('request',request => {
      const u=new URL(request.url());
      if(u.pathname.endsWith('admin.php')) return request.respond({contentType:'text/html',body:html});
      if(u.pathname.endsWith('lead-browser.js')) return request.respond({contentType:'application/javascript',body:fs.readFileSync(path.join(root,'assets/js/lead-browser.js'),'utf8')});
      if(u.pathname.endsWith('lead-browser.css')) return request.respond({contentType:'text/css',body:fs.readFileSync(path.join(root,'assets/css/lead-browser.css'),'utf8')});
      if(u.pathname.endsWith('admin-ajax.php')) {
        const body=request.postData()||'';
        if(body.includes('wnq_browser_search_history')) {
          const field=name=>(body.match(new RegExp('name="'+name+'"\\r\\n\\r\\n([^\\r]*)'))||[])[1]||'';
          const items=[...new Set(field('zips').split(/[\s,;]+/).filter(Boolean))].map(zip=>({zip,previous:zip==='32825'&&saves>0?{status:'completed',finished_at:'2026-09-10 12:00:00',saved:1}:null}));
          return request.respond({contentType:'application/json',body:JSON.stringify({success:true,data:field('operation')==='check'?{keyword:field('keyword').toLowerCase(),items}:{}})});
        }
        saves++;return request.respond({contentType:'application/json',body:JSON.stringify({success:true,data:{name:'Business',outcome:'saved',email:'info@business.example',message:'Website email found.'}})});
      }
      return request.abort();
    });
    await page.evaluateOnNewDocument(row => {
      window.ajaxurl='/wp-admin/admin-ajax.php';let job=null, ackFail=true;
      window.pacing=[];
      const nativeTimeout=window.setTimeout;
      window.setTimeout=(fn,ms,...args)=>{
        if(window.recoveryTest && [45000,5000,10000,20000,30000].includes(ms))return nativeTimeout(fn,window.pauseTest && ms===5000?500:25,...args);
        if(window.speedTest && [200,1800].includes(ms)){window.pacing.push(ms);return nativeTimeout(fn,1,...args);}return nativeTimeout(fn,ms,...args);
      };
      window.addEventListener('message',event => {
        const m=event.data;if(m?.channel!=='wnq-leads-request') return;
        if((window.dropStep || window.dropAllSteps) && m.action==='STEP'){window.dropStep=false;return;}
        let response={ok:true,version:'1.0.5'};
        if(m.action==='START') job={runId:m.payload.runId,keyword:m.payload.keyword,zip:m.payload.zip,phase:'details',found:1,index:0,stats:{saved:0,email:0,duplicate:0},pending:row};
        if(window.speedTest && m.action==='STEP' && job?.index===1 && !job.pending){
          if(job.waited)job.pending={...row,maps_url:row.maps_url+'second'};
          else job.waited=true;
        }
        if(m.action==='ACK') {
          if(ackFail) {ackFail=false;response={ok:false,error:'Simulated interrupted acknowledgement. Resume to reconcile.'};}
          else if(window.speedTest && job.index===0){job={...job,index:1,found:2,pending:null};}
          else {job={...job,phase:'done',pending:null,index:1,stats:{saved:1,email:1,duplicate:0},note:'End of fixture list.'};}
          if(window.dropAck){window.dropAck=false;return;}
        }
        response.job=job;
        window.postMessage({channel:'wnq-leads-response',id:m.id,response},location.origin);
      });
    },row);
    const url='https://goldenwebmarketing.com/wp-admin/admin.php?page=wnq-lead-finder';
    for(const width of [1280,390]) {
      await page.setViewport({width,height:1000});await page.goto(url);
      await page.waitForFunction(() => document.getElementById('lf-extension-status').textContent.includes('connected'));
      check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Responsive layout at '+width);
      if(process.env.WNQ_UI_OUTPUT) await page.screenshot({path:path.join(process.env.WNQ_UI_OUTPUT,`lead-finder-${width}.png`),fullPage:true});
    }
    await page.type('#lf-niche','Plumbers');await page.type('#lf-postcode','32825');await page.click('#lf-start');
    await page.waitForSelector('#lf-zip-review:not([hidden])');
    check(saves===0,'History review happens before scraping');
    await page.click('#lf-bulk-start');
    await page.waitForFunction(()=>document.getElementById('lf-progress').textContent.includes('Simulated'));
    check(saves===1,'Listing saved before acknowledgement');await page.click('#lf-resume');
    await page.waitForFunction(()=>document.getElementById('lf-progress').textContent.includes('Search complete'));
    check(saves===1,'Resume reuses saved receipt, not another website request');
    check(await page.$eval('#lf-count-saved',e=>e.textContent)==='1','Saved counter preserved across interrupted acknowledgement');
    check(await page.$eval('#lf-count-email',e=>e.textContent)==='1','Email counter reflects saved result');
    await page.$eval('#lf-postcode',e=>e.value='32825,32826,32826,32828');await page.click('#lf-start');
    await page.waitForSelector('#lf-zip-review:not([hidden])');
    check(await page.$$eval('#lf-zip-items input',els=>els.length)===3,'Bulk ZIP duplicates removed');
    check(await page.$eval('#lf-zip-items input[value="32825"]',e=>!e.checked),'Previously searched ZIP unchecked');
    await page.click('#lf-bulk-start');
    await page.waitForFunction(()=>document.getElementById('lf-bulk-progress').textContent.includes('2 ZIPs processed'));
    check(saves===3,'Two new ZIPs processed sequentially; prior ZIP skipped');
    await page.evaluate(()=>window.speedTest=true);
    await page.$eval('#lf-postcode',e=>e.value='32830');await page.click('#lf-start');
    await page.waitForSelector('#lf-zip-review:not([hidden])');await page.click('#lf-bulk-start');
    await page.waitForFunction(()=>document.getElementById('lf-bulk-progress').textContent.includes('1 ZIPs processed'));
    check(await page.evaluate(()=>window.pacing[0]===200 && window.pacing[1]===1800),'Fast post-ACK transition retains conservative Maps wait');
    await page.evaluate(()=>{window.speedTest=false;window.recoveryTest=true;window.dropStep=true;});
    const startZip=async zip=>{
      await page.$eval('#lf-postcode',(e,z)=>e.value=z,zip);await page.click('#lf-start');
      await page.waitForSelector('#lf-zip-review:not([hidden])');await page.click('#lf-bulk-start');
    };
    let expectedSaves=saves+1;
    await startZip('32831');
    await page.waitForFunction(()=>document.getElementById('lf-activity').textContent.includes('Automatic recovery'));
    await page.waitForFunction(()=>document.getElementById('lf-bulk-progress').textContent.includes('1 ZIPs processed'));
    check(saves===expectedSaves,'Lost STEP reply recovers without manual Resume or duplicate save');
    await page.evaluate(()=>window.dropAck=true);expectedSaves=saves+1;
    await startZip('32832');
    await page.waitForFunction(()=>document.getElementById('lf-bulk-progress').textContent.includes('1 ZIPs processed'));
    check(saves===expectedSaves,'Lost applied ACK reconciles completed state without re-saving');
    await page.evaluate(()=>{window.dropStep=true;window.pauseTest=true;});
    await startZip('32833');
    await page.waitForFunction(()=>document.getElementById('lf-progress').textContent.includes('Automatic recovery'));
    const beforePause=saves;await page.click('#lf-pause');
    await new Promise(resolve=>setTimeout(resolve,650));
    check(saves===beforePause && (await page.$eval('#lf-progress',e=>e.textContent)).includes('Paused by you'),'Pause cancels pending recovery');
    await page.evaluate(()=>{window.pauseTest=false;window.dropAllSteps=true;});await page.click('#lf-resume');
    await page.waitForFunction(()=>document.getElementById('lf-progress').textContent.startsWith('Needs attention: STEP'));
    check(saves===beforePause && !(await page.$eval('#lf-resume',e=>e.disabled)),'Sustained outage stops after bounded retries without sends');
    check(errors.length===0,'No browser JavaScript errors');
  } finally {await browser.close();}
}
(async()=>{await workerTests();await browserTests();console.log(`PASS: ${checks} companion, collector and WordPress UI checks; all pages/API responses mocked.`);})().catch(e=>{console.error(e);process.exit(1);});
