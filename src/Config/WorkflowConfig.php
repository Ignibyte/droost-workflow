<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

use Droost\Workflow\Support\DataError;
use Droost\Workflow\Support\TypedArray;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The resolved levers for a workflow run.
 *
 * Read from a repo-root droost.workflow.yml with no Drupal in the picture: the
 * agent must be able to read its own levers while the site is mid-build or
 * broken, and a plain Claude Code or Codex user must get the same answers from
 * the same file with no site at all. Nothing in this class, or anything it
 * calls, touches the Drupal container.
 *
 * Unknown keys are errors. A config loader that shrugs at "phpstain:" hands
 * back a run with static analysis quietly disabled and a report that says
 * everything passed.
 */
final class WorkflowConfig {

  /**
   * The lever file's name at the project root.
   */
  public const FILENAME = 'droost.workflow.yml';

  /**
   * The top-level keys the file may contain.
   */
  private const KNOWN_SETTINGS = [
    'mode',
    'phases',
    'preset',
    'gates',
    'max_gate_retries',
    'enforcement',
    'seekers',
  ];

  /**
   * What the seeker checkpoint may see: the browser capabilities aside, the
   * only lever is the switch. The one-hop blast radius and the six lenses
   * are the pattern, not configuration — a per-repo remap of what the
   * reviewer looks at would be a reviewer you can negotiate with.
   */
  private const SEEKER_OPTIONS = ['on'];

  /**
   * The largest retry bound a run may configure.
   */
  private const MAX_RETRIES_CEILING = 10;

  /**
   * Constructs a WorkflowConfig.
   *
   * @param \Droost\Workflow\Config\Mode $mode
   *   Automated or pair.
   * @param list<\Droost\Workflow\Config\Phase> $phases
   *   The phases this run executes, in canonical relative order.
   * @param array<string, \Droost\Workflow\Config\GateSettings> $gates
   *   Every known gate, keyed by name — resolved, not merely as written.
   * @param string $preset
   *   The preset these levers were resolved from.
   * @param int $maxGateRetries
   *   How many times a failing gate may drive the feedback loop.
   * @param \Droost\Workflow\Config\Provenance $provenance
   *   Whether a file was read or the built-in defaults are in force.
   * @param \Droost\Workflow\Config\Enforcement $enforcement
   *   How hard the harness hooks hold the phase discipline mid-run.
   * @param list<string> $deprecations
   *   Notices for keys the file still uses but the vocabulary has retired.
   *   Surfaced by every surface that reports levers; never fatal.
   * @param bool $seekers
   *   Whether the adversarial-review checkpoint holds the run between code
   *   and test, and again at complete. On by default: the seeker is the
   *   judgment half of the gate set, and a repo that says nothing has not
   *   opted out of judgment. Turning it off is allowed and recorded, like
   *   enforcement: off — a visible loosening in a reviewable diff.
   */
  private function __construct(
    public readonly Mode $mode,
    public readonly array $phases,
    public readonly array $gates,
    public readonly string $preset,
    public readonly int $maxGateRetries,
    public readonly Provenance $provenance,
    public readonly Enforcement $enforcement = Enforcement::Soft,
    public readonly array $deprecations = [],
    public readonly bool $seekers = TRUE,
  ) {}

  /**
   * Loads the levers for a project.
   *
   * @param string $projectRoot
   *   The repo root to look in.
   *
   * @return self
   *   The resolved configuration; built-in factory defaults when no file
   *   exists.
   *
   * @throws \Droost\Workflow\Config\ConfigError
   *   When the file exists but cannot be read or understood.
   * @throws \InvalidArgumentException
   *   When the project root is empty, is the filesystem root, or is not a
   *   directory.
   */
  public static function load(string $projectRoot): self {
    $path = TypedArray::requireProjectRoot($projectRoot)
      . '/' . self::FILENAME;

    // is_link() first: file_exists() FOLLOWS a symlink, so a dangling one
    // reads as "no file" and would hand back the built-in levers — the exact
    // silent substitution the checks below exist to refuse.
    if (!is_link($path) && !file_exists($path)) {
      return self::builtIn();
    }

    // Everything below is the same principle: something IS there, so silently
    // substituting the built-in levers would swap the repo's gates for
    // different ones and call that normal. Only genuine absence is allowed to
    // fall back.
    if (!is_file($path)) {
      throw ConfigError::notRegularFile(self::FILENAME);
    }
    if (!is_readable($path)) {
      throw ConfigError::unreadable(self::FILENAME);
    }

    try {
      $parsed = Yaml::parseFile($path);
    }
    catch (ParseException $e) {
      throw ConfigError::unparseable(self::FILENAME, $e, $path);
    }

    // An empty file is a legitimate statement: "the defaults, and I have
    // committed that choice".
    if ($parsed === NULL) {
      $parsed = [];
    }
    if (!is_array($parsed)) {
      throw ConfigError::notMapping(self::FILENAME, get_debug_type($parsed));
    }
    // A YAML sequence decodes to a PHP array too, so is_array() alone lets a
    // list through to be reported as an unknown setting called "0" — true, but
    // useless. An empty array is exempt: that is the empty file above.
    if ($parsed !== [] && array_is_list($parsed)) {
      throw ConfigError::notMapping(self::FILENAME, 'a list');
    }

    return self::fromArray($parsed, self::FILENAME, Provenance::File);
  }

  /**
   * The configuration in force when a project ships no lever file.
   *
   * @return self
   *   The factory defaults, marked as built-in.
   */
  public static function builtIn(): self {
    return self::fromArray([], '<built-in defaults>', Provenance::BuiltIn);
  }

  /**
   * Resolves a decoded mapping into a configuration.
   *
   * @param array<array-key, mixed> $raw
   *   The decoded document.
   * @param string $source
   *   The document label, for error messages.
   * @param \Droost\Workflow\Config\Provenance $provenance
   *   Where the document came from.
   *
   * @return self
   *   The resolved configuration.
   *
   * @throws \Droost\Workflow\Config\ConfigError
   *   When the document names anything the vocabulary does not contain, or a
   *   value has the wrong type.
   */
  public static function fromArray(
    array $raw,
    string $source,
    Provenance $provenance = Provenance::File,
  ): self {
    $root = TypedArray::authored($raw);

    foreach ($root->keys() as $key) {
      if (!in_array($key, self::KNOWN_SETTINGS, TRUE)) {
        throw ConfigError::unknownSetting($source, $key, self::KNOWN_SETTINGS);
      }
    }

    try {
      $preset = $root->optionalString(
        'preset',
        PresetResolver::DEFAULT_PRESET,
      ) ?? PresetResolver::DEFAULT_PRESET;
      if (isset(PresetResolver::RENAMED_PRESETS[$preset])) {
        throw ConfigError::renamedPreset(
          $source,
          $preset,
          PresetResolver::RENAMED_PRESETS[$preset],
        );
      }
      if (!PresetResolver::isKnown($preset)) {
        throw ConfigError::unknownPreset(
          $source,
          $preset,
          PresetResolver::KNOWN_PRESETS,
        );
      }
      $base = PresetResolver::resolve($preset);

      // The phases key is deprecated (0.3): every run walks the canonical
      // sequence. A present key is still VALIDATED — a typo should surface,
      // not vanish into a notice — and then superseded, with the supersession
      // recorded where every lever report will show it.
      $deprecations = [];
      if ($root->has('phases')) {
        self::readPhases($root, $source);
        $deprecations[] = ConfigError::phasesDeprecationNotice($source);
      }

      return new self(
        self::readMode($root, $source, $base->mode),
        Phase::canonical(),
        self::readGates($root, $source, $base->gates, $deprecations),
        $base->name,
        $root->has('max_gate_retries')
          ? $root->intInRange(
            'max_gate_retries',
            0,
            self::MAX_RETRIES_CEILING,
          )
          : $base->maxGateRetries,
        $provenance,
        self::readEnforcement($root, $source, $base->enforcement),
        $deprecations,
        self::readSeekers($root, $source),
      );
    }
    catch (DataError $e) {
      throw ConfigError::fromData($source, $e);
    }
  }

  /**
   * Reads the enforcement lever.
   *
   * Orthogonal to the preset on purpose: a repo may pair the factory gate
   * set with enforcement off — not advised, but a lever file is a reviewable
   * diff, and a visible loosening is the honest way to allow it.
   *
   * @param \Droost\Workflow\Support\TypedArray $root
   *   The document root.
   * @param string $source
   *   The document label.
   * @param \Droost\Workflow\Config\Enforcement $default
   *   The preset's enforcement.
   *
   * @return \Droost\Workflow\Config\Enforcement
   *   The resolved enforcement.
   *
   * @throws \Droost\Workflow\Config\ConfigError
   *   When the value is outside hard|soft|off.
   */
  private static function readEnforcement(
    TypedArray $root,
    string $source,
    Enforcement $default,
  ): Enforcement {
    if (!$root->has('enforcement')) {
      return $default;
    }
    $name = $root->string('enforcement');
    $enforcement = Enforcement::tryFrom($name);
    if ($enforcement === NULL) {
      throw ConfigError::unknownEnforcement(
        $source,
        $name,
        Enforcement::names(),
      );
    }
    return $enforcement;
  }

  /**
   * Reads the seekers block.
   *
   * @param \Droost\Workflow\Support\TypedArray $root
   *   The document root.
   * @param string $source
   *   The document label.
   *
   * @return bool
   *   Whether the checkpoint is armed.
   *
   * @throws \Droost\Workflow\Config\ConfigError
   *   When the block carries anything but its one switch.
   * @throws \Droost\Workflow\Support\DataError
   *   When the switch is not a boolean.
   */
  private static function readSeekers(
    TypedArray $root,
    string $source,
  ): bool {
    $node = $root->optionalChild('seekers');
    if ($node === NULL) {
      return TRUE;
    }
    foreach ($node->keys() as $key) {
      if (!in_array($key, self::SEEKER_OPTIONS, TRUE)) {
        throw ConfigError::unknownSeekersOption($source, $key);
      }
    }
    return $node->optionalBool('on', TRUE);
  }

  /**
   * One gate's resolved levers.
   *
   * @param string $name
   *   A name from GateSettings::KNOWN_GATES.
   *
   * @return \Droost\Workflow\Config\GateSettings
   *   The gate.
   *
   * @throws \InvalidArgumentException
   *   When the name is not a known gate — a programming error, not a config
   *   one, since every known gate is always present.
   */
  public function gate(string $name): GateSettings {
    if (!isset($this->gates[$name])) {
      throw new \InvalidArgumentException(
        sprintf('No such gate: %s', $name),
      );
    }
    return $this->gates[$name];
  }

  /**
   * Whether this run executes a phase.
   *
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase.
   *
   * @return bool
   *   TRUE when configured.
   */
  public function hasPhase(Phase $phase): bool {
    return in_array($phase, $this->phases, TRUE);
  }

  /**
   * Every gate's resolved levers as plain data.
   *
   * This is what a run records and a report renders, so that "which levers
   * was this run actually held to" is answerable from the artefacts alone.
   *
   * @return array<string, array<string, int|string|bool>>
   *   Gate name to its "on" flag and options.
   */
  public function resolvedGates(): array {
    $out = [];
    foreach ($this->gates as $name => $gate) {
      $out[$name] = $gate->toArray();
    }
    return $out;
  }

  /**
   * The phase names this run executes.
   *
   * @return list<string>
   *   The backing values, in execution order.
   */
  public function phaseNames(): array {
    return array_map(
      static fn (Phase $p): string => $p->value,
      $this->phases,
    );
  }

  /**
   * Reads the mode.
   *
   * @param \Droost\Workflow\Support\TypedArray $root
   *   The document root.
   * @param string $source
   *   The document label.
   * @param \Droost\Workflow\Config\Mode $default
   *   The preset's mode.
   *
   * @return \Droost\Workflow\Config\Mode
   *   The mode.
   *
   * @throws \Droost\Workflow\Config\ConfigError
   *   When the name is not a known mode.
   * @throws \Droost\Workflow\Support\DataError
   *   When the value is not a string.
   */
  private static function readMode(
    TypedArray $root,
    string $source,
    Mode $default,
  ): Mode {
    if (!$root->has('mode')) {
      return $default;
    }
    $name = $root->string('mode');
    $mode = Mode::tryFrom($name);
    if ($mode === NULL) {
      throw ConfigError::unknownMode($source, $name, Mode::names());
    }
    return $mode;
  }

  /**
   * Reads the phase sequence.
   *
   * @param \Droost\Workflow\Support\TypedArray $root
   *   The document root.
   * @param string $source
   *   The document label.
   *
   * @return list<\Droost\Workflow\Config\Phase>
   *   The phases, in execution order.
   *
   * @throws \Droost\Workflow\Config\ConfigError
   *   When a name is unknown, a required phase is dropped, or the order is
   *   not a subsequence of the canonical one.
   * @throws \Droost\Workflow\Support\DataError
   *   When the value is not a list of strings.
   */
  private static function readPhases(
    TypedArray $root,
    string $source,
  ): array {
    if (!$root->has('phases')) {
      return Phase::canonical();
    }

    $names = $root->stringList('phases');
    $phases = [];
    foreach ($names as $name) {
      // "document" was a real phase when this key was last honoured (0.3's
      // five), so a file that lists it is speaking the vocabulary the key
      // belongs to. The key is superseded anyway; refusing the file over a
      // word that was correct when written would punish exactly the files
      // the deprecation notice exists to shepherd.
      if ($name === 'document') {
        continue;
      }
      $phase = Phase::tryFrom($name);
      if ($phase === NULL) {
        throw ConfigError::unknownPhase($source, $name, Phase::names());
      }
      $phases[] = $phase;
    }

    foreach (Phase::REQUIRED as $required) {
      if (!in_array($required, $phases, TRUE)) {
        throw ConfigError::missingRequiredPhase($source, $required);
      }
    }

    // Duplicates are checked before ordering. The subsequence test rejects
    // both, but telling someone their phases are "out of order" when they
    // merely repeated one sends them looking in the wrong place.
    $seen = [];
    foreach ($names as $name) {
      if (isset($seen[$name])) {
        throw ConfigError::duplicatePhase($source, $name);
      }
      $seen[$name] = TRUE;
    }

    if (!Phase::isSubsequence($phases)) {
      throw ConfigError::phasesOutOfOrder($source, $names);
    }

    return $phases;
  }

  /**
   * Reads the gate overrides onto the preset's base set.
   *
   * @param \Droost\Workflow\Support\TypedArray $root
   *   The document root.
   * @param string $source
   *   The document label.
   * @param array<string, \Droost\Workflow\Config\GateSettings> $base
   *   The preset's gate set.
   *
   * @return array<string, \Droost\Workflow\Config\GateSettings>
   *   Every known gate, resolved.
   *
   * @throws \Droost\Workflow\Config\ConfigError
   *   When a gate or one of its options is unknown.
   * @throws \Droost\Workflow\Support\DataError
   *   When a value has the wrong type or falls outside its range.
   */
  private static function readGates(
    TypedArray $root,
    string $source,
    array $base,
    array &$deprecations = [],
  ): array {
    $node = $root->optionalChild('gates');
    if ($node === NULL) {
      return $base;
    }

    $gates = $base;
    foreach ($node->keys() as $name) {
      // gates.custom is a namespace, not a gate: each child becomes a
      // custom:<name> gate built whole from its entry (no preset base to
      // overlay). Appended after the named set so reports keep a stable
      // named-then-custom order.
      if ($name === 'custom') {
        foreach ($node->child('custom')->keys() as $key) {
          $gates[GateSettings::CUSTOM_PREFIX . $key] =
            GateSettings::customFromNode($key, $node->child('custom')->child($key), $source);
        }
        continue;
      }
      if (!GateSettings::isKnown($name)) {
        throw ConfigError::unknownGate(
          $source,
          $name,
          GateSettings::KNOWN_GATES,
        );
      }
      $entry = $node->child($name);
      // The mandatory trio cannot be disarmed. A disarm attempt is treated
      // exactly like the retired phases key: validated vocabulary, recorded
      // notice, superseded value — never silently obeyed, never fatal. The
      // stripped entry still overlays, so tuning levers beside the attempt
      // ("on: false" next to a paths list) keep their effect.
      if (in_array($name, GateSettings::MANDATORY, TRUE)) {
        $raw = $entry->toArray();
        $attempted = [];
        if (array_key_exists('on', $raw) && $raw['on'] === FALSE) {
          $attempted[] = 'on: false';
          unset($raw['on']);
        }
        if ($name === 'phpstan' && ($raw['level'] ?? NULL) === 'off') {
          $attempted[] = 'level: off';
          unset($raw['level']);
        }
        if ($attempted !== []) {
          $deprecations[] = ConfigError::mandatoryGateNotice(
            $source,
            $name,
            implode('", "', $attempted),
          );
          $entry = TypedArray::authored($raw);
        }
      }
      $gates[$name] = $gates[$name]->overlay($entry, $source);
    }

    return $gates;
  }

}
