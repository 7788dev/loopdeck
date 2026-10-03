'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const assert = require('node:assert/strict');
const pages = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const version = fs.readFileSync(path.join(__dirname, '../VERSION'), 'utf8').trim();
const shellPages = ['index/console/index.html', 'admin/system/index.html',
    'index/ruoyi/console/index.html', 'admin/ruoyi/system/index.html'];
for (const name of shellPages) assert.ok(pages[name], `Missing rendered navigation shell: ${name}`);
let count = 0;
for (const page of ['admin/system/data/kms.html', 'admin/ruoyi/system/data/kms.html']) {
    if (pages[page]) require('./RedemptionFormInteraction.js')(pages[page]);
}
for (const [file, html] of Object.entries(pages)) {
    const urls = [...html.matchAll(/<script\b[^>]*\bsrc=["']([^"']+)["']/gi)]
        .map(match => new URL(match[1].replace(/&amp;/g, '&'), 'http://fixture.local'))
        .filter(url => url.pathname === '/static/js/app.min.js');
    if (shellPages.includes(file)) assert.equal(urls.length, 1, `${file}: navigation script missing or loaded twice`);
    for (const url of urls) {
        assert.equal(url.searchParams.get('v'), version,
            `${file}: cached navigation script can survive an application upgrade`);
    }
    for (const match of html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
        if (/\btype=["'](?:application\/ld\+json|application\/json|text\/template)/i.test(match[1])) continue;
        try { new vm.Script(match[2], {filename: file}); count++; }
        catch (error) { console.error(error.stack); process.exitCode = 1; }
    }
}
if (!process.exitCode) console.log(`Parsed ${count} rendered script blocks`);
