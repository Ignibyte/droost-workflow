<?php

/**
 * @file
 * The droost workflow enforcement guard, wired as a Claude Code hook.
 *
 * Two modes, one rule: NO ACTIVE RUN, NO OPINION. The first thing either
 * mode does is read .droost-workflow/run.json; when it is absent, unreadable
 * or the run has ended, the guard exits 0 without a word — regular
 * conversation is never policed. Enforcement only exists inside a run, at
 * the level the run froze at begin (hard | soft | off).
 *
 *   php .claude/hooks/droost-workflow-guard.php pre-tool-use
 *     Blocks (hard) or warns once per phase (soft) when a file-editing tool
 *     fires while the run is still in PLAN. Writes under .droost-workflow/
 *     are always allowed — the plan phase's whole job is writing the spec.
 *
 *   php .claude/hooks/droost-workflow-guard.php stop
 *     Blocks (hard) or reminds once per phase (soft) when a turn tries to
 *     end while a run is mid-phase. A failed phase is a legitimate end and
 *     is never blocked; and when Claude reports the stop hook already fired
 *     (stop_hook_active), the guard stands down rather than deadlocking a
 *     run the agent cannot advance.
 *
 * Exit 0 allows (optionally emitting a {"systemMessage": ...} nudge);
 * exit 2 blocks, with the reason on stderr for the agent to act on.
 * Warn-once markers live inside .droost-workflow/, which is gitignored.
 */

declare(strict_types=1);

$mode = $argv[1] ?? '';
$root = getcwd();
if ($root === FALSE) {
  exit(0);
}

$stateFile = $root . '/.droost-workflow/run.json';
if (!is_file($stateFile)) {
  exit(0);
}
$document = json_decode((string) file_get_contents($stateFile), TRUE);
if (!is_array($document)) {
  exit(0);
}

$phase = $document['current_phase'] ?? NULL;
if (!is_string($phase) || $phase === '') {
  // A run with no current phase has ended; the record is history, not law.
  exit(0);
}
$phases = is_array($document['phases'] ?? NULL) ? $document['phases'] : [];
$phaseStatus = is_string($phases[$phase] ?? NULL) ? $phases[$phase] : '';
if ($phase === 'complete' && $phaseStatus === 'passed') {
  exit(0);
}
if ($phaseStatus === 'failed') {
  // A failed run is a legitimate outcome, already recorded. Holding the
  // agent hostage to a phase it cannot pass would punish the honesty.
  exit(0);
}

$enforcement = $document['enforcement'] ?? 'off';
if ($enforcement !== 'hard' && $enforcement !== 'soft') {
  exit(0);
}

/**
 * Emits a soft nudge, at most once per phase per mode.
 */
$warnOnce = static function (string $message) use ($root, $mode, $phase): void {
  $marker = $root . '/.droost-workflow/.guard-warned-' . $mode . '-' . $phase;
  if (is_file($marker)) {
    return;
  }
  @touch($marker);
  echo json_encode(['systemMessage' => $message]);
};

$payload = json_decode((string) stream_get_contents(STDIN), TRUE);
$payload = is_array($payload) ? $payload : [];

if ($mode === 'pre-tool-use') {
  if ($phase !== 'plan') {
    exit(0);
  }
  // The spec is plan's own artefact: writes into the run's directory (and
  // edits to the lever file itself, which are a reviewable choice) pass.
  $input = is_array($payload['tool_input'] ?? NULL) ? $payload['tool_input'] : [];
  $file = $input['file_path'] ?? ($input['notebook_path'] ?? '');
  $file = is_string($file) ? $file : '';
  if (str_contains($file, '.droost-workflow/')
    || str_ends_with($file, 'droost.workflow.yml')) {
    exit(0);
  }
  $message = 'droost:workflow:continue: the active run is still in PLAN — write the spec '
    . 'under .droost-workflow/ and advance the run (/droost:workflow:continue) before '
    . 'editing project files.';
  if ($enforcement === 'hard') {
    fwrite(STDERR, $message);
    exit(2);
  }
  $warnOnce($message . ' (enforcement is soft: proceeding.)');
  exit(0);
}

if ($mode === 'stop') {
  if (($payload['stop_hook_active'] ?? FALSE) === TRUE) {
    // The guard already spoke this turn and Claude continued once because
    // of it. Blocking again would deadlock a run the agent cannot advance;
    // one enforced continuation per stop attempt is the contract.
    exit(0);
  }
  $message = sprintf(
    'droost-work: a run is active in phase "%s" — advance it or abandon it '
    . '(/droost:workflow:continue) rather than ending the turn mid-phase.',
    $phase,
  );
  if ($enforcement === 'hard') {
    fwrite(STDERR, $message);
    exit(2);
  }
  $warnOnce($message . ' (enforcement is soft: allowing the stop.)');
  exit(0);
}

exit(0);
