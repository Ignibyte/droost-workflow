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
 * A failure says which of its errors lie in files the run did not change.
 *
 * The other half of F-80. P6 run 6's first run at `high` failed phpstan on
 * one line of T1's code, which the run never touched, and the agent could not
 * tell: it asked the operator. The summary now named the level and the line,
 * and still not that the file was outside the diff. At `max` every custom
 * file meets phpstan at level max, so most of a first failure is inherited.
 */
final class FindingsOutsideTheDiffTest extends WorkflowTestCase {

  /**
   * The inherited errors are counted and their files named.
   */
  public function testInheritedErrorsAreNamed(): void {
    $phpstan = $this->phpstanAfter(
      [
        $this->error('web/modules/custom/example_rinks/src/Old.php', 12),
        $this->error('web/modules/custom/example_rinks/src/Old.php', 40),
        $this->error('web/modules/custom/example_camps/src/New.php', 7),
      ],
      ['web/modules/custom/example_camps/src/New.php'],
    );

    $this->assertSame(GateStatus::Failed, $phpstan->status, 'the verdict is unchanged');
    $this->assertStringContainsString(
      '[2 of 3 errors are in a file this run did not change: web/modules/custom/example_rinks/src/Old.php]',
      $phpstan->summary,
    );
  }

  /**
   * Errors all in the run's own files need no note, and neither do warnings.
   */
  public function testTheRunsOwnErrorsAndWarningsAreNotNamed(): void {
    $own = $this->phpstanAfter([$this->error('web/modules/custom/example_camps/src/New.php', 7)], ['web/modules/custom/example_camps/src/New.php']);
    $this->assertStringNotContainsString('did not change', $own->summary);

    $warned = $this->phpstanAfter(
      [
        $this->error('web/modules/custom/example_camps/src/New.php', 7),
        ['file' => 'web/modules/custom/example_rinks/src/Old.php', 'line' => 3, 'detail' => 'warning'],
      ],
      ['web/modules/custom/example_camps/src/New.php'],
    );
    $this->assertStringNotContainsString('did not change', $warned->summary, 'a warning never fails a gate');
  }

  /**
   * A diff nobody could read calls nothing inherited.
   */
  public function testUnreadableDiffNamesNothing(): void {
    $phpstan = $this->phpstanAfter([$this->error('web/modules/custom/example_rinks/src/Old.php', 12)], NULL);

    $this->assertStringNotContainsString('did not change', $phpstan->summary);
  }

  /**
   * More than three files: three named, the rest counted.
   */
  public function testManyFilesAreCounted(): void {
    $findings = [];
    foreach (['A', 'B', 'C', 'D', 'E'] as $name) {
      $findings[] = $this->error('web/modules/custom/example_rinks/src/' . $name . '.php', 1);
    }

    $phpstan = $this->phpstanAfter($findings, []);

    $this->assertStringContainsString('[5 of 5 errors are in files this run did not change: ', $phpstan->summary);
    $this->assertStringContainsString('src/C.php, and 2 more]', $phpstan->summary);
  }

  /**
   * Past the findings cap, the note counts what the record kept, and says so.
   *
   * P6 run 8's first phpstan failure at `max` read "69 errors, … [50 of 50
   * errors are in files this run did not change …]". The result keeps a
   * gate's first 50 findings, so the note counted 50 beside a total of 69
   * and called it the whole (F-105).
   */
  public function testCappedFindingsAreCountedAsKept(): void {
    $findings = [];
    for ($i = 1; $i <= 69; $i++) {
      $findings[] = $this->error(sprintf('web/modules/custom/example_rinks/src/F%02d.php', $i % 16), $i);
    }

    $phpstan = $this->phpstanAfter($findings, ['web/modules/custom/example_camps/src/New.php']);

    $this->assertTrue($phpstan->truncated, 'the result keeps the first 50 findings');
    $this->assertStringNotContainsString('[50 of 50 errors are', $phpstan->summary);
    $this->assertStringContainsString('[50 of the 50 errors kept in the record are in files this run did not change: ', $phpstan->summary);
    $this->assertStringContainsString(', and 13 more. The record keeps a gate\'s first 50 findings, so the rest are not counted here]', $phpstan->summary);
  }

  /**
   * One error finding.
   *
   * @param string $file
   *   The project-relative file.
   * @param int $line
   *   The line.
   *
   * @return array<string, mixed>
   *   The finding, in the executor's shape.
   */
  private function error(string $file, int $line): array {
    return ['file' => $file, 'line' => $line, 'rule' => 'phpstan', 'message' => 'x', 'detail' => 'error'];
  }

  /**
   * The phpstan gate's result at code, failing on the given findings.
   *
   * @param list<array<string, mixed>> $findings
   *   What phpstan found.
   * @param list<string>|null $changed
   *   The run's changed files, or NULL for a diff that cannot be read.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The phpstan gate's result.
   */
  private function phpstanAfter(array $findings, ?array $changed): GateResult {
    $root = $this->makeRootWithConfig("preset: max\n");
    $runner = new GateRunner($this->executor($findings), new NullSiteDriver(), $this->vcs($changed));
    $state = RunState::begin('run-1', '2026-09-24T00:00:00+00:00', WorkflowConfig::load($root), 'abc', NULL);

    foreach ($runner->run($state, Phase::Code, $root)->results as $result) {
      if ($result->gate === 'phpstan') {
        return $result;
      }
    }
    $this->fail('phpstan did not run at code');
  }

  /**
   * An executor that fails phpstan on the findings and passes the rest.
   *
   * @param list<array<string, mixed>> $findings
   *   The findings phpstan reports.
   *
   * @return \Droost\Workflow\Gate\GateExecutorInterface
   *   The executor.
   */
  private function executor(array $findings): GateExecutorInterface {
    return new class($findings) implements GateExecutorInterface {

      /**
       * Constructs the fake.
       *
       * @param list<array<string, mixed>> $findings
       *   The findings phpstan reports.
       */
      public function __construct(private readonly array $findings) {}

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        if ($gate->name !== 'phpstan') {
          return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed', [], $gate->name);
        }
        return GateResult::ran('phpstan', GateStatus::Failed, 1, 1, 'phpstan (level max) failed (exit 1): errors', $this->findings, 'phpstan');
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
