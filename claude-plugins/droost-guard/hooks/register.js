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
//  - pins the run's phase, and whether the plan has looked at the site yet,
//    in the status line under the prompt;
//  - carries droost's guidance (owner, 2026-10-15): the agent looks at the
//    site through droost before it plans, and asks droost while it codes
//    instead of walking the tree. It never says how to build and never
//    refuses: a note on the prompt while the plan has asked droost nothing,
//    and a note on a tool's result when the agent searches core, contrib or
//    vendor. The project's settings hooks carry the same notes (the guard's
//    `nudge` mode), so this adds them only where that floor is not
//    installed.

const STATE_DIR = 'droost/droost-workflow'

const SHELL_ROUTES = [
  [/(^|[\s;&|(])(?:\S*\/)?claude\s+plugins?\s+(install|i|enable|update)\b/, 'installs or enables a Claude Code plugin'],
  [/(^|[\s;&|(])(?:\S*\/)?claude\s+plugins?\s+marketplace\s+(add|update)\b/, 'adds a source of Claude Code plugins'],
  [/(^|[\s;&|(])(?:\S*\/)?claude\b[^;&|\n]*\s--plugin-(dir|url)\b/, 'loads a plugin into a session'],
  [/\bCLAUDE_CODE_PLUGIN_DIRS=/, 'names plugin directories for every later session'],
]

const LOADED_FROM = /(^|\/)\.claude\/(dev-mods|plugins|droost-plugins)(\/|$)|(^|\/)\.claude\.json$/

const WATCHED_EVENTS = /^(tool\.(call|check)|classic\.PreToolUse|prompt\.(submit|section|context))/

// The words of every note, kept identical to the PHP guard's (`nudge` mode),
// so a record reads the same whichever of the two said it.
export const NOTES = {
  ground: 'droost: look before you spec. `droost code:wiki` (how this project is put together, and every contrib module and theme it installs) and `droost code:tree` (its modules, files, classes, hooks, services and routes) are the two looks plan cannot close without; `droost search:arch`, `droost code:symbol`, `droost code:graph` and `droost search:code` answer the narrower questions. droost reads files, so no site is needed. The spec\'s `## Grounding` cites what you found; plan does not close until its citations resolve and droost was asked. How you build is your choice.',
  traverse: 'droost: droost knows this codebase first-hand, core and contrib included. `droost code:symbol` says where a class or hook lives, `droost code:graph` what calls it and what it calls, `droost code:tree` how a module is laid out, `droost code:wiki` how this project is put together and what each installed module provides, and `droost search:code` finds this project\'s code by words. Reading files works too; asking is faster, and it is recorded.',
}

// The tools that read this site's knowledge: asking one is what grounds a
// plan (owner, 2026-10-15). The same list as the evidence report's.
export const KNOWLEDGE_TOOLS = [
  'code:wiki', 'code:tree', 'search:arch', 'code:symbol', 'code:graph',
  'code:docs', 'code:deprecations', 'search:code', 'brief',
]

// Where a search walks code that is not the project's own.
const FOREIGN_PATH = /(^|\/)(web\/core|core|vendor|(web\/)?(modules|themes|profiles)\/contrib)(\/|$)/
const SEARCH_HEAD = /^(?:\S*\/)?(grep|egrep|fgrep|rg|ag|ack|find|fd|tree)$/
const SPEC_FILE = /(^|\/)(droost\/droost-workflow|\.droost-workflow)\/(tmp-)?spec(-[^/]+)?\.md$/

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

/**
 * Whether a call searches code that is not the project's own.
 *
 * Grep and Glob by their `path`; a shell command when a search program runs
 * over a path in core, contrib or vendor. Exported for the tests.
 */
export function traverses(e) {
  const tool = String(e?.tool ?? '')
  if (tool === 'Grep' || tool === 'Glob') {
    const path = String(e.path ?? '')
    const pattern = String(e.pattern ?? '')
    return FOREIGN_PATH.test(path) || (tool === 'Glob' && FOREIGN_PATH.test(pattern))
  }
  if (tool === 'Bash') {
    // A SEARCH, read segment by segment: a search program whose own words
    // name core, contrib or vendor, or that runs after a `cd` into one. P8
    // run 1's first note fell on `ls vendor/bin | grep -i droost`. A bare
    // `core` is as likely a search term as a directory, so a word counts when
    // it is a path (it has a slash) or is `vendor`.
    let inForeign = false
    for (const segment of String(e.command ?? '').split(/\s*(?:&&|\|\||[;|\n])\s*/)) {
      const words = segment.trim().split(/\s+/).map((w) => w.replace(/^['"]|['"]$/g, '')).filter(Boolean)
      if (!words.length) continue
      if (words[0] === 'cd') {
        inForeign = Boolean(words[1]) && FOREIGN_PATH.test(words[1])
        continue
      }
      if (!SEARCH_HEAD.test(words[0])) continue
      if (inForeign) return true
      if (words.slice(1).some((w) => (w.includes('/') || w === 'vendor') && FOREIGN_PATH.test(w))) return true
    }
    return false
  }
  return false
}

/**
 * Whether a path is the run's spec.
 */
export function isSpec(path) {
  return SPEC_FILE.test(String(path ?? ''))
}

/**
 * Whether the ledger shows droost's knowledge asked for this run.
 *
 * A row made before the run opened (`run` null) counts, as the ledger's own
 * reader keeps such rows for the run that opens next.
 */
export function grounded(ledger, runId) {
  for (const line of String(ledger ?? '').split('\n')) {
    if (!line.trim()) continue
    let row
    try { row = JSON.parse(line) } catch { continue }
    if (!KNOWLEDGE_TOOLS.includes(row?.tool) || row?.outcome !== 'ok') continue
    if (row.run == null || runId == null || row.run === runId) return true
  }
  return false
}

/**
 * The note a finished tool call earns, given what this session has seen.
 *
 * `seen` counts per phase, so a search gets a note the first time in a phase
 * and every tenth time after, and a file shape once per phase.
 */
export function noteFor(e, phase, seen, planGrounded) {
  const tool = String(e?.tool ?? '')
  const key = (name) => `${phase ?? 'none'}:${name}`
  if (traverses(e)) {
    const n = (seen[key('traverse')] = (seen[key('traverse')] ?? 0) + 1)
    return n === 1 || n % 10 === 0 ? NOTES.traverse : null
  }
  if (['Write', 'Edit', 'MultiEdit'].includes(tool)) {
    const path = String(e.file_path ?? '')
    if (isSpec(path)) {
      if (planGrounded) return null
      const n = (seen[key('spec')] = (seen[key('spec')] ?? 0) + 1)
      return n === 1 || n % 5 === 0 ? NOTES.ground : null
    }
  }
  return null
}

const watched = []
const seen = {}
let floor = null

async function read($, path) {
  try {
    if (!(await $.fs.exists(path))) return null
    return await $.fs.read(path)
  } catch {
    return null
  }
}

/**
 * The open run: its id and phase, or nulls.
 */
async function openRun($) {
  const text = await read($, (await $.session.cwd()) + '/' + STATE_DIR + '/run.json')
  try {
    const run = JSON.parse(text ?? 'null')
    return { id: run?.run_id ?? null, phase: run?.current_phase ?? null }
  } catch {
    return { id: null, phase: null }
  }
}

async function groundedNow($, runId) {
  return grounded(await read($, (await $.session.cwd()) + '/' + STATE_DIR + '/tool-calls.jsonl'), runId)
}

/**
 * Whether the project's settings hooks already carry the notes.
 */
async function hasFloor($) {
  if (floor !== null) return floor
  const settings = await read($, (await $.session.cwd()) + '/.claude/settings.json')
  floor = typeof settings === 'string' && settings.includes('droost-workflow-guard.php\\" nudge')
  return floor
}

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
    const run = await openRun($)
    if (!run.phase) return $.ui.status(undefined)
    const plan = run.phase === 'plan' ? ((await groundedNow($, run.id)) ? ' · plan grounded' : ' · plan not grounded yet') : ''
    const others = watched.length ? ` · ${watched.length} other mod(s) hook tool calls` : ''
    $.ui.status(`droost · ${run.id ?? 'run'} · ${run.phase}${plan}${others}`)
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
    const out = await next(e)
    try {
      if (await hasFloor($)) return out
      if (!out || typeof out.result !== 'string') return out
      const run = await openRun($)
      const note = noteFor(e, run.phase, seen, await groundedNow($, run.id))
      return note ? { ...out, result: out.result + '\n\n' + note } : out
    } catch {
      return out
    }
  })

  on('prompt.submit', async ($, e, next) => {
    try {
      const run = await openRun($)
      if (run.phase === 'plan' && !(await groundedNow($, run.id))) {
        return next({ ...e, context: [...(Array.isArray(e?.context) ? e.context : []), NOTES.ground] })
      }
    } catch {
      // Guidance that could break a prompt is worth less than none.
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
