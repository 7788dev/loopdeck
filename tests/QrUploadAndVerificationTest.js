'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const scripts = file => [...fs.readFileSync(path.join(root, file), 'utf8').matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)]
    .map(match => match[1]).join('\n').replace(/\{(?:\$|:)[^}]*\}/g, '42');
const failures = [];
function checkCase(name, test) {
    try {test();} catch (error) {failures.push(name + ': ' + error.message);}
}

checkCase('Upload byte limit', () => {
    const events = {}, values = {'#alipay_url': 'old-url'}, requests = [], notices = [];
    let loading = 0;
    const document = {};
    const $ = selector => {
        if (selector === document) return {ready(fn) {fn();}};
        return {on(event, fn) {events[event] = fn; return this;}, empty() {values[selector] = ''; return this;},
            val(value) {if (value === undefined) return values[selector]; values[selector] = value; return this;},
            attr(name) {return name === 'id' ? selector.id : selector.getAttribute(name);}};
    };
    $.ajax = options => requests.push(options);
    const context = vm.createContext({$, document, FormData: class {constructor() {this.values = {};} append(k, v) {this.values[k] = v;}},
        layer: {load() {loading++; return 1;}, close() {}}, x: {notify: message => notices.push(message)}});
    vm.runInContext(scripts('app/index/view/console/qrcode/create.html'), context);
    const input = {id: 'alipay', value: 'selected', files: [{size: 2 * 1024 * 1024 + 1, type: 'image/png'}],
        getAttribute(name) {return name === 'data-max-bytes' ? '2097152' : null;}};
    events.change.call(input);
    assert.equal(requests.length, 0, 'An oversized file was sent to the server');
    assert.equal(loading, 0, 'An oversized file started a loading overlay');
    assert.equal(values['#alipay_url'], '', 'Rejected selection retained an old payment URL');
    assert.ok(notices.some(message => message.includes('2MB')), 'Missing upload-size feedback');
    input.files = [{size: 2 * 1024 * 1024, type: 'image/png'}];
    events.change.call(input);
    assert.equal(requests.length, 1, 'A file at the byte boundary was rejected');
    assert.equal(requests[0].data.values.file, input.files[0]);
    assert.equal(requests[0].data.values.type, 'alipay');
    input.files = [{size: 100, type: 'image/png'}];
    requests[0].success({code: 1, data: {type: 'alipay', url: 'stale-url'}});
    assert.equal(values['#alipay_url'], '', 'A previous upload overwrote the newly selected file');
});

function verificationEnvironment(autoReply = true, manualLogin = false, manualQr = false) {
    let now = 0, timerId = 0, verificationId = 0;
    let node = {key: 'fixture-key', events: {}}, alert = {text: ''};
    const timers = new Map(), events = new Map(), requests = [], notices = [], alertWrites = [];
    const document = {body: {contains: element => element === node}};
    const wrap = element => ({0: element, length: element ? 1 : 0,
        attr(name, value) {if (value === undefined) return element[name]; element[name] = value; return this;},
        html(value) {if (element === alert) alertWrites.push({method: 'html', value}); element.text = value; return this;},
        text(value) {if (element === alert) alertWrites.push({method: 'text', value}); element.text = value; return this;},
        addClass() {return this;}, removeClass() {return this;},
        on(name, handler) {element.events[name] = handler; return this;},
        click() {element.events.click(); return this;}});
    const $ = selector => selector === document ? {
        off(name) {events.delete(name); return this;}, on(name, handler) {events.set(name, handler); return this;}
    } : wrap(selector === '#qr-img' ? node : alert);
    const context = vm.createContext({$, document, location: {href: ''},
        setTimeout(fn, delay) {timers.set(++timerId, {fn, at: now + delay, interval: 0}); return timerId;},
        setInterval(fn, delay) {timers.set(++timerId, {fn, at: now + delay, interval: delay}); return timerId;},
        clearTimeout(id) {timers.delete(id);}, clearInterval(id) {timers.delete(id);},
        x: {escapeHtml: String, notify: message => notices.push(message), ajax(url, data, success, fail) {
            const request = {url, data, success, fail, completed: false};
            requests.push(request);
            if (url.endsWith('/qrLogin')) {
                if (!manualLogin) {request.completed = true; success({code: 8810, data: {verify_unikey: 'verify-' + (++verificationId)}});}
            }
            else if (url.endsWith('/getQrimg')) {
                if (!manualQr) {request.completed = true; success({code: 1, data: {key: 'fixture-key', qrimg: 'fixture'}});}
            }
            else if (autoReply) {request.completed = true; success({code: -1, message: '等待验证'});}
            return {abort() {if (!request.completed && fail) {request.completed = true; fail();}}};
        }}});
    context.window = context;
    const source = scripts('app/index/view/console/netease/add.html');
    function mount() {
        node = {key: 'fixture-key', events: {}};
        alert = {text: ''};
        vm.runInContext(source, context);
    }
    function advance(until) {
        for (let guard = 0; guard < 1000; guard++) {
            const due = [...timers].filter(([, timer]) => timer.at <= until)
                .sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];
            if (!due) {now = until; return;}
            const [id, timer] = due;
            now = timer.at;
            if (timer.interval) timer.at += timer.interval; else timers.delete(id);
            timer.fn();
        }
        throw new Error('Unbounded timer loop');
    }
    return {context, requests, timers, notices, alertWrites, mount, advance,
        begin() {node.key = 'fixture-key'; context.ajax_netease_qrlogin();},
        loadQr() {node.events.click();},
        leave() {for (const [name, handler] of [...events]) if (name.startsWith('pjax:beforeReplace')) handler(); node = null;},
        polls() {return requests.filter(request => request.url.endsWith('/verifyCheck'));}};
}

checkCase('Upstream QR messages remain plain text', () => {
    const message = '<img src=x onerror="alert(1)">';
    for (const stage of ['load', 'login', 'poll']) {
        const env = verificationEnvironment(false, true, true);
        env.mount();
        if (stage === 'load') {
            env.loadQr();
            env.requests[0].success({code: 0, message});
        } else {
            env.begin();
            if (stage === 'login') env.requests[0].success({code: 0, message});
            else {
                env.requests[0].success({code: 8810, data: {verify_unikey: 'fixture'}});
                env.advance(1500);
                env.polls()[0].success({code: -1, message});
            }
        }
        assert.deepEqual(env.alertWrites.at(-1), {method: 'text', value: message}, stage + ' interpreted an upstream message as HTML');
        assert.ok(!env.alertWrites.some(write => write.method === 'html' && write.value.includes(message)));
    }
});

checkCase('PJAX refresh preserves new verification', () => {
    const env = verificationEnvironment();
    env.mount(); env.begin(); env.advance(500);
    env.mount(); env.begin(); env.advance(8000);
    assert.ok(env.polls().filter(request => request.data.verify_unikey === 'verify-2').length >= 3,
        'The old page cancelled the new verification polling');
    assert.equal(env.polls().filter(request => request.data.verify_unikey === 'verify-1').length, 0,
        'A disposed verification sent another request');
    env.leave(); env.advance(12000);
    assert.equal(env.timers.size, 0, 'Leaving the page leaked timers');
});

checkCase('Restart cancels the first delayed poll', () => {
    const env = verificationEnvironment();
    env.mount(); env.begin(); env.advance(100); env.begin(); env.advance(7000);
    assert.equal(env.polls().filter(request => request.data.verify_unikey === 'verify-1').length, 0,
        'Restart left the previous initial timeout active');
    assert.ok(env.polls().filter(request => request.data.verify_unikey === 'verify-2').length >= 3);
});

checkCase('Slow responses do not overlap and stale callbacks do not redirect', () => {
    const env = verificationEnvironment(false);
    env.mount(); env.begin(); env.advance(7000);
    assert.equal(env.polls().length, 1, 'Slow verification responses caused overlapping requests');
    const old = env.polls()[0];
    env.leave(); env.mount(); env.begin();
    old.success({code: 1, message: 'old success'});
    env.advance(10000);
    assert.equal(env.context.location.href, '', 'An old callback redirected the new page');
    assert.ok(!env.notices.includes('old success'), 'An old callback changed the new page state');
    assert.equal(env.polls().filter(request => request.data.verify_unikey === 'verify-2').length, 1);
});

checkCase('New QR cancels verification and leaving cancels success redirect', () => {
    const env = verificationEnvironment(false);
    env.mount(); env.begin(); env.loadQr(); env.advance(3000);
    assert.equal(env.polls().length, 0, 'Fetching a new QR kept the old security verification alive');
    env.begin(); env.advance(5000);
    env.polls()[0].success({code: 1, message: 'success'});
    env.leave(); env.advance(8000);
    assert.equal(env.context.location.href, '', 'A pending success redirect survived leaving the page');
    assert.equal(env.timers.size, 0, 'A completed verification retained timers');
});

checkCase('Replacing QR allows a new login while ignoring the previous response', () => {
    const env = verificationEnvironment(true, true);
    env.mount(); env.begin(); env.loadQr(); env.begin();
    const logins = env.requests.filter(request => request.url.endsWith('/qrLogin'));
    assert.equal(logins.length, 2, 'A replaced QR remained blocked by its old login request');
    logins[0].success({code: 8810, data: {verify_unikey: 'old-key'}});
    env.advance(2000);
    assert.equal(env.polls().length, 0, 'An old login response started a stale verification');
    logins[1].success({code: 8810, data: {verify_unikey: 'new-key'}});
    env.advance(6000);
    assert.ok(env.polls().length >= 2);
    assert.ok(env.polls().every(request => request.data.verify_unikey === 'new-key'));
});

assert.deepEqual(failures, []);
console.log('QR upload limits and verification lifecycle interactions passed');
