const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const puppeteer = require('puppeteer');
const source = fs.readFileSync(require('node:path').join(__dirname, '../facebook-companion/worker.js'), 'utf8');
(async () => {
    let listener, created = 0, executions = 0;
    const storage = {};
    const tabActions = [];
    const chrome = {
        runtime: {id: 'test-extension', onMessage: {addListener(fn) { listener = fn; }}},
        storage: {local: {async get(key) { return {[key]: storage[key]}; }, async set(values) { Object.assign(storage, values); }, async remove(key) { delete storage[key]; }}},
        tabs: {async create(values) { tabActions.push(values); created++; return {id: 1, ...values}; }, async get() {return {id: 1, url: 'https://www.facebook.com/', status: 'complete'}; }, async update(id, values) {tabActions.push(values); return {id, ...values}; }},
        scripting: {async executeScript() {executions++; return [{result: {status: 'submitted', message: 'Confirmed'}}]; }}
    };
    const context = vm.createContext({chrome, URL, setTimeout});
    vm.runInContext(source, context);
    const sender = {id: 'test-extension', tab: {id: 7}, url: 'https://goldenwebmarketing.com/wp-admin/admin.php?page=wnq-facebook-groups'};
    const request = message => new Promise(resolve => listener({client: 'agency', clientName: 'Golden Web Marketing', ...message}, sender, resolve));
    assert.equal(listener({op: 'login'}, {...sender, url: 'https://evil.test/'}, () => {}), false);
    await request({op: 'login'});
    assert.equal(tabActions.at(-1).active, true, 'Explicit login opens in foreground');
    const job = {image_count: 0, images: [], client_id: 'agency', client_name: 'Golden Web Marketing', key: 'wnq_fb_job_' + 'a'.repeat(64), token: 'token', url: 'https://www.facebook.com/groups/123/', message: 'Hello', expires_at: Date.now() / 1000 + 120};
    assert.equal((await request({op: 'publish', job})).result.status, 'submitted');
    assert.equal(tabActions.at(-1).active, false, 'Publishing reuses tab without stealing focus');
    await request({op: 'publish', job});
    assert.equal(executions, 1, 'No duplicate dispatch');
    assert.equal(created, 1, 'Reuses one tab');
    const executionSnapshot = executions;
    assert((await request({op: 'publish', job: {...job, image_count: 1, images: []}})).error, 'Missing image payload is rejected before dispatch');
    assert((await request({op: 'publish', client: '22', clientName: 'Beta', job})).error, 'Mismatched selected client is rejected');
    assert((await request({op: 'publish', job: {...job, client_id: '11', client_name: 'Alpha'}})).error, 'Cross-client job rejected');
    assert.equal(executions, executionSnapshot, 'Identity mismatch never dispatches a browser action');
    assert((await request({op: 'publish', job: {...job, url: 'https://evil.test/'}})).error);
    vm.runInContext(source, vm.createContext({chrome, URL, setTimeout}));
    await request({op: 'publish', job});
    assert.equal(executions, 1, 'Persisted outcome survives worker restart');
    delete storage.tabId;
    await request({op: 'publish', job: {...job, key: 'wnq_fb_job_' + 'b'.repeat(64)}});
    assert.equal(tabActions.at(-1).active, false, 'New publishing tab opens in background');
    const submit = vm.runInContext('submit', context);
    const browser = await puppeteer.launch({headless: true});
    try {
        const page = await browser.newPage();
        await page.setRequestInterception(true);
        page.on('request', req => req.respond({status: 200, contentType: 'text/html', body: '<html><body></body></html>'}));
        await page.goto(job.url);
        await page.setContent(`<button aria-label="Create post" onclick="document.querySelector('[role=dialog]').style.display='block'">Open composer</button>
          <div role="dialog" style="display:none"><div contenteditable="true" role="textbox"></div>
          <button role="button" onclick="this.parentElement.style.display='none';document.querySelector('[role=status]').textContent='Your post was published'">Post</button></div><div role="status"></div>`);
        assert.equal((await page.evaluate(submit, job)).status, 'submitted', 'Only confirmed submission succeeds');
        assert.match(await page.$eval('#wnq-client-banner', el => el.textContent), /Golden Web Marketing/, 'Extension displays campaign name');
        await page.setContent(`<button aria-label="Create post" onclick="document.querySelector('[role=dialog]').style.display='block'">Open composer</button>
          <div role="dialog" style="display:none"><div contenteditable="true" role="textbox"></div>
          <input type="file" accept="image/*" onchange="window.uploadName=this.files[0].name;document.querySelector('#remove-photo').hidden=false">
          <button id="remove-photo" aria-label="Remove photo" hidden>Remove photo</button>
          <button onclick="this.parentElement.style.display='none';document.querySelector('[role=status]').textContent='Your post was published'">Post</button></div><div role="status"></div>`);
        const withImage = {...job, image_count: 1, images: [{name: 'client.png', mime: 'image/png', data: 'aGVsbG8='}]};
        assert.equal((await page.evaluate(submit, withImage)).status, 'submitted', 'Image attachment must be confirmed before Post');
        assert.equal(await page.evaluate(() => window.uploadName), 'client.png');
        await page.evaluate(() => {document.querySelector('[role=dialog]').style.display='block'; document.querySelector('[contenteditable]').innerText='Existing draft';});
        assert.equal((await page.evaluate(submit, job)).status, 'not_started', 'Preserves existing drafts');
        // Rich-text normalization plus a preview-triggered rerender and delayed,
        // aria-labelled Post control reproduce the failure before the final click.
        await page.setContent(`<button role="button" onclick="document.querySelector('[role=dialog]').style.display='block'">Write something...</button>
          <div role="dialog" style="display:none"><div contenteditable="true" role="textbox" oninput="if(!window.changed){window.changed=true;setTimeout(()=>{const d=document.querySelector('[role=dialog]');d.innerHTML='<div contenteditable=true role=textbox>Hey everyone!We help businesses.</div><div role=button aria-label=Post aria-disabled=true><span role=button aria-label=Post></span></div>';setTimeout(()=>{const b=d.querySelector('[aria-label=Post]');b.setAttribute('aria-disabled','false');b.onclick=()=>{window.postClicks=(window.postClicks||0)+1;d.style.display=\'none\';document.querySelector('[role=status]').textContent=\'Your post was published\';};},1600);},20);}"></div></div><div role="status"></div>`);
        const formatted = {...job, message: 'Hey everyone!\n\nWe help businesses.'};
        assert.equal((await page.evaluate(submit, formatted)).status, 'submitted', 'Rerender, whitespace, delayed button and aria-label supported');
        assert.equal(await page.evaluate(() => window.postClicks), 1, 'Exactly one click');
        await page.setContent('<p role="alert">You cannot post in this group</p>');
        const blocked = await page.evaluate(submit, job);
        assert.equal(blocked.scope, 'group', 'Group-local restrictions can be skipped');
        assert.equal(blocked.status, 'not_started');
        await page.setContent(`<header><h1>Test Group</h1><button onclick="this.textContent='Cancel request';window.joinClicks=(window.joinClicks||0)+1">Join group</button></header>`);
        assert.equal((await page.evaluate(submit, job)).status, 'join_required', 'Join requires durable authorization before click');
        assert.equal(await page.evaluate(() => window.joinClicks || 0), 0);
        const requested = await page.evaluate(submit, {...job, joinAuthorized: true});
        assert.equal(requested.scope, 'group');
        assert.equal(requested.status, 'not_started');
        assert.equal(await page.evaluate(() => window.joinClicks), 1);
        await page.evaluate(submit, {...job, joinAuthorized: true});
        assert.equal(await page.evaluate(() => window.joinClicks), 1, 'Pending join is not repeated');
        await page.setContent(`<header><h1>Test Group</h1><button onclick="document.querySelector('[role=dialog]').style.display='block'">Join group</button></header><div role="dialog" style="display:none"><input placeholder="Membership question"><input type="checkbox"></div>`);
        assert.equal((await page.evaluate(submit, {...job, joinAuthorized: true})).scope, 'group');
        assert.equal(await page.$eval('input[type=checkbox]', el => el.checked), false, 'Does not accept rules');
        assert.equal(await page.$eval('input', el => el.value), '', 'Does not answer questions');
        const quickSubmit = vm.runInNewContext(source.replace('const deadline = Date.now() + 15000;', 'const deadline = Date.now() + 200;') + '\nsubmit', {chrome, URL, setTimeout});
        await page.setContent('<button aria-label="Create post" onclick="document.querySelector(\'[role=dialog]\').style.display=\'block\'">Open</button><div role="dialog" style="display:none"><div contenteditable="true" role="textbox"></div><button onclick="window.imageFailurePosted=true">Post</button></div>');
        const missingUpload = await page.evaluate(quickSubmit, withImage);
        assert.equal(missingUpload.status, 'not_started', 'Missing upload input cannot fall back to a text-only post');
        assert.equal(await page.evaluate(() => !!window.imageFailurePosted), false);
        await page.setContent('<h1>Group without a posting button</h1>');
        const missing = await page.evaluate(quickSubmit, job);
        assert.equal(missing.status, 'not_started');
        assert.equal(missing.scope, 'group', 'Missing posting controls skip instead of stopping');
        assert(missing.message.startsWith('Skipped:'));
        await page.setContent('<button role="button">Write something...</button>');
        assert.equal((await page.evaluate(quickSubmit, job)).scope, 'group', 'Missing dialog skips');
        await page.setContent('<div role="alert">Your account is restricted</div>');
        assert.equal((await page.evaluate(quickSubmit, job)).scope, 'account', 'Account restriction overrides missing-controls skip');
        await page.goto('https://www.facebook.com/login');
        const login = await page.evaluate(submit, job);
        assert.equal(login.status, 'not_started', 'Redirect/login guard');
        assert.equal(login.scope, 'account', 'Login failures pause the whole run');
    } finally { await browser.close(); }
    console.log('PASS: origin validation, one tab, duplicate prevention, restart persistence, confirmed composer submission, draft and login guards. All Facebook pages mocked.');
})().catch(error => {console.error(error); process.exitCode = 1;});
