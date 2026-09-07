<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

/**
 * The built-in lever sets a config file starts from.
 *
 * A preset is a BASE, not an alternative to per-gate control: explicit gates:
 * entries are applied over whichever preset is named, so "max but without
 * Playwright" is one line rather than a fork. The resolved result is always
 * reported, because a run whose levers cannot be read back is a run whose
 * report cannot be trusted.
 *
 * Since 2.0 the presets form ONE GRADED DIAL — low, medium, high, xhigh, max —
 * for how much verification a run does (design: docs/design-effort-presets.md).
 * Two things never move with the dial: a spec is always written, and the brain
 * (guidelines, search, the wiki as knowledge) is always used — the dial scales
 * what is VERIFIED and what artefacts are WRITTEN, never what the agent must
 * know. Consent — the write wall, require_run — is not in a preset at all.
 */
final class PresetResolver {

  /**
   * Every canonical preset name a config file may use.
   *
   * Least to most verification: low, medium, high, xhigh, max. `custom` is not
   * a point on the dial — it is "no opinion, my gates: block is the truth",
   * the spelled-out baseline `init` writes so choosing it is visible in a diff.
   */
  public const KNOWN_PRESETS = ['custom', 'low', 'medium', 'high', 'xhigh', 'max'];

  /**
   * Synonyms that RESOLVE to a canonical name.
   *
   * Unlike a retired name, an alias is not refused. `factory` was "everything
   * on, strict" and `light` was "the same phases at lighter weight" — exactly
   * max and medium on the dial. A file naming either still loads; the run
   * records the canonical name and a notice says so, so the file can be
   * updated at leisure rather than under duress.
   */
  public const ALIASED_PRESETS = ['factory' => 'max', 'light' => 'medium'];

  /**
   * Preset names retired by a rename, mapped to their successor.
   *
   * 0.3 renamed `fast` to `light`. The old name is refused with a rename
   * message rather than aliased, so a file that says `fast` gets told what
   * happened instead of silently resolving to something. (`light` itself is
   * now an alias of `medium`; the pointer still lands on a working name.)
   */
  public const RENAMED_PRESETS = ['fast' => 'light'];

  /**
   * The preset assumed whenever a document does not name one.
   *
   * Unspecified means the strictest gates, not the loosest: a repo that has
   * said nothing has not opted out of anything. Critically this is ONE rule
   * covering three situations that are the same fact — no file, an empty
   * file, and a file that sets other things but never mentions a preset.
   *
   * An earlier revision defaulted a file-that-exists to "custom" and only a
   * missing file to the strict set. That made `touch droost.workflow.yml` turn
   * mutation, playwright and coverage off and drop PHPStan from max to 6,
   * with no error and no warning — a gate set silently weakened by creating
   * an empty file, which is the exact failure this package exists to prevent.
   *
   * Since 2.0 the top of the dial also REQUIRES a test suite to exist (see
   * max()), so a silent repo is now told its missing tests block — the same
   * rule, applied to one more thing nobody opted out of.
   */
  public const DEFAULT_PRESET = 'max';

  /**
   * The standard phpcs asks for unless a repo says otherwise.
   */
  private const DEFAULT_STANDARD = 'Drupal,DrupalPractice';

  /**
   * Whether a name is a known preset — canonical or alias.
   *
   * Aliases count as known so a run record written under `factory` or `light`
   * still loads after the rename to the dial.
   *
   * @param string $name
   *   The candidate name.
   *
   * @return bool
   *   TRUE when known.
   */
  public static function isKnown(string $name): bool {
    return in_array($name, self::KNOWN_PRESETS, TRUE)
      || isset(self::ALIASED_PRESETS[$name]);
  }

  /**
   * The canonical name for a preset — the alias target, or the name itself.
   *
   * @param string $name
   *   A canonical or aliased preset name.
   *
   * @return string
   *   The canonical name.
   */
  public static function canonical(string $name): string {
    return self::ALIASED_PRESETS[$name] ?? $name;
  }

  /**
   * The base lever set for a preset.
   *
   * @param string $preset
   *   A name from self::KNOWN_PRESETS, or an alias from self::ALIASED_PRESETS.
   *
   * @return \Droost\Workflow\Config\Preset
   *   The base mode, retry bound, enforcement, seeker default and gate set.
   */
  public static function resolve(string $preset): Preset {
    return match (self::canonical($preset)) {
      'low' => self::low(),
      'medium' => self::medium(),
      'high' => self::high(),
      'xhigh' => self::xhigh(),
      'max' => self::max(),
      default => self::custom(),
    };
  }

  /**
   * The bottom of the dial: basic checks, no tests, no wiki.
   *
   * The one preset whose base turns a mandatory gate off. That is allowed
   * HERE and only here: the mandate exists to stop a gate being disarmed
   * silently (a stray `on: false`, an empty file), and `preset: low` is the
   * opposite of silent — one loud, reviewable line, never the default. The
   * gate reports `off`, never `passed`; the report annotates it; the seeker,
   * when armed, is told. The test phase still runs — begin/end fire, the
   * phase map is unchanged — it simply has nothing due but the browser check.
   *
   * The rendered check stays on even here. It is the artefacts-are-truth leg,
   * and a run that stops checking whether the page renders is not low, it
   * is blind. The seeker defaults off: low is for fast iteration, and
   * `seekers: { on: true }` is one line to arm it back.
   *
   * @return \Droost\Workflow\Config\Preset
   *   The base lever set.
   */
  private static function low(): Preset {
    return new Preset('low', Mode::Agentic, 1, enforcement: Enforcement::Soft, seekers: FALSE, gates: [
      'phpcs' => new GateSettings('phpcs', TRUE, ['standard' => 'Drupal']),
      'phpstan' => new GateSettings('phpstan', TRUE, ['level' => 1]),
      'eslint' => new GateSettings('eslint', FALSE),
      'stylelint' => new GateSettings('stylelint', FALSE),
      'prettier' => new GateSettings('prettier', FALSE),
      'phpunit' => new GateSettings('phpunit', FALSE),
      'mutation' => new GateSettings('mutation', FALSE, ['msi_min' => 0]),
      'playwright' => new GateSettings('playwright', FALSE),
      'coverage' => new GateSettings('coverage', FALSE, ['min' => 0]),
      'rendered_check' => new GateSettings('rendered_check', TRUE),
      'config_clean' => new GateSettings('config_clean', TRUE),
      'wiki_fresh' => new GateSettings('wiki_fresh', FALSE),
    ]);
  }

  /**
   * The lighter weight (formerly `light`): same four phases, lighter load.
   *
   * Not a shorter path — every change still walks plan through complete, and
   * the mandatory trio runs. What thins out is the load: a shorter EARS spec
   * instead of the full table, phpstan at level 2 instead of max, no
   * mutation/browser/coverage tiers, and documentation presented in chat
   * rather than recorded artefacts. Unchanged from `light` on purpose, so a
   * repo on it today keeps its behaviour under the new name.
   *
   * @return \Droost\Workflow\Config\Preset
   *   The base lever set.
   */
  private static function medium(): Preset {
    return new Preset('medium', Mode::Agentic, 2, enforcement: Enforcement::Soft, gates: [
      'phpcs' => new GateSettings('phpcs', TRUE, [
        'standard' => self::DEFAULT_STANDARD,
      ]),
      'phpstan' => new GateSettings('phpstan', TRUE, ['level' => 2]),
      'eslint' => new GateSettings('eslint', FALSE),
      'stylelint' => new GateSettings('stylelint', FALSE),
      'prettier' => new GateSettings('prettier', FALSE),
      'phpunit' => new GateSettings('phpunit', TRUE),
      'mutation' => new GateSettings('mutation', FALSE, ['msi_min' => 0]),
      'playwright' => new GateSettings('playwright', FALSE),
      'coverage' => new GateSettings('coverage', FALSE, ['min' => 0]),
      'rendered_check' => new GateSettings('rendered_check', TRUE),
      'config_clean' => new GateSettings('config_clean', TRUE),
      'wiki_fresh' => new GateSettings('wiki_fresh', TRUE),
    ]);
  }

  /**
   * Solid static analysis and unit tests; none of the slow tiers.
   *
   * The same gate set as the shipped `custom` baseline — a repo on that
   * baseline keeps its behaviour under the new name — with enforcement hard,
   * because from here up the phase discipline is meant to hold.
   *
   * @return \Droost\Workflow\Config\Preset
   *   The base lever set.
   */
  private static function high(): Preset {
    return new Preset('high', Mode::Agentic, 2, enforcement: Enforcement::Hard, gates: self::baselineGates());
  }

  /**
   * The slow tiers arrive, at reachable thresholds.
   *
   * Coverage and mutation on at 60, the front-end trio on, phpstan at 8.
   * A repo with no node toolchain reports tool-missing on the trio — which
   * blocks, and is correct at this level: you asked for the front-end gates.
   *
   * @return \Droost\Workflow\Config\Preset
   *   The base lever set.
   */
  private static function xhigh(): Preset {
    return new Preset('xhigh', Mode::Agentic, 2, enforcement: Enforcement::Hard, gates: [
      'phpcs' => new GateSettings('phpcs', TRUE, [
        'standard' => self::DEFAULT_STANDARD,
      ]),
      'phpstan' => new GateSettings('phpstan', TRUE, ['level' => 8]),
      'eslint' => new GateSettings('eslint', TRUE),
      'stylelint' => new GateSettings('stylelint', TRUE),
      'prettier' => new GateSettings('prettier', TRUE),
      'phpunit' => new GateSettings('phpunit', TRUE),
      'mutation' => new GateSettings('mutation', TRUE, ['msi_min' => 60]),
      'playwright' => new GateSettings('playwright', TRUE),
      'coverage' => new GateSettings('coverage', TRUE, ['min' => 60]),
      'rendered_check' => new GateSettings('rendered_check', TRUE),
      'config_clean' => new GateSettings('config_clean', TRUE),
      'wiki_fresh' => new GateSettings('wiki_fresh', TRUE),
    ]);
  }

  /**
   * Everything on, strict, and tests REQUIRED to exist (formerly `factory`).
   *
   * The software factory. The two thresholds are deliberately reachable
   * rather than aspirational: they are the first numbers a consuming repo will
   * want to raise, and a default nobody can hit is a default everybody turns
   * off.
   *
   * `required: true` on phpunit and playwright is what "regressions forced"
   * means: a missing or empty suite is a FAILURE, not the labelled
   * nothing-to-run pass it is at every other level. You cannot complete a max
   * run without regression coverage that exists and passes.
   *
   * @return \Droost\Workflow\Config\Preset
   *   The base lever set.
   */
  private static function max(): Preset {
    return new Preset('max', Mode::Agentic, 3, enforcement: Enforcement::Hard, gates: [
      'phpcs' => new GateSettings('phpcs', TRUE, [
        'standard' => self::DEFAULT_STANDARD,
      ]),
      'phpstan' => new GateSettings('phpstan', TRUE, ['level' => 'max']),
      'eslint' => new GateSettings('eslint', TRUE),
      'stylelint' => new GateSettings('stylelint', TRUE),
      'prettier' => new GateSettings('prettier', TRUE),
      'phpunit' => new GateSettings('phpunit', TRUE, ['required' => TRUE]),
      'mutation' => new GateSettings('mutation', TRUE, ['msi_min' => 80]),
      'playwright' => new GateSettings('playwright', TRUE, ['required' => TRUE]),
      'coverage' => new GateSettings('coverage', TRUE, ['min' => 80]),
      'rendered_check' => new GateSettings('rendered_check', TRUE),
      'config_clean' => new GateSettings('config_clean', TRUE),
      'wiki_fresh' => new GateSettings('wiki_fresh', TRUE),
    ]);
  }

  /**
   * The shipped baseline: per-gate control, nothing slow turned on.
   *
   * These are the values the default droost.workflow.yml spells out, so a
   * repo that edits that file sees exactly what it started from. Same gates
   * as `high`; enforcement stays at the Preset default (soft) because
   * `custom` carries no opinion beyond what the file says.
   *
   * @return \Droost\Workflow\Config\Preset
   *   The base lever set.
   */
  private static function custom(): Preset {
    return new Preset('custom', Mode::Agentic, 2, self::baselineGates());
  }

  /**
   * The gate set `custom` and `high` share.
   *
   * @return array<string, \Droost\Workflow\Config\GateSettings>
   *   Every known gate, in KNOWN_GATES order.
   */
  private static function baselineGates(): array {
    return [
      'phpcs' => new GateSettings('phpcs', TRUE, [
        'standard' => self::DEFAULT_STANDARD,
      ]),
      'phpstan' => new GateSettings('phpstan', TRUE, ['level' => 6]),
      'eslint' => new GateSettings('eslint', FALSE),
      'stylelint' => new GateSettings('stylelint', FALSE),
      'prettier' => new GateSettings('prettier', FALSE),
      'phpunit' => new GateSettings('phpunit', TRUE),
      'mutation' => new GateSettings('mutation', FALSE, ['msi_min' => 0]),
      'playwright' => new GateSettings('playwright', FALSE),
      'coverage' => new GateSettings('coverage', FALSE, ['min' => 0]),
      'rendered_check' => new GateSettings('rendered_check', TRUE),
      'config_clean' => new GateSettings('config_clean', TRUE),
      'wiki_fresh' => new GateSettings('wiki_fresh', TRUE),
    ];
  }

}
