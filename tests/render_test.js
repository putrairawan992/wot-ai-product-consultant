#!/usr/bin/env node
/**
 * Verifies the shipped frontend renderer (public/index.html) escapes model output
 * and only links first-party product URLs. Extracts the real renderer source from
 * the shipped file, so the test cannot drift from production code.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const htmlPath = path.join(__dirname, '..', 'public', 'index.html');
const html = fs.readFileSync(htmlPath, 'utf8');

const start = html.indexOf("var WOT_HOST");
const end = html.indexOf('function addMessage');
if (start === -1 || end === -1 || end <= start) {
  console.error('renderer slice not found in public/index.html');
  process.exit(1);
}

const slice = html.slice(start, end);
const elementStub = () => ({
  style: {}, classList: { add() {}, remove() {} },
  addEventListener() {}, appendChild() {}, setAttribute() {},
  querySelector() { return null; }, textContent: '', innerHTML: '', value: '',
});
const documentStub = { getElementById: elementStub, createElement: elementStub, head: elementStub(), body: elementStub() };
const localStorageStub = { getItem() { return null; }, setItem() {}, removeItem() {} };

const factory = new Function(
  'window', 'document', 'localStorage',
  slice + '\nreturn { renderMarkdown: renderMarkdown, escapeHtml: escapeHtml, safeUrl: safeUrl, setImages: function (m) { images = m; } };'
);
const renderer = factory(
  { location: { href: 'http://127.0.0.1:8088/' }, crypto: undefined, open() {} },
  documentStub,
  localStorageStub
);

const results = [];
function check(name, condition, detail) {
  results.push({ name, pass: !!condition, detail });
  console.log((condition ? 'PASS' : 'FAIL') + '  ' + name + (detail ? '  (' + detail + ')' : ''));
}

// 1) Raw HTML in a reply must never become markup.
const imgPayload = 'Try this <img src=x onerror="alert(1)"> or <script>alert(2)</script>';
const imgOut = renderer.renderMarkdown(imgPayload);
check('img onerror is escaped', imgOut.indexOf('<img') === -1 && imgOut.indexOf('<script') === -1, imgOut.slice(0, 90));
check('img onerror survives as text', imgOut.indexOf('&lt;img') !== -1);

// 2) SVG/onload through a markdown link label.
const labelPayload = '[<svg onload=alert(1)>](https://www.worldoftest.com/brinell-hardness-tester/)';
const labelOut = renderer.renderMarkdown(labelPayload);
check('svg in link label is escaped', labelOut.indexOf('<svg') === -1, labelOut.slice(0, 90));
check('worldoftest product link kept', labelOut.indexOf('href="https://www.worldoftest.com/brinell-hardness-tester/"') !== -1);

// 3) Foreign links degrade to text.
const foreign = renderer.renderMarkdown('See [the guide](https://evil.example/phish) and https://evil.example/raw');
check('foreign markdown link dropped', foreign.indexOf('evil.example</a>') === -1 && foreign.indexOf('<a ') === -1, foreign.slice(0, 120));
check('foreign bare url dropped', foreign.indexOf('<a href="https://evil.example') === -1);

// 4) javascript: and data: schemes never become anchors.
const schemes = renderer.renderMarkdown('[a](javascript:alert(1)) [b](data:text/html,<script>alert(1)</script>)');
check('javascript/data schemes not linkified', schemes.indexOf('<a ') === -1, schemes.slice(0, 100));

// 5) Product image map only injects first-party images.
renderer.setImages({ 'https://www.worldoftest.com/brinell-hardness-tester/': 'https://www.worldoftest.com/sites/default/files/styles/product/x.jpg' });
const withImage = renderer.renderMarkdown('[Brinell Hardness Tester](https://www.worldoftest.com/brinell-hardness-tester/)');
check('catalog image rendered', withImage.indexOf('<img class="prod-img" src="https://www.worldoftest.com/sites/default/files/styles/product/x.jpg"') !== -1);

renderer.setImages({ 'https://www.worldoftest.com/brinell-hardness-tester/': 'https://evil.example/x.jpg' });
const badImage = renderer.renderMarkdown('[Brinell Hardness Tester](https://www.worldoftest.com/brinell-hardness-tester/)');
check('foreign image src dropped', badImage.indexOf('evil.example') === -1);

// 6) Tables still render, cells stay escaped.
const table = renderer.renderMarkdown(
  '| Product | Standard |\n|---|---|\n| Brinell <b>tester</b> | ISO 6506 |'
);
check('table renders', table.indexOf('<table>') !== -1 && table.indexOf('<th>Product</th>') !== -1);
check('table cell escaped', table.indexOf('<b>tester</b>') === -1 && table.indexOf('&lt;b&gt;tester') !== -1);

const failed = results.filter((r) => !r.pass);
console.log('\n' + (results.length - failed.length) + '/' + results.length + ' renderer checks passed');
process.exit(failed.length === 0 ? 0 : 1);
