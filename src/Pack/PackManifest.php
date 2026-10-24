<?php

declare(strict_types=1);

namespace Droost\Workflow\Pack;

use Droost\Workflow\Config\ConfigLocator;
use Droost\Workflow\Knowledge\CitationResolver;
use Droost\Workflow\Knowledge\FilesystemModuleLocator;

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
   * A file name a module's skill may install: visible, one path segment.
   */
  private const string SKILL_FILE = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

  /**
   * The skills droost's site modules ship, found where the site keeps them.
   *
   * The droost module and its submodules carry their own skills, each in
   * `skills/<name>/SKILL.md` (the module's `droost-tools`, its Canvas
   * submodule's `canvas-build`), so a skill says what the installed
   * module's tools do rather than what this package last knew of them.
   * Only a module named `droost` or `droost_*` is read: a skill is an
   * instruction to the agent, and init takes instructions from droost's own
   * code alone. A name the pack ships stays the pack's.
   *
   * @param string $root
   *   The project root.
   *
   * @return array<string, string>
   *   Destination under the project => the skill file, absolute, by
   *   destination.
   */
  public static function moduleSkills(string $root): array {
    $docroot = CitationResolver::docroot($root);
    $skills = [];
    foreach ((new FilesystemModuleLocator($docroot))->modules() as $name => $module) {
      if ($name !== 'droost' && !str_starts_with($name, 'droost_')) {
        continue;
      }
      foreach (glob($docroot . '/' . $module['path'] . '/skills/*/SKILL.md') ?: [] as $entry) {
        $skill = basename(dirname($entry));
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $skill) !== 1 || in_array('.claude/skills/' . $skill . '/SKILL.md', self::FILES, TRUE)) {
          continue;
        }
        // The skill and the files it links (canvas-build's tree contract):
        // its directory's own files, no deeper, none hidden.
        foreach (glob(dirname($entry) . '/*') ?: [] as $file) {
          if (is_file($file) && preg_match(self::SKILL_FILE, basename($file)) === 1) {
            $skills['.claude/skills/' . $skill . '/' . basename($file)] ??= $file;
          }
        }
      }
    }
    ksort($skills);
    return $skills;
  }

  /**
   * Whether a path is where a module's skill would be installed.
   *
   * @param string $destination
   *   A path under the project.
   *
   * @return bool
   *   TRUE for a file of `.claude/skills/<name>/` where the pack itself ships
   *   no skill of that name.
   */
  public static function isModuleSkillPath(string $destination): bool {
    return preg_match('#^\.claude/skills/([a-z0-9][a-z0-9-]*)/([^/]+)$#', $destination, $match) === 1
      && preg_match(self::SKILL_FILE, $match[2]) === 1
      && !in_array('.claude/skills/' . $match[1] . '/SKILL.md', self::FILES, TRUE);
  }

}
