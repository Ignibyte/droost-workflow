<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Support;

/**
 * A decoded value was absent or had the wrong type.
 *
 * Thrown only by TypedArray. Callers wrap it in their own typed error —
 * ConfigError for the lever file, StateError for run state — so the layer
 * that failed is named by the exception class and the exact key is named by
 * the message.
 */
final class DataError extends \RuntimeException {

  /**
   * How much of an offending value to quote back.
   *
   * Long enough to identify a typo'd lever, short enough that a pasted blob
   * does not become the whole error message.
   */
  private const MAX_SHOWN_VALUE = 40;

  /**
   * The dotted path of the offending key, relative to the source document.
   */
  public readonly string $path;

  /**
   * Constructs a DataError.
   *
   * @param string $path
   *   The dotted path of the offending key, e.g. "gates.phpstan.level".
   * @param string $message
   *   The full message, already naming the path.
   */
  public function __construct(string $path, string $message) {
    parent::__construct($message);
    $this->path = $path;
  }

  /**
   * A required key is absent.
   *
   * @param string $path
   *   The dotted path of the missing key.
   *
   * @return self
   *   The error.
   */
  public static function missing(string $path): self {
    return new self($path, sprintf('%s is required but missing', $path));
  }

  /**
   * A key holds the wrong type.
   *
   * @param string $path
   *   The dotted path of the offending key.
   * @param string $expected
   *   The expected type, in prose: "int", "int|\"max\"|\"off\"".
   * @param mixed $actual
   *   The value that was found.
   *
   * @return self
   *   The error.
   */
  public static function wrongType(
    string $path,
    string $expected,
    mixed $actual,
  ): self {
    // Show the value, not just its type, whenever showing it is safe. "must
    // be true or false, got string" leaves the reader hunting; "got the
    // string \"yes\"" tells them their YAML 1.1 reflex is the problem.
    $found = get_debug_type($actual);
    if (is_scalar($actual)) {
      $shown = is_bool($actual)
        ? ($actual ? 'true' : 'false')
        : (string) $actual;
      if (mb_strlen($shown) <= self::MAX_SHOWN_VALUE) {
        $found = sprintf('the %s "%s"', $found, $shown);
      }
    }
    return new self($path, sprintf(
      '%s must be %s, got %s',
      $path,
      $expected,
      $found,
    ));
  }

  /**
   * A key holds a value of the right type but outside its allowed range.
   *
   * @param string $path
   *   The dotted path of the offending key.
   * @param string $constraint
   *   The constraint in prose, e.g. "between 0 and 100".
   * @param int|string $actual
   *   The value that was found.
   *
   * @return self
   *   The error.
   */
  public static function outOfRange(
    string $path,
    string $constraint,
    int|string $actual,
  ): self {
    return new self($path, sprintf(
      '%s must be %s, got %s',
      $path,
      $constraint,
      (string) $actual,
    ));
  }

}
