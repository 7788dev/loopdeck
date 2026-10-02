'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const pages = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
let count = 0;
for (const page of ['admin/system/data/kms.html', 'admin/ruoyi/system/data/kms.html']) {
    if (pages[page]) require('./RedemptionFormInteraction.js')(pages[page]);
}
for (const [file, html] of Object.entries(pages)) {
    for (const match of html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
        if (/\btype=["'](?:application\/ld\+json|application\/json|text\/template)/i.test(match[1])) continue;
        try { new vm.Script(match[2], {filename: file}); count++; }
        catch (error) { console.error(error.stack); process.exitCode = 1; }
    }
}
if (!process.exitCode) console.log(`Parsed ${count} rendered script blocks`);
