<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

/**
 * How much the human is in the loop.
 *
 * Two modes, and the difference between them is how much the run involves
 * you — not what it records. The run record is identical either way, which is
 * what makes the choice safe to change mid-run.
 */
enum Mode: string {

  // Run plan through complete without stopping. The software factory.
  case Agentic = 'agentic';

  // Hold at every phase and CONVERSE before advancing: what the phase found,
  // what is recommended, and the questions that actually need an answer.
  case Interactive = 'interactive';

  /**
   * Spellings this enum still answers to, mapped to what they became.
   *
   * `automated` and `pair` were the original names and were written into
   * every site's droost.workflow.yml by the installer, as well as into the
   * run record of every run those sites started. Refusing them would turn a
   * rename into a broken run for anyone mid-flight, so they resolve rather
   * than fail. They are accepted, not canonical: nothing new writes them.
   *
   * `pair` maps to Interactive deliberately. Its behaviour was a yes/no at
   * every phase gate, which Interactive replaces with a conversation at the
   * same hold points — an upgrade at the same moments, not a different
   * lever.
   *
   * @var array<string, string>
   */
  private const ALIASES = [
    'automated' => 'agentic',
    'pair' => 'interactive',
  ];

  /**
   * Resolves a configured or recorded name, accepting the old spellings.
   *
   * Every place that reads a mode from outside this package — the lever
   * file, the CLI, and a stored run record — goes through here rather than
   * tryFrom(), which is what makes the aliases real instead of documented.
   *
   * @param string $name
   *   The name as written.
   *
   * @return self|null
   *   The mode, or NULL when the name is neither canonical nor an alias.
   */
  public static function resolve(string $name): ?self {
    return self::tryFrom(self::ALIASES[$name] ?? $name);
  }

  /**
   * Whether a name is one of the accepted-but-superseded spellings.
   *
   * @param string $name
   *   The name as written.
   *
   * @return bool
   *   TRUE when the name resolved through an alias.
   */
  public static function isLegacyName(string $name): bool {
    return isset(self::ALIASES[$name]);
  }

  /**
   * The mode names a config file should use.
   *
   * Canonical only. This feeds error messages, so it names what to write
   * rather than everything that would be tolerated.
   *
   * @return list<string>
   *   The backing values, in declaration order.
   */
  public static function names(): array {
    return array_map(static fn (self $m): string => $m->value, self::cases());
  }

  /**
   * Every name that parses, canonical and legacy alike.
   *
   * @return list<string>
   *   Canonical names first, then the accepted aliases.
   */
  public static function accepted(): array {
    return [...self::names(), ...array_keys(self::ALIASES)];
  }

  /**
   * Whether this mode holds the run to converse at each phase.
   *
   * Named rather than compared at call sites so the question reads as the
   * behaviour ("does it hold?") instead of the vocabulary, which has now
   * changed once.
   *
   * @return bool
   *   TRUE for Interactive.
   */
  public function holdsForConversation(): bool {
    return $this === self::Interactive;
  }

}
