const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {execFileSync}=require('node:child_process');const puppeteer=require('puppeteer');
(async()=>{
 const root=path.join(__dirname,'..');const client=execFileSync('php',[path.join(__dirname,'monthly-seo.php'),'--render'],{encoding:'utf8'});const overview=execFileSync('php',[path.join(__dirname,'monthly-seo.php'),'--overview'],{encoding:'utf8'});
 const browser=await puppeteer.launch({headless:'new'});
 try{
  const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));await page.setRequestInterception(true);
  page.on('request',req=>{const url=new URL(req.url());if(url.pathname.endsWith('monthly-seo.css'))return req.respond({contentType:'text/css',body:fs.readFileSync(path.join(root,'assets/css/monthly-seo.css'),'utf8')});if(url.pathname.endsWith('monthly-seo.js'))return req.respond({contentType:'application/javascript',body:fs.readFileSync(path.join(root,'assets/js/monthly-seo.js'),'utf8')});return req.respond({contentType:'text/html; charset=utf-8',body:url.searchParams.get('client')?client:overview});});
  for(const width of [1440,390]){
   await page.setViewport({width,height:1000});await page.goto('https://fixture.test/wp-admin/admin.php?page=wnq-seo');
   await page.addStyleTag({content:'body{background:#f0f0f1;margin:20px}a{color:#3357dc}.button{display:inline-block;text-decoration:none;border:1px solid #bdc8db;cursor:pointer;background:white;color:#234}.button-primary{color:white}.widefat{width:100%}'});
   await page.waitForSelector('.mseo-client');assert.equal(await page.$$eval('.mseo-client',nodes=>nodes.length),2);
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Overview mobile overflow');
   const visible=await page.$$eval('[data-mseo-agenda]',nodes=>nodes.filter(n=>!n.hidden).length);assert(visible>0);
   await page.select('#mseo-agenda-filter','all');assert.equal(await page.$$eval('[data-mseo-agenda]',nodes=>nodes.filter(n=>!n.hidden).length),12);
   await page.click('#mseo-agenda-next');assert(await page.$eval('#mseo-agenda-count',node=>node.textContent.startsWith('13')));
   await page.select('#mseo-agenda-client','beta');assert(await page.$eval('#mseo-agenda-empty',node=>!node.hidden));
   await page.select('#mseo-agenda-client','');await page.select('#mseo-agenda-filter','today');
   await page.type('#mseo-client-search','Erys');assert.equal(await page.$$eval('[data-mseo-client]',nodes=>nodes.filter(n=>!n.hidden).length),1);
   await page.$eval('#mseo-client-search',node=>{node.value='';node.dispatchEvent(new Event('input'));});
   if(process.env.WNQ_UI_OUTPUT)await page.screenshot({path:path.join(process.env.WNQ_UI_OUTPUT,`seo-overview-${width}.png`),fullPage:true});
   await page.goto('https://fixture.test/wp-admin/admin.php?page=wnq-seo&client=erys');
   await page.addStyleTag({content:'body{background:#f0f0f1;margin:20px}a{color:#3357dc}.button{display:inline-block;text-decoration:none;border:1px solid #bdc8db;cursor:pointer;background:white;color:#234}.button-primary{color:white}.widefat{width:100%}'});
   await page.waitForSelector('.mseo-category');assert.equal(await page.$$eval('.mseo-category',nodes=>nodes.length),10);
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Client mobile overflow');
   await page.select('#mseo-task-filter','completed');assert.equal(await page.$$eval('[data-task-status]',nodes=>nodes.filter(n=>!n.hidden).length),1);
   await page.select('#mseo-task-filter','overdue');assert(await page.$$eval('[data-task-status]',nodes=>nodes.filter(n=>!n.hidden).every(n=>n.dataset.taskStatus==='overdue')));
   await page.select('#mseo-task-filter','all');
   await page.click('a[href="#mseo-metrics"]');assert(await page.$eval('#mseo-metrics',node=>node.open),'Metrics navigation opens details');
   assert.equal(await page.$eval('input[name="organic_leads"]',node=>node.value),'0');assert.equal(await page.$eval('input[name="site_health"]',node=>node.value),'');
   assert(await page.$eval('#mseo-summary',node=>node.textContent.includes('New service page ranked')),'Saved narrative appears in report');
   assert(await page.$eval('#mseo-summary',node=>node.textContent.includes('Audit fixed and verified')),'Completed task evidence appears in report');
   assert(await page.$eval('#mseo-history',node=>node.textContent.includes('2026-10')),'Monthly history visible');
   const taskId=await page.$eval('.mseo-task',node=>node.id);await page.evaluate(id=>{location.hash=id;},taskId);await page.waitForFunction(id=>document.getElementById(id).open,{},taskId);
   assert(await page.$eval('#'+taskId,node=>node.parentElement.open),'Task deep link opens category');
   await page.$eval('#mseo-metrics',node=>node.open=false);
   if(process.env.WNQ_UI_OUTPUT)await page.screenshot({path:path.join(process.env.WNQ_UI_OUTPUT,`seo-client-${width}.png`),fullPage:true});
  }
  assert.deepEqual(errors,[]);console.log('PASS: monthly dashboard filters, ten categories, task deep links, metrics, summary, history and desktop/mobile layout. All requests mocked.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
