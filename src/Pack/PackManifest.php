<?php

declare(strict_types=1);

namespace Droost\Workflow\Pack;

use Droost\Workflow\Config\ConfigLocator;

/**
 * The sole enumerator of what the pack contains.
 *
 * Sole is the operative word. Nothing walks the pack directory looking for
 * files: a skill that is not listed here silently never materializes, and no
 * amount of reading the materializer would reveal it. druplit learned this
 * the same way and its own pack array carries the same warning.
 *
 * The pack lives in pack/, not in this repo's own .claude/. That directory
 * configures Claude Code for developing this package; conflating the two
 * would make every dev-tooling tweak a shipped change.
 */
final class PackManifest {

  /**
   * The directory, relative to the package root, holding the pack source.
   */
  public const SOURCE_DIR = 'pack';

  /**
   * The file planted in every directory this package owns.
   *
   * Its presence is the whole ownership rule: a directory carrying one may be
   * refreshed, a directory without one belongs to a human and is refused.
   * Mirrors droost's harness installer, which uninstalls only sentinel-
   * bearing skill directories, generalised from removal to refresh.
   */
  public const SENTINEL = '.droost-workflow-pack';

  /**
   * The lever file, written only when the project has none (ConfigLocator).
   */
  public const string CONFIG_FILE = ConfigLocator::PATH;

  /**
   * The guard, under the pack's source directory: shipped, never copied.
   *
   * Each harness's hooks call it in the package through GUARD_ENTRY, and the
   * guard protects the package it runs from (droost off Drupal, 2026-10-19).
   */
  public const string GUARD = 'hooks/droost-workflow-guard.php';

  /**
   * The guard's entry point, relative to the package root.
   */
  public const string GUARD_ENTRY = 'bin/droost-guard';

  /**
   * Where an earlier init copied the guard, relative to the project.
   */
  public const string LEGACY_GUARD = '.claude/hooks/droost-workflow-guard.php';

  /**
   * The guard mode a hook command runs, when it is droost's guard.
   *
   * Recognises the entry point and the copied script of earlier installs, so
   * an install wired by any version is found, upgraded in place and never
   * doubled.
   *
   * @param string $command
   *   A hook's command line.
   *
   * @return string|null
   *   The mode (pre-tool-use, stop, …), or NULL when it is not our guard.
   */
  public static function guardMode(string $command): ?string {
    return preg_match('#(?:droost-workflow-guard\.php|droost-guard)"?\s+(?:guard\s+)?([a-z][a-z-]*)#', $command, $m) === 1
      ? $m[1]
      : NULL;
  }

  /**
   * The lever file's template, under the pack's source directory.
   */
  public const string CONFIG_TEMPLATE = 'droost.yml';

  /**
   * Pack files, as source path => destination path under the project root.
   *
   * Destinations under .claude/ are owned and sentinelled. The lever file is
   * handled separately: it is the user's, written once and never refreshed.
   */
  public const FILES = [
    // The entry verbs as skills (D72): start, continue and status are read by
    // every host; the slash commands below are Claude Code pointers to them.
    'skills/workflow-start/SKILL.md'
    => '.claude/skills/workflow-start/SKILL.md',
    'skills/workflow-continue/SKILL.md'
    => '.claude/skills/workflow-continue/SKILL.md',
    'skills/workflow-status/SKILL.md'
    => '.claude/skills/workflow-status/SKILL.md',
    'skills/workflow-plan/SKILL.md'
    => '.claude/skills/workflow-plan/SKILL.md',
    'skills/workflow-code/SKILL.md'
    => '.claude/skills/workflow-code/SKILL.md',
    'skills/workflow-test/SKILL.md'
    => '.claude/skills/workflow-test/SKILL.md',
    'skills/workflow-complete/SKILL.md'
    => '.claude/skills/workflow-complete/SKILL.md',
    // The intake (0.14): a whole site from a design, planned with the human
    // before any run. A skill every host reads, and its Claude Code pointer.
    'skills/intake/SKILL.md'
    => '.claude/skills/intake/SKILL.md',
    // The knowledge commands plan consults (owner, 2026-10-19): the workflow
    // forces the consult, and these say how.
    'skills/droost-code-wiki/SKILL.md'
    => '.claude/skills/droost-code-wiki/SKILL.md',
    'skills/droost-code-tree/SKILL.md'
    => '.claude/skills/droost-code-tree/SKILL.md',
    'skills/droost-search-arch/SKILL.md'
    => '.claude/skills/droost-search-arch/SKILL.md',
    'commands/droost/workflow/start.md'
    => '.claude/commands/droost/workflow/start.md',
    'commands/droost/workflow/continue.md'
    => '.claude/commands/droost/workflow/continue.md',
    'commands/droost/workflow/status.md'
    => '.claude/commands/droost/workflow/status.md',
    'commands/droost/workflow/intake.md'
    => '.claude/commands/droost/workflow/intake.md',
    'partials/droost-usage.md' => '.claude/partials/droost-usage.md',
    'agents/workflow-researcher.md'
    => '.claude/agents/workflow-researcher.md',
    'agents/workflow-spec-writer.md'
    => '.claude/agents/workflow-spec-writer.md',
    'agents/workflow-seeker.md'
    => '.claude/agents/workflow-seeker.md',
    'agents/workflow-bug-fixer.md'
    => '.claude/agents/workflow-bug-fixer.md',
    'agents/droost-debugger.md'
    => '.claude/agents/droost-debugger.md',
    // The evaluation and observability template. Copied per round, filled by
    // hand: every lever, the effort it ran at, what it actually did, and the
    // observability chain that says which of those answers can be trusted.
    // Shipped rather than remembered because the failure it guards against is
    // a reading habit, and a habit needs a form in front of it.
    'templates/evaluation.md' => '.claude/templates/evaluation.md',
  ];

  /**
   * Destination directories shared with the user, never sentinelled.
   *
   * `.claude/commands`, `.claude/hooks` and `.claude/agents` are
   * conventional homes for a repo's OWN commands, hooks and agents; planting
   * a sentinel there would claim the whole directory and make init refuse a
   * project that already has any. Files this pack puts in them are still
   * refreshed on every init (the copy truncates), but the directories stay
   * the user's: nothing beyond the manifest's own files is ever touched.
   *
   * @var list<string>
   */
  public const SHARED_DIRS = [
    '.claude/commands',
    '.claude/hooks',
    '.claude/agents',
  ];

  /**
   * The directories under a project root that this package owns.
   *
   * Derived from FILES so the two cannot disagree.
   *
   * @return list<string>
   *   Relative directory paths, deepest last, without duplicates.
   */
  public static function ownedDirectories(): array {
    $dirs = [];
    foreach (self::FILES as $destination) {
      $dir = dirname($destination);
      if (in_array($dir, self::SHARED_DIRS, TRUE)) {
        continue;
      }
      if (!in_array($dir, $dirs, TRUE)) {
        $dirs[] = $dir;
      }
    }
    return $dirs;
  }

  /**
   * Whether this project already has a lever file, so init must keep it.
   *
   * `is_link` as well as `file_exists`, because a DANGLING symlink at the
   * path is still the user's decision about that path: file_exists() follows
   * the link and reports FALSE, and writing over it would replace whatever
   * they meant to point at.
   *
   * Named here because three places asked the same question in the same two
   * clauses — this package's installConfig() and, across the repository
   * boundary, droost's own WorkflowInstaller and its setup command. One of
   * the three drifting is the shape that has cost this project most.
   *
   * @param string $root
   *   The project root.
   *
   * @return bool
   *   TRUE when a lever file is present and must be left alone.
   */
  public static function hasConfigFile(string $root): bool {
    return ConfigLocator::found($root) !== NULL;
  }

}
