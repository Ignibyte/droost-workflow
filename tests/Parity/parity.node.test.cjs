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

// F-343: a text set aside takes its space with it. KCH-A run 2 met it: the
// client left the placeholder phone off (Q7), the gate left the phone out of
// the reference, and the copyright line below it, 40px higher on the site
// because the phone's row is gone, failed D5 against where it sat beside it.
test('the texts below a set-aside text are judged without the space it took (D5)', () => {
  const ref = page('http://localhost:5199/', [['Contact', 'h3', 100], ['info@club.example', 'a', 140], ['(816) 555-0199', 'a', 180], ['© 2026 The Club.', 'p', 300]]);
  const lifted = page('https://club.ddev.site/', [['Contact', 'h3', 100], ['info@club.example', 'a', 140], ['© 2026 The Club.', 'p', 260]]);
  const d5 = (rows) => rows.find((r) => r.id === 'D5');
  const aside = new Set(['(816) 555-0199']);
  const pass = d5(parity.judge(ref, lifted, 'page', aside));
  assert.equal(pass.verdict, 'PASS', JSON.stringify(pass));
  assert.match(pass.said, /1 pair\(s\) judged without the 40px of texts set aside above them/);
  // The space is the set-aside text's, and no more: 100px higher still fails.
  const far = page('https://club.ddev.site/', [['Contact', 'h3', 100], ['info@club.example', 'a', 140], ['© 2026 The Club.', 'p', 200]]);
  assert.equal(d5(parity.judge(ref, far, 'page', aside)).verdict, 'FAIL', 'the allowance is the space set aside, not a blank cheque');
  // A text above the set-aside one is judged as it always was.
  const moved = page('https://club.ddev.site/', [['Contact', 'h3', 100], ['info@club.example', 'a', 100], ['© 2026 The Club.', 'p', 260]]);
  assert.equal(d5(parity.judge(ref, moved, 'page', aside)).verdict, 'FAIL', 'only what sits below the set-aside text is lifted');
});

// F-348: the site judged was whatever the run's environment named, and the
// record did not say which. KCH-A run 3's agent set DROOST_PARITY_BASE_URL
// itself (droost ran on the host, where DDEV sets no URL); pointed at a copy
// of the design, the judge would have compared the source with itself.
test('the site judged is the project\'s before the environment\'s, and says where it came from', () => {
  const fs = require('fs'); const os = require('os');
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'parity-base-'));
  const none = {};
  assert.deepEqual(parity.siteBase({}, none, root), { base: '', from: '' }, 'nothing names a site');
  assert.deepEqual(parity.siteBase({}, { DROOST_PARITY_BASE_URL: 'http://localhost:5200' }, root), { base: 'http://localhost:5200', from: 'DROOST_PARITY_BASE_URL' });

  fs.mkdirSync(path.join(root, '.ddev'));
  fs.writeFileSync(path.join(root, '.ddev', 'config.yaml'), 'name: club\ntype: drupal11\n');
  const env = { DROOST_PARITY_BASE_URL: 'http://localhost:5200', PLAYWRIGHT_BASE_URL: 'http://localhost:5201' };
  assert.deepEqual(parity.siteBase({}, env, root), { base: 'https://club.ddev.site', from: '.ddev/config.yaml' }, 'the project\'s own DDEV site wins over an environment variable');
  fs.writeFileSync(path.join(root, '.ddev', 'config.yaml'), 'name: club\nproject_tld: test\n');
  assert.equal(parity.siteBase({}, env, root).base, 'https://club.test');
  assert.deepEqual(parity.siteBase({ base: 'https://other.example' }, env, root), { base: 'https://other.example', from: '--base' }, 'the gate\'s own lever first');
  fs.writeFileSync(path.join(root, '.ddev', 'config.yaml'), 'type: drupal11\ndocroot: web\n');
  assert.equal(parity.siteBase({}, env, root).base, `https://${path.basename(root).toLowerCase().replace(/[^a-z0-9-]/g, '-')}.ddev.site`, 'with no name, DDEV names the project after its directory');
  // F-350: inside the web container the project is /var/www/html, so its
  // directory names nothing; DDEV's own URL is right there, and comes first.
  const inside = { ...env, IS_DDEV_PROJECT: 'true', DDEV_PRIMARY_URL: 'https://club.ddev.site' };
  assert.deepEqual(parity.siteBase({}, inside, root), { base: 'https://club.ddev.site', from: 'DDEV_PRIMARY_URL' }, 'inside the container, DDEV\'s own URL');
});

// F-349: D5 held an element's box, not its text. A block-level <label> is as
// wide as its column, so KCH-A's contact form failed D5 on four labels whose
// text sits exactly where the design's does (width 281 for 45).
test('D5 holds a text\'s own extent when both readings carry it, its box otherwise', () => {
  const label = (w, tw) => ({ text: 'Name', tag: 'label', x: 40, y: 200, w, h: 20, tx: 40, tw, font: 'Inter', size: 14, weight: 500, transform: 'none', color: 'rgb(31, 41, 55)', ground: 'rgb(255, 255, 255)', inHeader: false, inFooter: false });
  const head = { text: 'Contact', tag: 'h2', x: 40, y: 100, w: 300, h: 30, tx: 40, tw: 120, font: 'Oswald', size: 30, weight: 700, transform: 'uppercase', color: 'rgb(31, 41, 55)', ground: 'rgb(255, 255, 255)', inHeader: false, inFooter: false };
  const ref = { ...reading('http://localhost:5199/contact', []), texts: [head, label(45, 45)] };
  const site = { ...reading('https://club.ddev.site/contact', []), texts: [head, label(281, 45)] };
  const d5 = (rows) => rows.find((r) => r.id === 'D5');
  assert.equal(d5(parity.judge(ref, site, 'page')).verdict, 'PASS', 'the same text in a wider box is the same text');
  const moved = { ...site, texts: [head, { ...label(281, 45), tx: 120 }] };
  assert.equal(d5(parity.judge(ref, moved, 'page')).verdict, 'FAIL', 'text that moved 80px still fails');
  const old = (t) => { const { tx, tw, ...rest } = t; return rest; };
  const refOld = { ...ref, texts: ref.texts.map(old) };
  assert.equal(d5(parity.judge(refOld, site, 'page')).verdict, 'FAIL', 'a reference read before has boxes only, and is judged on boxes');
});

// F-351: KCH-A's home page failed D5 in DDEV's Linux container and passed on
// the macOS host the reference was read on; the run chose where to judge.
test('a reference read on one platform is not judged on another', () => {
  const read = { read_on: { platform: 'darwin', browser: '156.0' } };
  assert.equal(parity.readOnElsewhere(read, 'darwin'), '', 'where it was read');
  assert.match(parity.readOnElsewhere(read, 'linux'), /read on darwin \(156\.0\) and this judge runs on linux/);
  assert.equal(parity.readOnElsewhere({}, 'linux'), '', 'a reference that does not say where is judged as it was');
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

// F-259: a static source's page is captured by the path the site answers.
// P9 · run 3 met it: `--routes /internet.html` was keyed `/internet.html`,
// and the judge read that path on a site that serves `/internet`.
test('a captured route is the site\'s clean path, or the one it names', () => {
  assert.deepEqual(parity.routeSpec('/internet.html'), { route: '/internet', from: '/internet.html' });
  assert.deepEqual(parity.routeSpec('/index.html'), { route: '/', from: '/index.html' });
  assert.deepEqual(parity.routeSpec('/about/index.html'), { route: '/about', from: '/about/index.html' });
  assert.deepEqual(parity.routeSpec('/camps'), { route: '/camps', from: '/camps' });
  assert.deepEqual(parity.routeSpec('/'), { route: '/', from: '/' });
  assert.deepEqual(parity.routeSpec('/internet=/internet.html'), { route: '/internet', from: '/internet.html' });
  assert.deepEqual(parity.routeSpec('/legacy.html=/legacy.html'), { route: '/legacy.html', from: '/legacy.html' }, 'a site that serves .html says so');
});

// F-263: a face declared and never drawn. United's redesign declared General
// Sans on 120 texts and never loaded it, and nothing in droost said so.
test('D3 fails a site whose face is declared and never drawn', () => {
  const ref = page('http://a/', [['Hello', 'p', 100]]);
  const ok = page('http://b/', [['Hello', 'p', 100]]);
  const d3 = (rows) => rows.find((r) => r.id === 'D3');
  ref.texts[0].font = 'General Sans'; ok.texts[0].font = 'General Sans';
  assert.equal(d3(parity.judge(ref, { ...ok, unloaded: [] }, 'page')).verdict, 'PASS');
  const bad = d3(parity.judge(ref, { ...ok, unloaded: ['General Sans'] }, 'page'));
  assert.equal(bad.verdict, 'FAIL');
  assert.match(bad.said, /declared and never drawn/);
  assert.equal(d3(parity.judge({ ...ref, unloaded: ['General Sans'] }, { ...ok, unloaded: ['General Sans'] }, 'page')).verdict, 'PASS', 'a reference read in the same fallback is the same');
});

test('a face declared and never drawn is read as such, and refuses a capture', { skip: !PW && 'no DROOST_SOURCE_PLAYWRIGHT_CWD: the browser half was not run' }, async () => {
  const { chromium } = require(path.join(PW, 'node_modules', 'playwright'));
  const browser = await chromium.launch();
  try {
    const r = await parity.read(browser, 'file://' + path.join(__dirname, 'fixtures', 'fonts.html'), 1280);
    assert.deepEqual(r.unloaded, ['Nowhere Sans Observer']);
    assert.ok(!r.faces.some((f) => /sans-serif/.test(f.family)), 'a generic family is no face to load');
  } finally {
    await browser.close();
  }
  const { spawnSync } = require('child_process');
  const fs = require('fs'); const os = require('os');
  const out = fs.mkdtempSync(path.join(os.tmpdir(), 'parity-faces-'));
  const bin = path.join(__dirname, '..', '..', 'bin', 'droost-parity');
  const run = (extra) => spawnSync(process.execPath, [bin, 'capture', '--source', 'file://' + path.join(__dirname, 'fixtures'), '--routes', '/fonts.html', '--width', '1280', '--out', out, ...extra], { cwd: PW, encoding: 'utf8' });
  const refused = run([]);
  assert.equal(refused.status, 2, refused.stdout.slice(-400));
  assert.match(refused.stdout, /never draws it/);
  assert.ok(!fs.existsSync(path.join(out, 'manifest.json')), 'nothing written');
  const accepted = run(['--accept-unloaded', 'Nowhere Sans Observer']);
  assert.equal(accepted.status, 0, accepted.stdout.slice(-400));
  assert.deepEqual(Object.keys(JSON.parse(fs.readFileSync(path.join(out, 'manifest.json'), 'utf8')).acceptedUnloaded), ['Nowhere Sans Observer']);
  fs.rmSync(out, { recursive: true, force: true });
});

// F-268: an intake that read a static source names the page as the source
// spells it (`/location.html`), and capture keys it by the site's path
// (`/location`). P11 · run 5 met it before its run: the decision read as a
// later rung's page and was never applied, so parity would have held the site
// to "Status uses your device's clock.", the line the client's Q5 removed.
test('a set-aside names its page in the source\'s spelling or the site\'s, and means the same page', () => {
  const fs = require('fs');
  const os = require('os');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'parity-decided-route-'));
  const views = [
    { route: '/location', texts: ["Status uses your device's clock.", 'Come see it in person.'], links: [] },
    { route: '/about', texts: ['Our story'], links: [] },
  ];
  const write = (doc) => fs.writeFileSync(path.join(dir, 'decided.json'), JSON.stringify(doc));
  write({ set_aside: [{ route: '/location.html', text: "Status uses your device's clock.", decided: 'Q5' }] });
  const read = parity.readDecided(dir, views, null);
  assert.equal(read.error, undefined);
  assert.deepEqual([...parity.asideFor(read.entries, '/location')], ["status uses your device's clock."]);
  assert.deepEqual([...parity.asideFor(read.entries, '/location.html')], ["status uses your device's clock."], 'and the other way round');
  assert.deepEqual([...parity.asideFor(read.entries, '/')], [], 'no other page');
  write({ set_aside: [{ route: '/about/index.html', text: 'Our story', decided: 'Q1' }] });
  assert.deepEqual([...parity.asideFor(parity.readDecided(dir, views, null).entries, '/about')], ['our story']);
  write({ set_aside: [{ route: '/location.html', text: 'Not on the page', decided: 'Q5' }] });
  assert.match(parity.readDecided(dir, views, null).error, /does not hold there/, 'a captured page is checked, whatever its spelling');
});

// F-269: a page whose words follow the clock is read twice more at capture
// with the clock moved ahead, and a text not there at every reading is live:
// left out of the reference, listed, and named as not judged. P11 met it on
// Wild Bill's /location, read on a Saturday while open.
test('a text that changes with the clock is live, and only that text', () => {
  const at = (...texts) => ({ texts: texts.map((text) => ({ text })) });
  const saturday = at('Hours', 'Open now · closes 6:00pm', 'Saturday · today', 'Sunday');
  const tuesday = at('Hours', 'Closed · opens Wednesday at 8:00am', 'Saturday', 'Sunday');
  const thursday = at('Hours', 'Open now · closes 7:00pm', 'Saturday', 'Sunday');
  assert.deepEqual(parity.liveTexts(saturday, [tuesday, thursday]), ['Open now · closes 6:00pm', 'Saturday · today']);
  assert.deepEqual(parity.liveTexts(saturday, [saturday]), [], 'a page that does not follow the clock has none');
  assert.deepEqual(parity.liveTexts(saturday, []), [], 'and with no other reading nothing is called live');
  assert.deepEqual(parity.liveTexts(at('Open now  ·  closes 6:00pm'), [at('open now · closes 6:00pm')]), [], 'compared as the judge compares');
});

// F-354: a roadmap that builds a page a rung failed parity on every page not
// built yet. The routes lever names the pages built so far, in either
// spelling; every other reference route is named not judged.
test('the routes not built yet are named, in either spelling, never judged', () => {
  const manifest = ['/', '/internet', '/business', '/about', '/support'];
  assert.deepEqual(parity.notJudged(manifest, ['/index.html', '/about.html', '/internet']), ['/business', '/support']);
  assert.deepEqual(parity.notJudged(manifest, manifest), []);
  assert.deepEqual(parity.notJudged(undefined, ['/']), [], 'a reference with no manifest leaves nothing out');
});

// F-356: a client's decision that adds to the frame had no way into the
// reference. UNI-A run 6 met it: Q8 linked the new /contact from the footer's
// Customers column, the footer grew 30px, and every text below the link failed
// D5 on all 7 routes, against a reference that predates the decision.
test('the texts below a text added by decision are judged with the space it takes (D5)', () => {
  const ref = page('http://localhost:5299/', [['Customers', 'h3', 100], ['Pay my bill', 'a', 140], ['© 2026 United', 'p', 300]]);
  const grown = page('https://united-a.ddev.site/', [['Customers', 'h3', 100], ['Pay my bill', 'a', 140], ['Contact us', 'a', 170], ['© 2026 United', 'p', 330]]);
  const d5 = (rows) => rows.find((r) => r.id === 'D5');
  const added = new Set(['contact us']);
  assert.equal(d5(parity.judge(ref, grown, 'page')).verdict, 'FAIL', 'without the decision the link\'s space fails what sits below it');
  const pass = d5(parity.judge(ref, grown, 'page', new Set(), added));
  assert.equal(pass.verdict, 'PASS', JSON.stringify(pass));
  assert.match(pass.said, /1 pair\(s\) judged with the 30px of texts added above them/);
  // The space is the added text's, and no more: 100px lower still fails.
  const far = page('https://united-a.ddev.site/', [['Customers', 'h3', 100], ['Pay my bill', 'a', 140], ['Contact us', 'a', 170], ['© 2026 United', 'p', 400]]);
  assert.equal(d5(parity.judge(ref, far, 'page', new Set(), added)).verdict, 'FAIL', 'the allowance is the space added, not a blank cheque');
  // A text above the added one is judged as it always was.
  const moved = page('https://united-a.ddev.site/', [['Customers', 'h3', 100], ['Pay my bill', 'a', 175], ['Contact us', 'a', 205], ['© 2026 United', 'p', 330]]);
  assert.equal(d5(parity.judge(ref, moved, 'page', new Set(), added)).verdict, 'FAIL', 'only what sits below the added text drops');
});

test('an addition is read only as a recorded decision after a text the reference holds', () => {
  const fs = require('fs');
  const os = require('os');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'parity-added-'));
  const questions = path.join(dir, 'questions.md');
  fs.writeFileSync(questions, '| Id | Question |\n|---|---|\n| Q8 | Link the contact page from the footer? |\n');
  const views = [{ route: '/', texts: ['Pay my bill', 'Home'], links: [] }, { route: '/business', texts: ['Pay my bill', 'Learn More'], links: [] }];
  const write = (doc) => fs.writeFileSync(path.join(dir, 'decided.json'), JSON.stringify(doc));
  const read = () => parity.readDecided(dir, views, questions);

  write({ added: [{ route: '*', text: 'Contact us', after: 'Pay my bill', decided: 'Q8', why: 'the footer links /contact' }] });
  assert.deepEqual(read().entries, [], 'additions alone are a decided file');
  assert.equal(read().added.length, 1);
  assert.deepEqual([...parity.addedFor(read().added, '/business')], ['contact us']);
  write({ added: [{ route: '*', text: 'Contact us', decided: 'Q8' }] });
  assert.match(read().error, /needs a route, a text, the reference text it follows/);
  write({ added: [{ route: '*', text: 'Contact us', after: 'Pay my bill', decided: 'Q9' }] });
  assert.match(read().error, /cites Q9, which is no question of the intake/);
  write({ added: [{ route: '/', text: 'Contact us', after: 'Nowhere', decided: 'Q8' }] });
  assert.match(read().error, /which the reference does not hold there/);
  write({ added: [{ route: '/business', text: 'Learn More', after: 'Pay my bill', decided: 'Q8' }] });
  assert.match(read().error, /which the reference already holds/, 'what the source shows is held as it is');
  write({ set_aside: [], added: 'Contact us' });
  assert.match(read().error, /"added" is not a list/);
  fs.rmSync(dir, { recursive: true, force: true });
});
