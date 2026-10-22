// What the droost pane draws, from `droost workflow:panel --json`.
//
// Pure: no `$`, no I/O. register.js hands in the document and the element
// constructors of the surface it is drawn on ($.ui.resolve(e)), and this
// returns the tree. Tested under node (tests/view.node.test.mjs) with stand-in
// constructors, since Claude Code's own test runner needs Claude Code.

// The design's palette (the run panel artifact), for text and its pills.
export const TONES = { ok: '#8EE3B9', warn: '#F7D49A', bad: '#FFB3B6', info: '#8FD0F7', muted: '#8B98A5' }
const PILL_BG = { ok: '#123A2B', warn: '#2B2111', bad: '#3A1518', info: '#10314A', muted: '#1C2430' }

const PHASE_MARK = { passed: '✓', failed: '✗', active: '●', skipped: '–', deferred: '–' }
const PHASE_COLOR = { passed: '#3FB68B', failed: '#F2555A', active: '#35A3E5', skipped: '#8B98A5', deferred: '#8B98A5' }
const DRUPAL_BLUE = [0x06, 0x78, 0xbe]

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

// The Drupal drop as RGBA pixels, for an Image: a terminal with an image
// protocol (iTerm2, kitty, WezTerm, Ghostty) draws it; one without draws the
// alt text, a space. Drawn here, four samples a pixel, so no file ships.
export function dropImage(width = 36, height = 44) {
  const bytes = new Uint8Array(width * height * 4)
  const cx = width / 2
  const r = width * 0.42
  const cy = height - r - 1
  const tip = height * 0.04
  const inside = (x, y) => {
    if (y >= cy) return (x - cx) ** 2 + (y - cy) ** 2 <= r * r
    if (y < tip) return false
    const half = r * Math.pow((y - tip) / (cy - tip), 0.75)
    return Math.abs(x - cx) <= half
  }
  for (let py = 0; py < height; py++) {
    for (let px = 0; px < width; px++) {
      let hits = 0
      for (const [dx, dy] of [[0.25, 0.25], [0.75, 0.25], [0.25, 0.75], [0.75, 0.75]]) {
        if (inside(px + dx, py + dy)) hits++
      }
      const o = (py * width + px) * 4
      bytes[o] = DRUPAL_BLUE[0]
      bytes[o + 1] = DRUPAL_BLUE[1]
      bytes[o + 2] = DRUPAL_BLUE[2]
      bytes[o + 3] = Math.round((hits / 4) * 255)
    }
  }
  return { rgba: base64(bytes), width, height }
}

// The run's name for a person: its ticket, else its id.
function runName(run) {
  if (!run) return 'no run'
  return run.work_item || run.id || 'run'
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
  } else if (s.kind === 'markdown') {
    rows.push(el.Markdown ? el.Markdown({ key: `m${i}`, text: s.text }) : Text({ key: `m${i}`, wrap: 'wrap', children: [s.text] }))
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
            it.tag
              ? Text({ color: tone, backgroundColor: PILL_BG[it.tone] || PILL_BG.muted, children: [` ${it.tag} `] })
              : Text({ color: tone, children: ['•'] }),
            Text({ wrap: 'wrap', children: [it.text] }),
          ],
        }),
      )
      if (it.detail) rows.push(Text({ key: `ld${i}-${j}`, dimColor: true, wrap: 'wrap', children: ['  ' + it.detail] }))
    })
  }
  return Box({ key: `s${i}`, flexDirection: 'column', children: rows })
}

// The phases: a line (✓ Plan › ● Code › ○ Test › ○ Complete), or a column
// for the minimized dock.
function phaseRow(el, doc, column = false) {
  const { Box, Text } = el
  const steps = []
  ;((doc && doc.phases) || []).forEach((p, i) => {
    if (i > 0 && !column) steps.push(Text({ key: `sep${i}`, dimColor: true, children: [' › '] }))
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
  return Box({ key: 'phases', flexDirection: column ? 'column' : 'row', flexWrap: column ? undefined : 'wrap', children: steps })
}

// The size toggle: minimize to the phases, maximize to everything.
function sizeButton(el, minimized, onSize) {
  return el.Button({
    key: 'size',
    label: minimized ? '□ maximize' : '— minimize',
    hotkey: 'm',
    plain: true,
    dimColor: true,
    onPress: () => onSize(),
  })
}

// The run as a pill: its ticket, else its id.
export function runPills(run) {
  if (!run) return []
  return [{ text: run.work_item || run.id || 'run', tone: 'info' }]
}

function runBadges(el, run) {
  const { Box, Text } = el
  const pills = runPills(run)
  if (!pills.length) return Text({ key: 'run', color: TONES.muted, children: ['no run open'] })
  return Box({
    key: 'run',
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'center',
    columnGap: 1,
    flexShrink: 1,
    children: pills.map((p, i) =>
      Text({ key: 'pill' + i, color: TONES[p.tone], backgroundColor: PILL_BG[p.tone], children: [' ' + p.text + ' '] }),
    ),
  })
}

// A rule the body's width.
function rule(el, key, columns) {
  return el.Text({ key, color: TONES.muted, children: ['─'.repeat(columns || 40)] })
}

// The drop: an image in a terminal that draws one, an SVG elsewhere.
function logo(el, surface) {
  if (surface === 'terminal' && el.Image) return el.Image({ key: 'logo', source: dropImage(), columns: 3, rows: 2, alt: ' ' })
  if (el.Svg) return el.Svg({ key: 'logo', source: DROP_SVG, alt: 'Drupal', width: 16, height: 20 })
  return null
}

// A tab's icon: one cell, so a row of them fits any pane. A project's own
// tab is its title's first letter.
const TAB_ICON = { spec: '≡', activity: '↯', gates: '◈', seeker: '⌕', files: '±' }
export function tabIcon(t) {
  return TAB_ICON[t.id] || (t.title || t.id || '?').charAt(0).toUpperCase()
}

// Tabs whose badge the pane leaves out: the spec's count is in the tab itself.
const ICON_ONLY = new Set(['spec'])

// The tabs, one row of icons with their badges; the open one lit.
function tabRow(el, doc, current, onTab) {
  const { Box, Button, Text } = el
  const tabs = ((doc && doc.tabs) || []).map((t) => {
    const open = Boolean(current && current.id === t.id)
    return Button({
      key: 'tab-' + t.id,
      label: t.title,
      plain: true,
      dimColor: !open,
      onPress: () => onTab(t.id),
      children: [
        Text({
          color: open ? TONES.info : undefined,
          backgroundColor: open ? PILL_BG.info : undefined,
          bold: open,
          children: [' ' + tabIcon(t) + (t.badge && !ICON_ONLY.has(t.id) ? ' ' + t.badge : '') + ' '],
        }),
      ],
    })
  })
  return tabs.length ? Box({ key: 'tabs', flexDirection: 'row', flexWrap: 'wrap', children: tabs }) : null
}

// The whole pane. `state` is { tab, error, minimized, placement, bodyRows,
// bodyColumns }; `onTab(id)` switches tabs and `onSize()` toggles minimized
// and maximized. The tabs sit between two rules with a row of air under
// them, so the run is above them and the open tab below.
//
// Docked beside the transcript (`placement: 'dock'`), a column:
//
//   (drop) droost          [EMT-425]          — minimize
//   (drop)
//
//   ✓ Plan › ● Code › ○ Test › ○ Complete
//   ─────────────────────────────────────
//    ≡  ↯  ◈ 3/4  ⌕  ± 4
//   ─────────────────────────────────────
//
//   (the open tab)
//
// and minimized, a strip: the drop, the name, a phase a row.
//
// Inline above the prompt, rows:
//
//   (drop) droost   ✓ Plan › ● Code › ○ Test › ○ Complete   — minimize
//                              [EMT-425]
//   ─────────────────────────────────────
//    ≡  ↯  ◈ 3/4  ⌕  ± 4
//   ─────────────────────────────────────
//
//   (the open tab)
//
// and minimized, the phase line alone.
export function paneTree(el, doc, state, onTab, surface, onSize = () => {}) {
  const { Box, Text } = el
  const docked = state.placement === 'dock'
  const run = runBadges(el, doc && doc.run)
  const name = Text({ key: 'name', bold: true, children: ['droost'] })
  const mark = logo(el, surface)

  if (state.minimized) {
    if (docked) {
      return Box({
        flexDirection: 'column',
        children: [mark, name, phaseRow(el, doc, true), sizeButton(el, true, onSize)].filter(Boolean),
      })
    }
    return Box({
      flexDirection: 'row',
      justifyContent: 'space-between',
      columnGap: 2,
      children: [phaseRow(el, doc), sizeButton(el, true, onSize)],
    })
  }

  const brand = Box({ key: 'brand', flexDirection: 'row', alignItems: 'center', columnGap: 1, children: [mark, name].filter(Boolean) })
  const rows = []
  if (docked) {
    rows.push(
      Box({
        key: 'header',
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'flex-start',
        columnGap: 2,
        children: [brand, run, sizeButton(el, false, onSize)],
      }),
      Box({ key: 'phasebox', marginTop: 1, children: [phaseRow(el, doc)] }),
    )
  } else {
    rows.push(
      Box({
        key: 'header',
        flexDirection: 'row',
        justifyContent: 'space-between',
        columnGap: 2,
        children: [
          brand,
          Box({ key: 'middle', flexDirection: 'column', alignItems: 'center', flexShrink: 1, children: [phaseRow(el, doc), run] }),
          sizeButton(el, false, onSize),
        ],
      }),
    )
  }

  if (doc && doc.hold) {
    rows.push(
      Box({
        key: 'hold',
        flexDirection: 'row',
        columnGap: 1,
        children: [
          Text({ color: TONES.warn, bold: true, children: ['Waiting on you'] }),
          Text({ wrap: 'wrap', children: [doc.hold.question] }),
        ],
      }),
    )
  }

  const current = pickTab(doc, state.tab)
  const tabs = tabRow(el, doc, current, onTab)
  if (tabs) {
    rows.push(
      Box({
        key: 'tabbar',
        flexDirection: 'column',
        marginBottom: 1,
        children: [rule(el, 'rule-top', state.bodyColumns), tabs, rule(el, 'rule-bottom', state.bodyColumns)],
      }),
    )
  }

  if (current) {
    if (current.error) {
      const lead = current.sections.length ? 'Part of this tab was dropped: ' : 'This tab could not be drawn: '
      rows.push(Text({ key: 'tab-error', color: TONES.warn, wrap: 'wrap', children: [lead + current.error] }))
    }
    rows.push(Box({ key: 'body', flexDirection: 'column', rowGap: 1, children: current.sections.map((s, i) => section(el, s, i)) }))
  }

  if (state.error) {
    rows.push(Text({ key: 'foot', color: TONES.warn, wrap: 'truncate-end', children: ['droost could not be asked: ' + state.error] }))
  }

  return Box({ flexDirection: 'column', minHeight: state.bodyRows || undefined, children: rows })
}
