<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

/**
 * Moves the effort dial: rewrites the `preset:` line of a lever file.
 *
 * One line is the whole switch — that is the point of the dial — but a bare
 * edit hides two things this class makes visible: the alias a person typed
 * versus the canonical name that is written, and how many explicit switches
 * the file's `gates:` block still carries, each of which overrides the level
 * (the standing gotcha: a file that spells out every gate changes only what
 * it leaves unsaid when the dial moves). Framework-free so the drush surface
 * can wrap it with the operator-terminal check and its printing, and so it is
 * tested here in seconds.
 */
final class EffortSwitch {

  /**
   * Rewrites the preset line, then re-reads the file to prove it still loads.
   *
   * The rewrite is rolled back if the result does not load — a lever file
   * that parsed before this command must parse after it.
   *
   * @param string $root
   *   The project root holding droost.workflow.yml.
   * @param string $level
   *   The level to write: a known preset, or one of its aliases.
   *
   * @return \Droost\Workflow\Config\EffortChange
   *   What was written, what it replaced, and what still overrides it.
   *
   * @throws \InvalidArgumentException
   *   When the level is not a preset this package knows, or there is no lever
   *   file to rewrite.
   * @throws \Droost\Workflow\Config\ConfigError
   *   When the file did not load before the rewrite (fix it first) or does not
   *   load after it (rolled back).
   */
  public static function apply(string $root, string $level): EffortChange {
    if (!PresetResolver::isKnown($level)) {
      throw new \InvalidArgumentException(sprintf(
        'Unknown preset "%s" (known: %s; factory and light load as aliases of max and medium).',
        $level,
        implode(', ', PresetResolver::KNOWN_PRESETS),
      ));
    }
    $path = rtrim($root, '/') . '/' . WorkflowConfig::FILENAME;
    if (!is_file($path)) {
      throw new \InvalidArgumentException(sprintf(
        'No %s at %s — nothing to switch. `drush droost:workflow:install` (or `droost-workflow init`) writes one.',
        WorkflowConfig::FILENAME,
        $root,
      ));
    }
    $previous = WorkflowConfig::load($root)->preset;
    $canonical = PresetResolver::canonical($level);
    $before = (string) file_get_contents($path);
    $after = self::rewrite($before, $canonical);
    file_put_contents($path, $after);
    try {
      $config = WorkflowConfig::load($root);
    }
    catch (\Throwable $e) {
      file_put_contents($path, $before);
      throw $e;
    }
    return new EffortChange(
      $previous,
      $canonical,
      $level === $canonical ? NULL : $level,
      self::overrides($after),
      $config,
    );
  }

  /**
   * The lever text with its preset line replaced, or added.
   *
   * A file with a `preset:` line has that one line rewritten (a same-line
   * comment goes with it — the line IS the switch). A file without one gets
   * the line after `mode:` where a reader expects it, or first when there is
   * no `mode:` either.
   *
   * @param string $yaml
   *   The current lever text.
   * @param string $canonical
   *   The canonical level to write.
   *
   * @return string
   *   The rewritten text.
   */
  public static function rewrite(string $yaml, string $canonical): string {
    $line = 'preset: ' . $canonical;
    if (preg_match('/^preset:[^\n]*$/m', $yaml) === 1) {
      return (string) preg_replace('/^preset:[^\n]*$/m', $line, $yaml, 1);
    }
    if (preg_match('/^mode:[^\n]*$/m', $yaml) === 1) {
      return (string) preg_replace('/^(mode:[^\n]*)$/m', '$1' . "\n" . $line, $yaml, 1);
    }
    return $line . "\n" . $yaml;
  }

  /**
   * The gates whose `on:` switch the file spells out explicitly.
   *
   * Each one overrides whatever the level says for that gate. Listed by name
   * rather than counted so the reader can judge: a repo's own custom gate
   * belongs in the file; `mutation: { on: false }` beside `preset: max` is a
   * loosening.
   *
   * @param string $yaml
   *   The lever text.
   *
   * @return list<string>
   *   Gate names carrying an explicit `on:`, in file order.
   */
  public static function overrides(string $yaml): array {
    preg_match_all('/^\s+([\w-]+):\s*\{[^}\n]*\bon:\s*(?:true|false)\b/m', $yaml, $matches);
    return $matches[1];
  }

}
