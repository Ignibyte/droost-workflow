<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use PHPUnit\Framework\Assert;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Gate\SiteDriverInterface;
use Droost\Workflow\Mode\PendingQuestion;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\State\StateError;
use Droost\Workflow\WorkflowFacade;

/**
 * REQ-003: the two surfaces produce the same report.
 *
 * The requirement this whole ticket exists for — "run it via a live site
 * and/or in a cli" is only true if the two agree. They agree by construction:
 * both call one facade and differ only in which SiteDriver they inject, so
 * these tests assert that the construction actually holds rather than that
 * two implementations happen to match today.
 */
class SurfaceParityTest extends WorkflowTestCase {

  /**
   * Both surfaces report the same levers for the same config.
   */
  public function testStatusIsIdenticalAcrossSurfaces(): void {
    $root = $this->makeRootWithConfig("preset: light\n");

    $cli = $this->facade(new NullSiteDriver())->status($root);
    $live = $this->facade($this->fakeSiteDriver())->status($root);

    $this->assertSame($cli, $live);
    // Narrowed one level at a time: asserting the outer array does not narrow
    // what is inside it, and a chained offset into the result is an
    // offset-on-mixed error at level max.
    $levers = $cli['levers'];
    $this->assertIsArray($levers);
    // `light` is an alias of `medium`; the record carries the canonical name.
    $this->assertSame('medium', $levers['preset']);
    // Status explains WHEN each gate runs, so "why did plan run nothing"
    // is answerable without reading the engine.
    $phaseGates = $levers['phase_gates'];
    $this->assertIsArray($phaseGates);
    $this->assertSame([], $phaseGates['plan']);
    $this->assertSame(['phpcs', 'phpstan', 'eslint', 'stylelint', 'prettier', 'config_clean', 'grounding_check'], $phaseGates['code']);
  }

  /**
   * The two surfaces differ in exactly one thing: the site gate.
   *
   * Everything else — which gates ran, their verdicts, the phase, the
   * advance decision — must be identical. If any other field diverges, the
   * facade has stopped being the single orchestration point.
   *
   * Two invocations per surface: the first works plan, which the phase map
   * leaves gateless (asserted, since that is itself new behavior); the
   * second works test, where the site gate is due and the surfaces may
   * lawfully differ.
   */
  public function testOnlyTheSiteGateDiffersBetweenSurfaces(): void {
    $config = "preset: custom\n";
    $cliRoot = $this->makeRootWithConfig($config);
    $liveRoot = $this->makeRootWithConfig($config);
    $cliFacade = $this->facade(new NullSiteDriver());
    $liveFacade = $this->facade($this->fakeSiteDriver());

    $cliPlan = $cliFacade->run($cliRoot);
    $livePlan = $liveFacade->run($liveRoot);
    $this->assertNotNull($cliPlan->report);
    $this->assertNotNull($livePlan->report);
    $this->assertSame([], $cliPlan->report->results, 'plan ran a gate');
    $this->assertSame([], $livePlan->report->results, 'plan ran a gate');

    // THE CODE PHASE'S OWN REPORT. This took a third step to reach it,
    // because the seeker used to hold `code` and the extra call was what
    // adjudicated it. The seeker no longer holds (F-35), so the second call
    // IS the code report — and the third would be `test`, which runs no phpcs
    // and made this assert a mandatory gate that was never in the phase.
    $cli = $cliFacade->run($cliRoot);
    $live = $liveFacade->run($liveRoot);

    $this->assertNotNull($cli->report);
    $this->assertNotNull($live->report);

    $cliGates = $this->byGate($cli->report->toArray());
    $liveGates = $this->byGate($live->report->toArray());

    $this->assertSame(array_keys($cliGates), array_keys($liveGates));

    // The loop below is the whole test, and `[] === []` passes — so with
    // `byGate()` returning nothing this stayed green about nothing, at a cost
    // of 37 assertions and zero failures. "The surfaces agree" is a sentence
    // two empty arrays satisfy. The neighbouring meta-tests get this right
    // (PackPortabilityTest asserts >20 files, UsageCompletenessTest >8 verbs);
    // this one did not.
    $this->assertNotSame([], $cliGates, 'the CLI surface ran some gates at all');
    $this->assertArrayHasKey('phpcs', $cliGates, 'including a mandatory one');
    $this->assertNotSame(
      [],
      array_intersect(
        array_keys($cliGates),
        ['rendered_check', 'config_clean', 'grounding_check'],
      ),
      'and at least one site gate, which is what the two surfaces differ on — '
      . 'without one in the set, the branch this test exists for is never taken',
    );

    foreach ($cliGates as $name => $cliResult) {
      if (in_array($name, ['rendered_check', 'config_clean', 'grounding_check'], TRUE)) {
        $this->assertSame('skipped-no-site', $cliResult['status']);
        $this->assertSame('passed', $liveGates[$name]['status']);
        continue;
      }
      $this->assertSame(
        $cliResult['status'],
        $liveGates[$name]['status'],
        $name . ' differs between surfaces',
      );
    }
  }

  /**
   * The CLI surface names every gate it could not run, and passes none.
   */
  public function testTheCliSurfaceReportsItsSkipsRatherThanOmittingThem(): void {
    $root = $this->makeRootWithConfig(
      "preset: custom\nseekers: { on: false }\n",
    );
    $facade = $this->facade(new NullSiteDriver());

    // Advance past the gateless plan phase and through code to test, where
    // the site gate is due (0.3: phases are canonical, never a subset).
    $facade->run($root);
    $facade->run($root);
    $outcome = $facade->run($root);

    $this->assertNotNull($outcome->report);
    $skipped = $outcome->report->skipped();
    $this->assertCount(2, $skipped);
    $this->assertSame(
      ['rendered_check', 'config_clean'],
      array_map(static fn ($r) => $r->gate, $skipped),
    );
    $this->assertNotNull($skipped[0]->skipReason);
    $this->assertNotNull($skipped[1]->skipReason);
  }

  /**
   * The facade writes run state — the thing nothing did until now.
   */
  public function testRunningWritesRunState(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");

    $this->assertFileDoesNotExist($root . '/droost/droost-workflow/run.json');
    $this->facade(new NullSiteDriver())->run($root);
    $this->assertFileExists($root . '/droost/droost-workflow/run.json');

    $status = $this->facade(new NullSiteDriver())->status($root);
    $this->assertIsArray($status['run']);
    $this->assertSame('run-test', $status['run']['run_id']);
  }

  /**
   * A run walks the phases and finishes, rather than repeating one.
   */
  public function testRunAdvancesThroughItsPhasesToCompletion(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $facade = $this->facade(new NullSiteDriver());

    // 0.4: every run walks the full canonical sequence — three working phases
    // and the terminal one, which carries the documentation work.
    //
    // The seeker used to hold this walk TWICE, at code and again at complete,
    // each until a clean inspection was recorded. It holds once now, at code,
    // and for a different reason (F-35): not "is the verdict clean" but "has
    // the inspection RUN" — a step with a yes/no answer. What it found is
    // advice below `high`, where only an open CRITICAL count holds.
    $first = $facade->run($root);
    $this->assertSame('advanced:code', $first->outcome->value . ':' . $first->state->currentPhase?->value);

    $due = $facade->run($root);
    $this->assertSame(
      'inspection-due:code',
      $due->outcome->value . ':' . $due->state->currentPhase?->value,
      'code passes its gates and waits for the inspection step',
    );

    $record = $facade->recordSeeker(
      $root,
      "## Seeker Inspection\n\nInspector: independent\n\n(no findings)\n",
    );
    $this->assertSame('clean', $record['status']);

    $walk = [];
    for ($step = 0; $step < 5; $step++) {
      $outcome = $facade->run($root);
      $phase = $outcome->state->currentPhase;
      // The terminal step completes: the final phase passed and the run
      // reached its terminal gate, so it has NO current phase — that NULL is
      // exactly how status, report and reset tell a finished run from a live
      // one.
      $walk[] = $outcome->outcome->value . ':' . ($phase === NULL ? '(none)' : $phase->value);
      if ($phase === NULL) {
        break;
      }
    }

    // And complete no longer asks for an inspection of its own. The seeker's
    // fix loop belongs in code — write, gate, read the diff, fix, gate again —
    // and complete carries the wiki and knowledge rebuilds instead.
    $this->assertSame(
      ['advanced:test', 'advanced:complete', 'completed:(none)'],
      $walk,
      'once the inspection has run the walk finishes, unheld',
    );
  }

  /**
   * Status carries toolchain rows probed from the executor's own mapping.
   *
   * Armed-and-working must be distinguishable from armed-and-broken before
   * a run hits it. The rows report the exact path the executor would run —
   * one fact, never two implementations.
   */
  public function testStatusReportsTheToolchain(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    mkdir($root . '/vendor/bin', 0755, TRUE);
    file_put_contents($root . '/vendor/bin/phpcs', "#!/bin/sh\nexit 0\n");

    $status = $this->facade(new NullSiteDriver())->status($root);

    $toolchain = $status['toolchain'];
    $this->assertIsArray($toolchain);
    $row = static function (string $gate) use ($toolchain): array {
      Assert::assertIsArray($toolchain[$gate]);
      return $toolchain[$gate];
    };
    $this->assertTrue($row('phpcs')['present']);
    $this->assertSame('vendor/bin/phpcs', $row('phpcs')['binary']);
    $this->assertTrue($row('phpcs')['on']);
    $this->assertFalse($row('phpstan')['present']);
    $this->assertSame(
      'node_modules/.bin/playwright',
      $row('playwright')['binary'],
    );
    $this->assertFalse(
      $row('phpunit')['suite_config'],
      'the phpunit row also reports whether a suite config exists',
    );
    $this->assertArrayNotHasKey('custom:lint', $toolchain);
    $this->assertArrayNotHasKey(
      'rendered_check',
      $toolchain,
      'a site gate runs through the driver, not a binary — a toolchain row for it reads "missing" forever',
    );
  }

  /**
   * Answering when nothing is waiting is a typed error, not a crash.
   */
  public function testAnsweringWithNoRunIsTyped(): void {
    $root = $this->makeRoot();

    $this->expectException(StateError::class);
    $this->expectExceptionMessage('no run in progress');
    $this->facade(new NullSiteDriver())->answer($root, 'yes');
  }

  /**
   * Every surface renders the ONE envelope RunOutcome::toArray() builds.
   *
   * The same five fields used to be assembled three times — bin, drush,
   * MCP — which is precisely the second-implementation drift the facade
   * exists to prevent. Two halves: the envelope itself has the agreed
   * shape, and each surface's source actually calls it (a surface class
   * cannot be instantiated in this suite, so the call is pinned in source).
   *
   * This package now owns ONE of the three surfaces. The drush and MCP
   * classes moved to the droost module when this became a framework-free
   * library, and their half of the assertion moved with them —
   * droost_workflow's WorkflowMcpToolsTest pins those two. The property is
   * preserved rather than weakened: the envelope's shape is asserted HERE,
   * once, and each surface separately asserts that it calls toArray()
   * instead of assembling its own.
   */
  public function testEverySurfaceRendersTheSharedEnvelope(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $outcome = $this->facade(new NullSiteDriver())->run($root);

    $envelope = $outcome->toArray();
    $this->assertSame(
      ['outcome', 'current_phase', 'preset', 'report', 'blocked', 'awaiting', 'retries'],
      array_keys($envelope),
    );
    $this->assertSame('custom', $envelope['preset'], 'the envelope names the level the run is held to');
    $retries = $envelope['retries'];
    $this->assertIsArray($retries);
    $this->assertSame(
      ['attempts', 'remaining', 'max_gate_retries', 'exhausted'],
      array_keys($retries),
    );

    $src = (string) file_get_contents(dirname(__DIR__) . '/src/Cli/ArgvDispatcher.php');
    $this->assertStringContainsString(
      '$outcome->toArray()',
      $src,
      'bin must render the shared envelope, not assemble its own',
    );
  }

  /**
   * The CLI records an inspection from stdin and a browser from argv.
   *
   * The dispatcher is exercised directly — injected streams, no process —
   * so the two verbs' whole path (parse, persist, envelope) is proven at
   * the surface a plain Claude Code or Codex session actually calls.
   */
  public function testTheCliRecordsInspectionsAndBrowserCapability(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $out = [];
    $dispatcher = new ArgvDispatcher(
      function (string $line) use (&$out): void {
        $out[] = $line;
      },
      static function (string $line): void {},
      static fn (): string => '2026-08-25T00:00:00+00:00',
      static fn (): string => 'run-cli',
      static fn (): string => "## Seeker Inspection\n\nInspector: independent\n\n(no findings)\n",
    );

    $this->assertSame(0, $dispatcher->dispatch(['run'], $root));
    $this->assertSame(
      0,
      $dispatcher->dispatch(['declare-browser', 'none'], $root),
    );
    $this->assertSame(
      0,
      $dispatcher->dispatch(['declare-tasks', 'claude-code'], $root),
    );
    $this->assertSame(0, $dispatcher->dispatch(['seeker-report'], $root));

    $status = $this->facade(new NullSiteDriver())->status($root);
    $run = $status['run'];
    $this->assertIsArray($run);
    $this->assertSame('none', $run['browser']);
    // Declared through the CLI, readable through the facade: the whole point
    // of a declaration is that the OTHER surfaces can see it.
    $this->assertSame('claude-code', $run['tasks']);
    $this->assertIsArray($run['seeker']);
    $this->assertSame('clean', $run['seeker']['status']);
    $this->assertTrue($run['seekers']);

    // A word outside the vocabulary is a usage error, recorded nowhere.
    $this->assertSame(
      2,
      $dispatcher->dispatch(['declare-browser', 'chrome'], $root),
    );
    $this->assertSame(
      2,
      $dispatcher->dispatch(['declare-tasks', 'jira'], $root),
    );
    $after = $this->facade(new NullSiteDriver())->status($root);
    $afterRun = $after['run'];
    $this->assertIsArray($afterRun);
    $this->assertSame(
      'claude-code',
      $afterRun['tasks'],
      'A refused declaration must not overwrite the accepted one.',
    );
  }

  /**
   * Gate results keyed by gate name.
   *
   * @param array<string, mixed> $report
   *   A serialized PhaseReport.
   *
   * @return array<string, array<array-key, mixed>>
   *   Gate name to its serialized result.
   */
  private function byGate(array $report): array {
    /** @var array<string, array<array-key, mixed>> $out */
    $out = [];
    $gates = $report['gates'];
    $this->assertIsArray($gates);
    foreach ($gates as $gate) {
      $this->assertIsArray($gate);
      $name = $gate['gate'];
      $this->assertIsString($name);
      $out[$name] = $gate;
      unset($gate);
    }
    return $out;
  }

  /**
   * An answer that did not move the phase is a non-zero exit, with the reason.
   *
   * `answer` chose its line from the phase alone, so when the facade declined
   * to advance past a blocked check it printed `answered — now at plan`, exit
   * 0 — byte for byte what an advance prints. A reviewer ran forty-three
   * `answer`/`run` cycles that way and could not tell "advanced" from "still
   * here" from the surface at all. stderr with a non-zero exit is this
   * surface's whole contract, and a phase that did not move is not a success.
   */
  public function testTheCliReportsAnAnswerThatDidNotMoveThePhase(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: pair\n");
    $out = [];
    $err = [];
    $dispatcher = new ArgvDispatcher(
      function (string $line) use (&$out): void {
        $out[] = $line;
      },
      function (string $line) use (&$err): void {
        $err[] = $line;
      },
      static fn (): string => '2026-08-25T00:00:00+00:00',
      static fn (): string => 'run-cli',
      static fn (): string => '',
    );
    $this->assertSame(0, $dispatcher->dispatch(['run'], $root), 'pair mode pauses at plan');

    // A blocked check this phase's own audit does not speak for — the shape a
    // contributed plugin that threw leaves behind.
    (new EvidenceStore($root))->record('run-cli', 'plan', new CheckRecord(
      'check',
      'contributed_checks',
      CheckState::Blocked,
      Fault::Environment,
      'the provider threw',
      'reinstall or remove the module that contributes it',
    ));

    $exit = $dispatcher->dispatch(['answer', 'keep going'], $root);

    $this->assertSame(ArgvDispatcher::EXIT_RUN_FAILED, $exit, 'a phase that did not move is not exit 0');
    $stderr = implode("\n", $err);
    $this->assertStringContainsString('still at plan', $stderr, 'it says where the run still is');
    $this->assertStringContainsString('contributed_checks', $stderr, 'and what holds it');
    $this->assertStringContainsString('reinstall or remove', $stderr, 'and the remedy');
    $this->assertNotContains('answered — now at plan', $out, 'and never the line an advance prints');
  }

  /**
   * An answer that STOPPED the run is reported as the decision it was.
   *
   * The held-phase path reads the store for blocked rows, and a "stop here"
   * leaves one behind (`stopped_by_operator`) — so unless the stop is decided
   * FIRST, a human ending a stuck run is told "still at code, 1 check holds
   * the phase" as though they had not just ended it. The one place the
   * product asks somebody for a judgement has to report the judgement back.
   */
  public function testTheCliReportsAnAnswerThatStoppedTheRun(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\n");
    $out = [];
    $err = [];
    $dispatcher = new ArgvDispatcher(
      function (string $line) use (&$out): void {
        $out[] = $line;
      },
      function (string $line) use (&$err): void {
        $err[] = $line;
      },
      static fn (): string => '2026-08-25T00:00:00+00:00',
      static fn (): string => 'run-cli',
      static fn (): string => '',
    );
    $this->assertSame(0, $dispatcher->dispatch(['run'], $root), 'plan passes and the run moves on');

    // The run in front of the block ceiling's question — the only question
    // whose answer can end a run.
    $store = new RunStateStore($root);
    $state = $store->load();
    $this->assertNotNull($state);
    $phase = $state->currentPhase;
    $this->assertNotNull($phase);
    $store->save($state->awaiting((new PendingQuestion(
      $phase,
      'Is the work progressing, or is it stuck on something it cannot fix?',
      $phase->value . ': 60 unresolved non-gate blocks',
      '2026-08-25T00:00:00+00:00',
      $phase->value . ' has not cleared a block in 60 attempts',
      [],
      ['keep going', 'stop here'],
      PendingQuestion::KIND_STUCK,
    ))->toArray()));

    $exit = $dispatcher->dispatch(['answer', 'stop here — this is stuck and I will look at it'], $root);

    $this->assertSame(ArgvDispatcher::EXIT_RUN_FAILED, $exit, 'a stopped run is not exit 0');
    $this->assertStringContainsString(
      'STOPPED at ' . $phase->value,
      implode("\n", $out),
      'it reports the decision that was made',
    );
    $this->assertStringNotContainsString(
      'still at',
      implode("\n", $err),
      'and not as a phase merely held by the row the stop itself wrote',
    );
  }

  /**
   * A facade with a deterministic clock and identity.
   *
   * @param \Droost\Workflow\Gate\SiteDriverInterface $driver
   *   The driver that makes it a CLI or a live surface.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(SiteDriverInterface $driver): WorkflowFacade {
    return new WorkflowFacade(
      new class() implements GateExecutorInterface {

        /**
         * {@inheritdoc}
         */
        public function execute(
          GateSettings $gate,
          string $projectRoot,
        ): GateResult {
          return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
        }

      },
      $driver,
      new RunStateOnlySink(),
      static fn (): string => '2026-07-27T10:00:00+00:00',
      static fn (): string => 'run-test',
    );
  }

  /**
   * A driver that stands in for a booted site.
   *
   * @return \Droost\Workflow\Gate\SiteDriverInterface
   *   The double.
   */
  private function fakeSiteDriver(): SiteDriverInterface {
    return new class() implements SiteDriverInterface {

      /**
       * {@inheritdoc}
       */
      public function available(): bool {
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function supports(): array {
        return ['rendered_check', 'config_clean', 'grounding_check'];
      }

      /**
       * {@inheritdoc}
       */
      public function run(GateSettings $gate, string $projectRoot): GateResult {
        return new GateResult($gate->name, GateStatus::Passed, 0, 5, 'rendered');
      }

    };
  }

}
