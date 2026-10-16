<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Event;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Event\RunEvent;
use Droost\Workflow\Event\RunEventLog;
use Droost\Workflow\Evidence\CheckAdjudicatorInterface;
use Droost\Workflow\Evidence\CheckAdjudicators;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * The engine writes one event per persisted change, in order, to the schema.
 *
 * Driven through the facade, as every surface drives it: an interactive run
 * from plan to complete, with a gate that fails once and a question at every
 * phase. Every line is checked against `schema/run-event.v1.json`, read from
 * the file, with no JSON Schema library: the envelope, and each type's
 * required payload keys and their types.
 */
final class RunEventLogFacadeTest extends WorkflowTestCase {

  /**
   * The whole run, event by event.
   */
  public function testRunWritesItsEventsInOrder(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: interactive\nseekers:\n  on: false\n");
    $facade = $this->facade();

    for ($i = 0; $i < 24; $i++) {
      $outcome = $facade->run($root);
      if ($outcome->outcome === Outcome::Completed) {
        break;
      }
      if ($outcome->outcome === Outcome::Paused && $facade->answer($root, 'continue')->state->currentPhase === NULL) {
        break;
      }
    }
    $state = $this->lastRun($root);
    $this->assertNotNull($state);
    $this->assertNull($state->currentPhase, 'the run reached its end');

    $events = iterator_to_array((new RunEventLog((new RunStateStore($root))->directory()))->read(), FALSE);
    $types = array_map(
      static fn (RunEvent $e): string => $e->type . (is_string($e->payload['phase'] ?? NULL) ? ':' . $e->payload['phase'] : ''),
      $events,
    );
    $this->assertSame([
      'run.started',
      'phase.began:plan',
      'phase.attempted:plan',
      'question.asked',
      'question.answered',
      'phase.ended:plan',
      'phase.began:code',
      'phase.attempted:code',
      'phase.attempted:code',
      'question.asked',
      'question.answered',
      'phase.ended:code',
      'phase.began:test',
      'phase.attempted:test',
      'question.asked',
      'question.answered',
      'phase.ended:test',
      'phase.began:complete',
      'phase.attempted:complete',
      'question.asked',
      'question.answered',
      'phase.ended:complete',
      'run.completed',
      'run.reset',
    ], $types, 'one event per persisted change, in the order the engine made them');

    // The failed attempt is the only event that shows the failed gate.
    $code = array_values(array_filter($events, static fn (RunEvent $e): bool => $e->type === 'phase.attempted' && ($e->payload['phase'] ?? NULL) === 'code'));
    $this->assertSame([1, 2], array_map(static fn (RunEvent $e): mixed => $e->payload['attempt'], $code));
    $this->assertIsArray($code[0]->payload['report']);
    $this->assertIsArray($code[0]->payload['report']['tally']);
    $this->assertGreaterThan(0, $code[0]->payload['report']['tally']['failed'] ?? 0, 'the first code attempt carries the failed gate');
    $this->assertIsArray($code[1]->payload['report']);
    $this->assertIsArray($code[1]->payload['report']['tally']);
    $this->assertSame(0, $code[1]->payload['report']['tally']['failed'] ?? NULL);

    // Every question is answered under the id it was asked with.
    $asked = array_values(array_filter($events, static fn (RunEvent $e): bool => $e->type === 'question.asked'));
    $answered = array_values(array_filter($events, static fn (RunEvent $e): bool => $e->type === 'question.answered'));
    $this->assertSame(array_column(array_map(static fn (RunEvent $e): array => $e->payload, $asked), 'question_id'), array_column(array_map(static fn (RunEvent $e): array => $e->payload, $answered), 'question_id'));
    $this->assertSame('continue', $answered[0]->payload['answer']);

    // One schema, one envelope, and every line valid against the file.
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schema/run-event.v1.json'), TRUE);
    $this->assertIsArray($schema);
    $this->assertSame(range(1, count($events)), array_map(static fn (RunEvent $e): int => $e->seq, $events));
    $this->assertCount(count($events), array_unique(array_map(static fn (RunEvent $e): string => $e->eventId, $events)));
    foreach ((new RunEventLog((new RunStateStore($root))->directory()))->lines() as $line) {
      $this->assertValidAgainstTheSchema(json_decode($line, TRUE), $schema);
    }
    $this->assertSame($state->runId, $events[0]->runId);
    $this->assertNull($events[0]->workItemId);
    $this->assertSame('interactive', $events[0]->payload['mode']);
  }

  /**
   * A plan a check held is not reported as advancing (F-296).
   *
   * The gate report said `advance: true` with no gates while plan_grounded
   * blocked, and the event carried nothing else.
   */
  public function testHeldPlanSaysSo(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\nseekers:\n  on: false\n");
    // Droost is here and was never asked, so plan_grounded holds the plan.
    mkdir($root . '/vendor/bin', 0755, TRUE);
    file_put_contents($root . '/vendor/bin/droost', "#!/usr/bin/env php\n");
    $outcome = $this->facade(CheckAdjudicators::withLibraryChecks())->run($root);
    $this->assertSame(Outcome::Blocked, $outcome->outcome);

    $events = iterator_to_array((new RunEventLog((new RunStateStore($root))->directory()))->read(), FALSE);
    $attempts = array_values(array_filter($events, static fn (RunEvent $e): bool => $e->type === 'phase.attempted'));
    $this->assertCount(1, $attempts);
    $payload = $attempts[0]->payload;
    $this->assertSame('blocked', $payload['outcome'] ?? NULL);
    $this->assertIsArray($payload['report']);
    $this->assertFalse($payload['report']['advance'] ?? NULL, 'a held phase does not advance');
    $this->assertIsArray($payload['blocked']);
    $this->assertContains('plan_grounded', array_column($payload['blocked'], 'check'));
  }

  /**
   * A ticket declared with declare-changes --work-item is on every later event.
   */
  public function testDeclaredTicketNamesTheEvents(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\nseekers:\n  on: false\n");
    $facade = $this->facade();
    $facade->run($root);
    mkdir($root . '/src', 0755, TRUE);
    file_put_contents($root . '/src/A.php', "<?php\n");
    $facade->declareChanges($root, ['src/A.php'], [], 'code', 'TICKET-1');
    $facade->run($root);

    $events = iterator_to_array((new RunEventLog((new RunStateStore($root))->directory()))->read(), FALSE);
    $last = end($events);
    $this->assertInstanceOf(RunEvent::class, $last);
    $this->assertSame('TICKET-1', $last->workItemId);
    $this->assertNull($events[0]->workItemId, 'and none before it was declared');
  }

  /**
   * Reset archives the run and keeps the log, and says so in it.
   *
   * A completed run is reset the moment it completes (0.14.18), so the
   * reset here is that one: right after `run.completed`.
   */
  public function testResetKeepsTheLog(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\nseekers:\n  on: false\n");
    $facade = $this->facade();
    for ($i = 0; $i < 12 && $facade->run($root)->outcome !== Outcome::Completed; $i++) {
      // Driven to completion.
    }
    $log = new RunEventLog((new RunStateStore($root))->directory());
    $state = $this->lastRun($root);
    $this->assertNotNull($state);
    $this->assertNull((new RunStateStore($root))->load(), 'the completed run was archived');

    $after = iterator_to_array($log->read(), FALSE);
    $types = array_map(static fn (RunEvent $e): string => $e->type, $after);
    $this->assertSame(['run.completed', 'run.reset'], array_slice($types, -2), 'every earlier event is still there, then the reset');
    $last = end($after);
    $this->assertInstanceOf(RunEvent::class, $last);
    $this->assertSame('run.reset', $last->type);
    $this->assertSame(['archived_run_id' => $state->runId], $last->payload);
    $this->assertFileDoesNotExist((new RunStateStore($root))->directory() . '/history/' . $state->runId . '.' . RunEventLog::FILENAME);
  }

  /**
   * The log lives in the state directory `init` keeps out of version control.
   */
  public function testTheLogIsIgnoredAsTheEvidenceStoreIs(): void {
    $root = $this->makeRoot();
    $dispatcher = new ArgvDispatcher(static function (string $line): void {}, static function (string $line): void {}, static fn (): string => 't', static fn (): string => 'run-x');
    $this->assertSame(ArgvDispatcher::EXIT_OK, $dispatcher->dispatch(['init'], $root));
    $stateDir = RunStateStore::resolveStateDir($root);
    $ignored = file($root . '/.gitignore', FILE_IGNORE_NEW_LINES) ?: [];
    $this->assertContains($stateDir . '/', $ignored);
    $this->assertStringStartsWith(
      $root . '/' . $stateDir . '/',
      (new RunEventLog((new RunStateStore($root))->directory()))->path(),
      'events.jsonl sits beside evidence.sqlite, under the ignored state directory',
    );
  }

  /**
   * Checks one event against the schema file, as a consumer's validator would.
   *
   * @param mixed $event
   *   The decoded line.
   * @param array<array-key, mixed> $schema
   *   The decoded schema.
   */
  private function assertValidAgainstTheSchema(mixed $event, array $schema): void {
    $this->assertIsArray($event);
    $this->assertIsArray($schema['required']);
    $this->assertIsArray($schema['properties']);
    $this->assertValue($event, $schema, 'event');
    $type = $event['type'];
    $this->assertIsString($type);
    $this->assertIsArray($schema['$defs']);
    $this->assertArrayHasKey($type, $schema['$defs'], 'the schema describes ' . $type);
    $definition = $schema['$defs'][$type];
    $this->assertIsArray($definition);
    $this->assertValue($event['payload'], $definition, $type . ' payload');
  }

  /**
   * Checks a value against a (sub)schema's type, const, pattern and keys.
   *
   * @param mixed $value
   *   The value.
   * @param array<array-key, mixed> $schema
   *   The (sub)schema.
   * @param string $where
   *   Where the value is, for the message.
   */
  private function assertValue(mixed $value, array $schema, string $where): void {
    if (array_key_exists('const', $schema)) {
      $this->assertSame($schema['const'], $value, $where);
    }
    if (isset($schema['type'])) {
      $allowed = (array) $schema['type'];
      $actual = match (TRUE) {
        $value === NULL => 'null',
        is_bool($value) => 'boolean',
        is_int($value) => 'integer',
        is_string($value) => 'string',
        // JSON's `[]` is either; PHP decodes both to the same empty array.
        $value === [] => in_array('array', $allowed, TRUE) ? 'array' : 'object',
        is_array($value) && array_is_list($value) => 'array',
        is_array($value) => 'object',
        default => gettype($value),
      };
      $this->assertContains($actual, $allowed, sprintf('%s is %s, the schema says %s', $where, $actual, implode('|', array_map(static fn (mixed $t): string => is_scalar($t) ? (string) $t : gettype($t), $allowed))));
    }
    if (isset($schema['pattern']) && is_string($value) && is_string($schema['pattern'])) {
      $this->assertMatchesRegularExpression('/' . $schema['pattern'] . '/', $value, $where);
    }
    if (isset($schema['minimum']) && is_int($value)) {
      $this->assertGreaterThanOrEqual($schema['minimum'], $value, $where);
    }
    if (is_array($value) && isset($schema['required']) && is_array($schema['required'])) {
      foreach ($schema['required'] as $key) {
        $this->assertIsString($key);
        $this->assertArrayHasKey($key, $value, $where . ' has ' . $key);
      }
    }
    if (is_array($value) && isset($schema['properties']) && is_array($schema['properties'])) {
      foreach ($schema['properties'] as $key => $child) {
        if (array_key_exists($key, $value) && is_array($child)) {
          $this->assertValue($value[$key], $child, $where . '.' . $key);
        }
      }
    }
  }

  /**
   * A facade whose phpcs fails its first run at code and passes after.
   *
   * @param \Droost\Workflow\Evidence\CheckAdjudicatorInterface|null $checks
   *   The checks it asks, or none.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(?CheckAdjudicatorInterface $checks = NULL): WorkflowFacade {
    $executor = new class() implements GateExecutorInterface {

      /**
       * Whether phpcs has failed its one time.
       */
      private bool $failed = FALSE;

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        $record = json_decode((string) @file_get_contents($projectRoot . '/droost/droost-workflow/run.json'), TRUE);
        $atCode = is_array($record) && ($record['current_phase'] ?? NULL) === 'code';
        if ($gate->name === 'phpcs' && $atCode && !$this->failed) {
          $this->failed = TRUE;
          return new GateResult($gate->name, GateStatus::Failed, 1, 1, 'a line too long');
        }
        return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };

    return new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-28T12:00:00+00:00',
      static fn (): string => 'run-events-log',
      checks: $checks,
    );
  }

}
