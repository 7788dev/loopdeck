'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

// Exercise the actual AnyRouter script: proxy switch, secret-field submission and PJAX rebinding.
{
    const events = new Map();
    const state = {mode: 'platform', input: {saved: '0'}, custom: {}, help: {}, requests: [], label: '验证并添加', reloads: 0};
    const input = {prop(k, v) {state.input[k] = v; return this;}, attr(k) {return k === 'data-proxy-saved' ? state.input.saved : undefined;}};
    const fieldset = {find(selector) {
        if (selector.includes(':checked')) return {val() {return state.mode;}};
        if (selector === '[name="proxy_url"]') return input;
        const target = selector === '[data-anyrouter-custom]' ? state.custom : state.help;
        return {prop(k, v) {target[k] = v; return this;}, toggle(visible) {target.visible = visible; return this;}};
    }};
    const button = {prop(k, v) {if (v === undefined) return state[k]; state[k] = v; return this;}, text(v) {if (v === undefined) return state.label; state.label = v; return this;},
        attr() {return '正在验证并保存…';}};
    const form = {attr() {return 'save';}, serialize() {return 'api_user=42&cookie=fixture' + (state.input.disabled ? '' : '&proxy_url=fixture-proxy');},
        find() {return button;}};
    const document = {};
    const chain = {off() {events.clear(); return this;}, on(event, selector, fn) {events.set(event, fn); return this;}};
    const $ = object => object === document ? chain
        : object === '[data-anyrouter-proxy]' ? {each(fn) {fn.call(fieldset);}} : object;
    $.ajax = options => {
        state.requests.push(options);
        const callbacks = {done(fn) {state.done = fn; return this;}, fail(fn) {state.fail = fn; return this;}, always(fn) {state.always = fn; return this;}};
        return callbacks;
    };
    const {context: sharedContext} = environment();
    vm.runInContext(read('public/static/js/app.min.js'), sharedContext);
    sharedContext.x.ajax = (url, data, success, fail) => {state.toggle = {url, data, success, fail};};
    sharedContext.x.notify = () => {};
    const context = vm.createContext({jQuery: $, document, x: sharedContext.x,
        layer: {open(options) {state.dialog = options;}, close() {state.closed = true;}},
        window: {innerWidth: 390, location: {pathname: '/index/console/anyrouter/info/42', reload() {state.reloads++;}}}});
    const source = read('public/static/js/anyrouter.js');
    vm.runInContext(source, context);
    assert.equal(state.custom.hidden, true);
    assert.equal(state.input.disabled, true);
    state.mode = 'custom';
    events.get('change.ldAnyrouter').call({closest() {return fieldset;}});
    assert.equal(state.custom.hidden, false);
    assert.equal(state.input.required, true);
    assert.equal(state.input.disabled, false);
    state.input.saved = '1';
    events.get('change.ldAnyrouter').call({closest() {return fieldset;}});
    assert.equal(state.input.required, false, 'Saved proxy cannot be retained with an empty input');
    events.get('submit.ldAnyrouter').call(form, {preventDefault() {}});
    assert.match(state.requests[0].data, /proxy_url/);
    assert.equal(state.disabled, true);
    state.always();
    assert.equal(state.disabled, false);
    state.mode = 'platform';
    vm.runInContext(source, context);
    assert.equal(events.size, 4, 'PJAX rebind duplicated handlers');
    events.get('submit.ldAnyrouter').call(form, {preventDefault() {}});
    assert.doesNotMatch(state.requests[1].data, /proxy_url/, 'Platform mode submitted the hidden custom proxy');
    state.always();
    const toggle = {checked: true, disabled: false, attr(k) {return k === 'data-anyrouter-toggle' ? 'sign_in' : '42';}};
    events.get('change.ldAnyrouter.toggle').call(toggle);
    assert.equal(state.toggle.url, '/index/ajax/anyrouter/toggle');
    assert.equal(state.toggle.data.user_id, '42');
    assert.equal(toggle.disabled, true);
    state.toggle.success({code: 0, message: 'VIP required'});
    assert.equal(toggle.checked, false, 'Rejected AnyRouter switch did not roll back');
    assert.equal(toggle.disabled, false);
    assert.equal(state.reloads, 0, 'Rejected switch reloaded the page');
    toggle.checked = true;
    events.get('change.ldAnyrouter.toggle').call(toggle);
    state.toggle.fail();
    assert.equal(toggle.checked, false, 'Failed AnyRouter request did not roll back');
    assert.equal(state.reloads, 0, 'Failed request reloaded the page');
    toggle.checked = true;
    events.get('change.ldAnyrouter.toggle').call(toggle);
    state.toggle.success({code: 1, message: '已启用签到'});
    assert.equal(toggle.checked, true);
    assert.equal(state.reloads, 1, 'Enabling AnyRouter did not refresh status and manual-run availability');
    toggle.checked = false;
    events.get('change.ldAnyrouter.toggle').call(toggle);
    state.toggle.success({code: 1, message: '已暂停签到'});
    assert.equal(toggle.checked, false);
    assert.equal(state.reloads, 2, 'Pausing AnyRouter did not refresh status and manual-run availability');
    button.attr = key => ({'data-anyrouter-action': 'timing', 'data-user-id': '42', 'data-timing': '09:00'})[key];
    events.get('click.ldAnyrouter').call(button);
    assert.equal(state.dialog.area[0], '358px', 'Timing dialog exceeds mobile viewport');
    state.dialog.yes(1, {find() {return {val() {assert.equal(state.closed, undefined); return '10:30';}};}});
    assert.equal(state.requests[2].url, '/index/ajax/anyrouter/timing');
    assert.equal(state.requests[2].data.timing, '10:30', 'Timing must be read before closing the dialog');
    events.get('click.ldAnyrouter').call(button);
    state.dialog.yes(2, {find() {return {val() {return '10:30';}};}});
    assert.equal(state.requests.length, 3, 'In-flight action was submitted twice');
}

function scripts(file) {
    return [...read(file).matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)]
        .map(match => match[1]).join('\n')
        .replace(/\{(?:if\b[^}]*|else\b[^}]*|\/if)\}/g, '')
        .replace(/\{(?:\$|:)[^}]*\}/g, '42');
}

function environment() {
    const state = {closed: false, sent: [], events: {}, values: {'#timing': '18:35', '#km': 'fixture-code'}};
    const chain = {pjax() {return this;}, on(event, handler) {state.events[event] = handler; return this;}, off() {return this;}, each() {return this;}, ready() {return this;}, removeClass() {return this;},
        DataTable() {return {ajax: {reload() {}}};},
        find() {return this;}, prop(name, value) {state[name] = value; return this;},
        flatpickr() {return this;},
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
        layer: {open(options) {state.dialog = options;}, close() {state.closed = true;}, load() {return 2;}, msg() {}, closeAll() {}},
        x: {getval: selector => state.closed && selector === '#timing' ? '' : state.values[selector],
            escapeHtml: value => String(value == null ? '' : value).replace(/[&<>"']/g,
                character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character])),
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
{
    // The edit dialog must initialize its own date pickers: the theme helper
    // it used to call does not exist in this build, which made the VIP dates
    // read-only with no picker at all.
    const {state, context} = environment();
    vm.runInContext(read('public/static/js/admin_usersList_datatables.js'), context);
    context.ajax_edit_user(2);
    context.x.ajax = (url, data, onSuccess) => onSuccess({
        uid: 2, username: 'fixture', qq: '12345', mail: 'fixture@example.com',
        quota: 5, vip_start: '2026-01-01 08:00:00', vip_end: '2026-02-01', state: 1,
    });
    state.dialog.success(1, {});
    assert.equal(state.values['#vip_start'], '2026-01-01',
        'The user dialog kept a datetime value a date-only picker cannot parse');
    assert.equal(state.values['#vip_end'], '2026-02-01',
        'The user dialog dropped the VIP end date');
}
{
    // The admin notice add dialog used to read an undefined `content`
    // variable, so the button threw a ReferenceError and never submitted.
    const {state, context} = environment();
    vm.runInContext(read('public/static/js/admin_noticesList_datatables.js'), context);
    vm.runInContext(scripts('app/admin/view/system/data/notices.html'), context);
    context.ajax_add_notice2();
    state.dialog.yes(1, {});
    assert.equal(state.sent.length, 0,
        'The notice add dialog submitted without reading the editor content');
}
for (const platform of ['netease', 'bilibili']) {
    const {state, context} = environment();
    vm.runInContext(scripts(`app/index/view/console/${platform}/info.html`), context);
    context.ajax_set_timing('42', '08:00');
    assert.equal(state.dialog.area[0], '343px', `${platform}: dialog must fit mobile viewport`);
    state.dialog.yes(1, {});
    assert.equal(state.sent[0].data.timing, '18:35', `${platform}: closing the dialog discarded the selected time`);
    assert.match(read(`app/index/view/console/${platform}/info.html`),
        /data-task-toggle="\{\$[^}]+\}" data-user-id="\{\$[^}]+\}"/);
    assert.doesNotMatch(read(`app/index/view/console/${platform}/info.html`),
        /onclick="ajax_set_zt\(/);
}
for (const [platform, functions] of Object.entries({netease: ['musicianTask', 'evaluateTask', 'dakaTask'], bilibili: ['coinAdd']})) {
    for (const fn of functions) {
        const {state, context} = environment();
        vm.runInContext(scripts(`app/index/view/console/${platform}/info.html`), context);
        context[fn]('fixture', '<img src=x onerror=alert(1)>', '{}');
        assert.ok(!state.dialog.title.includes('<img') && state.dialog.title.includes('&lt;img'),
            `${fn}: task title reached the layer as HTML`);
        context[fn]('fixture', '任务配置', '{}');
        state.dialog.yes(1, {});
        assert.equal(JSON.parse(state.sent[0].data.config).selected, 'saved-value', `${fn}: closing the dialog discarded configuration`);
    }
}
{
    const {context} = environment();
    vm.runInContext(scripts('app/index/view/console/bilibili/info.html'), context);
    assert.equal(typeof context.globalRoom, 'undefined', 'The retired live-room dialog is still callable');
    assert.doesNotMatch(read('app/index/view/console/bilibili/info.html'), /globalRoom|global_room|直播间/,
        'Retired live-room configuration markup returned to the Bilibili console page');
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

// Execute the active navigation handler at the responsive breakpoint edges.
for (const [theme, widths] of [['ruoyi', [390, 767, 768, 1440]]]) {
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
            delete context.Codebase;
            vm.runInContext(read('public/static/js/app.min.js'), context);
            context.x.reload = () => {refreshed++;};
            state.events['pjax:complete']();
            state.events['pjax:complete']();
            const mobile = width < 768;
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
    const ancestorClasses = new Set(['collapse']);
    const noOp = {removeClass(name) {ancestorClasses.delete(name); return this;}, addClass(name) {ancestorClasses.add(name); return this;}, css() {return this;}};
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
    assert.deepEqual([...ancestorClasses], ['in'], 'Route activation left contradictory collapse and expanded states');
    context.window.location.href = links[1].href + '?filter=all';
    context.x.reload();
    assert.deepEqual([...rows], ['settings'], 'Previous RuoYi menu group retained its active highlight');
    assert.deepEqual(links.map(link => link.active), [false, true], 'More than one leaf remained active');
}


// The notices preview must decode entities like a browser: strip real tags
// first, decode once, and let the caller's escapeHtml re-escape the result.
{
    const {context} = environment();
    vm.runInContext(read('public/static/js/admin_noticesList_datatables.js'), context);
    assert.equal(context.cutstr('<p>下午&nbsp;茶</p>', 30), '下午 茶',
        'The notice preview rendered &nbsp; as literal text instead of a space');
    assert.equal(context.cutstr('&lt;b&gt;重点&lt;/b&gt;&amp;&#39;x&#x41;', 30), '<b>重点</b>&\'xA',
        'cutstr must decode entities exactly once for the escaped preview');
    assert.equal(context.cutstr('&amp;lt; stays &amp;', 30), '&lt; stays &',
        'cutstr must not double-decode entity text');
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
