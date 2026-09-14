<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

/**
 * Which phpcs standard a project can actually be held to.
 *
 * ONE RULE, ASKED BY EVERYONE WHO NAMES A STANDARD. `init` decided this by
 * itself, as a text rewrite of the file it was about to write, and nothing
 * else knew: the preset bases hardcode `Drupal,DrupalPractice`, so the
 * moment an operator followed the lever file's own advice — "to let a level
 * decide a lever, DELETE that lever's line" — a plain PHP package was handed
 * the preset's Drupal standard, phpcs exited 16 with `the "Drupal" coding
 * standard is not installed`, and a mandatory gate failed hard on a repo
 * where nothing was wrong. The instruction pointed straight at the failure.
 *
 * And `init`'s own copy of the rule knew only SITE-level markers — coder in
 * vendor/, a docroot — so a Drupal contrib-module checkout, which has
 * neither (coder lives at the site), was written `standard: "PSR12"`. PSR-12
 * wants four-space indents; Drupal wants two. A reviewer ran phpcs over a
 * valid 24-line Drupal class and got seven errors, as an AGENT fault, with no
 * waiver on the standalone surface. Before that rewrite the same checkout got
 * Drupal's standard and an honest ENVIRONMENT fault naming coder as the fix.
 * A loud, remediable error had become a silent wrong-standard failure — on
 * the repository shape droost itself is.
 *
 * So: Drupal's standard applies where the project IS Drupal — coder
 * installed, a docroot, or a module, theme or profile declared at the root —
 * and a project that is none of those is held to what phpcs itself ships.
 * Whoever asks gets the same answer.
 */
final class PhpcsStandard {

  /**
   * The standard a Drupal project is held to.
   */
  public const DRUPAL = 'Drupal,DrupalPractice';

  /**
   * The standard phpcs ships with, for a project that is not Drupal.
   */
  public const FALLBACK = 'PSR12';

  /**
   * Whether Drupal's coding standard is the right one for this project.
   *
   * @param string $root
   *   The project root.
   *
   * @return bool
   *   TRUE for a site, a module/theme/profile checkout, or anywhere coder is
   *   installed.
   */
  public static function drupalApplies(string $root): bool {
    $root = rtrim($root, '/');
    // The sniffs themselves: phpcs can run the standard, whatever this is.
    foreach ([
      '/vendor/drupal/coder/coder_sniffer/Drupal/ruleset.xml',
      '/vendor/drupal/coder/coder_sniffer/Drupal',
    ] as $marker) {
      if (file_exists($root . $marker)) {
        return TRUE;
      }
    }
    // A site, under any of the docroot names composer scaffolds.
    foreach (['/web/core/lib/Drupal.php', '/docroot/core/lib/Drupal.php', '/core/lib/Drupal.php'] as $marker) {
      if (is_file($root . $marker)) {
        return TRUE;
      }
    }
    // A module, theme or profile checkout: its info file at the root says so.
    foreach (glob($root . '/*.info.yml') ?: [] as $info) {
      if (preg_match('/^type:\s*["\']?(module|theme|profile)\b/m', (string) @file_get_contents($info)) === 1) {
        return TRUE;
      }
    }
    // Or its composer.json does.
    $composer = json_decode((string) @file_get_contents($root . '/composer.json'), TRUE);
    $type = is_array($composer) && is_string($composer['type'] ?? NULL) ? $composer['type'] : '';

    return preg_match('/^drupal-(custom-)?(module|theme|profile)$/', $type) === 1;
  }

  /**
   * Whether a standard string names Drupal's sniffs.
   *
   * @param string $standard
   *   The `standard` lever, comma-separated.
   *
   * @return bool
   *   TRUE when any entry is Drupal or DrupalPractice.
   */
  public static function namesDrupal(string $standard): bool {
    foreach (explode(',', $standard) as $one) {
      if (in_array(trim($one), ['Drupal', 'DrupalPractice'], TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * The standard this project can be held to, given the one asked for.
   *
   * @param string $root
   *   The project root.
   * @param string $standard
   *   The standard a level or a file asked for.
   *
   * @return string
   *   The same standard, or the fallback when Drupal's was asked of a project
   *   that is not Drupal.
   */
  public static function forProject(string $root, string $standard): string {
    if (!self::namesDrupal($standard) || self::drupalApplies($root)) {
      return $standard;
    }

    return self::FALLBACK;
  }

}
