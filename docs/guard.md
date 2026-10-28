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
agent's, the loosening is not. An agent that needs one proposes it — the
refusal prints the exact command to hand over, and the operator runs it in a
terminal of their own, outside the agent's session. Not with Claude Code's
`!`: it runs a command with no terminal, so the command refuses it as it
refuses the agent (F-256).

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
