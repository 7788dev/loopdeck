'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

function scripts(file) {
    return [...read(file).matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)]
        .map(match => match[1]).join('\n')
        .replace(/\{(?:if\b[^}]*|else\b[^}]*|\/if)\}/g, '')
        .replace(/\{(?:\$|:)[^}]*\}/g, '42');
}

function environment() {
    const state = {closed: false, sent: [], values: {'#timing': '18:35', '#money': '12.34'}};
    const chain = {pjax() {return this;}, on() {return this;}, each() {return this;}, ready() {return this;},
        find() {return this;}, prop(name, value) {state[name] = value; return this;},
        parseForm() {return state.closed ? {} : {selected: 'saved-value'};}};
    const $ = () => chain;
    $.fn = {};
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
    vm.runInContext(scripts('app/index/view/console/shop/money.html'), context);
    context.ajax_shop_money('alipay');
    assert.equal(state.sent[0].data.shopid, '12.34', 'Recharge must send the amount, not window.money or an out-of-scope variable');
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
