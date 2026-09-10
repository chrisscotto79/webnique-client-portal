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
  const storage = {}, tabs = new Map(); let listener, injected = 0;
  const sender = {frameId:0,tab:{id:20},url:'https://goldenwebmarketing.com/wp-admin/admin.php?page=wnq-lead-finder'};
  const chrome = {
    storage:{session:{get:async key => ({[key]:structuredClone(storage[key])}),set:async obj => Object.assign(storage,structuredClone(obj))}},
    tabs:{create:async options => {const t={id:tabs.size+1,status:'complete',...options};tabs.set(t.id,t);return t;},
      get:async id => {if(!tabs.has(id)) throw Error('closed');return tabs.get(id);},update:async(id,data) => Object.assign(tabs.get(id),data)},
    scripting:{executeScript:async options => {injected++;return [{result:options.args[0]==='search'
      ? {ready:true,rows:[row,row],end:true} : {ready:true,row}}];}},
    runtime:{onMessage:{addListener:fn => {listener=fn;}}}
  };
  const boot = () => {
    const ctx = vm.createContext({chrome,URL,Set,crypto:require('node:crypto').webcrypto,console});
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
      if(u.pathname.endsWith('admin-ajax.php')) {saves++;return request.respond({contentType:'application/json',body:JSON.stringify({success:true,data:{name:'Business',outcome:'saved',email:'info@business.example',message:'Website email found.'}})});}
      return request.abort();
    });
    await page.evaluateOnNewDocument(row => {
      window.ajaxurl='/wp-admin/admin-ajax.php';let job=null, ackFail=true;
      window.addEventListener('message',event => {
        const m=event.data;if(m?.channel!=='wnq-leads-request') return;
        let response={ok:true};
        if(m.action==='START') job={runId:'test-run',keyword:m.payload.keyword,zip:m.payload.zip,phase:'details',found:1,index:0,stats:{saved:0,email:0,duplicate:0},pending:row};
        if(m.action==='ACK') {
          if(ackFail) {ackFail=false;response={ok:false,error:'Simulated interrupted acknowledgement. Resume to reconcile.'};}
          else {job={...job,phase:'done',pending:null,index:1,stats:{saved:1,email:1,duplicate:0},note:'End of fixture list.'};}
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
    await page.waitForFunction(()=>document.getElementById('lf-progress').textContent.includes('Simulated'));
    check(saves===1,'Listing saved before acknowledgement');await page.click('#lf-resume');
    await page.waitForFunction(()=>document.getElementById('lf-progress').textContent.includes('Search complete'));
    check(saves===1,'Resume reuses saved receipt, not another website request');
    check(await page.$eval('#lf-count-saved',e=>e.textContent)==='1','Saved counter preserved across interrupted acknowledgement');
    check(await page.$eval('#lf-count-email',e=>e.textContent)==='1','Email counter reflects saved result');
    check(errors.length===0,'No browser JavaScript errors');
  } finally {await browser.close();}
}
(async()=>{await workerTests();await browserTests();console.log(`PASS: ${checks} companion, collector and WordPress UI checks; all pages/API responses mocked.`);})().catch(e=>{console.error(e);process.exit(1);});
