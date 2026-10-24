<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Knowledge;

/**
 * Reads a decoded answer by dotted path, asserting each type on the way.
 */
trait TypedReads {

  /**
   * One value by its dotted path.
   *
   * @param array<array-key, mixed> $from
   *   The decoded structure.
   * @param string $path
   *   E.g. `data.semantic.0.file`.
   *
   * @return mixed
   *   The value.
   */
  private function dig(array $from, string $path): mixed {
    $value = $from;
    foreach (explode('.', $path) as $key) {
      $this->assertIsArray($value, sprintf('No "%s" in: %s', $path, json_encode($from)));
      $this->assertArrayHasKey($key, $value, sprintf('No "%s" in: %s', $path, json_encode($from)));
      $value = $value[$key];
    }
    return $value;
  }

  /**
   * A string by its dotted path.
   *
   * @param array<array-key, mixed> $from
   *   The decoded structure.
   * @param string $path
   *   Its path.
   *
   * @return string
   *   The string.
   */
  private function digString(array $from, string $path): string {
    $value = $this->dig($from, $path);
    $this->assertIsString($value);
    return $value;
  }

  /**
   * An array by its dotted path.
   *
   * @param array<array-key, mixed> $from
   *   The decoded structure.
   * @param string $path
   *   Its path.
   *
   * @return array<array-key, mixed>
   *   The array.
   */
  private function digArray(array $from, string $path): array {
    $value = $this->dig($from, $path);
    $this->assertIsArray($value);
    return $value;
  }

}
