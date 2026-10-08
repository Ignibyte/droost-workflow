// droost-source, tested under node: the reading of a source's files and of a
// page's tree needs no browser; the last case reads the fixture site whole
// when a Playwright is named (DROOST_SOURCE_PLAYWRIGHT_CWD, a folder whose
// node_modules holds one), and says it was skipped otherwise.
'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn, spawnSync } = require('child_process');

const BIN = path.join(__dirname, '..', '..', 'bin', 'droost-source');
const src = require(BIN);
const FIXTURE = path.join(__dirname, 'fixtures', 'static');

test('a router declares its routes, and a file that imports none declares nothing', () => {
  const wouter = `import { Route, Switch } from 'wouter';\n<Route path="/" component={Home} />\n<Route path="/camps/:id" component={Camp} />\n<Route component={NotFound} />`;
  assert.deepEqual(src.routerRoutes(wouter).sort(), ['/', '/camps/:id']);
  const rr = `import { createBrowserRouter } from "react-router-dom";\nconst r = createBrowserRouter([{ path: "/about/", element: <About/> }]);`;
  assert.deepEqual(src.routerRoutes(rr), ['/about']);
  assert.deepEqual(src.routerRoutes(`const x = { path: "/not-a-route" };`), []);
});

test('a static page is one page by its clean path', () => {
  assert.equal(src.staticPath('/contact.html'), '/contact');
  assert.equal(src.staticPath('/index.html'), '/');
  assert.equal(src.staticPath('/about/index.html'), '/about');
  assert.equal(src.staticPath('/events/'), '/events');
});

test('a pattern route matches its instances', () => {
  assert.equal(src.isPattern('/camps/:id'), true);
  assert.equal(src.isPattern('/camps'), false);
  assert.equal(src.matchPattern('/camps/goalie-clinic', ['/camps/:id']), '/camps/:id');
  assert.equal(src.matchPattern('/camps', ['/camps/:id']), null);
  assert.equal(src.normalPath('/camps/?x=1#top'), '/camps');
});

test('a commented-out table is not a table; a real one names its columns', () => {
  const template = `// export const postsTable = pgTable("posts", {\n//   id: serial("id").primaryKey(),\n// });\nexport {}`;
  assert.deepEqual(src.scriptData('lib/db/src/schema/index.ts', template), []);
  const real = `import { pgTable, text, serial } from "drizzle-orm/pg-core";\nexport const members = pgTable("members", {\n  id: serial("id").primaryKey(),\n  name: text("name").notNull(),\n  joined: timestamp("joined"),\n});`;
  const [table] = src.scriptData('lib/db/src/schema/members.ts', real);
  assert.equal(table.kind, 'table');
  assert.equal(table.name, 'members');
  assert.deepEqual(table.fields.map((f) => f.name), ['id', 'name', 'joined']);
});

test('records keep their values and the interface that types them; a component\'s props are not content', () => {
  const text = fs.readFileSync(path.join(FIXTURE, 'data', 'events.ts'), 'utf8');
  const data = src.scriptData('data/events.ts', text);
  const events = data.find((d) => d.name === 'EVENTS');
  assert.equal(events.kind, 'records');
  assert.equal(events.count, 3);
  assert.equal(events.type, 'BookEvent');
  assert.deepEqual(events.fields.map((f) => f.name), ['id', 'title', 'date', 'price', 'perks']);
  assert.equal(events.fields.find((f) => f.name === 'perks').optional, true);
  assert.deepEqual(events.values[1], { id: 'author-talk', title: 'Author talk', date: 'Mar 11', price: 10, perks: ['Q&A', 'Signing', 'Tea', 'Books'] });
  assert.ok(data.some((d) => d.kind === 'interface' && d.name === 'BookEvent'));
  assert.ok(!data.some((d) => d.name === 'DRAFTS'), 'a commented-out array is not data');

  const props = `export interface ButtonProps { label: string; onClick: () => void }\nexport const Button = (p: ButtonProps) => null;`;
  assert.deepEqual(src.scriptData('src/components/Button.tsx', props), []);
  assert.deepEqual(src.scriptData('src/components/ui/chart.tsx', `export interface ChartConfig { label: string }`), []);
});

test('an API with only a health check says it holds no content', () => {
  const yaml = `openapi: 3.1.0\ninfo:\n  title: Api\npaths:\n  /healthz:\n    get:\n      operationId: healthCheck\ncomponents:\n  schemas:\n    HealthStatus:\n      type: object\n`;
  const [api] = src.openapiData('lib/api-spec/openapi.yaml', yaml);
  assert.deepEqual(api.paths, ['/healthz']);
  assert.deepEqual(api.schemas, ['HealthStatus']);
  assert.match(api.note, /health check only/);
  const real = `openapi: 3.1.0\npaths:\n  /members:\n    get: {}\n  /members/{id}:\n    get: {}\n`;
  const [members] = src.openapiData('api.yaml', real);
  assert.deepEqual(members.paths, ['/members', '/members/{id}']);
  assert.equal(members.note, null);
  assert.deepEqual(src.openapiData('x.yaml', 'name: not an api\n'), []);
});

test('JSON records are data, a project\'s own config is not; Prisma models are tables', () => {
  const [r] = src.jsonData('content/coaches.json', JSON.stringify([{ name: 'A', rate: 5 }, { name: 'B', rate: 6, bio: 'x' }]));
  assert.equal(r.count, 2);
  assert.deepEqual(r.fields.map((f) => f.name), ['name', 'rate', 'bio']);
  assert.deepEqual(src.jsonData('package.json', JSON.stringify({ name: 'x', files: [{ a: 1 }] })), []);
  const [model] = src.prismaData('prisma/schema.prisma', 'model Post {\n  id Int @id\n  title String\n  body String?\n}\n');
  assert.equal(model.name, 'Post');
  assert.deepEqual(model.fields.map((f) => [f.name, f.optional]), [['id', false], ['title', false], ['body', true]]);
});

// A page's tree as the browser half writes it.
const card = (title, date, price, perks, href) => ({
  t: 'article',
  k: [
    { t: 'h3', x: title },
    { t: 'p', x: date },
    { t: 'span', x: 'Price' },
    { t: 'p', x: price },
    { t: 'ul', k: perks.map((p) => ({ t: 'li', x: p })) },
    { t: 'a', x: 'More', h: href },
  ],
});
const page = () => ({
  t: 'body',
  k: [
    { t: 'header', k: [{ t: 'nav', k: [{ t: 'a', x: 'Home', h: 'http://s.test/' }, { t: 'a', x: 'Events', h: 'http://s.test/events' }, { t: 'a', x: 'Contact', h: 'http://s.test/contact' }] }] },
    {
      t: 'main',
      k: [
        { t: 'section', k: [{ t: 'h1', x: 'Books by the water' }, { t: 'p', x: 'Open every day but Monday.' }] },
        {
          t: 'section',
          k: [
            { t: 'h2', x: 'Upcoming events' },
            {
              t: 'div',
              c: 'grid',
              k: [
                card('Poetry night', 'Mar 4', '$5', ['Readings', 'Tea', 'Open mic'], 'http://s.test/events/poetry-night'),
                card('Author talk', 'Mar 11', '$10', ['Q&A', 'Signing', 'Tea', 'Books'], 'http://s.test/events/author-talk'),
                card('Kids story hour', 'Mar 18', '$0', ['Stories', 'Crafts', 'Snacks'], 'http://s.test/events/story-hour'),
              ],
            },
          ],
        },
      ],
    },
  ],
});

test('cards of one template are one structure, lists of other lengths included, and a constant label is not a field', () => {
  const repeats = src.repeatsOf(page(), 'http://s.test');
  const cards = repeats.find((r) => r.kind === 'cards');
  assert.ok(cards, 'the three events are one repeated structure');
  assert.equal(cards.count, 3);
  assert.equal(cards.inside, 'main');
  assert.deepEqual(cards.slots.map((s) => s.role), ['heading', 'text', 'text', 'text', 'list', 'link']);
  const [title, date, label, price, perks, link] = cards.slots;
  assert.equal(title.varies, true);
  assert.equal(date.pattern, 'date');
  assert.equal(label.varies, false, '"Price" in every card is a label');
  assert.equal(price.pattern, 'price');
  assert.deepEqual(perks.items, [3, 4, 3]);
  assert.equal(link.varies, true, 'the same text, other targets: it varies');
  assert.deepEqual(link.targets, ['/events/poetry-night', '/events/author-talk', '/events/story-hour']);
  assert.ok(!repeats.some((r) => r.slots.length === 1 && r.slots[0].values.includes('Readings')), 'a list inside a card is the card\'s slot, not a structure');
  const nav = repeats.find((r) => r.kind === 'links');
  assert.equal(nav.inside, 'nav');
});

test('a structure is matched to the records it renders, each slot to its field, each item to its one record', () => {
  const data = src.scriptData('data/events.ts', fs.readFileSync(path.join(FIXTURE, 'data', 'events.ts'), 'utf8'));
  const cards = src.repeatsOf(page(), 'http://s.test').find((r) => r.kind === 'cards');
  const match = src.matchRecords(cards, data);
  assert.equal(match.id, 'd:data/events.ts#EVENTS');
  // The link's target holds the record's id: the card links its own page.
  assert.deepEqual(match.fields, { 1: 'title', 2: 'date', 4: 'price', 5: 'perks', 6: 'id' });
  assert.deepEqual(match.records, ['d:data/events.ts#EVENTS@poetry-night', 'd:data/events.ts#EVENTS@author-talk', 'd:data/events.ts#EVENTS@story-hour']);

  // Two records that share a value: each item still shows one.
  const shared = [{ id: 'd:x#E', kind: 'records', fields: [{ name: 'title' }, { name: 'place' }], values: [{ id: 'a', title: 'Alpha camp', place: 'North rink' }, { id: 'b', title: 'Beta camp', place: 'North rink' }, { id: 'c', title: 'Gamma camp', place: 'South rink' }] }];
  const two = { count: 2, slots: [{ n: 1, role: 'heading', varies: true, values: ['Alpha camp', 'Gamma camp'] }, { n: 2, role: 'text', varies: true, values: ['North rink', 'South rink'] }] };
  assert.deepEqual(src.matchRecords(two, shared).records, ['d:x#E@a', 'd:x#E@c']);
});

test('a section that shows one record whole names it', () => {
  const data = src.scriptData('data/events.ts', fs.readFileSync(path.join(FIXTURE, 'data', 'events.ts'), 'utf8'));
  assert.deepEqual(src.sectionRecords('Featured: Author talk on Mar 11, $10 at the door', data), ['d:data/events.ts#EVENTS@author-talk']);
  assert.deepEqual(src.sectionRecords('Books by the water', data), []);
});

test('a form lists its fields with their labels, types, flags and options', () => {
  const tree = { t: 'body', k: [{ t: 'main', k: [{ t: 'form', k: [
    { t: 'input', f: { type: 'text', name: 'name', label: 'Name', required: true } },
    { t: 'select', f: { type: 'select', name: 'topic', label: 'Topic', required: false, options: ['Orders', 'Events'] } },
    { t: 'button', x: 'Send', f: { type: 'button', name: '', label: '', required: false, submit: true } },
  ] }] }] };
  const { forms, loose } = src.formsOf(tree);
  assert.equal(forms.length, 1);
  assert.equal(forms[0].submit, 'Send');
  assert.deepEqual(forms[0].fields.map((f) => [f.label, f.type, f.required]), [['Name', 'text', true], ['Topic', 'select', false]]);
  assert.deepEqual(forms[0].fields[1].options, ['Orders', 'Events']);
  assert.deepEqual(loose, []);
});

test('a value pattern holds only when every value has it', () => {
  assert.equal(src.valuePattern(['$5', '$10', 'from $20']), 'price');
  assert.equal(src.valuePattern(['$5', 'FREE']), null);
  assert.equal(src.valuePattern(['(816) 555-0101', '913-555-0102']), 'phone');
  assert.equal(src.valuePattern(['Aug 10', 'Sept 6']), 'date');
  assert.equal(src.valuePattern(['10–18', '18+']), 'range');
});

// The fixture site, read whole: only with a Playwright to read it with.
const PW_CWD = process.env.DROOST_SOURCE_PLAYWRIGHT_CWD;

test('the fixture site, served and audited', { skip: !PW_CWD && 'no DROOST_SOURCE_PLAYWRIGHT_CWD: the browser half was not run' }, async () => {
  const server = spawn(process.execPath, [BIN, 'serve', FIXTURE, '--port', '0'], { stdio: ['ignore', 'pipe', 'inherit'] });
  try {
    const url = await new Promise((resolve, reject) => {
      server.stdout.once('data', (b) => resolve(JSON.parse(String(b).trim()).source.url));
      server.once('exit', () => reject(new Error('the server exited')));
    });
    const out = fs.mkdtempSync(path.join(os.tmpdir(), 'droost-source-'));
    const run = spawnSync(process.execPath, [BIN, 'audit', '--url', url, '--files', FIXTURE, '--out', out, '--static'], { cwd: PW_CWD, encoding: 'utf8', timeout: 180000 });
    const last = JSON.parse(run.stdout.trim().split('\n').pop()).source;
    assert.ok([0, 2].includes(run.status), `exit ${run.status}: ${last.summary}`);
    const audit = JSON.parse(fs.readFileSync(path.join(out, 'audit.json'), 'utf8'));
    const paths = audit.routes.map((r) => r.path);
    for (const p of ['/', '/events', '/contact']) assert.ok(paths.includes(p), `route ${p}`);
    const linked = audit.routes.find((r) => r.path === '/events/poetry-night');
    assert.equal(linked.status, 404, 'a card links a page the source does not have');
    assert.ok(!audit.pages.some((p) => p.route === '/events/poetry-night'), 'a page that answers 404 is a route, not a page');
    assert.ok(!paths.some((p) => p.endsWith('.html')), 'a static page is read once, by its clean path: ' + paths.join(', '));
    const home = audit.pages.find((p) => p.route === '/');
    const cards = home.repeats.find((r) => r.kind === 'cards');
    assert.equal(cards.data.id, 'd:data/events.ts#EVENTS');
    assert.ok(home.sections.some((s) => s.start.includes('Yes, on Saturdays.')), 'the disclosure was opened and its answer read');
    assert.ok(home.sections.length >= 2);
    const contact = audit.pages.find((p) => p.route === '/contact');
    assert.deepEqual(contact.forms[0].fields.map((f) => f.label), ['Name', 'Email', 'Topic', 'Message']);
    assert.deepEqual(audit.frame.header.links.map((l) => [l.label, l.href]), [['Harbor Books', '/'], ['Home', '/'], ['Events', '/events'], ['Contact', '/contact']]);
    assert.ok(fs.readFileSync(path.join(out, 'audit.md'), 'utf8').includes('renders `d:data/events.ts#EVENTS`'));
  } finally {
    server.kill();
  }
});
