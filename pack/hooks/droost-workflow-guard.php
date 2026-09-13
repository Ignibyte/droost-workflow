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
$root = getenv('CLAUDE_PROJECT_DIR');
if ($root === FALSE || $root === '') {
  $root = getcwd();
}
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
if (getenv('CLAUDE_PROJECT_DIR') === FALSE || getenv('CLAUDE_PROJECT_DIR') === '') {
  $here = rtrim($root, '/');
  $levers = NULL;
  for ($depth = 0; $depth < 32; $depth++) {
    if (is_file($here . '/droost/droost-workflow/run.json')
      || is_file($here . '/.droost-workflow/run.json')) {
      $root = $here;
      $levers = NULL;
      break;
    }
    if ($levers === NULL && is_file($here . '/droost.workflow.yml')) {
      $levers = $here;
    }
    // `file_exists`, not `is_dir`: a worktree and a submodule carry `.git` as a
    // FILE, and `is_dir` walked straight out of them into somebody else's repo.
    if (file_exists($here . '/.git')) {
      break;
    }
    $parent = dirname($here);
    if ($parent === $here) {
      break;
    }
    $here = $parent;
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
$stopHookActive = ($payload['stop_hook_active'] ?? FALSE) === TRUE;

if ($mode === 'operator-commands') {
  // A shell is a file editor. `baseline_dir_guard()` refuses the store and the
  // baseline for Edit|Write|MultiEdit|NotebookEdit, and Bash is wired to THIS
  // branch — which only ever looked at drush command names and returned. So the
  // identical write went through: the Write tool was refused and
  // `sqlite3 evidence.sqlite "UPDATE check_result SET state='satisfied'"` was
  // not. Every claim resting on "droost wrote these rows and the agent could
  // not" was false for as long as an agent had a shell.
  protected_path_shell_guard($stdin, $root, $stateDir);
  // Run state is irrelevant here: bypass is granted precisely when there is
  // no run, and a waiver during one. The rule is about WHO, not WHEN.
  operator_commands_guard($stdin);
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
    fwrite(STDERR, sprintf(
      'The run record at %s/run.json cannot be read. That is not the same as '
      . 'having no run: something wrote junk into it, or it was truncated '
      . 'mid-write. Nothing can say which phase this run is in or what it has '
      . 'passed, so the turn does not end here. An operator can archive it with '
      . '`droost-workflow reset --force`, which keeps it rather than discarding '
      . 'it.',
      $stateDir,
    ));
    exit(2);
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

/**
 * Emits a soft nudge, at most once per phase per mode.
 */
$warnOnce = static function (string $message) use ($root, $stateDir, $mode, $phase): void {
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
  if (str_contains($file, $stateDir . '/')
    || str_ends_with($file, 'droost.workflow.yml')) {
    exit(0);
  }
  $message = 'droost:workflow:continue: the active run is still in PLAN — write the spec '
    . 'under ' . $stateDir . '/ and advance the run (/droost:workflow:continue) before '
    . 'editing project files.';
  if ($enforcement === 'hard') {
    fwrite(STDERR, $message);
    exit(2);
  }
  $warnOnce($message . ' (enforcement is soft: proceeding.)');
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
  if ($enforcement === 'hard') {
    fwrite(STDERR, $message);
    exit(2);
  }
  $warnOnce($message . ' (enforcement is soft: allowing the stop.)');
  exit(0);
}

exit(0);

/**
 * The part of a Bash command the operator-command patterns may read.
 *
 * A heredoc body fed to something that is not an interpreter — `cat > file`,
 * `gh pr create --body-file -`, `git commit -F -`, `tee` — is text the agent is
 * writing FOR a human, and quoting the operator's command there is exactly
 * what this guard's own refusal asks it to do ("show the operator the exact
 * command"). A live run was refused for putting `drush
 * droost:workflow:gate-waive …` in a pull-request body (F-EMT-11). Such bodies
 * are dropped before matching. A heredoc piped into a shell or a language
 * runtime (`bash <<EOF`, `ddev exec … <<EOF`, `drush php:script - <<EOF`) is
 * still code and stays in the scan, as does everything outside heredocs.
 *
 * @param string $command
 *   The command as the agent typed it.
 *
 * @return string
 *   The command with data-only heredoc bodies removed.
 */
function operator_commands_scan_text(string $command): string {
  $interpreter = '/(?:^|[|;&(`]|\$\()\s*(?:sudo\s+(?:-\S+\s+)*)?(?:env\s+(?:\S+=\S*\s+)*)?'
    . '(?:sh|bash|zsh|dash|ksh|fish|eval|source|\.|php|python3?|perl|node|ruby|expect|tmux|ssh|docker'
    . '|ddev\s+(?:exec|ssh)|lando\s+(?:ssh|exec)|fin\s+(?:exec|ssh)|drush\s+(?:php:?\S*|ev|scr))(?:\s|$)/';
  $lines = preg_split('/\R/', $command) ?: [];
  $kept = [];
  $count = count($lines);
  for ($i = 0; $i < $count; $i++) {
    $line = $lines[$i];
    $kept[] = $line;
    if (preg_match('/<<-?\s*([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\1/', $line, $m) !== 1
      || preg_match($interpreter, $line) === 1) {
      continue;
    }
    // A data heredoc: skip to its terminator, keeping the terminator line.
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
  // Per INVOCATION, from TOKENS. Asking the raw line three different questions
  // let each answer come from a different command: `bypass "hotfix"; echo
  // --off` read its exemption out of the `echo`, `"droost:workflow:byp"ass`
  // defeated every verb because quoting splits a word to a regex and joins it
  // to the shell, and `droost:gate allow_entity_write "on"` armed a write gate
  // because `"on"` is not `on`.
  foreach (operator_commands_invocations($command) as $tokens) {
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
    // bodies (F-EMT-11, a pull-request body quoting a waiver); the same mistake
    // came back through a different door.
    $line = implode(' ', array_filter(
      $tokens,
      static fn (string $token): bool => preg_match('/\s/', $token) !== 1,
    ));
    $which = NULL;
    if (preg_match('/droost:workflow:gate-waive\b|(?<![\w-])dwfgw\b/', $line) === 1) {
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
    elseif (preg_match('/droost:workflow:bypass\b|(?<![\w-])dwfby\b/', $line) === 1
      && !operator_commands_flagged($tokens, ['--off'])) {
      $which = 'bypass';
    }
    elseif (preg_match('/(?:droost:workflow:effort|(?<![\w-])dwfe)\b/', $line) === 1
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
    elseif (operator_commands_arms_write_gate($tokens)) {
      // ARMING a write gate is the operator's act too (round 25, R25-F2: the
      // subject asked for allow_entity_write rather than arming it — this makes
      // asking the only path). Disarming is a tightening and is not matched.
      $which = 'gate (arming a write gate)';
    }
    if ($which === NULL) {
      continue;
    }
    $name = str_starts_with($which, 'gate (') ? 'droost:gate' : 'droost:workflow:' . $which;
    fwrite(STDERR, sprintf(
      '%1$s is the OPERATOR\'s command — an agent may propose it, never run it. '
      . 'Show the operator the exact command with your reason and ask them to '
      . 'run it in THEIR terminal (in Claude Code: `! drush %1$s …`), then '
      . 'continue once they say it is done. The record must carry a human\'s '
      . 'decision, not yours.%2$s',
      $name,
      str_starts_with($which, 'gate (') ? ' (Disarming a gate — `off` — needs no operator; only arming does.)' : '',
    ));
    exit(2);
  }
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
      $verb = isset($words[$index + 1]) && strtolower($words[$index + 1]) === 'droost.settings'
        ? $index + 1
        : NULL;
      if ($verb !== NULL) {
        break;
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
  $endCommand = static function () use (&$invocations, &$tokens, $endToken): void {
    $endToken();
    if ($tokens !== []) {
      $invocations[] = $tokens;
    }
    $tokens = [];
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
    if ($char === '(' || $char === ')' || $char === '{' || $char === '}') {
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
      $endToken();
      if ($char === '>') {
        $redirect = TRUE;
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
  // fixed this once, for heredoc bodies (F-EMT-11, a pull-request body quoting
  // a waiver). Quoting a command to a human is not running it, and only
  // something that will EXECUTE its argument makes it a command line again.
  // PREFIX WRAPPERS. `$head` is token 0, so anything in front of the runner hid
  // it: `nice bash -c …`, `time bash -c …`, `watch -n1 …`, `flock … -c …`,
  // `setsid`, `stdbuf`, `su -c`, `git -c alias.z='!drush …' z`. Growing the
  // allowlist loses that race, so the wrappers are STRIPPED first and whatever
  // is left is judged.
  $wrappers = '/^(?:sudo|command|env|nice|time|setsid|stdbuf|ionice|caffeinate|arch'
    . '|unbuffer|doas|busybox|nohup|timeout|watch|flock|parallel|su|script)$/';
  $runner = '/^(?:sudo|command|env)?$|^(?:\/\S+\/)?(?:sh|bash|zsh|dash|ksh|fish|eval'
    . '|php|python3?|perl|node|ruby|expect|xargs|nohup|timeout|script)$/';
  $verbs = '/droost:workflow:(gate-waive|baseline|bypass|effort)\b'
    . '|(?<![\w-])(dwfgw|dwfbl|dwfby|dwfe)\b|droost-workflow\s+baseline\b'
    . '|(?:droost:gate|(?<![\w-])dgate)\b/';
  $resolved = [];
  foreach ($invocations as $tokens) {
    // The subcommand forms — `ddev exec …`, `lando ssh …`, `docker exec …` —
    // plus anything that runs a string it was handed.
    // Strip leading wrappers and their own flags before asking what this is.
    $bare = $tokens;
    $stripped = 0;
    for ($strip = 0; $strip < 8 && $bare !== []; $strip++) {
      $word = strtolower(basename($bare[0]));
      if (preg_match($wrappers, $word) !== 1) {
        break;
      }
      array_shift($bare);
      $stripped++;
      while ($bare !== [] && str_starts_with($bare[0], '-')) {
        array_shift($bare);
      }
    }
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
    $runs = $wrapped
      || preg_match($runner, $head) === 1
      || ($head === 'ssh')
      || (in_array($head, ['ddev', 'lando', 'fin', 'docker', 'docker-compose', 'podman'], TRUE)
        && in_array($second, ['exec', 'ssh', 'run'], TRUE))
      || ($head === 'drush' && str_starts_with($second, 'php'));
    $kept = [];
    $inners = [];
    foreach ($tokens as $token) {
      // WHATEVER is in it. This also required the token to carry an operator
      // VERB, so every protected-path write inside a runner was invisible —
      // `bash -c 'echo bad > .claude/hooks/droost-workflow-guard.php'` kept the
      // whole thing as one multi-word token that no path check can match, and
      // the guard overwrote itself. `$runs` is the gate; what the runner was
      // handed is a command line either way.
      if ($runs && preg_match('/\s/', $token) === 1) {
        // The nested line REPLACES the argument that carried it. Keeping both
        // meant the outer invocation still held the verb — as one long token,
        // where its `--off` is not an argument — so `ddev exec "drush …
        // --off"` was refused by the outer while the inner would have allowed
        // it. The command that runs is the inner one; the outer is `ddev exec`.
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

  return $resolved;
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
  if (!is_string($command) || $command === '') {
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
  foreach (operator_commands_invocations($command) as $tokens) {
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
    $verbWord = strtolower(ltrim($tokens[0] ?? '', '({'));
    if ($verbWord === 'cd' || $verbWord === 'pushd') {
      // The first operand that is not one of `cd`'s own flags.
      $target = '';
      foreach (array_slice($tokens, 1) as $word) {
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
    $writesTo = FALSE;
    foreach ($tokens as $token) {
      if (str_starts_with($token, "\x01")) {
        $writesTo = TRUE;
        break;
      }
    }
    $verb = strtolower($tokens[0] ?? '');
    $sub = strtolower($tokens[1] ?? '');
    $reading = !$writesTo && in_array($verb, [
      'cat', 'less', 'more', 'head', 'tail', 'ls', 'stat', 'file', 'wc',
      'grep', 'egrep', 'rg', 'diff', 'md5', 'shasum', 'md5sum', 'sha1sum',
      'sha256sum', 'cmp', 'realpath', 'readlink', 'jq',
      // NOT sqlite3. `sqlite3 db "select 1"` and `sqlite3 db "update …"`
      // differ only in a string this cannot parse, and the store is what
      // that string would be rewriting. Read it with `droost-workflow
      // evidence`, which renders the whole round.
    ], TRUE)
      || ($verb === 'git' && in_array($sub, ['diff', 'log', 'show', 'status', 'blame'], TRUE));
    if ($reading) {
      continue;
    }
    foreach ($tokens as $operand) {
      if ($operand === '' || str_starts_with($operand, '-')) {
        continue;
      }
      // Resolved against the tracked cwd, then judged exactly as a Write is.
      // Is this operand being WRITTEN? A redirection target always is; a file
      // named to `cp`, `mv`, `tee` and friends is; a file named to anything
      // else is usually being read or run.
      $target = str_starts_with($operand, "\x01");
      $operand = ltrim($operand, "\x01");
      $absolute = str_starts_with($operand, '/') ? $operand : $cwd . '/' . $operand;
      $refusal = enforcement_refusal($absolute, $root, $stateDir);
      if ($refusal !== ''
        && preg_match('#(^|/)(vendor/bin|node_modules/\.bin)/[^/]+$#', $operand) === 1
        && !$target
        && preg_match('/^(cp|mv|ln|install|tee|dd|truncate|sed|chmod|chown|rm|shred|patch)$/', $verb) !== 1) {
        // Named, not written: running a gate's own tool is what it is for.
        continue;
      }
      if ($refusal !== '') {
        fwrite(STDERR, sprintf('%s (Refused: %s)', $refusal, trim($command)));
        exit(2);
      }
    }
  }

  // The CONTAINING directories, not only the files in them. Every entry above
  // names a file, so the cheapest way past all of them was to take away what
  // holds them:
  //
  //     mv droost/droost-workflow /tmp/dw      # the record, the store, the spec
  //     rm -rf droost                          # and the baseline with it
  //
  // `rm droost/droost-workflow/run.json` was refused; moving the directory it
  // sits in was not. The `mv` form is the worse one, because it is REVERSIBLE:
  // stash the directory, work ungoverned, put it back, and nothing in the
  // record has a gap to notice.
  //
  // Scoped to commands that MOVE OR REMOVE something. `droost` on its own is a
  // directory name and also a word this project says constantly, so matching
  // the name alone refused `git commit -m "droost work"` — and a guard that
  // blocks ordinary work is a guard somebody turns off. Quoting is not the
  // filter (an attacker would simply quote the path); the verb is.
  // `\\\\?` — an OPTIONAL BACKSLASH, for `\rm`, which is how a shell bypasses an
  // alias. Written `\\?` first, which in a single-quoted PHP string is `\?`:
  // a REQUIRED literal question mark. The pattern then matched nothing at all
  // and the whole scan was dead, which is why the tests below run the real hook
  // rather than the regex.
  $destructive = '/^(?:sudo\s+)?(?:command\s+)?\\\\?(?:\/\S+\/)?'
    . '(?:rm|unlink|rmdir|mv|cp|ln|install|rsync|shred|truncate|dd|mktemp)\b/';
  $protected = [
    'droost/droost-workflow',
    '.droost-workflow',
    'droost/baseline',
    '.claude/hooks',
    '.claude',
    'droost',
  ];
  // Per COMMAND, and per OPERAND. Scanning the whole string for a verb and a
  // name refused `rm -rf node_modules && ls droost`, where the two belong to
  // different commands; and matching the name with a regex missed `rm -rf
  // droost/`, `./droost` and `droost//`, because the character classes did not
  // list `/`. Both are the same mistake — reading a command line as a string.
  // A token goes through `normalised_path()`, which already collapses every one
  // of those spellings and has been attacked for it.
  //
  // What still escapes is a shell VARIABLE: `rm -rf "$PWD/droost"` is a path
  // this cannot resolve without running the shell, which is not something a
  // pre-tool hook may do. An absolute path is caught; an unexpanded one is not,
  // and that is stated rather than left to be discovered.
  // Through the SAME tokeniser as everything else. This used its own
  // segment/operand pair, which did not strip comments — so `cp .env.example
  // .env # set up droost` and `mv x.php y.php # renamed for droost` were
  // refused. Two implementations of "what are this command's words" is how they
  // drift, and one of them had already been fixed.
  $directory = NULL;
  foreach (operator_commands_invocations($command) as $tokens) {
    if (preg_match($destructive, implode(' ', $tokens)) !== 1) {
      continue;
    }
    foreach ($tokens as $operand) {
      $landing = resolved_relative(ltrim($operand, "\x01"), $root);
      if ($landing === '') {
        $landing = rtrim(normalised_path(ltrim($operand, "\x01")), '/');
      }
      if (in_array($landing, $protected, TRUE)) {
        $directory = $landing;
        break 2;
      }
    }
  }
  if ($directory !== NULL) {
    fwrite(STDERR, sprintf(
      'A shell command in this run acts on `%s` itself — the directory, not '
      . 'something in it. That directory holds the run\'s record, the evidence '
      . 'store, the adoption baseline or the guard, depending on which one this '
      . 'is, and moving or removing it disarms every one of them at once while '
      . 'leaving the files intact somewhere else. Act on a named file inside it '
      . 'instead. If the state itself has to go, that is the OPERATOR\'s '
      . '`droost-workflow reset --force`, which archives rather than discards. '
      . '(Refused: %s)',
      $directory,
      trim($command),
    ));
    exit(2);
  }

  if (preg_match('#evidence\.sqlite#', $command) === 1) {
    fwrite(STDERR, sprintf(
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
    exit(2);
  }

  if (preg_match('#(^|[\s\'"=/])droost/baseline(/|\s|$)#', $command) === 1) {
    fwrite(STDERR, sprintf(
      'A shell command in this run reaches droost/baseline/. That is the '
      . 'OPERATOR\'s adoption record, written by `droost-workflow baseline` from '
      . 'their terminal and never by hand — editing it is the one move that turns '
      . 'the whole discipline into a formality, because it redefines what counts '
      . 'as debt. Read it with `droost-workflow baseline --status`. If debt was '
      . 'paid, ask the operator for `baseline --refresh`. (Refused: %s)',
      trim($command),
    ));
    exit(2);
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
  // `settings.local.json` too. It was unprotected while `settings.json` was, and
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

  if (preg_match('#(^|/)(droost/droost-workflow|\.droost-workflow)/bypass\.json$#', $relative) === 1) {
    return 'That file IS the bypass grant — the operator\'s recorded decision '
      . 'to let this session build with no run at all. Writing your own is not '
      . 'obtaining permission, it is forging the signature on it. If work '
      . 'genuinely has to happen outside the pipeline, show the operator '
      . '`drush droost:workflow:bypass "<reason>"` and let them run it; they '
      . 'close it again with `bypass --off`.';
  }

  // The second tier applies only while a run is under way.
  if (!is_file($root . '/' . $stateDir . '/run.json')) {
    return '';
  }
  if (preg_match('#(^|/)droost\.workflow\.yml$#', $relative) === 1) {
    return 'The lever file sets what this run is held to, and a run is under '
      . 'way. Its levers were frozen when the run began, so editing it now '
      . 'cannot change THIS run — but it changes the next one, and moving the '
      . 'effort dial is the operator\'s decision rather than the agent\'s. '
      . 'Before a run, it is an ordinary file.';
  }
  if (preg_match('#(^|/)\.claude/(skills|agents)/#', $relative) === 1) {
    return 'That is one of the briefs this run is being held to, and a run is '
      . 'under way. Rewriting your own instructions mid-run is not the same act '
      . 'as improving them: do it before a run, or ask the operator. '
      . '`droost-workflow init` takes the shipped versions.';
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
    fwrite(STDERR, sprintf('%s (Refused: %s)', $refusal, $file));
    exit(2);
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
    fwrite(STDERR, sprintf(
      'The evidence store is the run\'s own record of what droost measured — '
      . 'gate verdicts, which citations resolved, which tools were called. It is '
      . 'written by droost and never by hand, and a record its subject can edit '
      . 'proves nothing about its subject. If a verdict in it is wrong, fix the '
      . 'thing it measured and let the gate run again; if the store itself is '
      . 'broken, the OPERATOR clears it with: drush droost:workflow:reset --force '
      . '(Refused: %s)',
      $file,
    ));
    exit(2);
  }
  fwrite(STDERR, sprintf(
    'droost/baseline/ is the OPERATOR\'s adoption record — it is written by '
    . '`drush droost:workflow:baseline` (or `droost-workflow baseline`) from '
    . 'their terminal and never edited by hand or by an agent. If debt was '
    . 'paid, ask the operator to run `droost:workflow:baseline --refresh`; if '
    . 'new debt must be accepted, `--refresh --grow --reason="…"`. '
    . '(Refused: %s)',
    $file,
  ));
  exit(2);
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
  if (preg_match('#(^|/)(modules|themes)/custom/#', $path) !== 1) {
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
  $message = sprintf(
    'droost workflow: "%s" is custom code and there is no active run. Building '
    . 'is governed by the pipeline. Do ONE of: (1) start a run with '
    . '/droost:workflow:start — write the spec, then build inside the run; or '
    . '(2) if this is a deliberate one-off, ask the OPERATOR to grant a bypass '
    . 'with: drush droost:workflow:bypass "<reason>". Do NOT retry this edit or '
    . 'grant the bypass yourself — surface the choice to the operator.',
    $file,
  );
  if ($level === 'hard') {
    fwrite(STDERR, $message);
    exit(2);
  }
  // soft: nudge once, then allow.
  $marker = $root . '/.droost-workflow/.guard-warned-require-run';
  if (!is_file($marker)) {
    @mkdir($root . '/.droost-workflow', 0777, TRUE);
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
      // A query that never returns cannot be caught: `catch (Throwable)` does
      // not fire on a hang. A store whose check_result is a view over an
      // unbounded recursive CTE held this hook open indefinitely, and a hook
      // that never returns is an agent that can never end a turn.
      PDO::ATTR_TIMEOUT => 2,
    ]);
    $statement = $pdo->prepare(
      'SELECT c.name, c.fault, c.summary, c.remedy
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
