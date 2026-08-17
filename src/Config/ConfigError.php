<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

use Droost\Workflow\Support\DataError;

/**
 * The lever file could not be understood.
 *
 * Every message names the offending key and, where there is one, the set of
 * values that would have been accepted. An unknown key is always an error and
 * never a silent default: a typo in "phpstan" that quietly disables static
 * analysis is a gate weakened by accident, which is the failure this class
 * exists to prevent.
 */
final class ConfigError extends \RuntimeException {

  /**
   * Constructs a ConfigError.
   *
   * @param string $source
   *   The document label, usually "droost.workflow.yml".
   * @param string $problem
   *   The problem, already naming the key.
   * @param \Throwable|null $previous
   *   The underlying error, when there is one.
   */
  private function __construct(
    string $source,
    string $problem,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($source . ': ' . $problem, 0, $previous);
  }

  /**
   * A gate name that is not in the vocabulary.
   *
   * @param string $source
   *   The document label.
   * @param string $name
   *   The offending gate name.
   * @param list<string> $known
   *   The accepted gate names.
   *
   * @return self
   *   The error.
   */
  public static function unknownGate(
    string $source,
    string $name,
    array $known,
  ): self {
    return new self($source, sprintf(
      'unknown gate "%s" (known: %s)',
      $name,
      implode(', ', $known),
    ));
  }

  /**
   * An option a gate does not accept.
   *
   * @param string $source
   *   The document label.
   * @param string $gate
   *   The gate the option was given to.
   * @param string $option
   *   The offending option name.
   * @param list<string> $known
   *   The options this gate accepts, "on" aside.
   *
   * @return self
   *   The error.
   */
  public static function unknownGateOption(
    string $source,
    string $gate,
    string $option,
    array $known,
  ): self {
    return new self($source, sprintf(
      'gate "%s" has no option "%s" (accepts: %s)',
      $gate,
      $option,
      $known === [] ? 'on' : 'on, ' . implode(', ', $known),
    ));
  }

  /**
   * A phase name that is not in the vocabulary.
   *
   * @param string $source
   *   The document label.
   * @param string $name
   *   The offending phase name.
   * @param list<string> $known
   *   The accepted phase names.
   *
   * @return self
   *   The error.
   */
  public static function unknownPhase(
    string $source,
    string $name,
    array $known,
  ): self {
    return new self($source, sprintf(
      'unknown phase "%s" (known: %s)',
      $name,
      implode(', ', $known),
    ));
  }

  /**
   * A mode name that is not in the vocabulary.
   *
   * @param string $source
   *   The document label.
   * @param string $name
   *   The offending mode name.
   * @param list<string> $known
   *   The accepted mode names.
   *
   * @return self
   *   The error.
   */
  public static function unknownMode(
    string $source,
    string $name,
    array $known,
  ): self {
    return new self($source, sprintf(
      'unknown mode "%s" (known: %s)',
      $name,
      implode(', ', $known),
    ));
  }

  /**
   * A preset name that is not in the vocabulary.
   *
   * @param string $source
   *   The document label.
   * @param string $name
   *   The offending preset name.
   * @param list<string> $known
   *   The accepted preset names.
   *
   * @return self
   *   The error.
   */
  public static function unknownPreset(
    string $source,
    string $name,
    array $known,
  ): self {
    return new self($source, sprintf(
      'unknown preset "%s" (known: %s)',
      $name,
      implode(', ', $known),
    ));
  }

  /**
   * A run dropped a phase it may not drop.
   *
   * @param string $source
   *   The document label.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The missing phase.
   *
   * @return self
   *   The error.
   */
  public static function missingRequiredPhase(
    string $source,
    Phase $phase,
  ): self {
    return new self($source, sprintf(
      'phases must include "%s" (plan and complete are mandatory endpoints)',
      $phase->value,
    ));
  }

  /**
   * A run listed its phases out of canonical order.
   *
   * @param string $source
   *   The document label.
   * @param list<string> $given
   *   The phases as written.
   *
   * @return self
   *   The error.
   */
  public static function phasesOutOfOrder(
    string $source,
    array $given,
  ): self {
    return new self($source, sprintf(
      'phases must be a subsequence of %s — got: %s',
      implode(', ', Phase::names()),
      implode(', ', $given),
    ));
  }

  /**
   * A top-level key that is not in the vocabulary.
   *
   * The one that matters most in practice: "gate:" for "gates:" would
   * otherwise leave every gate at its preset value while the file says
   * otherwise, and nothing would ever say so.
   *
   * @param string $source
   *   The document label.
   * @param string $name
   *   The offending key.
   * @param list<string> $known
   *   The accepted top-level keys.
   *
   * @return self
   *   The error.
   */
  public static function unknownSetting(
    string $source,
    string $name,
    array $known,
  ): self {
    return new self($source, sprintf(
      'unknown setting "%s" (known: %s)',
      $name,
      implode(', ', $known),
    ));
  }

  /**
   * The file exists but could not be opened.
   *
   * Distinguished from an absent file on purpose: treating an unreadable file
   * as "no configuration" would swap a repo's levers for the built-in ones and
   * report nothing unusual.
   *
   * @param string $source
   *   The document label.
   *
   * @return self
   *   The error.
   */
  public static function unreadable(string $source): self {
    return new self(
      $source,
      'exists but is not readable — check its permissions',
    );
  }

  /**
   * Something exists at the config path, but it is not a regular file.
   *
   * A directory or a dangling symlink there would otherwise take the
   * no-file branch and quietly hand back the built-in levers.
   *
   * @param string $source
   *   The document label.
   *
   * @return self
   *   The error.
   */
  public static function notRegularFile(string $source): self {
    return new self(
      $source,
      'exists but is not a regular file — a directory or a broken symlink '
      . 'is not a configuration',
    );
  }

  /**
   * A phase was listed more than once.
   *
   * Distinguished from an ordering error because the subsequence check
   * rejects both, and telling someone their phases are "out of order" when
   * they merely repeated one sends them looking in the wrong place.
   *
   * @param string $source
   *   The document label.
   * @param string $phase
   *   The repeated phase name.
   *
   * @return self
   *   The error.
   */
  public static function duplicatePhase(string $source, string $phase): self {
    return new self($source, sprintf(
      'phase "%s" is listed more than once; each phase runs at most once',
      $phase,
    ));
  }

  /**
   * A gate was switched off two different ways at once.
   *
   * @param string $source
   *   The document label.
   * @param string $gate
   *   The gate name.
   *
   * @return self
   *   The error.
   */
  public static function contradictorySwitch(
    string $source,
    string $gate,
  ): self {
    return new self($source, sprintf(
      'gate "%s" sets on: true and level: off — these contradict; use '
      . 'on: false to disable it',
      $gate,
    ));
  }

  /**
   * The document is not a mapping.
   *
   * @param string $source
   *   The document label.
   * @param string $found
   *   What was parsed instead, described for a human: "a list", "string".
   *
   * @return self
   *   The error.
   */
  public static function notMapping(string $source, string $found): self {
    return new self($source, sprintf(
      'the file must contain a mapping of settings, got %s',
      $found,
    ));
  }

  /**
   * The YAML could not be parsed at all.
   *
   * @param string $source
   *   The document label.
   * @param \Throwable $previous
   *   The parser's own exception, whose message carries the line.
   * @param string $absolutePath
   *   The path being parsed, so it can be redacted out of the parser's
   *   message. Symfony embeds the full filename for several error classes —
   *   including tab indentation, the commonest YAML mistake there is — which
   *   would put a developer's directory tree into any log this message
   *   reaches. Every other message in this package deliberately uses the
   *   short label; one library's phrasing should not be the exception.
   *
   * @return self
   *   The error.
   */
  public static function unparseable(
    string $source,
    \Throwable $previous,
    string $absolutePath = '',
  ): self {
    $detail = $previous->getMessage();
    if ($absolutePath !== '') {
      $detail = str_replace($absolutePath, $source, $detail);
    }
    return new self(
      $source,
      'could not be parsed as YAML — ' . $detail,
      $previous,
    );
  }

  /**
   * A value had the wrong type or fell outside its range.
   *
   * Wraps the reader's own error so the failing layer is named by the class
   * while the failing key stays named by the message.
   *
   * @param string $source
   *   The document label.
   * @param \Droost\Workflow\Support\DataError $error
   *   The reader's error.
   *
   * @return self
   *   The error.
   */
  public static function fromData(string $source, DataError $error): self {
    return new self($source, $error->getMessage(), $error);
  }

}
