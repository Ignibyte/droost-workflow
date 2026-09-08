<?php

declare(strict_types=1);

namespace Droost\Workflow\Baseline;

/**
 * Where a baseline lives, and how it is read, hashed and written.
 *
 * `droost/baseline/` — the visible `droost/` folder, beside `droost/wiki`,
 * NOT inside the (gitignored) run-state directory: a baseline is committed and
 * reviewed like a lever file, because it is one. The hash covers the manifest
 * and every file it lists, so a run can freeze one string at begin and any
 * later change — a file edited, added or removed — reads as tampering.
 */
final class BaselineStore {

  /**
   * The directory, project-relative.
   */
  public const DIR = 'droost/baseline';

  /**
   * The manifest file inside it.
   */
  public const MANIFEST = 'baseline.json';

  /**
   * The absolute baseline directory for a project.
   *
   * @param string $root
   *   The project root.
   *
   * @return string
   *   The directory.
   */
  public static function dir(string $root): string {
    return rtrim($root, '/') . '/' . self::DIR;
  }

  /**
   * Whether a project has a baseline.
   *
   * @param string $root
   *   The project root.
   *
   * @return bool
   *   TRUE when the manifest exists.
   */
  public static function exists(string $root): bool {
    return is_file(self::dir($root) . '/' . self::MANIFEST);
  }

  /**
   * Loads a project's baseline.
   *
   * @param string $root
   *   The project root.
   *
   * @return \Droost\Workflow\Baseline\Baseline|null
   *   The baseline, or NULL when the project has none.
   *
   * @throws \Droost\Workflow\Baseline\BaselineError
   *   When a manifest exists but cannot be read.
   */
  public static function load(string $root): ?Baseline {
    $dir = self::dir($root);
    $path = $dir . '/' . self::MANIFEST;
    if (!is_file($path)) {
      return NULL;
    }
    $label = self::DIR . '/' . self::MANIFEST;
    try {
      $decoded = json_decode((string) file_get_contents($path), TRUE, 64, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw BaselineError::corrupt($label, 'invalid JSON (' . $e->getMessage() . ')');
    }
    if (!is_array($decoded)) {
      throw BaselineError::corrupt($label, 'not a JSON object');
    }
    return new Baseline($dir, BaselineManifest::fromArray($decoded, $label));
  }

  /**
   * One string that changes when anything in the baseline changes.
   *
   * @param string $root
   *   The project root.
   *
   * @return string|null
   *   A sha256 hex digest over the manifest and every listed file (sorted by
   *   name, each prefixed by its name), or NULL when there is no baseline.
   */
  public static function hash(string $root): ?string {
    $dir = self::dir($root);
    $manifest = $dir . '/' . self::MANIFEST;
    if (!is_file($manifest)) {
      return NULL;
    }
    $names = [self::MANIFEST];
    try {
      $loaded = self::load($root);
    }
    catch (BaselineError) {
      // An unreadable manifest still hashes: the number changing is what
      // matters to the run, and a corrupt manifest must not read as absent.
      $loaded = NULL;
    }
    if ($loaded !== NULL) {
      foreach ($loaded->manifest->gates as $entry) {
        $names[] = $entry['file'];
      }
      if (is_file($dir . '/' . Baseline::PHPSTAN_WRAPPER)) {
        $names[] = Baseline::PHPSTAN_WRAPPER;
      }
    }
    $names = array_values(array_unique($names));
    sort($names);
    $context = hash_init('sha256');
    foreach ($names as $name) {
      hash_update($context, $name . "\0");
      $content = @file_get_contents($dir . '/' . $name);
      hash_update($context, ($content === FALSE ? '<missing>' : $content) . "\0");
    }
    return hash_final($context);
  }

  /**
   * Writes a baseline: the manifest and the per-gate files.
   *
   * The directory is created when absent. Files not named by the new manifest
   * that a previous baseline wrote are removed, so the directory always holds
   * exactly what the manifest says — and what the hash covers.
   *
   * @param string $root
   *   The project root.
   * @param \Droost\Workflow\Baseline\BaselineManifest $manifest
   *   The manifest to write.
   * @param array<string, string> $files
   *   File name to content, for every file the manifest lists (plus the
   *   phpstan wrapper when one is written).
   *
   * @throws \RuntimeException
   *   When the directory cannot be created or a file cannot be written.
   */
  public static function write(string $root, BaselineManifest $manifest, array $files): void {
    $dir = self::dir($root);
    if (!is_dir($dir) && !mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
      throw new \RuntimeException(sprintf('could not create %s', $dir));
    }
    $keep = [self::MANIFEST, ...array_keys($files)];
    foreach (glob($dir . '/*') ?: [] as $existing) {
      if (is_file($existing) && !in_array(basename($existing), $keep, TRUE)) {
        @unlink($existing);
      }
    }
    foreach ($files as $name => $content) {
      if (file_put_contents($dir . '/' . $name, $content) === FALSE) {
        throw new \RuntimeException(sprintf('could not write %s/%s', $dir, $name));
      }
    }
    $json = json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($dir . '/' . self::MANIFEST, $json . "\n") === FALSE) {
      throw new \RuntimeException(sprintf('could not write %s/%s', $dir, self::MANIFEST));
    }
  }

  /**
   * A short form of a hash for a report line.
   *
   * @param string|null $hash
   *   The full digest.
   *
   * @return string
   *   Its first twelve characters, or "none".
   */
  public static function short(?string $hash): string {
    return $hash === NULL ? 'none' : substr($hash, 0, 12);
  }

}
