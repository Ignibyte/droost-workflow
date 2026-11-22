<?php

declare(strict_types=1);

namespace Droost\Workflow\Pack;

/**
 * A harness's settings file, rewritten without changing what is not ours.
 *
 * The file is the user's. init and uninstall decode it, change droost's own
 * hook entries and write it back, and two things were lost on the way
 * (F-483, F-484): an empty object came back as an empty list, because PHP's
 * array is both, and a hook the user had put under the same matcher as
 * droost's went with droost's when the group was replaced or removed whole.
 */
final class SettingsJson {

  /**
   * Encodes settings, keeping the empty objects the file had.
   *
   * @param array<array-key, mixed> $settings
   *   The settings, decoded as arrays and edited.
   * @param string $raw
   *   The file as it was, or '' for a new one.
   *
   * @return string
   *   Pretty-printed JSON with a trailing newline.
   */
  public static function encode(array $settings, string $raw): string {
    $was = $raw === '' ? NULL : json_decode($raw, FALSE);

    return json_encode(
      self::withEmptyObjects($settings, $was),
      JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    ) . "\n";
  }

  /**
   * The index of the hook that runs droost's guard within one group.
   *
   * @param mixed $group
   *   One entry of an event's list: a matcher and its hooks.
   * @param string|null $mode
   *   The guard mode to find, or NULL for any.
   *
   * @return int|null
   *   The index within the group's `hooks`, or NULL when none is ours.
   */
  public static function guardHookIndex(mixed $group, ?string $mode = NULL): ?int {
    if (!is_array($group) || !is_array($group['hooks'] ?? NULL)) {
      return NULL;
    }
    foreach ($group['hooks'] as $i => $hook) {
      if (!is_int($i) || !is_array($hook) || !is_string($hook['command'] ?? NULL)) {
        continue;
      }
      $found = PackManifest::guardMode($hook['command']);
      if ($found !== NULL && ($mode === NULL || $found === $mode)) {
        return $i;
      }
    }

    return NULL;
  }

  /**
   * A value with its empty arrays made objects where the file had objects.
   *
   * @param mixed $value
   *   The value, decoded as arrays.
   * @param mixed $was
   *   The same place in the file decoded as objects, or NULL when new.
   *
   * @return mixed
   *   The value, ready to encode.
   */
  private static function withEmptyObjects(mixed $value, mixed $was): mixed {
    if (!is_array($value)) {
      return $value;
    }
    if ($value === []) {
      return $was instanceof \stdClass ? new \stdClass() : $value;
    }
    $out = [];
    foreach ($value as $key => $item) {
      $before = NULL;
      if ($was instanceof \stdClass) {
        $before = $was->{(string) $key} ?? NULL;
      }
      elseif (is_array($was)) {
        $before = $was[$key] ?? NULL;
      }
      $out[$key] = self::withEmptyObjects($item, $before);
    }

    return $out;
  }

}
