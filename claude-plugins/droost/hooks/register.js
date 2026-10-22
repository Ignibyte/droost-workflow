// droost's run panel, inside Claude Code: a pane with the run's phases and
// tabs, and the run's line under the prompt.
//
// Everything it shows comes from `droost workflow:panel --json`, asked every
// few seconds: droost builds the panel (its own tabs and the project's, from
// droost.yml `panel.tabs`), and this only draws it. It refuses nothing and
// writes nothing; the guard, reached through .claude/settings.json, is what
// holds a run. `/droost-panel` opens the pane; it opens itself, unfocused,
// when a session starts with a run under way.

import { paneTree, pickTab, statusText } from './view.js'

const PANE = 'droost'
const EVERY_MS = 3000

// What the pane shows: the last document, the tab open, and why droost could
// not be asked, if it could not.
let doc = null
let tab = 'activity'
let error = ''
let updatedAt = ''
let asking = false
let collapsed = false

// How the pane asks to be seated: wide enough for the tabs when docked beside
// a fullscreen transcript, a third of the screen above the prompt otherwise,
// and three rows when folded.
const OPEN = { id: PANE, title: 'droost', columns: 64 }
const ROWS = { open: 28, folded: 4 }

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

// Asks droost for the panel, and redraws.
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
        updatedAt = new Date().toLocaleTimeString()
      } else {
        error = (out.stderr || out.stdout || 'exit ' + out.exitCode).split('\n')[0].slice(0, 200)
      }
    }
  } catch (e) {
    error = String(e && e.message ? e.message : e).split('\n')[0].slice(0, 200)
  } finally {
    asking = false
  }
  $.ui.status(statusText(doc))
  $.ui.invalidate('ui.render')
}

export function register(on, options) {
  on('session.start', async ($, e, next) => {
    await $.command.register({ name: 'droost-panel', description: 'Open droost\'s run panel: phases, spec, activity, gates, seeker' })
    await refresh($, options)
    if (doc && doc.run && doc.run.state !== 'completed') {
      await $.ui.open({ ...OPEN, rows: ROWS.open })
    }
    $.clock.every(EVERY_MS, () => refresh($, options))
    return next(e)
  })

  on('command.run', { command: 'droost-panel' }, async ($) => {
    await refresh($, options)
    collapsed = false
    await $.ui.open({ ...OPEN, rows: ROWS.open, focus: true, closeOnEscape: true })
    return {}
  })

  on('ui.render', { component: 'Pane' }, async ($, e, next) => {
    if (e.requestId !== PANE) return next(e)
    const el = $.ui.resolve(e)
    const shown = pickTab(doc, tab)
    if (shown) tab = shown.id
    const view = { tab, error, updatedAt, collapsed, placement: e.props?.placement, viewport: e.viewport }
    return paneTree(el, doc, view, (id) => {
      tab = id
      $.ui.invalidate('ui.render')
    }, e.surface, async () => {
      collapsed = !collapsed
      // Asks for the new height; a size the person dragged to wins.
      await $.ui.open({ ...OPEN, rows: collapsed ? ROWS.folded : ROWS.open })
      $.ui.invalidate('ui.render')
    })
  })
}
