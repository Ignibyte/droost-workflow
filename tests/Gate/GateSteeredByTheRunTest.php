<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateRunner;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\State\RunState;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\Vcs\VcsInterface;

/**
 * A verdict names the configs the run changed that its tool read (F-102).
 *
 * P6 run 7 at xhigh: the agent wrote the stylelint config its CSS was judged
 * by, tuned to that CSS (0 problems under it, 89 under core's), and the phase
 * report's line was "stylelint passed". The runner now carries the files on
 * the result and says them in the summary, the line the agent and the
 * operator read while the phase runs.
 */
final class GateSteeredByTheRunTest extends WorkflowTestCase {

  /**
   * The gates that read a changed config name it; the others do not.
   */
  public function testVerdictsNameTheConfigsTheRunChanged(): void {
    $results = $this->resultsAt(['.stylelintrc.json', 'phpunit.xml', 'web/modules/custom/example_camps/src/Rule.php']);

    $this->assertSame(['.stylelintrc.json'], $results['stylelint']->steeredBy);
    $this->assertStringContainsString('[steered by config this run changed: .stylelintrc.json]', $results['stylelint']->summary);
    $this->assertSame(GateStatus::Passed, $results['stylelint']->status, 'naming the config changes no verdict');
    $this->assertSame(['phpunit.xml'], $results['phpunit']->steeredBy);
    $this->assertSame(['phpunit.xml'], $results['mutation']->steeredBy);
    $this->assertSame([], $results['phpcs']->steeredBy);
    $this->assertStringNotContainsString('steered by', $results['phpcs']->summary);
  }

  /**
   * A gate that did not run read nothing, and a lost diff names nothing.
   */
  public function testNothingIsNamedWhenTheGateDidNotRunOrTheDiffWasUnread(): void {
    $results = $this->resultsAt(['.stylelintrc.json']);
    $this->assertSame(GateStatus::SkippedNoSite, $results['config_clean']->status);
    $this->assertSame([], $results['config_clean']->steeredBy);

    $unread = $this->resultsAt(NULL);
    $this->assertSame([], $unread['stylelint']->steeredBy);
    $this->assertStringNotContainsString('steered by', $unread['stylelint']->summary);
  }

  /**
   * Every gate's result at complete on an xhigh run, given what it changed.
   *
   * @param list<string>|null $changed
   *   The run's changed files, or NULL for a diff that cannot be read.
   *
   * @return array<string, \Droost\Workflow\Gate\GateResult>
   *   The results, by gate.
   */
  private function resultsAt(?array $changed): array {
    $root = $this->makeRootWithConfig("preset: xhigh\n");
    $runner = new GateRunner($this->passingExecutor(), new NullSiteDriver(), $this->vcs($changed));
    $state = RunState::begin('run-1', '2026-09-23T00:00:00+00:00', WorkflowConfig::load($root), 'abc', NULL);

    $results = [];
    foreach ($runner->run($state, Phase::Complete, $root)->results as $result) {
      $results[$result->gate] = $result;
    }
    foreach (['stylelint', 'phpunit', 'mutation', 'phpcs', 'config_clean'] as $due) {
      $this->assertArrayHasKey($due, $results, $due . ' is due at complete on xhigh');
    }
    return $results;
  }

  /**
   * An executor that passes every gate.
   *
   * @return \Droost\Workflow\Gate\GateExecutorInterface
   *   The executor.
   */
  private function passingExecutor(): GateExecutorInterface {
    return new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed', [], $gate->name);
      }

    };
  }

  /**
   * A fake vcs, or one with no repository to ask.
   *
   * @param list<string>|null $changed
   *   The changed files, or NULL for no repository.
   *
   * @return \Droost\Workflow\Vcs\VcsInterface
   *   The vcs.
   */
  private function vcs(?array $changed): VcsInterface {
    return new class($changed) implements VcsInterface {

      /**
       * Constructs the fake.
       *
       * @param list<string>|null $changed
       *   The changed files, or NULL for no repository.
       */
      public function __construct(private readonly ?array $changed) {}

      /**
       * {@inheritdoc}
       */
      public function head(string $projectRoot): string {
        return 'abc';
      }

      /**
       * {@inheritdoc}
       */
      public function isRepository(string $projectRoot): bool {
        return $this->changed !== NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function changedFiles(string $projectRoot, ?string $base): array {
        return $this->changed ?? [];
      }

    };
  }

}
