<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Evidence\TestsChoiceCheck;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\Vcs\VcsInterface;
use Droost\Workflow\WorkflowFacade;

/**
 * The run keeps to what plan chose about tests, and looks in the browser.
 *
 * With phpunit off a run still wrote a Kernel and a Unit test (2026-10-29):
 * a gate that is off sees nothing, so the diff is read instead.
 */
final class TestsChoiceCheckTest extends WorkflowTestCase {

  /**
   * A test written after "no unit tests" blocks; a test deleted does not.
   */
  public function testDeclinedUnitTestsHoldWrittenTests(): void {
    $root = $this->runAtCode();
    @mkdir($root . '/web/modules/custom/x/tests/src/Unit', 0755, TRUE);
    file_put_contents($root . '/web/modules/custom/x/tests/src/Unit/XTest.php', '<?php');

    $held = $this->check($root, ['web/modules/custom/x/tests/src/Unit/XTest.php'], 'code');
    $this->assertSame(CheckState::Blocked, $held[TestsChoiceCheck::UNIT]->state);
    $this->assertSame(Fault::Agent, $held[TestsChoiceCheck::UNIT]->fault);
    $this->assertStringContainsString('XTest.php', $held[TestsChoiceCheck::UNIT]->summary);

    $deleted = $this->check($root, ['web/modules/custom/x/tests/src/Unit/GoneTest.php'], 'test');
    $this->assertSame(CheckState::Satisfied, $deleted[TestsChoiceCheck::UNIT]->state, 'removing a test is not writing one');
  }

  /**
   * A spec written after "save nothing" blocks.
   */
  public function testDeclinedSpecHoldsWrittenSpecs(): void {
    $root = $this->runAtCode();
    @mkdir($root . '/tests/e2e', 0755, TRUE);
    file_put_contents($root . '/tests/e2e/x.spec.ts', 'test()');

    $held = $this->check($root, ['tests/e2e/x.spec.ts'], 'test');

    $this->assertSame(CheckState::Blocked, $held[TestsChoiceCheck::SPEC]->state);
    $this->assertArrayNotHasKey(TestsChoiceCheck::BROWSER, $held, 'the browser look is code\'s');
  }

  /**
   * Code holds until the browser was used; a blind diary is no one's fault.
   */
  public function testCodeHoldsUntilTheWorkWasCheckedInTheBrowser(): void {
    $root = $this->runAtCode();
    $runId = (string) (new RunStateStore($root))->load()?->runId;
    $store = new EvidenceStore($root);

    $blind = $this->check($root, [], 'code');
    $this->assertSame(CheckState::Blocked, $blind[TestsChoiceCheck::BROWSER]->state);
    $this->assertSame(Fault::Environment, $blind[TestsChoiceCheck::BROWSER]->fault, 'no MCP call at all: cannot see');

    $store->recordGuardCall($runId, 'code', 'pre-tool-use', 'allow', tool: 'mcp__Snyk__snyk_code_scan');
    $unlooked = $this->check($root, [], 'code');
    $this->assertSame(Fault::Agent, $unlooked[TestsChoiceCheck::BROWSER]->fault, 'it sees MCP calls, and none was a browser');

    $store->recordGuardCall($runId, 'code', 'pre-tool-use', 'allow', tool: 'mcp__playwright__browser_navigate');
    $looked = $this->check($root, [], 'code');
    $this->assertSame(CheckState::Satisfied, $looked[TestsChoiceCheck::BROWSER]->state);
  }

  /**
   * Without `tests.ask` it records nothing.
   */
  public function testSilentWithoutTheLever(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\n");
    $this->facade()->run($root);

    $this->assertSame([], $this->check($root, ['tests/e2e/x.spec.ts'], 'code'));
  }

  /**
   * A run past plan, autonomous, so it took the default: neither.
   *
   * @return string
   *   The project.
   */
  private function runAtCode(): string {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\ntests:\n  ask: true\n");
    $state = $this->facade()->run($root)->state;
    $this->assertSame('code', $state->currentPhase?->value);
    $this->assertFalse($state->tests['unit'] ?? NULL);
    return $root;
  }

  /**
   * The check's records over a given diff, by name.
   *
   * @param string $root
   *   The project.
   * @param list<string> $changed
   *   What the diff shows.
   * @param string $phase
   *   The phase.
   *
   * @return array<string, \Droost\Workflow\Evidence\CheckRecord>
   *   The records.
   */
  private function check(string $root, array $changed, string $phase): array {
    $vcs = new class($changed) implements VcsInterface {

      /**
       * @param list<string> $changed
       *   The diff.
       */
      public function __construct(private readonly array $changed) {}

      /**
       * {@inheritdoc}
       */
      public function head(string $projectRoot): ?string {
        return NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function isRepository(string $projectRoot): bool {
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function changedFiles(string $projectRoot, ?string $base): array {
        return $this->changed;
      }

    };
    $runId = (string) (new RunStateStore($root))->load()?->runId;
    $out = [];
    foreach ((new TestsChoiceCheck($vcs))->adjudicate($root, $phase, $runId) as $record) {
      $this->assertInstanceOf(CheckRecord::class, $record);
      $out[$record->name] = $record;
    }
    return $out;
  }

  /**
   * A facade whose gates all pass.
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
        return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };

    return new WorkflowFacade($executor, new RunStateOnlySink(), static fn (): string => '2026-10-30T00:00:00+00:00', static fn (): string => 'run-tests');
  }

}
