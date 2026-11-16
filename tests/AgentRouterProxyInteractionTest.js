'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../public/static/js/agentrouter-admin.js'), 'utf8');
const jquery = fs.readFileSync(path.join(__dirname, '../public/static/ruoyi/js/jquery.min.js'), 'utf8');
const serializeMethod = jquery.match(/\bserialize:(function\(\)\{[\s\S]+?\}),serializeArray:function/);
assert.ok(serializeMethod, 'The page serializer must be exercised instead of assuming standard jQuery behavior');
const state = {saved: '0', savedKind: 'subscription', kind: 'subscription', clear: false,
    input: {}, label: '保存平台代理', requests: [], reloads: 0};
const serializerJquery = selector => selector === 'input[type=radio],input[type=checkbox]'
    ? [{name: 'clear'}] : {length: state.clear ? 1 : 0};
serializerJquery.each = (items, fn) => items.forEach((item, index) => fn.call(item, index, item));
const serialize = vm.runInNewContext('(' + serializeMethod[1] + ')', {
    $: serializerJquery,
    ke: {param: rows => new URLSearchParams(rows.map(row => [row.name, row.value])).toString()}
});
const events = new Map();
const document = {};
const button = {
    prop(key, value) { if (value === undefined) return state[key]; state[key] = value; return this; },
    text(value) { if (value === undefined) return state.label; state.label = value; return this; },
    attr() { return '正在保存…'; }
};
const form = {
    attr(key) { return key === 'data-proxy-saved' ? state.saved : state.savedKind; },
    find(selector) {
        if (selector === '[name="clear"]') return {prop() {return state.clear;}};
        if (selector === '[name="kind"]') return {val() {return state.kind;}};
        if (selector === '[name="source"]') return {prop(key, value) {state.input[key] = value; return this;}};
        return button;
    },
    serializeArray() {
        return [{name: 'kind', value: state.kind}, {name: 'origin', value: 'https://agentrouter.org'}]
            .concat(state.clear ? [{name: 'clear', value: '1'}] : [])
            .concat(state.input.disabled ? [] : [{name: 'source', value: 'fixture-private-source'}]);
    },
    serialize() { return serialize.call(this); }
};
const chain = {off() {events.clear(); return this;}, on(event, selector, handler) {events.set(event, handler); return this;}};
const $ = item => item === document ? chain
    : item === '[data-agentrouter-platform-form]' ? {each(handler) {handler.call(form);}} : item;
$.ajax = options => {
    state.requests.push(options);
    return {done(fn) {state.done = fn; return this;}, fail(fn) {state.fail = fn; return this;},
        always(fn) {state.always = fn; return this;}};
};
const context = vm.createContext({jQuery: $, document, x: {notify() {}}, window: {location: {reload() {state.reloads++;}}}});
vm.runInContext(source, context);
assert.equal(state.input.required, true, 'A missing platform source was optional');
state.saved = '1';
events.get('change.ldAgentrouterAdmin').call({closest() {return form;}});
assert.equal(state.input.required, false, 'A blank saved source could not be retained');
state.kind = 'proxy';
events.get('change.ldAgentrouterAdmin').call({closest() {return form;}});
assert.equal(state.input.required, true, 'Changing source type accepted an empty secret');
state.clear = true;
events.get('change.ldAgentrouterAdmin').call({closest() {return form;}});
assert.equal(state.input.disabled, true);
assert.equal(state.input.required, false);
events.get('submit.ldAgentrouterAdmin').call(form, {preventDefault() {}});
assert.equal(state.requests[0].url, '/admin/ajax/set/agentrouterProxy');
assert.match(state.requests[0].data, /clear=1/);
assert.doesNotMatch(state.requests[0].data, /source=/, 'Clearing retained a hidden source in the request');
assert.equal(state.disabled, true);
events.get('submit.ldAgentrouterAdmin').call(form, {preventDefault() {}});
assert.equal(state.requests.length, 1, 'An in-flight settings save was repeated');
state.fail();
state.always();
assert.equal(state.disabled, false);
assert.equal(state.label, '保存平台代理');
assert.equal(state.reloads, 0);
state.clear = false;
vm.runInContext(source, context);
assert.equal(events.size, 2, 'PJAX attached multiple settings handlers');
assert.equal(state.input.disabled, false);
events.get('submit.ldAgentrouterAdmin').call(form, {preventDefault() {}});
assert.match(state.requests[1].data, /source=fixture-private-source/);
assert.equal(new URLSearchParams(state.requests[1].data).get('clear'), '',
    'The real page sends an empty unchecked clear field; the save regression must retain that case');
state.done({code: 1, message: '已保存'});
state.always();
assert.equal(state.reloads, 1, 'Saved settings and node records did not refresh');
console.log('AgentRouter administrator proxy setting interactions passed');
