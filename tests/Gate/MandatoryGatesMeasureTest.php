<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Pack\PackMaterializer;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A mandatory gate is given something to measure, and something it can run.
 *
 * Both halves were measured by a reviewer running three real tickets through
 * real tools from `init` defaults, and between them they cost a whole ticket
 * and made a second one meaningless.
 *
 * PHPSTAN WAS GIVEN NO PATH AT ALL. `argvFor()` hands phpcs a `.` when the
 * repository has no ruleset; the branch directly beneath it passed phpstan
 * nothing, phpstan exited 1 with "At least one path must be specified", and
 * that became a labelled pass:
 *
 *   "gate":"phpstan","status":"passed","exit_code":1,
 *   "summary":"phpstan was given no path to analyse — a labeled pass"
 *   tally: {"passed": 1}
 *
 * `init` writes `phpstan: { level: 6 }` with no `paths`, so on every project
 * without a `phpstan.neon` — which is what init creates — the mandatory
 * analyser reported `passed` over zero files for the life of the project. The
 * reviewer planted a real return-type defect, watched phpstan find it in 0.8s
 * by hand, and watched the gate say passed. The prose was honest; the status
 * word and the tally were not, and the tally is what the evidence document
 * counts.
 *
 * AND INIT'S OWN DEFAULT COULD NOT PASS INIT'S OWN GATE. The shipped lever
 * file names `Drupal,DrupalPractice`; nothing in this package's dependency
 * tree provides it, and the README says the package "requires no Drupal". So
 * the documented happy path for a plain PHP project produced
 * `ERROR: the "Drupal" coding standard is not installed`, on a mandatory gate,
 * on a surface with no `gate-waive`, with `standard` frozen into run.json at
 * begin. Three attempts, `exhausted: true`, and `reset` the only exit.
 */
final class MandatoryGatesMeasureTest extends WorkflowTestCase {

  /**
   * Phpstan is handed the project's own source, and not the dependencies.
   */
  public function testPhpstanIsGivenSomethingToAnalyse(): void {
    $root = $this->projectWithTools();
    mkdir($root . '/src', 0755, TRUE);
    mkdir($root . '/tests', 0755, TRUE);
    mkdir($root . '/vendor/acme', 0755, TRUE);
    mkdir($root . '/.claude/hooks', 0755, TRUE);
    file_put_contents($root . '/src/Money.php', "<?php\n");
    file_put_contents($root . '/tests/MoneyTest.php', "<?php\n");
    file_put_contents($root . '/vendor/acme/Dep.php', "<?php\n");
    file_put_contents($root . '/.claude/hooks/droost-workflow-guard.php', "<?php\n");

    $argv = $this->argvFor($root, new GateSettings('phpstan', TRUE, ['level' => 6]));

    $this->assertContains('src', $argv, 'the project\'s own source is analysed');
    $this->assertContains('tests', $argv, 'and its tests');
    $this->assertNotContains('vendor', $argv, 'its dependencies are not its code');
    $this->assertNotContains(
      '.claude',
      $argv,
      'and neither is what droost installed — phpstan has no ignore flag, so '
      . 'the only way to keep those out is not to hand them over',
    );
  }

  /**
   * Anything that already names a subject is left alone.
   *
   * The default exists for the case where nothing else answers. A `paths`
   * lever or a `phpstan.neon` is somebody's decision and outranks it.
   */
  public function testSubjectsNamedElsewhereWin(): void {
    $root = $this->projectWithTools();
    mkdir($root . '/src', 0755, TRUE);
    file_put_contents($root . '/src/Money.php', "<?php\n");

    $lever = $this->argvFor($root, new GateSettings('phpstan', TRUE, ['paths' => 'src']));
    $this->assertCount(
      1,
      array_keys($lever, 'src', TRUE),
      'the lever names the subject once, not alongside a discovered copy',
    );

    file_put_contents($root . '/phpstan.neon', "parameters:\n  paths:\n    - src\n");
    $configured = $this->argvFor($root, new GateSettings('phpstan', TRUE, ['level' => 6]));
    $this->assertNotContains(
      'src',
      $configured,
      'a phpstan.neon names its own paths, and is not second-guessed',
    );
  }

  /**
   * Init writes a phpcs standard the project can actually run.
   */
  public function testInitWritesStandardsTheProjectHas(): void {
    $plain = $this->makeRoot();
    (new PackMaterializer())->init($plain);
    $this->assertSame(
      'PSR12',
      WorkflowConfig::load($plain)->gates['phpcs']->options['standard'] ?? NULL,
      'a plain PHP package gets the standard phpcs itself ships with',
    );

    $drupal = $this->makeRoot();
    mkdir($drupal . '/web/core/lib', 0755, TRUE);
    file_put_contents($drupal . '/web/core/lib/Drupal.php', "<?php\n");
    (new PackMaterializer())->init($drupal);
    $this->assertStringContainsString(
      'Drupal',
      (string) (WorkflowConfig::load($drupal)->gates['phpcs']->options['standard'] ?? ''),
      'and a Drupal site still gets Drupal\'s',
    );
  }

  /**
   * A project root with the mandatory binaries present.
   *
   * @return string
   *   The root.
   */
  private function projectWithTools(): string {
    $root = $this->makeRoot();
    mkdir($root . '/vendor/bin', 0755, TRUE);
    foreach (['phpcs', 'phpstan', 'phpunit'] as $tool) {
      file_put_contents($root . '/vendor/bin/' . $tool, "#!/bin/sh\nexit 0\n");
      chmod($root . '/vendor/bin/' . $tool, 0755);
    }

    return $root;
  }

  /**
   * The argv a gate is invoked with.
   *
   * @param string $root
   *   The project.
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate.
   *
   * @return list<string>
   *   The arguments, without the binary.
   */
  private function argvFor(string $root, GateSettings $gate): array {
    $seen = [];
    $executor = new ShellGateExecutor(
      function (array $argv) use (&$seen): array {
        $seen = $argv;
        return [0, '{}', ''];
      },
      static fn (): int => 0,
    );
    $executor->execute($gate, $root);

    return array_map(static fn (mixed $one): string => (string) $one, $seen);
  }

}
