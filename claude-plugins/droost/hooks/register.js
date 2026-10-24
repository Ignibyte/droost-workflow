// droost's run panel, inside Claude Code: a pane with the run's phases and
// tabs.
//
// Everything it shows comes from `droost workflow:panel --json`, asked every
// few seconds: droost builds the panel (its own tabs and the project's, from
// droost.yml `panel.tabs`), and this only draws it. `/droost-panel` opens the
// pane. It opens by itself as droost.yml `panel.open` says: `auto` (the
// default) when a run is under way, `always` at every session, `never` only
// when asked.
//
// While it is loaded the agent's own task list (TaskCreate) is turned away:
// the panel is the run's list, and `droost workflow:say` is how the agent adds
// to what it shows.

import { paneTree, pickTab } from './view.js'

const PANE = 'droost'
const EVERY_MS = 3000
const TURNED_AWAY =
  'Not here: droost\'s run panel is this session\'s task list (its phases, the spec\'s criteria and their proofs). ' +
  'Say what you are doing with `droost workflow:say "<what>" --detail="<file, count or name>"` and carry on.'

// What the pane shows: the last document, the tab open, its size, and why
// droost could not be asked, if it could not.
let doc = null
let tab = 'spec'
let error = ''
let asking = false
let minimized = false
let opened = ''
let openedAlways = false

// How the pane asks to be seated: maximized as large as the layout allows,
// the dock as wide as it gives; minimized, the phase line alone inline and
// the narrowest strip the dock allows. Requests, not grants: a size the
// person dragged the pane to wins.
const SIZE = {
  max: { id: PANE, title: 'droost', rows: 200, columns: 400 },
  min: { id: PANE, title: 'droost', rows: 1, columns: 14 },
}

// How to run droost: the operator's setting, else the project's install.
async function command($, options) {
  const configured = typeof options?.droost_command === 'string' ? options.droost_command.trim() : ''
  if (configured !== '') return configured.split(/\s+/)
  const root = await $.session.cwd()
  for (const path of ['tools/droost/vendor/bin/droost', 'vendor/bin/droost']) {
    if (await $.fs.exists(root + '/' + path)) return ['php', root + '/' + path]
  }
  return null
}

// Asks droost for the panel, opens the pane when it should, and redraws.
async function refresh($, options) {
  if (asking) return
  asking = true
  try {
    const argv = await command($, options)
    if (argv === null) {
      error = 'no droost here (tools/droost/vendor/bin/droost); set the plugin\'s droost_command'
    } else {
      const out = await $.process.run([...argv, 'workflow:panel', '--json'], { cwd: await $.session.cwd(), timeoutMs: 20000 })
      if (out.exitCode === 0) {
        doc = JSON.parse(out.stdout)
        error = ''
      } else {
        error = (out.stderr || out.stdout || 'exit ' + out.exitCode).split('\n')[0].slice(0, 200)
      }
    }
  } catch (e) {
    error = String(e && e.message ? e.message : e).split('\n')[0].slice(0, 200)
  } finally {
    asking = false
  }
  // `always`: once a session, at the first answer droost gives, so a slow or
  // failed first ask does not lose it.
  if (doc && doc.open === 'always' && !openedAlways) {
    openedAlways = true
    await $.ui.open(SIZE.max)
  }
  // `auto`: once for each run that starts under way; never again for the
  // same run, so a pane the person closed stays closed.
  const run = doc && doc.run
  if (doc && doc.open === 'auto' && run && run.state !== 'completed' && run.id && run.id !== opened) {
    opened = run.id
    await $.ui.open(minimized ? SIZE.min : SIZE.max)
  }
  $.ui.invalidate('ui.render')
}

export function register(on, options) {
  on('session.start', async ($, e, next) => {
    await $.command.register({ name: 'droost-panel', description: 'Open droost\'s run panel: phases, spec, activity, gates, seeker' })
    await refresh($, options)
    $.clock.every(EVERY_MS, () => refresh($, options))
    return next(e)
  })

  on('command.run', { command: 'droost-panel' }, async ($) => {
    await refresh($, options)
    minimized = false
    await $.ui.open({ ...SIZE.max, focus: true, closeOnEscape: true })
    return {}
  })

  // A pane this opens by itself waits undrawn on a terminal narrower than the
  // engine's floor for an unasked pane. The person's next prompt counts as
  // asking, so it seats the waiting pane at any width: above the prompt when
  // the dock has no room.
  on('prompt.submit', async ($, e, next) => {
    try {
      if ((await $.ui.panes()).some((p) => p.id === PANE && !p.isPlaced)) {
        await $.ui.open(minimized ? SIZE.min : SIZE.max)
      }
    } catch {
      // Never hold a prompt up over the pane.
    }
    return next(e)
  })

  // The agent's task list, while the panel is the run's: described as such,
  // kept behind ToolSearch, and turned away with where to go instead.
  on('tool.describe', { tool: 'TaskCreate' }, async ($, e) => ({ ...e, description: TURNED_AWAY, isDeferred: true }))
  on('tool.describe', { tool: 'TodoWrite' }, async ($, e) => ({ ...e, description: TURNED_AWAY, isDeferred: true }))
  on('tool.call', { tool: 'TaskCreate' }, async () => ({ deny: TURNED_AWAY }))
  on('tool.call', { tool: 'TodoWrite' }, async () => ({ deny: TURNED_AWAY }))

  on('ui.render', { component: 'Pane' }, async ($, e, next) => {
    if (e.requestId !== PANE) return next(e)
    const el = $.ui.resolve(e)
    const shown = pickTab(doc, tab)
    if (shown) tab = shown.id
    const state = { tab, error, minimized, placement: e.props?.placement, bodyRows: e.props?.scroll?.bodyRows, bodyColumns: e.props?.bodyColumns }
    return paneTree(el, doc, state, (id) => {
      tab = id
      $.ui.invalidate('ui.render')
    }, e.surface, async () => {
      minimized = !minimized
      await $.ui.open(minimized ? SIZE.min : SIZE.max)
      $.ui.invalidate('ui.render')
    })
  })
}
