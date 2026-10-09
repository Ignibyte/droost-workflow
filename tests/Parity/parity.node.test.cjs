// droost-parity's D6, tested under node: a header link is compared by where
// it goes, not how it is spelled (F-258). P9 · run 2 met it: United's static
// source links `/internet.html`, the site serves `/internet`, and D6 failed a
// correct build.
'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('path');
const parity = require(path.join(__dirname, '..', '..', 'bin', 'droost-parity'));

test('a page of the source is the site\'s clean path', () => {
  const src = 'http://localhost:4321/';
  const site = 'https://united.ddev.site/';
  assert.equal(parity.linkTarget('/internet.html', src), parity.linkTarget('/internet', site));
  assert.equal(parity.linkTarget('/about/index.html', src), parity.linkTarget('/about/', site));
  assert.equal(parity.linkTarget('/', src), '/');
  assert.equal(parity.linkTarget('http://localhost:4321/tv.html', src), '/tv', 'an absolute link to the page\'s own origin is a path');
});

test('another host keeps its host; a different page stays different', () => {
  assert.equal(parity.linkTarget('https://shop.united.net/', 'http://localhost:4321/'), 'https://shop.united.net');
  assert.notEqual(parity.linkTarget('/internet.html', 'http://a/'), parity.linkTarget('/phone', 'http://b/'));
  assert.notEqual(parity.linkTarget('https://shop.united.net/', 'http://a/'), parity.linkTarget('/shop', 'http://b/'));
  assert.equal(parity.linkTarget('tel:1-800-779-2227', 'http://a/'), 'tel:18007792227');
  assert.equal(parity.linkTarget('#', 'http://a/'), '#');
});

// A reading as droost-parity writes it, with one header text and the links.
const reading = (url, links) => ({
  url,
  status: 200,
  viewport: 1280,
  hscroll: false,
  header: { y: 0, h: 90, bg: 'rgb(255, 255, 255)', bgImage: false, borderTop: '0px rgb(0, 0, 0)', position: 'sticky' },
  footer: { y: 800, h: 200, bg: 'rgb(13, 43, 78)', bgImage: false, borderTop: '0px rgb(0, 0, 0)', position: 'static' },
  navLinks: links.map(([text, href]) => ({ text, href })),
  texts: [{ text: 'Internet', tag: 'a', x: 300, y: 30, w: 60, h: 20, font: 'Arial', size: 14, weight: 500, transform: 'none', color: 'rgb(31, 41, 55)', ground: 'rgb(255, 255, 255)', inHeader: true, inFooter: false, link: '/internet' }],
});

test('D6 passes a site whose links go where the source\'s go, and fails one whose do not', () => {
  const ref = reading('http://localhost:4321/', [['Internet', '/internet.html'], ['Check Availability', 'https://shop.united.net/']]);
  const good = reading('https://united.ddev.site/', [['Internet', '/internet'], ['Check Availability', 'https://shop.united.net/']]);
  const bad = reading('https://united.ddev.site/', [['Internet', '/phone'], ['Check Availability', 'https://shop.united.net/']]);
  const d6 = (rows) => rows.find((r) => r.id === 'D6');
  assert.equal(d6(parity.judge(ref, good, 'frame')).verdict, 'PASS', JSON.stringify(d6(parity.judge(ref, good, 'frame'))));
  assert.equal(d6(parity.judge(ref, bad, 'frame')).verdict, 'FAIL');
});
