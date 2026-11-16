'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../public/static/js/anyrouter-admin.js'), 'utf8');
const jquery = fs.readFileSync(path.join(__dirname, '../public/static/ruoyi/js/jquery.min.js'), 'utf8');
const method = jquery.match(/\bserialize:(function\(\)\{[\s\S]+?\}),serializeArray:function/);
assert.ok(method, 'Exercise the shipped serializer, including unchecked controls');
const state = {saved: '0', savedKind: 'builtin', requests: [], reloads: 0, groups: {}, notifications: []};
const fields = {};
function field(name, value, defaultValue = '') {
    const item = {name, value, defaultValue, disabled: false, checked: false,
        val(next) {if (next === undefined) return this.value; this.value = next; return this;},
        prop(key, next) {if (next === undefined) return this[key]; this[key] = next; return this;},
        attr() {return this.defaultValue;}};
    fields[name] = item;
    return item;
}
field('kind', 'builtin');
field('source', 'https://8.8.8.8/sub?token=fixture-private-token');
field('clear', '1');
field('http_sources', 'https://8.8.8.8/edited.txt', 'https://8.8.8.8/default.txt');
field('socks5_sources', '', 'https://1.1.1.1/default.txt');
const lists = {prop(key, value) {fields.http_sources[key] = value; fields.socks5_sources[key] = value; return this;},
    each(callback) {callback.call(fields.http_sources); callback.call(fields.socks5_sources); return this;}};
const button = {disabled: false, label: '保存 ANY 平台代理',
    prop(key, value) {if (value === undefined) return this[key]; this[key] = value; return this;},
    text(value) {if (value === undefined) return this.label; this.label = value; return this;},
    attr() {return '正在保存…';}};
const serializerJquery = selector => selector === 'input[type=radio],input[type=checkbox]'
    ? [{name: 'clear'}] : {length: fields.clear.checked ? 1 : 0};
serializerJquery.each = (items, callback) => items.forEach((item, index) => callback.call(item, index, item));
const serialize = vm.runInNewContext('(' + method[1] + ')', { $: serializerJquery,
    ke: {param: rows => new URLSearchParams(rows.map(row => [row.name, row.value])).toString()}});
const form = {
    attr(key) {return key === 'data-proxy-saved' ? state.saved : state.savedKind;},
    find(selector) {
        if (selector === '[name="http_sources"], [name="socks5_sources"]') return lists;
        const name = selector.match(/^\[name="(\w+)"\]$/)?.[1];
        if (name) return fields[name];
        if (selector === 'button[type="submit"]') return button;
        return {toggle(value) {state.groups[selector] = value; return this;},
            text(value) {state.sourceLabel = value; return this;}};
    },
    serializeArray() {
        return Object.values(fields).filter(item => !item.disabled && (item.name !== 'clear' || item.checked))
            .map(item => ({name: item.name, value: item.value}));
    },
    serialize() {return serialize.call(this);}
};
const events = new Map();
const document = {};
const chain = {off() {events.clear(); return this;},
    on(event, selector, handler) {events.set(event, handler); return this;}};
const $ = item => item === document ? chain
    : item === '[data-anyrouter-platform-form]' ? {each(callback) {callback.call(form);}} : item;
$.ajax = options => {
    state.requests.push(options);
    return {done(callback) {state.done = callback; return this;}, fail(callback) {state.fail = callback; return this;},
        always(callback) {state.always = callback; return this;}};
};
const context = vm.createContext({jQuery: $, document, window: {location: {reload() {state.reloads++;}}},
    x: {notify(message) {state.notifications.push(message);}}});
const mount = () => vm.runInContext(source, context);
const change = () => events.get('change.ldAnyrouterAdmin').call({closest() {return form;}});
const submit = () => events.get('submit.ldAnyrouterAdmin').call(form, {preventDefault() {}});
mount();
assert.equal(state.groups['[data-anyrouter-builtin]'], true);
assert.equal(fields.source.disabled, true, 'A builtin save should not submit a private source');
assert.equal(fields.http_sources.disabled, false);
fields.kind.value = 'subscription';
change();
assert.equal(fields.source.required, true);
assert.equal(fields.source.disabled, false);
assert.equal(fields.http_sources.disabled, true, 'A hidden list editor must not overwrite saved list sources');
assert.equal(state.sourceLabel, '订阅链接');
submit();
assert.equal(state.requests[0].url, '/admin/ajax/set/anyrouterProxy');
assert.equal(new URLSearchParams(state.requests[0].data).get('clear'), '', 'Unchecked clear must retain the real empty value');
assert.equal(new URLSearchParams(state.requests[0].data).get('source'), fields.source.value);
assert.equal(new URLSearchParams(state.requests[0].data).has('http_sources'), false);
submit();
assert.equal(state.requests.length, 1, 'A settings save was duplicated in flight');
state.fail(); state.always();
assert.equal(button.disabled, false);
assert.equal(button.label, '保存 ANY 平台代理');
assert.equal(state.reloads, 0);
state.saved = '1'; state.savedKind = 'subscription';
change();
assert.equal(fields.source.required, false, 'Keeping the same saved source required re-entering its secret');
fields.kind.value = 'proxy'; change();
assert.equal(fields.source.required, true, 'A type change incorrectly reused a secret from a different protocol');
assert.equal(state.sourceLabel, 'HTTP / SOCKS5 代理地址');
fields.clear.checked = true; change();
assert.equal(fields.source.disabled, true);
assert.equal(state.groups['[data-anyrouter-builtin]'], true);
submit();
const cleared = new URLSearchParams(state.requests[1].data);
assert.equal(cleared.get('clear'), '1');
assert.equal(cleared.has('source'), false);
assert.equal(cleared.get('http_sources'), 'https://8.8.8.8/edited.txt');
state.done({code: 0, message: '请重试'}); state.always();
assert.equal(state.reloads, 0);
events.get('click.ldAnyrouterAdmin').call({closest() {return form;}});
assert.equal(fields.kind.value, 'builtin');
assert.equal(fields.clear.checked, false);
assert.equal(fields.http_sources.value, fields.http_sources.defaultValue);
assert.equal(fields.socks5_sources.value, fields.socks5_sources.defaultValue);
submit();
const restored = new URLSearchParams(state.requests[2].data);
assert.equal(restored.get('kind'), 'builtin');
assert.equal(restored.get('clear'), '');
assert.equal(restored.get('http_sources'), fields.http_sources.defaultValue);
assert.equal(restored.has('source'), false);
state.done({code: 1, message: '已保存'}); state.always();
assert.equal(state.reloads, 1);
mount();
assert.equal(events.size, 3, 'PJAX accumulated duplicate administrator handlers');
if (process.argv.includes('--emit-bodies')) {
    process.stdout.write(JSON.stringify(state.requests.map(request => request.data)));
} else {
    console.log('AnyRouter administrator source selection and shipped serializer interactions passed');
}
