const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const puppeteer = require('puppeteer');
const source = fs.readFileSync(require('node:path').join(__dirname, '../facebook-companion/worker.js'), 'utf8');
(async () => {
    let listener, created = 0, executions = 0;
    const storage = {};
    const chrome = {
        runtime: {id: 'test-extension', onMessage: {addListener(fn) { listener = fn; }}},
        storage: {local: {async get(key) { return {[key]: storage[key]}; }, async set(values) { Object.assign(storage, values); }, async remove(key) { delete storage[key]; }}},
        tabs: {async create({url}) { created++; return {id: 1, url}; }, async get() {return {id: 1, url: 'https://www.facebook.com/', status: 'complete'}; }, async update(id, values) {return {id, ...values}; }},
        scripting: {async executeScript() {executions++; return [{result: {status: 'submitted', message: 'Confirmed'}}]; }}
    };
    const context = vm.createContext({chrome, URL, setTimeout});
    vm.runInContext(source, context);
    const sender = {id: 'test-extension', tab: {id: 7}, url: 'https://goldenwebmarketing.com/wp-admin/admin.php?page=wnq-facebook-groups'};
    const request = message => new Promise(resolve => listener(message, sender, resolve));
    assert.equal(listener({op: 'login'}, {...sender, url: 'https://evil.test/'}, () => {}), false);
    await request({op: 'login'});
    const job = {key: 'wnq_fb_job_' + 'a'.repeat(64), token: 'token', url: 'https://www.facebook.com/groups/123/', message: 'Hello'};
    assert.equal((await request({op: 'publish', job})).result.status, 'submitted');
    await request({op: 'publish', job});
    assert.equal(executions, 1, 'No duplicate dispatch');
    assert.equal(created, 1, 'Reuses one tab');
    assert((await request({op: 'publish', job: {...job, url: 'https://evil.test/'}})).error);
    vm.runInContext(source, vm.createContext({chrome, URL, setTimeout}));
    await request({op: 'publish', job});
    assert.equal(executions, 1, 'Persisted outcome survives worker restart');
    const submit = vm.runInContext('submit', context);
    const browser = await puppeteer.launch({headless: true});
    try {
        const page = await browser.newPage();
        await page.setRequestInterception(true);
        page.on('request', req => req.respond({status: 200, contentType: 'text/html', body: '<html><body></body></html>'}));
        await page.goto(job.url);
        await page.setContent(`<button role="button" onclick="document.querySelector('[role=dialog]').style.display='block'">Write something...</button>
          <div role="dialog" style="display:none"><div contenteditable="true" role="textbox"></div>
          <button role="button" onclick="this.parentElement.style.display='none';document.querySelector('[role=status]').textContent='Your post was published'">Post</button></div><div role="status"></div>`);
        assert.equal((await page.evaluate(submit, job)).status, 'submitted', 'Only confirmed submission succeeds');
        await page.evaluate(() => {document.querySelector('[role=dialog]').style.display='block'; document.querySelector('[contenteditable]').innerText='Existing draft';});
        assert.equal((await page.evaluate(submit, job)).status, 'not_started', 'Preserves existing drafts');
        await page.goto('https://www.facebook.com/login');
        assert.equal((await page.evaluate(submit, job)).status, 'not_started', 'Redirect/login guard');
    } finally { await browser.close(); }
    console.log('PASS: origin validation, one tab, duplicate prevention, restart persistence, confirmed composer submission, draft and login guards. All Facebook pages mocked.');
})().catch(error => {console.error(error); process.exitCode = 1;});
