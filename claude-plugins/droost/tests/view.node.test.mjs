// The pane's drawing, under plain node: stand-in element constructors record
// what was drawn, so the tree can be read without Claude Code.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { base64, dropImage, paneTree, pickTab, runPills, tabIcon } from '../hooks/view.js'

const make = (type) => (props) => ({ type, props })
const el = { Box: make('Box'), Text: make('Text'), Button: make('Button'), Image: make('Image'), Svg: make('Svg'), Markdown: make('Markdown') }

// Every string drawn anywhere in a tree, in order.
function texts(node, out = []) {
  if (typeof node === 'string') out.push(node)
  else if (node && node.props) {
    if (node.props.label) out.push(node.props.label)
    for (const child of node.props.children || []) texts(child, out)
  }
  return out
}

// Every element of one type in a tree.
function all(node, type, out = []) {
  if (node && node.props) {
    if (node.type === type) out.push(node)
    for (const child of node.props.children || []) all(child, type, out)
  }
  return out
}

const DOC = {
  schema: 'droost.panel/1',
  run: { id: 'run-1', state: 'running', phase: 'code', preset: 'medium', lead: 'autonomous', work_item: 'PROJ-142' },
  phases: [
    { id: 'plan', label: 'Plan', status: 'passed' },
    { id: 'code', label: 'Code', status: 'active' },
    { id: 'test', label: 'Test', status: 'pending' },
    { id: 'complete', label: 'Complete', status: 'pending' },
  ],
  hold: null,
  tabs: [
    { id: 'spec', title: 'Spec', badge: '1/2', source: 'droost', error: null, sections: [{ kind: 'checklist', title: 'Acceptance criteria', items: [{ text: 'AC-1 · cards', done: true, note: 'r04.spec.ts' }, { text: 'AC-2 · table', done: false, note: 'not proven yet' }] }] },
    { id: 'gates', title: 'Gates', badge: '1/2', source: 'droost', error: null, sections: [{ kind: 'list', title: 'Code', items: [{ text: 'phpcs', detail: '8 files', tag: 'passed', tone: 'ok' }, { text: 'phpstan', detail: 'nothing to analyse', tag: 'examined nothing', tone: 'warn' }] }] },
    { id: 'ticket', title: 'Ticket', badge: null, source: 'project', error: 'droost/panel/ticket.ts exited 3', sections: [] },
  ],
}

test('the pane draws the phases, a tab per tab, and the open tab', () => {
  const tree = paneTree(el, DOC, { tab: 'gates', error: '', updatedAt: '17:02' }, () => {}, 'terminal')
  const words = texts(tree)
  assert.ok(words.includes('✓ Plan') && words.includes('● Code') && words.includes('○ Test'))
  assert.deepEqual(all(tree, 'Button').map((b) => b.props.label), ['— minimize', 'Spec', 'Gates', 'Ticket'])
  assert.ok(words.includes(' ≡ ') && words.includes(' ◈ 1/2 ') && words.includes(' T '), 'a tab is its icon and badge; the spec its icon alone')
  assert.ok(words.includes(' examined nothing '), 'a pass that examined nothing says so')
  assert.ok(words.includes(' PROJ-142 ') && !words.includes(' medium ') && !words.includes(' autonomous '), 'the ticket alone')
  assert.ok(!words.some((w) => w.includes('Run ')), 'the ticket alone, no "Run"')
  assert.equal(all(tree, 'Box').filter((b) => b.props.borderStyle).length, 0, 'nothing framed')
  assert.equal(all(tree, 'Image').length, 1, 'the drop, in a terminal')
  assert.equal(all(tree, 'Svg').length, 0)
})

test('a tab that could not be drawn says why, and a press switches tabs', () => {
  let picked = null
  const tree = paneTree(el, DOC, { tab: 'ticket', error: '', updatedAt: '' }, (id) => { picked = id }, 'desktop')
  assert.ok(texts(tree).some((w) => w.startsWith('This tab could not be drawn') && w.includes('exited 3')))
  const partial = { ...DOC, tabs: [{ ...DOC.tabs[2], error: 'dropped section 3', sections: [{ kind: 'text', title: null, text: 'kept' }] }] }
  assert.ok(texts(paneTree(el, partial, { tab: 'ticket' }, () => {}, 'terminal')).some((w) => w.startsWith('Part of this tab was dropped')))
  all(tree, 'Button')[1].props.onPress()
  assert.equal(picked, 'spec')
  assert.equal(all(tree, 'Svg').length, 1, 'the drop, where an SVG draws')
})

test('a hold is drawn, and droost that cannot be asked is named', () => {
  const tree = paneTree(el, { ...DOC, hold: { question: 'Which rink?' } }, { tab: 'spec', error: 'php: not found', updatedAt: '' }, () => {}, 'terminal')
  const words = texts(tree)
  assert.ok(words.includes('Waiting on you') && words.includes('Which rink?'))
  assert.equal(all(tree, 'Box').filter((b) => b.props.borderStyle).length, 0, 'the hold is a line, not a box')
  assert.ok(words.some((w) => w.includes('could not be asked: php: not found')))
})

test('no document still draws a pane', () => {
  const tree = paneTree(el, null, { tab: 'spec', error: 'no droost here', updatedAt: '' }, () => {}, 'terminal')
  assert.ok(texts(tree).includes('no run open'))
  assert.equal(pickTab(null, 'spec'), null)
})

test('the drop is an RGBA image, opaque in its body and clear at its corners', () => {
  const drop = dropImage(36, 44)
  const bytes = Buffer.from(drop.rgba, 'base64')
  assert.equal(bytes.length, 36 * 44 * 4)
  const alpha = (x, y) => bytes[(y * 36 + x) * 4 + 3]
  assert.equal(alpha(18, 34), 255, 'the body')
  assert.equal(alpha(0, 0), 0, 'a corner')
  assert.equal(base64(new Uint8Array([1, 2, 3, 4])), Buffer.from([1, 2, 3, 4]).toString('base64'))
})

test('minimized inline, the pane is the phase line and the maximize button, nothing else', () => {
  let toggled = 0
  const tree = paneTree(el, DOC, { tab: 'spec', minimized: true }, () => {}, 'terminal', () => { toggled++ })
  assert.deepEqual(all(tree, 'Button').map((b) => b.props.label), ['□ maximize'])
  assert.equal(all(tree, 'Image').length, 0, 'no logo')
  assert.ok(!texts(tree).includes('droost'), 'no name')
  assert.ok(texts(tree).includes('● Code'))
  all(tree, 'Button')[0].props.onPress()
  assert.equal(toggled, 1)
})

test('the spec is drawn as markdown', () => {
  const doc = { ...DOC, tabs: [{ id: 'spec', title: 'Spec', badge: '0/5 proven', error: null, sections: [{ kind: 'markdown', title: null, text: '# R05\n\nThe contact page.' }] }] }
  const tree = paneTree(el, doc, { tab: 'spec' }, () => {}, 'terminal')
  assert.equal(all(tree, 'Markdown')[0].props.text, '# R05\n\nThe contact page.')
})

// The header's rows: the run line beside the phases inline, under the name docked.
const header = (tree) => tree.props.children.find((c) => c && c.props.key === 'header')

test('inline, the phases and the run pills share the header, centred between the name and minimize', () => {
  const tree = paneTree(el, DOC, { tab: 'spec', placement: 'inline' }, () => {}, 'terminal')
  const [brand, middle, size] = header(tree).props.children
  assert.deepEqual(texts(brand), ['droost'])
  assert.equal(middle.props.alignItems, 'center')
  assert.ok(texts(middle).includes('● Code') && texts(middle).includes(' PROJ-142 '))
  assert.equal(size.props.label, '— minimize')
})

test('docked, the pills sit between the name and minimize, the phases under them with air around', () => {
  const tree = paneTree(el, DOC, { tab: 'spec', placement: 'dock' }, () => {}, 'terminal')
  const rows = tree.props.children
  const [brand, run, size] = header(tree).props.children
  assert.deepEqual(texts(brand), ['droost'])
  assert.deepEqual(texts(run), [' PROJ-142 '])
  assert.equal(run.props.justifyContent, 'center')
  assert.equal(size.props.label, '— minimize')
  assert.equal(rows[1].props.key, 'phasebox')
  assert.equal(rows[1].props.marginTop, 1)
  assert.equal(rows[2].props.key, 'tabbar')
})

test('docked and minimized, a strip: the drop, the name, a phase a row', () => {
  const tree = paneTree(el, DOC, { tab: 'spec', placement: 'dock', minimized: true }, () => {}, 'terminal')
  assert.equal(tree.props.flexDirection, 'column')
  assert.equal(all(tree, 'Image').length, 1)
  const phases = all(tree, 'Box').find((b) => b.props.key === 'phases')
  assert.equal(phases.props.flexDirection, 'column')
  assert.deepEqual(texts(phases), ['✓ Plan', '● Code', '○ Test', '○ Complete'])
  assert.deepEqual(all(tree, 'Button').map((b) => b.props.label), ['□ maximize'])
  assert.ok(!texts(tree).includes(' PROJ-142 '), 'no run pills')
})

test('a tab icon is one cell: droost\'s own drawn, a project\'s its initial', () => {
  for (const id of ['spec', 'activity', 'gates', 'seeker', 'files']) assert.equal([...tabIcon({ id })].length, 1)
  assert.equal(tabIcon({ id: 'ticket', title: 'ticket' }), 'T')
})

test('the tabs sit between two rules the width of the body, a row of air under them', () => {
  for (const placement of ['dock', 'inline']) {
    const rows = paneTree(el, DOC, { tab: 'spec', placement, bodyColumns: 52 }, () => {}, 'terminal').props.children
    const at = rows.findIndex((r) => r && r.props.key === 'tabbar')
    const bar = rows[at]
    assert.equal(bar.props.marginBottom, 1)
    assert.equal(bar.props.marginTop, undefined, 'no row above the top rule')
    assert.deepEqual(bar.props.children.map((c) => c.props.key), ['rule-top', 'tabs', 'rule-bottom'])
    assert.deepEqual(texts(bar.props.children[0]), ['─'.repeat(52)])
    assert.equal(rows[at + 1].props.key, 'body')
  }
})

test('the run pill is the ticket, else the id', () => {
  assert.deepEqual(runPills({ id: 'run-b37048e955e1', work_item: 'EMT-425', preset: 'custom', lead: 'human-led' }), [{ text: 'EMT-425', tone: 'info' }])
  assert.deepEqual(runPills({ id: 'run-1', lead: 'autonomous' }), [{ text: 'run-1', tone: 'info' }])
  assert.deepEqual(runPills(null), [])
})
