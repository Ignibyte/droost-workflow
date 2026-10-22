// The pane's drawing, under plain node: stand-in element constructors record
// what was drawn, so the tree can be read without Claude Code.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { base64, dropImage, paneTree, pickTab, statusText } from '../hooks/view.js'

const make = (type) => (props) => ({ type, props })
const el = { Box: make('Box'), Text: make('Text'), Button: make('Button'), Image: make('Image'), Svg: make('Svg') }

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

test('the status line names the run, the phases and the gates, in plain text', () => {
  assert.equal(statusText(DOC), 'PROJ-142 · Plan ✓ › Code ● › Test › Complete · gates 1/2')
  assert.equal(statusText({ ...DOC, hold: { question: 'q' } }).endsWith('· waiting on you'), true)
  assert.equal(statusText({ ...DOC, run: null }), undefined, 'no run clears the line')
})

test('the pane draws the phases, a tab per tab, and the open tab', () => {
  const tree = paneTree(el, DOC, { tab: 'gates', error: '', updatedAt: '17:02' }, () => {}, 'terminal')
  const words = texts(tree)
  assert.ok(words.includes('✓ Plan') && words.includes('● Code') && words.includes('○ Test'))
  assert.deepEqual(all(tree, 'Button').map((b) => b.props.label), ['▾ fold', 'Spec 1/2', 'Gates 1/2', 'Ticket'])
  assert.ok(words.includes(' examined nothing '), 'a pass that examined nothing says so')
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

test('folded, the pane keeps its header and phases and drops the tabs', () => {
  let folded = 0
  const tree = paneTree(el, DOC, { tab: 'spec', collapsed: true }, () => {}, 'terminal', () => { folded++ })
  const buttons = all(tree, 'Button')
  assert.deepEqual(buttons.map((b) => b.props.label), ['▸ open'])
  assert.ok(texts(tree).includes('● Code'))
  buttons[0].props.onPress()
  assert.equal(folded, 1)
})

test('a pane above the prompt only for want of width says how to dock it', () => {
  const narrow = paneTree(el, DOC, { tab: 'spec', placement: 'inline', viewport: { columns: 92, isFullscreen: true } }, () => {}, 'terminal')
  assert.ok(texts(narrow).some((w) => w.startsWith('92 columns: widen to 110')))
  const wide = paneTree(el, DOC, { tab: 'spec', placement: 'dock', viewport: { columns: 140, isFullscreen: true } }, () => {}, 'terminal')
  assert.ok(!texts(wide).some((w) => w.includes('widen to 110')))
})
