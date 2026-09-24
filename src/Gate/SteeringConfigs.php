<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

/**
 * The config files a gate's verdict is reached under, as the executor runs it.
 *
 * F-102. At `xhigh` the agent in P6 run 7 wrote `.stylelintrc.json`, the
 * coverage `<source>` in `phpunit.xml` and `infection.json5`, and every gate
 * that read them passed. The stylelint config was tuned to the CSS it judged:
 * 0 problems under it, 89 under core's config on the same seven files. The
 * record's line was "stylelint passed". A config is a project file a run may
 * change and declare, and changing one is often right, but a verdict reached
 * under rules the run set is not the verdict a reader assumes, so the record
 * names the file beside it.
 *
 * Only what the tool reads under the executor's own invocation counts. phpcs
 * reads a root ruleset because the executor then drops `--standard`, phpstan
 * and PHPUnit discover theirs at the root, and infection reads its own and
 * PHPUnit's. The front-end trio cascades from each file it checks up to the
 * root, so any of their configs anywhere in the tree counts, unless a
 * `config` lever pins one and turns the cascade off. A deleted config counts
 * as much as a written one: removing a ruleset changes the rules as surely as
 * adding one does.
 */
final class SteeringConfigs {

  /**
   * PHPUnit's own configs, which coverage and infection read as well.
   */
  private const PHPUNIT = ['phpunit.xml', 'phpunit.xml.dist'];

  /**
   * The names each front-end tool finds by cascading up from a file.
   *
   * Ignore files included: a line in `.stylelintignore` takes a file out of
   * the verdict as surely as a rule switched off. `.editorconfig` because
   * prettier reads it unless told not to.
   */
  private const CASCADE = [
    'eslint' => [
      '.eslintrc', '.eslintrc.json', '.eslintrc.js', '.eslintrc.cjs', '.eslintrc.yml',
      '.eslintrc.yaml', 'eslint.config.js', 'eslint.config.mjs', 'eslint.config.cjs',
      'eslint.config.ts', 'eslint.config.mts', 'eslint.config.cts', '.eslintignore',
    ],
    'stylelint' => [
      '.stylelintrc', '.stylelintrc.json', '.stylelintrc.yml', '.stylelintrc.yaml',
      '.stylelintrc.js', '.stylelintrc.cjs', '.stylelintrc.mjs', 'stylelint.config.js',
      'stylelint.config.cjs', 'stylelint.config.mjs', '.stylelintignore',
    ],
    'prettier' => [
      '.prettierrc', '.prettierrc.json', '.prettierrc.yml', '.prettierrc.yaml',
      '.prettierrc.json5', '.prettierrc.js', '.prettierrc.cjs', '.prettierrc.mjs',
      '.prettierrc.toml', 'prettier.config.js', 'prettier.config.cjs', 'prettier.config.mjs',
      '.prettierignore', '.editorconfig',
    ],
  ];

  /**
   * The files each front-end tool still reads beside a pinned config.
   */
  private const BESIDE_A_PIN = [
    'eslint' => ['.eslintignore'],
    'stylelint' => ['.stylelintignore'],
    'prettier' => ['.prettierignore', '.editorconfig'],
  ];

  /**
   * The `package.json` key each front-end tool takes a config from.
   */
  private const PACKAGE_KEYS = [
    'eslint' => 'eslintConfig',
    'stylelint' => 'stylelint',
    'prettier' => 'prettier',
  ];

  /**
   * The files the run changed that a gate's verdict is reached under.
   *
   * @param string $gate
   *   The gate.
   * @param array<string, mixed> $levers
   *   Its levers, for a pinned `config` or a `standard` that names a file.
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $changed
   *   Project-relative paths the run changed, deleted ones included.
   *
   * @return list<string>
   *   The changed files that steer the gate, in the order given.
   */
  public static function changed(string $gate, array $levers, string $projectRoot, array $changed): array {
    $root = rtrim($projectRoot, '/');
    $steering = [];
    foreach ($changed as $file) {
      $path = ltrim(str_replace('\\', '/', $file), '/');
      if ($path !== '' && self::steers($gate, $levers, $root, $path)) {
        $steering[$path] = TRUE;
      }
    }

    return array_keys($steering);
  }

  /**
   * Whether one changed file steers a gate.
   *
   * @param string $gate
   *   The gate.
   * @param array<string, mixed> $levers
   *   Its levers.
   * @param string $root
   *   The repository.
   * @param string $path
   *   The project-relative path.
   *
   * @return bool
   *   TRUE when the gate's tool reads it.
   */
  private static function steers(string $gate, array $levers, string $root, string $path): bool {
    return match ($gate) {
      'phpcs' => in_array($path, ShellGateExecutor::PHPCS_CONFIGS, TRUE)
        || self::names($levers['standard'] ?? NULL, $root, $path),
      'phpstan' => in_array($path, ShellGateExecutor::PHPSTAN_CONFIGS, TRUE)
        || self::includedByPhpstan($root, $path),
      'phpunit', 'coverage' => in_array($path, self::PHPUNIT, TRUE),
      'mutation' => in_array($path, ShellGateExecutor::INFECTION_CONFIGS, TRUE)
        || in_array($path, self::PHPUNIT, TRUE),
      'eslint', 'stylelint', 'prettier' => self::steersTheTrio($gate, $levers, $root, $path),
      default => FALSE,
    };
  }

  /**
   * Whether a changed file steers one of the front-end trio.
   *
   * @param string $gate
   *   One of eslint, stylelint and prettier.
   * @param array<string, mixed> $levers
   *   Its levers.
   * @param string $root
   *   The repository.
   * @param string $path
   *   The project-relative path.
   *
   * @return bool
   *   TRUE when the tool reads it.
   */
  private static function steersTheTrio(string $gate, array $levers, string $root, string $path): bool {
    $pinned = $levers['config'] ?? NULL;
    if (is_string($pinned) && trim($pinned) !== '') {
      return self::names($pinned, $root, $path)
        || in_array(basename($path), self::BESIDE_A_PIN[$gate] ?? [], TRUE);
    }

    return in_array(basename($path), self::CASCADE[$gate] ?? [], TRUE)
      || (basename($path) === 'package.json' && self::packageConfigures($root, $path, self::PACKAGE_KEYS[$gate] ?? ''));
  }

  /**
   * Whether a lever's value names this path.
   *
   * @param mixed $value
   *   The lever: a path, or a name that is not one ("Drupal,DrupalPractice").
   * @param string $root
   *   The repository.
   * @param string $path
   *   The project-relative path.
   *
   * @return bool
   *   TRUE when the lever points at the file.
   */
  private static function names(mixed $value, string $root, string $path): bool {
    if (!is_string($value) || trim($value) === '') {
      return FALSE;
    }
    $named = str_replace('\\', '/', trim($value));
    if (str_starts_with($named, $root . '/')) {
      $named = substr($named, strlen($root) + 1);
    }

    return (string) preg_replace('#^(\./)+#', '', $named) === $path;
  }

  /**
   * Whether a changed `.neon` file is one a root phpstan config includes.
   *
   * A baseline or a shared ruleset reaches the verdict only through a root
   * config's `includes`, so the name has to appear in one of them.
   *
   * @param string $root
   *   The repository.
   * @param string $path
   *   The project-relative path.
   *
   * @return bool
   *   TRUE when a root config names it.
   */
  private static function includedByPhpstan(string $root, string $path): bool {
    if (!str_ends_with($path, '.neon') && !str_ends_with($path, '.neon.dist')) {
      return FALSE;
    }
    foreach (ShellGateExecutor::PHPSTAN_CONFIGS as $config) {
      $text = @file_get_contents($root . '/' . $config);
      if (is_string($text) && str_contains($text, basename($path))) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Whether a changed `package.json` carries a front-end tool's config.
   *
   * @param string $root
   *   The repository.
   * @param string $path
   *   The project-relative path of the `package.json`.
   * @param string $key
   *   The tool's key.
   *
   * @return bool
   *   TRUE when the file holds that key.
   */
  private static function packageConfigures(string $root, string $path, string $key): bool {
    $raw = @file_get_contents($root . '/' . $path);
    $decoded = is_string($raw) ? json_decode($raw, TRUE) : NULL;

    return $key !== '' && is_array($decoded) && array_key_exists($key, $decoded);
  }

}
