'use strict';
let busy = false;
const allowed = sender => {
    try {
        const url = new URL(sender.url);
        return sender.id === chrome.runtime.id && sender.tab &&
            ['https://goldenwebmarketing.com', 'https://www.goldenwebmarketing.com'].includes(url.origin) &&
            url.pathname === '/wp-admin/admin.php' && url.searchParams.get('page') === 'wnq-facebook-groups';
    } catch { return false; }
};
async function facebookTab(url) {
    const saved = await chrome.storage.local.get('tabId');
    let tab;
    if (saved.tabId) {
        try {
            const existing = await chrome.tabs.get(saved.tabId);
            if (existing.url?.startsWith('https://www.facebook.com/')) tab = existing;
        } catch { /* The owned tab was closed. */ }
    }
    if (tab) tab = await chrome.tabs.update(tab.id, {url, active: true});
    else tab = await chrome.tabs.create({url, active: true});
    await chrome.storage.local.set({tabId: tab.id});
    return tab;
}
async function loaded(id) {
    const deadline = Date.now() + 30000;
    while (Date.now() < deadline) {
        const tab = await chrome.tabs.get(id);
        if (tab.status === 'complete') return;
        await new Promise(resolve => setTimeout(resolve, 300));
    }
    throw new Error('Facebook page did not finish loading.');
}
// Runs only in our owned Facebook tab. No cookies, passwords, or page source leave it.
async function submit(job) {
    let clicked = false;
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    const visible = element => element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden';
    const text = element => (element.innerText || element.textContent || '').trim();
    const wait = async finder => {
        const deadline = Date.now() + 15000;
        while (Date.now() < deadline) { const result = finder(); if (result) return result; await sleep(250); }
        throw new Error('Facebook controls were not found. Check login, group access, language, or page changes.');
    };
    try {
        const current = new URL(location.href);
        if (current.origin !== 'https://www.facebook.com' ||
            current.pathname.replace(/\/$/, '') !== new URL(job.url).pathname.replace(/\/$/, '')) {
            throw new Error('Facebook redirected away from the expected group. Check login or group access.');
        }
        if (document.querySelector('input[type="password"]') || /checkpoint|challenge/.test(location.pathname)) {
            throw new Error('Facebook needs you to sign in or complete a security prompt.');
        }
        const trigger = await wait(() => [...document.querySelectorAll('[role="button"]')].find(el => visible(el) && /^Write something(?:\.\.\.|…)?$/i.test(text(el))));
        trigger.click();
        const dialog = await wait(() => [...document.querySelectorAll('[role="dialog"]')].find(el => visible(el) && el.querySelector('[contenteditable="true"][role="textbox"]')));
        const editors = [...dialog.querySelectorAll('[contenteditable="true"][role="textbox"]')].filter(visible);
        if (editors.length !== 1) throw new Error('The Facebook composer is ambiguous. No post sent.');
        const editor = editors[0];
        if (text(editor)) throw new Error('Facebook already has a draft in this composer. Review it manually first.');
        editor.focus();
        if (!document.execCommand('insertText', false, job.message)) throw new Error('Could not fill the Facebook composer.');
        await sleep(1000);
        // Facebook's rich-text editor rewrites paragraph breaks, NBSP and zero-width
        // formatting characters. Verify all non-whitespace content, not its DOM layout.
        const normalize = value => value.replace(/[\s\u200b\ufeff]+/gu, '');
        let postButton, activeDialog;
        const readyUntil = Date.now() + 15000;
        let reason = 'Post button unavailable or ambiguous.';
        while (Date.now() < readyUntil) {
            const dialogs = [...document.querySelectorAll('[role="dialog"]')].filter(el => visible(el) && el.querySelector('[contenteditable="true"][role="textbox"]'));
            if (dialogs.length !== 1) { reason = 'The Facebook composer is ambiguous.'; await sleep(250); continue; }
            activeDialog = dialogs[0];
            const liveEditors = [...activeDialog.querySelectorAll('[contenteditable="true"][role="textbox"]')].filter(visible);
            if (liveEditors.length !== 1 || normalize(text(liveEditors[0])) !== normalize(job.message)) {
                reason = 'Composer content did not match the saved message.'; await sleep(250); continue;
            }
            const matches = [...activeDialog.querySelectorAll('[role="button"],button')].filter(el => visible(el) &&
                (el.getAttribute('aria-label')?.trim() || text(el)) === 'Post');
            // A nested role=button wrapper is one control, not two competing posts.
            const buttons = matches.filter(el => !matches.some(other => other !== el && other.contains(el)));
            if (buttons.length === 1 && buttons[0].getAttribute('aria-disabled') !== 'true' && !buttons[0].disabled && buttons[0].getAttribute('aria-busy') !== 'true') {
                postButton = buttons[0]; break;
            }
            reason = 'Post button did not become ready; the link preview may still be loading.';
            await sleep(250);
        }
        if (!postButton) throw new Error(reason + ' No post sent.');
        postButton.scrollIntoView({block: 'center'});
        // After this line every uncertainty must remain held, never blindly retried.
        const oldNotices = new Set([...document.querySelectorAll('[role="alert"],[role="status"]')].filter(visible).map(text));
        clicked = true;
        postButton.click();
        const deadline = Date.now() + 20000;
        while (Date.now() < deadline) {
            await sleep(500);
            const notices = [...document.querySelectorAll('[role="alert"],[role="status"]')].filter(visible).map(text).filter(value => !oldNotices.has(value)).join('\n');
            if (!visible(activeDialog)) {
                if (/submitted.*approval|pending.*approval|post.*pending/i.test(notices)) return {status: 'pending', message: 'Submitted for group approval.'};
                if (/your post (?:has been |was )?(?:published|shared)|post (?:published|shared) successfully/i.test(notices)) return {status: 'submitted', message: 'Facebook confirmed the post submission.'};
            }
        }
        return {status: 'unknown', message: 'Post was clicked, but Facebook did not clearly confirm the result. Check the group; this submission will not be automatically repeated.'};
    } catch (error) {
        return {status: clicked ? 'unknown' : 'not_started', message: error.message};
    }
}
async function handle(message) {
    if (message.op === 'ping') return {version: '1.0.1'};
    if (busy) throw new Error('A Facebook request is already running.');
    busy = true;
    try {
        if (message.op === 'login') { await facebookTab('https://www.facebook.com/'); return {opened: true}; }
        const job = message.job;
        if (message.op !== 'publish' || !job || !/^https:\/\/www\.facebook\.com\/groups\/[a-zA-Z0-9._-]+\/$/.test(job.url) ||
            !/^wnq_fb_job_[a-f0-9]{64}$/.test(job.key) || typeof job.token !== 'string' ||
            typeof job.message !== 'string' || !job.message.trim() || job.message.length > 20000) throw new Error('Invalid publishing job.');
        const existing = (await chrome.storage.local.get(job.key))[job.key];
        if (existing) return existing.result || {status: 'unknown', message: 'This group has a previous browser submission. Check Facebook before taking further action.'};
        // Durable before dispatch, so a service-worker restart cannot replay a click.
        await chrome.storage.local.set({[job.key]: {started: Date.now()}});
        let result, dispatched = false;
        try {
            const tab = await facebookTab(job.url);
            await loaded(tab.id);
            dispatched = true;
            const results = await chrome.scripting.executeScript({target: {tabId: tab.id}, func: submit, args: [job]});
            result = results[0]?.result || {status: 'unknown', message: 'Facebook returned no result. Check the group.'};
        } catch {
            result = {status: dispatched ? 'unknown' : 'not_started', message: dispatched ? 'The browser request was interrupted. Check Facebook; automatic retries are disabled for this submission.' : 'Facebook did not load. No publishing script was dispatched; check the connection and try again.'};
        }
        if (result.status === 'not_started') await chrome.storage.local.remove(job.key);
        else await chrome.storage.local.set({[job.key]: {result}});
        return result;
    } finally { busy = false; }
}
chrome.runtime.onMessage.addListener((message, sender, respond) => {
    if (!allowed(sender)) return false;
    handle(message).then(result => respond({result})).catch(error => respond({error: error.message}));
    return true;
});
