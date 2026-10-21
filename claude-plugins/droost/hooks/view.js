// What the droost pane draws, from `droost workflow:panel --json`.
//
// Pure: no `$`, no I/O. register.js hands in the document and the element
// constructors of the surface it is drawn on ($.ui.resolve(e)), and this
// returns the tree. Tested under node (tests/view.node.test.mjs) with stand-in
// constructors, since Claude Code's own test runner needs Claude Code.

// The colours a tone draws in: plain names every surface knows.
export const TONES = { ok: 'green', warn: 'yellow', bad: 'red', info: 'cyan', muted: 'gray' }

const PHASE_MARK = { passed: '✓', failed: '✗', active: '●', skipped: '–', deferred: '–' }
const PHASE_COLOR = { passed: 'green', failed: 'red', active: 'cyan', skipped: 'gray', deferred: 'gray' }
const DRUPAL_BLUE = 0x0678be
const DEFAULT_COLOR = 0x01000000

// The Drupal drop, for a terminal: six columns, three rows of block glyphs.
const DROP = [
  '  ▗▖  ',
  ' ▟██▙ ',
  ' ▜██▛ ',
]

// The drop as an SVG, for the surfaces that draw one (Desktop, VS Code).
export const DROP_SVG =
  '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="36" viewBox="0 0 30 36">' +
  '<path d="M15 1.5C15 1.5 3 12.6 3 21.8C3 28.6 8.4 34 15 34C21.6 34 27 28.6 27 21.8C27 12.6 15 1.5 15 1.5Z" fill="#0678BE"/>' +
  '<path d="M9.2 23.6C10.6 21.4 12.6 20.4 15 22C17.4 23.6 19.4 22.6 20.8 20.4C21.3 24.6 18.6 28.4 15 28.4C11.8 28.4 9.6 26.4 9.2 23.6Z" fill="#FFFFFF"/>' +
  '</svg>'

const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'

// Standard padded base64 of bytes, without relying on a runtime's helper.
export function base64(bytes) {
  let out = ''
  for (let i = 0; i < bytes.length; i += 3) {
    const a = bytes[i]
    const b = i + 1 < bytes.length ? bytes[i + 1] : 0
    const c = i + 2 < bytes.length ? bytes[i + 2] : 0
    out += B64[a >> 2] + B64[((a & 3) << 4) | (b >> 4)]
    out += i + 1 < bytes.length ? B64[((b & 15) << 2) | (c >> 6)] : '='
    out += i + 2 < bytes.length ? B64[c & 63] : '='
  }
  return out
}

// The drop's cells, as a Raster takes them: [codePoint, fg, bg] per cell,
// little-endian u32s, row-major.
export function dropCells() {
  const words = []
  for (const row of DROP) {
    for (const ch of row) {
      const code = ch.codePointAt(0)
      words.push(code, ch === ' ' ? DEFAULT_COLOR : DRUPAL_BLUE, DEFAULT_COLOR)
    }
  }
  const bytes = new Uint8Array(words.length * 4)
  words.forEach((w, i) => {
    bytes[i * 4] = w & 0xff
    bytes[i * 4 + 1] = (w >>> 8) & 0xff
    bytes[i * 4 + 2] = (w >>> 16) & 0xff
    bytes[i * 4 + 3] = (w >>> 24) & 0xff
  })
  return { columns: DROP[0].length, rows: DROP.length, cells: base64(bytes) }
}

// The run's name for a person: its ticket, else its id.
function runName(run) {
  if (!run) return 'no run'
  return run.work_item || run.id || 'run'
}

// The one line under the prompt, or undefined to clear it: plain text, since
// the status line takes no colour.
export function statusText(doc) {
  if (!doc || !doc.run) return undefined
  const phases = (doc.phases || [])
    .map((p) => p.label + (PHASE_MARK[p.status] ? ' ' + PHASE_MARK[p.status] : ''))
    .join(' › ')
  const gates = (doc.tabs || []).find((t) => t.id === 'gates')
  const hold = doc.hold ? ' · waiting on you' : ''
  return `${runName(doc.run)} · ${phases}${gates && gates.badge ? ' · gates ' + gates.badge : ''}${hold}`
}

// The tab to show: the one asked for if it exists, else the first.
export function pickTab(doc, wanted) {
  const tabs = (doc && doc.tabs) || []
  return tabs.find((t) => t.id === wanted) || tabs[0] || null
}

// One section of a tab.
function section(el, s, i) {
  const { Box, Text } = el
  const rows = []
  if (s.title) rows.push(Text({ key: `t${i}`, bold: true, children: [s.title] }))
  if (s.kind === 'text') {
    rows.push(Text({ key: `x${i}`, wrap: 'wrap', children: [s.text] }))
  } else if (s.kind === 'fields') {
    s.items.forEach((f, j) =>
      rows.push(
        Box({
          key: `f${i}-${j}`,
          flexDirection: 'row',
          columnGap: 1,
          children: [
            Text({ dimColor: true, children: [f.label.padEnd(12)] }),
            Text({ wrap: 'wrap', children: [f.value] }),
          ],
        }),
      ),
    )
  } else if (s.kind === 'checklist') {
    s.items.forEach((c, j) => {
      rows.push(
        Text({ key: `c${i}-${j}`, wrap: 'wrap', color: c.done ? 'green' : undefined, children: [(c.done ? '[x] ' : '[ ] ') + c.text] }),
      )
      if (c.note) rows.push(Text({ key: `cn${i}-${j}`, dimColor: true, wrap: 'wrap', children: ['    ' + c.note] }))
    })
  } else if (s.kind === 'list') {
    s.items.forEach((it, j) => {
      const tone = TONES[it.tone] || undefined
      rows.push(
        Box({
          key: `l${i}-${j}`,
          flexDirection: 'row',
          columnGap: 1,
          children: [
            Text({ color: tone, children: [it.tag ? `[${it.tag}]` : '•'] }),
            Text({ wrap: 'wrap', children: [it.text] }),
          ],
        }),
      )
      if (it.detail) rows.push(Text({ key: `ld${i}-${j}`, dimColor: true, wrap: 'wrap', children: ['  ' + it.detail] }))
    })
  }
  return Box({ key: `s${i}`, flexDirection: 'column', children: rows })
}

// The whole pane. `state` is { tab, error, updatedAt }; `onTab(id)` switches.
export function paneTree(el, doc, state, onTab, surface) {
  const { Box, Text, Button } = el
  const header = []
  if (surface === 'terminal' && el.Raster) {
    header.push(el.Raster({ key: 'logo', ...dropCells() }))
  } else if (el.Svg) {
    header.push(el.Svg({ key: 'logo', source: DROP_SVG, alt: 'Drupal', width: 20, height: 24 }))
  }
  const run = doc && doc.run
  const facts = run
    ? [runName(run), run.preset, run.lead].filter(Boolean).join(' · ')
    : 'no run open'
  header.push(
    Box({
      key: 'name',
      flexDirection: 'column',
      children: [Text({ bold: true, children: ['droost'] }), Text({ dimColor: true, children: [facts] })],
    }),
  )

  const rows = [Box({ key: 'header', flexDirection: 'row', columnGap: 1, children: header })]

  const phases = (doc && doc.phases) || []
  const steps = []
  phases.forEach((p, i) => {
    if (i > 0) steps.push(Text({ key: `sep${i}`, dimColor: true, children: [' › '] }))
    steps.push(
      Text({
        key: `p-${p.id}`,
        color: PHASE_COLOR[p.status],
        dimColor: !PHASE_COLOR[p.status],
        bold: p.status === 'active',
        children: [(PHASE_MARK[p.status] || '○') + ' ' + p.label],
      }),
    )
  })
  rows.push(Box({ key: 'phases', flexDirection: 'row', children: steps }))

  if (doc && doc.hold) {
    rows.push(
      Box({
        key: 'hold',
        borderStyle: 'round',
        borderColor: 'yellow',
        paddingX: 1,
        flexDirection: 'column',
        children: [
          Text({ color: 'yellow', bold: true, children: ['Waiting on you'] }),
          Text({ wrap: 'wrap', children: [doc.hold.question] }),
        ],
      }),
    )
  }

  const current = pickTab(doc, state.tab)
  const tabs = ((doc && doc.tabs) || []).map((t, i) =>
    Button({
      key: 'tab-' + t.id,
      label: t.title + (t.badge ? ' ' + t.badge : ''),
      hotkey: i < 9 ? String(i + 1) : undefined,
      plain: true,
      dimColor: !current || current.id !== t.id,
      onPress: () => onTab(t.id),
    }),
  )
  if (tabs.length) rows.push(Box({ key: 'tabs', flexDirection: 'row', columnGap: 2, children: tabs }))

  if (current) {
    if (current.error) {
      const lead = current.sections.length ? 'Part of this tab was dropped: ' : 'This tab could not be drawn: '
      rows.push(Text({ key: 'tab-error', color: 'yellow', wrap: 'wrap', children: [lead + current.error] }))
    }
    rows.push(Box({ key: 'body', flexDirection: 'column', rowGap: 1, children: current.sections.map((s, i) => section(el, s, i)) }))
  }

  const foot = state.error
    ? 'droost could not be asked: ' + state.error
    : 'updated ' + (state.updatedAt || '—') + ' · droost workflow:panel'
  rows.push(Text({ key: 'foot', dimColor: true, wrap: 'truncate-end', children: [foot] }))

  return Box({ flexDirection: 'column', rowGap: 1, children: rows })
}
