# The guard

The hook that holds an agent to the run, which commands it keeps for the operator, and which agent harnesses it covers.

Part of the droost reference: [README](../README.md) · [all topics](README.md).

## The guard, and each harness's shim

The guard is droost's code and ships in the package; it is never copied into
the project. Each agent harness's hook configuration gets one line per
event, calling the guard from where the package is installed. For Claude
Code, `init` writes nine entries into `.claude/settings.json` (PreToolUse
on Edit/Write, on Bash, on `mcp__.*` and on AskUserQuestion; PostToolUse for
the nudge, for Agent/Task and for AskUserQuestion; Stop; SubagentStop), each running
`php <package>/bin/droost-guard <verb> --harness=claude` (`droost guard
<verb>` is the same guard). Before a tool call it applies droost's rules and
the project's walls; after an event it runs the project's hooks.

It refuses, from the agent: out-of-phase edits while a run is open
(`enforcement`), custom-code edits with no run at all (`require_run`), edits
to the run's record, the evidence store, the baseline, the lever file and the
project's rule files during a run, and edits to its own wiring
(`.claude/settings.json`) and to the package (`tools/droost/`) at any time,
as it refuses a new file the editor would make in the state directory (the
spec is the one file there that is the agent's).
And it refuses the operator's commands from the agent's shell, in every
spelling (`droost workflow:<verb>` from any install path, the retired
`droost-workflow <verb>`, and the colon spelling `droost:workflow:<verb>`),
handing back a command the operator can run:

| Refused from the agent's shell | What is still the agent's to run |
|---|---|
| `droost workflow:gate-waive` | — nothing; a waiver is always the operator's |
| `droost workflow:baseline` (writing) | `--status` and `--measure`, which only read |
| `droost workflow:bypass` | `bypass --off`, which tightens |
| `droost workflow:effort <level>` | bare `effort` (reports) and `effort <level> --preview` (prices it) |
| `droost workflow:ticket move <id> <state>` | `ticket list`, `ticket show` and `ticket new` (a follow-up, filed in backlog) |
| `droost workflow:intake abandon`, `intake answer` | `intake start`, `intake audit`, `intake check`, `intake status`, `intake ask`, and `intake approve` once the human has said yes; asking the human through AskUserQuestion, whose answers the guard records |
| a script `operator_only` names | — |

The pattern is the same in each row: the reading and the tightening are the
agent's, the loosening is not. An agent that needs one asks for it, and the
person says yes **where they are**: the refusal gives the agent a question
to put through AskUserQuestion, word for word, the guard records the answer
the dialog returned, and that one command then runs once (the approval lasts
30 minutes and is spent on use). A terminal of the operator's own is the
other proof, for a host with no dialog. Not Claude Code's `!`: it runs a
command with no terminal, so the command refuses it as it refuses the agent
(F-256).

**Three more verbs take the same two proofs, in the command itself.**
`droost workflow:uninstall` (it takes the guard's hooks out), a baseline
write, and `droost workflow:swap agentic` (it clears the question a run is
waiting on) refuse from a shell with no terminal and no approval, and say
what to ask. `swap interactive` tightens and asks for nothing.

**The guard is not for typing.** `droost guard <verb>`, the package's
`bin/droost-guard` and the script itself are what a harness calls, with its
own record of the session. Run from the agent's shell they are refused:
handed a payload, the guard writes the approvals and the tool-call record a
run is judged on. `php -l` on it is a syntax check and stays open.

**What a tool call has to be, to be read.** A substitution is refused only
in the command-name word of `drush`, `droost` or `droost-workflow`, or in a
word before it; after the verb it is an argument (`drush uli --uid=$(…)`).
What `echo` and `printf` are handed is text, unless the line pipes,
substitutes or evaluates it. Naming `evidence.sqlite` in a search pattern, a
commit message or printed text is not reaching it. A tool's `help` (`drush
droost:tool <id> help`) is reading in every phase. A call the guard cannot
parse is refused, not let through.

## Known limits

The guard reads a command before it runs, and no reading of a shell string
decides what it will do. It stops the ordinary route and makes the
deliberate one visible; the evidence store's digest chain and the seeker
are what catch the rest. These are known, and left, because each remedy
either refuses commands people type every day or makes the guard parse
more, which is where its false refusals have come from. One seen in a real
run is reopened.

- **The rule files are ordinary files while no run is open** (F-471). That
  is when a person asks their agent to change them.
- **The state directory can be removed wholesale** by `git clean -fdx`,
  `git stash -a` or an archive extracted over it (F-473). Refusing those
  would stop ordinary work.
- **Scripts the guard does not open** (F-468): three levels of one script
  calling another, a script under `vendor/`, one holding a NUL byte.
- **Writes the plan wall does not read** (F-469, the half left): `git
  checkout <rev> -- path`, `patch`, `curl -o`, `find -delete`, and code
  that builds a path in pieces.
- **A project gate file changed during a run keeps its verdict**, with a
  note on it (F-451). The guard refuses the agent's edit; the change that
  is left is a person correcting their own file.
- **An unknown `droost_*` site tool is read as a reading tool** (F-482).
  The list of writing tools is kept by id and was checked against the
  module's 54 tools on 2026-11-21.
- **The terminal check is `stream_isatty`**, which a pseudo-terminal
  satisfies (F-462), and **`guardWired()` reads the settings file**, not
  whether the guard ran this session (F-463). Both take an agent going out
  of its way, which the seeker's discipline lens is there to see.
- F-472, F-474, F-475 and F-476 in the 2026-11-21 audit are of the same
  kind and are recorded there.

**Which harnesses are forced.** A harness is forced only when its hooks can
refuse a tool call before it runs, and only Claude Code has a shim today.
`init` writes droost's AGENTS.md block, and the pointer files of the
harnesses it finds (`--harness=all` for every one it knows: Claude Code,
Codex, opencode, Gemini, Qwen), so every host gets the doctrine, the
levers, the command and the skills as readable prose — and not the
enforcement. `declare-tasks codex|other|none` records which host is driving,
and on a host with no shim the status document says `enforcement.effective:
advisory` in as many words: the gates still hold the run, because the engine
runs them, but nothing stops an out-of-phase edit. A report must not claim a
discipline the host never had. The guard's rules are written against
droost's own events (a file write, a shell command, a tool call, a stop, a
subagent), with a translator per harness, so a second shim is a translator
and its probe, not a second guard.

**Session transcripts are protected (F-401).** A Claude Code session
transcript (`~/.claude/projects/**/*.jsonl`, or under `$CLAUDE_CONFIG_DIR`)
may be read by a project's gate for a result Claude Code recorded, such as a
background task's notification. The guard refuses writes to one from the
editor, the shell and interpreter code; reading stays free, and an agent's
memory notes beside the transcripts stay its own.
