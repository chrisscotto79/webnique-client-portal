const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../facebook-companion/bridge.js'), 'utf8');
async function scenario(sendMessage, id = 'extension') {
    let listener, calls = 0;
    const replies = [];
    const location = {href: 'https://goldenwebmarketing.com/wp-admin/admin.php?page=wnq-facebook-groups', origin: 'https://goldenwebmarketing.com'};
    const window = {addEventListener(_, fn) {listener = fn;}, postMessage(value) {replies.push(value);}};
    vm.runInNewContext(source, {window, location, URL, chrome: {runtime: {id, sendMessage: (...args) => {calls++; return sendMessage(...args);}}}});
    const event = {source: window, origin: location.origin, data: {source: 'wnq-facebook-page', op: 'ping', id: 'request'}};
    await listener({...event, origin: 'https://evil.test'});
    assert.equal(calls, 0, 'Wrong origin ignored');
    await listener(event);
    await listener(event);
    assert.equal(replies.length, 2);
    assert(replies.every(reply => reply.id === 'request'));
    return {calls, replies};
}
(async () => {
    let result = await scenario(() => {throw new Error('Extension context invalidated.');});
    assert.equal(result.calls, 1, 'Dead runtime is not called repeatedly');
    assert(result.replies[0].error.includes('Refresh this WordPress tab'));
    result = await scenario(() => Promise.reject(new Error('Extension context invalidated.')));
    assert.equal(result.calls, 1);
    result = await scenario(() => Promise.resolve(undefined));
    assert(result.replies[0].error.includes('not responding'));
    result = await scenario(() => Promise.resolve({result: {version: '1.0.6'}}));
    assert.equal(result.replies[0].result.version, '1.0.6');
    result = await scenario(() => {throw new Error('Should not be called');}, null);
    assert.equal(result.calls, 0);
    console.log('PASS: sync throw, async rejection, missing runtime, empty reply, healthy reply and origin checks.');
})().catch(error => {console.error(error); process.exitCode = 1;});
