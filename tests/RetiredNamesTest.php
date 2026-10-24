<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use PHPUnit\Framework\TestCase;

/**
 * No shipped text describes the shape droost left (2026-10-19).
 *
 * The droost command left Drupal: one command, no drush door, no MCP server, no
 * module-contributed gates, no in-process site gates, the lever file in
 * `droost/droost.yml`. Text is served to agents and people as authoritative,
 * and a retired name in it is an instruction nobody can follow: an agent
 * told `drush droost status` learns that the pipeline is broken. The droost
 * module (2.x, 2026-10-23) is the site's toolset, and its one door,
 * `drush droost:tool`, is current again: the workflow names it where a run
 * reaches the site (the guard's site-tool rule, the `site_tools` check).
 * This fails on each retired name wherever it would be read as current, and
 * the allowances below are each a place the old name is the point (the
 * legacy fallback, the alias's notice).
 *
 * Read as current: everything in the pack but the guard's source (its
 * patterns must still match the old spellings, so the alias and a stray
 * drush call stay refused), the README, docs/ but a file marked as a
 * history note, bin/, and the string literals and docblocks of src/ (a `//`
 * comment may record what happened under an old name).
 */
final class RetiredNamesTest extends TestCase {

  /**
   * The retired names, each with why it is retired.
   */
  private const array RETIRED = [
    '/drush droost(?!:tool\b)/' => 'the workflow has no drush door; the droost module\'s is droost:tool alone',
    '/mcp:server|droost-mcp|mcp__droost/' => 'droost ships no MCP server',
    '/DroostGate/' => 'module-contributed gates are gone; a project gate is a file',
    '/hook_droost_/' => 'there are no Drupal hooks; a project hook is a file',
    '#(?<![\w-])\.droost/#' => 'the folder is the visible droost/',
    '/(?<![\w\/.-])droost-workflow (?:status|run|init|answer|reset|bypass|gate-waive|gate|criteria|evidence|baseline|effort|intake|ticket|events|rules|specs|swap|relay|declare-[a-z-]+|verify-criterion|seeker-report|uninstall)\b/' => 'the verb is `droost workflow:<verb>`',
    '#vendor/bin/droost-workflow#' => 'the command is `droost`',
    '/\bdroost_(?:symbol|graph|search|wiki|wiki_write|brief|module_docs|deprecations|tool|scaffold|structure_create|entity_create|entity_update|config_set|verify|entities|routes|capabilities|architecture|services|db_schema|last_error|logs|views_compose|views_alter|canvas|canvas_tree_set|display_compose)\b/' => 'a Drupal-era tool id; the command names it now',
    '/\b(?:rendered_check|composition_check|grounding_check)\b/' => 'an in-process site gate, gone with the module',
  ];

  /**
   * Retired names a whole file may carry, by path, with the reason.
   */
  private const array FILE_ALLOWANCES = [
    // The alias's own notice names what it stands for.
    'bin/droost-workflow' => 'the alias names its replacement',
    // The cockpit's API contract describes the cockpit's side, unchanged.
    'docs/cockpit-provider-api.md' => 'the cockpit contract, kept as it is',
  ];

  /**
   * Retired names in src/ the code must know, by file.
   *
   * `rendered_check` and its kin: an old lever file naming one loads with a
   * notice saying where it went, so the loader holds their names.
   */
  private const array SRC_ALLOWANCES = [
    'src/Config/GateSettings.php' => '/\b(?:rendered_check|composition_check|grounding_check)\b/',
  ];

  /**
   * Shipped text names nothing droost retired.
   */
  public function testNoShippedTextNamesTheRetiredShape(): void {
    $root = dirname(__DIR__);
    $found = [];
    foreach ($this->shippedText($root) as $relative => $text) {
      foreach (self::RETIRED as $pattern => $why) {
        if (preg_match_all($pattern, $text, $hits) > 0) {
          foreach (array_unique($hits[0]) as $hit) {
            $found[] = sprintf('%s: "%s" (%s)', $relative, $hit, $why);
          }
        }
      }
    }

    $this->assertSame([], $found, 'Shipped text names the shape droost left');
  }

  /**
   * The root lever file is named only where moving it is the subject.
   *
   * `droost.workflow.yml` is read for one release and `droost migrate` moves
   * it; anywhere else it is the old file named as current.
   */
  public function testTheOldLeverFileIsNamedOnlyToMoveIt(): void {
    $root = dirname(__DIR__);
    $found = [];
    foreach ($this->shippedText($root) as $relative => $text) {
      if (str_starts_with($relative, 'src/')) {
        // The loader, the locator and migrate must know the old name.
        continue;
      }
      foreach (explode("\n", $text) as $number => $line) {
        if (str_contains($line, 'droost.workflow.yml') && !preg_match('/migrate|one release|older|still read|legacy/i', $line)) {
          $found[] = sprintf('%s:%d', $relative, $number + 1);
        }
      }
    }

    $this->assertSame([], $found, 'droost.workflow.yml named as the current lever file');
  }

  /**
   * The scan reaches every file it claims to.
   *
   * A clean result is worth only what was read, so the read is checked too.
   */
  public function testTheScanReachesWhatItClaims(): void {
    $text = $this->shippedText(dirname(__DIR__));
    $expected = [
      'README.md',
      'pack/partials/droost-usage.md',
      'pack/skills/workflow-plan/SKILL.md',
      'src/Cli/ArgvDispatcher.php',
      'bin/droost',
    ];
    foreach ($expected as $relative) {
      $this->assertArrayHasKey($relative, $text, $relative . ' is read');
    }
    $this->assertArrayNotHasKey('pack/hooks/droost-workflow-guard.php', $text, 'the guard source is not');
  }

  /**
   * Every shipped text, by project-relative path, as it is checked.
   *
   * @param string $root
   *   The package root.
   *
   * @return array<string, string>
   *   Path to text: whole files, or for src/ the string literals and
   *   docblocks alone.
   */
  private function shippedText(string $root): array {
    $out = [];
    $files = ['README.md'];
    foreach (['pack', 'docs', 'bin', 'src'] as $dir) {
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
      foreach ($iterator as $file) {
        if ($file instanceof \SplFileInfo && $file->isFile()) {
          $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
      }
    }
    sort($files);
    foreach ($files as $relative) {
      if ($relative === 'pack/hooks/droost-workflow-guard.php' || isset(self::FILE_ALLOWANCES[$relative])) {
        continue;
      }
      $text = (string) file_get_contents($root . '/' . $relative);
      if (str_starts_with($relative, 'docs/') && str_contains($text, '**History note (2026-10-19).**')) {
        continue;
      }
      if (str_starts_with($relative, 'src/')) {
        $text = self::literalsAndDocblocks($text);
        if (isset(self::SRC_ALLOWANCES[$relative])) {
          $text = (string) preg_replace(self::SRC_ALLOWANCES[$relative], '', $text);
        }
      }
      $out[$relative] = $text;
    }

    return $out;
  }

  /**
   * A PHP file's string literals and docblocks, one per line.
   *
   * @param string $php
   *   The source.
   *
   * @return string
   *   What a person or an agent reads of it.
   */
  private static function literalsAndDocblocks(string $php): string {
    $kept = [];
    foreach (token_get_all($php) as $token) {
      if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_DOC_COMMENT], TRUE)) {
        $kept[] = $token[1];
      }
    }

    return implode("\n", $kept);
  }

}
