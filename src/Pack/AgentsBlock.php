<?php

declare(strict_types=1);

namespace Droost\Workflow\Pack;

/**
 * The pipeline's own block in AGENTS.md — the one surface every host reads.
 *
 * Eval T01 proved what its absence costs: the subject's transcript held ZERO
 * workflow mentions, because the doctrine lived only in PULL surfaces —
 * guideline topics an agent might read — while the file auto-loaded into every
 * session never said the pipeline existed. A pipeline nobody is told about is
 * a pipeline nobody uses.
 *
 * WHY IT LIVES HERE rather than in droost. This block was written by
 * `drush droost:workflow:install` alone until 2026-09-15, so the documented
 * CLI route — `droost-workflow init`, the one a project without Drupal has —
 * produced no block at all. The install that needed the doctrine MOST was the
 * one that did not get it, and a cross-repo audit found this project's own
 * dogfood site running exactly that configuration.
 *
 * AGENTS.md is also the honest answer to this package's host-agnostic claim.
 * Everything else `init` writes is Claude Code's (`.claude/skills`,
 * `.claude/commands`, the guard hook and its `settings.json` wiring). This
 * file is the cross-tool convention, so it is the one thing a Codex or
 * "other" user actually receives — which is why init CREATES it when absent
 * rather than reporting "skipped" and moving on.
 *
 * Droost extends the block rather than replacing it: same markers, same
 * renderer, its own paragraphs (which name the slash commands and drush) plus
 * whatever a project's own layer adds through droost's alter hook. Whichever
 * installer runs last wins, and both write one shape.
 */
final class AgentsBlock {

  /**
   * The file, project-relative. The cross-tool convention, not Claude's.
   */
  public const string FILE = 'AGENTS.md';

  /**
   * Opens the region this package owns.
   */
  public const string BEGIN = '<!-- BEGIN DROOST WORKFLOW -->';

  /**
   * Closes it.
   */
  public const string END = '<!-- END DROOST WORKFLOW -->';

  /**
   * The heading inside the block.
   */
  private const string HEADING = '## The build pipeline is installed — every build starts with a run';

  /**
   * The doctrine, for a project whose only surface is this package.
   *
   * Named commands an agent can actually run here: the standalone binary.
   * Droost passes its own paragraphs naming the slash commands and drush,
   * because on a Drupal site those are what exist.
   *
   * @return list<string>
   *   The paragraphs.
   */
  public static function paragraphs(): array {
    return [
      "Building means ANY new functionality: a module, a class, a template, a\n"
      . "config change, or a schema change. The moment intent turns from\n"
      . "discussing a change to making it, your FIRST move is to open a run —\n"
      . "`vendor/bin/droost-workflow run` (it begins one, and advances it by a\n"
      . "phase on each later call) — never a write.\n"
      . "`vendor/bin/droost-workflow status` inspects it, and\n"
      . "`vendor/bin/droost-workflow declare-changes --files=… --tests=…` says\n"
      . "what the run will touch before it touches anything.",
      "This is enforced, not advisory: with `require_run: hard` (the default in\n"
      . "`droost.workflow.yml`) the editor guard blocks ungoverned custom-code\n"
      . "edits. A finished run counts as no run —\n"
      . "`vendor/bin/droost-workflow reset` archives it under the state\n"
      . "directory's `history/` rather than discarding it. If a one-off should\n"
      . "genuinely skip the pipeline, that is the OPERATOR's call: show them\n"
      . "`droost-workflow bypass \"<why>\"` and let them run it in their own\n"
      . "terminal. Never grant it yourself — the guard refuses it from your\n"
      . "shell, and a bypass with no human behind it is indistinguishable from\n"
      . "tampering.",
    ];
  }

  /**
   * The block's text, for the given paragraphs.
   *
   * @param list<string> $paragraphs
   *   The paragraphs, in order. Empty and whitespace-only entries are
   *   dropped: an extension handing back nothing must not produce a block
   *   with a hole in it.
   *
   * @return string
   *   The block, markers included, newline-terminated.
   */
  public static function render(array $paragraphs): string {
    $kept = [];
    foreach ($paragraphs as $paragraph) {
      if (trim($paragraph) !== '') {
        $kept[] = trim($paragraph);
      }
    }

    return self::BEGIN . "\n"
      . self::HEADING . "\n\n"
      . implode("\n\n", $kept) . "\n"
      . self::END . "\n";
  }

  /**
   * Writes the block into AGENTS.md, replacing any block already there.
   *
   * Idempotent, and it never touches a byte outside the markers: a project's
   * own instructions, and droost's separate guidelines region, survive
   * untouched. A block already present is replaced IN PLACE rather than
   * appended, so re-running an install cannot leave two.
   *
   * @param string $root
   *   The project root.
   * @param list<string> $paragraphs
   *   The paragraphs to render.
   *
   * @return string
   *   'written' (created or changed), 'kept' (already exactly this), or
   *   'failed' (the write did not complete).
   */
  public static function write(string $root, array $paragraphs): string {
    $path = rtrim($root, '/') . '/' . self::FILE;
    $block = self::render($paragraphs);
    // CREATED WHEN ABSENT. droost's installer used to report 'skipped' here,
    // which was right for a Drupal site — droost's own harness installer
    // writes AGENTS.md, so a missing file meant "that has not run yet". It is
    // wrong for every other project, where nothing else will ever create it
    // and reporting "skipped" means the pipeline is installed and no agent is
    // ever told.
    if (!is_file($path)) {
      $contents = "# Agent instructions\n\n" . $block;
      return @file_put_contents($path, $contents) === strlen($contents)
        ? 'written'
        : 'failed';
    }
    $current = (string) file_get_contents($path);
    $pattern = '/' . preg_quote(self::BEGIN, '/') . '.*?'
      . preg_quote(self::END, '/') . '\n?/s';
    if (preg_match($pattern, $current) === 1) {
      $updated = (string) preg_replace($pattern, $block, $current);
      if ($updated === $current) {
        return 'kept';
      }
      return @file_put_contents($path, $updated) === strlen($updated)
        ? 'written'
        : 'failed';
    }
    $updated = rtrim($current, "\n") . "\n\n" . $block;

    return @file_put_contents($path, $updated) === strlen($updated)
      ? 'written'
      : 'failed';
  }

}
