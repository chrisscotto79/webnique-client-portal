const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const puppeteer=require('puppeteer');
const root=path.join(__dirname,'..');
(async()=>{
  const browser=await puppeteer.launch({headless:true});
  try {
    const page=await browser.newPage();
    const html=execFileSync('php',[path.join(__dirname,'lead-browser-regression.php'),'--render'],{encoding:'utf8'});
    await page.setRequestInterception(true);
    page.on('request',r=>{
      const p=new URL(r.url()).pathname;
      if(p.endsWith('admin.php'))return r.respond({contentType:'text/html',body:html});
      if(p.endsWith('lead-browser.js'))return r.respond({contentType:'application/javascript',body:fs.readFileSync(path.join(root,'assets/js/lead-browser.js'),'utf8')});
      if(p.endsWith('lead-browser.css'))return r.respond({contentType:'text/css',body:''});
      r.abort();
    });
    await page.evaluateOnNewDocument(()=>{
      window.calls=[];window.sent=[];window.mode='normal';
      window.addEventListener('message',e=>{if(e.data?.channel==='wnq-leads-request')window.sent.push(e.data.action);});
      window.chrome={runtime:{sendMessage:(m,callback)=>{
        window.calls.push(m.action);
        if(window.mode==='invalidated')throw Error('Extension context invalidated');
        callback(m.action==='STATUS'&&window.mode==='status-error'?{ok:false,error:'Search busy'}:{ok:true,job:null});
      }}};
    });
    await page.goto('https://goldenwebmarketing.com/wp-admin/admin.php?page=wnq-lead-finder&tab=find');
    await page.waitForFunction(()=>window.sent.filter(x=>x==='HELLO').length>=3);
    assert.equal(await page.evaluate(()=>window.calls.length),0,'Handshake sent before bridge loads');
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'browser-companion/bridge.js'),'utf8')});
    await page.waitForFunction(()=>document.getElementById('lf-extension-status').textContent.includes('connected'));
    assert(await page.evaluate(()=>window.calls.includes('HELLO')),'Late listener automatically discovered');
    const count=await page.evaluate(()=>window.sent.length);
    await new Promise(r=>setTimeout(r,1100));
    assert.equal(await page.evaluate(()=>window.sent.length),count,'Retry timer stops after success');
    await page.evaluate(()=>window.mode='status-error');await page.click('#lf-reconnect');
    await page.waitForFunction(()=>document.getElementById('lf-progress').textContent.includes('Search busy'));
    assert((await page.$eval('#lf-extension-status',e=>e.textContent)).includes('connected'),'Status error does not falsely say extension missing');
    await page.evaluate(()=>window.mode='invalidated');await page.click('#lf-reconnect');
    await page.waitForFunction(()=>document.getElementById('lf-extension-status').textContent.includes('Refresh this WordPress'));
    assert(!(await page.$eval('#lf-reconnect',e=>e.disabled)),'Reconnect restored after failure');
    assert((await page.evaluate(()=>window.calls)).every(a=>['HELLO','STATUS'].includes(a)),'Connection check never starts scraping or sends leads');
    console.log('PASS: late bridge discovery, retry cleanup, reconnect, status-error isolation, invalidated context, read-only handshake.');
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
