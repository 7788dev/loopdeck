'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

module.exports = function (html) {
    const template = html.match(/<template id="redemption-form-template">([\s\S]*?)<\/template>/)[1];
    const attributes = tag => Object.fromEntries([...tag.matchAll(/([\w-]+)="([^"]*)"/g)].map(match => [match[1], match[2]]));
    const fields = {};
    for (const [tag] of template.matchAll(/<input\b[^>]*>/g)) {
        const attrs = attributes(tag);
        fields[attrs.name] = {value: attrs.value, min: Number(attrs.min), max: Number(attrs.max),
            addEventListener(event, handler) {this[event] = handler;}};
    }
    assert.deepEqual(Object.keys(fields).sort(), ['account_limit', 'num', 'vip_days'], 'Use exactly one account total');
    const options = [...template.matchAll(/<option\b[^>]*>/g)].map(([tag]) => {
        const attrs = attributes(tag);
        return {value: attrs.value, dataset: {days: attrs['data-days'], accounts: attrs['data-accounts']}};
    });
    const preset = {value: '', options, addEventListener(event, handler) {this[event] = handler;},
        get selectedIndex() {return options.findIndex(option => option.value === this.value);}};
    const summary = {textContent: ''};
    const customTag = template.match(/<div\b[^>]*id="km-custom-fields"[^>]*>/)[0];
    const customClasses = new Set(attributes(customTag).class.split(/\s+/));
    const customFields = {classList: {toggle(name, enabled) {
        if (enabled) customClasses.add(name); else customClasses.delete(name);
    }}};
    const formNodes = {'#km-preset': preset, '#km-summary': summary, '#km-custom-fields': customFields};
    const form = {elements: fields, querySelector: selector => formNodes[selector],
        reportValidity: () => Object.values(fields).every(field => field.value !== '' && /^\d+$/.test(field.value)
            && Number(field.value) >= field.min && Number(field.value) <= field.max)};
    let opened, requests = [], reloads = 0, closed = 0;
    const text = {};
    const dom = {find: selector => selector === 'form' ? [form] : {text: value => {text.button = value;}}};
    const $ = selector => ({html: () => template, text: value => {text[selector] = value;}, attr() {},
        DataTable: () => ({ajax: {reload() {reloads++;}}})});
    $.extend = () => {};
    $.fn = {dataTable: {ext: {classes: {}}, defaults: {}}};
    const context = vm.createContext({$, window: {innerWidth: 375, innerHeight: 812},
        layer: {open: options => {opened = options;}, close: () => {closed++;}, msg() {}},
        x: {renderText() {}, escapeHtml: String, notify() {},
            ajax: (url, data, success, failure) => requests.push({url, data, success, failure})}});
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/static/js/admin_kmsList_datatables.js'), 'utf8'), context);
    context.ajax_add_km();
    const dialog = opened;
    assert.equal(dialog.area[0], '343px', 'Generation dialog must fit a phone viewport');
    dialog.success(dom);
    assert.equal(preset.value, '1');
    assert.equal(customClasses.has('d-none'), true, 'Default monthly plan must hide custom fields');
    assert.match(summary.textContent, /会员 30 天.*账号总数 7 个/);
    dialog.yes(1, dom);
    assert.deepEqual(JSON.parse(JSON.stringify(requests[0].data)), {vip_days: '30', account_limit: '7', num: '1'},
        'Hidden monthly values must still be submitted');
    requests[0].failure(); requests = [];
    for (const option of options.filter(option => option.value !== '')) {
        preset.value = option.value; preset.change();
        assert.equal(customClasses.has('d-none'), true, 'Every preset must hide custom fields');
        assert.equal(fields.vip_days.value, option.dataset.days);
        assert.equal(fields.account_limit.value, option.dataset.accounts);
    }
    assert.match(summary.textContent, /永久会员.*账号数量不限/);
    dialog.yes(1, dom);
    assert.deepEqual(JSON.parse(JSON.stringify(requests[0].data)), {vip_days: '0', account_limit: '0', num: '1'},
        'Hidden permanent plan values must still be submitted');
    requests[0].failure(); requests = [];
    preset.value = '2'; preset.change();
    preset.value = ''; preset.change();
    assert.equal(customClasses.has('d-none'), false, 'Custom mode must show both editable fields');
    assert.equal(fields.vip_days.value, '90', 'Switching to custom must retain the selected benefits');
    assert.equal(fields.account_limit.value, '10');
    fields.account_limit.value = '7'; fields.account_limit.input();
    assert.equal(preset.value, '');
    assert.match(summary.textContent, /账号总数 7 个/);
    preset.value = '0'; preset.change();
    assert.equal(customClasses.has('d-none'), true, 'Returning to a preset must hide custom fields again');
    assert.equal(fields.vip_days.value, '7');
    assert.equal(fields.account_limit.value, '3', 'Preset selection must replace custom values');
    preset.value = ''; preset.change();
    assert.equal(customClasses.has('d-none'), false, 'Repeated switches must restore custom fields');
    for (const value of ['', '-1', '1.5', '100001']) {
        fields.account_limit.value = value; dialog.yes(1, dom);
        assert.equal(requests.length, 0, 'Invalid total reached the endpoint');
    }
    fields.vip_days.value = '0'; fields.account_limit.value = '0'; fields.account_limit.input();
    assert.match(summary.textContent, /永久会员.*账号数量不限/);
    dialog.yes(1, dom);
    assert.equal(requests.length, 1, 'Both-zero permanent and unlimited plan was rejected');
    assert.deepEqual(JSON.parse(JSON.stringify(requests[0].data)), {vip_days: '0', account_limit: '0', num: '1'});
    requests[0].failure(); requests = [];
    fields.vip_days.value = '30'; fields.account_limit.value = '7'; fields.num.value = '2';
    dialog.yes(1, dom); dialog.yes(1, dom);
    assert.equal(requests.length, 1, 'Repeated click generated multiple batches');
    assert.equal(dialog.cancel(), false);
    assert.equal(requests[0].url, '/admin/ajax/data/add/km');
    assert.deepEqual(JSON.parse(JSON.stringify(requests[0].data)), {vip_days: '30', account_limit: '7', num: '2'});
    requests[0].failure();
    assert.equal(dialog.cancel(), true, 'Network failure left the form blocked');
    dialog.yes(1, dom); requests[1].success({code: 0, message: 'rejected'});
    assert.equal(closed, 0, 'Rejected request discarded the form');
    dialog.yes(1, dom);
    requests[2].success({code: 1, data: {copy: 'fixture-a\nfixture-b\n', count: 2, benefits: '会员 30 天 · 账号总数 7 个'}});
    assert.equal(closed, 1);
    assert.equal(reloads, 1);
    opened.success();
    assert.match(opened.content, /共 2 张，每张：会员 30 天 · 账号总数 7 个/);
    assert.ok(opened.content.includes('fixture-a\nfixture-b\n'), 'Codes must be present before the dialog measures its height');
    console.log('Rendered redemption form presets, validation, retries and copy interactions passed');
};
