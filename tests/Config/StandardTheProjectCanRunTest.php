<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A level's phpcs standard is one the project can run, and the file's is law.
 *
 * The preset bases ask for `Drupal,DrupalPractice`, and only `init` knew to
 * write PSR-12 instead on a project that is not Drupal — as a rewrite of the
 * file, once. So the lever file's own advice, added the same day — "to let a
 * level decide a lever, DELETE that lever's line" — handed a plain PHP
 * package the level's Drupal standard, and phpcs exited 16 with `the "Drupal"
 * coding standard is not installed` on a mandatory gate with no waiver on the
 * standalone surface. The instruction pointed straight at the failure.
 *
 * Only the LEVEL's word is substituted. A standard the operator spells out in
 * the file is theirs, and stands; phpcs will say what is missing.
 */
final class StandardTheProjectCanRunTest extends WorkflowTestCase {

  /**
   * The level asked for Drupal; the project is not Drupal; the reader is told.
   */
  public function testTheLevelsStandardIsSubstitutedWhereDrupalIsNot(): void {
    $root = $this->makeRootWithConfig("preset: high\n");

    $config = WorkflowConfig::load($root);

    $this->assertSame('PSR12', $config->gates['phpcs']->options['standard'] ?? NULL);
    $notice = implode(' ', $config->deprecations);
    $this->assertStringContainsString('"Drupal,DrupalPractice"', $notice, 'what the level asked for');
    $this->assertStringContainsString('"PSR12"', $notice, 'what the project is held to');
    $this->assertStringContainsString('drupal/coder', $notice, 'and how to make the level\'s word run here');
  }

  /**
   * A standard the file spells out is the operator's, and stands.
   */
  public function testTheFilesOwnStandardStands(): void {
    $root = $this->makeRootWithConfig(
      "preset: high\ngates:\n  phpcs: { standard: \"Drupal,DrupalPractice\" }\n",
    );

    $config = WorkflowConfig::load($root);

    $this->assertSame('Drupal,DrupalPractice', $config->gates['phpcs']->options['standard'] ?? NULL);
    $this->assertStringNotContainsString(
      'held to',
      implode(' ', $config->deprecations),
      'nothing was substituted, so nothing is announced',
    );
  }

  /**
   * Where the project IS Drupal, the level's standard stands unannounced.
   */
  public function testTheLevelsStandardStandsWhereDrupalIs(): void {
    $root = $this->makeRootWithConfig("preset: high\n");
    mkdir($root . '/web/core/lib', 0755, TRUE);
    file_put_contents($root . '/web/core/lib/Drupal.php', "<?php\n");

    $config = WorkflowConfig::load($root);

    $this->assertSame('Drupal,DrupalPractice', $config->gates['phpcs']->options['standard'] ?? NULL);
    $this->assertStringNotContainsString('held to', implode(' ', $config->deprecations));
  }

  /**
   * With no project to look at, the level's word is left as it is.
   */
  public function testFromArrayWithNoProjectLeavesTheStandardAlone(): void {
    $config = WorkflowConfig::fromArray(['preset' => 'high'], 'test');

    $this->assertSame('Drupal,DrupalPractice', $config->gates['phpcs']->options['standard'] ?? NULL);
    $this->assertSame([], $config->deprecations);
  }

}
