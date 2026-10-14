<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\State\StateError;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * The gate verb: a phase's gates outside any run (0.14.17).
 *
 * Asked for by druplit's landing, which merges an agent's worktree into the
 * main site and has no run to put the gates in. The gates are the lever
 * file's, frozen as a run would freeze them; nothing is kept.
 */
class StandaloneGatesTest extends WorkflowTestCase {

  /**
   * The test phase's gates run, and no run, evidence or retry is left.
   */
  public function testTheTestPhaseGatesRunAndNothingIsKept(): void {
    $root = $this->project("preset: low\n");
    $gates = $this->gates([]);

    $result = $this->facade($gates)->gates($root);

    $this->assertSame('test', $result['phase']);
    $this->assertSame('low', $result['preset']);
    $this->assertTrue($result['passed']);
    $outcomes = array_column($result['gates'], 'outcome', 'gate');
    $this->assertSame('pass', $outcomes['playwright'] ?? NULL);
    $this->assertSame('skipped', $outcomes['rendered_check'] ?? NULL, 'a site gate with no site is skipped, not failed');
    $this->assertSame(1, $gates->executions['playwright'] ?? 0);
    $this->assertNull((new RunStateStore($root))->load(), 'no run was opened');
    $this->assertFileDoesNotExist($root . '/droost/droost-workflow/evidence.sqlite');
  }

  /**
   * A gate that blocks fails the whole check.
   */
  public function testBlockingGateFailsTheCheck(): void {
    $root = $this->project("preset: low\n");
    $failing = GateResult::ran('playwright', GateStatus::Failed, 1, 1, 'playwright failed (exit 1): 1 failed', [], 'playwright test');
    $result = $this->facade($this->gates(['playwright' => [$failing]]))->gates($root);

    $this->assertFalse($result['passed']);
    $playwright = array_values(array_filter($result['gates'], static fn (array $g): bool => $g['gate'] === 'playwright'))[0];
    $this->assertSame('fail', $playwright['outcome']);
    $this->assertSame('failed', $playwright['status']);
    $this->assertSame(1, $playwright['exit_code']);
  }

  /**
   * An open run at code is left exactly as it was.
   */
  public function testRunAtCodeIsUntouched(): void {
    $root = $this->project("preset: low\n");
    $gates = $this->gates([]);
    $this->facade($gates)->run($root);
    $store = new RunStateStore($root);
    $before = file_get_contents($store->path());
    $this->assertSame(Phase::Code, $store->load()?->currentPhase);

    $this->facade($gates)->gates($root);

    $this->assertSame($before, file_get_contents($store->path()), 'the run\'s state did not move');
  }

  /**
   * It is refused while this project's run is at test.
   */
  public function testRefusedWhileTheRunIsAtTest(): void {
    $root = $this->project("preset: low\n");
    $gates = $this->gates([]);
    $facade = $this->facade($gates);
    $facade->run($root);
    $facade->declareChanges($root, ['src', 'tests'], [], 'code');
    file_put_contents($root . '/tests/e2e/mine.spec.ts', "test('the ticket', () => {});\n");
    for ($i = 0; $i < 6 && (new RunStateStore($root))->load()?->currentPhase !== Phase::Test; $i++) {
      $this->facade($gates)->run($root);
    }
    $this->assertSame(Phase::Test, (new RunStateStore($root))->load()?->currentPhase);

    $this->expectException(StateError::class);
    $this->expectExceptionMessage('is at its test phase');
    $this->facade($gates)->gates($root);
  }

  /**
   * With a base, a ticket-scoped gate reads only what changed since it.
   */
  public function testBaseNarrowsTheGatesToWhatChangedSinceIt(): void {
    $root = $this->project("preset: low\n");
    $before = trim((string) shell_exec(sprintf('git -C %s rev-parse HEAD', escapeshellarg($root))));
    file_put_contents($root . '/tests/e2e/merged.spec.ts', "test('merged', () => {});\n");
    exec(sprintf(
      'cd %s && git add -A && git -c user.name=droost-test -c user.email=test@example.invalid commit -q -m merge 2>&1',
      escapeshellarg($root),
    ), $output, $exit);
    $this->assertSame(0, $exit, implode("\n", $output));
    $gates = $this->gates([]);

    $result = $this->facade($gates)->gates($root, Phase::Test, $before);

    $this->assertSame($before, $result['base']);
    $this->assertSame('tests/e2e/merged.spec.ts', $gates->ticketFiles['playwright'] ?? NULL, 'only the merge\'s spec');

    $this->facade($gates)->gates($root);
    $this->assertNull($gates->ticketFiles['playwright'] ?? NULL, 'with no base, no list: the full set');
  }

  /**
   * A base that names no commit is refused, and an option-shaped one too.
   */
  public function testBaseThatNamesNoCommitIsRefused(): void {
    $root = $this->project("preset: low\n");
    foreach (['no-such-ref', '--output=/tmp/x'] as $base) {
      try {
        $this->facade($this->gates([]))->gates($root, Phase::Test, $base);
        $this->fail('refused: ' . $base);
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString('names no commit in this repository', $e->getMessage());
      }
    }
  }

  /**
   * The CLI: --json, the exit codes, and a phase it refuses.
   */
  public function testTheVerb(): void {
    $off = "  phpcs: { on: false }\n  phpstan: { on: false }\n  phpunit: { on: false }\n"
      . "  playwright: { on: false }\n  parity: { on: false }\n  rendered_check: { on: false }\n"
      . "  config_clean: { on: false }\n";
    $passing = $this->project("preset: custom\ngates:\n" . $off
      . "  custom:\n    ok: { on: true, phase: test, cmd: 'true' }\n");
    [$code, $out] = $this->cli(['gate', '--json'], $passing);
    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, $out);
    $document = json_decode($out, TRUE);
    $this->assertIsArray($document);
    $this->assertTrue($document['passed']);
    $this->assertIsArray($document['gates']);
    $outcomes = array_column($document['gates'], 'outcome', 'gate');
    $this->assertSame('pass', $outcomes['custom:ok'] ?? NULL);

    $failing = $this->project("preset: custom\ngates:\n" . $off
      . "  custom:\n    no: { on: true, phase: test, cmd: 'false' }\n");
    [$code, $out] = $this->cli(['gate'], $failing);
    $this->assertSame(ArgvDispatcher::EXIT_RUN_FAILED, $code, $out);
    $this->assertStringContainsString('FAILED: test gates at preset custom, outside any run', $out);

    [$code, $out] = $this->cli(['gate', '--json', '--base=HEAD'], $passing);
    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, $out);
    $document = json_decode($out, TRUE);
    $this->assertIsArray($document);
    $this->assertIsString($document['base']);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $document['base']);

    [$code, $out] = $this->cli(['gate', '--base=nowhere'], $passing);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);
    $this->assertStringContainsString('names no commit', $out);

    [$code, $out] = $this->cli(['gate', '--phase=plan'], $passing);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);
    $this->assertStringContainsString('unknown phase "plan"', $out);
  }

  /**
   * Runs the CLI.
   *
   * @param list<string> $argv
   *   The arguments.
   * @param string $root
   *   The project.
   *
   * @return array{0: int, 1: string}
   *   The exit code and everything printed.
   */
  private function cli(array $argv, string $root): array {
    $lines = [];
    $sink = function (string $line) use (&$lines): void {
      $lines[] = $line;
    };
    $dispatcher = new ArgvDispatcher(
      $sink,
      $sink,
      static fn (): string => '2026-10-14T00:00:00+00:00',
      static fn (): string => 'run-gate',
    );

    return [$dispatcher->dispatch($argv, $root), implode("\n", $lines)];
  }

  /**
   * A project in a repository, with a source tree and a spec.
   *
   * @param string $yaml
   *   The lever file.
   *
   * @return string
   *   The root.
   */
  private function project(string $yaml): string {
    $root = $this->makeRootWithConfig($yaml . "mode: agentic\n");
    mkdir($root . '/src', 0755, TRUE);
    mkdir($root . '/tests/e2e', 0755, TRUE);
    file_put_contents($root . '/src/Thing.php', "<?php\n");
    file_put_contents($root . '/tests/e2e/mine.spec.ts', "\n");
    exec(sprintf(
      'cd %s && git init -q . && git add -A && git -c user.name=droost-test -c user.email=test@example.invalid commit -q -m base 2>&1',
      escapeshellarg($root),
    ), $output, $exit);
    $this->assertSame(0, $exit, implode("\n", $output));

    return $root;
  }

  /**
   * An executor that passes everything but the queued results.
   *
   * @param array<string, list<\Droost\Workflow\Gate\GateResult>> $queued
   *   Results each gate returns, in order, before it starts passing.
   *
   * @return object{executions: array<string, int>, ticketFiles: array<string, string|null>}&\Droost\Workflow\Gate\GateExecutorInterface
   *   The double.
   */
  private function gates(array $queued): object {
    return new class($queued) implements GateExecutorInterface {

      /**
       * Executions per gate.
       *
       * @var array<string, int>
       */
      public array $executions = [];

      /**
       * The ticket files each gate was handed, NULL when none.
       *
       * @var array<string, string|null>
       */
      public array $ticketFiles = [];

      /**
       * Constructs the double.
       *
       * @param array<string, list<\Droost\Workflow\Gate\GateResult>> $queued
       *   The queued results.
       */
      public function __construct(private array $queued) {}

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        $this->executions[$gate->name] = ($this->executions[$gate->name] ?? 0) + 1;
        $files = $gate->option('ticket_files');
        $this->ticketFiles[$gate->name] = is_string($files) ? $files : NULL;
        if (($this->queued[$gate->name] ?? []) !== []) {
          return array_shift($this->queued[$gate->name]);
        }

        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed — 2 passed', [], $gate->name);
      }

    };
  }

  /**
   * A fresh facade, as a new process would build it.
   *
   * @param \Droost\Workflow\Gate\GateExecutorInterface $gates
   *   The shared executor.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(GateExecutorInterface $gates): WorkflowFacade {
    return new WorkflowFacade(
      $gates,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-10-14T12:00:00+00:00',
      static fn (): string => 'run-gate',
    );
  }

}
