<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

use Droost\Workflow\Support\DataError;
use Droost\Workflow\Support\TypedArray;

/**
 * One quality gate's levers.
 *
 * A gate is on or off, plus whatever typed options it accepts. The option
 * vocabulary is closed: a gate given an option it does not know is an error,
 * because the alternative is a misspelled threshold that silently never
 * applies.
 *
 * Thresholds never imply "on". Writing coverage.min without coverage.on
 * leaves the gate at whatever the preset said. An inferred switch would make
 * min: 0 and on: false two spellings of one intent with two different failure
 * modes.
 *
 * "on" is the only switch. The one lever that could have become a second one
 * is phpstan's `level: off`, which the design's vocabulary allows; it disables
 * the gate, and pairing it with an explicit `on: true` is refused rather than
 * silently resolved, so the recorded levers can never say a gate ran when it
 * did not.
 */
final class GateSettings {

  /**
   * Every gate name a config file may use.
   */
  public const KNOWN_GATES = [
    'phpcs',
    'phpstan',
    'phpunit',
    'mutation',
    'playwright',
    'coverage',
    'rendered_check',
  ];

  /**
   * The options each gate accepts, and how each is read.
   *
   * Types: "string", "percent" (an integer 0-100), "level" (0-9, "max" or
   * "off"). A gate absent from a row accepts nothing but "on".
   */
  private const GATE_OPTIONS = [
    'phpcs' => ['standard' => 'string'],
    'phpstan' => ['level' => 'level'],
    'phpunit' => [],
    'mutation' => ['msi_min' => 'percent'],
    'playwright' => [],
    'coverage' => ['min' => 'percent'],
    'rendered_check' => ['routes' => 'string'],
  ];

  /**
   * The words a PHPStan level may be instead of a number.
   */
  private const LEVEL_WORDS = ['max', 'off'];

  /**
   * The level constraint, phrased once so two messages cannot drift apart.
   */
  private const LEVEL_CONSTRAINT = 'between 0 and 9, "max" or "off"';

  /**
   * What a string destined for a tool's argv may contain.
   *
   * Wide enough for "Drupal,DrupalPractice", a vendor path, or a ruleset
   * filename; narrow enough that no shell metacharacter survives.
   */
  private const TOOL_ARGUMENT = '#^[A-Za-z0-9 _.,/-]+$#';

  /**
   * Constructs a GateSettings.
   *
   * @param string $name
   *   The gate name, one of self::KNOWN_GATES.
   * @param bool $on
   *   Whether the gate runs.
   * @param array<string, int|string> $options
   *   The gate's typed options, keyed by option name. Booleans are absent by
   *   design: "on" is the only switch a gate has, and a second boolean option
   *   would be a second way to disable something.
   */
  public function __construct(
    public readonly string $name,
    public readonly bool $on,
    public readonly array $options = [],
  ) {
    if (!self::isKnown($name)) {
      throw new \InvalidArgumentException(sprintf(
        'No such gate: "%s" (known: %s)',
        $name,
        implode(', ', self::KNOWN_GATES),
      ));
    }
  }

  /**
   * Whether a name is a known gate.
   *
   * @param string $name
   *   The candidate name.
   *
   * @return bool
   *   TRUE when known.
   */
  public static function isKnown(string $name): bool {
    return in_array($name, self::KNOWN_GATES, TRUE);
  }

  /**
   * The option names a gate accepts, "on" aside.
   *
   * @param string $gate
   *   The gate name.
   *
   * @return list<string>
   *   The accepted option names.
   */
  public static function optionNames(string $gate): array {
    $options = self::GATE_OPTIONS[$gate] ?? [];
    return array_keys($options);
  }

  /**
   * This gate with a config file's entries applied over it.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The gate's mapping from the config file.
   * @param string $source
   *   The document label, for error messages.
   *
   * @return self
   *   A new GateSettings; this one is unchanged.
   *
   * @throws \Droost\Workflow\Config\ConfigError
   *   When the mapping names an option this gate does not accept.
   * @throws \Droost\Workflow\Support\DataError
   *   When a value has the wrong type or falls outside its range.
   */
  public function overlay(TypedArray $node, string $source): self {
    $accepted = self::optionNames($this->name);
    foreach ($node->keys() as $key) {
      if ($key !== 'on' && !in_array($key, $accepted, TRUE)) {
        throw ConfigError::unknownGateOption(
          $source,
          $this->name,
          $key,
          $accepted,
        );
      }
    }

    $options = $this->options;
    foreach ($accepted as $option) {
      if ($node->has($option)) {
        $options[$option] = $this->readOption($node, $option);
      }
    }

    $on = $node->optionalBool('on', $this->on);

    // `level: off` disables the gate. Left alone it would be a second switch,
    // and the resolved levers would report on: true for a gate that never
    // runs — a record that lies about what was checked.
    if (($options['level'] ?? NULL) === 'off') {
      if ($node->has('on') && $node->bool('on')) {
        throw ConfigError::contradictorySwitch($source, $this->name);
      }
      $on = FALSE;
    }

    return new self($this->name, $on, $options);
  }

  /**
   * One option's value.
   *
   * @param string $key
   *   The option name.
   *
   * @return int|string|null
   *   The value, or NULL when this gate carries no such option.
   */
  public function option(string $key): int|string|NULL {
    return $this->options[$key] ?? NULL;
  }

  /**
   * This gate as plain data, for the run-state file and the gate report.
   *
   * @return array<string, int|string|bool>
   *   The "on" flag followed by the options.
   */
  public function toArray(): array {
    return ['on' => $this->on] + $this->options;
  }

  /**
   * Reads one option according to its declared type.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The gate's mapping.
   * @param string $option
   *   The option name.
   *
   * @return int|string
   *   The value.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When the value has the wrong type or falls outside its range.
   */
  private function readOption(TypedArray $node, string $option): int|string {
    $type = self::GATE_OPTIONS[$this->name][$option] ?? 'string';
    return match ($type) {
      'percent' => $node->intInRange($option, 0, 100),
      'level' => $this->readLevel($node, $option),
      default => $this->readToolArgument($node, $option),
    };
  }

  /**
   * Reads a string option that will become an argument to a tool.
   *
   * Nothing in this package executes anything, so this is not defending a
   * live injection — it is defending the layer that owns the vocabulary.
   * `standard` is handed to phpcs by the gate runner, and a value like
   * "Drupal; rm -rf /" or "../../../etc/passwd" is not a coding standard by
   * any reading. Constraining it here means the runner cannot inherit a
   * hostile string no matter how it invokes the tool, rather than every
   * future call site having to remember to quote (the indirect-sanitiser
   * trap: a guard the next reader and every SAST tool can actually see).
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The gate's mapping.
   * @param string $option
   *   The option name.
   *
   * @return string
   *   The value.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When the value is empty or contains anything but the characters a tool
   *   name, standard name or relative path needs.
   */
  private function readToolArgument(
    TypedArray $node,
    string $option,
  ): string {
    $value = $node->string($option);
    // A relative ruleset path is legitimate, so "." and "/" stay allowed —
    // but a ".." component never names a coding standard, and letting one
    // through would hand the gate runner a traversal it has no reason to
    // accept.
    $traverses = $value === '..'
      || str_starts_with($value, '../')
      || str_contains($value, '/../')
      || str_ends_with($value, '/..');
    if ($value === ''
      || $traverses
      || preg_match(self::TOOL_ARGUMENT, $value) !== 1) {
      throw new DataError($node->path($option), sprintf(
        '%s must be a non-empty name, standard or relative path using only '
        . 'letters, digits, and the characters _ - . / , and spaces — got '
        . '"%s"',
        $node->path($option),
        $value,
      ));
    }
    return $value;
  }

  /**
   * Reads a PHPStan level: 0-9, "max" or "off".
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The gate's mapping.
   * @param string $option
   *   The option name.
   *
   * @return int|string
   *   The level.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When the value is neither a level number nor a level word.
   */
  private function readLevel(TypedArray $node, string $option): int|string {
    $value = $node->intOrString($option);
    if (is_int($value)) {
      if ($value < 0 || $value > 9) {
        throw DataError::outOfRange(
          $node->path($option),
          self::LEVEL_CONSTRAINT,
          $value,
        );
      }
      return $value;
    }
    if (!in_array($value, self::LEVEL_WORDS, TRUE)) {
      // Naming the string-ness matters: a quoted "6" is between 0 and 9, so
      // the numeric message would deny something the reader can see is true
      // and never mention that YAML quoting was the actual problem.
      throw new DataError($node->path($option), sprintf(
        '%s must be an integer 0-9, "max" or "off" — got the string "%s"%s',
        $node->path($option),
        $value,
        ctype_digit($value) ? '; remove the quotes to make it a number' : '',
      ));
    }
    return $value;
  }

}
