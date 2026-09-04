<?php

declare(strict_types=1);

namespace Droost\Workflow\Pack;

use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Support\TypedArray;

/**
 * Installs the pack into a consuming repository.
 *
 * Pure PHP, deliberately. droost already has a harness installer that writes
 * .claude/skills/, but it is a Drupal service and needs a booted container —
 * which the CLI surface, by definition, does not have. So the semantics are
 * borrowed rather than the code: druplit's copy behaviour (a manifest as the
 * sole enumerator, fail-closed, truncating writes so a re-run is idempotent)
 * and droost's ownership behaviour (a sentinel per directory, and only
 * sentinel-bearing directories may be touched again).
 *
 * The lever file is treated differently from everything else: written when
 * absent, never refreshed. The pack is ours; the configuration is the user's.
 */
final class PackMaterializer {

  /**
   * The package root, where the pack/ directory lives.
   */
  private readonly string $packageRoot;

  /**
   * Constructs a PackMaterializer.
   *
   * @param string|null $packageRoot
   *   The package root, or NULL to derive it from this file's location.
   */
  public function __construct(?string $packageRoot = NULL) {
    $this->packageRoot = rtrim(
      $packageRoot ?? dirname(__DIR__, 2),
      '/',
    );
  }

  /**
   * Installs the pack into a project.
   *
   * @param string $projectRoot
   *   The repository to install into.
   *
   * @return \Droost\Workflow\Pack\InitReport
   *   What was written and what was left alone.
   *
   * @throws \Droost\Workflow\Pack\PackError
   *   When a destination directory exists but is not ours, when a pack file
   *   is missing from this package, or when a write fails.
   * @throws \InvalidArgumentException
   *   When the project root is empty, the filesystem root, or not a
   *   directory.
   */
  public function init(string $projectRoot): InitReport {
    $root = TypedArray::requireProjectRoot($projectRoot);

    // Check every directory before writing anything. A half-installed pack
    // where the refusal happened on the sixth file is worse than one that
    // refused up front, because the agent would find some skills present and
    // conclude the install worked.
    foreach (PackManifest::ownedDirectories() as $relative) {
      $this->assertOursOrAbsent($root . '/' . $relative, $relative);
    }

    // Drift-aware materialisation. pack.lock records the hash of what droost
    // last shipped for each file; a file whose on-disk hash still matches is
    // refreshed, one the user has since edited is KEPT and reported. First
    // init (no lock) writes everything, as before.
    $report = new InitReport();
    $lock = $this->readLock($root);
    $newLock = [];
    foreach (PackManifest::FILES as $source => $destination) {
      $shipped = $this->readSource($source);
      $lastShipped = is_string($lock[$destination] ?? NULL)
        ? $lock[$destination]
        : NULL;
      $to = $root . '/' . $destination;
      if ($lastShipped !== NULL && is_file($to)
        && !hash_equals($lastShipped, hash('sha256', (string) file_get_contents($to)))) {
        // Edited since droost shipped it — keep it, hold the lock at what we
        // last wrote so the next init still sees the edit.
        $report = $report->withDrifted($destination);
        $newLock[$destination] = $lastShipped;
        continue;
      }
      $this->writeFile($destination, $to, $shipped);
      $report = $report->withWritten($destination);
      $newLock[$destination] = hash('sha256', $shipped);
    }
    $this->writeLock($root, $newLock);
    foreach (PackManifest::ownedDirectories() as $relative) {
      $this->plantSentinel($root . '/' . $relative, $relative);
    }

    $report = $this->installConfig($root, $report);
    $report = $this->wireClaudeSettings($root, $report);
    return $this->ensureGitignore($root, $report);
  }

  /**
   * Wires the enforcement guard into .claude/settings.json.
   *
   * A merge, never a copy: settings.json is the user's file and may carry
   * their own hooks. Ours are recognised by their command string, so a
   * re-run is idempotent and a project that already carries them reports
   * "kept". An existing file that does not parse is refused rather than
   * clobbered — rewriting a file we could not read would destroy hooks we
   * never saw.
   *
   * @param string $root
   *   The project root.
   * @param \Droost\Workflow\Pack\InitReport $report
   *   The report so far.
   *
   * @return \Droost\Workflow\Pack\InitReport
   *   The report, extended.
   *
   * @throws \Droost\Workflow\Pack\PackError
   *   When an existing settings.json cannot be parsed, or the write fails.
   */
  private function wireClaudeSettings(
    string $root,
    InitReport $report,
  ): InitReport {
    $relative = '.claude/settings.json';
    $path = $root . '/' . $relative;
    $settings = [];
    if (is_file($path)) {
      $raw = (string) file_get_contents($path);
      $decoded = json_decode($raw, TRUE);
      if (!is_array($decoded)) {
        throw PackError::unwritable(
          $relative,
          'it exists but is not a JSON object; fix it before init can merge hooks',
        );
      }
      $settings = $decoded;
    }

    // Anchor the script to $CLAUDE_PROJECT_DIR, not a bare relative path.
    // Claude Code runs a hook from the invoking tool's cwd, and the agent's
    // Bash tool persists a cwd that moves the moment it runs `cd` — after
    // which `php .claude/hooks/…` fails to open the file and the guard
    // silently stops enforcing (seen live: "Could not open input file",
    // non-blocking, on every Bash call once the agent had cd'd away). The env
    // var is set for every hook execution and is the documented way to
    // reference project files from a hook; the double quotes tolerate a space
    // in the path. The guard resolves the same var internally for its run
    // state, so both the script and what it reads are project-anchored.
    $guard = 'php "$CLAUDE_PROJECT_DIR/.claude/hooks/droost-workflow-guard.php"';
    // Three wirings of one script; two of them on PreToolUse with different
    // matchers, so this is a list of (event, entry) pairs and presence is
    // checked per COMMAND, not per event — an install that already carries
    // the edit guard still gets the Bash guard added (R23-F2).
    $wanted = [
      ['PreToolUse', [
        'matcher' => 'Edit|Write|MultiEdit|NotebookEdit',
        'hooks' => [['type' => 'command', 'command' => $guard . ' pre-tool-use']],
      ],
      ],
      ['PreToolUse', [
        'matcher' => 'Bash',
        'hooks' => [['type' => 'command', 'command' => $guard . ' operator-commands']],
      ],
      ],
      ['Stop', [
        'hooks' => [['type' => 'command', 'command' => $guard . ' stop']],
      ],
      ],
    ];

    $changed = FALSE;
    $hooks = is_array($settings['hooks'] ?? NULL) ? $settings['hooks'] : [];
    foreach ($wanted as [$event, $entry]) {
      $existing = is_array($hooks[$event] ?? NULL) ? $hooks[$event] : [];
      $command = $entry['hooks'][0]['command'];
      // Identify our guard for this mode by its suffix, not its exact command,
      // so an install carrying an older script path is UPGRADED in place. Add
      // when absent, replace when the command drifted, keep when identical —
      // which keeps a re-init idempotent and never leaves a second, dead guard
      // beside the live one (R27-F1).
      $mode = substr($command, (int) strrpos($command, ' ') + 1);
      $at = self::guardIndex($existing, $mode);
      if ($at === NULL) {
        $existing[] = $entry;
        $hooks[$event] = $existing;
        $changed = TRUE;
      }
      elseif (self::commandAt($existing, $at) !== $command) {
        $existing[$at] = $entry;
        $hooks[$event] = $existing;
        $changed = TRUE;
      }
    }

    if (!$changed) {
      return $report->withKept($relative);
    }

    $settings['hooks'] = $hooks;
    $this->makeDirectory(dirname($path), $relative);
    $encoded = json_encode(
      $settings,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    ) . "\n";
    if (@file_put_contents($path, $encoded) !== strlen($encoded)) {
      throw PackError::unwritable($relative, 'the write did not complete');
    }
    return $report->withWritten($relative);
  }

  /**
   * The index of our guard entry for a mode within an event's list, or NULL.
   *
   * Identity is the guard filename plus the mode suffix (pre-tool-use |
   * operator-commands | stop), independent of the script path it was installed
   * with — so a guard wired by any earlier version is recognised and upgraded
   * in place, never duplicated.
   *
   * @param array<array-key, mixed> $entries
   *   The event's configured entries (a list).
   * @param string $mode
   *   The guard mode to find.
   *
   * @return int|null
   *   The list index of the matching entry, or NULL when none carries it.
   */
  private static function guardIndex(array $entries, string $mode): ?int {
    foreach ($entries as $i => $entry) {
      if (!is_int($i) || !is_array($entry)) {
        continue;
      }
      foreach (is_array($entry['hooks'] ?? NULL) ? $entry['hooks'] : [] as $hook) {
        if (is_array($hook)
          && is_string($hook['command'] ?? NULL)
          && str_contains($hook['command'], 'droost-workflow-guard.php')
          && str_ends_with($hook['command'], ' ' . $mode)) {
          return $i;
        }
      }
    }
    return NULL;
  }

  /**
   * The first hook command at a list index, or NULL when the shape is off.
   *
   * @param array<array-key, mixed> $entries
   *   The event's configured entries.
   * @param int $index
   *   The index ::guardIndex() returned.
   *
   * @return string|null
   *   The command string on that entry's first hook, or NULL.
   */
  private static function commandAt(array $entries, int $index): ?string {
    $entry = $entries[$index] ?? NULL;
    if (!is_array($entry)) {
      return NULL;
    }
    $hooks = $entry['hooks'] ?? NULL;
    if (!is_array($hooks)) {
      return NULL;
    }
    $first = $hooks[0] ?? NULL;
    if (!is_array($first)) {
      return NULL;
    }
    $command = $first['command'] ?? NULL;
    return is_string($command) ? $command : NULL;
  }

  /**
   * Keeps .droost-workflow/ out of version control by default.
   *
   * Run state and spec files are the run's own working papers; tracking
   * them is an opt-in a repo makes by removing the line, not a default it
   * discovers in review. An existing ignore entry — with or without the
   * trailing slash — is respected and reported "kept".
   *
   * @param string $root
   *   The project root.
   * @param \Droost\Workflow\Pack\InitReport $report
   *   The report so far.
   *
   * @return \Droost\Workflow\Pack\InitReport
   *   The report, extended.
   *
   * @throws \Droost\Workflow\Pack\PackError
   *   When the write fails.
   */
  private function ensureGitignore(
    string $root,
    InitReport $report,
  ): InitReport {
    $relative = '.gitignore';
    $path = $root . '/' . $relative;
    $existing = is_file($path) ? (string) file_get_contents($path) : '';

    // Ignore the RESOLVED state dir (the visible droost/droost-workflow for a
    // fresh init, the legacy hidden dir for a project still on it). Either
    // spelling already present — for the new dir or the legacy one — is kept.
    $stateDir = RunStateStore::resolveStateDir($root);
    $already = [
      $stateDir, $stateDir . '/', '/' . $stateDir, '/' . $stateDir . '/',
      '.droost-workflow', '.droost-workflow/',
      '/.droost-workflow', '/.droost-workflow/',
    ];
    foreach (explode("\n", $existing) as $line) {
      if (in_array(trim($line), $already, TRUE)) {
        return $report->withKept($relative);
      }
    }

    $addition = "# Droost workflow run state and specs (droost/workflow init).\n"
      . $stateDir . "/\n";
    $contents = $existing === ''
      ? $addition
      : rtrim($existing, "\n") . "\n\n" . $addition;
    if (@file_put_contents($path, $contents) !== strlen($contents)) {
      throw PackError::unwritable($relative, 'the write did not complete');
    }
    return $report->withWritten($relative);
  }

  /**
   * Whether a directory was created by this package.
   *
   * @param string $absolute
   *   The directory path.
   *
   * @return bool
   *   TRUE when it carries our sentinel.
   */
  public function owns(string $absolute): bool {
    return is_file($absolute . '/' . PackManifest::SENTINEL);
  }

  /**
   * Refuses a directory that exists and is not ours.
   *
   * @param string $absolute
   *   The directory path.
   * @param string $relative
   *   The path as shown to the user.
   *
   * @throws \Droost\Workflow\Pack\PackError
   *   When the directory exists without our sentinel.
   */
  private function assertOursOrAbsent(
    string $absolute,
    string $relative,
  ): void {
    if (is_dir($absolute) && !$this->owns($absolute)) {
      throw PackError::notOurs($relative);
    }
  }

  /**
   * Reads one pack file's shipped content.
   *
   * @param string $source
   *   The path within pack/.
   *
   * @return string
   *   The file's content.
   *
   * @throws \Droost\Workflow\Pack\PackError
   *   When the source is missing or unreadable.
   */
  private function readSource(string $source): string {
    $from = $this->packageRoot . '/' . PackManifest::SOURCE_DIR . '/' . $source;
    if (!is_file($from)) {
      throw PackError::sourceMissing(PackManifest::SOURCE_DIR . '/' . $source);
    }
    $contents = file_get_contents($from);
    if ($contents === FALSE) {
      throw PackError::sourceMissing(PackManifest::SOURCE_DIR . '/' . $source);
    }
    return $contents;
  }

  /**
   * Writes one pack file into the project, creating its directory.
   *
   * @param string $destination
   *   The path within the project (for error messages).
   * @param string $to
   *   The absolute destination path.
   * @param string $contents
   *   The shipped content.
   *
   * @throws \Droost\Workflow\Pack\PackError
   *   When the destination cannot be written.
   */
  private function writeFile(string $destination, string $to, string $contents): void {
    $this->makeDirectory(dirname($to), $destination);
    // A truncating write, so a re-run refreshes rather than appends and the
    // result does not depend on what was there before.
    if (@file_put_contents($to, $contents) !== strlen($contents)) {
      throw PackError::unwritable($destination, 'the write did not complete');
    }
  }

  /**
   * The pack lock's path — inside the (gitignored) run-state directory.
   */
  private function lockPath(string $root): string {
    return $root . '/' . RunStateStore::resolveStateDir($root) . '/pack.lock';
  }

  /**
   * Reads the per-file shipped-hash lock, or [] when absent or unreadable.
   *
   * An unreadable or malformed lock degrades to "no lock" — the next init then
   * refreshes every file once (the pre-drift-awareness behaviour) and rewrites
   * a clean lock, rather than refusing.
   *
   * @param string $root
   *   The project root.
   *
   * @return array<string, string>
   *   Destination path => sha256 of what droost last shipped for it.
   */
  private function readLock(string $root): array {
    $path = $this->lockPath($root);
    if (!is_file($path)) {
      return [];
    }
    $decoded = json_decode((string) file_get_contents($path), TRUE);
    if (!is_array($decoded)) {
      return [];
    }
    $lock = [];
    foreach ($decoded as $file => $hash) {
      if (is_string($file) && is_string($hash)) {
        $lock[$file] = $hash;
      }
    }
    return $lock;
  }

  /**
   * Writes the per-file shipped-hash lock.
   *
   * @param string $root
   *   The project root.
   * @param array<string, string> $lock
   *   Destination path => sha256 of what droost last shipped for it.
   *
   * @throws \Droost\Workflow\Pack\PackError
   *   When the lock cannot be written.
   */
  private function writeLock(string $root, array $lock): void {
    ksort($lock);
    $stateDir = RunStateStore::resolveStateDir($root);
    $relative = $stateDir . '/pack.lock';
    $path = $this->lockPath($root);
    $this->makeDirectory(dirname($path), $stateDir);
    $encoded = json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (@file_put_contents($path, $encoded) !== strlen($encoded)) {
      throw PackError::unwritable($relative, 'the write did not complete');
    }
  }

  /**
   * Writes the sentinel that marks a directory as ours.
   *
   * @param string $absolute
   *   The directory path.
   * @param string $relative
   *   The path as shown to the user.
   *
   * @throws \Droost\Workflow\Pack\PackError
   *   When the sentinel cannot be written.
   */
  private function plantSentinel(string $absolute, string $relative): void {
    $marker = $absolute . '/' . PackManifest::SENTINEL;
    $body = "Written by droost/workflow (droost_workflow). Safe to delete along with\n"
      . "this directory; deleting it alone makes the next init refuse.\n";
    if (@file_put_contents($marker, $body) !== strlen($body)) {
      throw PackError::unwritable(
        $relative . '/' . PackManifest::SENTINEL,
        'the write did not complete',
      );
    }
  }

  /**
   * Writes the default lever file, but only when the project has none.
   *
   * @param string $root
   *   The project root.
   * @param \Droost\Workflow\Pack\InitReport $report
   *   The report so far.
   *
   * @return \Droost\Workflow\Pack\InitReport
   *   The report, extended.
   *
   * @throws \Droost\Workflow\Pack\PackError
   *   When the file is missing from this package or cannot be written.
   */
  private function installConfig(
    string $root,
    InitReport $report,
  ): InitReport {
    $destination = PackManifest::CONFIG_FILE;
    $to = $root . '/' . $destination;

    // Never refresh a lever file. It is version-controlled intent that
    // somebody wrote; re-running init must not quietly reset their gates.
    if (file_exists($to) || is_link($to)) {
      return $report->withKept($destination);
    }

    $from = $this->packageRoot . '/' . PackManifest::SOURCE_DIR
      . '/' . PackManifest::CONFIG_FILE;
    if (!is_file($from)) {
      throw PackError::sourceMissing(
        PackManifest::SOURCE_DIR . '/' . PackManifest::CONFIG_FILE,
      );
    }
    $contents = file_get_contents($from);
    if ($contents === FALSE) {
      throw PackError::sourceMissing(
        PackManifest::SOURCE_DIR . '/' . PackManifest::CONFIG_FILE,
      );
    }
    if (@file_put_contents($to, $contents) !== strlen($contents)) {
      throw PackError::unwritable($destination, 'the write did not complete');
    }

    return $report->withWritten($destination);
  }

  /**
   * Creates a directory, with the modes this package uses everywhere.
   *
   * @param string $absolute
   *   The directory to create.
   * @param string $relative
   *   The path as shown to the user.
   *
   * @throws \Droost\Workflow\Pack\PackError
   *   When the directory cannot be created.
   */
  private function makeDirectory(string $absolute, string $relative): void {
    if (is_dir($absolute)) {
      return;
    }
    if (!@mkdir($absolute, 0755, TRUE) && !is_dir($absolute)) {
      throw PackError::unwritable($relative, 'could not create its directory');
    }
  }

}
