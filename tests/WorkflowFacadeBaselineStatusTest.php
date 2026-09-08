<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Baseline\BaselineManifest;
use Droost\Workflow\Baseline\BaselineStore;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\WorkflowFacade;

/**
 * The status document's baseline block says whether the baseline is honoured.
 *
 * The first live room read "present but OFF (strict mode)" with no lever in
 * the file: the block was assembled with `+`, which keeps the left operand's
 * keys, so a FALSE placeholder beat the real value every time. Pinned here
 * for the three states an operator can be in.
 */
final class WorkflowFacadeBaselineStatusTest extends WorkflowTestCase {

  /**
   * A present baseline with no lever is honoured; the counts ride along.
   */
  public function testPresentBaselineIsHonouredByDefault(): void {
    $root = $this->makeRootWithConfig("preset: high\n");
    $this->writeBaseline($root);

    $block = $this->block($root);
    $this->assertTrue($block['present']);
    $this->assertTrue($block['lever']);
    $this->assertTrue($block['honoured'], 'no lever means honoured — presence turns the baseline on');
    $this->assertSame(['phpstan' => 26], $block['gates']);
    $this->assertSame('bb631e4', $block['generated_commit']);
    $this->assertSame('high', $block['preset']);
    $this->assertSame(0, $block['grown']);
    $this->assertIsString($block['hash']);
  }

  /**
   * Strict mode (`baseline: { on: false }`): present, on record, not honoured.
   */
  public function testStrictModeLeverIsReportedAsNotHonoured(): void {
    $root = $this->makeRootWithConfig("preset: high\nbaseline: { on: false }\n");
    $this->writeBaseline($root);

    $block = $this->block($root);
    $this->assertTrue($block['present']);
    $this->assertFalse($block['lever']);
    $this->assertFalse($block['honoured']);
    $this->assertSame(['phpstan' => 26], $block['gates'], 'strict mode still shows what is being refused');
  }

  /**
   * No baseline: absent, nothing honoured, no counts to show.
   */
  public function testAbsentBaselineIsNeitherPresentNorHonoured(): void {
    $root = $this->makeRootWithConfig("preset: high\n");

    $block = $this->block($root);
    $this->assertFalse($block['present']);
    $this->assertFalse($block['honoured']);
    $this->assertTrue($block['lever']);
    $this->assertArrayNotHasKey('gates', $block);
  }

  /**
   * Writes a one-gate baseline the store accepts.
   *
   * @param string $root
   *   The project root.
   */
  private function writeBaseline(string $root): void {
    $manifest = new BaselineManifest('2026-09-08T11:03:14-05:00', 'bb631e4', 'high', [
      'phpstan' => ['file' => 'phpstan-baseline.neon', 'count' => 26, 'sha256' => 'x'],
    ]);
    BaselineStore::write($root, $manifest, [
      'phpstan-baseline.neon' => "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: '#Debt#'\n\t\t\tcount: 26\n\t\t\tpath: ../../web/a.php\n",
    ]);
  }

  /**
   * The status document's baseline block.
   *
   * @param string $root
   *   The project root.
   *
   * @return array<string, mixed>
   *   The block.
   */
  private function block(string $root): array {
    $status = $this->facade()->status($root);
    $this->assertIsArray($status['baseline']);
    $block = [];
    foreach ($status['baseline'] as $key => $value) {
      $this->assertIsString($key);
      $block[$key] = $value;
    }
    return $block;
  }

  /**
   * A facade whose gates all pass; no vcs.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(): WorkflowFacade {
    $executor = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed', [], $gate->name);
      }

    };
    return new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-08T00:00:00+00:00',
      static fn (): string => 'run-baseline',
    );
  }

}
