/* Offline execution of the actual Analytics inline controller with a jQuery test double. */
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(path.join(__dirname,'../admin/AnalyticsAdmin.php'),'utf8');
const script=source.match(/\(function\(\$\) \{[\s\S]*?\}\)\(jQuery\);/)[0].replace(/<\?php[\s\S]*?\?>/g,'');
const requests=[],elements=new Map();
const document={getElementById:()=>null};
function element(selector){
    if(typeof selector==='object'&&selector.selector) return selector;
    if(!elements.has(selector)) elements.set(selector,{
        selector,handlers:{},value:'30',markup:'',props:{},
        on(name,handler){this.handlers[name]=handler;return this;},show(){return this;},hide(){return this;},
        prop(key,value){this.props[key]=value;return this;},text(value){this.markup=String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));return this;},
        html(value){if(value===undefined)return this.markup;this.markup=value;return this;},
        val(){return this.value;},ready(fn){fn();return this;}
    });
    return elements.get(selector);
}
element.ajax=options=>{
    const request={options,abort(){this.aborted=true;options.error({statusText:'abort'});options.complete();}};
    requests.push(request);return request;
};
vm.runInNewContext(script,{jQuery:element,document,window:{location:{}},wnqAnalytics:{clientId:'client',nonce:'fixture',ajaxUrl:'/ajax'},console});
assert.equal(requests.length,1);
const range=element('#wnq-date-range');
range.value='7';range.handlers.change.call(range);
assert.equal(requests.length,2);
assert.equal(requests[0].aborted,true);
const reply=label=>({success:true,data:{ga4:{status:'unavailable',message:label},google_ads:{status:'not_linked'},search_console:{status:'unavailable'},period:{start:'2026-09-01',end:'2026-09-07'}}});
requests[1].options.success(reply('Latest range'));requests[1].options.complete();
requests[0].options.success(reply('Stale range'));requests[0].options.complete();
assert.match(element('#wnq-analytics-content').markup,/Latest range/);
assert.doesNotMatch(element('#wnq-analytics-content').markup,/Stale range/);
assert.equal(element('#wnq-refresh-data').props.disabled,false);
range.handlers.change.call(range);
requests[2].options.success({success:false,data:{message:'<img src=x onerror=alert(1)>'}});
assert.doesNotMatch(element('#wnq-analytics-content').markup,/<img/);
assert.match(element('#wnq-analytics-content').markup,/&lt;img/);
console.log('Analytics UI race-condition and escaping checks passed.');
range.value='previous_month'; range.handlers.change.call(range);
assert.equal(requests.at(-1).options.data.date_range,'previous_month');
requests.at(-1).options.success({success:true,data:{period:{start:'2026-08-01',end:'2026-08-31'},ga4:{status:'unavailable'},search_console:{status:'unavailable'},google_ads:{status:'available',data:{currency_code:'EUR',cost:123.45,clicks:12,impressions:100,ctr:.12,conversions:2,campaigns:[{name:'Search',status:'enabled',cost:123.45,clicks:12,impressions:100,ctr:.12,conversions:2}]}}}});
assert.match(element('#wnq-analytics-content').markup,/€123\.45/);
assert.match(element('#wnq-analytics-content').markup,/<th>Cost<\/th>/);
assert.match(element('#wnq-analytics-content').markup,/12%/);
console.log('Monthly filter, account currency and campaign cost rendering passed.');
