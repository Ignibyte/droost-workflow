<?php

/**
 * @file
 * The droost workflow enforcement guard, wired as a Claude Code hook.
 *
 * PHASE enforcement exists only inside a run, at the level the run froze at
 * begin (hard | soft | off). Ordinary conversation is never policed for a phase
 * it is not in.
 *
 * That used to be stated as "no active run, no opinion — when run.json is
 * absent, unreadable or the run has ended, the guard exits 0 without a word",
 * and it was false on all three counts by the time anyone read it. THREE THINGS
 * HOLD RUN OR NO RUN, because each of them is what makes a run mean anything:
 *
 *   * the enforcement files — this guard, `.claude/settings(.local).json`,
 *     `run.json`, the bypass grant — and the directories holding them;
 *   * the adoption baseline and the evidence store;
 *   * the `require_run` wall over custom modules and themes, which is the whole
 *     point: building with no run at all is the one way to skip governance
 *     entirely.
 *
 * And under `stop`, a run.json that exists but cannot be PARSED is refused
 * rather than permitted: a damaged record is not the same as no record.
 *
 *   php .claude/hooks/droost-workflow-guard.php pre-tool-use
 *     Blocks (hard) or warns once per phase (soft) when a file-editing tool
 *     fires while the run is still in PLAN. Writes under the state directory
 *     are allowed — the plan phase's whole job is writing the spec — EXCEPT
 *     the run record, the bypass grant and the evidence store, which sit there
 *     too and are nobody's to edit.
 *
 *   php .claude/hooks/droost-workflow-guard.php stop
 *     Blocks (hard) or reminds once per phase (soft) when a turn tries to
 *     end while a run is mid-phase. A failed phase is a legitimate end and
 *     is never blocked; and when Claude reports the stop hook already fired
 *     (stop_hook_active), the guard stands down rather than deadlocking a
 *     run the agent cannot advance.
 *
 *   php .claude/hooks/droost-workflow-guard.php operator-commands
 *     Wired to the Bash tool. Refuses `droost:workflow:gate-waive`,
 *     `droost:workflow:bypass` (granting; `--off` re-arms the wall and is
 *     allowed) and ARMING a droost write gate (`droost:gate <flag> on`,
 *     `config:set droost.settings allow_* true`; disarming is allowed) from
 *     the agent's own shell, run or no run — plus writing the baseline and
 *     moving the effort dial, which is five commands, and the README's table
 *     lists them with what stays the agent's. Each is the operator's
 *     signature; "CLI-only" never excluded an agent, which has a shell — round
 *     23 watched a subject run the waiver itself after the operator picked it
 *     in a dialog, overwriting the operator's recorded reason. What the phrase
 *     excludes is the MCP transport, and that is a much smaller claim than it
 *     sounds. The agent proposes; a human runs it.
 *
 * Exit 0 allows (optionally emitting a {"systemMessage": ...} nudge);
 * exit 2 blocks, with the reason on stderr for the agent to act on.
 * Warn-once markers live inside the state directory, which is gitignored.
 */

declare(strict_types=1);

$mode = $argv[1] ?? '';
// A hook runs from the invoking tool's cwd, which the agent's Bash tool can
// move out of the project root (a persisted `cd`). getcwd() would then point
// the guard at the wrong tree — reading a run.json and lever that are not
// there, so the wall and the plan-phase block quietly stop firing. Claude Code
// sets CLAUDE_PROJECT_DIR to the real project root for every hook; prefer it,
// and fall back to getcwd() for a manual CLI run where the var is unset.
$named = guard_named_root() !== '';
$root = $named ? guard_named_root() : getcwd();
if ($root === FALSE) {
  exit(0);
}

// And when the var is unset — a plain CLI run, Codex, a hook invoked by hand —
// walk up to the project, exactly as ArgvDispatcher does. It did not, and the
// engine did, so the two resolved different projects: a `cd` into any
// subdirectory left the guard seeing "no active run" and standing the stop wall
// down, while the binary from the same directory happily advanced the real run.
// The engine advancing a run the guard is not watching is the worst outcome
// available here.
//
// An ACTIVE RUN wins outright and wins over anything nearer, because an empty
// `lib/droost/droost-workflow/` — one mkdir, which this guard permits, since
// creating a directory is not an edit — must not hide the run somebody is in.
// Inlined rather than imported: this file carries no autoloader by design, and
// `PackGuardParityTest` is what keeps the two copies honest.
if (!$named) {
  $here = rtrim($root, '/');
  $levers = NULL;
  $boundary = NULL;
  for ($depth = 0; $depth < 32; $depth++) {
    if (is_file($here . '/droost/droost-workflow/run.json')
      || is_file($here . '/.droost-workflow/run.json')) {
      $root = $here;
      $levers = NULL;
      $boundary = NULL;
      break;
    }
    if ($levers === NULL && is_file($here . '/droost.workflow.yml')) {
      $levers = $here;
    }
    // A worktree and a submodule carry `.git` as a FILE, so `is_dir` alone
    // walked straight out of them into somebody else's repo. But `file_exists`
    // alone meant ANY file called `.git` was a repository boundary, and
    // `echo x > modules/custom/.git` is one command: the walk stopped there,
    // the root became a directory three levels below the real one, and every
    // protected path — `.claude/hooks/droost-workflow-guard.php` among them —
    // stopped matching. One echo turned the guard off.
    //
    // A real `.git` file says `gitdir: <path>` and that path is on disk. That
    // is the whole difference, it costs a read of a few bytes, and a plant now
    // has to build a plausible git directory rather than write a word.
    if ($boundary === NULL && git_boundary($here)) {
      // REMEMBERED, not obeyed yet. A boundary is a real stop — a run started
      // in a worktree of repo B must not adopt repo A's levers — but it is
      // also one `git init web/modules/custom`, one `ln -s /tmp .git`, or one
      // `gitdir:` pointing at any directory that happens to exist. Each of
      // those hid the active run above and took `stop` from 2 to 0.
      //
      // So the walk keeps going, and an ACTIVE RUN above the boundary wins —
      // the rule this file already states for an empty state directory, which
      // is the same rule for the same reason. With no run above, the boundary
      // answers, and a real worktree behaves exactly as before.
      $boundary = ['root' => $here, 'levers' => $levers];
    }
    $parent = dirname($here);
    if ($parent === $here) {
      break;
    }
    $here = $parent;
  }
  if ($boundary !== NULL) {
    // No active run above it, so the boundary stands and answers — a real
    // worktree or submodule behaves exactly as it did before.
    $root = $boundary['root'];
    $levers = $boundary['levers'];
  }
  if ($levers !== NULL) {
    $root = $levers;
  }
}

// The run-state directory — the visible droost/droost-workflow, or the legacy
// hidden .droost-workflow when only that exists. Resolved exactly as the engine
// does (RunStateStore::resolveStateDir) so the wall reads run state from where
// it actually lives; a mismatch would over- or under-block. Standalone hook, so
// the rule is inlined rather than imported.
// Mirrors RunStateStore::resolveStateDir() exactly, and must keep doing so: a
// mismatch would over- or under-block. Standalone hook, so the rule is inlined
// rather than imported, and `PackGuardParityTest` compares the two.
//
// Where the RECORD is, before which directory exists. Existence alone made
// `mkdir -p droost/droost-workflow` a disarm on a legacy project — the new
// directory wins, holds no run.json, and this guard reads that as "no active
// run" while the real record sits untouched in `.droost-workflow/`.
$stateDir = 'droost/droost-workflow';
if (!is_file($root . '/droost/droost-workflow/run.json')) {
  if (is_file($root . '/.droost-workflow/run.json')
    || (is_dir($root . '/.droost-workflow') && !is_dir($root . '/droost/droost-workflow'))) {
    $stateDir = '.droost-workflow';
  }
}

// A CRASH READS AS PERMISSION. The host runs this hook and interprets its exit
// code: 2 blocks, 0 allows, and anything else is neither — which every host
// tried resolves in the agent's favour. So every way this file can die has to
// land on 2, and two of them did not.
//
// A NUL byte in `file_path` was the live one. `preg_match()` and
// `file_exists()`
// throw ValueError on a NUL in PHP 8, nothing here caught it, and
// `{"tool_input":{"file_path":"a\u0000b"}}` exited 255 — the protected-path
// check never ran. A fatal error anywhere else in the file lands the same way.
//
// These two handlers are the floor: whatever goes wrong, the answer is a
// refusal that says so, never a silent allow. They are deliberately the FIRST
// thing the file does, because a crash before they are installed is exactly the
// crash they exist to catch.
// ONE ENFORCED CONTINUATION PER STOP ATTEMPT, and that has to include these.
// These two handlers refused unconditionally, and a crash is DETERMINISTIC:
// the same payload crashes on attempt one, two and ten, so under `stop` the
// agent was told to continue, tried to stop again, and got the same exit 2 for
// ever. A hook that can never be satisfied is not enforcement, it is a hang —
// and the remedy these print (`droost-workflow init`) fixes none of the
// causes that land here. The file already learned this once, for an
// unparseable run.json; the handlers added later did not inherit it.
//
// Written by the reader below the moment it knows, and defaulted so that a
// crash BEFORE the payload is understood still fails closed. Only `stop` is
// let through: a refusal in pre-tool-use costs one tool call, and the agent
// can do something else.
$GLOBALS['workflow_guard_mode'] = $argv[1] ?? '';
$GLOBALS['workflow_guard_continued'] = FALSE;
$crashExit = static function (): int {
  return (($GLOBALS['workflow_guard_mode'] ?? '') === 'stop'
    && ($GLOBALS['workflow_guard_continued'] ?? FALSE) === TRUE) ? 0 : 2;
};
set_exception_handler(static function (\Throwable $error) use ($crashExit): void {
  $GLOBALS['workflow_guard_verdict'] = 'crash';
  $GLOBALS['workflow_guard_rule'] = 'crash:' . $error::class;
  fwrite(STDERR, sprintf(
    'The droost workflow guard could not complete this check (%s: %s at %s:%d), '
    . 'so it refused rather than permitted. A guard that cannot decide has not '
    . 'decided in your favour. Retry the action as written; if it keeps failing, '
    . 'the OPERATOR reinstalls the hook with `droost-workflow init`.',
    $error::class,
    $error->getMessage(),
    basename($error->getFile()),
    $error->getLine(),
  ));
  exit($crashExit());
});
register_shutdown_function(static function () use ($crashExit): void {
  $fatal = error_get_last();
  if ($fatal !== NULL && ($fatal['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) !== 0) {
    $GLOBALS['workflow_guard_verdict'] = 'crash';
    $GLOBALS['workflow_guard_rule'] = 'crash:fatal';
    fwrite(STDERR, sprintf(
      'The droost workflow guard died before it could decide (%s at %s:%d), so '
      . 'it refused. The OPERATOR reinstalls the hook with `droost-workflow init`.',
      $fatal['message'],
      basename($fatal['file']),
      $fatal['line'],
    ));
    // WRITTEN HERE, because the ledger's own shutdown function never runs
    // after this one. An exit() inside a shutdown function ends every
    // shutdown function registered after it, and the ledger's is registered
    // later, once the root is known. So a fatal left no row at all, while the
    // comment on the ledger promised one "however this script leaves".
    $record = $GLOBALS['workflow_guard_record'] ?? NULL;
    if ($record instanceof \Closure) {
      $record();
    }
    exit($crashExit());
  }
});

// THE ONE THING THE RECORD COULD NOT SHOW: that this hook ran at all.
//
// `run.json` carries the enforcement level somebody REQUESTED, and the status
// document's `effective` is inferred from the host the session DECLARED — a
// claim derived from a claim. So a run could report `enforcement: hard`, pass
// every phase, and render a clean evaluation while this file was never invoked
// once: settings.json edited, a host that ignores hooks, a stale copy that
// crashed on load. Nothing anywhere contradicted it. Every evaluation written
// so far has had to list "whether enforcement held" as a blind spot, and it
// was the largest one, because it is the thing the entire discipline rests on.
//
// One append per invocation, and it cannot be wrong about the only question it
// answers: a shutdown function runs however this script leaves — normal exit,
// `exit(2)`, an uncaught throw, a fatal. A run whose store holds no guard rows
// while claiming `hard` is now a finding rather than an unknown.
//
// EVERY ROW SAYS WHAT THE GUARD DECIDED, in one of four verdicts:
//
//   refuse: the call was blocked (exit 2). Every refusal leaves through
//   `guard_refuse()`, the only exit(2) in this file, and names its rule;
//   `GuardVerdictLedgerTest` fails if a second exit(2) appears.
//
//   nudge: a soft wall was hit and let the call through, with a message the
//   first time in a phase and silently after. The rule names the wall, so
//   every hit is counted, not only the first.
//
//   crash: the guard failed, and the crash handlers answered for it.
//
//   allow: nothing objected.
//
// Until this chokepoint existed, only the operator-command refusals and the
// require_run wall set a verdict, and every other row read `invoked`: "the
// hook ran, and this row does not say what it decided". At `soft` that hid
// little, because soft walls let the call through anyway. At `hard` the plan
// wall and the shell's plan wall REFUSE, and the record could not say so.
// Rows from a guard older than this one still read `invoked`.
//
// JSONL, ingested by the engine at phase close, rather than a write to the
// evidence store: this file reads that database strictly read-only (see
// `unresolved_checks()`) and must not become a writer of it. The tool-call
// ledger has the same shape for the same reason. The state directory is
// already a protected path, so the agent cannot edit what this writes.
$GLOBALS['workflow_guard_verdict'] = 'allow';
$GLOBALS['workflow_guard_rule'] = NULL;
$recordCall = static function () use ($root, $stateDir): void {
  // Once, whoever calls first: the fatal-error handler writes the row itself
  // (see above), and the shutdown function would otherwise write it again.
  static $written = FALSE;
  if ($written) {
    return;
  }
  $written = TRUE;
  $directory = $root . '/' . $stateDir;
  // Only where the run state already lives. Creating the directory to record
  // that the hook fired would make this file a writer on every project that
  // has never run anything, and `mkdir droost/droost-workflow` is itself a
  // move this guard treats as significant.
  if (!is_dir($directory)) {
    return;
  }
  $run = NULL;
  // The PHASE AS THE HOOK SAW IT, from the document it is already reading.
  // The engine stamps its rows with the phase open at ingest, which is the
  // phase that CLOSED — so a browser call made during test was filed under
  // whatever came next. Cheap to fix here and unanswerable anywhere else.
  $phase = NULL;
  $record = @file_get_contents($directory . '/run.json');
  if (is_string($record)) {
    $decoded = json_decode($record, TRUE);
    if (is_array($decoded) && is_string($decoded['run_id'] ?? NULL)) {
      $run = $decoded['run_id'];
    }
    if (is_array($decoded) && is_string($decoded['current_phase'] ?? NULL)) {
      $phase = $decoded['current_phase'];
    }
  }
  $row = [
    'run' => $run,
    'phase' => $phase,
    'tool' => $GLOBALS['workflow_guard_tool'] ?? NULL,
    'mode' => $GLOBALS['workflow_guard_mode'] ?? '',
    'verdict' => $GLOBALS['workflow_guard_verdict'] ?? 'allow',
    'rule' => $GLOBALS['workflow_guard_rule'] ?? NULL,
    'at' => date('c'),
  ];
  // WHICH GENERATORS THIS COMMAND RAN, when it was allowed to run. droost's
  // grounding_check asks the diary whether a file the run added came from
  // the surface that makes it, and a `drush generate` left no other trace:
  // the scaffold ledger holds droost's blueprints only, and the tool-call
  // ledger holds MCP tools. Only an allowed command counts, and only one
  // that writes (not `--dry-run`, not `--help`). Absent, not empty, when
  // there is nothing to say, so every older row reads the same.
  $command = $GLOBALS['workflow_guard_command'] ?? '';
  if ($row['verdict'] === 'allow' && is_string($command) && $command !== '') {
    $generated = operator_commands_generators($command);
    if ($generated !== []) {
      $row['generated'] = $generated;
    }
  }
  $line = json_encode($row, JSON_UNESCAPED_SLASHES);
  if ($line === FALSE) {
    return;
  }
  // Silenced and unchecked on purpose. A hook that failed because it could not
  // write its own diary would turn a full disk into an agent that cannot act;
  // the record is worth having and worth nothing if it can block the thing it
  // is recording. LOCK_EX because several tool calls can overlap.
  @file_put_contents($directory . '/guard-calls.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
};
$GLOBALS['workflow_guard_record'] = $recordCall;
register_shutdown_function($recordCall);

// The payload is read ONCE: several branches below consult it, and a stream
// read twice is empty the second time.
$stdin = (string) stream_get_contents(STDIN);

// Decoded HERE and not at the stop branch below, because one refusal that must
// honour `stop_hook_active` fires a hundred lines earlier: an unparseable
// run.json. It did not honour it, and that made the refusal terminal — Claude
// was made to continue once, tried to stop again, and got the same exit 2, for
// ever. A hook that can never be satisfied is not enforcement, it is a hang,
// and the remedy it prints (`reset --force`) is an OPERATOR command the stuck
// agent is not allowed to run. One enforced continuation per stop attempt is
// the contract, and it is the contract for every branch.
$payload = json_decode($stdin, TRUE);
$payload = is_array($payload) ? $payload : [];
// TRUTHY, not identical-to-TRUE. Claude Code sends a boolean; this file also
// documents Codex and a hand-invoked hook as supported callers, and `"true"`,
// `1` and `"1"` all read as "the agent was already made to continue once".
// Reading those as FALSE turns the one-continuation contract into a deadlock
// on every host that does not send a JSON boolean.
$flag = $payload['stop_hook_active'] ?? FALSE;
$stopHookActive = $flag === TRUE
  || $flag === 1
  || (is_string($flag) && in_array(strtolower($flag), ['true', '1', 'yes'], TRUE));
$GLOBALS['workflow_guard_continued'] = $stopHookActive;

// THE TOOL'S NAME, recorded and never acted on here. Every other branch in
// this file infers what is happening from `tool_input` — a `command` key means
// a shell, a `file_path` means a write — because that is what a REFUSAL can
// safely rest on: a host that renames its tools does not get to walk through a
// wall. Recording is the opposite problem. "Did the agent open a browser and
// look at what it built" has no answer in `tool_input`; `browser_navigate`
// carries a `url`, and so does half of everything else.
//
// So the name goes in the diary and nowhere else. Nothing refuses on it, the
// engine reads it as a count, and a host whose browser tool is called
// something this does not recognise produces an honest zero rather than a
// false permit.
$toolName = $payload['tool_name'] ?? $payload['tool'] ?? NULL;
$GLOBALS['workflow_guard_tool'] = is_string($toolName) && $toolName !== ''
  ? substr($toolName, 0, 120)
  : NULL;
// The shell command, kept for the diary: which `drush generate` an ALLOWED
// command ran is evidence droost's grounding_check reads (below, at the row).
$guardInput = is_array($payload['tool_input'] ?? NULL) ? $payload['tool_input'] : [];
$GLOBALS['workflow_guard_command'] = is_string($guardInput['command'] ?? NULL) ? $guardInput['command'] : '';

// RECORD MODE ENDS HERE, and ending here is the whole design (F-37).
//
// The diary's other two modes are walls that happen to keep a diary. This one
// is a diary and nothing else: it exists because Claude Code fires a hook for
// the tools its MATCHER names, and the two wall registrations name
// `Edit|Write|MultiEdit|NotebookEdit` and `Bash`. An MCP call matches neither,
// so for three phases of a live run the guard recorded 94 rows across exactly
// three tool names and not one `mcp__*` — while the agent was driving a real
// browser. `browser_review` counted zero, blocked `test`, and could never have
// been satisfied.
//
// The fix is NOT a wider wall. Widening a refusal matcher to `.*` would run
// the file-path and shell checks over every third-party tool's arguments, and
// an enforcement surface whose behaviour depends on the shape of somebody
// else's tool input is one nobody can reason about. So: a third registration,
// matched on `mcp__.*`, that returns before any branch that can refuse.
//
// The shutdown function is already registered above and writes the row however
// this script leaves, so `return` here is a complete recording.
if ($mode === 'record') {
  return;
}

// A NUL byte is never part of a real path or a real command — no filesystem
// this runs on accepts one — but it IS what truncates a C string, so
// `.claude/hooks/droost-workflow-guard.php\0.txt` is one name to this guard and
// a different one to whatever acts on it. Refusing the byte is the whole
// answer, and it has to happen before any check touches the value.
$carriesNul = static function (mixed $value) use (&$carriesNul): bool {
  if (is_string($value)) {
    return str_contains($value, "\0");
  }
  if (is_array($value)) {
    foreach ($value as $key => $item) {
      if ((is_string($key) && str_contains($key, "\0")) || $carriesNul($item)) {
        return TRUE;
      }
    }
  }

  return FALSE;
};
// The DECODED payload, not just the raw text: a host sends the byte as the six
// characters `\\u0000`, so scanning stdin finds nothing while the value that
// reaches `preg_match()` carries a real NUL. Both are checked, because a host
// that sends the raw byte exists too.
if (str_contains($stdin, "\0") || $carriesNul($payload)) {
  // Under `stop` on a second attempt there is nothing for the agent to do —
  // it named no path, and the refusal's own advice ("write it without the
  // byte") applies to a tool call it did not make. Refusing for ever there is
  // a hang, so the one-continuation contract holds here as everywhere else.
  if ($mode === 'stop' && $stopHookActive) {
    exit(0);
  }
  guard_refuse('nul-byte',
    'A NUL byte appeared in this tool call. No path or command legitimately '
    . 'carries one, and it is the classic way to make a guard read one name '
    . 'while the system acts on another — so this is refused without being '
    . 'interpreted. If you meant an ordinary path, write it without the byte.');
}

if ($mode === 'operator-commands') {
  // A shell is a file editor. `baseline_dir_guard()` refuses the store and the
  // baseline for Edit|Write|MultiEdit|NotebookEdit, and Bash is wired to THIS
  // branch — which only ever looked at drush command names and returned. So the
  // identical write went through: the Write tool was refused and
  // `sqlite3 evidence.sqlite "UPDATE check_result SET state='satisfied'"` was
  // not. Every claim resting on "droost wrote these rows and the agent could
  // not" was false for as long as an agent had a shell.
  protected_path_shell_guard($stdin, $root, $stateDir);
  // Run state is irrelevant to the two tiers above: bypass is granted
  // precisely when there is no run, and a waiver during one. The rule is about
  // WHO, not WHEN.
  operator_commands_guard($stdin);
  // THE PHASE WALLS APPLY TO A SHELL TOO, and they did not. `.claude/
  // settings.json` routes Bash here, this branch exited without ever reading
  // the run, and so during PLAN — the phase whose whole job is "write the
  // spec, do not start building yet" — `Write modules/custom/x.module` was
  // refused while `echo x > modules/custom/x.module` was permitted. The agent
  // that obeyed the refusal was the only one held to it.
  //
  // Read here rather than at the top, so the two tiers above keep costing
  // nothing on a project with no run.
  $shellDocument = [];
  $shellState = $root . '/' . $stateDir . '/run.json';
  if (is_file($shellState)) {
    $decoded = json_decode((string) @file_get_contents($shellState), TRUE);
    $shellDocument = is_array($decoded) ? $decoded : [];
  }
  $shellInput = is_array($payload['tool_input'] ?? NULL) ? $payload['tool_input'] : [];
  $shellCommand = $shellInput['command'] ?? '';
  if (is_string($shellCommand) && $shellCommand !== '') {
    $shellPhase = $shellDocument['current_phase'] ?? NULL;
    $shellPhases = is_array($shellDocument['phases'] ?? NULL) ? $shellDocument['phases'] : [];
    $shellStatus = is_string($shellPhases[$shellPhase] ?? NULL) ? $shellPhases[$shellPhase] : '';
    // A finished or failed phase is not a live plan phase; the pre-tool-use
    // branch makes the same two exceptions and they have to agree.
    if (is_string($shellPhase) && $shellStatus !== 'failed' && $shellStatus !== 'passed') {
      shell_phase_guard(
        operator_commands_scan_text($shellCommand),
        $root,
        $stateDir,
        $shellDocument,
        $shellPhase,
        is_string($shellDocument['enforcement'] ?? NULL) ? $shellDocument['enforcement'] : 'off',
      );
    }
  }
  exit(0);
}

if ($mode === 'pre-tool-use') {
  // Run or no run, the adoption baseline is never the agent's to edit.
  baseline_dir_guard($stdin, $root, $stateDir);
}

$stateFile = $root . '/' . $stateDir . '/run.json';
if (!is_file($stateFile)) {
  // No active run. The pipeline is silent about ordinary conversation — but
  // there is one moment governance gets skipped entirely: a code edit with no
  // run at all, the agent quietly building outside the pipeline. require_run
  // guards exactly that, and ONLY that (pre-tool-use, custom code paths).
  require_run_guard($root, $mode, $stdin, $stateDir);
  exit(0);
}
$document = json_decode((string) file_get_contents($stateFile), TRUE);
if (!is_array($document)) {
  // Unreadable is not a licence — and until now it was, for the one mode that
  // matters. This branch calls `require_run_guard()`, which returns immediately
  // unless the mode is pre-tool-use, so under `stop` it was a bare permit:
  // corrupting run.json stood the wall down silently and permanently, which is
  // exactly what the previous version of this comment claimed to have closed.
  //
  // A file that EXISTS and cannot be parsed is not "no run". It is a run whose
  // record is damaged, and a turn does not end on one.
  if ($mode === 'stop' && !$stopHookActive) {
    guard_refuse('run-record-unreadable', sprintf(
      'The run record at %s/run.json cannot be read. That is not the same as '
      . 'having no run: something wrote junk into it, or it was truncated '
      . 'mid-write. Nothing can say which phase this run is in or what it has '
      . 'passed, so the turn does not end here. An operator can archive it with '
      . '`droost-workflow reset --force`, which keeps it rather than discarding '
      . 'it.',
      $stateDir,
    ));
  }
  require_run_guard($root, $mode, $stdin, $stateDir);
  exit(0);
}

$phase = $document['current_phase'] ?? NULL;
if (!is_string($phase) || $phase === '') {
  // A run with no current phase has ended; the record is history, not law —
  // and history does not stand the wall down. The finished ticket's run.json
  // sits here until reset, which must not leave the NEXT ticket ungoverned.
  require_run_guard($root, $mode, $stdin, $stateDir);
  exit(0);
}
$phases = is_array($document['phases'] ?? NULL) ? $document['phases'] : [];
$phaseStatus = is_string($phases[$phase] ?? NULL) ? $phases[$phase] : '';
if ($phase === 'complete' && $phaseStatus === 'passed') {
  require_run_guard($root, $mode, $stdin, $stateDir);
  exit(0);
}
if ($phaseStatus === 'failed') {
  // A failed run is a legitimate outcome, already recorded. Holding the
  // agent hostage to a phase it cannot pass would punish the honesty — but
  // an ended run does not license ungoverned building either.
  require_run_guard($root, $mode, $stdin, $stateDir);
  exit(0);
}

$enforcement = $document['enforcement'] ?? 'off';
if ($enforcement !== 'hard' && $enforcement !== 'soft') {
  exit(0);
}

// THE RUN'S OWN MODE, which is a different promise from the enforcement level
// and until F-29 was quietly outranked by it.
//
// `mode: agentic` is documented in the lever file as "run plan through complete
// without stopping". `enforcement` is documented as "how hard the harness hooks
// hold the phase discipline". Read separately those are compatible; read
// together, `agentic` + `soft` meant the agent announced it was continuing and
// the turn ended anyway, because only `hard` held the stop.
//
// Measured over rounds P2-KCH-2 and P2-KCH-3: three stalls at phase boundaries,
// every one needing an operator to type the command the agent had just said it
// was running. One of them sat idle for thirty minutes having built nothing.
// An unattended agentic run that halts at every boundary is not being forced,
// it is being asked.
//
// So agentic raises the floor for the STOP hook alone. `enforcement: off` still
// silences everything — that lever is an explicit "stay out of the way", and it
// has already returned above. Tool-use enforcement is untouched: this is about
// ending a turn mid-phase, not about what may be written during one.
$runMode = $document['mode'] ?? '';
$runMode = is_string($runMode) ? $runMode : '';

/**
 * Emits a soft nudge, at most once per phase per mode.
 */
$warnOnce = static function (string $message, string $rule) use ($root, $stateDir, $mode, $phase): void {
  // EVERY HIT IS COUNTED, the silent ones too. The message goes out once per
  // phase; the call it lets through is a soft wall doing its job each time,
  // and a record that counted only the first would say an agent pushed on a
  // wall once when it pushed on it twelve times.
  $GLOBALS['workflow_guard_verdict'] = 'nudge';
  $GLOBALS['workflow_guard_rule'] = $rule;
  $marker = $root . '/' . $stateDir . '/.guard-warned-' . $mode . '-' . $phase;
  if (is_file($marker)) {
    return;
  }
  // Encode BEFORE burning the marker, and never emit nothing.
  //
  // json_encode returns FALSE on malformed UTF-8, and this message carries a
  // gate summary built from the tool's own bytes — so one stray 0xC3 out of
  // phpstan produced an empty echo. The marker had already been touched, so the
  // nudge was gone for that phase permanently: the operator was told nothing,
  // twice, and soft mode's entire purpose is the message.
  $json = json_encode(['systemMessage' => $message], JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === FALSE) {
    $clean = preg_replace('/[^\x20-\x7E]/', '', $message);
    $json = json_encode(['systemMessage' => is_string($clean) ? $clean : 'droost-workflow: a check is unresolved.']);
  }
  if ($json === FALSE) {
    return;
  }
  @touch($marker);
  echo $json;
};

if ($mode === 'pre-tool-use') {
  if ($phase !== 'plan') {
    exit(0);
  }
  // The spec is plan's own artefact: writes into the run's directory (and
  // edits to the lever file itself, which are a reviewable choice) pass.
  $input = is_array($payload['tool_input'] ?? NULL) ? $payload['tool_input'] : [];
  $file = $input['file_path'] ?? ($input['notebook_path'] ?? '');
  $file = is_string($file) ? $file : '';
  // CONTAINS was the test, and `..` walks straight back out of what it
  // contains: `droost/droost-workflow/../../modules/custom/evil.php` holds the
  // state directory's name and lands in custom code, so the plan-phase block —
  // the one that says "write the spec, do not start building yet" — exempted
  // the exact edit it exists to refuse. So did any path anywhere on the disk
  // with those two segments in it.
  //
  // The question is where the write LANDS, which is a different question from
  // what the string spells, and `resolved_relative()` already answers it.
  $inState = plan_exempts($file, $root, $stateDir, $document);
  if ($inState) {
    exit(0);
  }
  $message = 'droost:workflow:continue: the active run is still in PLAN — write the spec '
    . 'under ' . $stateDir . '/ and advance the run (/droost:workflow:continue) before '
    . 'editing project files.';
  if ($enforcement === 'hard') {
    guard_refuse('plan-wall', $message);
  }
  $warnOnce($message . ' (enforcement is soft: proceeding.)', 'plan-wall:soft');
  exit(0);
}

if ($mode === 'stop') {
  if ($stopHookActive) {
    // The guard already spoke this turn and Claude continued once because
    // of it. Blocking again would deadlock a run the agent cannot advance;
    // one enforced continuation per stop attempt is the contract.
    exit(0);
  }
  // Until the evidence store existed this could say only that a phase was open
  // — it read the phase name, its status and the enforcement level, and nothing
  // else. "A run is active" is equally true of a phase whose work is finished
  // and one that has not started, so the agent got the same sentence either way
  // and had to work out which it was in.
  //
  // Now it asks what is actually unresolved, and says so: the items, the fault
  // each carries, and for an environment fault the remedy. "phpunit is blocked"
  // and "phpunit is blocked because this root has no phpunit.xml, which your
  // operator writes with this command" are different messages, and a live round
  // wedged for an hour on the difference.
  $blocking = unresolved_checks($root, $stateDir, $document['run_id'] ?? NULL, $phase);
  $message = sprintf(
    'droost-work: a run is active in phase "%s" — advance it or abandon it '
    . '(/droost:workflow:continue) rather than ending the turn mid-phase.',
    $phase,
  );
  if ($blocking !== []) {
    $message .= sprintf(
      ' %d check(s) are unresolved, and the phase cannot end until they are: %s',
      count($blocking),
      implode('; ', array_map(static function (array $row): string {
        $line = $row['name'];
        if ($row['fault'] !== 'none' && $row['fault'] !== '') {
          $line .= ' [' . $row['fault'] . ']';
        }
        if ($row['summary'] !== '') {
          $line .= ' — ' . $row['summary'];
        }
        if ($row['fault'] === 'environment' && $row['remedy'] !== '') {
          $line .= ' — the OPERATOR clears this with: ' . $row['remedy'];
        }

        return $line;
      }, $blocking)),
    );
  }
  // Either lever can hold the boundary, and the message names the one that
  // did — an operator reading "enforcement is soft" while the stop is blocked
  // would reasonably think the guard was broken.
  if ($enforcement === 'hard') {
    guard_refuse('stop-hold', $message);
  }
  if ($runMode === 'agentic') {
    guard_refuse('stop-hold:agentic', $message . ' (enforcement is soft, but mode is agentic,'
      . ' which is a promise to run plan through complete without stopping:'
      . ' holding the boundary. Set mode: interactive to converse between'
      . ' phases, or enforcement: off to silence the harness entirely.)');
  }
  $warnOnce($message . ' (enforcement is soft and mode is ' . ($runMode !== '' ? $runMode : 'unset') . ': allowing the stop.)', 'stop-hold:soft');
  exit(0);
}

exit(0);

/**
 * Refuses the call, and says why in the guard's own ledger.
 *
 * THE ONLY exit(2) IN THIS FILE. Every refusal leaves through here, so every
 * refusal is a row in `guard-calls.jsonl` that names its rule. There used to
 * be twenty-seven exits, and two of them set a verdict first. The other
 * twenty-five, among them the plan-phase wall that `enforcement: hard` exists
 * for, recorded "invoked", and a run at `hard` could not show a single thing
 * the wall had stopped. `GuardVerdictLedgerTest` fails when a second exit(2)
 * appears anywhere, so a new refusal cannot skip the ledger by accident.
 *
 * @param string $rule
 *   The wall that refused, as an evaluator would count it:
 *   `plan-wall`, `stop-hold`, `protected-path:editor`, `require-run`, ...
 * @param string $message
 *   The reason, for the agent to act on.
 */
function guard_refuse(string $rule, string $message): never {
  $GLOBALS['workflow_guard_verdict'] = 'refuse';
  $GLOBALS['workflow_guard_rule'] = $rule;
  fwrite(STDERR, $message);
  exit(2);
}

/**
 * The part of a Bash command the operator-command patterns may read.
 *
 * A heredoc body fed to something that is not an interpreter — `cat > file`,
 * `gh pr create --body-file -`, `git commit -F -`, `tee` — is text the agent is
 * writing FOR a human, and quoting the operator's command there is exactly
 * what this guard's own refusal asks it to do ("show the operator the exact
 * command"). A live run was refused for putting `drush
 * droost:workflow:gate-waive …` in a pull-request body (F-ADOPT-11). Such
 * bodies are dropped before matching. A heredoc piped into a shell or a
 * language runtime (`bash <<EOF`, `ddev exec … <<EOF`, `drush php:script -
 * <<EOF`) is still code and stays in the scan, as does everything outside
 * heredocs.
 *
 * @param string $command
 *   The command as the agent typed it.
 *
 * @return string
 *   The command with data-only heredoc bodies removed.
 */
function operator_commands_scan_text(string $command): string {
  // ANY wrapper in front, not just `sudo` and `env`. This was a FOURTH copy
  // of the list, and `nohup bash <<EOF`, `timeout 9 bash <<EOF` and
  // `sudo -u me bash <<EOF` all classified their bodies as DATA — so the
  // heredoc was dropped and the verb inside it was never seen, while the bare
  // `bash <<EOF` was refused.
  $interpreter = '/(?:^|[|;&(`]|\$\()\s*'
    . '(?:(?:' . operator_commands_wrapper_words() . ')\s+(?:-\S+\s+|\S+=\S*\s+|\d+(?:\.\d+)?[smhd]?\s+)*)*'
    . '(?:' . operator_commands_interpreter_words() . '|tmux|ssh|docker'
    . '|ddev\s+(?:exec|ssh)|lando\s+(?:ssh|exec)|fin\s+(?:exec|ssh)|drush\s+(?:php:?\S*|ev|scr))(?:\s|$)/';
  $lines = preg_split('/\R/', $command) ?: [];
  $kept = [];
  $count = count($lines);
  for ($i = 0; $i < $count; $i++) {
    $line = $lines[$i];
    $kept[] = $line;
    if (preg_match('/<<-?\s*([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\1/', $line, $m) !== 1
      || (preg_match($interpreter, $line) === 1 && !operator_commands_feeds_code($line))) {
      continue;
    }
    // A data heredoc, or one fed to php, python, perl, node or ruby: skip to
    // its terminator, keeping the terminator line. A CODE body is not shell,
    // and tokenising it as shell is what refused P6 run 6's `python3 -
    // <<'EOF'` edit: Python's `'''` flipped the quote parity and a PHP line
    // like `  $values = [];` read as a command whose program was a variable
    // (F-78). Its verbs and its writes to the enforcement are checked as code,
    // by `operator_commands_code_heredocs()`, as `python3 -c` code already is.
    $tag = $m[2];
    for ($i++; $i < $count; $i++) {
      if (trim($lines[$i]) === $tag) {
        $kept[] = $lines[$i];
        break;
      }
    }
  }
  return implode("\n", $kept);
}

/**
 * Whether a heredoc line feeds its body to an interpreter that is not a shell.
 *
 * @param string $line
 *   The line carrying the `<<`.
 *
 * @return bool
 *   TRUE when the command the heredoc belongs to is php, python, perl, node
 *   or ruby, or drush's PHP runners, once the wrappers and container runners
 *   in front of it are set aside, so the body is code in another language.
 *   Anything else, a shell or `ssh` or `docker` included, is FALSE, and its
 *   body is read as it always was.
 *
 *   The old reading asked the whole LINE for an interpreter's name, so
 *   `ddev drush php:script - <<'PHP'` matched none, and its body was dropped
 *   as data: a PHP write to the run record through it was never looked at.
 */
function operator_commands_feeds_code(string $line): bool {
  $at = strpos($line, '<<');
  if ($at === FALSE) {
    return FALSE;
  }
  // The command the heredoc belongs to: what follows the last separator.
  $parts = preg_split('/&&|\|\||[;|&(`]|\$\(/', substr($line, 0, $at)) ?: [];
  $words = preg_split('/\s+/', trim((string) end($parts))) ?: [];
  // Past the wrappers, the assignments and the container runners that hand
  // the command on unchanged: `sudo`, `FOO=1`, `ddev exec`, `ddev drush`.
  $skip = '/^(?:' . operator_commands_wrapper_words() . '|ddev|lando|fin|exec|-\S*|\S+=\S*|\d+(?:\.\d+)?[smhd]?)$/';
  while ($words !== [] && preg_match($skip, $words[0]) === 1) {
    array_shift($words);
  }
  $head = strtolower(basename($words[0] ?? ''));
  if (preg_match('/^(?:php|python[0-9.]*|perl|node|ruby)$/', $head) === 1) {
    return TRUE;
  }

  return $head === 'drush' && preg_match('/^(?:php:?\S*|ev|scr)$/', $words[1] ?? '') === 1;
}

/**
 * The bodies of heredocs fed to an interpreter that is not a shell.
 *
 * `operator_commands_scan_text()` leaves them out of what is read as shell,
 * so they are read here as what they are: code, whose operator verbs and
 * writes to the enforcement are checked as text, exactly as `python3 -c` code
 * is.
 *
 * @param string $command
 *   The command as the agent sent it.
 *
 * @return list<string>
 *   Each body, its lines joined.
 */
function operator_commands_code_heredocs(string $command): array {
  $lines = preg_split('/\R/', $command) ?: [];
  $bodies = [];
  $count = count($lines);
  for ($i = 0; $i < $count; $i++) {
    if (preg_match('/<<-?\s*([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\1/', $lines[$i], $m) !== 1
      || !operator_commands_feeds_code($lines[$i])) {
      continue;
    }
    $body = [];
    for ($i++; $i < $count && trim($lines[$i]) !== $m[2]; $i++) {
      $body[] = $lines[$i];
    }
    $bodies[] = implode("\n", $body);
  }

  return $bodies;
}

/**
 * Refuses the operator's commands when the agent's shell issues them.
 *
 * `droost:workflow:gate-waive`, `droost:workflow:bypass` and
 * `droost:workflow:effort <level>` exist so a human can loosen the pipeline
 * deliberately — a waiver or bypass with a recorded reason, or the effort
 * dial moved in a reviewable line. They have no MCP surface, but an agent
 * running with permissions bypassed has a shell, and Drush is a shell command
 * — so the harness is where the agent's hand has to be stopped. Recognises
 * the full command names and the Drush aliases (dwfgw, dwfby, dwfe);
 * `bypass --off` re-arms the wall, and a bare `effort` or an `effort <level>
 * --preview` only reports, so those are always allowed. The adoption baseline
 * (`droost:workflow:baseline`, `droost-workflow baseline`) is the operator's
 * too — a baseline the agent can write is a finding it can hide — while its
 * `--status` and `--measure` only read and are anyone's to ask. Exit 2 with
 * the reason on stderr; the agent is told to ask the operator.
 *
 * @param string $stdin
 *   The hook payload, read once by the caller.
 */
function operator_commands_guard(string $stdin): void {
  $payload = json_decode($stdin, TRUE);
  $input = is_array($payload) && is_array($payload['tool_input'] ?? NULL) ? $payload['tool_input'] : [];
  $command = is_string($input['command'] ?? NULL) ? $input['command'] : '';
  if ($command === '') {
    return;
  }
  $command = operator_commands_scan_text($command);
  // A droost command must NAME its verb. `drush $(echo droost:workflow:byp)ass`
  // ran the bypass and matched nothing here, because `(` and `)` end a token
  // and the verb arrived in three pieces — the shell joins them back together
  // after this hook has already answered.
  //
  // Substitution is refused only where it can hide an operator verb: inside an
  // invocation of drush or droost-workflow. `cd $(git rev-parse
  // --show-toplevel)`
  // and `git commit -m "$(cat msg)"` are ordinary work and stay ordinary. That
  // narrowness is the point — a guard that refused every `$(` would be switched
  // off within a day, and a guard switched off enforces nothing.
  if (preg_match(
    '/(?:^|[;&|(]|\s)(?:\S*\/)?(?:drush|droost-workflow)\b[^;&|\n]*(?:\$\(|`|\$\{)/',
    operator_commands_unquoted($command),
  ) === 1) {
    guard_refuse('operator-command:substitution',
      'This command builds a droost command out of a substitution, so what it '
      . 'actually runs cannot be read here — and the operator-only verbs '
      . '(bypass, gate-waive, baseline, effort, arming a write gate) are '
      . 'exactly what that hides. Write the drush command out in full. If you '
      . 'genuinely need a computed argument, compute it into a variable on its '
      . 'own line first; it is the VERB that has to be legible.');
  }
  // Per INVOCATION, from TOKENS. Asking the raw line three different questions
  // let each answer come from a different command: `bypass "hotfix"; echo
  // --off` read its exemption out of the `echo`, `"droost:workflow:byp"ass`
  // defeated every verb because quoting splits a word to a regex and joins it
  // to the shell, and `droost:gate allow_entity_write "on"` armed a write gate
  // because `"on"` is not `on`.
  foreach (with_find_exec_commands(operator_commands_invocations($command)) as $tokens) {
    // A MULTI-WORD token is a quoted argument — a commit message, a PR body, a
    // sentence being echoed — and a verb inside one is prose, not an
    // invocation. `operator_commands_invocations()` has already recursed into
    // the ones that belong to a command runner and replaced them with the
    // command they carry, so anything multi-word still here is text.
    //
    // Without this, `git commit -m "ran drush droost:workflow:bypass for the
    // hotfix"` was refused, and so was `echo "ask the operator to run drush
    // droost:workflow:gate-waive phpcs"` — which is the guard's OWN refusal
    // message being followed. A previous round fixed this once for heredoc
    // bodies (F-ADOPT-11, a pull-request body quoting a waiver); the same
    // mistake came back through a different door.
    $line = implode(' ', array_filter(
      $tokens,
      static fn (string $token): bool => preg_match('/\s/', $token) !== 1,
    ));
    if (operator_commands_opaque_head($tokens)) {
      guard_refuse('operator-command:variable-program',
        'This command runs whatever a variable happens to hold, so what it '
        . 'actually does cannot be read here — and the operator-only verbs '
        . '(bypass, gate-waive, baseline, effort, arming a write gate) are '
        . 'exactly what that hides. Write the program name out: `bash -c "…"`, '
        . '`drush …`, `vendor/bin/phpunit …`. A variable is fine in an '
        . 'ARGUMENT; it is the program that has to be legible.');
    }
    // A SEARCHER CARRIES THE VERB AS A PATTERN, IT DOES NOT RUN IT. This tier
    // matched the operator verbs anywhere on the line and never looked at the
    // head, so `grep -rn droost:workflow:bypass web/modules`, `rg
    // droost:workflow:gate-waive` and `git log --grep=…bypass` were all
    // refused — and searching the codebase or its history for the verb is
    // exactly how an agent grounds a proposal to the operator. Only a droost
    // surface RUNS one; a searcher names it as the string to look for. Judged
    // per invocation, so `grep x; drush …bypass` still catches the second
    // half — its head is `drush`, not `grep`.
    $verbHead = operator_commands_unwrapped($tokens);
    $searchHead = strtolower(basename(ltrim($verbHead[0] ?? '', "\x01")));
    $searchSub = strtolower(ltrim($verbHead[1] ?? '', "\x01"));
    if (in_array($searchHead, ['grep', 'egrep', 'fgrep', 'rg', 'ripgrep', 'ag', 'ack'], TRUE)
      || ($searchHead === 'git' && in_array($searchSub, ['log', 'grep', 'show', 'diff', 'blame'], TRUE))) {
      continue;
    }
    // ASKING HOW A VERB WORKS IS NOT RUNNING IT (F-79). drush prints a
    // command's help and runs nothing when given `--help`, and droost-workflow
    // prints the verb's usage. P6 run 6's agent read `gate-waive --help` before
    // proposing a waiver and was refused as though it had waived. Only a
    // help-ONLY invocation passes: `--help` beside any other argument is still
    // the verb, and so is anything that is not a droost workflow verb.
    if (operator_commands_help_only($verbHead)) {
      continue;
    }
    $which = NULL;
    // BOTH SPELLINGS, for all three. These matched the drush verb only,
    // and the standalone binary grew the same three commands on 2026-09-15 —
    // so `droost-workflow bypass "x"` would have granted the operator's
    // bypass from the agent's own shell, past a wall that refuses the drush
    // spelling of the identical act. A verb is the same act whichever surface
    // spells it.
    if (preg_match('/droost:workflow:gate-waive\b|(?<![\w-])dwfgw\b'
      . '|droost-workflow\s+gate-waive\b/', $line) === 1) {
      $which = 'gate-waive';
    }
    elseif (preg_match('/(?:droost:workflow:baseline|(?<![\w-])dwfbl|droost-workflow\s+baseline)\b/', $line) === 1
      && !operator_commands_flagged($tokens, ['--status', '--measure'])) {
      // Writing or refreshing the baseline decides what counts as inherited
      // debt for every later run. The bill (--measure) and the record
      // (--status) are read-only and exactly how an agent grounds a proposal
      // to baseline; the write is the operator's.
      $which = 'baseline';
    }
    elseif (preg_match('/droost:workflow:bypass\b|(?<![\w-])dwfby\b'
      . '|droost-workflow\s+bypass\b/', $line) === 1
      && !operator_commands_flagged($tokens, ['--off'])) {
      $which = 'bypass';
    }
    elseif (preg_match('/(?:droost:workflow:effort|(?<![\w-])dwfe'
      . '|droost-workflow\s+effort)\b/', $line) === 1
      && !operator_commands_flagged($tokens, ['--preview'])
      && array_intersect($tokens, ['custom', 'low', 'medium', 'high', 'xhigh', 'max', 'factory', 'light']) !== []) {
      // Moving the dial is the operator's act whichever way it goes — down is
      // a loosening, and either way it is a lever change the file records.
      // Only a command that NAMES a level and would WRITE is refused: a bare
      // `effort` reports the current level, and `effort <level> --preview`
      // shows the bill without writing — both are anyone's to ask, and the
      // preview is exactly how an agent should ground a level it proposes.
      $which = 'effort';
    }
    elseif (operator_commands_arms_write_gate($tokens)
      || operator_commands_php_arms_write_gate($tokens)) {
      // ARMING a write gate is the operator's act too (round 25, R25-F2: the
      // subject asked for allow_entity_write rather than arming it — this makes
      // asking the only path). Disarming is a tightening and is not matched.
      $which = 'gate (arming a write gate)';
    }
    if ($which === NULL) {
      continue;
    }
    $gate = str_starts_with($which, 'gate (');
    // Name it back in the spelling that was USED. Every one of these verbs
    // now exists on two surfaces, and telling an operator to run the drush
    // command on a project with no Drupal is advice they cannot take.
    $cli = !$gate && preg_match('/droost-workflow\s+' . preg_quote($which, '/') . '\b/', $line) === 1;
    $name = match (TRUE) {
      $gate => 'droost:gate',
      $cli => 'droost-workflow ' . $which,
      default => 'droost:workflow:' . $which,
    };
    // The rule tag is the verb, which is what an evaluator wants to see
    // counted.
    //
    // The RUNNABLE command, which is not the same string as the verb's name.
    // A drush verb is not a command without `drush` in front of it, and the
    // hand-over line is the whole point of this refusal: printing
    // `! droost:workflow:gate-waive …` gives the operator something their
    // shell does not have. The standalone binary IS the command, so it stands
    // alone. Caught by GuardTest, which pins the hand-over's exact shape.
    $handover = $cli ? $name : 'drush ' . $name;
    guard_refuse('operator-command:' . $which, sprintf(
      '%1$s is the OPERATOR\'s command — an agent may propose it, never run it. '
      . 'Show the operator the exact command with your reason and ask them to '
      . 'run it in THEIR terminal (in Claude Code: `! %2$s …`), then '
      . 'continue once they say it is done. The record must carry a human\'s '
      . 'decision, not yours.%3$s',
      $name,
      $handover,
      $gate ? ' (Disarming a gate — `off` — needs no operator; only arming does.)' : '',
    ));
  }
}

/**
 * The command with the contents of every quoted span blanked out.
 *
 * A substitution inside quotes is still a substitution to the shell, but the
 * distinction that matters here is a different one: text inside quotes is
 * usually being PASSED to something (a commit message, a PR body, an echo)
 * rather than being the command's own verb. `git commit -m "ran drush
 * droost:workflow:bypass for $(date)"` is a sentence about a command; `drush
 * $(echo droost:workflow:byp)ass` is the command. Blanking the quoted spans
 * tells them apart without a list of which tools take a message argument.
 *
 * A single-word check would be simpler and wrong in both directions; this one
 * is wrong only where an agent quotes a real droost invocation's verb, which
 * the tokeniser catches separately because a runner's string is re-scanned.
 *
 * @param string $command
 *   The command line.
 *
 * @return string
 *   The same text, with quoted contents replaced by spaces so offsets and word
 *   boundaries are preserved.
 */
function operator_commands_unquoted(string $command): string {
  $out = '';
  $quote = '';
  $length = strlen($command);
  for ($i = 0; $i < $length; $i++) {
    $char = $command[$i];
    if ($quote !== '') {
      if ($char === '\\' && $quote === '"' && $i + 1 < $length) {
        $out .= '  ';
        $i++;
        continue;
      }
      if ($char === $quote) {
        $quote = '';
      }
      $out .= ' ';
      continue;
    }
    if ($char === '\'' || $char === '"') {
      $quote = $char;
      $out .= ' ';
      continue;
    }
    $out .= $char;
  }

  return $out;
}

/**
 * Whether a command's program name is a variable this guard cannot expand.
 *
 * `$SHELL -c "…"`, `S=/bin/sh; $S -c "…"` and `C="drush …bypass"; $C` all put
 * an unexpanded variable where the program name goes. The guard has no shell
 * and no environment, so it cannot know what runs — and a command it cannot
 * read is not a command it may permit. A reviewer used exactly this to run the
 * operator's bypass and to overwrite this file with the word PWNED, both under
 * a verdict of ALLOW, with no pre-step and against every rule at once.
 *
 * ONLY THE PROGRAM NAME. A variable in an argument is ordinary and stays
 * ordinary: `cp "$f" /tmp`, `cd "$ROOT"`, `git commit -m "$MSG"` are untouched.
 *
 * @param list<string> $tokens
 *   One invocation's tokens.
 *
 * @return bool
 *   TRUE when the head is an unexpanded variable.
 */
function operator_commands_opaque_head(array $tokens): bool {
  // The quote MARKER and literal quotes both, because the two callers used to
  // strip different sets and were one function's worth of drift apart.
  $head = ltrim(operator_commands_unwrapped($tokens)[0] ?? '', "\x01\"'({");

  // `${RUNNER} -c …` arrives as one token since braces inside a word stopped
  // being treated as grouping; `$RUNNER`, `$(which sh)` and a bare `$` all
  // begin the same way. Any head beginning with `$` is a program name this
  // guard cannot read, which is the whole test.
  // A BACKTICK IS THE SAME SHELL FEATURE. Round five closed `$VAR -c` and
  // `$(which sh) -c`; `` `echo sh` -c "…" `` is the older spelling of the
  // second and was permitted — the tokeniser treats a backtick as an ordinary
  // character, so `` `echo `` and `` sh` `` arrive as two plain words and the
  // quoted payload is dropped as prose. A reviewer used it to overwrite this
  // file and take `stop` from 2 to 0.
  return str_starts_with($head, '$') || str_contains($head, '`');
}

/**
 * Whether an argument list arms a droost write gate.
 *
 * Both spellings: `droost:gate allow_x on` and `config:set droost.settings
 * allow_x true`. Read from tokens, because the value arrived quoted — `"on"` —
 * and a raw-string match let a write gate be armed. Flags may sit anywhere;
 * only the ORDER of the gate name and its value matters.
 *
 * @param list<string> $tokens
 *   The argument list.
 *
 * @return bool
 *   TRUE when this command arms one.
 */
function operator_commands_arms_write_gate(array $tokens): bool {
  $arming = ['on', 'true', '1', 'yes', 'arm', 'armed'];
  $words = array_values(array_filter(
    $tokens,
    static fn (string $token): bool => !str_starts_with($token, '-'),
  ));
  $verb = NULL;
  foreach ($words as $index => $word) {
    $lower = strtolower($word);
    if ($lower === 'droost:gate' || $lower === 'dgate') {
      $verb = $index;
      break;
    }
    if (in_array($lower, ['config:set', 'config-set', 'cset'], TRUE)) {
      // The settings object has to be named for this to be droost's gate.
      $next = strtolower($words[$index + 1] ?? '');
      if ($next === 'droost.settings') {
        $verb = $index + 1;
        break;
      }
      // The DOTTED spelling is the same command. `drush cset
      // droost.settings.allow_entity_write true` names the key in one token,
      // which read as neither the object nor the flag, and armed a write gate
      // with the guard watching. Drush accepts both forms; so does this.
      if (preg_match('/^droost\.settings\.(allow_\w+)$/i', $next) === 1
        && in_array(strtolower($words[$index + 2] ?? ''), $arming, TRUE)) {
        return TRUE;
      }
    }
  }
  if ($verb === NULL) {
    return FALSE;
  }
  $flag = $words[$verb + 1] ?? '';
  $value = $words[$verb + 2] ?? '';

  return preg_match('/^allow_\w+$/i', $flag) === 1
    && in_array(strtolower($value), $arming, TRUE);
}

/**
 * Whether a line of PHP arms a droost write gate.
 *
 * `drush php:eval` is a runner, so the tokeniser already hands the PHP through
 * as its own invocation — and then every matcher looked for `droost:gate` or
 * `config:set` and found neither, because this spelling has no drush verb at
 * all:
 *
 *     drush php:eval "\Drupal::configFactory()
 *       ->getEditable('droost.settings')
 *       ->set('allow_entity_write', TRUE)->save();"
 *
 * Same config object, same key, same effect, and it was permitted. The shape to
 * match is not a verb but the three things that have to be present for the
 * write to mean anything: droost's settings, an allow_* key, and a truthy.
 *
 * @param list<string> $tokens
 *   The invocation's tokens.
 *
 * @return bool
 *   TRUE when the invocation writes a droost allow_* switch on.
 */
function operator_commands_php_arms_write_gate(array $tokens): bool {
  // Flattened from the tokens rather than matched against PHP source, because
  // by the time this sees it there is no PHP source left. The tokeniser splits
  // on grouping punctuation and marks `>` as a redirect, so
  // `->set('allow_entity_write', TRUE)` arrives as `-`, `\x01set`,
  // `allow_entity_write,`, `TRUE`. A pattern written against the code an agent
  // typed matches none of that, which is why the first cut of this permitted
  // the write it was added to refuse.
  $flat = strtolower(implode(' ', array_map(
    static fn (string $token): string => ltrim($token, "\x01"),
    $tokens,
  )));
  // `$settings['droost']['allow_entity_write'] = TRUE;` is the arming that
  // actually takes effect — GateState reads exactly that shape. The config
  // spelling below is kept because an operator may reasonably TRY it, and
  // refusing a no-op costs nothing; this is the one that works.
  if (preg_match('/settings.{0,4}droost|droost.{0,4}settings|droost_workflow/', $flat) !== 1) {
    return FALSE;
  }
  if (preg_match('/allow_\w+/', $flat) !== 1) {
    return FALSE;
  }
  // Something that WRITES, and something truthy. Reading the switch is not
  // arming it — `\Drupal::config('droost.settings')->get('allow_entity_write')`
  // is a perfectly good thing for an agent to ask, and asking is the behaviour
  // this whole rule is trying to produce.
  return (preg_match('/(?<![a-z_])(set|setmultiple|save|seteditable|setsetting)(?![a-z_])/', $flat) === 1
    || str_contains($flat, '='))
    && preg_match('/(?<![a-z_])(true|1|on)(?![a-z_0-9])/', $flat) === 1;
}

/**
 * The programs that run whatever follows them.
 *
 * ONE LIST, because there were two and they drifted — and the drift was a
 * complete bypass rather than an inconsistency. `builtin`, `chronic` and `ts`
 * had been added to the stripper used by the PATH tier and not to the one that
 * decides whether a quoted argument is re-scanned as a command line, so:
 *
 *     builtin eval "drush droost:workflow:bypass x"                  ALLOWED
 *     builtin eval "echo '<?php exit(0);' > .claude/hooks/guard.php" ALLOWED
 *     builtin eval "rm -rf droost/droost-workflow"                   ALLOWED
 *
 * while the identical commands without the two-word prefix were all refused.
 * `builtin eval` is a real construct in both bash and zsh. A reviewer found it
 * by diffing the two lists — which is the only way anyone would have.
 *
 * The lesson is in the shape, not the words: a rule stated twice is a rule
 * that will eventually be true once.
 *
 * @return string
 *   A regex matching one wrapper word.
 */
function operator_commands_interpreter_words(): string {
  // ONE LIST, for the same reason the wrappers became one: there were FOUR
  // and every gap between them was a bypass. The heredoc classifier had its
  // own at `operator_commands_scan_text()`, the tokeniser's `$runner` had
  // another, the pipe rule had a third, and the script-file read rode on the
  // second. Each round of fixes taught one of them a new word.
  //
  // `source` and `.` are here because they run a file in the current shell —
  // `source do.sh` was a bypass while `bash do.sh` was refused. `xargs` is
  // here because `| xargs sh -c` hands over a command line.
  return 'sh|bash|zsh|dash|ksh|fish|eval|source|\.|php|python3?|perl|node|ruby'
    . '|expect|xargs|script';
}

/**
 * The programs that run whatever follows them.
 *
 * @return string
 *   A regex matching one wrapper word.
 */
function operator_commands_wrapper_pattern(): string {
  return '/^(?:' . operator_commands_wrapper_words() . ')$/';
}

/**
 * The wrapper words, as a bare alternation.
 *
 * Separate from the anchored pattern so the heredoc classifier and the pipe
 * rule can embed the same words rather than keeping copies — which is how
 * `nohup bash <<EOF` and `| env sh` came to be permitted while `bash <<EOF`
 * and `| sh` were refused.
 *
 * @return string
 *   The alternation, without delimiters or anchors.
 */
function operator_commands_wrapper_words(): string {
  return 'sudo|command|builtin|eval|exec|env|nice|time|setsid|stdbuf|ionice'
    . '|caffeinate|arch|unbuffer|doas|busybox|nohup|timeout|watch|flock|parallel'
    . '|su|script|chronic|ts';
}

/**
 * One invocation with its leading wrappers removed, and how many came off.
 *
 * ONE STRIPPER. There were two, and they disagreed about a wrapper's own
 * ARGUMENT: this one ate `timeout`'s `5`, and the copy inside
 * `operator_commands_invocations()` ate only flags. So the tokeniser's `$head`
 * became the literal `5`, `$runs` was FALSE, the quoted payload was never
 * re-scanned, and the verb tier then dropped it as prose:
 *
 *     ddev exec "drush droost:workflow:bypass x"              refused
 *     timeout 5 ddev exec "drush droost:workflow:bypass x"    ALLOWED
 *     timeout 5 ddev exec "echo x > .claude/hooks/…guard.php" ALLOWED
 *
 * One extra word took the operator-verb wall, the protected-path wall and the
 * state directory together. The list had been unified one round earlier and
 * the RULE had not, which is the same defect the unification was written to
 * end — so it is one function now, and both callers ask it.
 *
 * `timeout` also takes a unit suffix (`5s`, `5m`, `5h`, `5d`) on GNU and BSD,
 * and a bare `/^\d+$/` matched none of them: `timeout 5s rm -rf
 * droost/droost-workflow` walked past the destructive tier that `timeout 5`
 * did not.
 *
 * @param list<string> $tokens
 *   The argument list.
 *
 * @return array{0: list<string>, 1: int}
 *   The remaining tokens, and how many wrappers were removed.
 */
function operator_commands_strip_wrappers(array $tokens): array {
  $wrappers = operator_commands_wrapper_pattern();
  $stripped = 0;
  for ($strip = 0; $strip < 8 && $tokens !== []; $strip++) {
    // A SHELL RESERVED WORD IS A PREFIX TOO (F-118). `for i in 1; do php -r
    // '…'; done` splits at the semicolons into `do php -r '…'`, whose head
    // is `do`, and every rule that asks what the command IS read the
    // keyword. The interpreter rule never saw the `php`: code that emptied
    // the guard or deleted the run record ran at `hard` inside any loop or
    // `if`, where the same line on its own is refused. Not counted as a
    // wrapper: a keyword does not run a string it is handed.
    if (in_array(ltrim($tokens[0], "\x01"), ['do', 'then', 'else', 'elif', 'if', 'while', 'until', '!'], TRUE)) {
      array_shift($tokens);
      continue;
    }

    // A LEADING ASSIGNMENT IS A PREFIX TOO. `FOO=bar bash -c "…"` is `bash -c
    // "…"` with one variable set for it, and that is how the shell runs it —
    // but the head here was the literal `FOO=bar`, which is not a wrapper word
    // and not an interpreter, so `$runs` was FALSE, the quoted payload was
    // never re-scanned, and both walls went blind on one token. `env FOO=bar
    // bash -c …` was refused because `env` IS a wrapper; dropping the word
    // `env` was the whole bypass. Proven end to end: the guard overwritten,
    // run.json removed, `stop` from exit 2 to exit 0.
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', ltrim($tokens[0], "\x01")) === 1) {
      array_shift($tokens);
      $stripped++;
      continue;
    }
    $word = strtolower(basename(ltrim($tokens[0], "\x01")));
    if (preg_match($wrappers, $word) !== 1) {
      break;
    }
    array_shift($tokens);
    $stripped++;
    // The wrapper's own flags and its argument: `nice -n 10`, `timeout 5`,
    // `timeout 5s`, `timeout 1.5m`, `sudo -u me`.
    while ($tokens !== [] && (
      str_starts_with($tokens[0], '-')
      || preg_match('/^\d+(?:\.\d+)?[smhd]?$/i', $tokens[0]) === 1
    )) {
      array_shift($tokens);
    }
  }

  return [array_values($tokens), $stripped];
}

/**
 * An argument list with its leading wrappers removed.
 *
 * `nice`, `time`, `timeout 5`, `sudo`, and the shell's own `builtin`, `command`
 * and `eval` all run what follows them, and every decision this file makes was
 * reading `$tokens[0]`. So `builtin cd .claude/hooks && echo … > guard.php`
 * left the tracked directory pinned at the project root while the real shell
 * had moved — every relative operand then resolved somewhere harmless, the
 * guard overwrote itself, and `stop` went from exit 2 to exit 0. `nice rm -rf
 * droost/droost-workflow` walked past the destructive-verb test the same way.
 *
 * @param list<string> $tokens
 *   The argument list.
 *
 * @return list<string>
 *   The list with wrappers and their own flags removed.
 */
function operator_commands_unwrapped(array $tokens): array {
  [$tokens] = operator_commands_strip_wrappers($tokens);

  return array_values($tokens);
}

/**
 * The project root the host named, or '' when it named none it can use.
 *
 * IS IT A DIRECTORY. Taking the value verbatim meant a stale worktree path, a
 * typo or a deleted directory in that variable pointed the guard at nothing:
 * it found no run.json, reported no active run, and permitted every stop —
 * silently, permanently, with no message — while `bin/droost-workflow` from
 * the same shell rejected the same value, walked up, and kept advancing the
 * real run. `ArgvDispatcher` was hardened for this and the guard was not, so
 * the fix on one side BECAME the divergence it was written to close.
 *
 * A function, because the tokeniser needs the same answer to tell an
 * absolute path INSIDE the project (the agent's own script, spelled long)
 * from a system binary — and the top-level code and a helper agreeing on
 * what "the project" is has to be one rule.
 *
 * @return string
 *   The directory Claude Code named, or ''.
 */
function guard_named_root(): string {
  $host = getenv('CLAUDE_PROJECT_DIR');

  return is_string($host) && $host !== '' && is_dir($host) ? $host : '';
}

/**
 * Every command on the line, as the argument list the shell would build.
 *
 * One tokeniser, because three ad-hoc scanners over the raw string each missed
 * a different thing and each fix reopened another's hole:
 *
 *   * `droost:gate allow_entity_write "on"` armed a write gate — the value was
 *     matched as a raw word and `"on"` is not `on`;
 *   * `drush "droost:workflow:byp"ass` defeated every verb, because quoting
 *     splits a word to a regex and JOINS it to the shell;
 *   * `bypass "hotfix"; echo --off` read the `--off` from a different command
 *     entirely and treated it as this one's exemption.
 *
 * All three are the same mistake: reading a command line as text. A token is
 * what a verb, a flag and a path all really are, so everything downstream asks
 * about tokens.
 *
 * Quotes are REMOVED and what they surround stays attached to its neighbours,
 * which is exactly the shell's rule and the one a placeholder-based approach
 * kept getting backwards. `>` and `<` separate words but not commands, so a
 * redirection target is an ordinary operand. A token that itself contains a
 * command line — `ddev exec "drush …"`, `bash -c '…'` — is recursed into, so a
 * containerised invocation is judged as the invocation it is.
 *
 * @param string $command
 *   The command, heredoc bodies already dropped.
 * @param int $depth
 *   Recursion guard.
 *
 * @return list<list<string>>
 *   One argument list per command.
 */
function operator_commands_invocations(string $command, int $depth = 0): array {
  $invocations = [];
  $tokens = [];
  $current = '';
  $started = FALSE;
  $quote = '';
  $length = strlen($command);

  $redirect = FALSE;
  $endToken = static function () use (&$tokens, &$current, &$started, &$redirect): void {
    if ($started) {
      // A redirection TARGET carries a marker, so a caller can tell "this
      // command's arguments" from "the file it is writing to". Stripped by
      // anything that treats it as a path.
      $tokens[] = ($redirect ? "\x01" : '') . $current;
      $redirect = FALSE;
    }
    $current = '';
    $started = FALSE;
  };
  $endCommand = static function () use (&$invocations, &$tokens, &$redirect, $endToken): void {
    $endToken();
    // A CLOSING keyword takes only redirections after it, and an output
    // target carries the redirection mark, so an unmarked word after `done`,
    // `fi` or `esac` is an INPUT redirection's file: `while read l; do …;
    // done < ledger` reads it. Its head was `done`, which no rule knows as a
    // reader, so the read was refused as a write. Kept: the marked targets,
    // so `done > run.json` is judged as the write it is.
    if (in_array(ltrim($tokens[0] ?? '', "\x01"), ['done', 'fi', 'esac'], TRUE)) {
      $tokens = array_values(array_filter(array_slice($tokens, 1), static fn (string $token): bool => str_starts_with($token, "\x01")));
    }
    if ($tokens !== []) {
      $invocations[] = $tokens;
    }
    $tokens = [];
    // A redirection never outlives its command. The mark used to survive a
    // `&`, `|` or `;` that ended a command before its target began, which
    // is how the `1` in `2>&1` became a file. Every spelling that puts an
    // operator between `>` and its file, `>&file` and `>|`, is read as one
    // redirection below, so nothing is lost by ending the mark here.
    $redirect = FALSE;
  };

  for ($i = 0; $i < $length; $i++) {
    $char = $command[$i];
    if ($quote !== '') {
      if ($char === '\\' && $quote === '"' && $i + 1 < $length) {
        $current .= $command[++$i];
        continue;
      }
      if ($char === $quote) {
        $quote = '';
        continue;
      }
      $current .= $char;
      continue;
    }
    if ($char === '\'' || $char === '"') {
      // A quote starts a token even when what it surrounds is empty, so `""`
      // is an argument rather than nothing.
      $quote = $char;
      $started = TRUE;
      continue;
    }
    if ($char === '\\' && $i + 1 < $length) {
      $current .= $command[++$i];
      $started = TRUE;
      continue;
    }
    if ($char === '#' && !$started) {
      // A comment, to the end of its LINE. The next line is code again.
      $i += strcspn($command, "\r\n", $i) - 1;
      continue;
    }
    if ($char === ';' || $char === "\n" || $char === "\r") {
      $endCommand();
      continue;
    }
    if (($char === '&' || $char === '|') && $i + 1 < $length && $command[$i + 1] === $char) {
      $endCommand();
      $i++;
      continue;
    }
    if ($char === '&' || $char === '|') {
      $endCommand();
      continue;
    }
    if ($char === '{' || $char === '}') {
      // A BRACE INSIDE A WORD IS EXPANSION, not grouping. Splitting it off
      // turned `…guard.ph{p,x}` into two tokens that no path regex matches,
      // which is half of why brace expansion reached the guard file. A brace
      // that STARTS a word is still shell grouping — `{ cd x; rm y; }` — and
      // is still separated.
      // Shell GROUPING is `{ cmd; }` — the brace is followed by whitespace.
      // `{a,b}/x` is expansion, and splitting it left `rm
      // {.claude/hooks,foo}/droost-workflow-guard.php` as tokens no path
      // regex could match.
      $next = $command[$i + 1] ?? ' ';
      if ($current === '' && $char === '{' && preg_match('/\s/', $next) === 1) {
        $endToken();
        continue;
      }
      if ($current === '' && $char === '}') {
        $endToken();
        continue;
      }
      $current .= $char;
      $started = TRUE;
      continue;
    }
    if ($char === '(' && str_ends_with($current, '$')) {
      // A SUBSTITUTION IS ONE WORD. `$(` … `)` is a VALUE, and splitting it
      // on the parentheses handed `A=$(echo x) rm -rf droost/droost-workflow`
      // to the strippers as `A=$`, `echo`, `x`, `rm`, …: the assignment strip
      // took `A=$`, `echo` became the head, the destructive tier's
      // `^`-anchored regex never saw `rm`, and the state directory was
      // deleted. `A=$(echo x) find . -delete` behind the same prefix emptied
      // the project. The rule that refuses a substitution INSIDE a droost
      // command reads the raw line, not these tokens, and is unaffected.
      // Nesting and quotes inside are honoured; an unterminated one runs to
      // the end, which is a line the shell itself would refuse.
      $parens = 0;
      $quoted = '';
      for (; $i < $length; $i++) {
        $ch = $command[$i];
        $current .= $ch;
        if ($quoted !== '') {
          if ($ch === $quoted) {
            $quoted = '';
          }
          continue;
        }
        if ($ch === '\'' || $ch === '"') {
          $quoted = $ch;
        }
        elseif ($ch === '(') {
          $parens++;
        }
        elseif ($ch === ')' && --$parens === 0) {
          break;
        }
      }
      $started = TRUE;
      continue;
    }
    if ($char === '(' || $char === ')') {
      // Grouping punctuation is not part of a word: `(cd x && …)` has `cd` as
      // the head of its first command, and treating `(cd` as one token meant
      // the subshell's `cd` was never seen.
      $endToken();
      continue;
    }
    if ($char === '>' || $char === '<') {
      // A redirection separates words, not commands: its target is an operand
      // and has to be seen as one. But WHICH operand matters — `cat x >
      // .claude/settings.json` has `cat` at the front, and a read-verb check
      // that did not know about the `>` let it write. The target is marked, so
      // the caller can tell a command's arguments from what it is writing to.
      //
      // The digits right before it are the descriptor it redirects, as the
      // shell reads them, not an argument: `cmd 2>&1` runs `cmd` with no `2`.
      if ($started && $quote === '' && $current !== '' && ctype_digit($current)) {
        $current = '';
        $started = FALSE;
      }
      $endToken();
      // A DESCRIPTOR IS NOT A FILE. `2>&1`, `>&2`, `3>&-` and `<&3` copy or
      // close a file descriptor and write nothing. The `&` used to end the
      // command while the redirect mark survived it, so in `2>&1` the `1`
      // became a file this command writes. At `hard` the shell's plan wall
      // refuses such a write, and it refused the first command of the first
      // run to reach it (P6 run 6): `ddev drush … 2>&1 | tail`.
      if (($command[$i + 1] ?? '') === '&') {
        $end = $i + 2;
        while ($end < $length && (ctype_digit($command[$end]) || $command[$end] === '-')) {
          $end++;
        }
        $descriptor = substr($command, $i + 2, $end - $i - 2);
        $bounded = $end >= $length || preg_match('/[\s;|&()<>]/', $command[$end]) === 1;
        if ($bounded && preg_match('/^(\d+-?|-)$/', $descriptor) === 1) {
          $i = $end - 1;
          continue;
        }
        // `>&word` sends both streams to the FILE `word`: the `&` joins the
        // redirection and ends nothing.
        $redirect = $char === '>';
        $i++;
        continue;
      }
      if ($char === '>') {
        $redirect = TRUE;
        // `>|` writes past noclobber. The `|` is part of the redirection,
        // not a pipe.
        if (($command[$i + 1] ?? '') === '|') {
          $i++;
        }
      }
      continue;
    }
    if (preg_match('/\s/', $char) === 1) {
      $endToken();
      continue;
    }
    $current .= $char;
    $started = TRUE;
  }
  $endCommand();

  if ($depth >= 3) {
    return $invocations;
  }

  // A COMMAND SUBSTITUTION RUNS. `$( … )` is kept as one word, which is right
  // for what it produces, a value, and it hid what it does: the shell runs the
  // command inside first. `X=$(echo pwned > .claude/hooks/droost-workflow-
  // guard.php)` and `echo "$(rm droost/droost-workflow/run.json)"` were both
  // ALLOWED, because nothing looked inside, except by accident: a pipe into an
  // interpreter anywhere on the line turned every multi-word argument into a
  // command line (`$piped` below). That accident is also how a curl format
  // string, `-w "$p %{http_code}"`, came to be refused as a program named `$p`
  // (P6 run 10, F-114). So each substitution's command is tokenised as the
  // command it is and judged by every rule, and `$piped` reads only the pipes
  // outside substitutions.
  $substituted = [];
  foreach (operator_commands_substitution_spans($command)[0] as $inner) {
    foreach (operator_commands_invocations($inner, $depth + 1) as $one) {
      $substituted[] = $one;
    }
  }

  // PIPED INTO AN INTERPRETER, the whole pipeline is a program. `echo "drush
  // droost:workflow:bypass x" | sh` put the verb in a quoted argument to
  // `echo`, which is not a runner, so it was dropped as prose — and `sh` on the
  // other side of the pipe had no argument for anything to look at. The
  // unquoted spelling was already caught; the quoted one ran.
  // WRAPPERS HERE TOO. This was a third copy of the list and had neither
  // `env` nor `command`, so `echo "drush …bypass" | env sh` was permitted
  // while `| sh` was refused — and `env` is the canonical way to pipe into
  // a shell with a modified environment.
  $piped = preg_match(
    '/\|\s*(?:(?:' . operator_commands_wrapper_words() . ')\s+(?:-\S+\s+|\S+=\S*\s+|\d+[smhd]?\s+)*)*'
    . '(?:\S*\/)?(?:' . operator_commands_interpreter_words() . ')\b/',
    operator_commands_without_substitutions($command),
  ) === 1;

  // A token carrying a whole command line is one: `ddev exec "drush …"`.
  //
  // Gated on the COMMAND being a runner, not on the token looking like a
  // command. "Contains a verb" recursed into ordinary prose and refused it:
  //
  //     git commit -m "ran drush droost:workflow:bypass for the hotfix"
  //     echo "ask the operator to run drush droost:workflow:gate-waive phpcs"
  //
  // The second is the guard's OWN refusal message being followed — it tells the
  // agent to show the operator the exact command — and a previous round already
  // fixed this once, for heredoc bodies (F-ADOPT-11, a pull-request body
  // quoting a waiver). Quoting a command to a human is not running it, and only
  // something that will EXECUTE its argument makes it a command line again.
  // PREFIX WRAPPERS. `$head` is token 0, so anything in front of the runner hid
  // it: `nice bash -c …`, `time bash -c …`, `watch -n1 …`, `flock … -c …`,
  // `setsid`, `stdbuf`, `su -c`, `git -c alias.z='!drush …' z`. Growing the
  // allowlist loses that race, so the wrappers are STRIPPED first and whatever
  // is left is judged.
  // The wrappers are stripped first, so the runner test never sees one — the
  // `sudo|command|env` alternation the old pattern carried was unreachable
  // past that strip and is gone with it.
  $runner = '/^(?:\/\S+\/)?(?:' . operator_commands_interpreter_words() . ')$/';
  $verbs = operator_verb_pattern();
  $resolved = [];
  foreach ($invocations as $tokens) {
    // The subcommand forms — `ddev exec …`, `lando ssh …`, `docker exec …` —
    // plus anything that runs a string it was handed.
    // Strip leading wrappers and their own flags before asking what this is.
    [$bare, $stripped] = operator_commands_strip_wrappers($tokens);
    // A wrapper's whole job is to run what follows, so when stripping one
    // leaves a single multi-word token, that token IS the command line —
    // `watch -n1 'drush …'` and `flock /tmp/l -c '…'` hand over a string
    // exactly as `bash -c` does, without being named `bash`.
    $wrapped = $stripped > 0
      && ($bare[0] ?? '') !== ''
      && preg_match('/\s/', $bare[0]) === 1;
    // `flock /tmp/lock -c '…'` and `su -c '…' me` put the string after their
    // own `-c`, past an operand of their own, so the head is not it. Only the
    // token immediately after a `-c` counts — scanning for any multi-word token
    // would read `nice git commit -m "…"`'s MESSAGE as a command line.
    if ($stripped > 0 && !$wrapped) {
      foreach ($tokens as $index => $word) {
        if ($word === '-c' && preg_match('/\s/', $tokens[$index + 1] ?? '') === 1) {
          $wrapped = TRUE;
          break;
        }
      }
    }
    $head = strtolower($bare[0] ?? '');
    $second = strtolower($bare[1] ?? '');
    // A VARIABLE IS A SHELL. `$runs` asked whether the head literally spelled
    // an interpreter, so `$SHELL -c "…"`, `S=/bin/sh; $S -c "…"` and
    // `C="drush …bypass"; $C` were not runners, their quoted argument was
    // dropped as prose, and BOTH walls went blind at once — a reviewer used it
    // to run the operator's bypass and to overwrite this file with the word
    // PWNED, under a verdict of ALLOW. It needed no pre-step and it defeated
    // every rule this mode has.
    //
    // The guard cannot expand a variable, and that is the point: an unexpanded
    // head is a command it CANNOT READ, and the honest answer to a command it
    // cannot read is not "allow". Refusing here is narrow — a variable in an
    // ARGUMENT (`cp "$f" /tmp`, `cd "$ROOT"`) is untouched, and only a variable
    // standing where the program name goes is refused.
    // ONE PREDICATE. This was a second copy of
    // `operator_commands_opaque_head()`
    // with a different strip set — this one took literal quotes and not the
    // tokeniser's quote marker, that one the reverse — and no test could tell
    // the two apart: remove the backtick clause from EITHER alone and the suite
    // stayed green, because the other copy still answered. A rule stated twice
    // is a rule that will eventually be true once.
    $opaque = operator_commands_opaque_head($bare);
    // A SCRIPT RUN DIRECTLY IS STILL A SCRIPT. The read tier was gated on the
    // head being an interpreter, so `bash do.sh` was read and `./do.sh` was
    // not — and `./do.sh` is the more idiomatic spelling of exactly the
    // attack that tier was built for: one Write, then one allowed Bash call.
    // `source do.sh` and `. do.sh` ran it in the current shell and were not
    // read either.
    //
    // RELATIVE, OR ABSOLUTE INSIDE THE PROJECT. `\.{0,2}/` permitted zero
    // dots, so ANY absolute path was "the agent's own script": `/usr/bin/git
    // status` was refused because git is larger than 64KB, `/bin/ls` with it,
    // and `/opt/homebrew/bin/php -v` because the binary is a symlink — a
    // refusal nobody can act on, over commands that touch nothing. A system
    // binary is not a script the agent wrote a moment ago. An absolute path
    // that lands INSIDE the project still is, because `/abs/project/do.sh`
    // is `./do.sh` spelled longer.
    $headRaw = ltrim($bare[0] ?? '', "\x01");
    $project = guard_named_root() !== '' ? guard_named_root() : (getcwd() ?: '');
    $selfExecuting = preg_match('#^\\.{1,2}/|^[^/]+\\.(?:sh|bash|zsh|php|py|pl|rb)$#', $head) === 1
      || (str_starts_with($headRaw, '/') && $project !== '' && resolved_relative($headRaw, $project) !== '');
    // A SCRIPT'S ARGUMENTS ARE DATA; a `-c` STRING IS A COMMAND LINE. These
    // are two different questions and were one answer. `bash -c "…"`, `eval
    // "…"` and `ddev exec "…"` are handed a command line and run it, so their
    // quoted argument must be re-scanned. `./deploy.sh "some message"` is
    // handed an ARGUMENT — the script decides what it means, and it is
    // usually text. Treating every `./x.sh` as a runner re-scanned its
    // arguments as shell, so any project script taking a message was judged
    // on the message's contents: telling the eval harness's subject
    //
    //   ./dogfood.sh say "the change to .claude/hooks/…guard.php is mine"
    //
    // was refused as an attempt to rewrite the guard. It is a sentence. The
    // script itself is still READ below, which is the tier that catches a
    // verb hidden in a file — that protection is untouched.
    $takesCommandString = $wrapped
      || $opaque
      || preg_match($runner, $head) === 1
      || ($head === 'ssh')
      || (in_array($head, ['ddev', 'lando', 'fin', 'docker', 'docker-compose', 'podman'], TRUE)
        && in_array($second, ['exec', 'ssh', 'run'], TRUE))
      || ($head === 'drush' && str_starts_with($second, 'php'));
    $runs = $takesCommandString || $selfExecuting;
    // AN INTERPRETER GIVEN CODE IS GIVEN DATA, not more scripts. `perl -pi -e
    // '<code>' FILE` edits FILE; `python3 -c '<code>' data.json` reads it.
    // The read tier below treated every non-flag argument as a script the
    // command RUNS, so it opened the input file and judged its contents —
    // and a source file that merely MENTIONS an operator verb (in a string,
    // in a comment, in a remedy message) made editing it impossible. Hit
    // while editing the gate whose remedy names `droost:workflow:baseline`.
    // When the invocation carries inline code, the code is the program and
    // the files are its subject.
    $inlineCodeHeld = operator_commands_inline_code($bare) !== NULL;
    // A SCRIPT FILE IS A COMMAND LINE THE GUARD CAN READ. `bash script.sh`
    // hid the operator's verb in a file, and the guard judged the two words in
    // front of it. It is a file on disk, inside the project, written moments
    // ago by the agent — so read it, and judge what it says.
    //
    // Only the agent's own scripts: anything under `vendor/` or
    // `node_modules/` is a tool's source, not a command line, and reading
    // PHP as a shell script invents refusals out of string literals.
    $inners = [];
    if ($runs && !$inlineCodeHeld && $depth < 2) {
      // The HEAD too, for `./do.sh` — there the script is the program, not an
      // argument to one.
      foreach ($selfExecuting ? $bare : array_slice($bare, 1) as $argument) {
        $file = ltrim($argument, "\x01");
        if ($file === '' || str_starts_with($file, '-') || preg_match('/\s/', $file) === 1) {
          continue;
        }
        if (preg_match('#(^|/)(vendor|node_modules)/#', $file) === 1) {
          continue;
        }
        $path = str_starts_with($file, '/') ? $file : (getcwd() ?: '.') . '/' . $file;
        // BEFORE `is_file()`, which follows links and answers FALSE for one
        // that dangles — so a broken link fell through this `continue` as
        // "not a file" and was never refused as unreadable.
        $dangling = is_link($path) && realpath($path) === FALSE;
        if (!$dangling && !is_file($path)) {
          continue;
        }
        // A SCRIPT THIS GUARD CANNOT READ IS ONE IT MAY NOT PERMIT. These
        // four skips were silent `continue`s, and each was a working bypass
        // one Write away: a 72KB script with the verb on the last line, a
        // symlinked script, a script under vendor/, and a script that only
        // `source`s another. The file's own doctrine already says the answer
        // — "a command it cannot read is not a command it may permit" — and
        // this tier was the one place not following it.
        //
        // A LINK IS FOLLOWED, NOT REFUSED. `bin/drush -> ../vendor/drush/…` is
        // the standard composer bin-dir layout, and refusing `./bin/drush cr`
        // as "a symlink" was a false positive with no way to comply. The
        // bypass the symlink refusal closed was `link.sh -> do.sh`, and the
        // honest answer to that is to read do.sh — so the link is resolved
        // and its TARGET judged: under vendor/ or node_modules/ it is a tool
        // and skipped as one; a readable file anywhere else is read; a link
        // that resolves to nothing is still a script this cannot read.
        $unreadable = '';
        if (is_link($path)) {
          $real = realpath($path);
          if ($real === FALSE) {
            $unreadable = 'a symlink to nothing this guard can open';
          }
          elseif (preg_match('#(^|/)(vendor|node_modules)/#', $real) === 1) {
            continue;
          }
          else {
            $path = $real;
          }
        }
        if ($unreadable === '' && (int) @filesize($path) > 65536) {
          $unreadable = 'larger than 64KB';
        }
        if ($unreadable !== '') {
          guard_refuse('operator-command:script-unreadable', sprintf(
            'This runs a script droost cannot read — %s is %s. What it does '
            . 'cannot be judged from here, and the operator-only verbs and '
            . 'this guard\'s own file are exactly what that hides. Run the '
            . 'commands directly, or keep the script under 64KB and pointing '
            . 'at a real file so it can be read. (Refused: %s)',
            $file,
            $unreadable,
            trim($command),
          ));
        }
        $script = (string) @file_get_contents($path, FALSE, NULL, 0, 65536);
        if ($script === '' || str_contains($script, "\0")) {
          continue;
        }
        // SHELL IS TOKENISED AS SHELL. EVERY OTHER LANGUAGE IS READ AS TEXT.
        //
        // This tier exists to catch an operator verb hidden in a file —
        // `bash do.sh` where do.sh holds `drush droost:workflow:bypass`. It
        // did that by tokenising the file AS A SHELL COMMAND LINE, whatever
        // language it was in, and the tokens that come out of reading Python
        // or PHP that way are nonsense that the path and glob rules then
        // judge. Measured inside a live run: a subject decoding a string
        // wrote `'=' * (-len(s) % 4)`, and the `*` — Python's multiplication
        // operator — was read as a shell wildcard "expanding onto" the lever
        // file. Moving the code into a `.py` did not help, because the guard
        // then read the .py the same way. The same defect refused
        // `bin/droost-workflow init`, a PHP CLI, and so refused the command
        // that reinstalls this guard.
        //
        // A non-shell file is scanned for the operator verbs instead, which
        // is the whole reason to open it. Its syntax is not shell and is not
        // pretended to be.
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $isShell = in_array($extension, ['sh', 'bash', 'zsh', 'ksh'], TRUE)
          || preg_match('#^\#!\S*/(?:env\s+)?(?:ba|z|k|da)?sh\b#', $script) === 1;
        if (!$isShell) {
          if (preg_match($verbs, $script) === 1) {
            guard_refuse('operator-command:in-script', sprintf(
              'This runs %s, and that file contains one of the operator-only '
              . 'verbs. Putting the command in a file does not make it the '
              . 'agent\'s to run — show the OPERATOR the command and the '
              . 'reason, and let them run it. (Refused: %s)',
              $file,
              trim($command),
            ));
          }
          continue;
        }
        foreach (operator_commands_invocations($script, $depth + 1) as $line) {
          $inners[] = $line;
        }
        break;
      }
    }
    // CODE IS NOT A COMMAND LINE, AND RE-SCANNING IT AS ONE ERASES IT.
    // `php -r '<code>'` is a runner by `$runs`, so the code token — any code
    // with a space in it — was REPLACED by whatever tokenising it as shell
    // produced, and the inline-code rule below never saw the argument it
    // exists to read. `php -r 'file_put_contents(".claude/hooks/droost-
    // workflow-guard.php", "");'` emptied this guard, while the identical
    // payload without that one space was refused — the rule was alive only
    // for the spelling nobody uses. The inner scan still runs, because an
    // operator verb inside the code is worth catching; the token is KEPT as
    // well, so the code can also be read as code.
    $kept = [];
    foreach ($tokens as $token) {
      // WHATEVER is in it. This also required the token to carry an operator
      // VERB, so every protected-path write inside a runner was invisible —
      // `bash -c 'echo bad > .claude/hooks/droost-workflow-guard.php'` kept the
      // whole thing as one multi-word token that no path check can match, and
      // the guard overwrote itself. `$runs` is the gate; what the runner was
      // handed is a command line either way.
      if (($takesCommandString || $piped) && preg_match('/\s/', $token) === 1) {
        // The nested line REPLACES the argument that carried it. Keeping both
        // meant the outer invocation still held the verb — as one long token,
        // where its `--off` is not an argument — so `ddev exec "drush …
        // --off"` was refused by the outer while the inner would have allowed
        // it. The command that runs is the inner one; the outer is `ddev exec`.
        // INTERPRETER CODE IS NOT RE-SCANNED AS SHELL. Tokenising Python or
        // PHP as a command line produces tokens no shell would ever make, and
        // the path rules then judge them: `python3 -c "json.load(open(
        // 'droost/droost-workflow/run.json'))"` — a subject READING its own
        // run record — came out as an operand naming the record and was
        // refused as rewriting the referee. The code is judged as code
        // instead, by the inline-code rules in the path tier, exactly as a
        // `.py` file is judged as Python rather than as shell.
        if ($inlineCodeHeld) {
          $kept[] = $token;
          continue;
        }
        foreach (operator_commands_invocations($token, $depth + 1) as $inner) {
          $inners[] = $inner;
        }
        continue;
      }
      $kept[] = $token;
    }
    if ($kept !== []) {
      $resolved[] = $kept;
    }
    foreach ($inners as $inner) {
      $resolved[] = $inner;
    }
  }
  foreach ($substituted as $one) {
    $resolved[] = $one;
  }

  return $resolved;
}

/**
 * Every command substitution in a command line, and where each one sits.
 *
 * `$( … )` and backticks, where the shell expands them: bare or inside double
 * quotes, never inside single quotes. `$(( … ))` is arithmetic and is not one.
 * A substitution nested in another is found when the outer one's command is
 * tokenised in turn.
 *
 * @param string $command
 *   The command line, heredoc bodies already dropped.
 *
 * @return array{0: list<string>, 1: list<array{0: int, 1: int}>}
 *   Each substitution's command, and its span as [start, end) offsets.
 */
function operator_commands_substitution_spans(string $command): array {
  $inners = [];
  $spans = [];
  $length = strlen($command);
  $quote = '';
  for ($i = 0; $i < $length; $i++) {
    $char = $command[$i];
    if ($quote === '\'') {
      if ($char === '\'') {
        $quote = '';
      }
      continue;
    }
    if ($char === '\\' && $i + 1 < $length) {
      $i++;
      continue;
    }
    if ($char === '\'' && $quote === '') {
      $quote = '\'';
      continue;
    }
    if ($char === '"') {
      $quote = $quote === '"' ? '' : '"';
      continue;
    }
    if ($char === '$' && ($command[$i + 1] ?? '') === '(' && ($command[$i + 2] ?? '') !== '(') {
      $close = operator_commands_closing_paren($command, $i + 1);
      $inners[] = substr($command, $i + 2, max(0, $close - $i - 2));
      $spans[] = [$i, min($close + 1, $length)];
      $i = $close;
      continue;
    }
    if ($char === '`') {
      $close = strpos($command, '`', $i + 1);
      $close = $close === FALSE ? $length : $close;
      $inners[] = substr($command, $i + 1, max(0, $close - $i - 1));
      $spans[] = [$i, min($close + 1, $length)];
      $i = $close;
    }
  }

  return [$inners, $spans];
}

/**
 * The command line with every substitution blanked, offsets kept.
 *
 * For the questions that belong to this line's own words, such as whether it
 * pipes into an interpreter. A pipe inside `$( … )` belongs to the command in
 * the substitution, which is tokenised and judged on its own.
 *
 * @param string $command
 *   The command line.
 *
 * @return string
 *   The same text, each substitution replaced by spaces.
 */
function operator_commands_without_substitutions(string $command): string {
  foreach (array_reverse(operator_commands_substitution_spans($command)[1]) as [$start, $end]) {
    $command = substr_replace($command, str_repeat(' ', $end - $start), $start, $end - $start);
  }

  return $command;
}

/**
 * Where the `)` closing the `(` at an offset sits, as the shell reads it.
 *
 * Quotes and nested parentheses inside are honoured, so `$(python3 -c
 * 'print(")")')` closes at its last parenthesis. An unclosed one runs to the
 * end of the line.
 *
 * @param string $command
 *   The command line.
 * @param int $open
 *   The offset of the opening parenthesis.
 *
 * @return int
 *   The offset of the closing one, or the line's length.
 */
function operator_commands_closing_paren(string $command, int $open): int {
  $depth = 0;
  $quote = '';
  $length = strlen($command);
  for ($i = $open; $i < $length; $i++) {
    $char = $command[$i];
    if ($quote === '\'') {
      if ($char === '\'') {
        $quote = '';
      }
      continue;
    }
    if ($char === '\\' && $i + 1 < $length) {
      $i++;
      continue;
    }
    if ($quote === '"') {
      if ($char === '"') {
        $quote = '';
      }
      continue;
    }
    if ($char === '\'' || $char === '"') {
      $quote = $char;
      continue;
    }
    if ($char === '(') {
      $depth++;
      continue;
    }
    if ($char === ')') {
      $depth--;
      if ($depth === 0) {
        return $i;
      }
    }
  }

  return $length;
}

/**
 * The drush generators a command runs for real, in order.
 *
 * `drush generate <name>`, `drush gen <name>` and every spelling the
 * tokeniser already unwraps (`ddev drush`, `vendor/bin/drush`, `ddev exec
 * "…"`, `bash -c '…'`). A run that only looks — `--dry-run`, `--help`, `-h`,
 * or no generator named, which lists them — writes nothing and is not
 * recorded: the ledger is evidence that a file came from a generator, and a
 * dry run is not.
 *
 * @param string $command
 *   The shell command as the agent sent it.
 *
 * @return list<string>
 *   The generator names, each once.
 */
function operator_commands_generators(string $command): array {
  $names = [];
  foreach (operator_commands_invocations(operator_commands_scan_text($command)) as $tokens) {
    $words = array_values(array_map(
      static fn (string $token): string => ltrim($token, "\x01"),
      array_filter(operator_commands_unwrapped($tokens), static fn (string $token): bool => !str_starts_with($token, "\x01")),
    ));
    // The command is drush, or `ddev drush`: a drush named anywhere else on
    // the line is an argument (`echo drush generate module`).
    $at = basename($words[0] ?? '') === 'ddev' ? 1 : 0;
    if (!in_array(basename($words[$at] ?? ''), ['drush', 'drush.php'], TRUE)
      || !in_array($words[$at + 1] ?? '', ['generate', 'gen'], TRUE)) {
      continue;
    }
    $rest = array_slice($words, $at + 2);
    if (operator_commands_flagged($rest, ['--dry-run', '--help', '-h'])) {
      continue;
    }
    // The generator is the first word that is neither a flag nor the value of
    // one written apart from it (`-a my_module`). None named is the
    // listing, which writes nothing.
    $takesValue = FALSE;
    foreach ($rest as $arg) {
      if ($takesValue) {
        $takesValue = FALSE;
        continue;
      }
      if (in_array($arg, ['-a', '--answer', '-d', '--destination', '--directory'], TRUE)) {
        $takesValue = TRUE;
        continue;
      }
      if (str_starts_with($arg, '-')) {
        continue;
      }
      // `list` and `help` are the console's own commands, not generators.
      if (preg_match('/^[a-z][\w.:-]*$/', $arg) === 1 && !in_array($arg, ['list', 'help'], TRUE) && !in_array($arg, $names, TRUE)) {
        $names[] = $arg;
      }
      break;
    }
  }

  return $names;
}

/**
 * Whether an invocation only asks a droost workflow verb for its help.
 *
 * @param list<string> $plain
 *   The invocation's unwrapped tokens.
 *
 * @return bool
 *   TRUE when a `droost:workflow:*` verb, its `dwf*` alias, or a
 *   `droost-workflow` verb is followed by `--help` or `-h` and nothing else
 *   but redirection targets.
 */
function operator_commands_help_only(array $plain): bool {
  $words = array_values(array_map(static fn (string $token): string => ltrim($token, "\x01"), array_filter(
    $plain,
    static fn (string $token): bool => !str_starts_with($token, "\x01"),
  )));
  $verb = NULL;
  foreach ($words as $index => $word) {
    if (preg_match('/^(?:droost:workflow:[a-z-]+|dwf[a-z]+)$/', $word) === 1) {
      $verb = $index;
      break;
    }
    if (basename($word) === 'droost-workflow' && isset($words[$index + 1])) {
      $verb = $index + 1;
      break;
    }
  }
  if ($verb === NULL) {
    return FALSE;
  }
  $rest = array_slice($words, $verb + 1);

  return $rest === ['--help'] || $rest === ['-h'];
}

/**
 * Whether a `sed` invocation edits the files it is given in place.
 *
 * @param list<string> $tokens
 *   The invocation's tokens.
 *
 * @return bool
 *   TRUE for `-i`, `-i.bak`, a short flag cluster carrying `i` (`-ni`), or
 *   `--in-place` in any spelling.
 */
function sed_edits_in_place(array $tokens): bool {
  foreach ($tokens as $token) {
    $word = ltrim($token, "\x01");
    if (str_starts_with($word, '--in-place') || preg_match('/^-[A-Za-z]*i/', $word) === 1) {
      return TRUE;
    }
  }

  return FALSE;
}

/**
 * Whether an argument list carries a flag, as its own argument.
 *
 * @param list<string> $tokens
 *   The argument list.
 * @param list<string> $flags
 *   The flags, with their dashes.
 *
 * @return bool
 *   TRUE when one of them is present.
 */
function operator_commands_flagged(array $tokens, array $flags): bool {
  foreach ($tokens as $token) {
    foreach ($flags as $flag) {
      if ($token === $flag || str_starts_with($token, $flag . '=')) {
        return TRUE;
      }
    }
  }

  return FALSE;
}

/**
 * Whether a path is plan's own to write.
 *
 * The state directory, and the run's declared spec wherever the operator put
 * it. Asked of where the write LANDS rather than of what the string spells:
 * `droost/droost-workflow/../../modules/custom/evil.php` holds the state
 * directory's two segments and lands in custom code, and a symlink out of the
 * state directory does the same thing without the `..`.
 *
 * ONE FUNCTION, because the plan wall used to live only in `pre-tool-use` —
 * so `Write modules/custom/x.module` was refused during PLAN and
 * `Bash echo x > modules/custom/x.module` was permitted. The agent that obeyed
 * the refusal was the only one held to it, which is the worst way for a rule
 * to be wrong, and the commit that fixed the spec half of this named the shell
 * half in its own message and did not close it.
 *
 * @param string $file
 *   The path being written.
 * @param string $root
 *   The project root.
 * @param string $stateDir
 *   The resolved state directory.
 * @param array<string, mixed> $document
 *   The run record, for the spec it declares.
 *
 * @return bool
 *   TRUE when plan may write it.
 */
function plan_exempts(string $file, string $root, string $stateDir, array $document): bool {
  if ($file === '') {
    return TRUE;
  }
  $absolute = str_starts_with($file, '/') ? $file : rtrim($root, '/') . '/' . ltrim($file, '/');
  $landing = normalised_path($absolute);
  $rootPath = normalised_path(rtrim($root, '/'));
  $relative = str_starts_with($landing, $rootPath . '/')
    ? substr($landing, strlen($rootPath) + 1)
    : '';
  $resolved = resolved_relative($absolute, $root);
  $candidate = $resolved !== '' ? $resolved : $relative;
  if ($candidate === '') {
    // OUTSIDE THE PROJECT. `/tmp/scratch.txt` is not this project's code and
    // the plan wall has nothing to say about it — refusing it would stop a
    // scratch file, a log, a heredoc into /tmp, which is ordinary work at
    // every phase.
    return TRUE;
  }
  if (str_starts_with($candidate, trim($stateDir, '/') . '/')) {
    return TRUE;
  }
  // The run's declared spec, read from the RECORD rather than guessed, so it
  // is exactly the document this run is held to and not any file that looks
  // like one.
  $declared = $document['spec_path'] ?? NULL;

  return is_string($declared) && $declared !== '' && normalised_path($declared) === $candidate;
}

/**
 * Applies the phase walls to what a shell command writes.
 *
 * `.claude/settings.json` routes Bash to `operator-commands`, which ran the
 * protected-path and operator-verb tiers and exited without ever consulting
 * the phase. So during PLAN — the phase whose whole job is "write the spec,
 * do not start building yet" — a redirect into custom code was permitted
 * while the identical `Write` was refused, and the same was true of the
 * require_run wall on a project with no run at all.
 *
 * The write operands come from the same tokeniser the path tier uses, so the
 * two walls agree about what a command writes; only the question differs.
 *
 * @param string $command
 *   The already-scanned command line.
 * @param string $root
 *   The project root.
 * @param string $stateDir
 *   The resolved state directory.
 * @param array<string, mixed> $document
 *   The run record.
 * @param string $phase
 *   The current phase, or '' when no run is active.
 * @param string $enforcement
 *   The run's frozen enforcement level.
 */
function shell_phase_guard(
  string $command,
  string $root,
  string $stateDir,
  array $document,
  string $phase,
  string $enforcement,
): void {
  if ($phase !== 'plan' || $enforcement !== 'hard') {
    return;
  }
  // The shell's own idea of where it is, tracked exactly as the path tier
  // tracks it — `getcwd()` is the HOOK's directory, which is not the
  // command's, and a bare filename means something different in each.
  $cwd = rtrim($root, '/');
  foreach (operator_commands_invocations($command) as $tokens) {
    $unwrapped = operator_commands_unwrapped($tokens);
    $verb = strtolower(ltrim($unwrapped[0] ?? '', "\x01({"));
    if ($verb === 'cd' || $verb === 'pushd') {
      $target = '';
      foreach (array_slice($unwrapped, 1) as $word) {
        if ($word === '--' || str_starts_with($word, '-')) {
          continue;
        }
        $target = ltrim($word, "\x01");
        break;
      }
      $moved = $target === '' ? FALSE : realpath(
        str_starts_with($target, '/') ? $target : $cwd . '/' . $target,
      );
      $cwd = $moved === FALSE ? $root : $moved;
      continue;
    }
    foreach ($tokens as $token) {
      if (!str_starts_with($token, "\x01")) {
        continue;
      }
      $target = ltrim($token, "\x01");
      if ($target === '' || str_starts_with($target, '/dev/')) {
        continue;
      }
      $absolute = str_starts_with($target, '/') ? $target : $cwd . '/' . $target;
      if (plan_exempts($absolute, $root, $stateDir, $document)) {
        continue;
      }
      guard_refuse('plan-wall:shell', sprintf(
        'droost:workflow:continue: the active run is still in PLAN, and this '
        . 'command writes to %s. Write the spec under %s/ and advance the run '
        . '(/droost:workflow:continue) before building. A shell redirect is '
        . 'the same act as an edit; the wall does not stop at the tool you '
        . 'chose.',
        $target,
        $stateDir,
      ));
    }
  }
}

/**
 * Refuses a shell command that reaches the evidence store or the baseline.
 *
 * The file-path guard covers the editing tools. This covers the other editor
 * every agent has, and it has to be blunt: a command line can reach a file
 * through `sqlite3`, `php -r`, `python3`, a redirect, `mv`, `dd`, a heredoc or
 * a script it wrote a moment ago, and no parse of a shell string tells a read
 * from a write reliably — `sqlite3 db "select 1"; sqlite3 db "update …"` is one
 * command.
 *
 * So the rule is the path, not the verb: if a command mentions the store or the
 * baseline, it is refused, and the sanctioned surface is named. Reading the
 * store has one — `droost-workflow evidence` — and reading the baseline has
 * another — `baseline --status`. Neither needs to touch the file.
 *
 * THIS IS A SPEED BUMP, NOT A WALL, and saying so is the point. A reviewer
 * defeated the first cut in four ways within minutes: a glob, a shell variable,
 * `find -exec`, and `php -r` assembling the path from two halves. No parse of a
 * shell string can decide which file a command will open — that is undecidable,
 * not merely hard — so anything here stops the casual route and nothing more.
 *
 * The real defence is downstream and does not depend on prediction:
 * `EvidenceStore` chains every verdict's digest into the next, so a row altered
 * outside droost breaks the chain and the evaluation leads with a banner naming
 * the first broken row. Forging the record is still possible. It is no longer
 * quiet, which is the property that actually matters for an artefact whose
 * whole job is being read later.
 *
 * @param string $stdin
 *   The hook payload.
 */
function protected_path_shell_guard(string $stdin, string $root, string $stateDir): void {
  $payload = json_decode($stdin, TRUE);
  $payload = is_array($payload) ? $payload : [];
  $input = is_array($payload['tool_input'] ?? NULL) ? $payload['tool_input'] : [];
  $command = $input['command'] ?? '';
  // DATA HEREDOCS DROPPED HERE TOO. Only the verb tier called this, so a
  // heredoc body was prose to one wall and code to the other — and the
  // opaque-head check then fired on any body line starting with `$`:
  //
  //   cat > x.php <<'PHP'   with  $x = 1;
  //   gh pr create --body-file - <<'EOF'  with
  //   $ drush droost:workflow:gate-waive phpcs
  //
  // The second is F-ADOPT-11 returning through the other door: a pull-request
  // body quoting the waiver this guard's own refusal tells the agent to show
  // the operator. Writing a PHP file with a heredoc is routine here, and a
  // guard that refuses that is a guard somebody deletes.
  // CODE HEREDOCS ARE CODE (F-78): checked as text for the two things that
  // matter, as `python3 -c` code is below, and left out of the shell parse.
  foreach (is_string($command) ? operator_commands_code_heredocs($command) : [] as $body) {
    if (preg_match(operator_verb_pattern(), $body) === 1) {
      guard_refuse('operator-command:in-interpreter', sprintf(
        'This hands an interpreter code carrying one of the operator-only '
        . 'verbs. Putting the command inside a program does not make it the '
        . 'agent\'s to run — show the OPERATOR the command and the reason, '
        . 'and let them run it. (Refused: %s)',
        trim($command),
      ));
    }
    if (code_names_enforcement($body) && code_writes($body)) {
      guard_refuse('protected-path:interpreter', sprintf(
        'This hands an interpreter code that names the enforcement itself — the '
        . 'guard, the run record, the evidence store, the baseline or the '
        . 'settings — and code is not a command line this guard can read, so '
        . 'what it writes cannot be checked. Do the work through the pipeline, '
        . 'or if a file genuinely must change, that is the OPERATOR\'s at a '
        . 'terminal. (Refused: %s)',
        trim($command),
      ));
    }
  }
  $command = is_string($command) ? operator_commands_scan_text($command) : '';
  if ($command === '') {
    return;
  }

  // The enforcement paths, reachable through a shell exactly as through Write.
  //
  // Asked of the OPERANDS, and relative to wherever the command line has `cd`'d
  // to. Substring-matching the raw line missed the obvious move:
  //
  //     cd droost/droost-workflow && echo '{"reason":…}' > bypass.json
  //
  // Neither half contains a protected path as written, the grant lands, and
  // custom-code edits that were refused a moment earlier are permitted. A
  // shell's idea of where it is changes what a bare filename means, and a
  // guard that does not follow `cd` is reading a different command from the one
  // that runs.
  $cwd = $root;
  // The previous command's unwrapped tokens, for a pipeline. `find … | xargs
  // rm` splits into two invocations and the xargs tier has to judge the
  // `find` that feeds the pipe; precomputed and indexed so the predecessor is
  // always available regardless of which `continue` this iteration takes.
  $invocations = with_find_exec_commands(operator_commands_invocations($command));
  $unwrappedByIndex = array_map(
    static fn (array $one): array => operator_commands_unwrapped($one),
    $invocations,
  );
  $previousPlain = [];
  foreach ($invocations as $invocationIndex => $tokens) {
    $previousPlain = $unwrappedByIndex[$invocationIndex - 1] ?? [];
    // `cd X` moves the floor for everything after it. `cd` with no argument,
    // or to somewhere unreadable, gives up on tracking rather than guessing.
    // EVERY spelling of it. Only a bare `cd X` moved the floor, so `pushd X`,
    // `cd -- X`, `cd -P X` and `(cd X && …)` all left the guard resolving bare
    // filenames against the project root — and a forged bypass.json written
    // that way took the require_run wall from exit 2 to exit 0. Proven with
    // `pushd`.
    //
    // A leading `(` or `{` is its own token now, so a subshell's `cd` is still
    // the head of its invocation.
    $unwrapped = operator_commands_unwrapped($tokens);
    $verbWord = strtolower(ltrim($unwrapped[0] ?? '', '({'));
    if ($verbWord === 'cd' || $verbWord === 'pushd') {
      // The first operand that is not one of `cd`'s own flags.
      $target = '';
      foreach (array_slice($unwrapped, 1) as $word) {
        if ($word === '--') {
          continue;
        }
        if (str_starts_with($word, '-')) {
          continue;
        }
        $target = ltrim($word, "\x01");
        break;
      }
      $moved = $target === '' ? FALSE : realpath(
        str_starts_with($target, '/') ? $target : $cwd . '/' . $target,
      );
      $cwd = $moved === FALSE ? $root : $moved;
      continue;
    }
    // Same reasoning as the verb tier: a program name this guard cannot read
    // reaches every protected path as surely as `cp` does, and the tokeniser
    // that feeds both walls is blind in the same way.
    if (operator_commands_opaque_head($tokens)) {
      guard_refuse('protected-path:variable-program', sprintf(
        'This command runs whatever a variable happens to hold, so where it '
        . 'writes cannot be read here — and the run record, the evidence store '
        . 'and this guard are all one redirect away. Write the program name '
        . 'out. A variable is fine in an ARGUMENT; it is the program that has '
        . 'to be legible. (Refused: %s)',
        trim($command),
      ));
    }
    // A READ is not a write, and refusing one costs more than it buys. The
    // tier's own docblock says a shell string does not reliably tell them
    // apart — true of a string, and this is a list of arguments with a command
    // at the front. `git diff .claude/settings.local.json` was refused, which
    // means the agent could not review or report a change to its own wiring;
    // so were `cat`, `ls` and `git log` on the same paths.
    //
    // Each invocation is judged on its OWN leading command, so `cat x; rm y`
    // still refuses the second half. A command that is not on this list is
    // treated as a write, which is the right way round to be wrong.
    $plain = operator_commands_unwrapped($tokens);
    $writesTo = FALSE;
    $redirectsOnly = TRUE;
    foreach ($tokens as $token) {
      if (str_starts_with($token, "\x01")) {
        $writesTo = TRUE;
        continue;
      }
      // A flag retargets an OPERAND, which a redirect does not: `git diff
      // --output=<path>` truncates and fills its target, and `git diff` is on
      // the read list — so the guard's own file was a legal destination for a
      // "read". The program is passed so a flag that means something else FOR
      // IT (grep's `-o`) is not read as a destination.
      if (operator_commands_write_flag($token, $plain[0] ?? '')) {
        $writesTo = TRUE;
        $redirectsOnly = FALSE;
        break;
      }
    }
    $verb = strtolower(basename($plain[0] ?? ''));
    $sub = strtolower($plain[1] ?? '');
    // INTERPRETER CODE IS NOT SHELL, AND THIS GUARD CANNOT READ IT. `php -r
    // 'file_put_contents(".claude/hooks/droost-workflow-guard.php","");'`
    // empties the guard, `python3 -c 'open("droost/droost-workflow/run.json",
    // "w")...'` rewrites the record, and no shell parse sees the path: the
    // whole program is one token the tokeniser keeps opaque, and a payload
    // with no whitespace is never re-scanned at all. The evidence-store rule
    // below already refuses a WHOLE command that names `evidence.sqlite`; this
    // is that rule for the rest of the enforcement, scoped to an interpreter
    // handed inline code so it does not fire on prose that merely mentions a
    // path. Naming an enforcement file in code you hand an interpreter is not
    // something ordinary work does.
    // AND ONLY WHEN IT WRITES. Naming the path was enough on its own, which
    // refused READING the record —
    // `python3 -c "json.load(open('…/run.json'))"`,
    // a subject inspecting its own run, came back as "code that names the
    // enforcement". Reading the record is ordinary and the whole pack
    // encourages it; the attack this rule exists for is a WRITE
    // (`file_put_contents(<guard>, "")`). So the code must both name an
    // enforcement path and carry something that writes.
    //
    // This is a text heuristic over code, which is the shape that keeps
    // producing false positives here — but the alternative is refusing every
    // read, and the rule is already only a wall against LITERAL spellings:
    // a path assembled from two halves slips it either way, so requiring a
    // literal write beside a literal path gives up very little.
    $inlineCode = operator_commands_inline_code($plain, $cwd);
    // The verb hidden in a PROGRAM rather than in a file. The code is no
    // longer re-scanned as shell (tokenising Python as a command line is how
    // reading the run record got refused), so the verbs are looked for in the
    // text, the same way the script reader looks for them in a `.py`.
    if ($inlineCode !== NULL && preg_match(operator_verb_pattern(), $inlineCode) === 1) {
      guard_refuse('operator-command:in-interpreter', sprintf(
        'This hands an interpreter code carrying one of the operator-only '
        . 'verbs. Putting the command inside a program does not make it the '
        . 'agent\'s to run — show the OPERATOR the command and the reason, '
        . 'and let them run it. (Refused: %s)',
        trim($command),
      ));
    }
    if ($inlineCode !== NULL
      && code_names_enforcement($inlineCode)
      && code_writes($inlineCode)) {
      guard_refuse('protected-path:interpreter', sprintf(
        'This hands an interpreter code that names the enforcement itself — the '
        . 'guard, the run record, the evidence store, the baseline or the '
        . 'settings — and code is not a command line this guard can read, so '
        . 'what it writes cannot be checked. Do the work through the pipeline, '
        . 'or if a file genuinely must change, that is the OPERATOR\'s at a '
        . 'terminal. (Refused: %s)',
        trim($command),
      ));
    }
    // THE CONTAINING DIRECTORIES, not only the files in them. Every rule below
    // names a file, so the cheapest way past all of them was to take away
    // what holds them: `mv droost/droost-workflow /tmp/dw` stashes the record,
    // the store and the spec; `rm -rf droost` takes the baseline with them.
    // The `mv` form is the worse one, because it is REVERSIBLE — stash, work
    // ungoverned, put it back, and the record has no gap to notice.
    //
    // Scoped to verbs that MOVE OR REMOVE something: `droost` is a directory
    // name and also a word this project says constantly, and matching the
    // name alone refused `git commit -m "droost work"`. `\\\\?` is an OPTIONAL
    // backslash, for `\rm`, which is how a shell bypasses an alias.
    //
    // IN THIS LOOP, AGAINST THIS `$cwd`. This lived in a second loop after
    // the main one, with its own pass over the invocations and no tracked
    // directory — so `cd droost && rm -rf droost-workflow` resolved the bare
    // name against the project ROOT, found `droost-workflow` on no list, and
    // permitted it. The state directory was deleted; so was `.claude/hooks`
    // by the same move. The main loop had been following `cd` for exactly
    // this reason since the forged-bypass round, one screen above.
    //
    // What still escapes is a shell VARIABLE: `rm -rf "$PWD/droost"` is a
    // path this cannot resolve without running the shell. An absolute path
    // is caught; an unexpanded one is not, and that is stated rather than
    // left to be discovered.
    $destructive = '/^\\\\?(?:\/\S+\/)?'
      . '(?:rm|unlink|rmdir|mv|cp|ln|install|rsync|shred|truncate|dd|mktemp)\b/';
    // THE VERB, not the whole line. This matched the joined tokens, so a
    // destructive WORD anywhere in an invocation armed the tier — as an
    // argument, a filename, a commit message, or a variable's value. Reading
    // a shell script is where it bit: `dogfood.sh` assigns
    // `STATE="$SITE/droost/droost-workflow"` and elsewhere says `install`,
    // and launching the eval harness came back as "a shell command acts on
    // droost/droost-workflow itself". Nothing in that script removes
    // anything. `$plain` is already unwrapped, so its head IS the verb.
    if (preg_match($destructive, ltrim($plain[0] ?? '', "\x01")) === 1) {
      $protectedDirs = enforcement_protected_dirs($stateDir);
      // A COPY READS ITS SOURCE. This judged every operand, so `cp -Rp
      // droost/droost-workflow <somewhere>` was refused as though it removed
      // the directory — and that is ARCHIVING the run state, which is what
      // the eval harness does when it collects a finished round, and what
      // `reset --force` promises in this very message ("archives rather than
      // discards"). The rule's own words are "moving or removing it disarms
      // every one of them"; a copy does neither, and the original stays
      // exactly where the enforcement expects it.
      //
      // So for the copying verbs only the DESTINATION is judged — the last
      // operand, as every one of them defines it. `mv` keeps every operand,
      // because its source does not survive.
      $copyLike = in_array(
        strtolower(basename(ltrim($plain[0] ?? '', "\x01"))),
        ['cp', 'rsync', 'install'],
        TRUE,
      );
      $candidates = [];
      foreach (array_slice($tokens, 1) as $operand) {
        $operand = ltrim($operand, "\x01");
        if ($operand === '' || str_starts_with($operand, '-')) {
          continue;
        }
        $candidates[] = $operand;
      }
      if ($copyLike && $candidates !== []) {
        $candidates = [end($candidates)];
      }
      foreach ($candidates as $operand) {
        // BRACE-EXPANDED. `rm -rf {droost,.claude}` names two directories in
        // one operand, neither spelled literally, and wiped the entire
        // enforcement tree in one allowed command; `cd droost && rm -rf
        // {droost-workflow,baseline}` did it after a cd. The FILE tier already
        // expands braces before judging; this DIRECTORY tier did not, so a
        // braced operand slipped both.
        foreach (operator_commands_brace_branches($operand) as $branch) {
          if (preg_match('/[*?\[{]/', $branch) === 1) {
            continue;
          }
          $absolute = str_starts_with($branch, '/') ? $branch : $cwd . '/' . $branch;
          $landing = resolved_relative($absolute, $root);
          if ($landing === '') {
            // Not on disk, or resolving outside: judge the spelling, relative
            // to where the shell is standing.
            $spelled = rtrim(normalised_path($absolute), '/');
            $rootPath = normalised_path($root);
            $landing = str_starts_with($spelled, $rootPath)
              ? trim(substr($spelled, strlen($rootPath)), '/')
              : $spelled;
          }
          if (in_array($landing, $protectedDirs, TRUE)) {
            guard_refuse('protected-path:directory', sprintf(
              'A shell command in this run acts on `%s` itself — the directory, '
              . 'not something in it. That directory holds the run\'s record, the '
              . 'evidence store, the adoption baseline or the guard, depending on '
              . 'which one this is, and moving or removing it disarms every one of '
              . 'them at once while leaving the files intact somewhere else. Act '
              . 'on a named file inside it instead. If the state itself has to go, '
              . 'that is the OPERATOR\'s `droost-workflow reset --force`, which '
              . 'archives rather than discards. (Refused: %s)',
              $landing,
              trim($command),
            ));
          }
        }
      }
    }
    // PARENTHESISED. `&&` binds tighter than `||`, so
    // `!$writesTo && in_array(…)
    // || ($verb === 'git' && …)` left the git branch answering on its own —
    // and `git diff --output=.claude/hooks/droost-workflow-guard.php` was read
    // as a read while it truncated the guard. The write check has to govern
    // BOTH lists, which is what it was written to do.
    // `find` READS until it is told to act, and then it acts on everything it
    // found. `find . -name droost-workflow-guard.php -delete` deleted the
    // guard and `find .claude -type f -delete` wiped the directory, because
    // find is not a destructive verb and its targets are not globs — so
    // neither wall was looking. The action flags are the whole difference.
    // AND BY WHAT THE ACTION DOES. `-exec` runs a program, and the program
    // decides: `find . -name "*.php" -exec php -l {} \;` lints every file in
    // the project and `-exec vendor/bin/phpcs {} +` is the canonical phpcs
    // invocation — both were refused, and both are typed daily. `-delete`,
    // and an `-exec` of something that writes, are the cases that matter.
    //
    // AND BY WHAT IT WOULD ACTUALLY MATCH. The reach test used to PREDICT from
    // a hand-written list of basenames whether find's filters could touch
    // enforcement, and the `&&` turned every misprediction into an allow:
    //
    //   find . -delete -name "nomatch"          -delete runs BEFORE the filter
    //   find . -path "*droost-workflow/*" -delete   the list never held a path
    //   find -L .claude/hooks -type f -delete   `-L` emptied the search paths
    //   find vendor/bin -name eslint -delete    eslint was not on the list
    //
    // each proven to delete what it named. So the guard no longer predicts: it
    // enumerates the protected files that really sit under each search path,
    // asks the same rule `rm` is held to which they are, and lets a filter
    // exclude one only when it comes BEFORE the action — which is the order
    // find applies them in.
    // XARGS RUNS A PROGRAM OVER NAMES THIS GUARD NEVER SEES. `find . -name
    // droost-workflow-guard.php | xargs rm` was two harmless-looking
    // invocations: a find with no action, and an `rm` with no operand. The
    // names travel down the pipe, and the guard deleted itself. When xargs
    // hands a program that writes an argument list it cannot read, the only
    // thing it CAN judge is what fed the pipe: a `find` is judged by its
    // reach exactly as `find … -delete` would be; anything else — `cat`, `ls`,
    // a file — is unreadable and refused, and the message names the form
    // that can be judged.
    if ($verb === 'xargs') {
      $program = '';
      $valueNext = FALSE;
      foreach (array_slice($plain, 1) as $word) {
        $word = ltrim($word, "\x01");
        if ($valueNext) {
          $valueNext = FALSE;
          continue;
        }
        if (in_array($word, ['-I', '-n', '-L', '-P', '-d', '-E', '-s', '-a'], TRUE)) {
          $valueNext = TRUE;
          continue;
        }
        if (str_starts_with($word, '-')) {
          continue;
        }
        $program = strtolower(basename($word));
        break;
      }
      $writers = '/^(?:rm|unlink|rmdir|mv|cp|ln|install|rsync|shred|truncate|dd|sed'
        . '|tee|chmod|chown|perl|ruby|php|python3?|sh|bash|zsh|node)$/';
      if ($program !== '' && preg_match($writers, $program) === 1) {
        // The feeder decides, judged the way the operand loop judges the same
        // producer on its own: a `find` by its filters, anything else by
        // whether ITS operands name or glob a protected path. `ls *.log |
        // xargs rm` reaches nothing and is ordinary cleanup; `ls
        // .claude/hooks/* | xargs rm` reaches the guard. Blanket-refusing
        // every non-find feeder blocked the first — the false positive that
        // gets a guard switched off.
        $reach = pipe_feeder_reaches($previousPlain, $cwd, $root, $stateDir);
        if ($reach !== '') {
          guard_refuse('protected-path:xargs', sprintf(
            '`xargs %s` acts on whatever the pipe carries, and this guard never '
            . 'sees those names — here the command feeding it reaches `%s`. A '
            . '`find … -delete` or `find … -exec %s {} +` is judged by its '
            . 'filters and permitted when it reaches nothing protected; so is '
            . 'naming the files. (Refused: %s)',
            $program,
            $reach,
            $program,
            trim($command),
          ));
        }
      }
    }
    // THE UNWRAPPED TOKENS. `find_reaches_enforcement()` reads the search
    // paths from index 1 on, assuming `find` is index 0 — and `env find
    // -delete`, `X=1 find -delete`, `nice find -delete` put a wrapper there,
    // so `find` itself was taken as a search path that resolved to nothing,
    // the `.` default never applied, and GNU find with no path emptied the
    // project. The wrapped form was never a different command.
    if ($verb === 'find' && find_action_writes($plain)) {
      $reached = find_reaches_enforcement($plain, $cwd, $root, $stateDir);
      if ($reached !== []) {
        $more = count($reached) - 1;
        guard_refuse('protected-path:find', sprintf(
          '`find` here removes or rewrites what it matches, and what it '
          . 'matches includes `%s`%s — part of the enforcement this run rests '
          . 'on (the guard, its wiring, the run record, the evidence store, a '
          . 'gate\'s executable or a brief). A filter placed BEFORE the action '
          . 'narrows what it reaches; one after it does not, and `-o`, `!` and '
          . 'parentheses cannot be modelled here. Name what you mean, or search '
          . 'below the directory that holds it. (Refused: %s)',
          $reached[0],
          $more > 0 ? sprintf(' and %d more', $more) : '',
          trim($command),
        ));
      }
    }
    $isReader = (in_array($verb, [
      'cat', 'less', 'more', 'head', 'tail', 'ls', 'stat', 'file', 'wc',
      'grep', 'egrep', 'fgrep', 'rg', 'ag', 'ack', 'diff', 'md5', 'shasum',
      'md5sum', 'sha1sum', 'sha256sum', 'cmp', 'realpath', 'readlink', 'jq',
      // `test -f run.json` and `[ -f run.json ]` only stat. Without them
      // here, checking whether the record EXISTS was refused as editing it.
      'test', '[',
      // NOT sqlite3. `sqlite3 db "select 1"` and `sqlite3 db "update …"`
      // differ only in a string this cannot parse, and the store is what
      // that string would be rewriting. Read it with `droost-workflow
      // evidence`, which renders the whole round.
    ], TRUE)
      // `add` and `commit` READ the working tree — into the index, into
      // history — and never write it, so staging the guard after `init` is
      // recording the enforcement, not rewriting it; it was refused as the
      // latter. `checkout`, `restore`, `reset`, `stash`, `rm`, `mv` and
      // `clean` all DO write the working tree and stay out of this list.
      || ($verb === 'git' && in_array($sub, ['diff', 'log', 'show', 'status', 'blame', 'grep', 'add', 'commit'], TRUE))
      // `sed` READS unless it edits in place (F-83). Its program writes only
      // through `w`, and the program is read as code above, so what is left
      // for this tier is `-i`: `sed -n 60,200p droost/droost-workflow/run.json`
      // printed part of the record and was refused as rewriting it, while
      // `cat` and `head` over the same file passed.
      || ($verb === 'sed' && !sed_edits_in_place($tokens))
      // `find` READS unless an action writes (F-97). With no `-delete`,
      // `-exec` and the like it only prints names, and a find that does
      // write is judged above by what its filters reach. Its quoted
      // `-path '*droost*'` was glob-expanded here as if the shell would
      // expand it, landed on the lever file, and a search of vendor/ for
      // PHP files was refused as editing the dial.
      || ($verb === 'find' && !find_action_writes($plain)));
    $reading = !$writesTo && $isReader;
    if ($reading) {
      continue;
    }
    // A READER WHOSE ONLY WRITE IS A REDIRECT. `2>/dev/null` is the commonest
    // idiom in shell, and it set `$writesTo`, which switched the exemption
    // above off — after which every path the command merely READ was judged a
    // write target. `grep -rn allow_entity_write web/sites 2>/dev/null` came
    // back as "that file IS the write-gate arming". The redirect's TARGET
    // still has to be judged (`cat x > guard` is how the guard gets
    // overwritten), so it is not the exemption that changes: the operands the
    // reader reads are skipped, and the `\x01` target is not.
    $readerRedirectOnly = $isReader && $writesTo && $redirectsOnly;
    // A flag's VALUE is an operand. Every token starting with `-` was skipped
    // as "a flag, not a path", which is true of `-q` and false of
    // `--output=.claude/hooks/droost-workflow-guard.php` — so the one form
    // that both marks itself a write AND carries its destination inside the
    // flag reached neither check. Split here, and mark the value a write
    // target, because that is exactly what the flag makes it.
    $operands = [];
    foreach ($tokens as $token) {
      if (preg_match('/^(--output|--out|--outfile|--write|--dest|--destination|--report-file|-o|-O|of)=(.+)$/i', $token, $flag) === 1) {
        // `of=` is dd's. It also defeated the `(^|/)` anchor every protected
        // path is written with, so
        // `dd of=.claude/hooks/droost-workflow-guard.php`
        // matched nothing at all and overwrote the guard.
        //
        // `--report-file` is phpcs's, and `operator_commands_write_flag()`
        // gained it as "the one flag function both tiers ask" — but that
        // function only decides IS-a-write, while splitting the DESTINATION
        // out of the flag happens here, and this list had not learned it. So
        // `phpcs --report-file=.claude/hooks/droost-workflow-guard.php` was
        // marked a write and then its target skipped as "a flag", and the
        // real phpcs emptied the guard into a report. The two lists are the
        // drift this series keeps paying for; the value-bearing flags are
        // named in both now.
        $operands[] = "\x01" . $flag[2];
        continue;
      }
      $operands[] = $token;
    }
    foreach ($operands as $operand) {
      if ($operand === '' || str_starts_with($operand, '-')) {
        continue;
      }
      // The reader's own operands, when the only write on the line is a
      // redirect: it is READING these. The redirect target keeps its `\x01`
      // and is judged below like any other write.
      if ($readerRedirectOnly && !str_starts_with($operand, "\x01")) {
        continue;
      }
      // Resolved against the tracked cwd, then judged exactly as a Write is.
      // Is this operand being WRITTEN? A redirection target always is; a file
      // named to `cp`, `mv`, `tee` and friends is; a file named to anything
      // else is usually being read or run.
      $target = str_starts_with($operand, "\x01");
      $operand = ltrim($operand, "\x01");
      // A GLOB names the directory it sits in, never the files — and the
      // guard was reading the pattern where the shell will read the
      // filesystem. Judging the DIRECTORY closed `rm .claude/hooks/*`; it did
      // not close `rm .claude/hooks/droost-workflow-guard.ph?` (the `?` was
      // dropped from the metacharacter set to stop `<?php` matching) or
      // `rm dro[o]st/droost-workflow/run.json` (only the basename was
      // inspected). Both deleted their targets.
      //
      // So ASK THE FILESYSTEM, exactly as the shell is about to. `glob()`
      // expands the same patterns against the same disk, and every name it
      // returns is judged as if it had been typed. What the shell will do is
      // knowable here, and guessing at the pattern was the mistake.
      // `{` TOO. The shell expands braces before it expands globs, and this
      // set had only `*`, `?` and `[` — so `rm
      // .claude/hooks/droost-workflow-guard.ph{p,x}` reached neither the
      // `glob()` tier nor the directory rule, and deleted the guard. The
      // tokeniser also treats `{`/`}` as grouping, so the operand arrives
      // split and the literal-path regexes cannot match it either: two
      // independent mechanisms both missed it.
      $meta = preg_match('/[*?\[{]/', $operand) === 1;
      if ($meta) {
        $pattern = str_starts_with($operand, '/') ? $operand : $cwd . '/' . $operand;
        // Brace expansion happens whether or not the names exist, so the
        // branches are judged as literal paths too — `glob()` returns nothing
        // for a name that is not on disk yet, and `rm {a,b}/guard.php` is
        // about to create the absence it is being judged for.
        foreach (operator_commands_brace_branches($pattern) as $branch) {
          if (preg_match('/[*?\[]/', $branch) === 1) {
            continue;
          }
          $refusedBranch = enforcement_refusal($branch, $root, $stateDir);
          if ($refusedBranch !== '') {
            guard_refuse('protected-path:brace-expansion', sprintf(
              '%s A brace expansion in this command produces it. (Refused: %s)',
              $refusedBranch,
              trim($command),
            ));
          }
        }
        foreach (glob($pattern, GLOB_BRACE) ?: [] as $expanded) {
          $refused = enforcement_refusal($expanded, $root, $stateDir);
          if ($refused !== '') {
            // A loop's list is not a read. The body can write to every name
            // the list expands to, through a variable the guard cannot
            // resolve, so the loop is refused where `cat` over the same glob
            // is not. P6 run 9's agent looped `head -1` over archived specs
            // and was told only that a hand-written line forges the record
            // (F-112), so the refusal says why a loop is different.
            guard_refuse('protected-path:wildcard', sprintf(
              '%s A wildcard in this command expands onto it.%s (Refused: %s)',
              $refused,
              in_array($verb, ['for', 'select'], TRUE)
                ? ' A `' . $verb . '` loop\'s body can write to every name its list expands to, and the guard cannot see what it does with them, so a loop over these files is refused where naming them to a reader is not.'
                : '',
              trim($command),
            ));
          }
        }
      }
      // And the directory, for the case the expansion cannot answer: a pattern
      // that matches nothing today still names a directory, and `rm -r
      // droost/*` reaches the run record through names that do not exist yet.
      $globDir = NULL;
      if ($meta && str_contains($operand, '/')) {
        $globDir = dirname($operand);
      }
      elseif ($operand === '*' || $operand === './*' || $operand === '.*') {
        $globDir = '.';
      }
      // ONLY WHERE THE EXPANSION COULD DESTROY SOMETHING. This rule is about
      // a glob the shell expands into names the guard never sees — its own
      // message says it cannot tell "a tidy-up from the one move that removes
      // the enforcement" — so it belongs to verbs that remove and to writes.
      // It fired for ANY non-reading verb, which includes reading a script:
      // `bin/droost-workflow` is a PHP CLI with globs in it, so running the
      // installer came back as "a shell command expands a wildcard across the
      // project root", and the guard could not be re-materialised because the
      // guard refused the command that materialises it.
      // A REDIRECT DOES NOT EXPAND A GLOB. `>` takes one target, so a
      // redirect can never become "names the guard never sees" — only a
      // destructive verb over an expansion can, which is what every test of
      // this rule spells (`rm .claude/hooks/*`, `rm -r droost/*`). Including
      // writes here meant any script with a `>> "$LOG"` in it armed the rule
      // for every OTHER line's globs, and the guard reads a script before
      // running it: `./dogfood.sh status` — which prints a pane and a run
      // record — was refused for "expands a wildcard across the project
      // root".
      $globDestroys = preg_match($destructive, ltrim($plain[0] ?? '', "\x01")) === 1;
      if ($globDir !== NULL && $globDestroys) {
        $globRefusal = wildcard_directory_refusal(
          $globDir === '.' || $globDir === '' ? $cwd : (str_starts_with($globDir, '/') ? $globDir : $cwd . '/' . $globDir),
          $root,
          $stateDir,
        );
        if ($globRefusal !== '') {
          guard_refuse('protected-path:wildcard-directory', sprintf('%s (Refused: %s)', $globRefusal, trim($command)));
        }
      }
      $absolute = str_starts_with($operand, '/') ? $operand : $cwd . '/' . $operand;
      $refusal = enforcement_refusal($absolute, $root, $stateDir);
      if ($refusal !== ''
        && preg_match('#(^|/)(vendor/bin|node_modules/\.bin)/[^/]+$#', $operand) === 1
        && !$target
        && preg_match('/^(cp|mv|ln|install|tee|dd|truncate|sed|chmod|chown|rm|rmdir|unlink|shred|patch|curl|wget|scp|rsync|unzip|tar)$/', $verb) !== 1) {
        // Named, not written: running a gate's own tool is what it is for.
        // `unlink`/`rmdir` join the writers: `unlink vendor/bin/phpcs`
        // deletes a gate's executable — replacing the verdict — and read as
        // "named, not written" it was permitted, while `rm vendor/bin/phpcs`
        // was refused. The destructive-verb list two screens up already had
        // `unlink`; this one had not.
        continue;
      }
      if ($refusal !== '') {
        guard_refuse('protected-path:shell', sprintf('%s (Refused: %s)', $refusal, trim($command)));
      }
    }
  }

  if (preg_match('#evidence\.sqlite#', $command) === 1) {
    guard_refuse('protected-path:evidence-store:shell', sprintf(
      'A shell command in this run reaches the evidence store. That store is the '
      . 'run\'s own record of what droost measured — gate verdicts, which '
      . 'citations resolved, which tools were called — and a record its subject '
      . 'can edit proves nothing about its subject. Read it with '
      . '`droost-workflow evidence` (or `drush droost:workflow:evidence`), which '
      . 'renders the whole round. If a verdict in it is wrong, fix the thing it '
      . 'measured and let the gate run again; if the store itself is broken, the '
      . 'OPERATOR clears it. (Refused: %s)',
      trim($command),
    ));
  }

  if (preg_match('#(^|[\s\'"=/])droost/baseline(/|\s|$)#', $command) === 1) {
    guard_refuse('protected-path:baseline:shell', sprintf(
      'A shell command in this run reaches droost/baseline/. That is the '
      . 'OPERATOR\'s adoption record, written by `droost-workflow baseline` from '
      . 'their terminal and never by hand — editing it is the one move that turns '
      . 'the whole discipline into a formality, because it redefines what counts '
      . 'as debt. Read it with `droost-workflow baseline --status`. If debt was '
      . 'paid, ask the operator for `baseline --refresh`. (Refused: %s)',
      trim($command),
    ));
  }
}

/**
 * A path reduced to the one spelling every guard compares against.
 *
 * Separators, `//`, `/./` and `../` collapsed, case folded — APFS resolves
 * `Modules/Custom` to the same directory, so a cosmetic spelling must not be a
 * different path to the wall.
 *
 * `require_run_guard()` did this and had a test pinning it. The two guards
 * added since did not, and a reviewer walked through all three of them with
 * `//`, `/./`, `..` and a capital letter — `.claude//hooks/…guard.php` was
 * permitted, proven to reach the same inode. One function now, because three
 * implementations of "is this the same path" is three chances to be wrong and
 * two of them already were.
 *
 * @param string $file
 *   The path as the tool gave it.
 *
 * @return string
 *   The comparable form.
 */
function normalised_path(string $file): string {
  $path = strtolower(str_replace('\\', '/', trim($file)));
  $path = (string) preg_replace(['#/(?:\./)+#', '#//+#'], '/', $path);
  // Collapse `a/../b` by hand; realpath is no use for a file about to be
  // created, which is the case the wall exists for.
  while (preg_match('#(^|/)(?!\.\./)[^/]+/\.\./#', $path) === 1) {
    $path = (string) preg_replace('#(^|/)(?!\.\./)[^/]+/\.\./#', '$1', $path, 1);
  }

  return ltrim($path, '/');
}

/**
 * The project-relative path a write would REALLY land on.
 *
 * `normalised_path()` canonicalises spelling — case, `//`, `/./`, `..`, a
 * leading `./`, an absolute form — and it holds against every one of those. It
 * never resolves the inode, and that is a different question:
 *
 *     ln -s .claude/hooks tools
 *     Write  tools/droost-workflow-guard.php
 *
 * Neither call names a protected path, the second one overwrites the guard, and
 * the turn then ends cleanly with a run open. The editing-tool tier is the one
 * whose docblock says every claim this pipeline makes rests on these files
 * being out of reach, so a spelling check alone was not the wall it described.
 *
 * The parent is resolved, not the file: the wall exists for files about to be
 * created, and `realpath()` is FALSE for those. Walking up to the nearest
 * ancestor that exists and re-attaching the rest resolves any symlinked
 * DIRECTORY on the way — which is the whole attack, since a link whose target
 * is absent is followed by nothing either.
 *
 * @param string $file
 *   The path being written, absolute or project-relative.
 * @param string $root
 *   The project root.
 *
 * @return string
 *   The normalised project-relative path after resolution, or '' when it lands
 *   outside the project (which the caller treats as unprotected: the wall is
 *   about this repository's own enforcement files).
 */
function resolved_relative(string $file, string $root): string {
  $file = trim($file);
  if ($file === '') {
    return '';
  }
  $absolute = str_starts_with($file, '/') ? $file : rtrim($root, '/') . '/' . ltrim($file, '/');
  $tail = [];
  $head = $absolute;
  // At most a few dozen levels; the bound is a guard against a pathological
  // path rather than an expected case.
  for ($depth = 0; $depth < 64; $depth++) {
    $real = realpath($head);
    if ($real !== FALSE) {
      $resolved = $real . ($tail === [] ? '' : '/' . implode('/', array_reverse($tail)));
      $rootReal = realpath($root);
      $rootReal = $rootReal === FALSE ? rtrim($root, '/') : $rootReal;
      if ($resolved !== $rootReal && !str_starts_with($resolved, $rootReal . '/')) {
        return '';
      }

      return normalised_path(ltrim(substr($resolved, strlen($rootReal)), '/'));
    }
    $parent = dirname($head);
    if ($parent === $head) {
      return '';
    }
    $tail[] = basename($head);
    $head = $parent;
  }

  // Out of budget. Returning '' would say "not inside the project", which the
  // caller reads as UNPROTECTED — a fail-open on the one input designed to
  // exhaust it. A path this deep is not something ordinary work produces, so
  // naming it as the guard script is the safe answer: the caller refuses, and
  // whoever genuinely needed a sixty-level relative path can say so.
  //
  // In practice `enforcement_refusal()` also checks the LITERAL normalised
  // path, which collapses `a/../` pairs lexically and catches these anyway —
  // verified to depth 60. This is the belt to that brace, because the double
  // check is one refactor away from being a single one.
  return '.claude/hooks/droost-workflow-guard.php';
}

/**
 * Refuses edits to the enforcement itself, and to the rules during a run.
 *
 * Two tiers, because they answer different questions.
 *
 * ALWAYS: the guard script and `.claude/settings.json`. An agent that can
 * overwrite its own wall with `<?php exit(0);`, or blank the file that wires
 * the wall in, has no wall — and it does not need a run to be active to do it,
 * because disarming first and running second is one extra step. Every claim
 * this pipeline makes about what an agent cannot do rests on these two files
 * being out of reach.
 *
 * DURING AN ACTIVE RUN: the lever file and the skill briefs. Setting a project
 * up is legitimate work and an agent may be asked to do it — but changing the
 * rules you are currently being judged by is not the same act.
 * `droost.workflow.yml`
 * is where the effort dial lives, and moving the dial is documented throughout
 * as the operator's decision; the briefs are the instructions the run is being
 * held to. Before a run, both are ordinary files.
 *
 * @param string $file
 *   The project-relative path being written.
 * @param string $root
 *   The project root.
 * @param string $stateDir
 *   The resolved state directory.
 *
 * @return string
 *   A refusal message, or '' when the path is not protected.
 */
function enforcement_refusal(string $file, string $root, string $stateDir): string {
  $path = normalised_path($file);
  // Compare against the project-relative tail, so an absolute path lands too.
  $rootPath = normalised_path($root);
  $relative = str_starts_with($path, $rootPath)
    ? ltrim(substr($path, strlen($rootPath)), '/')
    : $path;

  // BOTH the spelling and the destination. A path can dodge this wall two
  // ways — by being written differently, which `normalised_path()` answers,
  // and by going somewhere else, which only resolving it answers. Checking the
  // literal form as well means a symlink that resolves OUT of the project (so
  // `resolved_relative()` returns '') still gets the spelling check.
  $landing = resolved_relative($file, $root);
  if ($landing !== '' && $landing !== $relative) {
    $viaLink = enforcement_refusal_for($landing, $root, $stateDir);
    if ($viaLink !== '') {
      return $viaLink . ' (That path reaches it through a link or a renamed '
        . 'directory; what the write lands on is what matters here.)';
    }
  }

  return enforcement_refusal_for($relative, $root, $stateDir);
}

/**
 * The refusal for one already-resolved project-relative path.
 *
 * Split out so `enforcement_refusal()` can ask it twice — once about the path
 * as written and once about where that path really lands.
 *
 * @param string $relative
 *   The normalised project-relative path.
 * @param string $root
 *   The project root.
 * @param string $stateDir
 *   The resolved state directory.
 *
 * @return string
 *   A refusal message, or '' when the path is not protected.
 */
function enforcement_refusal_for(string $relative, string $root, string $stateDir): string {

  // The run's own record. `baseline_dir_guard()` protected the evidence store
  // beside it and not this, and a single ordinary Write to it takes the whole
  // wall down: set `enforcement: off`, or mark the phase passed, or mark it
  // failed, or simply corrupt the file — the stop hook permits on every one of
  // those. The comment in this file claiming that hole was closed was about
  // `require_run_guard()`, which acts only in pre-tool-use.
  if (preg_match('#(^|/)(droost/droost-workflow|\.droost-workflow)/run\.json$#', $relative) === 1) {
    return 'That file is the run\'s own record — which phase it is in, what it '
      . 'has passed, whether enforcement is on. droost writes it; an agent that '
      . 'edits it is not advancing a run, it is rewriting the referee. If the '
      . 'record is wrong, the OPERATOR clears it with '
      . '`droost-workflow reset --force`, which archives it rather than '
      . 'discarding it.';
  }

  if (preg_match('#(^|/)\.claude/hooks/droost-workflow-guard\.php$#', $relative) === 1) {
    return 'That file IS the enforcement. It is the hook that refuses ungoverned '
      . 'edits, holds a phase until its checks resolve, and keeps the evidence '
      . 'store out of reach — and an agent that can rewrite it has none of those '
      . 'things. If the guard is wrong, the OPERATOR reinstalls it with '
      . '`droost-workflow init`, which takes the shipped version.';
  }
  // `settings.local.json` too. It was unprotected while `settings.json`
  // was, and
  // Claude Code reads both — so the wall's own wiring could be overridden from
  // a file beside the one the wall defends, which is the same disarm with an
  // extra dot in the name.
  if (preg_match('#(^|/)\.claude/settings(\.local)?\.json$#', $relative) === 1) {
    return 'That file wires the enforcement in. Removing an entry from it '
      . 'disarms the wall exactly as surely as deleting the guard, and quietly. '
      . 'The OPERATOR rewires it with `droost-workflow init`, which merges '
      . 'rather than overwrites and leaves everything else in the file alone.';
  }

  // The bypass grant. It is two strings in a JSON file and it stands the
  // `require_run` wall down completely — and until now it was in NEITHER
  // protected list, so an agent could write its own and the guard would read it
  // as the operator's decision. The same file also clears droost's destructive
  // tool base, so one forged grant reaches further than this hook.
  //
  // Protecting the path is not authentication: an agent with a shell that
  // reaches the filesystem another way still wins, and the honest claim is that
  // this is a wall against the obvious route, not a signature. Making it a
  // signature needs a secret that does not live in the repository, which is the
  // same open problem the evidence chain has.
  // The gate executables. `ShellGateExecutor::binaryPathFor()` resolves every
  // gate to `vendor/bin/<tool>` or `node_modules/.bin/<tool>`, so these are the
  // programs whose exit codes this whole pipeline treats as the truth. An agent
  // that can replace one has replaced the verdict — a reviewer stubbed all
  // three with `exit 0` and watched the code phase pass with phpcs and phpstan
  // recorded `satisfied`.
  //
  // The directories they sit in stay writable: `composer install` and `npm ci`
  // are ordinary work, and this is about the files, not the trees. The SHELL
  // tier asks this only in a writing context, because naming one of these is
  // usually how you RUN it — `timeout 30 vendor/bin/phpunit` is a test suite,
  // not an attack, and refusing it would be the kind of false positive that
  // gets a guard switched off.
  if (preg_match('#(^|/)(vendor/bin|node_modules/\.bin)/[^/]+$#', $relative) === 1) {
    return 'That is a gate\'s own executable. droost runs it and reads its exit '
      . 'code as the verdict, so an agent that can rewrite it can make any gate '
      . 'say anything — and the run would report the tools as passing. Install '
      . 'and update these through composer or npm, which the OPERATOR runs, '
      . 'never by writing the file.';
  }

  // WHERE THE WRITE GATES ACTUALLY LIVE. `Drupal\droost\GateState` reads the
  // seven `allow_*` switches out of `$settings['droost']` in a dedicated
  // `settings.droost.php` beside settings.php — deliberately not in config, so
  // a `config:export`/`config:import` cannot arm them on another site. This
  // guard was matching `droost.settings` config, which GateState never reads:
  // it refused a command that does nothing and permitted the file that arms
  // every destructive gate droost has. Writing it is the operator's act at a
  // TTY, per environment, which is the whole design of putting it there.
  if (preg_match('#(^|/)settings\.droost\.php$#', $relative) === 1) {
    return 'That file IS the write-gate arming — the `allow_*` switches that '
      . 'authorise destructive work. droost reads them live out of it, and it '
      . 'lives outside config precisely so that arming one is a human decision '
      . 'made per environment at a terminal. An agent writing its own is not '
      . 'obtaining permission. Show the OPERATOR the gate you need and why, '
      . 'and let them arm it: `drush droost:gate <gate> on`.';
  }
  // settings.php too, because `$settings['droost'][…]` set there is honoured
  // as the base GateState overlays. A different file, the same arming.
  if (preg_match('#(^|/)sites/[^/]+/settings\.php$#', $relative) === 1) {
    return 'That is the site\'s settings.php, and droost honours '
      . '`$settings[\'droost\'][\'allow_*\']` set there as the base for its '
      . 'write gates — so an edit to it can arm destructive work. It is also '
      . 'the file holding the database credentials and the hash salt. Ask the '
      . 'OPERATOR for whatever this needs; if it is a gate, the command is '
      . '`drush droost:gate <gate> on`.';
  }

  if (preg_match('#(^|/)(droost/droost-workflow|\.droost-workflow)/bypass\.json$#', $relative) === 1) {
    return 'That file IS the bypass grant — the operator\'s recorded decision '
      . 'to let this session build with no run at all. Writing your own is not '
      . 'obtaining permission, it is forging the signature on it. If work '
      . 'genuinely has to happen outside the pipeline, show the operator '
      . '`drush droost:workflow:bypass "<reason>"` and let them run it; they '
      . 'close it again with `bypass --off`.';
  }

  // THE REST OF THE STATE DIRECTORY (F-96). The comment on the find tier
  // below says these trees are enforcement top to bottom, and this function
  // held two files of them. `tool-calls.jsonl`, `guard-calls.jsonl`,
  // `scaffolded.jsonl`, `pack.lock` and the archived runs took a Write, a
  // redirect or a `sed -i`, run or no run. `grounding_check` reads the
  // tool-call ledger straight from its file, so one appended line satisfied
  // the gate that holds a run to the tools its plan named. The guard's own
  // rows are what §7a counts, and the scaffold record is what sets an
  // untouched scaffold aside from `tests_in_diff`. No run open is no
  // exception: a row written before the run opens is attributed to it. The
  // spec is the one file here the agent writes.
  //
  // BY NAME, OR BY BEING THERE. The operand loop hands this every word of a
  // command, resolved against its `cd`, so after `cd droost/droost-workflow`
  // the `echo` in `echo x > spec-b.md` is `droost/droost-workflow/echo`. A
  // rule for "anything in the directory" refused that. The files the record
  // is made of are named; the archive is a directory; and a file already in
  // the directory is protected whatever it is called.
  //
  // EXCEPT THE STORE, which has its own rule and its own words, and this one
  // answered first for it (F-108). P6 run 8's seeker ran `sqlite3
  // evidence.sqlite ".tables"` and was told a hand-written line forges a
  // ledger and to write its spec with Edit, under a generic rule name. The
  // store's own refusal names it and says how to read it.
  if (preg_match('#(^|/)(droost/droost-workflow|\.droost-workflow)/([A-Za-z0-9._/-]+)$#', $relative, $inState) === 1
    && preg_match('#^(tmp-)?spec(-[A-Za-z0-9._-]+)?\.md$#', $inState[3]) !== 1
    && preg_match('#^evidence\.sqlite(-wal|-shm|-journal)?$#', $inState[3]) !== 1
    && (preg_match('#^(tool-calls\.jsonl|guard-calls\.jsonl|scaffolded\.jsonl|pack\.lock)$|^history(/|$)#', $inState[3]) === 1
      || is_file(rtrim($root, '/') . '/' . $relative))) {
    return 'That file is part of the run\'s own evidence: the tool-call ledger '
      . '`grounding_check` reads, the guard\'s record of itself, the scaffold '
      . 'record, the pack lock or an archived run. droost and the pipeline '
      . 'write it. A line written by hand is a forged entry in the record the '
      . 'gates and the evaluation are built from, with a run open or not. The '
      . 'spec is the one file in this directory that is yours: write it with '
      . 'the Write or Edit tool. Reading the rest is not refused: `cat`, `head` '
      . 'and `grep` read it, so does the Read tool, and `droost-workflow '
      . 'evidence` renders the whole record.';
  }

  // The second tier applies only while a run is under way — which is not the
  // same as "a run.json exists". A FINISHED run leaves its record behind with
  // `current_phase: null`; that file is the run's history, and the phase
  // skills say so in as many words. Arming on the file's existence meant a
  // run completed weeks ago went on refusing every lever edit with "a run is
  // under way", and the only way out was a `reset` nobody needed. Proven on a
  // walk that could not change `preset:` anywhere because of a run finished
  // the previous month.
  if (!guard_run_is_live($root, $stateDir)) {
    return '';
  }
  // ANCHORED, not "any path ending in the name". The lever file that governs
  // a run is the one at the project root — `WorkflowConfig::load()` reads
  // exactly `<root>/droost.workflow.yml` — but the pattern matched the
  // basename anywhere, so a `pack/droost.workflow.yml` shipped inside another
  // checkout was refused on the strength of an unrelated repository's run.
  if (preg_match('#^droost\.workflow\.yml$#', $relative) === 1) {
    return 'The lever file sets what this run is held to, and a run is under '
      . 'way. Its levers were frozen when the run began, so editing it now '
      . 'cannot change THIS run — but it changes the next one, and moving the '
      . 'effort dial is the operator\'s decision rather than the agent\'s. '
      . 'Before a run, it is an ordinary file.';
  }
  // COMMANDS too. Skills and agents were refused and `.claude/commands/` was
  // not, and a slash command is the same thing wearing a different extension:
  // it is instructions the agent invokes on itself. The scope audit exempts the
  // whole of `.claude/` (init writes thirty-one files there, and blaming the
  // agent for droost's own install is an inversion that has shipped three
  // times), so this wall was the ONLY thing watching that directory — and it
  // was not watching all of it.
  if (preg_match('#(^|/)\.claude/(skills|agents|commands)/#', $relative) === 1) {
    return 'That is one of the briefs this run is being held to, and a run is '
      . 'under way. Rewriting your own instructions mid-run is not the same act '
      . 'as improving them: do it before a run, or ask the operator. '
      . '`droost-workflow init` takes the shipped versions.';
  }

  return '';
}

/**
 * Every literal path a brace expansion can produce.
 *
 * `glob()` with GLOB_BRACE answers only for names that EXIST, and a command
 * that removes a file is about to create the absence it is being judged for.
 * So the branches are enumerated directly: `rm {a,b}/guard.php` names two
 * paths whether or not either is on disk.
 *
 * Bounded, because `{a,b}{c,d}{e,f}…` multiplies: past 64 branches the
 * command is not something a person typed and the directory rule answers it.
 *
 * @param string $pattern
 *   The operand, absolute.
 *
 * @return list<string>
 *   The literal paths, or the pattern unchanged when it holds no braces.
 */
function operator_commands_brace_branches(string $pattern): array {
  if (!str_contains($pattern, '{')) {
    return [$pattern];
  }
  $out = [$pattern];
  for ($round = 0; $round < 6; $round++) {
    $next = [];
    $grew = FALSE;
    foreach ($out as $one) {
      if (preg_match('/^(.*?)\{([^{}]*)\}(.*)$/s', $one, $m) !== 1) {
        $next[] = $one;
        continue;
      }
      $grew = TRUE;
      // RANGES TOO. This split on commas only, so `guar{c..e}.php` was one
      // branch spelled literally and matched nothing — while the shell
      // produced `guarc`, `guard`, `guare` and deleted the guard. Letters and
      // integers, which is what bash expands; the 64-branch cap below is safe
      // because no protected name carries a digit and no letter range exceeds
      // 58 branches.
      $inner = $m[2];
      if (preg_match('/^(-?\d+)\.\.(-?\d+)$/', $inner, $range) === 1) {
        $from = (int) $range[1];
        $to = (int) $range[2];
        $branches = abs($to - $from) > 64
          ? array_map('strval', range($from, $from + ($to >= $from ? 64 : -64)))
          : array_map('strval', range($from, $to));
      }
      elseif (preg_match('/^([a-zA-Z])\.\.([a-zA-Z])$/', $inner, $range) === 1) {
        $branches = range($range[1], $range[2]);
      }
      else {
        $branches = explode(',', $inner);
      }
      foreach ($branches as $branch) {
        $next[] = $m[1] . $branch . $m[3];
      }
    }
    $out = array_slice($next, 0, 64);
    if (!$grew) {
      break;
    }
  }

  return array_values($out);
}

/**
 * Whether a `find` command's action changes anything.
 *
 * `-delete` always does. `-exec`/`-ok` depend on the PROGRAM they run, and
 * judging them all as writes refused two commands people type every day —
 * `find . -name "*.php" -exec php -l {} \;` and `find src -name "*.php" -exec
 * vendor/bin/phpcs {} +`, the second being the canonical phpcs invocation
 * that droost's own gates run.
 *
 * The read list here is find's own: a program that only inspects the file it
 * is handed. It is NOT the shell tier's `$reading` list — that one gates a
 * bare command's operands, and a claim that the two were the same was false
 * in this very docblock while `php` sat on this list as a read and on the
 * interpreter list as a runner. An INTERPRETER is judged as the shell tier
 * judges one: it reads only when told to do nothing but check syntax or
 * print a version, and a script handed to it is a program this tier never
 * opens — `-exec php evil.php {} \;` was permitted as a read, and evil.php
 * is one Write away. The write flags are the shell tier's, from the one
 * function both ask.
 *
 * @param list<string> $tokens
 *   The invocation's tokens.
 *
 * @return bool
 *   TRUE when the action can change or remove what it matches.
 */

/**
 * The commands a find runs through `-exec`/`-execdir`/`-ok`/`-okdir`.
 *
 * Each is the program and its arguments up to the `;` or `+` terminator,
 * with find's `{}` placeholder dropped — so `find . -exec rm -rf
 * droost/droost-workflow {} \;` yields `[rm, -rf, droost/droost-workflow]`.
 * The caller judges each as its own command, which is what it is: the find
 * tier answers "does the action write" and "does the MATCH reach
 * enforcement", and neither asks what the -exec'd program does to a path it
 * names itself. `find . -name x -exec rm -rf droost/droost-workflow \;`
 * removed the state directory under a verdict of ALLOW.
 *
 * @param list<string> $tokens
 *   The find invocation's tokens.
 *
 * @return list<list<string>>
 *   One token-list per -exec command.
 */
function find_exec_commands(array $tokens): array {
  $out = [];
  $count = count($tokens);
  for ($i = 0; $i < $count; $i++) {
    if (!in_array(ltrim($tokens[$i], "\x01"), ['-exec', '-execdir', '-ok', '-okdir'], TRUE)) {
      continue;
    }
    $cmd = [];
    $i++;
    for (; $i < $count; $i++) {
      $word = ltrim($tokens[$i], "\x01");
      if ($word === ';' || $word === '+') {
        break;
      }
      // `{}` is where find substitutes the matched path, not an operand the
      // program names; the reach it opens is the matched files, which the
      // find tier judges. What matters here is a path the program names
      // ITSELF, like `rm -rf droost/droost-workflow`.
      if ($word === '{}') {
        continue;
      }
      $cmd[] = $tokens[$i];
    }
    if ($cmd !== []) {
      $out[] = $cmd;
    }
  }

  return $out;
}

/**
 * The invocations of a command line, plus the commands its finds `-exec`.
 *
 * A `-exec`'d program is a command in its own right and is judged as one —
 * the same per-invocation walls a top-level command meets. Appended after
 * the real invocations so the tracked `cd` has already settled.
 *
 * @param list<list<string>> $invocations
 *   The tokeniser's invocations.
 *
 * @return list<list<string>>
 *   Those, followed by every -exec'd command.
 */
function with_find_exec_commands(array $invocations): array {
  $extra = [];
  foreach ($invocations as $one) {
    $head = strtolower(basename(ltrim(operator_commands_unwrapped($one)[0] ?? '', "\x01")));
    if ($head !== 'find') {
      continue;
    }
    foreach (find_exec_commands($one) as $exec) {
      $extra[] = $exec;
      // `-exec sh -c "rm -rf droost/droost-workflow"` hands a RUNNER a command
      // line in a quoted token. The tokeniser re-scans such a token for a
      // command it PARSED — but it never parsed this one: it was assembled
      // here, out of find's own arguments, after tokenising was over. So the
      // re-scan has to happen here too. A previous round removed it as
      // "redundant" on the strength of a test that used `find .`, where the
      // find tier's own reach check answers first and hides that nothing ever
      // judged the `rm`; with `find src` the same payload deleted the state
      // directory under a verdict of ALLOW.
      foreach ($exec as $word) {
        $word = ltrim($word, "\x01");
        if (preg_match('/\s/', $word) === 1) {
          $extra = [...$extra, ...operator_commands_invocations($word)];
        }
      }
    }
  }

  return [...$invocations, ...$extra];
}

/**
 * Whether a run is actually under way, rather than merely recorded.
 *
 * `run.json` is BOTH the live record and the finished one: a completed run
 * leaves it with `current_phase: null`, which is how every reader in the
 * pack tells the two apart. Asking `is_file()` conflated them and left a
 * finished run enforcing itself forever.
 *
 * Fails CLOSED: a file that exists but cannot be read or parsed is treated
 * as live, because the alternative is that corrupting the record disarms the
 * enforcement.
 *
 * @param string $root
 *   The project root.
 * @param string $stateDir
 *   The run-state directory, relative to the root.
 *
 * @return bool
 *   TRUE while a phase is active.
 */
function guard_run_is_live(string $root, string $stateDir): bool {
  $path = $root . '/' . $stateDir . '/run.json';
  if (!is_file($path)) {
    return FALSE;
  }
  $raw = @file_get_contents($path);
  if (!is_string($raw) || trim($raw) === '') {
    return TRUE;
  }
  $document = json_decode($raw, TRUE);
  if (!is_array($document) || !array_key_exists('current_phase', $document)) {
    return TRUE;
  }

  return $document['current_phase'] !== NULL && $document['current_phase'] !== '';
}

/**
 * The operator-only verbs, as one pattern.
 *
 * Asked by the tokeniser (is this quoted payload a command line worth
 * re-scanning), by the script reader (is the verb hidden in a file) and by
 * the inline-code rule (is it hidden in a program). One function, because
 * three copies of a list is how a verb gets learned by two of them.
 *
 * @return string
 *   A PCRE pattern.
 */
function operator_verb_pattern(): string {
  return '/droost:workflow:(gate-waive|baseline|bypass|effort)\b'
    . '|(?<![\w-])(dwfgw|dwfbl|dwfby|dwfe)\b'
    . '|droost-workflow\s+(baseline|gate-waive|bypass|effort)\b'
    . '|(?:droost:gate|(?<![\w-])dgate)\b/';
}

/**
 * Whether a piece of interpreter code does something that WRITES.
 *
 * Naming an enforcement path was once enough on its own to refuse, which
 * refused READING the run record — a subject inspecting its own run with
 * `python3 -c "json.load(open('…/run.json'))"` was told it was writing the
 * enforcement. Reading the record is ordinary and the pack encourages it;
 * the attack the rule exists for is `file_put_contents(<guard>, "")`.
 *
 * A text heuristic over code, which is the shape that keeps producing false
 * positives in this file — but the alternative is refusing every read, and
 * the rule it guards is already only a wall against LITERAL spellings: a path
 * assembled from two halves slips it either way. Requiring a literal write
 * beside a literal path gives up very little and hands back every read.
 *
 * @param string $code
 *   The inline program.
 *
 * @return bool
 *   TRUE when something in it writes, renames, truncates or deletes.
 */
function code_writes(string $code): bool {
  $calls = '/\b(?:file_put_contents|fwrite|fputs|ftruncate|unlink|rename|copy|rmdir|mkdir|touch|chmod'
    . '|writeFileSync|writeFile|appendFileSync|appendFile|unlinkSync|rmSync|renameSync|createWriteStream'
    . '|remove|rmtree|truncate|write_text|write_bytes|shutil)\s*\(/i';
  if (preg_match($calls, $code) === 1) {
    return TRUE;
  }
  // `open(path, "w")` — the MODE is what makes it a write — and Perl's
  // two-argument `open(FH, ">path")`.
  if (preg_match('/open\s*\(.*,\s*[\'"][rwax+b]*[wax][rwax+b]*[\'"]/is', $code) === 1
    || preg_match('/open\s*\([^,)]*[\'"]\s*>+/s', $code) === 1) {
    return TRUE;
  }
  // Ruby's File verbs, and a redirect inside an awk program (`print > "…"`).
  if (preg_match('/File\.(?:write|delete|rename|open|new)/i', $code) === 1
    || preg_match('/>\s*[\'"]/', $code) === 1) {
    return TRUE;
  }

  // sed's `w <path>` command, which writes the pattern space to a file and is
  // how `sed -n "w…guard.php" src/a.php` overwrote this guard.
  return preg_match('/(?:^|[;{}\s])w\s*[\/.\w-]*[\/.]\S/', $code) === 1;
}

/**
 * Whether a program's text names the enforcement, the run's spec aside.
 *
 * THE SPEC IS THE AGENT'S, in code as with the Write tool (F-98). The
 * markers name the state directory whole, so a Python edit filling in the
 * spec's Verified By cells was refused as writing the enforcement, and told
 * the file was the operator's, while the Write tool and a plain `sed -i`
 * both allow it. The spec's own path is set aside before the markers are
 * asked; any other mention of the directory, or of a file in it, counts.
 *
 * @param string $code
 *   A program's text: a heredoc's body, or inline `-c`/`-r` code.
 *
 * @return bool
 *   TRUE when it names something the enforcement rests on.
 */
function code_names_enforcement(string $code): bool {
  $named = (string) preg_replace('#(droost/droost-workflow|\.droost-workflow)/(tmp-)?spec(-[A-Za-z0-9._-]+)?\.md#', '', $code);

  return preg_match(enforcement_path_markers(), $named) === 1;
}

/**
 * The pattern that says a piece of TEXT names the enforcement.
 *
 * Asked by the interpreter-code rule and by the `printf … | xargs` feeder,
 * which is the whole reason it is a function: those two started as one
 * regex copied into the second place, and a copied rule is one that will
 * eventually be true once.
 *
 * @return string
 *   A PCRE pattern.
 */
function enforcement_path_markers(): string {
  return '#droost-workflow-guard\.php|\brun\.json|\bbypass\.json|evidence\.sqlite'
    . '|tool-calls\.jsonl|guard-calls\.jsonl|scaffolded\.jsonl|\bpack\.lock'
    . '|settings\.local\.json|\bsettings\.json|settings\.droost\.php'
    . '|droost\.workflow\.yml|\.claude/hooks|\.claude/(?:skills|agents|commands)'
    . '|droost/droost-workflow|\.droost-workflow|droost/baseline#';
}

/**
 * The inline code an interpreter was handed, or NULL.
 *
 * `php -r '…'`, `perl -e '…'`, `python3 -c '…'`, `node -e '…'` run code this
 * guard cannot read as a shell command line — `php -r
 * 'file_put_contents(".claude/hooks/droost-workflow-guard.php","");'` empties
 * the guard, and a payload with no whitespace is never even re-scanned as a
 * command. Per interpreter, because the flag differs and `php -c` is a config
 * file, not code.
 *
 * @param list<string> $plain
 *   The invocation's unwrapped tokens.
 * @param string $cwd
 *   The directory the command runs in, as tracked through `cd`, so an input
 *   file is looked for where the command will look for it. Empty falls back
 *   to this process's own.
 *
 * @return string|null
 *   The code (all arguments after the flag, joined), or NULL when this is not
 *   an interpreter handed inline code.
 */
function operator_commands_inline_code(array $plain, string $cwd = ''): ?string {
  $flags = [
    'php' => ['-r'],
    'perl' => ['-e', '-E'],
    'ruby' => ['-e'],
    'python' => ['-c'],
    'python3' => ['-c'],
    'node' => ['-e', '--eval', '-p', '--print'],
  ];
  $head = strtolower(basename(ltrim($plain[0] ?? '', "\x01")));
  // AWK AND SED WRITE THROUGH THEIR OWN PROGRAMS, not through the shell.
  // `awk 'BEGIN{print > "droost/droost-workflow/run.json"}'` truncates the
  // record, and `sed -n 'w.claude/hooks/droost-workflow-guard.php' src/a.php`
  // overwrites this guard: no redirect, no destructive verb, nothing the
  // operand loop can see. Their PROGRAM is code in exactly the sense `php -r`
  // is. It is told apart from the input FILES the one way that holds without
  // parsing each dialect — a file that exists is an input, and a program is
  // not a file — so `sed 's/a/b/' droost/droost-workflow/run.json` READING
  // the record stays ordinary.
  if (in_array($head, ['awk', 'gawk', 'mawk', 'nawk', 'sed', 'ed', 'ex'], TRUE)) {
    $valued = ['-f', '-v', '-F', '-e', '--expression', '--file', '-i'];
    $program = [];
    $takesNext = FALSE;
    foreach (array_slice($plain, 1) as $token) {
      $token = ltrim($token, "\x01");
      if ($takesNext) {
        $takesNext = FALSE;
        $program[] = $token;
        continue;
      }
      if (in_array($token, $valued, TRUE)) {
        $takesNext = TRUE;
        continue;
      }
      // AGAINST THE TRACKED DIRECTORY, not this process's. The guard runs
      // from the project root; after `cd sub && sed -n '1,5p' file`, `file`
      // exists in `sub` and not here, so an input that "did not exist" was
      // read as program text — and then refused for naming a path. Proven
      // on the guard's own source: `cd <sibling> && sed -n '2040,2066p'
      // pack/hooks/droost-workflow-guard.php` was refused as code that
      // names the enforcement.
      $where = str_starts_with($token, '/')
        ? $token
        : rtrim($cwd !== '' ? $cwd : (getcwd() ?: '.'), '/') . '/' . $token;
      if ($token === '' || str_starts_with($token, '-') || is_file($where)) {
        continue;
      }
      $program[] = $token;
    }

    return $program === [] ? NULL : implode(' ', $program);
  }
  $want = $flags[$head] ?? NULL;
  if ($want === NULL) {
    return NULL;
  }
  foreach ($plain as $index => $token) {
    $token = ltrim($token, "\x01");
    if (in_array($token, $want, TRUE)) {
      $rest = array_slice($plain, $index + 1);

      return implode(' ', array_map(static fn (string $t): string => ltrim($t, "\x01"), $rest));
    }
  }

  return NULL;
}

/**
 * Whether a find invocation's own actions write.
 *
 * As opposed to the command it hands to -exec, which is judged separately.
 *
 * `-delete` removes what it matched, and `-fprint`/`-fls` and friends write
 * to a file they name themselves — `find . -fprint <guard>` truncates the
 * guard without running anything.
 *
 * @param list<string> $tokens
 *   The find invocation's tokens.
 *
 * @return bool
 *   TRUE when an action writes.
 */
function find_action_writes(array $tokens): bool {
  // `-fprint`, `-fls` and friends WRITE TO A NAMED FILE: `find . -fprint
  // .claude/hooks/droost-workflow-guard.php` truncates the guard.
  if (operator_commands_flagged($tokens, ['-delete', '-fprint', '-fprint0', '-fprintf', '-fls'])) {
    return TRUE;
  }
  $reads = [
    'cat', 'head', 'tail', 'less', 'more', 'file', 'wc', 'stat', 'ls', 'echo',
    'grep', 'egrep', 'rg', 'md5', 'shasum', 'md5sum', 'sha1sum', 'sha256sum',
    'phpcs', 'phpstan', 'psalm', 'eslint', 'stylelint', 'prettier',
    'jq', 'realpath', 'readlink', 'dirname', 'basename', 'printf',
  ];
  $interpreter = '/^(?:' . operator_commands_interpreter_words() . ')$/';
  // WHICH FLAGS ONLY INSPECT DEPENDS ON THE INTERPRETER. One list served all
  // of them, and `-i` — php's phpinfo — is perl's and ruby's EDIT IN PLACE:
  // `find .claude/hooks -name …guard.php
  // -exec perl -i -pe 's/.*/<?php exit(0);/' {} +`
  // was a read, and rewrote the guard into a stub that permits everything.
  // Meanwhile `perl -c` (a syntax check) was a write, because `-c` is sh's
  // "run this string". An interpreter not listed here inspects nothing: every
  // argument makes it a write.
  $inspectOnly = [
    'php' => ['-l', '-v', '--version', '-i', '-m', '--syntax-check', '-h', '--help'],
    'perl' => ['-c', '-v', '-V', '--version', '-h'],
    'ruby' => ['-c', '-v', '--version', '-h'],
    'python' => ['-V', '--version', '-h', '--help'],
    'python3' => ['-V', '--version', '-h', '--help'],
    'node' => ['--check', '-c', '-v', '--version', '-h', '--help'],
    'sh' => ['-n', '--version', '--help'],
    'bash' => ['-n', '--version', '--help'],
    'zsh' => ['-n', '--version', '--help'],
    'dash' => ['-n'],
    'ksh' => ['-n'],
  ];
  foreach ($tokens as $index => $token) {
    if (!in_array(ltrim($token, "\x01"), ['-exec', '-execdir', '-ok', '-okdir'], TRUE)) {
      continue;
    }
    $program = strtolower(basename(ltrim($tokens[$index + 1] ?? '', "\x01")));
    if ($program === '') {
      return TRUE;
    }
    // The program's own arguments, up to find's terminator.
    $arguments = [];
    for ($next = $index + 2; $next < count($tokens); $next++) {
      $argument = ltrim($tokens[$next], "\x01");
      if ($argument === ';' || $argument === '+') {
        break;
      }
      $arguments[] = $argument;
    }
    if (preg_match($interpreter, $program) === 1) {
      if (!in_array(strtolower($arguments[0] ?? ''), $inspectOnly[$program] ?? [], TRUE)) {
        return TRUE;
      }
      continue;
    }
    if (!in_array($program, $reads, TRUE)) {
      return TRUE;
    }
    // `-exec vendor/bin/phpcs --report-file=<guard> {} +` is phpcs writing a
    // file, and phpcs is on the read list.
    foreach ($arguments as $argument) {
      if (operator_commands_write_flag($argument, $program)) {
        return TRUE;
      }
    }
  }

  return FALSE;
}

/**
 * Whether one argument is a flag that names a file to WRITE.
 *
 * `git diff --output=<path>` truncates and fills its target, `phpcs
 * --report-file=<path>` does the same, `eslint --fix` and `prettier --write`
 * rewrite what they are handed. One function, asked by the shell tier for a
 * bare command's arguments and by the find tier for an `-exec` program's, so
 * that a flag learned by one is learned by both.
 *
 * @param string $token
 *   One argument.
 * @param string $command
 *   The program the argument belongs to, when known. `-o` is a destination
 *   for `curl`, `sort`, `gcc` and `wget -O`, and is "only the matching part"
 *   for every grep — so `grep -o '…' run.json` was refused as WRITING the
 *   record. The searchers never write through `-o`.
 *
 * @return bool
 *   TRUE when it makes its command write.
 */
function operator_commands_write_flag(string $token, string $command = ''): bool {
  if (preg_match('/^-[oO](=|$)/', $token) === 1
    && in_array(strtolower(basename(ltrim($command, "\x01"))), ['grep', 'egrep', 'fgrep', 'rg', 'ag', 'ack'], TRUE)) {
    return FALSE;
  }

  return preg_match(
    '/^(--output|--out|--outfile|--write|--dest|--destination|--report-file|--fix|-o|-O)(=|$)/i',
    $token,
  ) === 1;
}

/**
 * The directories whose loss or move disarms the enforcement.
 *
 * One list, because it was two — the destructive-verb tier and the xargs
 * feeder check both need it, and a directory added to one copy and not the
 * other is the exact drift this project keeps paying for.
 *
 * @param string $stateDir
 *   The resolved state directory, included so a project on the legacy layout
 *   protects its own.
 *
 * @return list<string>
 *   Project-relative directory paths.
 */
function enforcement_protected_dirs(string $stateDir): array {
  return array_values(array_unique([
    'droost/droost-workflow',
    '.droost-workflow',
    'droost/baseline',
    '.claude/hooks',
    '.claude',
    'droost',
    trim($stateDir, '/'),
  ]));
}

/**
 * A protected path the command feeding a pipe would reach, or ''.
 *
 * `xargs <writer>` runs a program over names this guard never sees, so the
 * only thing it can judge is the producer on the other side of the pipe —
 * and it judges it exactly as the operand loop judges that producer standing
 * alone. A `find` is judged by its filters (`find_reaches_enforcement()`).
 * Anything else is judged by its own operands: each is brace-expanded and,
 * where it globs, expanded against the disk, then asked whether it names a
 * protected file or a protected directory. `ls *.log` reaches nothing;
 * `ls .claude/hooks/*` reaches the guard; `ls .claude/hooks` names a
 * protected directory. A producer that names nothing protected is ordinary,
 * whatever it is — the blanket "not a find, therefore refused" it replaced
 * blocked `ls *.log | xargs rm`, which is cleanup, not an attack.
 *
 * @param list<string> $plain
 *   The producer's unwrapped tokens.
 * @param string $cwd
 *   The tracked working directory.
 * @param string $root
 *   The project root.
 * @param string $stateDir
 *   The resolved state directory.
 *
 * @return string
 *   The first protected path reached, or '' when none is.
 */
function pipe_feeder_reaches(array $plain, string $cwd, string $root, string $stateDir): string {
  $head = strtolower(basename(ltrim($plain[0] ?? '', "\x01")));
  if ($head === 'find') {
    $reached = find_reaches_enforcement($plain, $cwd, $root, $stateDir);
    return $reached[0] ?? '';
  }
  // `printf`/`echo` FEED TEXT, not paths this loop can resolve. `printf
  // ".claude/hooks/%s" droost-workflow-guard.php | xargs rm` deleted this
  // guard: the operands are a format and its argument, and neither is a path
  // until printf has joined them. What reaches xargs is the TEXT, so the text
  // is what gets asked.
  if (in_array($head, ['printf', 'echo'], TRUE)) {
    $text = implode(' ', array_map(
      static fn (string $t): string => ltrim($t, "\x01"),
      array_slice($plain, 1),
    ));
    if (preg_match(enforcement_path_markers(), $text) === 1) {
      return trim($text);
    }
  }
  $protectedDirs = enforcement_protected_dirs($stateDir);
  foreach (array_slice($plain, 1) as $operand) {
    $operand = ltrim($operand, "\x01");
    if ($operand === '' || str_starts_with($operand, '-')) {
      continue;
    }
    foreach (operator_commands_brace_branches($operand) as $branch) {
      $absolute = str_starts_with($branch, '/') ? $branch : $cwd . '/' . $branch;
      $candidates = preg_match('/[*?\[]/', $branch) === 1 ? (glob($absolute) ?: []) : [];
      $candidates[] = $absolute;
      foreach ($candidates as $candidate) {
        if (enforcement_refusal($candidate, $root, $stateDir) !== '') {
          return $candidate;
        }
        $landing = resolved_relative($candidate, $root);
        if ($landing === '') {
          $spelled = rtrim(normalised_path($candidate), '/');
          $rootPath = normalised_path($root);
          $landing = str_starts_with($spelled, $rootPath)
            ? trim(substr($spelled, strlen($rootPath)), '/')
            : $spelled;
        }
        if (in_array($landing, $protectedDirs, TRUE)) {
          return $landing;
        }
      }
    }
  }

  return '';
}

/**
 * The protected files that really exist under this project.
 *
 * The find tier used to PREDICT reach from a hand-written list of twelve
 * basenames — a second, narrower copy of what `enforcement_refusal_for()`
 * and the tree rule protect. It had `phpcs` and not `eslint`, the guard's
 * name and not the briefs, and no path form at all, so `rm vendor/bin/eslint`
 * was refused while `find vendor/bin -name eslint -delete` was permitted.
 * Same target, two answers, and the commit that wrote the list was titled
 * for the pattern it was repeating.
 *
 * So there is no list. The trees the tree rule names are walked, and every
 * file under one it protects is protected; the standalone files are asked of
 * `enforcement_refusal_for()` one by one. Whatever those two rules learn,
 * this learns.
 *
 * @param string $root
 *   The project root.
 * @param string $stateDir
 *   The resolved state directory.
 *
 * @return list<string>
 *   Project-relative paths of files whose loss or rewrite disarms something.
 */
function enforcement_protected_files(string $root, string $stateDir): array {
  $root = rtrim($root, '/');
  $found = [];
  $budget = 3000;
  $walk = static function (string $relative, int $depth) use (&$walk, &$found, &$budget, $root): void {
    if ($depth > 5 || $budget <= 0) {
      return;
    }
    foreach ((array) @scandir($root . '/' . $relative) as $entry) {
      if (!is_string($entry) || $entry === '.' || $entry === '..' || --$budget <= 0) {
        continue;
      }
      $path = $relative . '/' . $entry;
      if (is_dir($root . '/' . $path) && !is_link($root . '/' . $path)) {
        $walk($path, $depth + 1);
        continue;
      }
      $found[] = $path;
    }
  };
  // The trees. Asked of the rule, once per tree: a tree the rule protects is
  // protected all the way down, which is what the rule says.
  // A WHOLE TREE, or a file the rule names. `droost/droost-workflow`,
  // `.droost-workflow`, `droost/baseline` and the state dir ARE enforcement
  // top to bottom — every file in them is the run's own record or the
  // baseline, so any one is protected. `.claude`, `vendor/bin` and
  // `node_modules/.bin` are NOT protected wholesale: only the guard, the
  // settings and the briefs under `.claude`, only the executables under the
  // bin dirs. Walking `.claude` whole (the old test: "does this tree ever
  // fire the wildcard rule") declared every file under it enforcement, so
  // `find .claude/hooks -name my-hook.sh -delete` was refused while `rm
  // .claude/hooks/my-hook.sh` was allowed — the find tier over-protecting
  // where its own message promises it asks the rule `rm` is held to. Each
  // walked file is now included only when it is in a whole-state tree OR
  // `enforcement_refusal_for()` names it — which IS that rule.
  $wholeTrees = array_values(array_filter([
    'droost/droost-workflow', '.droost-workflow', 'droost/baseline', trim($stateDir, '/'),
  ], static fn (string $t): bool => $t !== ''));
  $include = static function (string $relative) use ($wholeTrees, $root, $stateDir): bool {
    foreach ($wholeTrees as $tree) {
      if ($relative === $tree || str_starts_with($relative, $tree . '/')) {
        return TRUE;
      }
    }
    return enforcement_refusal_for($relative, $root, $stateDir) !== '';
  };
  foreach (array_unique([...$wholeTrees, '.claude', 'vendor/bin', 'node_modules/.bin']) as $tree) {
    if ($tree === '' || !is_dir($root . '/' . $tree)) {
      continue;
    }
    $walk($tree, 0);
  }
  $found = array_values(array_filter($found, $include));
  // The standalone files, wherever the rule places them.
  $candidates = ['droost.workflow.yml'];
  foreach (glob($root . '/{web/,docroot/,}sites/*/settings*.php', GLOB_BRACE) ?: [] as $absolute) {
    $candidates[] = ltrim(substr($absolute, strlen($root)), '/');
  }
  foreach ($candidates as $relative) {
    if (is_file($root . '/' . $relative) && enforcement_refusal_for($relative, $root, $stateDir) !== '') {
      $found[] = $relative;
    }
  }

  return array_values(array_unique($found));
}

/**
 * The protected files a `find` with a writing action would actually reach.
 *
 * Find applies its expression LEFT TO RIGHT, and an action acts on whatever
 * has passed the tests before it. So `find . -name "*.tmp" -delete` deletes
 * only `.tmp` files, and `find . -delete -name "*.tmp"` deletes everything —
 * the filter after the action constrains nothing. The old test asked "could
 * any filter anywhere match a protected name" and a decoy `-name "nomatch"`
 * placed after `-delete` answered no. Proven: `find . -delete -name
 * "nomatch"` deleted the guard and every other file under `.`.
 *
 * What is modelled: a flat AND-chain of tests before the first action, over
 * the protected files that really sit under each search path, tested in the
 * form find itself would print them (`./droost/droost-workflow/run.json` for
 * a search rooted at `.`). `-name` matches the basename and `-path`/`-regex`
 * the whole path, exactly as find does. What is NOT modelled fails closed:
 * `-o`, `!`, parentheses and any test this does not know leave every
 * candidate in reach, because a guard that guesses at find's grammar is the
 * guard that permitted the decoy.
 *
 * find's GLOBAL options come before the search paths — `find -L . …` — and
 * the old loop broke on the first `-`, leaving no search path at all to
 * judge. A round-six regression, proven to delete the guard.
 *
 * @param list<string> $tokens
 *   The invocation's tokens.
 * @param string $cwd
 *   The tracked working directory the search paths are relative to.
 * @param string $root
 *   The project root.
 * @param string $stateDir
 *   The resolved state directory.
 *
 * @return list<string>
 *   Project-relative protected paths in reach, or empty.
 */
function find_reaches_enforcement(array $tokens, string $cwd, string $root, string $stateDir): array {
  $words = array_map(static fn (string $token): string => ltrim($token, "\x01"), $tokens);
  $count = count($words);

  // Global options first, then the search paths.
  $index = 1;
  $searchPaths = [];
  for (; $index < $count; $index++) {
    $word = $words[$index];
    if (preg_match('/^-(H|L|P|E|X|d|s|x|O\d?)$/', $word) === 1) {
      continue;
    }
    if ($word === '-D') {
      $index++;
      continue;
    }
    if ($word === '-f') {
      $searchPaths[] = $words[++$index] ?? '';
      continue;
    }
    if ($word === '--') {
      continue;
    }
    if (str_starts_with($word, '-') || $word === '!' || $word === '(' || $word === ',') {
      break;
    }
    $searchPaths[] = $word;
  }
  if ($searchPaths === []) {
    $searchPaths = ['.'];
  }

  // The expression: name filters before the first action, or a shape this
  // cannot model.
  $filters = [];
  $modelled = TRUE;
  $zeroArity = '/^-(a|and|depth|d|daystart|empty|executable|false|follow|mount|noleaf'
    . '|nogroup|nouser|readable|true|writable|xdev|print|print0|ls|quit|prune'
    . '|ignore_readdir_race|noignore_readdir_race|nowarn|warn|H|L|P)$/';
  $oneArity = '/^-(amin|anewer|atime|cmin|cnewer|ctime|fstype|gid|group|inum|links'
    . '|maxdepth|mindepth|mmin|mtime|newer|perm|printf|regextype|samefile|size'
    . '|type|uid|used|user|xtype|context|Bmin|Bnewer|Btime|flags|lname|ilname'
    . '|newer[aBcmt][aBcmt])$/';
  for (; $index < $count; $index++) {
    $word = $words[$index];
    if (in_array($word, ['-delete', '-exec', '-execdir', '-ok', '-okdir', '-fprint', '-fprint0', '-fprintf', '-fls'], TRUE)) {
      break;
    }
    if (preg_match('/^-(i?name|i?path|i?wholename|i?regex)$/', $word) === 1) {
      $filters[] = [$word, $words[++$index] ?? ''];
      continue;
    }
    if (preg_match($zeroArity, $word) === 1) {
      continue;
    }
    if (preg_match($oneArity, $word) === 1) {
      $index++;
      continue;
    }
    $modelled = FALSE;
    break;
  }

  $protected = enforcement_protected_files($root, $stateDir);
  if ($protected === []) {
    return [];
  }
  $rootReal = realpath($root);
  $rootReal = $rootReal === FALSE ? rtrim($root, '/') : $rootReal;

  $reached = [];
  foreach ($searchPaths as $where) {
    if ($where === '') {
      continue;
    }
    $real = realpath(str_starts_with($where, '/') ? $where : $cwd . '/' . $where);
    if ($real === FALSE) {
      continue;
    }
    if ($real === $rootReal) {
      $under = '';
    }
    elseif (str_starts_with($real, $rootReal . '/')) {
      $under = substr($real, strlen($rootReal) + 1);
    }
    else {
      continue;
    }
    foreach ($protected as $relative) {
      if ($under !== '' && $relative !== $under && !str_starts_with($relative, $under . '/')) {
        continue;
      }
      // The path as find prints it: the search path as typed, then the rest.
      $printed = $relative === $under
        ? $where
        : rtrim($where, '/') . '/' . ($under === '' ? $relative : substr($relative, strlen($under) + 1));
      if ($modelled && !find_filters_match($filters, $printed)) {
        continue;
      }
      $reached[] = $relative;
    }
  }

  return array_values(array_unique($reached));
}

/**
 * Whether every one of find's name filters matches a printed path.
 *
 * `-name` against the basename, `-path`/`-wholename` against the whole path,
 * both with `fnmatch()` and no FNM_PATHNAME so that `*` crosses `/` exactly
 * as find's does; `-regex` anchored to the whole path. The `-i` forms fold
 * case. An expression this guard cannot compile is one it cannot judge, and
 * counts as a match.
 *
 * @param list<array{0: string, 1: string}> $filters
 *   Flag and pattern pairs.
 * @param string $printed
 *   The path in find's own form.
 *
 * @return bool
 *   TRUE when the path passes every filter.
 */
function find_filters_match(array $filters, string $printed): bool {
  foreach ($filters as [$flag, $pattern]) {
    $insensitive = str_starts_with($flag, '-i');
    if (str_ends_with($flag, 'regex')) {
      $delimited = '#^(?:' . str_replace('#', '\#', $pattern) . ')$#' . ($insensitive ? 'i' : '');
      $result = @preg_match($delimited, $printed);
      if ($result === FALSE) {
        continue;
      }
      if ($result !== 1) {
        return FALSE;
      }
      continue;
    }
    $subject = str_ends_with($flag, 'name') && !str_ends_with($flag, 'wholename')
      ? basename($printed)
      : $printed;
    if (!guard_fnmatch($pattern, $subject, $insensitive)) {
      return FALSE;
    }
  }

  return TRUE;
}

/**
 * Shell-glob match, on every platform this guard might run on.
 *
 * `fnmatch()` IS NOT ALWAYS THERE — which is why Drupal's own standard
 * discourages it, and why that warning mattered here more than it does in
 * ordinary code. This file is an autoloader-free hook whose contract is
 * "exit 2 refuses, 0 allows, ANYTHING ELSE is a crash the host reads as
 * allow". A call to a function the build does not define is a fatal Error, so
 * on such a platform every `find -name` reach check would have failed OPEN —
 * the one direction an enforcement must never fail in.
 *
 * The fallback translates the glob itself: `*` and `?` become their regex
 * equivalents and cross `/` (no FNM_PATHNAME, matching the call site), a
 * bracket expression becomes a character class, `\` escapes the next
 * character as fnmatch's default does, and everything else is quoted.
 *
 * @param string $pattern
 *   The shell glob.
 * @param string $subject
 *   The string to match.
 * @param bool $insensitive
 *   Whether to fold case (find's `-iname` and friends).
 *
 * @return bool
 *   TRUE when the glob matches.
 */
function guard_fnmatch(string $pattern, string $subject, bool $insensitive): bool {
  if (function_exists('fnmatch')) {
    return fnmatch($pattern, $subject, $insensitive ? FNM_CASEFOLD : 0);
  }
  $regex = '';
  $length = strlen($pattern);
  for ($index = 0; $index < $length; $index++) {
    $char = $pattern[$index];
    if ($char === '\\' && $index + 1 < $length) {
      $regex .= preg_quote($pattern[++$index], '#');
      continue;
    }
    if ($char === '*') {
      $regex .= '.*';
      continue;
    }
    if ($char === '?') {
      $regex .= '.';
      continue;
    }
    if ($char === '[') {
      $close = strpos($pattern, ']', $index + 1);
      // AN UNTERMINATED BRACKET MATCHES NOTHING — fnmatch's grammar, not an
      // edge case worth guessing at. Quoting the `[` as a literal instead
      // made `fnmatch('x[', 'x[')` answer TRUE here and FALSE there, which a
      // differential run over 756 pattern/subject pairs caught. Two
      // implementations of one rule that disagree is the defect this whole
      // codebase keeps paying for; the fallback follows fnmatch or it is not
      // a fallback.
      if ($close === FALSE) {
        return FALSE;
      }
      $class = substr($pattern, $index + 1, $close - $index - 1);
      // `!` is the shell's negation; `^` is the regex's.
      if (str_starts_with($class, '!')) {
        $class = '^' . substr($class, 1);
      }
      $regex .= '[' . str_replace('#', '\#', $class) . ']';
      $index = $close;
      continue;
    }
    $regex .= preg_quote($char, '#');
  }
  return @preg_match('#^' . $regex . '$#' . ($insensitive ? 'i' : ''), $subject) === 1;
}

/**
 * Whether a directory is a real repository boundary.
 *
 * A `.git` DIRECTORY always is. A `.git` FILE is one only when it points at a
 * git directory that exists — which is what git itself requires of a worktree
 * or a submodule, and what a planted file does not have.
 *
 * @param string $directory
 *   The directory being tested.
 *
 * @return bool
 *   TRUE when the walk should stop here.
 */
function git_boundary(string $directory): bool {
  $dot = rtrim($directory, '/') . '/.git';
  // NOT A SYMLINK. `ln -s /tmp web/modules/custom/.git` made `is_dir` true and
  // moved the project root; git does not accept a symlinked `.git` as a
  // repository either, so refusing it costs nothing real.
  if (is_link($dot)) {
    return FALSE;
  }
  if (is_dir($dot)) {
    return git_directory($dot);
  }
  if (!is_file($dot)) {
    return FALSE;
  }
  $head = (string) @file_get_contents($dot, FALSE, NULL, 0, 4096);
  if (preg_match('/^gitdir:\s*(\S.*?)\s*$/m', $head, $match) !== 1) {
    return FALSE;
  }
  $target = $match[1];
  $target = str_starts_with($target, '/') ? $target : rtrim($directory, '/') . '/' . $target;

  return git_directory($target);
}

/**
 * Whether a directory is actually a git directory.
 *
 * `is_dir` alone accepted `gitdir: /tmp` — any path that happens to exist —
 * so the round that required the target to exist was satisfied by naming
 * `/tmp`. A git directory holds `HEAD` and an object store; checking for those
 * two costs two stats and is what git itself looks for.
 *
 * @param string $directory
 *   The candidate.
 *
 * @return bool
 *   TRUE when it looks like a git directory.
 */
function git_directory(string $directory): bool {
  $path = rtrim($directory, '/');

  return is_file($path . '/HEAD')
    && (is_dir($path . '/objects') || is_dir($path . '/refs') || is_file($path . '/config'));
}

/**
 * The refusal for a wildcard aimed at a directory.
 *
 * A glob names no file, so `enforcement_refusal()` has nothing to judge: `rm
 * .claude/hooks/*` reached the protected-path check as the literal string `*`
 * and passed it, and the shell then deleted the guard. This asks the only
 * question that can be asked before expansion — does this directory hold, or
 * sit inside, something the pipeline rests on?
 *
 * Both directions matter. `rm .claude/hooks/*` aims INSIDE a protected
 * directory; `rm -r droost/*` aims at one that CONTAINS two of them. Either
 * way the expansion reaches enforcement, so either way this refuses and asks
 * for the name instead.
 *
 * @param string $directory
 *   The absolute directory the wildcard sits in.
 * @param string $root
 *   The project root.
 * @param string $stateDir
 *   The resolved state directory.
 *
 * @return string
 *   A refusal message, or '' when nothing protected is in reach.
 */
function wildcard_directory_refusal(string $directory, string $root, string $stateDir): string {
  $rootPath = normalised_path($root);
  $path = normalised_path($directory);
  $relative = str_starts_with($path, $rootPath)
    ? trim(substr($path, strlen($rootPath)), '/')
    : $path;
  $landing = resolved_relative($directory, $root);
  if ($landing !== '') {
    $landing = trim($landing, '/');
  }

  // Protected as a TREE: everything under these is enforcement, so a wildcard
  // that reaches them from ABOVE is refused too. `rm -r droost/*` takes the run
  // record and the baseline without naming either.
  $trees = [
    '.claude/hooks' => 'the hook that IS this enforcement',
    'droost/droost-workflow' => 'the run\'s own record, its evidence store and the bypass grant',
    '.droost-workflow' => 'the run\'s own record, its evidence store and the bypass grant',
    'droost/baseline' => 'the operator\'s adoption baseline',
  ];
  $stateRelative = trim($stateDir, '/');
  if ($stateRelative !== '' && !isset($trees[$stateRelative])) {
    $trees[$stateRelative] = 'the run\'s own record and its evidence store';
  }
  // Protected as FILES ONLY. `vendor/bin/phpcs` is a verdict; `vendor/` is an
  // ordinary tree that `composer install` rewrites, and `rm -rf node_modules`
  // before `npm ci` is a thing people do every day. So a wildcard INSIDE these
  // is refused and one above them is not — which is the same rule the
  // single-file check already applies, said once more for the glob.
  $files = [
    '.claude' => 'the hook and the settings that wire this enforcement in',
    'vendor/bin' => 'the gate executables whose exit codes are the verdicts',
    'node_modules/.bin' => 'the gate executables whose exit codes are the verdicts',
  ];

  foreach ([$relative, $landing] as $candidate) {
    if ($candidate === '.') {
      $candidate = '';
    }
    foreach ($trees + $files as $dir => $what) {
      if ($candidate !== '' && ($candidate === $dir || str_starts_with($candidate, $dir . '/'))) {
        return sprintf(
          'A shell command in this run expands a wildcard inside `%s`, which holds %s. '
          . 'The shell expands it; this guard sees a `*` and never the names it '
          . 'becomes, so it cannot tell a tidy-up from the one move that removes '
          . 'the enforcement. Name the file you mean.',
          $dir,
          $what,
        );
      }
    }
    foreach ($trees as $dir => $what) {
      if (str_starts_with($dir . '/', $candidate === '' ? '' : $candidate . '/')) {
        return sprintf(
          'A shell command in this run expands a wildcard across `%s`, which holds %s. '
          . 'The shell expands it; this guard sees a `*` and never the names it '
          . 'becomes, so it cannot tell a tidy-up from the one move that removes '
          . 'the enforcement. Name what you mean, or work below the directory '
          . 'that holds the run.',
          $candidate === '' ? 'the project root' : $candidate,
          $what,
        );
      }
    }
  }

  return '';
}

/**
 * Refuses any agent edit under droost/baseline/, run or no run.
 *
 * The adoption baseline says which findings are inherited and which are new
 * for every later run. It is measured and written by the operator's command
 * from a terminal — never typed, never edited by hand, and never by an
 * agent, whose edit there is the one move that makes its own finding
 * disappear. A run in flight would also fail every consulting gate the
 * moment the directory's hash moved; refusing here says why first.
 *
 * @param string $stdin
 *   The hook payload, read once by the caller.
 */
function baseline_dir_guard(string $stdin, string $root, string $stateDir): void {
  $payload = json_decode($stdin, TRUE);
  $payload = is_array($payload) ? $payload : [];
  $input = is_array($payload['tool_input'] ?? NULL) ? $payload['tool_input'] : [];
  $file = $input['file_path'] ?? ($input['notebook_path'] ?? '');
  $file = is_string($file) ? $file : '';
  if ($file === '') {
    return;
  }
  // The enforcement itself comes first: a guard an agent can rewrite is not a
  // guard, and neither is a settings file it can blank.
  $refusal = enforcement_refusal($file, $root, $stateDir);
  if ($refusal !== '') {
    guard_refuse('protected-path:editor', sprintf('%s (Refused: %s)', $refusal, $file));
  }
  // The evidence store belongs here for the same reason the baseline does: it
  // is the run's own record, and a record the subject can edit is not evidence.
  // EvidenceStore's docblock says "rows droost wrote and the agent cannot", and
  // EvaluationReport tells its reader run.json is untrustworthy BECAUSE the
  // agent can write it and the store is different. Both were false as shipped:
  // in plan the store was allowed by the state-dir exemption, and in every
  // other phase pre-tool-use returned before any file check ran.
  $normalised = normalised_path($file);
  $isStore = preg_match('#(^|/)(droost/droost-workflow|\.droost-workflow)/evidence\.sqlite(-wal|-shm|-journal)?$#', $normalised) === 1;
  if (preg_match('#(^|/)droost/baseline(/|$)#', $normalised) !== 1 && !$isStore) {
    return;
  }
  if ($isStore) {
    guard_refuse('protected-path:evidence-store', sprintf(
      'The evidence store is the run\'s own record of what droost measured — '
      . 'gate verdicts, which citations resolved, which tools were called. It is '
      . 'written by droost and never by hand, and a record its subject can edit '
      . 'proves nothing about its subject. If a verdict in it is wrong, fix the '
      . 'thing it measured and let the gate run again; if the store itself is '
      . 'broken, the OPERATOR clears it with: drush droost:workflow:reset --force '
      . '(Refused: %s)',
      $file,
    ));
  }
  guard_refuse('protected-path:baseline', sprintf(
    'droost/baseline/ is the OPERATOR\'s adoption record — it is written by '
    . '`drush droost:workflow:baseline` (or `droost-workflow baseline`) from '
    . 'their terminal and never edited by hand or by an agent. If debt was '
    . 'paid, ask the operator to run `droost:workflow:baseline --refresh`; if '
    . 'new debt must be accepted, `--refresh --grow --reason="…"`. '
    . '(Refused: %s)',
    $file,
  ));
}

/**
 * Refuses ungoverned custom-code edits when no run is ACTIVE (require_run).
 *
 * The one gap "no run, no opinion" leaves open: an agent quietly building
 * outside the pipeline. This closes it, and ONLY it — pre-tool-use, and only
 * writes into custom-code territory (modules/custom, themes/custom). Docs,
 * config outside those trees, non-Drupal files and the spec never trip it, so
 * the wall rarely fires on non-build work.
 *
 * "No active run" includes an ENDED one: a completed or failed run.json is
 * history, not law, and an unreadable one is not a run at all — all of those
 * paths land here, so finishing ticket A never leaves ticket B ungoverned and
 * corrupting run.json is not a self-disarm.
 *
 * The level lives in droost.workflow.yml (require_run: hard|soft|off), read
 * here with a dependency-free regex because the hook cannot boot Drupal;
 * absent or unreadable defaults to hard, because building is exactly where the
 * pipeline must engage. hard blocks (exit 2) and names the two ways forward —
 * start a run, or take an OPERATOR-granted bypass; soft nudges once; off is
 * silent. The bypass is the operator's to grant (drush droost:workflow:bypass),
 * never the agent's — that is what keeps "chose not to use it" from returning.
 *
 * @param string $root
 *   The project root (cwd).
 * @param string $mode
 *   The hook mode; only 'pre-tool-use' acts.
 * @param string $stdin
 *   The hook payload, read once by the caller.
 */
function require_run_guard(string $root, string $mode, string $stdin, string $stateDir): void {
  if ($mode !== 'pre-tool-use') {
    return;
  }
  $level = 'hard';
  $lever = $root . '/droost.workflow.yml';
  // Optional quotes: the lever parser reads "off" and off as the same value,
  // so the regex must too — or the hook enforces hard while status says off.
  if (is_file($lever)
    && preg_match('/^require_run:\s*["\']?(hard|soft|off)\b["\']?/m', (string) file_get_contents($lever), $m) === 1) {
    $level = $m[1];
  }
  if ($level === 'off') {
    return;
  }
  $payload = json_decode($stdin, TRUE);
  $payload = is_array($payload) ? $payload : [];
  $input = is_array(($payload['tool_input'] ?? NULL)) ? $payload['tool_input'] : [];
  $file = $input['file_path'] ?? ($input['notebook_path'] ?? '');
  $file = is_string($file) ? $file : '';
  if ($file === '') {
    return;
  }
  // The narrow boundary: only custom-code territory is "build work".
  // Normalized first — separators, ./ and // collapsed, case folded (APFS
  // resolves Modules/Custom to the same directory) — so a cosmetic spelling
  // of the same path cannot slip past the wall.
  $path = strtolower(str_replace('\\', '/', $file));
  $path = (string) preg_replace(['#/(?:\./)+#', '#//+#'], '/', $path);
  // PROFILES TOO. The gate executor has always analysed `profiles/custom`
  // as the project's own code (DRUPAL_OWN_TREES), and this wall did not
  // cover it — so an install profile, which is PHP that builds the whole
  // site, could be written with no run at all while the module beside it
  // could not. Three lists said "custom code" and one of them meant
  // something narrower.
  if (preg_match('#(^|/)(modules|themes|profiles)/custom/#', $path) !== 1) {
    return;
  }
  // An operator-granted bypass stands the wall down; its visibility lives in
  // drush droost:workflow:status, so the hook allows silently rather than
  // narrating every edit. Only the operator's command writes reason and
  // granted_at — a hand-rolled or corrupt marker is not a grant.
  // Resolve the state dir the same way the engine does — the visible
  // THE RESOLVED state directory, handed in. This recomputed it by which
  // directory EXISTS — the rule the guard's own resolution stopped using, and
  // which fix #4 updated in one place and not this one. So on a legacy project
  // a single `mkdir -p droost/droost-workflow` pointed this at an empty
  // directory and the operator's recorded grant in `.droost-workflow/` stopped
  // being honoured: the wall turned back ON over a decision a human had made
  // and the record still held. Refusing work somebody authorised is the
  // failure that gets a guard switched off.
  $bypass = $root . '/' . $stateDir . '/bypass.json';
  $grant = is_file($bypass)
    ? json_decode((string) file_get_contents($bypass), TRUE)
    : NULL;
  if (is_array($grant)
    && is_string($grant['reason'] ?? NULL) && $grant['reason'] !== ''
    && is_string($grant['granted_at'] ?? NULL) && $grant['granted_at'] !== '') {
    return;
  }
  // A RUN THAT ENDED IS NOT THE SAME AS NO RUN, and this said the same thing
  // about both. On a run whose phase exhausted its budget, the two options it
  // names are both dead ends: `/droost:workflow:start` refuses to clobber an
  // existing run.json, and the bypass is the operator's. What actually works
  // is `reset --force`, which the agent IS allowed to run and which this
  // message never mentioned — so an agent following the instructions had
  // nowhere to go, and one ignoring them did.
  $ended = is_file($root . '/' . $stateDir . '/run.json');
  $message = $ended
    ? sprintf(
      'droost workflow: "%s" is custom code, and this run has ENDED — its '
      . 'record is still here but no phase is open, so there is nothing to '
      . 'build inside. Starting a run will not help while that record stands: '
      . 'clear it first with `droost-workflow reset --force` (it archives the '
      . 'record under history/ rather than discarding it), then start the next '
      . 'run and write its spec. If instead this edit genuinely belongs outside '
      . 'the pipeline, that is the OPERATOR\'s call: show them '
      . '`drush droost:workflow:bypass "<reason>"` and let them run it.',
      $file,
    )
    : sprintf(
      'droost workflow: "%s" is custom code and there is no active run. Building '
      . 'is governed by the pipeline. Do ONE of: (1) start a run with '
      . '/droost:workflow:start — write the spec, then build inside the run; or '
      . '(2) if this is a deliberate one-off, ask the OPERATOR to grant a bypass '
      . 'with: drush droost:workflow:bypass "<reason>". Do NOT retry this edit or '
      . 'grant the bypass yourself — surface the choice to the operator.',
      $file,
    );
  if ($level === 'hard') {
    // The one an evaluator most wants a number for: it is the moment the
    // pipeline actually stopped ungoverned work.
    guard_refuse('require-run', $message);
  }
  // soft: nudge once, then allow.
  //
  // THE MARKER GOES WHERE THE RUN'S RECORD GOES. This spelled
  // `.droost-workflow` — the LEGACY hidden directory — and created it, on a
  // project that may never have had one. That is not a stray dotfile: the
  // whole layout follows it. `RunStateStore::resolve()` and this file's own
  // walk both answer "legacy when it exists and the visible one does not", so
  // after a single nudge a project that had begun no run was permanently on
  // the hidden layout. Driven end to end on a fresh repo: the same repo, same
  // config, nudged and un-nudged, answers
  //
  //   no spec found under .droost-workflow       (nudged)
  //   no spec found under droost/droost-workflow (not nudged)
  //
  // and the record, the evidence store and the bypass grant follow the spec.
  // A warning that relocates the thing it is warning about is worse than no
  // warning, and D57 moved this directory into sight on purpose.
  //
  // $stateDir is what the resolver already decided, so guard and engine cannot
  // disagree and nothing is created anywhere the engine would not have.
  $GLOBALS['workflow_guard_verdict'] = 'nudge';
  $GLOBALS['workflow_guard_rule'] = 'require-run:soft';
  $marker = $root . '/' . $stateDir . '/.guard-warned-require-run';
  if (!is_file($marker)) {
    @mkdir($root . '/' . $stateDir, 0777, TRUE);
    @touch($marker);
    echo json_encode(['systemMessage' => $message . ' (require_run is soft: allowing this edit.)']);
  }
}

/**
 * The checks that stop this phase ending, read from the evidence store.
 *
 * Bare PDO, no autoloader — this file has none and must never acquire one. It
 * is copied into a project's .claude/hooks and runs as a standalone script on
 * whatever PHP the editor invokes.
 *
 * Fails OPEN in every direction: no extension, no database, an older schema, a
 * locked file, a query that throws. A hook that hardened because its record was
 * missing would turn a storage problem into an agent that cannot end a turn,
 * and what it falls back to is the message this has always printed.
 *
 * @param string $root
 *   The project root.
 * @param string $stateDir
 *   The resolved run-state directory, project-relative.
 * @param mixed $runId
 *   The run id, as run.json carries it.
 * @param string $phase
 *   The phase now open.
 *
 * @return array<int, array<string, string>>
 *   One row per unresolved check: name, fault, summary, remedy.
 */
function unresolved_checks(string $root, string $stateDir, mixed $runId, string $phase): array {
  if (!is_string($runId) || $runId === '' || !extension_loaded('pdo_sqlite')) {
    return [];
  }
  $path = $root . '/' . $stateDir . '/evidence.sqlite';
  if (!is_file($path)) {
    return [];
  }
  try {
    // Read-only by DSN: no row this hook touches can change. It is not quite
    // "writes nothing" — opening a WAL store creates its -shm and -wal
    // sidecars, which is SQLite's business and not a row — so the claim is the
    // narrower and true one.
    $pdo = new PDO('sqlite:file:' . rawurlencode($path) . '?mode=ro', NULL, NULL, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      // Lock contention only. `PDO::ATTR_TIMEOUT` maps to
      // `sqlite3_busy_timeout`, which caps waiting for a LOCK and not
      // execution — which is not what the comment here used to claim.
      PDO::ATTR_TIMEOUT => 2,
    ]);
    // A VIEW IS NOT A RECORD. The real defect the old comment described: a
    // reviewer replaced `check_result` with a view over an unbounded recursive
    // CTE, and this hook never returned — sixty seconds, still running, killed.
    // `LIMIT 100` cannot save it, because the MAX(attempt) subquery has to
    // drain the view first. An agent whose stop hook never returns cannot end
    // its turn at all, which is worse than any wrong answer.
    //
    // THERE IS NO WALL-CLOCK CAP AVAILABLE HERE, and it is worth saying why
    // rather than shipping one that looks like a cap and is not. PHP delivers
    // signals between opcodes; a blocked `PDOStatement::execute()` is inside
    // SQLite's own loop and never reaches one, so `pcntl_alarm` does not fire
    // (measured — the hang survived it unchanged). SQLite's own interrupt is
    // `sqlite3_progress_handler`, which PDO does not expose.
    //
    // So the defence is structural instead of temporal, and it is exact for
    // the way in: droost writes `check_result` as a TABLE and nothing else,
    // so a `check_result` that is not one is a store somebody has rebuilt.
    // Fail open, as this whole function does — the wall itself does not
    // depend on this read.
    $shape = $pdo->prepare(
      'SELECT type FROM sqlite_master WHERE name = ? LIMIT 1'
    );
    $shape->execute(['check_result']);
    $kind = $shape->fetchColumn();
    if ($kind !== 'table') {
      return [];
    }
    // BOUNDED AT THE READ, not only at the write.
    //
    // Every writer in this package caps what it puts in a summary, and one of
    // them forgot: a 20,000-file diff produced a 1,080,341-byte
    // `declared_files` row, and this hook wrote every byte of it to stderr on
    // every stop attempt until the block cleared — the message that exists to
    // tell an agent what to do, as a megabyte of file paths.
    //
    // That writer is fixed. This cap is here anyway, because a hook that
    // cannot crash is the whole contract of this file: it runs on whatever
    // PHP an editor invokes, against a store that a contributed gate, an older
    // release or a future one may have written, and `substr()` in SQLite means
    // the bytes never enter the process at all. Exit 2 blocks, 0 allows, and
    // ANYTHING ELSE — including a memory exhaustion — reads to the host as
    // permission.
    $statement = $pdo->prepare(
      'SELECT c.name, c.fault,
              substr(c.summary, 1, 2000) AS summary,
              substr(c.remedy, 1, 2000) AS remedy
         FROM check_result c
         JOIN (SELECT kind, name, MAX(attempt) AS attempt
                 FROM check_result WHERE run_id = ? AND phase = ?
                GROUP BY kind, name) latest
           ON c.kind = latest.kind AND c.name = latest.name AND c.attempt = latest.attempt
        WHERE c.run_id = ? AND c.phase = ? AND c.state IN (\'blocked\', \'pending\')
        ORDER BY c.name
        LIMIT 100'
    );
    $statement->execute([$runId, $phase, $runId, $phase]);
    $rows = $statement->fetchAll();
  }
  catch (Throwable $e) {
    return [];
  }
  if (!is_array($rows)) {
    return [];
  }

  return array_map(static fn (array $row): array => [
    'name' => (string) ($row['name'] ?? ''),
    'fault' => (string) ($row['fault'] ?? 'none'),
    'summary' => (string) ($row['summary'] ?? ''),
    'remedy' => (string) ($row['remedy'] ?? ''),
  ], $rows);
}
