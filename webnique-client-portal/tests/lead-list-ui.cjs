const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const puppeteer = require('puppeteer');
(async()=>{
  const browser=await puppeteer.launch({headless:'new',args:['--no-sandbox']});
  try {
    const page=await browser.newPage();
    await page.setRequestInterception(true);
    page.on('request',r=>r.abort());
    const html=execFileSync('php',[path.join(__dirname,'lead-list-regression.php'),'--list-render'],{encoding:'utf8'});
    for (const width of [1440,390]) {
      await page.setViewport({width,height:1000});await page.setContent(html);
      await page.addStyleTag({content:'body{font:14px system-ui;margin:16px;background:#f1f5f9;color:#1e293b}.wnq-card{background:white;padding:20px;margin:16px 0;border-radius:12px}.wnq-btn{padding:10px;border-radius:6px;border:1px solid #ccd5e0;background:white}input,select,textarea{box-sizing:border-box}.wnq-tbl-wrap{overflow:auto}'+fs.readFileSync(path.join(__dirname,'../assets/css/lead-browser.css'),'utf8')});
      assert.equal(await page.$$eval('form form',e=>e.length),0,'No nested forms');
      assert(await page.$('select[name=company_fit]'));
      assert(await page.$('input[name=max_reviews]'));
      assert(await page.$('input[name=seo_issues_min]'));
      assert(await page.$eval('input[name=confirmation]',e=>{e.value='wrong';return !e.checkValidity();}));
      assert(await page.$eval('input[name=confirmation]',e=>{e.value='DELETE ALL';return e.checkValidity();}));
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'No page horizontal overflow');
      await page.screenshot({path:`/tmp/wnq-lead-list-${width}.png`,fullPage:true});
    }
    console.log('PASS: Lead List desktop/mobile filters, forms, typed confirmation and overflow checks.');
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
