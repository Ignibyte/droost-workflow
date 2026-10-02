// droost-guard: droost's guard, inside Claude Code (owner, 2026-10-01).
//
// It complements the project's PreToolUse guard
// (.claude/hooks/droost-workflow-guard.php), which stays the floor. A mod a
// repository ships runs among the user's own mods, not ahead of them: only
// managed settings (prependPlugins) put a mod first, and a project's
// PreToolUse hook runs after every mod. So this does only what is honest
// from where it runs:
//
//  - refuses the agent's own routes to loading a mod (E3), at tool.call,
//    as the PHP guard does at PreToolUse;
//  - records, for the run's evidence, the Claude Code version and every
//    other mod that hooks tool calls or prompts, so a record can say what
//    could have stood in front of the guard (E5);
//  - pins the run's phase in the status line under the prompt.

const STATE_DIR = 'droost/droost-workflow'

const SHELL_ROUTES = [
  [/(^|[\s;&|(])(?:\S*\/)?claude\s+plugins?\s+(install|i|enable|update)\b/, 'installs or enables a Claude Code plugin'],
  [/(^|[\s;&|(])(?:\S*\/)?claude\s+plugins?\s+marketplace\s+(add|update)\b/, 'adds a source of Claude Code plugins'],
  [/(^|[\s;&|(])(?:\S*\/)?claude\b[^;&|\n]*\s--plugin-(dir|url)\b/, 'loads a plugin into a session'],
  [/\bCLAUDE_CODE_PLUGIN_DIRS=/, 'names plugin directories for every later session'],
]

const LOADED_FROM = /(^|\/)\.claude\/(dev-mods|plugins|droost-plugins)(\/|$)|(^|\/)\.claude\.json$/

const WATCHED_EVENTS = /^(tool\.(call|check)|classic\.PreToolUse|prompt\.(submit|section|context))/

/**
 * Why a tool call is one of the agent's routes to loading a mod, or null.
 *
 * Exported for the tests. Reading what is installed is not a route.
 */
export function refusal(e) {
  const tool = String(e?.tool ?? '')
  if (tool === 'Bash') {
    const command = String(e.command ?? '')
    for (const [pattern, why] of SHELL_ROUTES) {
      if (pattern.test(command)) return why
    }
    return null
  }
  if (['Write', 'Edit', 'MultiEdit', 'NotebookEdit'].includes(tool)) {
    const path = String(e.file_path ?? e.notebook_path ?? '')
    if (LOADED_FROM.test(path)) return 'writes where Claude Code loads plugins from'
  }
  return null
}

const watched = []

/**
 * Writes the host record beside the run's state, when the state exists.
 */
async function record($) {
  try {
    const dir = (await $.session.cwd()) + '/' + STATE_DIR
    if (!(await $.fs.exists(dir))) return
    const version = await $.session.version()
    await $.fs.write(dir + '/host-mods.json', JSON.stringify({
      claude_code: version?.version ?? null,
      recorded_by: 'droost-guard',
      mods_that_hook_tool_calls_or_prompts: watched,
      at: new Date().toISOString(),
    }, null, 2) + '\n')
  } catch {
    // A record that could fail a session is worth less than none.
  }
}

/**
 * Pins the open run's phase under the prompt, or clears the line.
 */
async function status($) {
  try {
    const file = (await $.session.cwd()) + '/' + STATE_DIR + '/run.json'
    if (!(await $.fs.exists(file))) return $.ui.status(undefined)
    const run = JSON.parse(await $.fs.read(file))
    if (!run.current_phase) return $.ui.status(undefined)
    const others = watched.length ? ` · ${watched.length} other mod(s) hook tool calls` : ''
    $.ui.status(`droost · ${run.run_id ?? 'run'} · ${run.current_phase}${others}`)
  } catch {
    $.ui.status(undefined)
  }
}

export function register(on) {
  on('tool.call', async ($, e, next) => {
    const why = refusal(e)
    if (why) {
      return {
        deny: `droost: this ${why}, and that is the OPERATOR's act. A mod runs inside Claude Code with the user's permissions and can approve a call droost's guard refused. Name the plugin and why it is needed, and ask the operator to install it.`,
      }
    }
    return next(e)
  })

  on('plugin.register', async ($, e, next) => {
    const name = String(e?.plugin ?? e?.name ?? e?.id ?? 'unknown')
    const events = [...(e?.uses?.hooks ?? []), ...(e?.uses?.events ?? [])].map(String)
    const hooks = events.filter((event) => WATCHED_EVENTS.test(event))
    if (hooks.length && !name.startsWith('droost-guard')) {
      watched.push({ name, tier: e?.tier ?? null, hooks })
    }
    return next(e)
  })

  on('session.start', async ($, e, next) => {
    const result = await next(e)
    await record($)
    await status($)
    $.clock.every(5000, () => { status($) })
    return result
  })
}
