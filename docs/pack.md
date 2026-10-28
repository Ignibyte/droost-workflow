# The pack and the run panel

What `droost init` installs for each agent harness, and the run panel beside the chat.

Part of the droost reference: [README](../README.md) · [all topics](README.md).

## The pack

`droost init` installs the phases as a `.claude/` pack: eleven skills (the
three entry verbs `workflow-start`, `workflow-continue` and `workflow-status`,
one per phase, `intake`, and one per knowledge command: `droost-code-wiki`,
`droost-code-tree`, `droost-search-arch`), four slash commands that are
one-paragraph pointers to the verb skills (`/droost:workflow:start`,
`/droost:workflow:continue`, `/droost:workflow:status`,
`/droost:workflow:intake` — Claude Code's way in; every other host reads the
skill itself, which is why the procedure lives there and not in the
command), five agents (the plan researcher and spec-writer, the adversarial
`workflow-seeker`, the one-finding-at-a-time `workflow-bug-fixer`, and
`droost-debugger`, which flips xdebug on for a stubborn failure and back off
after), and a shared partial on using droost. It writes:

```
.claude/skills/workflow-{start,continue,status}/SKILL.md
.claude/skills/workflow-{plan,code,test,complete}/SKILL.md
.claude/skills/intake/SKILL.md
.claude/skills/droost-{code-wiki,code-tree,search-arch}/SKILL.md
.claude/commands/droost/workflow/{start,continue,status,intake}.md
.claude/agents/workflow-{researcher,spec-writer,seeker,bug-fixer}.md
.claude/agents/droost-debugger.md
.claude/partials/droost-usage.md
.claude/templates/evaluation.md
.claude/settings.json        # the guard's hook entries, merged in
AGENTS.md                    # droost's blocks, created when absent
CLAUDE.md                    # Claude Code's pointer to AGENTS.md
droost/droost.yml            # only if you don't already have one
```

**The droost module's skills join it.** On a Drupal site with the droost
module on disk, `init` also installs each skill the module and its
submodules ship (`skills/<name>/`, the skill and the files it links):
`droost-tools`, which says which of the site's tools (`drush droost:tool`)
does what and how a run holds them, on a Canvas site `canvas-build`, and
with `droost_mcp` the `droost-tools-mcp` skill for the same tools over MCP. Each
goes into `.claude/skills/` for Claude Code and `.agents/skills/` for
Codex. Only a module named `droost` or `droost_*` is read, a name the pack
ships stays the pack's, and a skill whose module is gone is removed at the
next `init` unless you edited it.

The workflow forces the consult; the knowledge skills say how. Each phase
skill states its entry gate, the work, its exit gate, and **what it can and
cannot check without a running site**: droost reads files, so it answers with
the site down, but the site's content, active configuration and rendered
pages are not droost's to read, and a run that hides that produces a report
nobody should trust.

**Ownership is explicit.** Every directory the pack owns gets a
`.droost-workflow-pack` marker. Re-running `init` refreshes those
directories and nothing else; a directory without the marker belongs to you
and is refused rather than overwritten. Your `droost/droost.yml` is never
refreshed at all — it is version-controlled intent you wrote. `.claude/settings.json`
is merged (an existing file keeps everything else it holds; one that cannot
be parsed is refused, not replaced), and the state directory's ignore lines
are appended to `.gitignore` if they are not already there.

## The run panel, and the droost mod

`droost workflow:panel --json` is the run as a panel: the run (ticket, level,
lead, state), its four phases, a question it waits on, and tabs. droost's own
are **Spec** (the spec itself, and each criterion with its proof),
**Activity** (what the agent says it is doing, with `droost workflow:say
"<what>" [--detail=<…>]`, labelled as its own account, beside what the
guard saw it do: each call and the file or program it touched, refusals
marked), **Gates** (each phase's
verdicts, a pass that examined nothing, a gate a level turned off and one
that could not run told apart from a pass) and **Seeker** (each finding and
how it closed). A project's own tabs follow, from `panel.tabs` (the table
above). A tab is sections of four kinds:

| kind | holds |
|---|---|
| `text` | `text` |
| `markdown` | `text`, drawn as markdown (at most 9,500 characters) |
| `fields` | `items: [{label, value}]` |
| `checklist` | `items: [{text, done, note?}]` |
| `list` | `items: [{text, detail?, tag?, tone?: ok \| warn \| bad \| info \| muted}]` |

Each may carry a `title`. What does not fit is dropped and the tab says so,
so a project tab can be written against this and nothing else.

**The droost mod** draws it in Claude Code: a pane (`/droost-panel`) with
the Drupal drop, the phases, the run, and a bordered tab per tab (keys 1 to
9). It opens by itself as `panel.open` in droost.yml says: `auto` (the
default) when a run is under way, `always` at every session, `never` only
when asked; Claude Code shows a pane opened unasked only where it docks it.
While the mod is loaded the agent's own task list (TaskCreate, TodoWrite) is
turned away: the panel is the run's list. `m` minimizes it to the phase line alone and maximizes it again
(as much room as Claude Code gives); Esc closes it and `/droost-panel`
brings it back. Claude Code seats the pane: on the right, full height and
as wide as it allows, in its fullscreen layout (`/tui fullscreen`) on a
terminal 110 columns or wider; above the prompt, about a third of the
height, otherwise. The drop is a real image where the
terminal draws images (iTerm2, kitty, WezTerm, Ghostty; not inside tmux),
and a blank otherwise. It asks `droost workflow:panel --json` every three seconds and
draws what comes back; it refuses nothing and writes nothing. It ships in
this package (`claude-plugins/`), and installing it is the operator's act
(the guard refuses an agent's):

```
claude plugin marketplace add ./tools/droost/vendor/droost/workflow/claude-plugins
claude plugin install droost@droost --scope project
```

or, for one session, `claude --plugin-dir tools/droost/vendor/droost/workflow/claude-plugins/droost`.
Where droost runs in a container, set the plugin's `droost_command` (for
example `ddev droost`).
