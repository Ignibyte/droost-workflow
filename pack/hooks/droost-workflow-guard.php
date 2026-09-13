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
  require_run_guard($root, $mode, $stdin);
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
  require_run_guard($root, $mode, $stdin);
  exit(0);
}

$phase = $document['current_phase'] ?? NULL;
if (!is_string($phase) || $phase === '') {
  // A run with no current phase has ended; the record is history, not law —
  // and history does not stand the wall down. The finished ticket's run.json
  // sits here until reset, which must not leave the NEXT ticket ungoverned.
  require_run_guard($root, $mode, $stdin);
  exit(0);
}
$phases = is_array($document['phases'] ?? NULL) ? $document['phases'] : [];
$phaseStatus = is_string($phases[$phase] ?? NULL) ? $phases[$phase] : '';
if ($phase === 'complete' && $phaseStatus === 'passed') {
  require_run_guard($root, $mode, $stdin);
  exit(0);
}
if ($phaseStatus === 'failed') {
  // A failed run is a legitimate outcome, already recorded. Holding the
  // agent hostage to a phase it cannot pass would punish the honesty — but
  // an ended run does not license ungoverned building either.
  require_run_guard($root, $mode, $stdin);
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
/**
 * A nested command line, unwrapped from the quotes that carry it.
 *
 * `ddev exec "drush droost:workflow:bypass --off"` is a real invocation and
 * this project runs drush through ddev. The verb is found in the raw command,
 * but the exemption test drops quoted spans — so the `--off` that makes it the
 * SAFE form vanished with them, and the operator standing the wall back down
 * was refused. So were the agent's sanctioned grounding commands, `--status`,
 * `--measure` and `--preview`, in every containerised or remote form. A guard
 * that refuses the documented way out is worse than one hole: it is the reason
 * somebody turns the guard off.
 *
 * Only a span that CARRIES a verb is unwrapped, so an ordinary quoted argument
 * is still dropped by the flag test — which is what closes the hole above it.
 *
 * @param string $command
 *   The command, heredoc bodies already dropped.
 *
 * @return string
 *   The innermost command line carrying an operator verb, else the input.
 */
function operator_commands_unwrap(string $command): string {
  // Local, not a top-level `const`. A function declaration is hoisted and a
  // constant is NOT, and this file does its work near the top and exits long
  // before a `const` further down would run — so the constant did not exist
  // when this was called, every operator-command check died with an uncaught
  // Error, and the hook exited 255. A host reads that as "not a block".
  //
  // The lesson is in the test helper as much as here: a probe that asks "is the
  // exit code 2?" reads a CRASH as permission. Exit 255 is now a failure in
  // GuardTest, not a pass.
  $verbs = '/droost:workflow:(gate-waive|baseline|bypass|effort)\b'
    . '|(?<![\w-])(dwfgw|dwfbl|dwfby|dwfe)\b|droost-workflow\s+baseline\b'
    . '|(?:droost:gate|(?<![\w-])dgate)\b/';
  // Bounded: three levels covers `ddev exec "bash -c '…'"` and stops a
  // pathological string from looping.
  for ($depth = 0; $depth < 3; $depth++) {
    if (preg_match_all('/"([^"]*)"|\'([^\']*)\'/', $command, $spans, PREG_SET_ORDER) === 0) {
      return $command;
    }
    $next = NULL;
    foreach ($spans as $span) {
      $inner = $span[2] ?? '';
      if ($inner === '') {
        $inner = $span[1] ?? '';
      }
      if ($inner !== '' && preg_match($verbs, $inner) === 1) {
        $next = $inner;
        break;
      }
    }
    if ($next === NULL) {
      return $command;
    }
    $command = $next;
  }

  return $command;
}

/**
 * The part of a command in which a bare `--flag` is really a flag.
 *
 * The read-only exemptions below — `--status`, `--measure`, `--preview`,
 * `--off` — were matched with lookaheads over the whole command string, which
 * asks "does this word appear anywhere on the line". The shell asks a narrower
 * question, and the gap between the two was four characters:
 *
 *     drush droost:workflow:bypass "hotfix" # --off
 *
 * The shell discards everything after the `#`; the lookahead did not, read the
 * exemption, and permitted the one command that stands `require_run` down
 * permanently. The same trick worked on `baseline --refresh # --status` and
 * `effort low # --preview`. No obfuscation, nothing in the record.
 *
 * Quoted text is dropped for the same reason and it is not hypothetical: a
 * bypass takes a REASON, and `bypass "needed --off for the hotfix"` is an
 * ordinary sentence that contained its own exemption.
 *
 * Verb detection keeps the quotes — `drush "droost:workflow:bypass"` is a real
 * invocation — so only the flag question uses this narrower text.
 *
 * @param string $command
 *   The command, heredoc bodies already dropped.
 *
 * @return string
 *   The command with comments and quoted spans removed.
 */
function operator_commands_flag_text(string $command): string {
  $out = '';
  $length = strlen($command);
  $quote = '';
  for ($i = 0; $i < $length; $i++) {
    $char = $command[$i];
    if ($quote !== '') {
      // A backslash escapes the next byte inside double quotes only, which is
      // the shell's rule; inside single quotes nothing escapes.
      if ($quote === '"' && $char === '\\' && $i + 1 < $length) {
        $i++;
        continue;
      }
      if ($char === $quote) {
        $quote = '';
      }
      continue;
    }
    if ($char === '\'' || $char === '"') {
      $quote = $char;
      // A PLACEHOLDER, not a space, and the difference granted a bypass. A
      // quote JOINS adjacent words in the shell — `bypass "urgent"--off` is one
      // argument, the reason `urgent--off`, with no `--off` flag anywhere. A
      // space here split it into two, so the exemption test saw a bare `--off`
      // and allowed the grant. The byte only has to be non-whitespace: the
      // tests below ask for a word boundary, and a quoted span is not one.
      $out .= "\x00";
      continue;
    }
    if ($char === '\\' && $i + 1 < $length) {
      // Same reasoning: `urgent\ --off` is ONE argument in the shell, because
      // the backslash escapes the space.
      $i++;
      $out .= "\x00";
      continue;
    }
    if ($char === '#' && ($out === '' || preg_match('/\s$/', $out) === 1)) {
      // A comment runs to the end of its LINE, not of the command: a second
      // line after it is code again.
      $newline = strcspn($command, "\r\n", $i);
      $i += $newline - 1;
      $out .= ' ';
      continue;
    }
    $out .= $char;
  }

  return $out;
}

/**
 * Whether a flag is really present as an argument.
 *
 * @param string $command
 *   The command, heredoc bodies already dropped.
 * @param list<string> $flags
 *   The flags to look for, with their leading dashes.
 *
 * @return bool
 *   TRUE when any of them appears outside quotes and outside a comment.
 */
function operator_commands_has_flag(string $command, array $flags): bool {
  $text = operator_commands_flag_text($command);
  foreach ($flags as $flag) {
    if (preg_match('/(?:^|\s)' . preg_quote($flag, '/') . '(?:=|\s|$)/', $text) === 1) {
      return TRUE;
    }
  }

  return FALSE;
}

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
  // `ddev exec "drush …"`, `bash -c '…'`, `ssh host "…"`: the command line that
  // matters is the one inside the quotes, and it must be judged as a command
  // line rather than as an argument.
  $command = operator_commands_unwrap($command);
  if (preg_match('/droost:workflow:gate-waive\b|(?<![\w-])dwfgw\b/', $command) === 1) {
    $which = 'gate-waive';
  }
  elseif (preg_match('/(?:droost:workflow:baseline|(?<![\w-])dwfbl|droost-workflow\s+baseline)\b/', $command) === 1
    && !operator_commands_has_flag($command, ['--status', '--measure'])) {
    // Writing or refreshing the baseline decides what counts as inherited
    // debt for every later run. The bill (--measure) and the record
    // (--status) are read-only and exactly how an agent grounds a proposal
    // to baseline; the write is the operator's.
    //
    // The exemption is asked of the ARGUMENTS now, not of the line. As a
    // lookahead it also read `# --status` and `"… --status …"`, either of
    // which the shell discards or passes as text.
    $which = 'baseline';
  }
  elseif (preg_match('/droost:workflow:bypass\b|(?<![\w-])dwfby\b/', $command) === 1) {
    if (operator_commands_has_flag($command, ['--off'])) {
      return;
    }
    $which = 'bypass';
  }
  elseif (preg_match('/(?:droost:workflow:effort|(?<![\w-])dwfe)\b(?=.*\s(?:custom|low|medium|high|xhigh|max|factory|light)\b)/', $command) === 1
    && !operator_commands_has_flag($command, ['--preview'])) {
    // Moving the dial is the operator's act whichever way it goes — down is
    // a loosening, and either way it is a lever change the file records.
    // Only a command that NAMES a level and would WRITE is refused: a bare
    // `effort` reports the current level, and `effort <level> --preview`
    // shows the bill without writing — both are anyone's to ask, and the
    // preview is exactly how an agent should ground a level it proposes.
    $which = 'effort';
  }
  elseif (preg_match('/(?:droost:gate|(?<![\w-])dgate)\s+(?:-\S+\s+)*allow_\w+\s+(?:-\S+\s+)*(?:on|true|1|yes|arm|armed)\b/i', $command) === 1
    || preg_match('/(?:config:set|config-set|cset)\b[^\n;&|]*\bdroost\.settings\s+allow_\w+\s+(?:on|true|1|yes)\b/i', $command) === 1) {
    // `(?:-\S+\s+)*` because the flag does not have to be the next word:
    // `drush droost:gate --yes allow_entity_write on` armed a write gate, and
    // `--yes` is the first thing anyone adds to a drush command they expect to
    // prompt.
    // ARMING a write gate is the operator's act too (round 25, R25-F2: the
    // subject asked for allow_entity_write rather than arming it — this makes
    // asking the only path). Disarming is a tightening and is not matched.
    $which = 'gate (arming a write gate)';
  }
  else {
    return;
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

/**
 * A command split into its separate commands.
 *
 * `rm -rf node_modules && ls droost` is two commands, and scanning the whole
 * string for "a destructive verb" and "a protected name" found one of each and
 * refused — although the `rm` was nowhere near the `droost`. A verb governs its
 * OWN operands.
 *
 * @param string $command
 *   The command.
 *
 * @return list<string>
 *   The segments.
 */
function operator_commands_segments(string $command): array {
  $parts = preg_split('/(?:\|\||&&|[;|&\n])/', $command) ?: [];

  return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
}

/**
 * The operands of a command, quotes removed.
 *
 * Splitting on unquoted whitespace, which is what the shell does and what a
 * regex over the raw string kept failing to do: `rm -rf droost/` slipped
 * through a character class that did not list `/`, and so did `./droost`,
 * `droost//` and `droost/.`. Every one of those is the same token once it is a
 * token — and `normalised_path()` already collapses all of them, having been
 * attacked for exactly that.
 *
 * @param string $segment
 *   One command.
 *
 * @return list<string>
 *   Its words.
 */
function operator_commands_operands(string $segment): array {
  $tokens = [];
  $current = '';
  $quote = '';
  $length = strlen($segment);
  for ($i = 0; $i < $length; $i++) {
    $char = $segment[$i];
    if ($quote !== '') {
      if ($char === $quote) {
        $quote = '';
        continue;
      }
      $current .= $char;
      continue;
    }
    if ($char === '\'' || $char === '"') {
      $quote = $char;
      continue;
    }
    if ($char === '\\' && $i + 1 < $length) {
      $current .= $segment[++$i];
      continue;
    }
    if (preg_match('/\s/', $char) === 1) {
      if ($current !== '') {
        $tokens[] = $current;
        $current = '';
      }
      continue;
    }
    $current .= $char;
  }
  if ($current !== '') {
    $tokens[] = $current;
  }

  return $tokens;
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
  foreach ([
    '.claude/hooks/droost-workflow-guard.php',
    '.claude/settings.json',
    '.claude/settings.local.json',
    'droost/droost-workflow/run.json',
    '.droost-workflow/run.json',
    'droost/droost-workflow/bypass.json',
    '.droost-workflow/bypass.json',
    'droost.workflow.yml',
    '.claude/skills/',
    '.claude/agents/',
  ] as $guarded) {
    if (!str_contains($command, $guarded)) {
      continue;
    }
    // A directory prefix needs a filename to test as a path; an exact file
    // must be passed exactly, or it stops matching its own rule.
    $probe = str_ends_with($guarded, '/') ? $guarded . 'x' : $guarded;
    $refusal = enforcement_refusal($probe, $root, $stateDir);
    if ($refusal !== '') {
      fwrite(STDERR, sprintf('%s (Refused: %s)', $refusal, trim($command)));
      exit(2);
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
  $directory = NULL;
  foreach (operator_commands_segments($command) as $segment) {
    if (preg_match($destructive, $segment) !== 1) {
      continue;
    }
    foreach (operator_commands_operands($segment) as $operand) {
      $landing = resolved_relative($operand, $root);
      if ($landing === '') {
        $landing = rtrim(normalised_path($operand), '/');
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

  return '';
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
function require_run_guard(string $root, string $mode, string $stdin): void {
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
  // droost/droost-workflow, or the legacy hidden dir when only that exists.
  $stateDir = (is_dir($root . '/.droost-workflow')
    && !is_dir($root . '/droost/droost-workflow'))
    ? '.droost-workflow'
    : 'droost/droost-workflow';
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
