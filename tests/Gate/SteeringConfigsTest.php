<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Gate\SteeringConfigs;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Which of a run's changed files a gate's verdict is reached under (F-102).
 *
 * P6 run 7's agent wrote `.stylelintrc.json`, the coverage `<source>` in
 * `phpunit.xml` and `infection.json5` at xhigh, and each gate that read them
 * passed. The stylelint config was tuned to the CSS it judged. Only a file the
 * tool reads under the executor's own invocation counts, and a deleted one
 * counts as much as a written one.
 */
final class SteeringConfigsTest extends WorkflowTestCase {

  /**
   * What P6 run 7 changed, config and code together.
   */
  private const RUN_SEVEN = [
    '.prettierrc.json',
    '.stylelintrc.json',
    'infection.json5',
    'phpunit.xml',
    'web/modules/custom/example_camps/css/filters.css',
    'web/modules/custom/example_camps/js/filters.js',
    'web/modules/custom/example_camps/src/Filter/Rule.php',
  ];

  /**
   * Each gate is steered by its own configs and by no one else's.
   */
  public function testEachGateNamesOnlyTheConfigsItsToolReads(): void {
    $root = $this->makeRoot();

    $this->assertSame(['.stylelintrc.json'], SteeringConfigs::changed('stylelint', [], $root, self::RUN_SEVEN));
    $this->assertSame(['.prettierrc.json'], SteeringConfigs::changed('prettier', [], $root, self::RUN_SEVEN));
    $this->assertSame([], SteeringConfigs::changed('eslint', [], $root, self::RUN_SEVEN));
    $this->assertSame(['phpunit.xml'], SteeringConfigs::changed('phpunit', [], $root, self::RUN_SEVEN));
    $this->assertSame(['phpunit.xml'], SteeringConfigs::changed('coverage', [], $root, self::RUN_SEVEN));
    $this->assertSame(['infection.json5', 'phpunit.xml'], SteeringConfigs::changed('mutation', [], $root, self::RUN_SEVEN));
    $this->assertSame([], SteeringConfigs::changed('phpcs', [], $root, self::RUN_SEVEN));
    $this->assertSame([], SteeringConfigs::changed('phpstan', [], $root, self::RUN_SEVEN));
    foreach (['wiki_fresh', 'grounding_check', 'config_clean', 'module:snyk'] as $gate) {
      $this->assertSame([], SteeringConfigs::changed($gate, [], $root, self::RUN_SEVEN), $gate);
    }
  }

  /**
   * The front-end trio cascades, so a config in a module's own tree counts.
   */
  public function testTheTrioIsSteeredByConfigAnywhereInTheTree(): void {
    $root = $this->makeRoot();
    $changed = [
      'web/modules/custom/example_camps/.stylelintignore',
      'web/themes/custom/example_theme/eslint.config.mjs',
      'web/modules/custom/example_camps/.editorconfig',
      'web/modules/custom/example_camps/stylelint.css',
    ];

    $this->assertSame(['web/modules/custom/example_camps/.stylelintignore'], SteeringConfigs::changed('stylelint', [], $root, $changed));
    $this->assertSame(['web/themes/custom/example_theme/eslint.config.mjs'], SteeringConfigs::changed('eslint', [], $root, $changed));
    $this->assertSame(['web/modules/custom/example_camps/.editorconfig'], SteeringConfigs::changed('prettier', [], $root, $changed));
  }

  /**
   * A pinned config turns the cascade off, and only the pin steers.
   */
  public function testPinnedConfigIsTheOnlyOneThatSteers(): void {
    $root = $this->makeRoot();
    $levers = ['config' => 'web/core/.stylelintrc.json'];

    $this->assertSame([], SteeringConfigs::changed('stylelint', $levers, $root, ['.stylelintrc.json']));
    $this->assertSame(['web/core/.stylelintrc.json'], SteeringConfigs::changed('stylelint', $levers, $root, ['web/core/.stylelintrc.json']));
    $this->assertSame(['web/core/.stylelintrc.json'], SteeringConfigs::changed('stylelint', ['config' => './web/core/.stylelintrc.json'], $root, ['web/core/.stylelintrc.json']));
    $this->assertSame(['web/core/.stylelintrc.json'], SteeringConfigs::changed('stylelint', ['config' => $root . '/web/core/.stylelintrc.json'], $root, ['web/core/.stylelintrc.json']));
    $this->assertSame(['.stylelintignore'], SteeringConfigs::changed('stylelint', $levers, $root, ['.stylelintignore']), 'an ignore file still applies beside a pin');
  }

  /**
   * A `package.json` steers a tool only when it carries that tool's key.
   */
  public function testPackageJsonSteersTheToolWhoseKeyItCarries(): void {
    $root = $this->makeRoot();
    $package = ['name' => 'x', 'prettier' => ['singleQuote' => TRUE]];
    file_put_contents($root . '/package.json', (string) json_encode($package));

    $this->assertSame(['package.json'], SteeringConfigs::changed('prettier', [], $root, ['package.json']));
    $this->assertSame([], SteeringConfigs::changed('eslint', [], $root, ['package.json']));
    $this->assertSame([], SteeringConfigs::changed('stylelint', [], $root, ['package.json']));
  }

  /**
   * The phpcs gate reads a root ruleset, or the file its `standard` names.
   */
  public function testPhpcsIsSteeredByRootRulesetOrStandardLever(): void {
    $root = $this->makeRoot();

    $changed = ['phpcs.xml.dist', 'web/phpcs.xml'];
    $this->assertSame(['phpcs.xml.dist'], SteeringConfigs::changed('phpcs', [], $root, $changed));
    $named = ['standard' => 'Drupal,DrupalPractice'];
    $this->assertSame([], SteeringConfigs::changed('phpcs', $named, $root, ['web/phpcs.xml']));
    $file = ['standard' => 'tools/ruleset.xml'];
    $this->assertSame(['tools/ruleset.xml'], SteeringConfigs::changed('phpcs', $file, $root, ['tools/ruleset.xml']));
  }

  /**
   * The phpstan gate reads its root config and whatever that includes.
   */
  public function testPhpstanIsSteeredByItsConfigAndWhatItIncludes(): void {
    $root = $this->makeRoot();
    file_put_contents($root . '/phpstan.neon', "includes:\n  - phpstan-baseline.neon\n");

    $this->assertSame(
      ['phpstan.neon', 'phpstan-baseline.neon'],
      SteeringConfigs::changed('phpstan', [], $root, ['phpstan.neon', 'phpstan-baseline.neon', 'other.neon']),
    );
  }

  /**
   * A config the run deleted steers as much as one it wrote.
   */
  public function testDeletedConfigStillCounts(): void {
    $root = $this->makeRoot();

    $this->assertSame(['phpcs.xml.dist'], SteeringConfigs::changed('phpcs', [], $root, ['phpcs.xml.dist']), 'no file on disk');
    $this->assertSame(['infection.json5'], SteeringConfigs::changed('mutation', [], $root, ['infection.json5']));
  }

}
