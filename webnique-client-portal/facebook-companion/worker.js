'use strict';
let busy = false;
let activeToken = null, cancelled = false;
const allowed = sender => {
    try {
        const url = new URL(sender.url);
        return sender.id === chrome.runtime.id && sender.tab &&
            ['https://goldenwebmarketing.com', 'https://www.goldenwebmarketing.com'].includes(url.origin) &&
            url.pathname === '/wp-admin/admin.php' && url.searchParams.get('page') === 'wnq-facebook-groups';
    } catch { return false; }
};
async function facebookTab(url, foreground = false) {
    const saved = await chrome.storage.local.get('tabId');
    let tab;
    if (saved.tabId) {
        try {
            const existing = await chrome.tabs.get(saved.tabId);
            if (existing.url?.startsWith('https://www.facebook.com/')) tab = existing;
        } catch { /* The owned tab was closed. */ }
    }
    if (tab) tab = await chrome.tabs.update(tab.id, {url, active: foreground});
    else tab = await chrome.tabs.create({url, active: foreground});
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
    const groupFailure = message => Object.assign(new Error(message), {scope: 'group'});
    const wait = async (finder, step) => {
        const deadline = Date.now() + 15000;
        while (Date.now() < deadline) { const result = finder(); if (result) return result; await sleep(250); }
        throw groupFailure(step || 'Facebook posting controls were not found.');
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
        // Restrict membership actions to the current group's heading area, never
        // Join buttons on suggested groups or feed posts.
        const membership = () => {
            const heading = [...document.querySelectorAll('h1')].find(visible);
            let root = heading?.parentElement;
            for (let depth = 0; root && depth < 6; depth++, root = root.parentElement) {
                if (root === document.body || root.querySelector('[role="feed"],[role="article"]')) break;
                const buttons = [...root.querySelectorAll('button,[role="button"]')].filter(el => visible(el));
                const pending = buttons.some(el => /^(Cancel request|Cancel join request|Requested|Request pending)$/i.test(el.getAttribute('aria-label') || text(el)));
                const joined = buttons.some(el => /^(Joined|Member)$/i.test(el.getAttribute('aria-label') || text(el)));
                const joins = buttons.filter(el => /^Join group$/i.test(el.getAttribute('aria-label') || text(el)));
                if (pending || joined || joins.length) return {pending, joined, joins};
            }
            return {joins: []};
        };
        let member = membership();
        const joinHold = message => ({status: 'not_started', scope: 'group', message});
        if (member.pending) return joinHold('Membership request already pending. Skipped until the group approves it.');
        if (member.joins.length > 1) return joinHold('Membership controls are ambiguous. Check this group manually.');
        if (member.joins.length === 1 && !member.joined) {
            if (!job.joinAuthorized) return {status: 'join_required'};
            if (!Number.isFinite(job.expires_at) || Date.now() >= job.expires_at * 1000) return joinHold('Join authorization expired. No request sent.');
            if (window.__wnqFbCancelled === job.token) return joinHold('Stopped before joining.');
            member.joins[0].click();
            const until = Date.now() + 15000;
            while (Date.now() < until) {
                await sleep(300);
                if (document.querySelector('input[type="password"]') || /checkpoint|challenge/.test(location.pathname)) throw new Error('Facebook needs login or security verification.');
                if ([...document.querySelectorAll('[role="dialog"]')].some(visible)) return joinHold('Joining needs questions, rules, or identity confirmation. Complete it manually in Facebook.');
                member = membership();
                if (member.pending) return joinHold('Join request sent. Waiting for group approval; no post sent.');
                if (member.joined) break;
            }
            if (!member.joined) return joinHold('Join was attempted but membership is not confirmed. Check Facebook; no repeat join request or post was sent.');
        }
        // Only explicit group-local restrictions allow proceeding to the next group.
        // Generic selector failures, login and security prompts pause the account.
        const groupNotice = [...document.querySelectorAll('[role="alert"],[role="status"],h1,h2')]
            .filter(el => visible(el) && !el.closest('[role="article"]')).map(text).join('\n');
        if (/you (?:can't|cannot|are not allowed to) post in this group|this group (?:is no longer available|has been removed)|this content isn't available right now/i.test(groupNotice)) {
            return {status: 'not_started', scope: 'group', message: 'Group unavailable or posting restricted. Skipped without posting.'};
        }
        const trigger = await wait(() => {
            const matches = [...document.querySelectorAll('button,[role="button"]')].filter(el => visible(el) &&
                !el.closest('[role="article"],[role="dialog"],aside,[role="complementary"]') &&
                /^(?:Write something(?:\.\.\.|…)?|Create (?:a )?post)$/i.test((el.getAttribute('aria-label') || text(el)).trim()));
            const controls = matches.filter(el => !matches.some(other => other !== el && other.contains(el)));
            return controls.length === 1 ? controls[0] : null;
        }, 'Posting-box button not found or ambiguous (Write something / Create post). No post clicked.');
        trigger.click();
        const dialog = await wait(() => [...document.querySelectorAll('[role="dialog"]')].find(el => visible(el) && el.querySelector('[contenteditable="true"][role="textbox"]')), 'The posting-box button opened, but the editable Create post dialog was not found. No post clicked. Check the Facebook tab for a prompt or changed composer.');
        const editors = [...dialog.querySelectorAll('[contenteditable="true"][role="textbox"]')].filter(visible);
        if (editors.length !== 1) throw groupFailure('The Facebook composer is ambiguous. No post sent.');
        const editor = editors[0];
        if (text(editor)) throw groupFailure('Facebook already has a draft in this composer. Review it manually first.');
        editor.focus();
        if (!document.execCommand('insertText', false, job.message)) throw groupFailure('Could not fill the Facebook composer.');
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
        if (!postButton) throw groupFailure(reason + ' No post sent.');
        postButton.scrollIntoView({block: 'center'});
        if (window.__wnqFbCancelled === job.token) throw new Error('Stopped before clicking Post.');
        if (!Number.isFinite(job.expires_at) || Date.now() >= job.expires_at * 1000) throw new Error('Publishing authorization expired. No post sent; return to WordPress and retry.');
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
        const accountBlocked = document.querySelector('input[type="password"]') || /checkpoint|challenge/.test(location.pathname);
        return {status: 'unknown', scope: accountBlocked ? 'account' : 'group', message: 'Post was clicked, but Facebook did not clearly confirm the result. Held for review; this group will not be automatically repeated.'};
    } catch (error) {
        const securityNotice = [...document.querySelectorAll('[role="alert"],[role="dialog"]')].filter(visible).map(text).join('\n');
        const accountBlocked = document.querySelector('input[type="password"]') || /checkpoint|challenge|\/login/.test(location.pathname) || /temporarily blocked|account (?:is )?(?:restricted|suspended|disabled)|confirm your identity|security check/i.test(securityNotice);
        const scope = !accountBlocked && error.scope === 'group' ? 'group' : 'account';
        return {status: clicked ? 'unknown' : 'not_started', scope, message: (!clicked && scope === 'group' ? 'Skipped: ' : '') + error.message};
    }
}
async function handle(message) {
    if (message.op === 'ping') return {version: '1.0.9'};
    if (message.op === 'cancel') {
        cancelled = true;
        const tabId = (await chrome.storage.local.get('tabId')).tabId;
        if (tabId && activeToken) await chrome.scripting.executeScript({target: {tabId}, func: token => { window.__wnqFbCancelled = token; }, args: [activeToken]});
        return {stopped: true};
    }
    if (busy) throw new Error('A Facebook request is already running.');
    busy = true;
    try {
        if (message.op === 'login') { await facebookTab('https://www.facebook.com/', true); return {opened: true}; }
        const job = message.job;
        if (message.op !== 'publish' || !job || !/^https:\/\/www\.facebook\.com\/groups\/[a-zA-Z0-9._-]+\/$/.test(job.url) ||
            !/^wnq_fb_job_[a-f0-9]{64}$/.test(job.key) || typeof job.token !== 'string' ||
            typeof job.message !== 'string' || !job.message.trim() || job.message.length > 20000) throw new Error('Invalid publishing job.');
        activeToken = job.token; cancelled = false;
        const existing = (await chrome.storage.local.get(job.key))[job.key];
        if (existing) return existing.result || {status: 'unknown', message: 'This group has a previous browser submission. Check Facebook before taking further action.'};
        // Durable before dispatch, so a service-worker restart cannot replay a click.
        await chrome.storage.local.set({[job.key]: {started: Date.now()}});
        let result, dispatched = false;
        try {
            const tab = await facebookTab(job.url);
            await loaded(tab.id);
            if (cancelled) throw new Error('Stopped before dispatch');
            dispatched = true;
            const results = await chrome.scripting.executeScript({target: {tabId: tab.id}, func: submit, args: [{...job, joinAuthorized: false}]});
            result = results[0]?.result || {status: 'unknown', message: 'Facebook returned no result. Check the group.'};
            if (result.status === 'join_required') {
                const joinKey = 'join_' + new URL(job.url).pathname;
                const attempted = (await chrome.storage.local.get(joinKey))[joinKey];
                if (attempted) result = {status: 'not_started', scope: 'group', message: 'A join request was already attempted. Check membership manually; no repeat request sent.'};
                else {
                    await chrome.storage.local.set({[joinKey]: {attemptedAt: Date.now()}});
                    const joined = await chrome.scripting.executeScript({target: {tabId: tab.id}, func: submit, args: [{...job, joinAuthorized: true}]});
                    result = joined[0]?.result || {status: 'unknown', scope: 'account', message: 'Join response interrupted. Check Facebook before continuing.'};
                }
            }
        } catch {
            result = {status: dispatched ? 'unknown' : 'not_started', message: dispatched ? 'The browser request was interrupted. Check Facebook; automatic retries are disabled for this submission.' : 'Facebook did not load. No publishing script was dispatched; check the connection and try again.'};
        }
        if (result.status === 'not_started') await chrome.storage.local.remove(job.key);
        else await chrome.storage.local.set({[job.key]: {result}});
        return result;
    } finally { busy = false; activeToken = null; }
}
chrome.runtime.onMessage.addListener((message, sender, respond) => {
    if (!allowed(sender)) return false;
    handle(message).then(result => respond({result})).catch(error => respond({error: error.message}));
    return true;
});
