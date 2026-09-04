<?php

declare(strict_types=1);

namespace Droost\Workflow\State;

use Droost\Workflow\Config\Phase;
use Droost\Workflow\Support\DataError;
use Droost\Workflow\Support\TypedArray;

/**
 * Reads and writes run state as a file beside the lever file.
 *
 * State lives on the filesystem for the same reason the levers do: the CLI
 * surface has no booted Drupal to keep it in, and if run state lived only in
 * Drupal's State API then a run started against a live site could not be
 * resumed, inspected or even described from a plain checkout — the two
 * surfaces would be two pipelines sharing a name.
 *
 * Writes go through a temporary file and a rename, so a run that dies
 * mid-write leaves the previous state intact rather than a truncated file
 * that no longer parses. Two limits on that claim, stated because an
 * overstated durability promise is worse than a modest one:
 *
 * - There is no fsync. rename() makes the directory entry flip atomically;
 *   it does not guarantee the bytes reached the platter. A process crash is
 *   covered, a power cut is not.
 * - There is no locking. The model is one run per repo, and two processes
 *   doing load-modify-save against the same file will lose one of the
 *   updates without either being told. Nothing is ever torn, but "last
 *   writer wins" is the honest description.
 */
final class RunStateStore {

  /**
   * The directory, relative to the project root, that holds run artefacts.
   *
   * A visible folder under a single top-level `droost/` directory — beside
   * `droost/wiki` — because the spec and the run record are things people read,
   * not machinery to hide. Prefer resolveStateDir(), which honours a project
   * still on the legacy hidden dir.
   */
  public const STATE_DIR = 'droost/droost-workflow';

  /**
   * The pre-0.7 hidden location, still honoured so existing runs keep working.
   *
   * A project that already has `.droost-workflow/` (and not the new dir) keeps
   * using it until it is moved by hand; a fresh project gets the visible dir.
   * Every reader resolves through resolveStateDir() so the wall, status, reset
   * and the guards all agree on where a given project's run state lives — an
   * inconsistency there would make the wall look in the wrong place.
   */
  public const LEGACY_STATE_DIR = '.droost-workflow';

  /**
   * The state file's name within that directory.
   */
  public const STATE_FILE = 'run.json';

  /**
   * Resolves which state directory a project uses.
   *
   * The visible `droost/droost-workflow` by default; the legacy hidden
   * `.droost-workflow` only when that exists and the new one does not, so an
   * install predating the move keeps working with no migration step.
   *
   * @param string $projectRoot
   *   The project root.
   *
   * @return string
   *   The state directory relative to the project root.
   */
  public static function resolveStateDir(string $projectRoot): string {
    $root = rtrim($projectRoot, '/');
    if (is_dir($root . '/' . self::LEGACY_STATE_DIR)
      && !is_dir($root . '/' . self::STATE_DIR)) {
      return self::LEGACY_STATE_DIR;
    }
    return self::STATE_DIR;
  }

  /**
   * Constructs a RunStateStore.
   *
   * @param string $projectRoot
   *   The repo root the run belongs to.
   *
   * @throws \InvalidArgumentException
   *   When the root is empty, is the filesystem root, or is not a directory —
   *   see TypedArray::requireProjectRoot().
   */
  public function __construct(string $projectRoot) {
    $this->projectRoot = TypedArray::requireProjectRoot($projectRoot);
    $this->stateDir = self::resolveStateDir($this->projectRoot);
  }

  /**
   * The repo root the run belongs to, validated and without a trailing slash.
   */
  private readonly string $projectRoot;

  /**
   * The resolved state directory for this project (new visible, or legacy).
   */
  private readonly string $stateDir;

  /**
   * The directory run artefacts live in.
   *
   * @return string
   *   The absolute directory path.
   */
  public function directory(): string {
    return rtrim($this->projectRoot, '/') . '/' . $this->stateDir;
  }

  /**
   * The state file's path.
   *
   * @return string
   *   The absolute file path.
   */
  public function path(): string {
    return $this->directory() . '/' . self::STATE_FILE;
  }

  /**
   * The path as shown to an operator, relative to the project root.
   *
   * @return string
   *   The relative path.
   */
  public function label(): string {
    return $this->stateDir . '/' . self::STATE_FILE;
  }

  /**
   * Whether a run is recorded.
   *
   * @return bool
   *   TRUE when a state file exists.
   */
  public function exists(): bool {
    return is_file($this->path());
  }

  /**
   * Loads the recorded run.
   *
   * @return \Droost\Workflow\State\RunState|null
   *   The run, or NULL when none is recorded.
   *
   * @throws \Droost\Workflow\State\StateError
   *   When a state file exists but cannot be read, parsed, or understood. It
   *   is never deleted or replaced in that case.
   */
  public function load(): ?RunState {
    $path = $this->path();
    if (!is_file($path)) {
      return NULL;
    }
    if (!is_readable($path)) {
      throw StateError::corrupt($this->label(), 'the file is not readable');
    }

    $raw = file_get_contents($path);
    if ($raw === FALSE) {
      throw StateError::corrupt($this->label(), 'the file could not be read');
    }

    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw StateError::corrupt(
        $this->label(),
        'invalid JSON — ' . $e->getMessage(),
        $e,
      );
    }

    if (!is_array($decoded)) {
      throw StateError::corrupt(
        $this->label(),
        'the file must contain an object, got ' . get_debug_type($decoded),
      );
    }
    // A JSON array decodes to a PHP array too, so without this a list would
    // be reported as merely lacking a schema version. An empty array is
    // exempt: that is how an empty JSON object arrives.
    if ($decoded !== [] && array_is_list($decoded)) {
      throw StateError::corrupt(
        $this->label(),
        'the file must contain an object, got a list',
      );
    }

    $node = TypedArray::serialized($decoded);
    try {
      if (!$node->has('v')) {
        throw StateError::corrupt(
          $this->label(),
          'no schema version — this is not a run state file',
        );
      }
      $version = $node->int('v');
      if ($version !== RunState::SCHEMA_VERSION) {
        throw StateError::unsupportedVersion(
          $this->label(),
          $version,
          RunState::SCHEMA_VERSION,
        );
      }
      return RunState::fromArray($node, $this->label());
    }
    catch (DataError $e) {
      throw StateError::fromData($this->label(), $e);
    }
  }

  /**
   * Persists a run.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run to record.
   *
   * @throws \Droost\Workflow\State\StateError
   *   When the state cannot be encoded or written. Any previously recorded
   *   state survives such a failure untouched.
   */
  public function save(RunState $state): void {
    $directory = $this->directory();
    // 0755, not 0777: is_dir()/mkdir() modes are masked by the umask, and a
    // umask of 0 is routine for root in a container — which is how this
    // project's own ddev environment runs. A world-writable directory
    // recording what an autonomous agent is doing is not a default worth
    // inheriting from a habit.
    if (!is_dir($directory) && !@mkdir($directory, 0755, TRUE)
      && !is_dir($directory)) {
      throw StateError::unwritable(
        $this->label(),
        'could not create ' . $this->stateDir,
      );
    }
    // is_dir() and is_writable() both follow symlinks, so without this a
    // symlinked state directory would have the run quietly writing outside
    // the project it belongs to.
    if (is_link($directory)) {
      throw StateError::unwritable(
        $this->label(),
        $this->stateDir . ' is a symlink; run state must stay inside the '
        . 'project',
      );
    }
    if (!is_writable($directory)) {
      throw StateError::unwritable(
        $this->label(),
        $this->stateDir . ' is not writable',
      );
    }

    try {
      $json = json_encode(
        $state->toArray(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
      );
    }
    catch (\JsonException $e) {
      throw StateError::unwritable($this->label(), $e->getMessage());
    }

    // Same directory, so the same filesystem, so the rename is atomic. The
    // name carries the pid to make a stray file traceable AND a random suffix
    // because the pid alone is not unique here: this project runs under ddev,
    // where a host process and a container process in separate PID namespaces
    // share the bind-mounted repo and can hold the same pid.
    $temp = sprintf(
      '%s.%d.%s.tmp',
      $this->path(),
      getmypid(),
      bin2hex(random_bytes(4)),
    );
    // Compare the byte count rather than only testing for FALSE. Today
    // file_put_contents() does detect a partial write internally and returns
    // FALSE — verified against a real full filesystem — so this is not
    // guarding a live bug. It is guarding the signature: the function returns
    // a count, and anyone switching to fwrite(), which genuinely can short-
    // write, would otherwise rename a truncated file over good state and
    // defeat the whole temp-and-rename dance.
    $payload = $json . "\n";
    $written = @file_put_contents($temp, $payload);
    if ($written !== strlen($payload)) {
      @unlink($temp);
      throw StateError::unwritable($this->label(), $written === FALSE
        ? 'could not write the temporary file'
        : sprintf(
          'only %d of %d bytes were written — the disk may be full',
          $written,
          strlen($payload),
        ));
    }
    @chmod($temp, 0644);
    if (!@rename($temp, $this->path())) {
      @unlink($temp);
      throw StateError::unwritable(
        $this->label(),
        'could not replace the state file',
      );
    }
  }

  /**
   * Advances a run to a later phase and records it.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param \Droost\Workflow\Config\Phase $to
   *   The phase to enter.
   *
   * @return \Droost\Workflow\State\RunState
   *   The advanced run, already persisted.
   *
   * @throws \Droost\Workflow\State\StateError
   *   When the advanced state cannot be written.
   * @throws \InvalidArgumentException
   *   When the transition itself is illegal — see RunState::advanceTo(). This
   *   is declared rather than converted: it is a caller error, not a storage
   *   failure, and a caller who catches only StateError should not have it
   *   quietly reclassified as one. Ask RunState::canAdvanceTo() first.
   */
  public function advance(RunState $state, Phase $to): RunState {
    $advanced = $state->advanceTo($to);
    $this->save($advanced);
    return $advanced;
  }

}
