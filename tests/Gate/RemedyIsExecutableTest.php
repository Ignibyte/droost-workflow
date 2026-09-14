<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Evidence\WorkType;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateRemedy;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateRunner;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\State\RunState;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A printed remedy is something you can actually type.
 *
 * `type_coverage`'s remedy was one sentence for whichever gate had measured
 * nothing — "set gates.%s.paths in droost.workflow.yml … Levers are frozen per
 * run, so an operator editing them now is editing the next run — ask them to
 * clear this one with `reset --force` after."
 *
 * Both halves are false, and both fail in the direction that costs a run.
 *
 * ONLY FIVE GATES HAVE A `paths` LEVER. `WorkType::mustMeasure()` also names
 * phpunit, config_clean and rendered_check, which do not — and writing the key
 * anyway is not a no-op. `WorkflowConfig::load()` refuses a gate option it does
 * not know, and it refuses the WHOLE FILE, so an operator who does exactly as
 * instructed ends with every droost-workflow command printing
 *
 *     droost.workflow.yml: gate "phpunit" has no option "paths"
 *     (accepts: on, required, timeout, mode)
 *
 * and a project that can no longer run its own workflow. The remedy for a
 * blocked run broke the tool.
 *
 * AND THE LEVER IS NOT FROZEN. `on` is the frozen half; `GateRunner::run()`
 * calls `liveTuning()` and overlays the file's CURRENT tuning options — `paths`
 * among them — before every gate, announcing it in the gate's own summary. So
 * the sentence told an operator to throw away a run that one edit and one
 * re-run would have finished, and `reset --force` is not reversible.
 */
final class RemedyIsExecutableTest extends WorkflowTestCase {

  /**
   * Every lever a remedy names is one the config reader accepts.
   *
   * Written this way round on purpose: asserting the TEXT says "phpcs" only
   * repeats the sentence back. Feeding what it says to type through the thing
   * that reads it is the only assertion that can catch the next version of
   * this defect, whatever wording it arrives in.
   */
  public function testEveryLeverAnyRemedyNamesIsRealAndLoads(): void {
    $named = [];
    foreach ($this->gatesThatCanMeasureNothing() as $gate) {
      $remedy = GateRemedy::measuredNothing($gate);
      $this->assertNotSame('', $remedy, $gate . ' says something');
      preg_match_all('/gates\.([a-z_]+)\.([a-z_]+)/', $remedy, $matches, PREG_SET_ORDER);
      foreach ($matches as $match) {
        $named[$match[1] . '.' . $match[2]] = [$match[1], $match[2], $gate];
      }
    }
    $this->assertNotSame([], $named, 'the remedies name levers at all');

    foreach ($named as $key => [$gate, $option, $about]) {
      $root = $this->makeRootWithConfig(sprintf(
        "gates:\n  %s:\n    %s: %s\n",
        $gate,
        $option,
        $option === 'on' ? 'false' : '"src"',
      ));
      // The reader, not a list copied out of it: a lever this accepts is a
      // lever, and there is no second opinion to drift from.
      WorkflowConfig::load($root);
      $this->assertContains(
        $option,
        [...GateSettings::optionNames($gate), 'on', 'mode'],
        sprintf('%s (named in the remedy for %s) is a real lever', $key, $about),
      );
    }
  }

  /**
   * No remedy tells an operator their edit will not land until the next run.
   */
  public function testNoRemedyClaimsTuningIsFrozen(): void {
    foreach ($this->gatesThatCanMeasureNothing() as $gate) {
      $remedy = GateRemedy::measuredNothing($gate);
      $this->assertStringNotContainsStringIgnoringCase(
        'levers are frozen per run',
        $remedy,
        $gate . ': tuning is re-read at gate time, so this would send the operator away',
      );
      foreach (['editing the next run', 'the next run\'s to fix'] as $deferral) {
        $this->assertStringNotContainsString(
          $deferral,
          $remedy,
          $gate . ': the edit lands in THIS run, and saying otherwise sends the operator away',
        );
      }
      if (str_contains($remedy, 'reset --force')) {
        $this->assertStringContainsString(
          'No `reset --force` is needed',
          $remedy,
          $gate . ': the only reason to mention an irreversible command is to rule it out',
        );
      }
    }
  }

  /**
   * A gate with no `paths` lever says not to add one, and why.
   */
  public function testGatesWithoutThatLeverSayToLeaveItAlone(): void {
    foreach (['phpunit', 'config_clean', 'rendered_check'] as $gate) {
      $this->assertNotContains(
        'paths',
        GateSettings::optionNames($gate),
        $gate . ' really has no paths lever — the premise of this test',
      );
      $this->assertStringContainsString(
        'rejects the whole file',
        GateRemedy::measuredNothing($gate),
        $gate . ': the consequence is the whole tool stopping, so it gets said',
      );
    }
  }

  /**
   * And the promise the remedy makes about `paths` is kept.
   *
   * The sibling test pins `standard`; this pins the lever the remedy actually
   * names, because a promise in a printed sentence is a contract with whoever
   * reads it.
   */
  public function testRepointingPathsReachesTheGateInTheSameRun(): void {
    $root = $this->makeRootWithConfig(
      "preset: custom\ngates:\n  phpstan: { level: 6, paths: \"nowhere\" }\n",
    );
    $state = RunState::begin('run-1', '2026-09-02T09:00:00+00:00', WorkflowConfig::load($root));
    $executor = new class() implements GateExecutorInterface {

      /**
       * The settings each gate ran with.
       *
       * @var array<string, \Droost\Workflow\Config\GateSettings>
       */
      public array $seen = [];

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        $this->seen[$gate->name] = $gate;
        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, 'passed', [], $gate->name);
      }

    };

    // The operator does exactly what the remedy says, mid-run.
    file_put_contents(
      $root . '/droost.workflow.yml',
      "preset: custom\ngates:\n  phpstan: { level: 6, paths: \"src\" }\n",
    );
    $report = (new GateRunner($executor, new NullSiteDriver()))->run($state, Phase::Code, $root);

    $this->assertArrayHasKey('phpstan', $executor->seen);
    $this->assertSame(
      'src',
      $executor->seen['phpstan']->option('paths'),
      'the re-pointed lever reaches the gate in the run it was meant to unblock',
    );
    $summaries = array_map(
      static fn (GateResult $result): string => $result->summary,
      array_values(array_filter(
        $report->results,
        static fn (mixed $one): bool => $one instanceof GateResult && $one->gate === 'phpstan',
      )),
    );
    $this->assertStringContainsString(
      'levers re-read at gate time: paths nowhere → src',
      implode(' ', $summaries),
      'and the run says out loud that it moved, so nobody has to take this on trust',
    );
  }

  /**
   * Every gate a work type rests on — every gate this remedy speaks for.
   *
   * @return list<string>
   *   The gate names.
   */
  private function gatesThatCanMeasureNothing(): array {
    $gates = [];
    foreach (WorkType::cases() as $type) {
      foreach ($type->mustMeasure() as $gate) {
        $gates[$gate] = $gate;
      }
    }

    return array_values($gates);
  }

}
