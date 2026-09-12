const fs=require('node:fs'), vm=require('node:vm'), assert=require('node:assert/strict');
const source=fs.readFileSync(require('node:path').join(__dirname,'../assets/js/lead-browser.js'),'utf8');
const start=source.indexOf('  async function wordpressRequest('), end=source.indexOf('  function scheduleRecovery(',start);
let response;
const request=vm.runInNewContext(`function retryError(message){return Object.assign(new Error(message),{retryable:true});}\n${source.slice(start,end)}\nwordpressRequest`,{AbortController,TypeError,setTimeout,clearTimeout,ajaxurl:'https://fixture.invalid',fetch:async()=>{if(response instanceof Error)throw response;return response;}});
(async()=>{
  let checks=0;
  const reply=(status,raw,url='')=>({status,ok:status>=200&&status<300,url,text:async()=>raw});
  for(const [status,raw,retry] of [[502,'<html>proxy error</html>',true],[429,'throttled',true],[200,'<html>error</html>',true],[200,'',true],[200,'{"success":true}',true],[403,'-1',false],[200,'-1',false],[200,'0',false],[200,'<form id="loginform">',false],[400,'bad',false]]) {
    response=reply(status,raw);
    await assert.rejects(()=>request({}),e=>{assert.equal(!!e.retryable,retry);assert(!e.message.includes('<html>'));return true;});checks++;
  }
  response=new TypeError('network');await assert.rejects(()=>request({}),e=>e.retryable);checks++;
  response=reply(200,'{"success":true,"data":{"outcome":"saved"}}');assert.equal((await request({})).data.outcome,'saved');checks++;
  response=reply(200,'{"success":false,"data":{"message":"invalid listing"}}');assert.equal((await request({})).success,false);checks++;
  console.log(`PASS: ${checks} WordPress response classification checks; no live requests.`);
})().catch(e=>{console.error(e);process.exitCode=1;});
