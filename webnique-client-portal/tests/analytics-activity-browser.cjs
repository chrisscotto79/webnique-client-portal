/* Offline browser fixture using the production renderer/styles and synthetic API responses. */
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict'),puppeteer=require('puppeteer');
(async()=>{
    const script=fs.readFileSync(path.join(__dirname,'../assets/admin/analytics-activity.js'),'utf8');
    const css=fs.readFileSync(path.join(__dirname,'../assets/admin/analytics-activity.css'),'utf8');
    const artifact=fs.mkdtempSync(path.join(os.tmpdir(),'wnq-analytics-activity-'));
    const rows=Array.from({length:60},(_,i)=>({time:'2026-09-07 12:'+String(i%60).padStart(2,'0'),source:i%2?'Google Ads':'Organic search',event:i===0?'<img src=x onerror="window.injected=true">':'phone_click',device:i%2?'mobile':'desktop',count:1,key_events:1,channel:i%2?'Paid Search':'Organic Search'}));
    const reports={
        lead_summary:{status:'available',threshold_seconds:20,period_label:'this reporting period',period:{start:'2026-09-01',end:'2026-09-07',timezone:'America/New_York'},total_verified_leads:8,verified_calls:2,form_leads:4,email_leads:2,website_phone_clicks:7,all_recorded_calls:3,warning:'Some paid phone-click activity could not be attributed to the linked Google Ads account. These interactions are excluded from Verified Calls.',breakdown:{google_ads_recorded_calls:3,google_ads_verified_calls:2,ga4_ads_phone_clicks:2,ga4_organic_phone_clicks:3,ga4_other_phone_clicks:1,ga4_unknown_phone_clicks:1,forms:4,emails:2},message:'Phone clicks are interactions, not calls.'},
        gbp_summary:{status:'available',location:'SNS Hauling',period:{start:'2026-09-01',end:'2026-09-07'},metrics:{profile_views:120,search_views:80,maps_views:40,website_clicks:12,call_clicks:6,direction_requests:4},series:{BUSINESS_IMPRESSIONS_DESKTOP_SEARCH:[{date:'2026-09-07',value:50}],BUSINESS_IMPRESSIONS_MOBILE_SEARCH:[{date:'2026-09-07',value:30}],BUSINESS_IMPRESSIONS_MOBILE_MAPS:[{date:'2026-09-07',value:0}],BUSINESS_IMPRESSIONS_DESKTOP_MAPS:[{date:'2026-09-07',value:40}],WEBSITE_CLICKS:[{date:'2026-09-07',value:12}],CALL_CLICKS:[{date:'2026-09-07',value:6}],BUSINESS_DIRECTION_REQUESTS:[{date:'2026-09-07',value:4}]},message:'Read-only Business Profile data.'},
        phone_events:{status:'available',timezone:'America/New_York',period:{start:'2026-09-01',end:'2026-09-07'},message:'Phone clicks, not confirmed calls. Rows group events within one minute by device and session attribution. These may overlap Ads calls.',rows},
        ads_calls:{status:'available',timezone:'America/New_York',period:{start:'2026-09-01',end:'2026-09-07'},message:'Search ad call records. Recordings are not available through this feed.',rows:[{time:'2026-09-07 14:20:00',source:'Google Ads',campaign:'Local Search',status:'RECEIVED',duration:85}]},
    };
    const mock=`
    window.wnqAnalytics={clientId:'fixture',ajaxUrl:'/fixture',nonce:'fixture'};
    window.fixtureReports=${JSON.stringify(reports)}; window.fixtureRequests=[];
    function $(selector){if(typeof selector==='function'){selector();return;} const el=document.querySelector(selector);return {val:()=>el.value,on:(event,fn)=>el.addEventListener(event,fn)};}
    $.ajax=function(options){window.fixtureRequests.push(options);let timer=setTimeout(()=>options.success({success:true,data:fixtureReports[options.data.provider]}),options.data.provider==='ads_calls'?30:60);return {abort(){clearTimeout(timer);options.error({},'abort')}}};
    window.jQuery=$;`;
    const html='<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Client results QA</title><style>body{background:#f0f2f5;font:14px system-ui;padding:20px;margin:0}main{max-width:1200px;margin:auto}button,input,select{font:inherit;padding:6px;border:1px solid #bdc9d8;border-radius:4px;background:white}h1{color:#163756}@media(max-width:600px){body{padding:10px}}</style><style>'+css+'</style><main><h1>Client results · QA preview</h1><select id="wnq-date-range"><option value="7">Last 7 Days</option><option value="30">Last 30 Days</option></select><button id="wnq-refresh-data">Refresh</button><section id="wnq-results-activity"><header class="wnq-results-header"><span>CLIENT RESULTS</span><h2>Lead Summary</h2><p>Verified leads and separate source evidence.</p></header><div id="wnq-activity-feeds"></div></section></main><script>'+mock+'</script><script>'+script+'</script></html>';
    fs.writeFileSync(path.join(artifact,'fixture.html'),html);
    const browser=await puppeteer.launch({headless:'new'});
    try{
        const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
        await page.setViewport({width:1440,height:1000});await page.goto('file://'+path.join(artifact,'fixture.html'));
        await page.waitForSelector('#wnq-feed-lead_summary .wnq-lead-metric-primary');
        assert.equal(await page.$eval('#wnq-feed-gbp_summary .wnq-gbp-metrics .wnq-lead-metric strong',el=>el.textContent),'120');
        assert.equal(await page.$$eval('#wnq-feed-gbp_summary tbody tr',rows=>rows.length),1);
        assert.equal(await page.$eval('#wnq-feed-lead_summary .wnq-lead-metric-primary strong',el=>el.textContent),'8');
        assert.match(await page.$eval('#wnq-feed-lead_summary .wnq-lead-sentence',el=>el.textContent),/2 verified Google Ads calls, 4 tracked form leads, and 2 tracked email leads/);
        assert.match(await page.$eval('#wnq-feed-lead_summary .wnq-lead-warning',el=>el.textContent),/excluded from Verified Calls/);
        await page.click('#wnq-feed-lead_summary .wnq-lead-breakdown summary');
        assert.equal(await page.$eval('#wnq-feed-lead_summary .wnq-breakdown-item strong',el=>el.textContent),'3');
        await page.waitForSelector('#wnq-feed-phone_events tbody tr');
        await page.click('#wnq-feed-phone_events .wnq-feed-evidence summary');
        assert.equal(await page.$$eval('#wnq-feed-phone_events tbody tr',rows=>rows.length),25);
        await page.select('#wnq-feed-phone_events select','Google Ads');
        assert.equal(await page.$$eval('#wnq-feed-phone_events tbody tr',rows=>rows.length),25);
        await page.click('#wnq-feed-phone_events .wnq-feed-controls button:last-child');
        assert.equal(await page.$$eval('#wnq-feed-phone_events tbody tr',rows=>rows.length),5);
        await page.type('#wnq-feed-phone_events input','not present');
        assert.equal(await page.$$eval('#wnq-feed-phone_events tbody tr',rows=>rows.length),0);
        await page.$eval('#wnq-feed-phone_events input',el=>{el.value='';el.dispatchEvent(new Event('input'));});
        await page.select('#wnq-feed-phone_events select','All sources');
        assert.equal(await page.evaluate(()=>window.injected),undefined);
        await page.screenshot({path:path.join(artifact,'desktop.png'),fullPage:true});
        await page.setViewport({width:390,height:844});
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'No mobile page overflow');
        await page.screenshot({path:path.join(artifact,'mobile.png'),fullPage:true});
        await page.click('#wnq-refresh-data');
        assert.equal(await page.evaluate(()=>fixtureRequests.slice(-4).every(r=>r.data.refresh===1)),true);
        assert.deepEqual(errors,[]);
        console.log('Analytics activity browser tests passed. Screenshots: '+artifact);
    } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
