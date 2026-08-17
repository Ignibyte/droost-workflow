<?php

declare(strict_types=1);

namespace Droost\Workflow\Pack;

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

    $report = new InitReport();
    foreach (PackManifest::FILES as $source => $destination) {
      $this->copy($root, $source, $destination);
      $report = $report->withWritten($destination);
    }
    foreach (PackManifest::ownedDirectories() as $relative) {
      $this->plantSentinel($root . '/' . $relative, $relative);
    }

    return $this->installConfig($root, $report);
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
   * Copies one pack file into the project.
   *
   * @param string $root
   *   The project root.
   * @param string $source
   *   The path within pack/.
   * @param string $destination
   *   The path within the project.
   *
   * @throws \Droost\Workflow\Pack\PackError
   *   When the source is missing or the destination cannot be written.
   */
  private function copy(
    string $root,
    string $source,
    string $destination,
  ): void {
    $from = $this->packageRoot . '/' . PackManifest::SOURCE_DIR . '/' . $source;
    if (!is_file($from)) {
      throw PackError::sourceMissing(PackManifest::SOURCE_DIR . '/' . $source);
    }

    $contents = file_get_contents($from);
    if ($contents === FALSE) {
      throw PackError::sourceMissing(PackManifest::SOURCE_DIR . '/' . $source);
    }

    $to = $root . '/' . $destination;
    $this->makeDirectory(dirname($to), $destination);
    // A truncating write, so a re-run refreshes rather than appends and the
    // result does not depend on what was there before.
    if (@file_put_contents($to, $contents) !== strlen($contents)) {
      throw PackError::unwritable($destination, 'the write did not complete');
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
