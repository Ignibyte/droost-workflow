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

// F-260: what the client decided the site will not show is set aside by
// decision, named in decided.json, and never a way round the reference. P9 ·
// run 3 met it: the client hid every `#` link until an editor sets it (Q6),
// and a whole page failed D2 on each one the source shows.
const page = (url, texts, links = []) => ({
  ...reading(url, links),
  texts: texts.map(([text, tag, y, inHeader]) => ({ text, tag, x: 40, y, w: 200, h: 20, font: 'Arial', size: 14, weight: 400, transform: 'none', color: 'rgb(31, 41, 55)', ground: 'rgb(255, 255, 255)', inHeader: !!inHeader, inFooter: false })),
});

test('a text set aside by decision is left out of the reference, and only that text', () => {
  const ref = page('http://localhost:4323/business', [['Business Internet', 'h1', 120], ['Learn More', 'a', 400], ['Dark Fiber', 'h4', 460]]);
  const hid = page('https://united.ddev.site/business', [['Business Internet', 'h1', 120], ['Dark Fiber', 'h4', 460]]);
  const lost = page('https://united.ddev.site/business', [['Business Internet', 'h1', 120]]);
  const d2 = (rows) => rows.find((r) => r.id === 'D2');
  assert.equal(d2(parity.judge(ref, hid, 'page')).verdict, 'FAIL', 'without the decision the hidden link is missing');
  assert.equal(d2(parity.judge(ref, hid, 'page', new Set(['learn more']))).verdict, 'PASS');
  assert.equal(d2(parity.judge(ref, lost, 'page', new Set(['learn more']))).verdict, 'FAIL', 'a text no decision names is still held');
});

test('a header link set aside by decision leaves D6 too', () => {
  const ref = reading('http://localhost:4323/', [['Internet', '/internet.html'], ['Spanish', '#']]);
  const site = reading('https://united.ddev.site/', [['Internet', '/internet']]);
  const d6 = (rows) => rows.find((r) => r.id === 'D6');
  assert.equal(d6(parity.judge(ref, site, 'frame')).verdict, 'FAIL');
  assert.equal(d6(parity.judge(ref, site, 'frame', new Set(['spanish']))).verdict, 'PASS');
});

test('decided.json is read only as recorded decisions about texts the reference holds', () => {
  const fs = require('fs');
  const os = require('os');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'parity-decided-'));
  const questions = path.join(dir, 'questions.md');
  fs.writeFileSync(questions, '| Id | Question |\n|---|---|\n| Q6 | What should the # links do? |\n| Q7 | Spanish? |\n');
  const views = [{ route: '/business', texts: ['Business Internet', 'Learn More'], links: ['Internet', 'Spanish'] }, { route: '/', texts: ['Home'], links: ['Spanish'] }];
  const write = (doc) => fs.writeFileSync(path.join(dir, 'decided.json'), JSON.stringify(doc));
  const read = () => parity.readDecided(dir, views, questions);

  assert.deepEqual(read(), { entries: [] }, 'no file, nothing set aside');
  write({ set_aside: [{ route: '/business', text: 'Learn More', decided: 'Q6' }, { route: '*', text: 'Spanish', decided: 'Q7' }] });
  assert.equal(read().entries.length, 2);
  assert.deepEqual([...parity.asideFor(read().entries, '/')], ['spanish']);
  assert.deepEqual([...parity.asideFor(read().entries, '/business')].sort(), ['learn more', 'spanish']);

  write({ set_aside: [{ route: '/business', text: 'Learn More' }] });
  assert.match(read().error, /needs a route, a text and the decision/);
  write({ set_aside: [{ route: '/business', text: 'Learn More', decided: 'Q9' }] });
  assert.match(read().error, /cites Q9, which is no question of the intake/);
  write({ set_aside: [{ route: '/business', text: 'Dark Fiber', decided: 'Q6' }] });
  assert.match(read().error, /which the reference does not hold there/);
  write({ set_aside: [{ route: '/', text: 'Learn More', decided: 'Q6' }] });
  assert.match(read().error, /does not hold there/, 'a text held on another route is not held on this one');
  write({ set_aside: [{ route: '/about', text: 'Learn More About Us', decided: 'Q6' }] });
  assert.equal(read().entries.length, 1, 'a page not captured yet is checked once it is');
  write({ set_aside: [{ route: '*', text: 'Nowhere', decided: 'Q6' }] });
  assert.match(read().error, /does not hold there/, 'a text set aside everywhere must be held somewhere');
  write({ set_aside: 'Learn More' });
  assert.match(read().error, /no "set_aside" list/);
  fs.writeFileSync(path.join(dir, 'decided.json'), '{');
  assert.match(read().error, /is not JSON/);
  assert.equal(parity.readDecided(dir, views, path.join(dir, 'none.md')).error, 'decided.json is not JSON: ' + read().error.split('is not JSON: ')[1], 'with no intake, the JSON is still read');
  fs.rmSync(dir, { recursive: true, force: true });
});

// F-262, read in a browser: the header is the page's <header>, not the
// utility bar's <nav> above it, and the header's links are every link the top
// of the frame shows, never the footer's, the page's own or a hidden drawer's.
// United's redesign has exactly this frame. Only with a Playwright to read it
// (DROOST_SOURCE_PLAYWRIGHT_CWD, as the source tests take it).
const PW = process.env.DROOST_SOURCE_PLAYWRIGHT_CWD;
test('the frame of a page whose utility bar is a nav above its header', { skip: !PW && 'no DROOST_SOURCE_PLAYWRIGHT_CWD: the browser half was not run' }, async () => {
  const { chromium } = require(path.join(PW, 'node_modules', 'playwright'));
  const browser = await chromium.launch();
  try {
    const url = 'file://' + path.join(__dirname, 'fixtures', 'frame.html');
    const r = await parity.read(browser, url, 1280);
    assert.equal(r.header.position, 'sticky', 'the header is the sticky <header>, not the bar');
    assert.equal(r.header.h, 80);
    assert.deepEqual(r.navLinks.map((a) => a.text), ['My Account', '800-779-2227', 'United', 'Internet', 'Business', 'Check availability']);
  } finally {
    await browser.close();
  }
});

// F-264, read in a browser: a tall page that scrolls smoothly is read once it
// is back at its top. United's 390 reading began 54px down, and its sticky
// header read at y=90 for 36.
test('a smooth-scrolling page is read at its top', { skip: !PW && 'no DROOST_SOURCE_PLAYWRIGHT_CWD: the browser half was not run' }, async () => {
  const { chromium } = require(path.join(PW, 'node_modules', 'playwright'));
  const browser = await chromium.launch();
  try {
    const r = await parity.read(browser, 'file://' + path.join(__dirname, 'fixtures', 'smooth.html'), 390);
    assert.equal(r.header.y, 36, 'the sticky header where it sits at the top, below the 36px bar');
  } finally {
    await browser.close();
  }
});

// F-265, read in a browser: CSS-generated text is read as it draws. United's
// build numbers its steps with a counter where the source types "01".
test('generated text is read: a counter as numbered, a string as written', { skip: !PW && 'no DROOST_SOURCE_PLAYWRIGHT_CWD: the browser half was not run' }, async () => {
  const { chromium } = require(path.join(PW, 'node_modules', 'playwright'));
  const browser = await chromium.launch();
  try {
    const r = await parity.read(browser, 'file://' + path.join(__dirname, 'fixtures', 'generated.html'), 1280);
    const texts = r.texts.map((t) => t.text);
    assert.ok(texts.includes('01') && texts.includes('02'), 'the counter numbers: ' + JSON.stringify(texts));
    assert.ok(texts.includes('New'), 'a ::before string');
    assert.ok(texts.includes('Plain') && !texts.includes(''), 'an empty ::before adds nothing');
  } finally {
    await browser.close();
  }
});
