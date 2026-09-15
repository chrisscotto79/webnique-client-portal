const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const puppeteer = require('puppeteer');
(async () => {
    const html = execFileSync('php', [path.join(__dirname, 'facebook-publish.php'), '--render'], {encoding: 'utf8'});
    const browser = await puppeteer.launch({headless: true});
    try {
        const page = await browser.newPage(), errors = [];
        page.on('pageerror', e => errors.push(e.message));
        page.on('dialog', dialog => dialog.accept());
        await page.setRequestInterception(true);
        page.on('request', req => req.respond({status: 200, contentType: 'text/html', body: html}));
        await page.goto('https://goldenwebmarketing.com/wp-admin/admin.php?page=wnq-facebook-groups');
        await page.evaluate(() => {
            window.WNQFacebook = {ajax: '/mock', nonce: 'test'};
            window.operations = []; window.clientScopes = []; window.publishCalls = 0; window.enabled = false; window.done = false;
            const nativeTimeout = setTimeout;
            window.setTimeout = (fn, ms) => nativeTimeout(fn, ms === 10000 ? 50 : ms);
            window.fetch = async (_, args) => {
                const op = args.body.get('op'); operations.push(op); clientScopes.push(args.body.get('client'));
                if (op === 'start' || op === 'resume') enabled = true;
                if (op === 'stop') enabled = false;
                if (op === 'result') done = true;
                const url = document.querySelector('[data-fb-group]').dataset.fbGroup;
                let data = {};
                if (op === 'progress') data = {enabled, counts: {total: 1, submitted: 0, pending: 0, skipped: 0, review: done ? 1 : 0}, rows: [{url, status: done ? 'review' : 'waiting', message: ''}], review: []};
                if (op === 'next') data = done ? {waiting: true, next_at: Date.now()/1000 + 360, message: 'Waiting for the six-minute posting interval.'} : {job: {client_id: window.badClient || 'agency', client_name: 'Golden Web Marketing', key: 'job', token: 'token', url, message: 'hello'}};
                return {ok: true, json: async () => ({success: true, data})};
            };
            window.addEventListener('message', event => {
                if (event.data.source !== 'wnq-facebook-page') return;
                if (event.data.op === 'publish') publishCalls++;
                const result = event.data.op === 'publish' ? {status: 'unknown', scope: 'group', message: 'Check Facebook; submission not confirmed.'} : {version: '1.1.0'};
                window.postMessage({source: 'wnq-facebook-extension', id: event.data.id, result}, location.origin);
            });
        });
        await page.addScriptTag({path: path.join(__dirname, '../assets/js/facebook-publish.js')});
        assert.equal(await page.$('#fb-now'), null, 'Old immediate-batch control removed');
        assert.deepEqual(await page.$$eval('#fb-client option', nodes => nodes.map(n => n.textContent.trim())), ['Golden Web Marketing', 'Alpha Tree', 'Beta Welding']);
        await page.click('#fb-start');
        await page.waitForFunction(() => document.getElementById('fb-status').textContent.includes('six-minute'));
        assert(await page.$eval('#fb-errors', el => !el.hidden && el.textContent.includes('not confirmed')));
        assert(await page.$eval('[data-fb-group]', el => el.textContent.includes('Unconfirmed')));
        assert(await page.$eval('#fb-client', el => el.disabled), 'Client selector locked during a run');
        await page.click('#fb-stop');
        await page.waitForFunction(() => document.getElementById('fb-status').textContent.includes('Weekly schedule stopped'));
        assert.equal(await page.evaluate(() => enabled), false);
        await page.click('#fb-resume');
        await page.waitForFunction(() => operations.includes('resume'));
        await page.click('#fb-stop');
        await page.waitForFunction(() => !document.getElementById('fb-test').disabled);
        const posted = await page.evaluate(() => publishCalls);
        await page.evaluate(() => { window.badClient = '22'; window.done = false; });
        await page.click('#fb-test');
        await page.waitForFunction(() => document.getElementById('fb-errors').textContent.includes('identity mismatch'));
        assert.equal(await page.evaluate(() => publishCalls), posted, 'Wrong-client job never reaches extension');
        assert(await page.evaluate(() => clientScopes.every(id => id === 'agency')), 'Every request keeps the loaded client scope');
        assert.deepEqual(errors, []);
        console.log('PASS: rendered controls, persistent Stop/Resume requests, unknown outcome continues, separate error/progress, weekly row status. No real network actions.');
    } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode = 1;});
