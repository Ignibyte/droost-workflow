<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Event\RunEventLog;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\RunState;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\WorkflowFacade;
use Droost\Workflow\WorkItem\MarkdownWorkItemSource;
use Droost\Workflow\WorkItem\WorkItem;
use Droost\Workflow\WorkItem\WorkItemError;
use Droost\Workflow\WorkItem\WorkItemSourceInterface;

/**
 * A run bound to a ticket: declared, moved to in_progress, and to review.
 *
 * The engine moves a bound ticket twice and only twice. `done` is a
 * person's move, and a source that refuses the move never fails the run.
 */
final class WorkflowFacadeWorkItemTest extends WorkflowTestCase {

  private const LEVERS = "preset: custom\nmode: agentic\nseekers:\n  on: false\nwork_item:\n  provider: markdown\n";

  /**
   * A `run --ticket` binds the run, records the declaration, and moves it.
   */
  public function testRunBindsTheTicket(): void {
    $root = $this->project();
    $facade = $this->facade($root);

    $outcome = $facade->run($root, NULL, 'TICKET-169');

    $state = $this->stateOf($root);
    $this->assertNotNull($state);
    $this->assertSame('TICKET-169', $state->workItem['id'] ?? NULL);
    $this->assertSame('markdown', $state->workItem['source'] ?? NULL);
    $this->assertSame('in_progress', $state->workItem['status'] ?? NULL, 'the binding records the state the ticket moved to');
    $this->assertArrayNotHasKey('body', $state->workItem, 'a run records the binding, not the ticket');
    $this->assertSame($state->workItem, $outcome->state->workItem);

    // run.json carries it, and reads back as it was written.
    $document = json_decode((string) file_get_contents($root . '/droost/droost-workflow/run.json'), TRUE);
    $this->assertIsArray($document);
    $this->assertIsArray($document['work_item'] ?? NULL);
    $this->assertSame('TICKET-169', $document['work_item']['id'] ?? NULL);

    // Every event the run writes names its ticket (TICKET-187's work_item_id).
    $events = iterator_to_array((new RunEventLog((new RunStateStore($root))->directory()))->read(), FALSE);
    $this->assertNotSame([], $events);
    $this->assertSame('run.started', $events[0]->type);
    $this->assertSame(['TICKET-169'], array_values(array_unique(array_map(static fn ($event) => $event->workItemId, $events))));

    // The declaration a contributed `work_item_declared` check reads.
    $this->assertSame(['TICKET-169'], (new EvidenceStore($root))->declared($state->runId, 'work_item'));

    // The ticket itself.
    $this->assertStringContainsString("\nstatus: in_progress\n", (string) file_get_contents($root . '/droost/tickets/open/TICKET-169-the-container-stops-granting-root.md'));

    // And status shows it.
    $status = $facade->status($root);
    $this->assertIsArray($status['run']);
    $this->assertIsArray($status['run']['work_item'] ?? NULL);
    $this->assertSame('TICKET-169', $status['run']['work_item']['id'] ?? NULL);

    // Naming the same ticket again is no rebinding and no error.
    $facade->run($root, NULL, 'TICKET-169');
    $this->assertSame(['TICKET-169'], (new EvidenceStore($root))->declared($state->runId, 'work_item'));
  }

  /**
   * A completed run moves its ticket to review, and never to done.
   */
  public function testCompletionMovesTheTicketToReviewNeverDone(): void {
    $root = $this->project();
    $facade = $this->facade($root);

    $outcome = $facade->run($root, NULL, 'TICKET-169');
    for ($i = 0; $i < 12 && $outcome->outcome === Outcome::Advanced; $i++) {
      $outcome = $facade->run($root);
    }
    $this->assertSame(Outcome::Completed, $outcome->outcome);
    $this->assertNull($outcome->state->currentPhase);

    $file = $root . '/droost/tickets/open/TICKET-169-the-container-stops-granting-root.md';
    $this->assertFileExists($file, 'a ticket in review stays in open/');
    $this->assertStringContainsString("\nstatus: review\n", (string) file_get_contents($file));
    $this->assertFileDoesNotExist($root . '/droost/tickets/closed/TICKET-169-the-container-stops-granting-root.md');
    $state = $this->stateOf($root);
    $this->assertNotNull($state);
    $this->assertSame('review', $state->workItem['status'] ?? NULL);
  }

  /**
   * A source that refuses to move the ticket leaves the run completed.
   */
  public function testThrowingSourceNeverFailsTheRun(): void {
    $root = $this->project();
    $real = new MarkdownWorkItemSource('droost/tickets', 'TICKET', $root);
    $refusing = new class($real) implements WorkItemSourceInterface {

      public function __construct(private readonly WorkItemSourceInterface $inner) {}

      /**
       * {@inheritdoc}
       */
      public function get(string $id): ?WorkItem {
        return $this->inner->get($id);
      }

      /**
       * {@inheritdoc}
       */
      public function list(?string $status = NULL): array {
        return $this->inner->list($status);
      }

      /**
       * {@inheritdoc}
       */
      public function create(string $title, string $type, string $body = ''): WorkItem {
        return $this->inner->create($title, $type, $body);
      }

      /**
       * {@inheritdoc}
       */
      public function transition(string $id, string $to, string $reason): WorkItem {
        throw new \RuntimeException('the tracker is down');
      }

    };
    $facade = $this->facade($root, $refusing);

    $outcome = $facade->run($root, NULL, 'TICKET-169');
    for ($i = 0; $i < 12 && $outcome->outcome === Outcome::Advanced; $i++) {
      $outcome = $facade->run($root);
    }

    $this->assertSame(Outcome::Completed, $outcome->outcome, 'the run completed though the ticket never moved');
    $state = $this->stateOf($root);
    $this->assertNotNull($state);
    $this->assertSame('ready', $state->workItem['status'] ?? NULL, 'the binding says what the ticket really is');
    $notes = (new EvidenceStore($root))->specNotes($state->runId, 'work_item');
    $this->assertCount(1, $notes, 'the refusals are on record, one note per ticket, its latest revision');
    $this->assertStringContainsString('could not be moved to review: the tracker is down', (string) $notes[0]['detail']);
  }

  /**
   * No source, an unknown ticket, or a second ticket: refused, nothing begun.
   */
  public function testRefusedBindings(): void {
    $root = $this->project();
    $plain = new WorkflowFacade($this->allGatesPass(), new NullSiteDriver(), new RunStateOnlySink(), static fn (): string => '2026-09-28T12:00:00+00:00', static fn (): string => 'run-tickets');
    try {
      $plain->run($root, NULL, 'TICKET-169');
      $this->fail('a ticket was bound with no source');
    }
    catch (WorkItemError $e) {
      $this->assertStringContainsString('no work-item source is configured', $e->getMessage());
    }
    $this->assertNull($this->stateOf($root), 'and no run began');

    $facade = $this->facade($root);
    try {
      $facade->run($root, NULL, 'TICKET-4040');
      $this->fail('an unknown ticket was bound');
    }
    catch (WorkItemError $e) {
      $this->assertStringContainsString('no ticket TICKET-4040', $e->getMessage());
    }
    $this->assertNull($this->stateOf($root));

    // A run begun with none binds none later, and a bound one keeps its own.
    $facade->run($root);
    try {
      $facade->run($root, NULL, 'TICKET-169');
      $this->fail('a ticket was bound to a run in progress');
    }
    catch (WorkItemError $e) {
      $this->assertStringContainsString('began with none', $e->getMessage());
    }
    $state = $this->stateOf($root);
    $this->assertInstanceOf(RunState::class, $state);
    $this->assertNull($state->workItem, 'a run without --ticket binds nothing');
  }

  /**
   * A project with druplit's two fixture tickets and a markdown source.
   *
   * @return string
   *   The root.
   */
  private function project(): string {
    $root = $this->makeRootWithConfig(self::LEVERS);
    $fixtures = [
      'open/TICKET-169-the-container-stops-granting-root.md',
      'closed/TICKET-89-reap-superseded-manager-runs.md',
    ];
    foreach ($fixtures as $file) {
      mkdir(dirname($root . '/droost/tickets/' . $file), 0755, TRUE);
      copy(__DIR__ . '/WorkItem/fixtures/' . $file, $root . '/droost/tickets/' . $file);
    }
    return $root;
  }

  /**
   * A facade over the project's markdown tickets, or another source.
   *
   * @param string $root
   *   The project.
   * @param \Droost\Workflow\WorkItem\WorkItemSourceInterface|null $source
   *   The source, or NULL for the project's markdown tickets.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(string $root, ?WorkItemSourceInterface $source = NULL): WorkflowFacade {
    return new WorkflowFacade(
      $this->allGatesPass(),
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-28T12:00:00+00:00',
      static fn (): string => 'run-tickets',
      workItems: $source ?? new MarkdownWorkItemSource('droost/tickets', 'TICKET', $root),
    );
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
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };
  }

  /**
   * The run as it stands on disk now.
   *
   * @param string $root
   *   The project.
   *
   * @return \Droost\Workflow\State\RunState|null
   *   The run, or NULL when there is none.
   *
   * @phpstan-impure
   */
  private function stateOf(string $root): ?RunState {
    return (new RunStateStore($root))->load();
  }

}
