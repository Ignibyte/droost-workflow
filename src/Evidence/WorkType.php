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
   * File shapes that CONTRADICT this kind of work.
   *
   * Inverted from what it was, because the other way round does not survive
   * contact with Drupal. An allow-list of "what code work looks like" has to
   * enumerate .php, .module, .info.yml, .routing.yml, .permissions.yml,
   * .services.yml, .libraries.yml, twig, css, js and tests — the first cut
   * listed four, so declaring `code` over an ordinary module blocked the run on
   * its own .info.yml. An agent punished for declaring honestly learns to
   * declare nothing, which is exactly what was measured: the shortest path
   * through a run was the one that declared neither a type nor a test.
   *
   * So the question is narrower and answerable: is there anything here the
   * declaration cannot be true ALONGSIDE? For most types, nothing — `code`,
   * `theme` and `mixed` are broad by nature and their value is `mustMeasure()`,
   * not a file census. Only a narrow claim can be contradicted, and only by
   * something unmistakable.
   *
   * @return list<string>
   *   Regular expressions that, if matched, make the declaration false.
   */
  public function contradictions(): array {
    return match ($this) {
      // "Nothing here executes" is a strong claim and an easy one to check.
      self::Docs => ['#\\.(php|module|inc|install|profile|theme|engine|js|twig)$#'],
      // A bundle and some fields are configuration. Substantial PHP under a
      // custom module is a different ticket wearing this one's label — a
      // .theme or an .info.yml alongside is the obvious thing, not a lie.
      // An install profile is PHP that builds a site; it is code by the same
      // rule a module is, and `ShellGateExecutor` has always analysed it as
      // the project's own. Themes stay out on purpose — the `.theme` beside
      // a bundle is the obvious thing, not a lie.
      self::ContentModel, self::Content => ['#(^|/)(modules|profiles)/custom/.*\\.php$#'],
      // Broad by nature: nothing contradicts them, and pretending otherwise
      // teaches agents to declare `mixed` for everything, which is the same as
      // declaring nothing.
      self::Code, self::Theme, self::Mixed => [],
    };
  }

  /**
   * The changed files that make this declaration false.
   *
   * A declaration is false when the diff holds work the type cannot be true
   * alongside — not merely work the type did not predict. One stray file
   * among others is never a lie, and a type with no contradictions can never
   * tell one. (One file that is the WHOLE diff is not stray — the audit
   * judges that proportion, not this method.)
   *
   * @param list<string> $changed
   *   Project-relative changed paths, already filtered of the run's own record.
   *
   * @return list<string>
   *   The paths that contradict the declaration.
   */
  public function contradictedBy(array $changed): array {
    $patterns = $this->contradictions();
    if ($patterns === []) {
      return [];
    }

    return array_values(array_filter($changed, static function (string $file) use ($patterns): bool {
      foreach ($patterns as $pattern) {
        if (preg_match($pattern, $file) === 1) {
          return TRUE;
        }
      }

      return FALSE;
    }));
  }

  /**
   * A type from a user-supplied name, hyphens and case forgiven.
   *
   * A hyphen and an underscore are the same word to everybody except a backed
   * enum, and `--type=content-model` throwing is a papercut that teaches an
   * agent to stop passing the flag at all.
   *
   * @param string $value
   *   What was typed.
   *
   * @return self|null
   *   The type, or NULL when it names none.
   */
  public static function parse(string $value): ?self {
    return self::tryFrom(str_replace('-', '_', strtolower(trim($value))));
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
