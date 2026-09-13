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
   * Every named gate a config file may use.
   *
   * Custom gates (declared under `gates.custom`, carried as
   * `custom:<name>`) extend this vocabulary without appearing here — see
   * isKnown() and customFromNode().
   */
  public const KNOWN_GATES = [
    'phpcs',
    'phpstan',
    'eslint',
    'stylelint',
    'prettier',
    'phpunit',
    'mutation',
    'playwright',
    'coverage',
    'rendered_check',
    'config_clean',
    // Resolves the spec's grounding citations against the site's symbol
    // graph. Engine-side this gate has only a name and a phase; the truth
    // lives where the graph does, exactly as rendered_check does.
    'grounding_check',
    'wiki_fresh',
  ];

  /**
   * The gates no lever file can disarm.
   *
   * 0.4's opinion, stated once: the toolchain Drupal core itself develops
   * with — phpcs, phpstan, phpunit, exactly what drupal/core-dev ships — is
   * not optional. Levers still tune HOW they run (standard, level, paths);
   * an `on: false` (or phpstan's `level: off`) on one of these is recorded
   * as a deprecation notice and superseded, the same treatment as the
   * retired phases key. A repo that cannot run one of them yet still gets an
   * honest answer — tool-missing, or the labeled nothing-to-analyse pass —
   * because honesty is the gate's job, not the switch's.
   */
  public const MANDATORY = [
    'phpcs',
    'phpstan',
    'phpunit',
  ];

  /**
   * The prefix carried by custom gates in the resolved set and reports.
   *
   * Namespacing keeps a repo's `semgrep` from colliding with any gate this
   * package might name in a later release.
   */
  public const CUSTOM_PREFIX = 'custom:';

  /**
   * The prefix carried by gates a MODULE contributes (D72).
   *
   * `module:snyk` is declared in code by droost_snyk and joins the run when
   * the module is enabled; the lever file may override only `on` and `mode`
   * for it (under `gates.contributed.snyk`). A different prefix from custom
   * gates so a repo's own `custom:snyk` and a module's never collide, and so
   * a report's provenance is legible from the name alone.
   */
  public const MODULE_PREFIX = 'module:';

  /**
   * The two switches the lever file may override on a contributed gate.
   */
  private const CONTRIBUTED_OVERRIDES = ['on', 'mode'];

  /**
   * What a gate does with a blocking result: fail the phase, or only say so.
   *
   * `block` is every gate's default and the only behaviour that existed
   * before the mode arrived. `report` runs the gate exactly as before but
   * turns a blocking outcome into GateStatus::Reported — the findings ride
   * the report, the seeker reads them, the phase advances. It is the second
   * switch a gate has after `on`, and like `on` it is frozen into the run at
   * begin: WHETHER a gate blocks may not change under a half-finished run,
   * only HOW it runs (its tuning) is re-read at gate time.
   */
  public const MODES = ['block', 'report'];

  /**
   * The mode every gate starts in.
   */
  public const DEFAULT_MODE = 'block';

  /**
   * What a custom gate's name (the key under gates.custom) may look like.
   */
  private const CUSTOM_NAME = '#^[a-z0-9][a-z0-9_-]*$#';

  /**
   * The phases a custom gate may attach to.
   *
   * Complete is deliberately absent as a choice: every enabled gate re-runs
   * there anyway, and "only at complete" would be a gate that skips the
   * phase whose job was to catch its failure early.
   */
  private const CUSTOM_PHASES = ['code', 'test'];

  /**
   * The options each gate accepts, and how each is read.
   *
   * Types: "string", "percent" (an integer 0-100), "level" (0-9, "max" or
   * "off"), "paths" (comma-separated repo-relative paths), "flag" (true or
   * false). A gate absent from a row accepts nothing but "on".
   *
   * `required` (a flag on the functional gates) is what the top of the dial
   * means by "regressions forced": with it set, a missing or empty suite is a
   * FAILURE rather than the labelled nothing-to-run pass. The default, off,
   * is right for a fresh site whose first test the test phase will write.
   *
   * Only the static pair takes `paths`: they are the two tools that can be
   * pointed at a directory with nothing but argv, which is what a repo with
   * no tool configs of its own — a Drupal site root, most importantly —
   * needs. phpunit is deliberately excluded: a test run is defined by its
   * config file (bootstrap, env), and a bare path would invent a suite.
   *
   * Every gate that spawns a tool takes `timeout` (seconds): how long the
   * tool may run before the executor kills it. The executor's default is
   * ten minutes, which a mutation run over one kernel-test-heavy module
   * already exceeds (F-EMT-23) — the slow tiers carry longer defaults from
   * xhigh up, and a repo raises any of them here. The two gates the site
   * driver answers (rendered_check, config_clean) spawn nothing and take
   * none.
   */
  private const GATE_OPTIONS = [
    'phpcs' => ['standard' => 'string', 'paths' => 'paths', 'timeout' => 'seconds'],
    'phpstan' => ['level' => 'level', 'paths' => 'paths', 'timeout' => 'seconds'],
    'phpunit' => ['required' => 'flag', 'timeout' => 'seconds'],
    'mutation' => ['msi_min' => 'percent', 'timeout' => 'seconds'],
    'playwright' => ['required' => 'flag', 'timeout' => 'seconds'],
    'coverage' => ['min' => 'percent', 'timeout' => 'seconds'],
    'rendered_check' => ['routes' => 'string'],
    'config_clean' => [],
    // The front-end lint trio takes `paths` for the same reason the static
    // PHP pair does: pointed at a directory, each tool discovers config with
    // nothing but argv. `config` pins the project's own file instead and
    // turns discovery off — on a Drupal site discovery reaches core's
    // scaffolded .eslintrc.json, which extends plugins only core's own yarn
    // install provides, and eslint crashes before it reads a file (F-EMT-9).
    // A project's package.json lint script names the file to point at.
    'eslint' => ['paths' => 'paths', 'config' => 'string', 'timeout' => 'seconds'],
    'stylelint' => ['paths' => 'paths', 'config' => 'string', 'timeout' => 'seconds'],
    'prettier' => ['paths' => 'paths', 'config' => 'string', 'timeout' => 'seconds'],
    'wiki_fresh' => ['timeout' => 'seconds'],
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
   * @param array<string, int|string|bool> $options
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
    return in_array($name, self::KNOWN_GATES, TRUE) || self::isCustom($name) || self::isContributed($name);
  }

  /**
   * Whether a name denotes a module-contributed gate.
   *
   * @param string $name
   *   The candidate name.
   *
   * @return bool
   *   TRUE for a well-formed module:<id>.
   */
  public static function isContributed(string $name): bool {
    if (!str_starts_with($name, self::MODULE_PREFIX)) {
      return FALSE;
    }
    return preg_match(self::CUSTOM_NAME, substr($name, strlen(self::MODULE_PREFIX))) === 1;
  }

  /**
   * Whether this gate runs its own command rather than a known tool.
   *
   * Custom and contributed gates both carry a `cmd` the executor runs through
   * the shell; the executor's dispatch turns on this, not on the prefix.
   *
   * @return bool
   *   TRUE for custom and contributed gates.
   */
  public function runsOwnCommand(): bool {
    return self::isCustom($this->name) || self::isContributed($this->name);
  }

  /**
   * A contributed gate with the lever file's override applied.
   *
   * Only `on` and `mode` may be overridden: the command, the phases and the
   * verdict are the declaring module's contract. A repo that wants a
   * different scan writes a custom gate; a repo that wants this one to block
   * writes `mode: block`, in one line, in a reviewable diff.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The gates.contributed.<id> mapping.
   * @param string $source
   *   The document label, for error messages.
   *
   * @return self
   *   A new GateSettings; this one is unchanged.
   *
   * @throws \Droost\Workflow\Config\ConfigError
   *   When the mapping names anything but `on` or `mode`.
   * @throws \Droost\Workflow\Support\DataError
   *   When a value has the wrong type.
   */
  public function overlayContributed(TypedArray $node, string $source): self {
    foreach ($node->keys() as $key) {
      if (!in_array($key, self::CONTRIBUTED_OVERRIDES, TRUE)) {
        throw ConfigError::unknownContributedOption($source, substr($this->name, strlen(self::MODULE_PREFIX)), $key);
      }
    }
    $options = $this->options;
    if ($node->has('mode')) {
      if (self::readModeWord($node) === 'report') {
        $options['mode'] = 'report';
      }
      else {
        unset($options['mode']);
      }
    }
    return new self($this->name, $node->optionalBool('on', $this->on), $options);
  }

  /**
   * Whether a name denotes a custom gate.
   *
   * @param string $name
   *   The candidate name.
   *
   * @return bool
   *   TRUE for a well-formed custom:<name>.
   */
  public static function isCustom(string $name): bool {
    if (!str_starts_with($name, self::CUSTOM_PREFIX)) {
      return FALSE;
    }
    $bare = substr($name, strlen(self::CUSTOM_PREFIX));
    return preg_match(self::CUSTOM_NAME, $bare) === 1;
  }

  /**
   * Builds a custom gate from its gates.custom entry.
   *
   * Everything is explicit — `on`, `phase` and `cmd` are all required.
   * Custom gates have no preset base to inherit from, so an absent switch
   * is not "whatever the preset said", it is ambiguity; and this package's
   * one rule about switches is that none of them is ever inferred.
   *
   * @param string $key
   *   The name under gates.custom.
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The entry's mapping.
   * @param string $source
   *   The document label, for error messages.
   *
   * @return self
   *   The gate, named custom:<key>.
   *
   * @throws \Droost\Workflow\Config\ConfigError
   *   When the name is malformed or a required key is missing/invalid.
   * @throws \Droost\Workflow\Support\DataError
   *   When a value has the wrong type.
   */
  public static function customFromNode(
    string $key,
    TypedArray $node,
    string $source,
  ): self {
    if (preg_match(self::CUSTOM_NAME, $key) !== 1) {
      throw ConfigError::invalidCustomGate($source, $key, sprintf(
        'the name must match %s (lowercase letters, digits, "_", "-")',
        self::CUSTOM_NAME,
      ));
    }
    foreach (['on', 'phase', 'cmd'] as $required) {
      if (!$node->has($required)) {
        throw ConfigError::invalidCustomGate($source, $key, sprintf(
          '"%s" is required — a custom gate has no preset base, so nothing '
          . 'about it is inferred',
          $required,
        ));
      }
    }
    foreach ($node->keys() as $given) {
      if (!in_array($given, ['on', 'phase', 'cmd', 'mode'], TRUE)) {
        throw ConfigError::invalidCustomGate($source, $key, sprintf(
          'unknown option "%s" (accepted: on, phase, cmd, mode)',
          $given,
        ));
      }
    }
    // The `phase` option accepts one phase or a comma-separated list, the same
    // shape as `paths`, so a gate can attach to code AND test — a Snyk scan,
    // say, that must run as code lands and again under the test phase. Each
    // entry is validated; the deduplicated, order-preserving list is stored.
    $phases = [];
    foreach (explode(',', $node->string('phase')) as $candidate) {
      $candidate = trim($candidate);
      if ($candidate === '') {
        continue;
      }
      if (!in_array($candidate, self::CUSTOM_PHASES, TRUE)) {
        throw ConfigError::invalidCustomGate($source, $key, sprintf(
          'phase must be one or more of: %s (comma-separated; everything '
          . 'enabled re-runs at complete)',
          implode(', ', self::CUSTOM_PHASES),
        ));
      }
      if (!in_array($candidate, $phases, TRUE)) {
        $phases[] = $candidate;
      }
    }
    if ($phases === []) {
      throw ConfigError::invalidCustomGate($source, $key, sprintf(
        'phase must name at least one of: %s',
        implode(', ', self::CUSTOM_PHASES),
      ));
    }
    $phase = implode(',', $phases);
    $cmd = $node->string('cmd');
    // The command is the repo's own, reviewed in the same diff as every
    // other lever — the constraint here is shape, not trust: one line,
    // printable, non-empty.
    if (trim($cmd) === ''
      || str_contains($cmd, "\n")
      || str_contains($cmd, "\0")) {
      throw ConfigError::invalidCustomGate(
        $source,
        $key,
        'cmd must be a non-empty single-line command',
      );
    }
    $options = [
      'cmd' => $cmd,
      'phase' => $phase,
    ];
    // `mode` is optional here because block is every gate's default; only
    // `report` is recorded, so an unchanged file resolves to unchanged levers.
    if ($node->has('mode') && self::readModeWord($node) === 'report') {
      $options['mode'] = 'report';
    }
    return new self(self::CUSTOM_PREFIX . $key, $node->bool('on'), $options);
  }

  /**
   * What this gate does with a blocking result.
   *
   * @return string
   *   'block' (the default) or 'report'.
   */
  public function mode(): string {
    return ($this->options['mode'] ?? NULL) === 'report' ? 'report' : self::DEFAULT_MODE;
  }

  /**
   * Reads the `mode` word: block or report.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The gate's mapping, which has a `mode` key.
   *
   * @return string
   *   The mode.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When the word is outside block|report.
   */
  private static function readModeWord(TypedArray $node): string {
    $word = $node->string('mode');
    if (!in_array($word, self::MODES, TRUE)) {
      throw new DataError($node->path('mode'), sprintf(
        '%s must be "block" (fail the phase on a blocking result) or "report" '
        . '(record it and advance) — got "%s"',
        $node->path('mode'),
        $word,
      ));
    }
    return $word;
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
      // `on` and `mode` are the two switches every gate has; the accepted
      // list is the gate's tuning vocabulary.
      if ($key !== 'on' && $key !== 'mode' && !in_array($key, $accepted, TRUE)) {
        throw ConfigError::unknownGateOption(
          $source,
          $this->name,
          $key,
          [...$accepted, 'mode'],
        );
      }
    }

    $options = $this->options;
    foreach ($accepted as $option) {
      if ($node->has($option)) {
        $options[$option] = $this->readOption($node, $option);
      }
    }
    if ($node->has('mode')) {
      // Recorded only as `report`: block is the default, and an explicit
      // `mode: block` resolves to the same levers as saying nothing.
      if (self::readModeWord($node) === 'report') {
        $options['mode'] = 'report';
      }
      else {
        unset($options['mode']);
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
   * @return int|string|bool|null
   *   The value, or NULL when this gate carries no such option.
   */
  public function option(string $key): int|string|bool|NULL {
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
   * @return int|string|bool
   *   The value.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When the value has the wrong type or falls outside its range.
   */
  private function readOption(TypedArray $node, string $option): int|string|bool {
    $type = self::GATE_OPTIONS[$this->name][$option] ?? 'string';
    return match ($type) {
      'percent' => $node->intInRange($option, 0, 100),
      // At least a second; a day is the ceiling anyone should ever want.
      'seconds' => $node->intInRange($option, 1, 86400),
      'level' => $this->readLevel($node, $option),
      'paths' => $this->readPaths($node, $option),
      'flag' => $node->bool($option),
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
    if ($value === ''
      || self::traverses($value)
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
   * Reads a comma-separated list of repo-relative analysis paths.
   *
   * Validated per component, because the whole-string traversal check would
   * wave through "src,.." — the ".." hides behind the comma. Each component
   * must be a relative path: the gate runner roots every invocation at the
   * project, and an absolute path would let a lever file point the analysis
   * outside the repo whose diff reviewed it.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The gate's mapping.
   * @param string $option
   *   The option name.
   *
   * @return string
   *   The list, normalized to trimmed components joined by ",".
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When a component is empty, absolute, traversing, or carries a
   *   character no path needs.
   */
  private function readPaths(TypedArray $node, string $option): string {
    $value = $node->string($option);
    $components = array_map(trim(...), explode(',', $value));
    foreach ($components as $component) {
      if ($component === ''
        || str_starts_with($component, '/')
        || self::traverses($component)
        || preg_match(self::TOOL_ARGUMENT, $component) !== 1) {
        throw new DataError($node->path($option), sprintf(
          '%s must be a comma-separated list of repo-relative paths — '
          . '"%s" is not one',
          $node->path($option),
          $component,
        ));
      }
    }
    return implode(',', $components);
  }

  /**
   * Whether a value contains a ".." path component.
   *
   * A relative ruleset path is legitimate, so "." and "/" stay allowed — but
   * a ".." component never names a coding standard or an analysis target,
   * and letting one through would hand the gate runner a traversal it has no
   * reason to accept.
   *
   * @param string $value
   *   The candidate.
   *
   * @return bool
   *   TRUE when the value traverses upward.
   */
  private static function traverses(string $value): bool {
    return $value === '..'
      || str_starts_with($value, '../')
      || str_contains($value, '/../')
      || str_ends_with($value, '/..');
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
