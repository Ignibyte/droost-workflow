<?php

declare(strict_types=1);

namespace Droost\Workflow\Pack;

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
   * The lever file, written to the project root only when absent.
   */
  public const CONFIG_FILE = 'droost.workflow.yml';

  /**
   * Pack files, as source path => destination path under the project root.
   *
   * Destinations under .claude/ are owned and sentinelled. The lever file is
   * handled separately: it is the user's, written once and never refreshed.
   */
  public const FILES = [
    'skills/workflow-plan/SKILL.md'
    => '.claude/skills/workflow-plan/SKILL.md',
    'skills/workflow-code/SKILL.md'
    => '.claude/skills/workflow-code/SKILL.md',
    'skills/workflow-test/SKILL.md'
    => '.claude/skills/workflow-test/SKILL.md',
    'skills/workflow-document/SKILL.md'
    => '.claude/skills/workflow-document/SKILL.md',
    'skills/workflow-complete/SKILL.md'
    => '.claude/skills/workflow-complete/SKILL.md',
    'commands/workflow/run.md' => '.claude/commands/workflow/run.md',
    'commands/workflow/status.md' => '.claude/commands/workflow/status.md',
    'partials/droost-usage.md' => '.claude/partials/droost-usage.md',
  ];

  /**
   * Every droost tool a pack file is allowed to name.
   *
   * The pack is prose served to a coding agent as authoritative, and nothing
   * lints prose — so a confidently wrong tool name would be acted on. An
   * earlier draft of the design cited "droost worker-docs", which does not
   * exist. This list is checked against the real plugin ids and the pack lint
   * refuses any name outside it.
   *
   * @var list<string>
   */
  public const CITABLE_TOOLS = [
    'droost_architecture',
    'droost_capabilities',
    'droost_config_set',
    'droost_entities',
    'droost_entity_create',
    'droost_entity_update',
    'droost_graph',
    'droost_guidelines',
    'droost_last_error',
    'droost_logs',
    'droost_module_docs',
    'droost_routes',
    'droost_scaffold',
    'droost_search',
    'droost_structure_create',
    'droost_symbol',
    'droost_verify',
    // Also a legacy MODULE name below, and deliberately in both lists: droost
    // 2.x retired droost_wiki_pages/_status/_factsheet in favour of one
    // kind-dispatched droost_wiki tool, while droost 1.x sites still have a
    // droost_wiki module. Same string, two kinds of thing, both citable.
    'droost_wiki',
    'droost_wiki_write',
  ];

  /**
   * Every droost MODULE a pack file is allowed to name.
   *
   * Kept apart from the tool list because `droost_brain` and `droost_verify`
   * look identical to a scanner and are different kinds of thing. Several
   * tools live in submodules that a healthy site may not have installed, so
   * the pack has to be able to name the module a missing tool comes from —
   * but naming a module that does not exist would mislead just as badly as
   * naming a tool that does not.
   *
   * @var list<string>
   */
  public const CITABLE_MODULES = [
    // Droost 2.x merged the brain, search and the wiki into droost itself, so
    // on a current site every tool below comes from this one module. The three
    // submodule names stay citable because droost 1.x sites still have them
    // and a pack file written for one is not wrong on the other — but prefer
    // 'droost' when writing new guidance.
    'droost',
    'droost_brain',
    'droost_search',
    'droost_wiki',
    'droost_workflow',
  ];

  /**
   * Every droost identifier a pack file may name, of either kind.
   *
   * @return list<string>
   *   Tool ids and module names.
   */
  public static function citableIdentifiers(): array {
    return [...self::CITABLE_TOOLS, ...self::CITABLE_MODULES];
  }

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
      if (!in_array($dir, $dirs, TRUE)) {
        $dirs[] = $dir;
      }
    }
    return $dirs;
  }

}
