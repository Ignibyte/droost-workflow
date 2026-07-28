<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Support;

/**
 * The one place this package reads INTO decoded data.
 *
 * Both of the package's inputs arrive as mixed: Yaml::parseFile() for the
 * lever file and json_decode() for run state. Reading them by chained offset
 * ($raw['gates']['phpstan']['level']) is an offset-access-on-mixed error under
 * PHPStan level max, and narrowing only the outermost value does not help —
 * each level needs its own check.
 *
 * Rather than repeat that discipline at every read site, every accessor here
 * narrows exactly ONE level and returns a concrete type, and nesting is done
 * with child() rather than by chaining offsets.
 *
 * The boundary is precise, and worth stating precisely: each of the two
 * loaders decodes its own document and checks that the result is an array at
 * all — two statements, in WorkflowConfig::load() and RunStateStore::load() —
 * and hands it here. Past that point no class touches a mixed value. So
 * level-max cleanliness is a property of the structure rather than something
 * reviewers must keep noticing, and there are exactly two lines to audit if
 * that ever stops being true.
 *
 * The dotted path is carried down through child() so an error names the key
 * a human has to go and fix.
 */
final class TypedArray {

  /**
   * Constructs a TypedArray.
   *
   * @param array<array-key, mixed> $data
   *   The decoded data at this level.
   * @param bool $nullMeansAbsent
   *   Whether a key written with an explicit null counts as absent.
   * @param string $prefix
   *   The dotted path of this level, empty at the document root.
   */
  private function __construct(
    private readonly array $data,
    private readonly bool $nullMeansAbsent,
    private readonly string $prefix = '',
  ) {}

  /**
   * Validates a caller-supplied project root.
   *
   * Lives here because both the config loader and the state store need the
   * identical check, and three separate near-misses is how the empty-string
   * case got fixed in one of them and not the other.
   *
   * @param string $projectRoot
   *   The candidate root.
   *
   * @return string
   *   The root with any trailing slash removed.
   *
   * @throws \InvalidArgumentException
   *   When the root is empty, is the filesystem root, or is not a directory.
   *   Each would otherwise fail somewhere surprising: an unset variable
   *   resolves to "/", making an autonomous run read /droost.workflow.yml and
   *   write /.droost-workflow — which fails on permissions here and SUCCEEDS
   *   as root in a container. A mistyped root that simply does not exist is
   *   worse than an error, because it reads as "this repo has no lever file"
   *   and hands back defaults the repo never asked for.
   */
  public static function requireProjectRoot(string $projectRoot): string {
    $trimmed = rtrim(trim($projectRoot), '/');
    if ($trimmed === '') {
      throw new \InvalidArgumentException(sprintf(
        'The project root must be a directory, got "%s".',
        $projectRoot,
      ));
    }
    if (!is_dir($trimmed)) {
      throw new \InvalidArgumentException(sprintf(
        'The project root "%s" is not a directory.',
        $projectRoot,
      ));
    }
    return $trimmed;
  }

  /**
   * A reader for a human-written document, where blank means blank.
   *
   * `coverage:\n  min:` is a sentence somebody typed on purpose, and the
   * likeliest reason for it is a half-finished edit. Reading it as "said
   * nothing" would discard a deliberate statement in silence — the same
   * silent-weakening this package refuses everywhere else — so an explicit
   * null here is a value, and asking for it as an int reports that the key
   * was left empty.
   *
   * @param array<array-key, mixed> $data
   *   The decoded document.
   *
   * @return self
   *   The reader.
   */
  public static function authored(array $data): self {
    return new self($data, FALSE);
  }

  /**
   * A reader for a document this package wrote itself.
   *
   * Here null is not a mistake, it is this package's own spelling of an unset
   * field: toArray() emits `mode_override: null` for a run that has never
   * swapped modes. Treating that as a missing string is what once made every
   * state file unreadable by the code that wrote it.
   *
   * @param array<array-key, mixed> $data
   *   The decoded document.
   *
   * @return self
   *   The reader.
   */
  public static function serialized(array $data): self {
    return new self($data, TRUE);
  }

  /**
   * Whether a key carries a value.
   *
   * Whether an explicit null counts as a value depends on who wrote the
   * document, which is why that is decided once by the named constructor
   * rather than per call site. Two earlier attempts got this wrong in
   * opposite directions: making null always a value meant no state file could
   * be read back, and making null always absent meant every blank lever in
   * the config file was discarded in silence. A second family of nullable*
   * accessors fixed both but left the next author guessing which family a new
   * field needed, with a runtime error and no static warning if they guessed
   * wrong. Binding the rule to the document removes the choice entirely.
   *
   * @param string $key
   *   The key.
   *
   * @return bool
   *   TRUE when the key is present and carries a value.
   */
  public function has(string $key): bool {
    if (!array_key_exists($key, $this->data)) {
      return FALSE;
    }
    return !($this->nullMeansAbsent && $this->data[$key] === NULL);
  }

  /**
   * The keys at this level, in document order.
   *
   * @return list<string>
   *   The keys, cast to strings.
   */
  public function keys(): array {
    return array_map(strval(...), array_keys($this->data));
  }

  /**
   * The raw data at this level.
   *
   * Used to round-trip fields this build does not interpret. Callers must not
   * read into the result — that is what the accessors are for.
   *
   * @return array<array-key, mixed>
   *   The data.
   */
  public function toArray(): array {
    return $this->data;
  }

  /**
   * A required string.
   *
   * @param string $key
   *   The key.
   *
   * @return string
   *   The value.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When absent or not a string.
   */
  public function string(string $key): string {
    $value = $this->require($key);
    if (!is_string($value)) {
      throw DataError::wrongType($this->path($key), 'a string', $value);
    }
    return $value;
  }

  /**
   * A required boolean.
   *
   * @param string $key
   *   The key.
   *
   * @return bool
   *   The value.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When absent or not a boolean.
   */
  public function bool(string $key): bool {
    $value = $this->require($key);
    if (!is_bool($value)) {
      throw DataError::wrongType($this->path($key), 'true or false', $value);
    }
    return $value;
  }

  /**
   * A required integer.
   *
   * @param string $key
   *   The key.
   *
   * @return int
   *   The value.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When absent or not an integer.
   */
  public function int(string $key): int {
    $value = $this->require($key);
    if (!is_int($value)) {
      throw DataError::wrongType($this->path($key), 'an integer', $value);
    }
    return $value;
  }

  /**
   * A required integer within an inclusive range.
   *
   * @param string $key
   *   The key.
   * @param int $min
   *   The inclusive minimum.
   * @param int $max
   *   The inclusive maximum.
   *
   * @return int
   *   The value.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When absent, not an integer, or out of range.
   */
  public function intInRange(string $key, int $min, int $max): int {
    $value = $this->int($key);
    if ($value < $min || $value > $max) {
      throw DataError::outOfRange(
        $this->path($key),
        sprintf('between %d and %d', $min, $max),
        $value,
      );
    }
    return $value;
  }

  /**
   * A required value that may be an integer or a string.
   *
   * For levers whose vocabulary mixes the two — a PHPStan level is 0-9, "max"
   * or "off" — so that the caller can range-check the number and match the
   * word without ever handling a mixed value itself.
   *
   * @param string $key
   *   The key.
   *
   * @return int|string
   *   The value.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When absent or neither an integer nor a string.
   */
  public function intOrString(string $key): int|string {
    $value = $this->require($key);
    if (!is_int($value) && !is_string($value)) {
      throw DataError::wrongType(
        $this->path($key),
        'an integer or a string',
        $value,
      );
    }
    return $value;
  }

  /**
   * A required list of strings.
   *
   * @param string $key
   *   The key.
   *
   * @return list<string>
   *   The values.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When absent, not a list, or any element is not a string.
   */
  public function stringList(string $key): array {
    $value = $this->require($key);
    if (!is_array($value) || !array_is_list($value)) {
      throw DataError::wrongType($this->path($key), 'a list', $value);
    }
    $out = [];
    foreach ($value as $index => $element) {
      if (!is_string($element)) {
        throw DataError::wrongType(
          sprintf('%s[%d]', $this->path($key), $index),
          'a string',
          $element,
        );
      }
      $out[] = $element;
    }
    return $out;
  }

  /**
   * A required nested mapping, as its own TypedArray.
   *
   * @param string $key
   *   The key.
   *
   * @return self
   *   The nested level, carrying the extended path.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When absent or not a mapping.
   */
  public function child(string $key): self {
    $value = $this->require($key);
    if (!is_array($value)) {
      throw DataError::wrongType($this->path($key), 'a mapping', $value);
    }
    return new self($value, $this->nullMeansAbsent, $this->path($key));
  }

  /**
   * An optional string.
   *
   * @param string $key
   *   The key.
   * @param string|null $default
   *   The value to return when the key is absent.
   *
   * @return string|null
   *   The value or the default.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When present but not a string.
   */
  public function optionalString(
    string $key,
    ?string $default = NULL,
  ): ?string {
    return $this->has($key) ? $this->string($key) : $default;
  }

  /**
   * An optional boolean.
   *
   * @param string $key
   *   The key.
   * @param bool $default
   *   The value to return when the key is absent.
   *
   * @return bool
   *   The value or the default.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When present but not a boolean.
   */
  public function optionalBool(string $key, bool $default): bool {
    return $this->has($key) ? $this->bool($key) : $default;
  }

  /**
   * An optional integer.
   *
   * @param string $key
   *   The key.
   * @param int $default
   *   The value to return when the key is absent.
   *
   * @return int
   *   The value or the default.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When present but not an integer.
   */
  public function optionalInt(string $key, int $default): int {
    return $this->has($key) ? $this->int($key) : $default;
  }

  /**
   * An optional nested mapping.
   *
   * @param string $key
   *   The key.
   *
   * @return self|null
   *   The nested level, or NULL when the key is absent.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When present but not a mapping.
   */
  public function optionalChild(string $key): ?self {
    return $this->has($key) ? $this->child($key) : NULL;
  }

  /**
   * An optional list of strings.
   *
   * @param string $key
   *   The key.
   * @param list<string> $default
   *   The value to return when the key is absent.
   *
   * @return list<string>
   *   The values or the default.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When present but not a list of strings.
   */
  public function optionalStringList(string $key, array $default): array {
    return $this->has($key) ? $this->stringList($key) : $default;
  }

  /**
   * The dotted path of a key at this level.
   *
   * @param string $key
   *   The key.
   *
   * @return string
   *   The dotted path.
   */
  public function path(string $key): string {
    return $this->prefix === '' ? $key : $this->prefix . '.' . $key;
  }

  /**
   * The raw value at a key, asserting only presence.
   *
   * @param string $key
   *   The key.
   *
   * @return mixed
   *   The value.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When absent.
   */
  private function require(string $key): mixed {
    if (!array_key_exists($key, $this->data)) {
      throw DataError::missing($this->path($key));
    }
    return $this->data[$key];
  }

}
