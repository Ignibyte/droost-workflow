<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

/**
 * What kind of work a run is doing, as distinct from how hard it tries.
 *
 * `preset` answers "how much effort" and says nothing about WHAT is being
 * built. So a content-model ticket — a bundle, some fields, a view, no PHP at
 * all — ran phpcs and phpstan over an empty path set and reported "passed,
 * nothing to analyse" three times across three phases. Honest, and noise, and
 * noise in a report is how people learn to skim the one line that mattered.
 *
 * THIS IS NOT A WAIVER, and the distinction is the design. A run type never
 * removes a gate. It does three things:
 *
 *   1. makes an empty result EXPECTED rather than suspicious, so a reader can
 *      tell "nothing to analyse because there is no PHP in a content-model
 *      ticket" from "nothing to analyse because the paths lever is wrong";
 *   2. names the gates that must have actually MEASURED something for this
 *      kind of work — a content-model run whose config_clean measured nothing
 *      has not been checked, whatever else is green;
 *   3. gets audited against the diff, like any other declaration.
 *
 * That last one is what stops it becoming the escape hatch we removed. An agent
 * that declares `content_model` and then writes four hundred lines of PHP has
 * made a false declaration, and that is a blocked check — not a gate it
 * skipped.
 * The mandatory trio remains mandatory at every type; turning a gate off stays
 * the operator's deliberate act through the dial.
 */
enum WorkType: string {

  // Custom PHP: modules, classes, plugins, hooks. The full trio is the point.
  case Code = 'code';

  // Content types, fields, displays, views — configuration, not PHP. What
  // proves it is config_clean (the export is canonical) and rendered_check
  // (the thing it builds actually renders).
  case ContentModel = 'content_model';

  // Themes, templates, SDCs, CSS. Proven by rendering, not by static analysis
  // of PHP that barely exists.
  case Theme = 'theme';

  // Nodes, menus, taxonomy terms — content, and the site still has to serve it.
  case Content = 'content';

  // Markdown, comments, READMEs. Nothing executes.
  case Docs = 'docs';

  // Honestly several of the above. Requires everything the components would,
  // and is the right answer for a ticket that really does span them rather
  // than a way to avoid choosing.
  case Mixed = 'mixed';

  /**
   * The gates that must have MEASURED something for this kind of work.
   *
   * Not "must pass" — every gate must pass. These must have actually looked at
   * something, which is a different and stricter question: a `config_clean`
   * that ran over an empty export directory passes and proves nothing, and for
   * a content-model run that is the gate the whole ticket rests on.
   *
   * @return list<string>
   *   Gate names.
   */
  public function mustMeasure(): array {
    return match ($this) {
      self::Code => ['phpcs', 'phpstan', 'phpunit'],
      self::ContentModel => ['config_clean', 'rendered_check'],
      self::Theme => ['rendered_check'],
      self::Content => ['rendered_check'],
      self::Docs => [],
      self::Mixed => ['config_clean', 'rendered_check'],
    };
  }

  /**
   * File shapes this kind of work is expected to produce.
   *
   * Used to audit the declaration, never to restrict what may be written: the
   * question is whether the declaration was HONEST, not whether the agent
   * stayed inside a sandbox.
   *
   * @return list<string>
   *   Regular expressions matched against project-relative paths.
   */
  public function expects(): array {
    return match ($this) {
      self::Code => ['#\.(php|module|inc|install|profile|theme)$#'],
      self::ContentModel => ['#(^|/)config/.*\.yml$#', '#\.(yml|yaml)$#'],
      self::Theme => ['#\.(twig|css|scss|js|yml)$#', '#(^|/)themes/#'],
      self::Content => ['#\.(yml|json|php)$#'],
      self::Docs => ['#\.(md|txt|rst)$#'],
      self::Mixed => ['#.#'],
    };
  }

  /**
   * The changed files this type does not account for.
   *
   * A declaration is false when the work is substantially NOT what was
   * declared. One stray file is not a lie — a content-model ticket that also
   * touches a .theme file to register its display is doing the obvious thing —
   * so the caller decides what proportion is too much, and this only reports.
   *
   * @param list<string> $changed
   *   Project-relative changed paths.
   *
   * @return list<string>
   *   The paths this type would not expect.
   */
  public function unexpected(array $changed): array {
    $patterns = $this->expects();

    return array_values(array_filter($changed, static function (string $file) use ($patterns): bool {
      foreach ($patterns as $pattern) {
        if (preg_match($pattern, $file) === 1) {
          return FALSE;
        }
      }

      return TRUE;
    }));
  }

  /**
   * Every type's value, for an error message or a CLI usage line.
   *
   * @return list<string>
   *   The names.
   */
  public static function names(): array {
    return array_map(static fn (self $case): string => $case->value, self::cases());
  }

  /**
   * A short phrase for a report.
   *
   * @return string
   *   The rendering.
   */
  public function label(): string {
    return match ($this) {
      self::Code => 'custom PHP',
      self::ContentModel => 'content model (configuration)',
      self::Theme => 'theme and templates',
      self::Content => 'content',
      self::Docs => 'documentation',
      self::Mixed => 'mixed',
    };
  }

}
