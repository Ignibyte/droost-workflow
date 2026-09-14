<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Mode;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Spec\SpecError;
use Droost\Workflow\State\PhaseStatus;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\State\StateError;
use Droost\Workflow\WorkflowFacade;

/**
 * The run's whole life: pair mode walks to the end, and the end stays ended.
 *
 * Three contracts pinned here were each broken in the field before they were
 * tested. Pair mode paused after every passing phase and answering only
 * cleared the question — the same question re-asked forever, so a pair run
 * could never finish. The mutating verbs accepted a FINISHED run and rewrote
 * its record. And reset trusted things it should not have: the lever file's
 * parseability (archiving a live run when the yml had a typo) and rename()'s
 * success (reporting "cleared" over a file still in place).
 */
final class WorkflowFacadeLifecycleTest extends WorkflowTestCase {

  /**
   * In pair mode, answering the check-in advances — all the way to the end.
   */
  public function testPairModeWalksToCompletionThroughAnswers(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: pair\n");
    $executor = $this->allGatesPass();

    // Plan passes its (empty) gate set and pauses for the check-in.
    $paused = $this->facade($executor)->run($root);
    $this->assertSame(Outcome::Paused, $paused->outcome);
    $this->assertSame(Phase::Plan, $paused->state->currentPhase);

    // The answer IS the check-in: the run moves to code.
    $answered = $this->facade($executor)->answer($root, 'yes, continue');
    $this->assertSame(Phase::Code, $answered->currentPhase);
    $this->assertSame(PhaseStatus::Passed, $answered->statusOf(Phase::Plan));
    $this->assertNull($answered->awaiting, 'the pause is consumed');
    $this->assertCount(1, $answered->qaHistory, 'the exchange is recorded');

    // Walk the rest: each phase pauses once, each answer advances once. The
    // seeker checkpoint still holds a green code/complete phase FIRST — the
    // answer cannot skip it — so a clean inspection is recorded when due.
    for ($i = 0; $i < 12; $i++) {
      $facade = $this->facade($executor);
      $outcome = $facade->run($root);
      if ($outcome->outcome === Outcome::InspectionDue) {
        $facade->recordSeeker($root, "## Seeker Inspection\n\nInspector: independent\n\n(no findings)\n");
        continue;
      }
      if ($outcome->outcome === Outcome::Paused) {
        $this->facade($executor)->answer($root, 'continue');
        continue;
      }
      break;
    }

    // Answering the FINAL phase's check-in finalizes the run: pair mode can
    // actually reach the terminal state.
    $reloaded = (new RunStateStore($root))->load();
    $this->assertNotNull($reloaded);
    $this->assertNull($reloaded->currentPhase, 'the pair run finished');
    $this->assertSame(PhaseStatus::Passed, $reloaded->statusOf(Phase::Complete));
  }

  /**
   * A finished run refuses every mutating verb: the record is closed.
   */
  public function testMutatingVerbsRefuseTheFinishedRun(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmax_gate_retries: 1\n");
    $executor = $this->allGatesPass();
    $this->driveToCompletion($executor, $root);

    $verbs = [
      'declareBrowser' => fn () => $this->facade($executor)->declareBrowser($root, 'native'),
      'declareTasks' => fn () => $this->facade($executor)->declareTasks($root, 'claude-code'),
      'recordSeeker' => fn () => $this->facade($executor)->recordSeeker($root, "## Seeker Inspection\n\nInspector: independent\n\n(no findings)\n"),
      'swap' => fn () => $this->facade($executor)->swap($root, Mode::Agentic),
      'answer' => fn () => $this->facade($executor)->answer($root, 'yes'),
    ];
    foreach ($verbs as $name => $verb) {
      try {
        $verb();
        $this->fail($name . ' must refuse a finished run');
      }
      catch (StateError $e) {
        $this->assertStringContainsString('ended', $e->getMessage(), $name);
        $this->assertStringContainsString('reset', $e->getMessage(), $name);
      }
    }

    // The record itself is untouched by the refusals.
    $reloaded = (new RunStateStore($root))->load();
    $this->assertNotNull($reloaded);
    $this->assertNull($reloaded->browser, 'no browser was written post-completion');
  }

  /**
   * Reset archives a finished run and clears the way — and only then.
   */
  public function testResetArchivesTheFinishedRun(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $executor = $this->allGatesPass();
    $this->driveToCompletion($executor, $root);
    touch($root . '/droost/droost-workflow/.guard-warned-require-run');

    $archived = $this->facade($executor)->reset($root);

    $this->assertFileDoesNotExist($root . '/droost/droost-workflow/run.json');
    $this->assertFileExists($archived);
    $this->assertStringStartsWith($root . '/droost/droost-workflow/history/', $archived);
    $record = json_decode((string) file_get_contents($archived), TRUE);
    $this->assertIsArray($record, 'the archive is the record, not a copy of nothing');
    $this->assertFileDoesNotExist(
      $root . '/droost/droost-workflow/.guard-warned-require-run',
      'per-run warn-once markers do not outlive the run',
    );

    // And the next bare `run` does NOT quietly inherit this ticket's spec.
    //
    // This assertion used to say the opposite — that a bare run after a reset
    // "starts fresh" — and it was codifying a bug. The reset archives the
    // record and leaves the spec file where it is, so the state directory
    // holds exactly one candidate and the resolver adopted it: the FINISHED
    // ticket's acceptance criteria, governing a new ticket, with nothing said.
    // A reviewer drove three tickets through one repository and watched the
    // second inherit the first's contract. At two leftover specs the resolver
    // already refused and asked; at one there was nothing to ask about.
    try {
      $this->facade($executor)->run($root);
      $this->fail('a finished ticket\'s spec is not adopted in silence');
    }
    catch (SpecError $error) {
      $this->assertStringContainsString('already finished', $error->getMessage());
      $this->assertStringContainsString('--spec=', $error->getMessage());
    }

    // Re-running that same ticket is one flag away, and still works.
    $again = $this->facade($executor)->run($root, spec: 'droost/droost-workflow/spec-test-run.md');
    $this->assertSame(Outcome::Advanced, $again->outcome);
    $this->assertSame(Phase::Code, $again->state->currentPhase);
  }

  /**
   * Naming a spec at a finished run refuses instead of doing nothing.
   *
   * `run --spec=<the next ticket's spec>` on a completed run returned
   * `{"outcome":"completed","report":null}`. The spec was never adopted,
   * nothing ran, and nothing in that answer says either of those things — so
   * the caller's next move is made believing a new ticket started under a new
   * contract. A reviewer hit it on the second of three tickets.
   *
   * The `completed` answer to a BARE `run` is still right and still silent
   * about specs, because nothing was claimed.
   */
  public function testNamingSpecsAtFinishedRunsIsRefused(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $executor = $this->allGatesPass();
    $this->driveToCompletion($executor, $root);
    file_put_contents($root . '/droost/droost-workflow/spec-next.md', "# Next\n");

    $bare = $this->facade($executor)->run($root);
    $this->assertSame(Outcome::Completed, $bare->outcome, 'a bare re-run still just says so');

    try {
      $this->facade($executor)->run($root, spec: 'droost/droost-workflow/spec-next.md');
      $this->fail('a spec named at a finished run must not be swallowed');
    }
    catch (StateError $error) {
      $this->assertStringContainsString('NOT adopted', $error->getMessage());
      $this->assertStringContainsString('reset', $error->getMessage(), 'and it names the way forward');
    }
  }

  /**
   * An answer does not advance a phase whose checks are still blocked.
   *
   * `answer` re-audited Code and Test and fell straight through to
   * `advanceTo()`/`complete()` everywhere else. A reviewer drove sixty blocked
   * rows at `complete`, answered the block ceiling's question with "keep
   * going", and got:
   *
   *     answered — the run completed
   *     {'plan':'passed','code':'passed','test':'passed','complete':'passed'}
   *     rows left: complete | contributed_checks | blocked | environment | 60
   *
   * Sixty unresolved blocks and every phase reported passed. The ceiling
   * exists so a human can say "I have seen this, carry on" — it was laundering
   * the blocks into a green rather than surfacing them. Same at `plan`.
   *
   * An answer is a person's words. It cannot change what a check found, and a
   * phase that advances on one is reporting a verification nobody performed.
   */
  public function testAnswersDoNotAdvancePastBlockedChecks(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: pair\n");
    $executor = $this->allGatesPass();
    $facade = $this->facade($executor);
    $facade->run($root);

    $store = new RunStateStore($root);
    $state = $store->load();
    $this->assertNotNull($state, 'the run started and paused for its question');
    $this->assertNotNull($state->awaiting);
    $phase = $state->currentPhase;
    $this->assertNotNull($phase);

    // A blocked check this phase's audit does not speak for — the shape the
    // reviewer reached with a contributed plugin that threw.
    (new EvidenceStore($root))->record($state->runId, $phase->value, new CheckRecord(
      'check',
      'contributed_checks',
      CheckState::Blocked,
      Fault::Environment,
      'the provider threw',
      'reinstall or remove the module that contributes it',
    ), '2026-09-14T00:00:00+00:00');

    $answered = $facade->answer($root, 'keep going');

    $this->assertSame(
      $phase,
      $answered->currentPhase,
      'the phase does not advance while a check is still blocked',
    );
    $this->assertNotSame(
      PhaseStatus::Passed,
      $answered->statusOf($phase),
      'and it is certainly not marked passed',
    );
  }

  /**
   * A stuck question cannot be answered by changing the subject.
   *
   * Two ways past the block ceiling were open to the agent, and both left the
   * blocks unresolved:
   *
   *   * `answer "keep going"` re-audited only Code and Test, and fell straight
   *     through to `advanceTo()`/`complete()` at every other phase. A reviewer
   *     drove sixty blocked rows at `complete`, answered, and got "the run
   *     completed" with all four phases marked passed and the sixty rows still
   *     there. Same at `plan`.
   *   * `swap agentic` called `released()` regardless of the question's kind,
   *     so the pause cleared, the counter reset, and nothing anywhere recorded
   *     that a question had been asked. `run` x60 -> `swap` -> repeat, for
   *     ever, with no human involved. `swap` is not an operator-only verb.
   *
   * The ceiling exists so a human can say "I have seen this, carry on". It was
   * laundering unresolved blocks into a green instead of surfacing them.
   *
   * What a human can still do is end the run: `answer "stop here"` fails the
   * phase and records who stopped it. That is a decision; dismissing the
   * question is not.
   */
  public function testTheStuckQuestionCannotBeAnsweredByChangingTheSubject(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: pair\n");
    $executor = $this->allGatesPass();
    $facade = $this->facade($executor);
    $facade->run($root);

    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state, 'the run started');
    $this->assertNotNull($state->awaiting, 'and pair mode is waiting on a question');

    // A swap is refused while a STUCK question stands, and permitted for an
    // ordinary conversational pause — the distinction the old code ignored.
    $facade->swap($root, Mode::Agentic);
    $after = (new RunStateStore($root))->load();
    $this->assertNotNull($after);
    $this->assertNull($after->awaiting, 'an ordinary pause is still swappable');

    $stuck = $after->awaiting($this->stuckQuestion());
    (new RunStateStore($root))->save($stuck);
    try {
      $facade->swap($root, Mode::Agentic);
      $this->fail('swapping mode is not an answer to a stuck question');
    }
    catch (\InvalidArgumentException $error) {
      $this->assertStringContainsString('stuck', $error->getMessage());
      $this->assertStringContainsString('stop here', $error->getMessage(), 'and it names the way out');
    }
  }

  /**
   * A pending question shaped like the block ceiling's.
   *
   * @return array<string, string>
   *   The awaiting payload.
   */
  private function stuckQuestion(): array {
    return [
      'kind' => 'stuck',
      'question' => 'This phase has blocked 60 times. Keep going, or stop here?',
      'asked_at' => '2026-09-14T00:00:00+00:00',
      'phase' => 'plan',
    ];
  }

  /**
   * A live run is refused without force, whatever the lever file looks like.
   *
   * Classification reads the RUN STATE alone: a typo in droost.workflow.yml —
   * a file the plan phase explicitly allows editing mid-run — must not turn
   * "may I clear this run" into "archive the live run".
   */
  public function testResetRefusesTheLiveRunOverBrokenLevers(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: pair\n");
    $executor = $this->allGatesPass();
    $this->facade($executor)->run($root);

    // Break the lever file AFTER the run began (its levers are frozen).
    file_put_contents($root . '/droost.workflow.yml', "presett: custom\n");

    try {
      $this->facade($executor)->reset($root);
      $this->fail('a live run must not be cleared without force');
    }
    catch (StateError $e) {
      $this->assertStringContainsString('in progress', $e->getMessage());
      $this->assertStringContainsString('plan', $e->getMessage());
    }
    $this->assertFileExists($root . '/droost/droost-workflow/run.json');

    // Abandoning it stays possible — said out loud.
    $archived = $this->facade($executor)->reset($root, force: TRUE);
    $this->assertFileExists($archived);
    $this->assertFileDoesNotExist($root . '/droost/droost-workflow/run.json');
  }

  /**
   * An unreadable run.json is clearable, and collisions never overwrite.
   */
  public function testResetArchivesCorruptRecordsWithoutOverwriting(): void {
    $root = $this->makeRoot();
    $executor = $this->allGatesPass();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);

    file_put_contents($root . '/droost/droost-workflow/run.json', 'FIRST-GARBAGE');
    $first = $this->facade($executor)->reset($root);
    file_put_contents($root . '/droost/droost-workflow/run.json', 'SECOND-GARBAGE');
    $second = $this->facade($executor)->reset($root);

    $this->assertNotSame($first, $second, 'the second archive gets its own name');
    $this->assertSame('FIRST-GARBAGE', file_get_contents($first));
    $this->assertSame('SECOND-GARBAGE', file_get_contents($second));
  }

  /**
   * A failed archive is an error, never a false "cleared".
   */
  public function testResetFailsLoudlyWhenTheArchiveCannotBeWritten(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $executor = $this->allGatesPass();
    $this->driveToCompletion($executor, $root);

    // With history in the way as a FILE, mkdir and rename both must fail.
    file_put_contents($root . '/droost/droost-workflow/history', 'in the way');

    try {
      $this->facade($executor)->reset($root);
      $this->fail('an unarchivable record must not be reported cleared');
    }
    catch (StateError $e) {
      $this->assertStringContainsString('archive', $e->getMessage());
      $this->assertStringContainsString('nothing was cleared', $e->getMessage());
    }
    $this->assertFileExists(
      $root . '/droost/droost-workflow/run.json',
      'nothing was deleted on the failed archive',
    );
  }

  /**
   * Resetting nothing says so.
   */
  public function testResetWithNoRunSaysStartOne(): void {
    $root = $this->makeRoot();
    $this->expectException(StateError::class);
    $this->expectExceptionMessage('no run in progress');
    $this->facade($this->allGatesPass())->reset($root);
  }

  /**
   * Drives an automated run to its terminal state.
   *
   * @param \Droost\Workflow\Gate\GateExecutorInterface $executor
   *   The gate executor.
   * @param string $root
   *   The project root.
   */
  private function driveToCompletion(
    GateExecutorInterface $executor,
    string $root,
  ): void {
    for ($i = 0; $i < 16; $i++) {
      $facade = $this->facade($executor);
      $outcome = $facade->run($root);
      if ($outcome->outcome === Outcome::InspectionDue) {
        $facade->recordSeeker($root, "## Seeker Inspection\n\nInspector: independent\n\n(no findings)\n");
        continue;
      }
      if ($outcome->outcome !== Outcome::Advanced) {
        break;
      }
    }
    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state);
    $this->assertNull($state->currentPhase, 'the fixture run reached its end');
  }

  /**
   * An executor where every gate passes.
   *
   * @return \Droost\Workflow\Gate\GateExecutorInterface
   *   The double.
   */
  private function allGatesPass(): GateExecutorInterface {
    return new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(
        GateSettings $gate,
        string $projectRoot,
      ): GateResult {
        return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };
  }

  /**
   * A fresh facade over a shared executor, as a new process would build it.
   *
   * @param \Droost\Workflow\Gate\GateExecutorInterface $executor
   *   The shared executor.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(GateExecutorInterface $executor): WorkflowFacade {
    return new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-08-26T12:00:00+00:00',
      static fn (): string => 'run-lifecycle',
    );
  }

}
