const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs'), path = require('node:path');
const puppeteer = require('puppeteer');
(async () => {
    const root = path.join(__dirname, '..');
    const html = execFileSync('php', [path.join(__dirname, 'facebook-publish.php'), '--dashboard'], {encoding:'utf8'});
    const browser = await puppeteer.launch({headless:'new'});
    try {
        const page = await browser.newPage(), errors = [];
        page.on('pageerror', e => errors.push(e.message));
        await page.setViewport({width:1440,height:1000});
        await page.setRequestInterception(true);
        page.on('request', request => request.respond({contentType:'text/html; charset=utf-8',body:html}));
        await page.goto('https://goldenwebmarketing.com/wp-admin/admin.php?page=wnq-facebook-groups');
        await page.addStyleTag({content:'body{background:#eee;font:14px system-ui;padding:20px}.screen-reader-text{position:absolute;clip:rect(1px,1px,1px,1px)}[hidden]{display:none!important}' + fs.readFileSync(path.join(root,'assets/css/task-dashboard.css'),'utf8')});
        await page.evaluate(() => {
            window.WNQFacebook={ajax:'/mock',nonce:'test'};
            window.sent=[];window.results=[];window.cancelled=[];window.operations=[];window.inflight=0;window.maxInflight=0;window.holdPublish=false;window.holdStart=false;
            window.campaigns=[['agency','Golden Web Marketing'],['11','Alpha Tree'],['22','Beta Welding']].map(([id,name])=>({id,name,ready:true,enabled:false,groups:70,today:10,schedule:'09:00–18:00',timezone:'America/New_York',counts:{submitted:0,pending:0,skipped:0,reserved:0},edit:'?client='+id}));
            const nativeTimeout=setTimeout;
            window.setTimeout=(fn,ms)=>nativeTimeout(fn,ms===5000?25:ms);
            window.fetch=async (_,args)=>{
                const op=args.body.get('op'),id=args.body.get('client'),row=campaigns.find(row=>row.id===id);
                operations.push([op,id]);let data={};
                if(op==='dashboard')data={campaigns:JSON.parse(JSON.stringify(campaigns)),next_at:0};
                if(op==='start'||op==='resume'){if(holdStart)await new Promise(resolve=>window.releaseStart=resolve);row.enabled=true;}
                if(op==='stop')row.enabled=false;
                if(op==='next')data={job:{key:'job-'+id,token:'token',client_id:id,client_name:row.name,images:[],image_count:0,url:'https://www.facebook.com/groups/'+(id==='agency'?'1':id)+'/',message:row.name}};
                if(op==='result'){results.push([id,args.body.get('status')]);row.counts[args.body.get('status')==='submitted'?'submitted':'skipped']++;}
                return {ok:true,json:async()=>({success:true,data})};
            };
            window.addEventListener('message',async event=>{
                if(event.data.source!=='wnq-facebook-page')return;
                const req=event.data;let result={version:'1.2.0'};
                if(req.op==='publish'){
                    sent.push([req.client,req.job.client_id,req.job.message]);inflight++;maxInflight=Math.max(maxInflight,inflight);
                    if(holdPublish)await new Promise(resolve=>window.releasePublish=resolve);
                    result=req.client==='agency'?{status:'unknown',scope:'group',message:'Unconfirmed; skipped.'}:{status:'submitted',message:'Facebook confirmed.'};inflight--;
                }
                if(req.op==='cancel')cancelled.push(req.client);
                window.postMessage({source:'wnq-facebook-extension',id:req.id,result},location.origin);
            });
        });
        await page.addScriptTag({path:path.join(root,'assets/js/facebook-dashboard.js')});
        await page.waitForSelector('#fb-task-rows tr[data-client]');
        await page.click('#fb-dash-start');
        await page.waitForFunction(()=>results.length>=3);
        await page.click('#fb-dash-stop');
        await page.waitForFunction(()=>campaigns.every(row=>!row.enabled));
        assert.deepEqual(await page.evaluate(()=>sent.slice(0,3).map(row=>row[0])),['agency','11','22'],'Round robin reaches all companies after unconfirmed first result');
        assert.equal(await page.evaluate(()=>maxInflight),1,'Never simultaneous browser publishing');
        assert(await page.evaluate(()=>sent.every(row=>row[0]===row[1])),'Company identity preserved');
        assert.equal(await page.$('#fb-review'),null,'No submission approval queue');
        await page.type('#fb-task-search','Beta');
        assert.equal(await page.$$eval('#fb-task-rows tr[data-client]',nodes=>nodes.length),1);
        await page.$eval('#fb-task-search',node=>{node.value='';node.dispatchEvent(new Event('input'));});
        await page.evaluate(()=>{holdPublish=true;});
        await page.click('#fb-dash-start');
        await page.waitForFunction(()=>typeof releasePublish==='function');
        await page.click('#fb-dash-stop');
        await page.waitForFunction(()=>cancelled.length>0&&campaigns.every(row=>!row.enabled));
        const count=await page.evaluate(()=>sent.length);
        await page.evaluate(()=>releasePublish());
        await new Promise(resolve=>setTimeout(resolve,150));
        assert.equal(await page.evaluate(()=>sent.length),count,'Pause in flight does not dispatch next company');
        await page.evaluate(()=>{holdPublish=false;holdStart=true;});
        await page.click('#fb-dash-start');
        await page.waitForFunction(()=>typeof releaseStart==='function');
        await page.click('#fb-dash-stop');
        await page.evaluate(()=>releaseStart());
        await page.waitForFunction(()=>!document.getElementById('fb-dash-start').disabled);
        assert(await page.evaluate(()=>campaigns.every(row=>!row.enabled)),'Late start response is stopped after Pause all');
        assert.deepEqual(errors,[]);
        await page.screenshot({path:'/tmp/wnq-facebook-dashboard.png',fullPage:true});
        await page.setViewport({width:390,height:844});
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Mobile table scroll stays within page');
        console.log('PASS: multi-company fairness, skip and continue, serialized publishing, search, cancellation and late-start race, desktop/mobile dashboard. All network mocked.');
    }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
