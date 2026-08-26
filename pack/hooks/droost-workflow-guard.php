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
  // No active run. The pipeline is silent about ordinary conversation — but
  // there is one moment governance gets skipped entirely: a code edit with no
  // run at all, the agent quietly building outside the pipeline. require_run
  // guards exactly that, and ONLY that (pre-tool-use, custom code paths).
  require_run_guard($root, $mode);
  exit(0);
}
$document = json_decode((string) file_get_contents($stateFile), TRUE);
if (!is_array($document)) {
  // Unreadable is not a licence. A run that cannot be read is not an ACTIVE
  // run, and require_run guards exactly the no-active-run case — otherwise
  // junk written into run.json would be a silent, permanent self-disarm.
  require_run_guard($root, $mode);
  exit(0);
}

$phase = $document['current_phase'] ?? NULL;
if (!is_string($phase) || $phase === '') {
  // A run with no current phase has ended; the record is history, not law —
  // and history does not stand the wall down. The finished ticket's run.json
  // sits here until reset, which must not leave the NEXT ticket ungoverned.
  require_run_guard($root, $mode);
  exit(0);
}
$phases = is_array($document['phases'] ?? NULL) ? $document['phases'] : [];
$phaseStatus = is_string($phases[$phase] ?? NULL) ? $phases[$phase] : '';
if ($phase === 'complete' && $phaseStatus === 'passed') {
  require_run_guard($root, $mode);
  exit(0);
}
if ($phaseStatus === 'failed') {
  // A failed run is a legitimate outcome, already recorded. Holding the
  // agent hostage to a phase it cannot pass would punish the honesty — but
  // an ended run does not license ungoverned building either.
  require_run_guard($root, $mode);
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
 */
function require_run_guard(string $root, string $mode): void {
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
  $payload = json_decode((string) stream_get_contents(STDIN), TRUE);
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
  $grant = is_file($root . '/.droost-workflow/bypass.json')
    ? json_decode((string) file_get_contents($root . '/.droost-workflow/bypass.json'), TRUE)
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
