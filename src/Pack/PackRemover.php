<?php

declare(strict_types=1);

namespace Droost\Workflow\Pack;

use Droost\Workflow\State\RunStateStore;

/**
 * Takes the pack back out — the half `init` never had.
 *
 * There was no uninstall at all until 2026-09-15, and the gap had a shape
 * worse than "a missing convenience". `drush droost:uninstall` removes
 * droost's skills and its MCP entry and leaves the three guard hooks wired in
 * `settings.json` — so a project that removed droost kept a PreToolUse hook on
 * every Edit and every Bash call, running a guard that reads a lever file for a
 * module no longer installed. The only way out was to hand-edit JSON, and the
 * guard refuses edits to `settings.json`.
 *
 * WHAT IS REMOVED AND WHAT IS KEPT. The pack's own files and its wiring go;
 * the project's work stays. In particular the lever file and the state
 * directory are NOT removed: `droost.workflow.yml` is version-controlled
 * intent somebody wrote, and the state directory holds run records and
 * evidence — the history of what was built here. An uninstaller that deletes
 * the record of past runs is a data-loss bug wearing a tidy-up's clothes. Both
 * are named in the report so an operator can remove them deliberately.
 *
 * Ownership is the same rule `init` uses, so the two cannot disagree: a
 * sentinelled directory is this package's and is removed whole; a shared
 * directory (`.claude/commands`, `.claude/hooks`, `.claude/agents` — the
 * conventional homes for a repo's OWN commands, hooks and agents) yields only
 * the manifest's named files, and is then removed if nothing else is left.
 */
final class PackRemover {

  /**
   * Removes the pack from a project.
   *
   * Idempotent: running it twice reports the second run as having nothing to
   * do rather than failing. A file the project has edited since droost shipped
   * it is still removed — it is our file, and drift is a reason to tell the
   * operator, not a reason to leave a guard hook behind.
   *
   * @param string $root
   *   The project root.
   *
   * @return \Droost\Workflow\Pack\RemoveReport
   *   What was removed, what was kept, and what the operator may want to
   *   remove by hand.
   */
  public function uninstall(string $root): RemoveReport {
    $root = rtrim($root, '/');
    $removed = [];
    $kept = [];

    // pack.lock is OURS — the per-file shipped-hash record `init` keeps
    // inside the state directory. The directory is the project's (it holds
    // the run records and the evidence store) and stays; the lock does not.
    // Leaving it behind means a project with no pack still carries a file
    // describing the pack it used to have.
    $files = array_values(PackManifest::FILES);
    $files[] = RunStateStore::resolveStateDir($root) . '/pack.lock';
    foreach ($files as $destination) {
      $path = $root . '/' . $destination;
      if (!is_file($path)) {
        continue;
      }
      if (@unlink($path)) {
        $removed[] = $destination;
      }
      else {
        $kept[] = $destination;
      }
    }

    // Our own directories, sentinel and all. Deepest first, so a parent is
    // considered only once its children are gone.
    $ours = PackManifest::ownedDirectories();
    usort($ours, static fn (string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/'));
    foreach ($ours as $relative) {
      $absolute = $root . '/' . $relative;
      if (!is_dir($absolute)) {
        continue;
      }
      $sentinel = $absolute . '/' . PackManifest::SENTINEL;
      // NOT OURS, NOT OURS TO REMOVE. Without the sentinel this directory
      // belongs to a human who happened to use the same name, which is the
      // same test `init` applies before it will write there.
      if (!is_file($sentinel)) {
        $kept[] = $relative;
        continue;
      }
      @unlink($sentinel);
      if (self::removeIfEmpty($absolute)) {
        $removed[] = $relative;
      }
      else {
        $kept[] = $relative;
      }
    }

    // The shared directories are the user's; they go only if we emptied them.
    foreach (PackManifest::SHARED_DIRS as $relative) {
      $absolute = $root . '/' . $relative;
      if (is_dir($absolute)) {
        self::removeIfEmpty($absolute);
      }
    }
    // `.claude/commands/droost` exists only to hold our `workflow/`
    // subdirectory, and `.claude` only because something put files in it.
    // Both go when empty and stay the moment they are not — droost's own
    // installer puts commands in the first and skills under the second.
    foreach (['.claude/commands/droost', '.claude'] as $relative) {
      $absolute = $root . '/' . $relative;
      if (is_dir($absolute)) {
        self::removeIfEmpty($absolute);
      }
    }

    $settings = $this->unwireClaudeSettings($root);
    if ($settings !== NULL) {
      $removed[] = $settings;
    }

    $agents = $this->removeAgentsBlock($root);
    if ($agents !== NULL) {
      $removed[] = $agents;
    }

    return new RemoveReport(
      $removed,
      $kept,
      $this->leftForTheOperator($root),
    );
  }

  /**
   * Takes our three guard hooks out of settings.json, leaving every other.
   *
   * The file is the user's and may carry their own hooks, so this is a
   * surgical removal keyed the same way `wireClaudeSettings()` adds: by the
   * guard's filename plus its mode suffix, independent of the script path it
   * was installed with. An unparseable file is left alone rather than
   * rewritten — rewriting JSON we could not read would destroy hooks we never
   * saw.
   *
   * @param string $root
   *   The project root.
   *
   * @return string|null
   *   The path, when it changed; NULL when there was nothing of ours in it.
   */
  private function unwireClaudeSettings(string $root): ?string {
    $relative = '.claude/settings.json';
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
      return NULL;
    }
    $settings = json_decode((string) file_get_contents($path), TRUE);
    if (!is_array($settings) || !is_array($settings['hooks'] ?? NULL)) {
      return NULL;
    }
    $changed = FALSE;
    $hooks = $settings['hooks'];
    foreach ($hooks as $event => $entries) {
      if (!is_array($entries)) {
        continue;
      }
      $keep = [];
      foreach ($entries as $entry) {
        if (self::isOurGuard($entry)) {
          $changed = TRUE;
          continue;
        }
        $keep[] = $entry;
      }
      if ($keep === []) {
        unset($hooks[$event]);
        continue;
      }
      $hooks[$event] = $keep;
    }
    if (!$changed) {
      return NULL;
    }
    if ($hooks === []) {
      unset($settings['hooks']);
    }
    else {
      $settings['hooks'] = $hooks;
    }
    // An empty object left behind is noise, but it is the user's file and it
    // may have existed before we ever wrote to it. Removing it only when it
    // holds nothing at all is the narrow, reversible choice.
    if ($settings === []) {
      return @unlink($path) ? $relative : NULL;
    }
    $encoded = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    return @file_put_contents($path, $encoded) === strlen($encoded) ? $relative : NULL;
  }

  /**
   * Whether a settings.json hook entry is one of ours.
   *
   * @param mixed $entry
   *   The entry, whose shape is whatever the file held.
   *
   * @return bool
   *   TRUE when any of its hooks runs this package's guard.
   */
  private static function isOurGuard(mixed $entry): bool {
    if (!is_array($entry)) {
      return FALSE;
    }
    foreach (is_array($entry['hooks'] ?? NULL) ? $entry['hooks'] : [] as $hook) {
      if (is_array($hook)
        && is_string($hook['command'] ?? NULL)
        && str_contains($hook['command'], 'droost-workflow-guard.php')) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Removes our block from AGENTS.md, leaving the rest of the file.
   *
   * The whole file goes only if our block was all it ever held, which is the
   * case `init` created when it wrote the file itself.
   *
   * @param string $root
   *   The project root.
   *
   * @return string|null
   *   The path, when it changed; NULL when there was no block.
   */
  private function removeAgentsBlock(string $root): ?string {
    $path = $root . '/' . AgentsBlock::FILE;
    if (!is_file($path)) {
      return NULL;
    }
    $current = (string) file_get_contents($path);
    $pattern = '/' . preg_quote(AgentsBlock::BEGIN, '/') . '.*?'
      . preg_quote(AgentsBlock::END, '/') . '\n?/s';
    if (preg_match($pattern, $current) !== 1) {
      return NULL;
    }
    $updated = (string) preg_replace($pattern, '', $current);
    // What init writes around its own block when it creates the file, and
    // nothing else: the file existed for the pipeline alone.
    if (trim(str_replace('# Agent instructions', '', $updated)) === '') {
      return @unlink($path) ? AgentsBlock::FILE : NULL;
    }
    $updated = rtrim($updated, "\n") . "\n";

    return @file_put_contents($path, $updated) === strlen($updated) ? AgentsBlock::FILE : NULL;
  }

  /**
   * What is deliberately left behind, for the report to name.
   *
   * @param string $root
   *   The project root.
   *
   * @return list<string>
   *   Paths the operator may want to remove themselves.
   */
  private function leftForTheOperator(string $root): array {
    $left = [];
    if (PackManifest::hasConfigFile($root)) {
      $left[] = PackManifest::CONFIG_FILE;
    }
    foreach (['droost/droost-workflow', '.droost-workflow'] as $relative) {
      if (is_dir($root . '/' . $relative)) {
        $left[] = $relative;
      }
    }

    return $left;
  }

  /**
   * Removes a directory when nothing is left in it.
   *
   * @param string $absolute
   *   The directory.
   *
   * @return bool
   *   TRUE when it is gone.
   */
  private static function removeIfEmpty(string $absolute): bool {
    $entries = @scandir($absolute);
    if ($entries === FALSE) {
      return FALSE;
    }
    if (array_diff($entries, ['.', '..']) !== []) {
      return FALSE;
    }

    return @rmdir($absolute);
  }

}
