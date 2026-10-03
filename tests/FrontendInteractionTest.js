'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
if (process.env.LOOPDECK_TEST_THEME !== 'ruoyi') {
    require('node:child_process').execFileSync(process.execPath, [__filename], {
        env: {...process.env, LOOPDECK_TEST_THEME: 'ruoyi'}, stdio: 'inherit'
    });
}
const read = file => fs.readFileSync(path.join(root,
    process.env.LOOPDECK_TEST_THEME === 'ruoyi'
        ? file.replace('app/index/view/console/', 'app/index/view/ruoyi/console/')
        : file), 'utf8');

function scripts(file) {
    return [...read(file).matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)]
        .map(match => match[1]).join('\n')
        .replace(/\{(?:if\b[^}]*|else\b[^}]*|\/if)\}/g, '')
        .replace(/\{(?:\$|:)[^}]*\}/g, '42');
}

function environment() {
    const state = {closed: false, sent: [], events: {}, values: {'#timing': '18:35', '#km': 'fixture-code'}};
    const chain = {pjax() {return this;}, on(event, handler) {state.events[event] = handler; return this;}, each() {return this;}, ready() {return this;}, removeClass() {return this;},
        DataTable() {return {ajax: {reload() {}}};},
        find() {return this;}, prop(name, value) {state[name] = value; return this;},
        parseForm() {return state.closed ? {} : {selected: 'saved-value'};}};
    const $ = selector => Object.assign(Object.create(chain), {
        text(value) {state.values[selector] = value; return this;},
        val(value) {state.values[selector] = value; return this;},
        serialize() {return state.closed ? '' : 'quota_unlimited=1&vip_permanent=1&state=1';}
    });
    $.fn = {};
    $.extend = () => {};
    $.fn.dataTable = {ext: {classes: {}}, defaults: {}};
    const context = vm.createContext({$, jQuery: $, document: {},
        ClipboardJS: function () {this.on = () => {};},
        window: {innerWidth: 375, location: {href: ''}}, setTimeout() {},
        layer: {open(options) {state.dialog = options;}, close() {state.closed = true;}, load() {return 2;}},
        Codebase: {helpers() {}, helpersOnLoad() {}},
        x: {getval: selector => state.closed && selector === '#timing' ? '' : state.values[selector],
            ajax: (url, data) => state.sent.push({url, data}), notify() {}, close() {}}
    });
    return {state, context};
}

{
    const {state, context} = environment();
    let success, failure;
    context.x.ajax = (url, data, onSuccess, onFailure) => {
        state.sent.push({url, data}); success = onSuccess; failure = onFailure;
    };
    vm.runInContext(scripts('app/index/view/console/shop/card.html'), context);
    context.ajax_km_activate();
    assert.equal(state.sent[0].url, '/index/ajax/shop/activate');
    assert.equal(state.sent[0].data.km, 'fixture-code', 'Redemption must submit the entered code');
    context.ajax_km_activate();
    assert.equal(state.sent.length, 1, 'Pending redemption was submitted twice');
    failure();
    assert.equal(state.disabled, false, 'Network failure disabled further redemptions');
    context.ajax_km_activate();
    success({code: 0, message: 'invalid'});
    assert.equal(state.disabled, false, 'Rejected redemption disabled further attempts');
    context.ajax_km_activate();
    success({code: 1, message: 'ok', data: {account_limit_label: '不限', membership_label: '永久会员'}});
    assert.equal(state.values['#redeemed-account-limit'], '账号总数：不限（所有平台共用）');
    assert.equal(state.values['#redeemed-vip-end'], '永久会员');
    state.values['#km'] = '';
    context.ajax_km_activate();
    assert.equal(state.sent.length, 3, 'Empty codes must not be submitted');
}
{
    const {state, context} = environment();
    vm.runInContext(read('public/static/js/admin_usersList_datatables.js'), context);
    context.ajax_edit_user(2);
    state.dialog.yes(1, {});
    assert.equal(state.sent[0].data, 'id=2&quota_unlimited=1&vip_permanent=1&state=1',
        'Closing the administrator dialog discarded permanent entitlement settings');
}
for (const platform of ['netease', 'bilibili', 'heybox']) {
    const {state, context} = environment();
    vm.runInContext(scripts(`app/index/view/console/${platform}/info.html`), context);
    context.ajax_set_timing('42', '08:00');
    assert.equal(state.dialog.area[0], '343px', `${platform}: dialog must fit mobile viewport`);
    state.dialog.yes(1, {});
    assert.equal(state.sent[0].data.timing, '18:35', `${platform}: closing the dialog discarded the selected time`);
    assert.match(read(`app/index/view/console/${platform}/info.html`), /ajax_set_zt\([^\n]+this\)/);
}
for (const [platform, functions] of Object.entries({netease: ['musicianTask', 'evaluateTask', 'dakaTask'], bilibili: ['globalRoom', 'coinAdd']})) {
    for (const fn of functions) {
        const {state, context} = environment();
        vm.runInContext(scripts(`app/index/view/console/${platform}/info.html`), context);
        context[fn]('fixture', '任务配置', '{}');
        state.dialog.yes(1, {});
        assert.equal(JSON.parse(state.sent[0].data.config).selected, 'saved-value', `${fn}: closing the dialog discarded configuration`);
    }
}
{
    const {context} = environment();
    vm.runInContext(read('public/static/js/app.min.js'), context);
    let callbacks;
    context.x.ajax = (url, data, success, fail) => {callbacks = {success, fail};};
    context.x.notify = () => {};
    const input = {checked: true, disabled: false};
    context.x.taskSwitch('/set', 'sign', '42', input);
    assert.equal(input.disabled, true);
    callbacks.success({code: 0, message: 'VIP required'});
    assert.equal(input.checked, false, 'Rejected switch must restore its previous state');
    assert.equal(input.disabled, false);
    input.checked = true;
    context.x.taskSwitch('/set', 'sign', '42', input);
    callbacks.fail();
    assert.equal(input.checked, false, 'Network failure must restore the switch');
    input.checked = true;
    context.x.taskSwitch('/set', 'sign', '42', input);
    callbacks.success({code: 1, message: 'OK'});
    assert.equal(input.checked, true);
}
{
    const {state, context} = environment();
    Object.assign(state.values, {'#tool_account': '42', '#tool_songid': '12345', '#tool_times': '10'});
    let onFailure;
    context.x.ajax = (url, data, success, fail) => {onFailure = fail;};
    vm.runInContext(scripts('app/index/view/console/netease/tool.html'), context);
    context.submitListenTask();
    assert.equal(state.disabled, true);
    onFailure();
    assert.equal(state.disabled, false, 'Failed listen request must allow retry');
}
{
    const {context} = environment();
    delete context.ClipboardJS;
    vm.runInContext(read('public/static/js/app.min.js'), context);
    assert.equal(typeof context.x.ajax, 'function', 'Login pages must work without optional clipboard and PJAX plugins');
}
console.log('Frontend interaction tests passed');

// Execute the actual navigation-completion handler for both shells and breakpoint edges.
for (const [theme, widths] of [['default', [390, 991, 992, 1440]], ['ruoyi', [390, 767, 768, 1440]]]) {
    for (const width of widths) {
        for (const initiallyOpen of [true, false]) {
            const {state, context} = environment();
            let open = initiallyOpen, completed = 0, refreshed = 0;
            context.window.innerWidth = width;
            context.$.fn.pjax = () => {};
            context.NProgress = {done() {completed++;}};
            const originalSelector = context.$;
            const selector = value => Object.assign(originalSelector(value), {
                removeClass(name) { if (value === 'body' && name === 'mini-navbar') open = false; return this; }
            });
            selector.fn = context.$.fn;
            context.$ = context.jQuery = selector;
            if (theme === 'default') context.Codebase.layout = action => {if (action === 'sidebar_close') open = false;};
            else delete context.Codebase;
            vm.runInContext(read('public/static/js/app.min.js'), context);
            context.x.reload = () => {refreshed++;};
            state.events['pjax:complete']();
            state.events['pjax:complete']();
            const mobile = width < (theme === 'default' ? 992 : 768);
            assert.equal(open, mobile ? false : initiallyOpen, `${theme} at ${width}px changed the desktop sidebar preference`);
            assert.equal(completed, 2, 'Navigation must finish the progress indicator');
            assert.equal(refreshed, 2, 'Navigation must still refresh active menu and tabs');
        }
    }
}
console.log('Sidebar navigation state and responsive boundaries passed');

{
    const {context} = environment();
    vm.runInContext(read('public/static/js/app.min.js'), context);
    const rows = new Set();
    const links = ['accounts', 'settings'].map(name => ({href: 'http://fixture/' + name, group: name, active: false}));
    const noOp = {addClass() {return this;}, css() {return this;}};
    context.$ = selector => {
        if (selector === '#nav-main a') return {each() {}};
        if (selector === '#side-menu li') return {removeClass() {rows.clear();}};
        if (selector === '#side-menu a') return {each(callback) {links.forEach(link => callback.call(link));}};
        return {
            addClass() {selector.active = true; return this;},
            removeClass() {selector.active = false; return this;},
            parents(kind) {return kind === 'li' ? {addClass() {rows.add(selector.group);}} : noOp;}
        };
    };
    context.x.tabSync = () => {};
    context.window.location.href = links[0].href;
    context.x.reload();
    context.window.location.href = links[1].href + '?filter=all';
    context.x.reload();
    assert.deepEqual([...rows], ['settings'], 'Previous RuoYi menu group retained its active highlight');
    assert.deepEqual(links.map(link => link.active), [false, true], 'More than one leaf remained active');
}


// Run the real updater controller against a small DOM and controlled network.
(async function updaterProgressInteractions() {
    const elements = new Map();
    const timers = new Map();
    let id = 0, attached = true, requests = [];
    const element = name => {
        if (!elements.has(name)) elements.set(name, {textContent: '', className: '', style: {}, hidden: false,
            addEventListener(event, handler) {this[event] = handler;}, removeEventListener(event) {delete this[event];}});
        return elements.get(name);
    };
    const panel = {querySelector: selector => element(selector)};
    const window = {};
    const context = vm.createContext({window, document: {body: {contains: () => attached}}, Date, Math, String,
        Number, AbortController, setTimeout: (fn, delay) => {timers.set(++id, {fn, delay}); return id;},
        clearTimeout: timer => timers.delete(timer), setInterval: () => ++id, clearInterval() {},
        fetch: (url, options) => new Promise((resolve, reject) => requests.push({url, options, resolve, reject}))});
    vm.runInContext(read('public/static/js/updater-progress.js'), context);
    const settle = async () => {for (let i = 0; i < 12; i++) await Promise.resolve();};
    const runTimer = delay => {
        const match = [...timers].find(([, timer]) => timer.delay === delay);
        assert.ok(match, 'Expected polling timer ' + delay);
        timers.delete(match[0]); match[1].fn();
    };
    const state = {current_version: '1.2.13', checked_at: 'old', status: 'up_to_date',
        manual_check_available: true, cooldown_seconds: 35};
    const stop = window.LoopDeckUpdater.mount(panel, state, '/check', '/status');
    runTimer(0);
    requests.shift().resolve({ok: true, json: async () => ({code: 1, data: state})});
    await settle();
    element('#updater-check-button').click();
    assert.equal(element('#updater-button-label').textContent, '正在提交…');
    assert.equal(element('#updater-check-button').disabled, true);
    element('#updater-check-button').click();
    assert.equal(requests.length, 1, 'Repeated clicks posted duplicate requests');
    requests.shift().resolve({ok: true, json: async () => ({code: 1})});
    await settle();
    assert.match(element('#updater-live-detail').textContent, /35 秒/);
    runTimer(0);
    requests.shift().resolve({ok: true, json: async () => ({code: 1, data: {...state, status: 'checking',
        phase: 'versions', probe_completed: 2, probe_total: 5, message: '正在检查版本', heartbeat_age_seconds: 0}})});
    await settle();
    assert.match(element('#updater-live-detail').textContent, /2\/5/);
    assert.equal(element('#updater-status').textContent, '检查版本');
    runTimer(1000);
    requests.shift().reject(new Error('restart'));
    await settle();
    assert.match(element('#updater-connection').textContent, /自动重连/);
    runTimer(2000);
    requests.shift().resolve({ok: true, json: async () => ({code: 1, data: {...state, checked_at: 'new',
        status: 'updated', current_version: '1.2.14', message: '<img onerror=alert(1)>', finished_at: 'now'}})});
    await settle();
    assert.equal(element('#updater-current').textContent, 'v1.2.14');
    assert.equal(element('#updater-status').textContent, '更新完成');
    assert.equal(element('#updater-check-button').disabled, false);
    assert.equal(element('#updater-result').textContent, '<img onerror=alert(1)>');
    attached = false; stop();
    assert.equal(timers.size, 0, 'Leaving the page retained polling timers');
    console.log('Updater live progress and reconnection interactions passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
