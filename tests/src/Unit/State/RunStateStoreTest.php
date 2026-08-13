<?php

declare(strict_types=1);

namespace Drupal\Tests\droost_workflow\Unit\State;

use Drupal\Tests\droost_workflow\Unit\WorkflowTestCase;
use Drupal\droost_workflow\Config\Mode;
use Drupal\droost_workflow\Config\Phase;
use Drupal\droost_workflow\Config\Provenance;
use Drupal\droost_workflow\Config\WorkflowConfig;
use Drupal\droost_workflow\State\PhaseStatus;
use Drupal\droost_workflow\State\RunState;
use Drupal\droost_workflow\State\RunStateStore;
use Drupal\droost_workflow\State\StateError;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Persisting a run, and refusing to destroy evidence.
 */
class RunStateStoreTest extends WorkflowTestCase {

  /**
   * REQ-002: a run survives a save and comes back identical.
   *
   * Asserted on the serialized document rather than "no exception" — the
   * absence of exactly this assertion is what let the package ship unable to
   * read state it had just written.
   *
   * @param string $shape
   *   Which run to build.
   */
  #[DataProvider('roundTripShapes')]
  public function testRunsRoundTripIdentically(string $shape): void {
    $root = $this->makeRoot();
    $store = new RunStateStore($root);
    $state = $this->buildRun($shape);

    $store->save($state);
    $loaded = $store->load();

    $this->assertNotNull($loaded);
    $this->assertSame($state->toArray(), $loaded->toArray());
    $this->assertSame([], $this->tempResidue($root));
  }

  /**
   * Run shapes whose fields are all nullable or all populated.
   *
   * @return array<string, array{string}>
   *   Case name to shape key.
   */
  public static function roundTripShapes(): array {
    return [
      'freshly begun' => ['fresh'],
      'with a mode override' => ['override'],
      'mid-advance' => ['advanced'],
      'ended, no current phase' => ['ended'],
      'all reserved fields populated' => ['reserved'],
    ];
  }

  /**
   * Every field the writer can emit as null survives a reload.
   *
   * Table-driven off toArray()'s own keys so a new nullable field cannot be
   * added without this failing.
   */
  public function testEveryNullableFieldSurvivesReload(): void {
    $root = $this->makeRoot();
    $store = new RunStateStore($root);
    $state = $this->buildRun('fresh');
    $document = $state->toArray();

    $nullable = array_keys(array_filter(
      $document,
      static fn (mixed $value): bool => $value === NULL,
    ));
    $this->assertNotSame([], $nullable, 'Expected some nullable fields.');

    $store->save($state);
    $loaded = $store->load();
    $this->assertNotNull($loaded);

    foreach ($nullable as $key) {
      $this->assertArrayHasKey($key, $loaded->toArray());
      $this->assertNull($loaded->toArray()[$key], $key . ' did not survive');
    }
  }

  /**
   * REQ-002: a genuinely separate process resumes where the last one stopped.
   */
  public function testSeparateProcessResumesAtPersistedPhase(): void {
    $root = $this->makeRoot();
    $store = new RunStateStore($root);
    $store->advance($this->buildRun('fresh'), Phase::Code);

    $resumed = (new RunStateStore($root))->load();

    $this->assertNotNull($resumed);
    $this->assertSame(Phase::Code, $resumed->currentPhase);
    $this->assertSame(PhaseStatus::Passed, $resumed->statusOf(Phase::Plan));
    $this->assertSame(PhaseStatus::Active, $resumed->statusOf(Phase::Code));
  }

  /**
   * Gate lever types survive the JSON round trip unchanged.
   */
  public function testGateLeverTypesAreNotCoerced(): void {
    $root = $this->makeRoot();
    $store = new RunStateStore($root);
    $store->save($this->buildRun('fresh'));

    $loaded = $store->load();
    $this->assertNotNull($loaded);
    $gates = $loaded->resolvedGates;

    $this->assertSame('max', $gates['phpstan']['level']);
    $this->assertSame(80, $gates['coverage']['min']);
    $this->assertTrue($gates['phpcs']['on']);
  }

  /**
   * No state file means no run, not an error.
   */
  public function testAbsentStateIsNotAnError(): void {
    $this->assertNull((new RunStateStore($this->makeRoot()))->load());
  }

  /**
   * REQ-006: a state file it cannot understand is never destroyed.
   *
   * @param string $contents
   *   What to put in run.json.
   * @param string $expected
   *   Text the message must contain.
   */
  #[DataProvider('corruptStateCases')]
  public function testCorruptStateIsRefusedAndPreserved(
    string $contents,
    string $expected,
  ): void {
    $root = $this->makeRoot();
    $store = new RunStateStore($root);
    mkdir($root . '/.droost-workflow', 0755, TRUE);
    file_put_contents($store->path(), $contents);
    $before = hash_file('sha256', $store->path());

    try {
      $store->load();
      $this->fail('Expected a StateError.');
    }
    catch (StateError $e) {
      $this->assertStringContainsString($expected, $e->getMessage());
    }

    $this->assertSame($before, hash_file('sha256', $store->path()));
    $this->assertSame([], $this->tempResidue($root));
  }

  /**
   * Every way a state file can be unusable.
   *
   * @return array<string, array{string, string}>
   *   Case name to contents and expected message fragment.
   */
  public static function corruptStateCases(): array {
    $valid = static function (array $overrides): string {
      $base = [
        'v' => 1,
        'run_id' => 'r',
        'started_at' => 't',
        'mode' => 'automated',
        'mode_override' => NULL,
        'preset' => 'factory',
        'max_gate_retries' => 2,
        'provenance' => 'built-in',
        'resolved_gates' => [],
        'phases' => ['plan' => 'active'],
        'current_phase' => 'plan',
        'gate_results' => [],
        'awaiting' => NULL,
        'qa_history' => [],
        'feedback_attempts' => [],
      ];
      $encoded = json_encode(array_merge($base, $overrides));
      return $encoded === FALSE ? '{}' : $encoded;
    };

    return [
      'not json' => ['{ this is not json', 'invalid JSON'],
      'a bare list' => ['[1,2,3]', 'must contain an object'],
      'a bare scalar' => ['42', 'must contain an object'],
      'no version' => ['{"run_id":"r"}', 'no schema version'],
      'a future version' => [$valid(['v' => 99]), 'schema v99 is not supported'],
      'a stringy version' => [$valid(['v' => '1']), 'v must be an integer'],
      'unknown mode' => [$valid(['mode' => 'banana']), 'unknown mode "banana"'],
      'unknown override' => [
        $valid(['mode_override' => 'banana']),
        'unknown mode_override "banana"',
      ],
      'unknown current phase' => [
        $valid(['current_phase' => 'nope']),
        'unknown current_phase "nope"',
      ],
      'unknown phase key' => [
        $valid(['phases' => ['plna' => 'active'], 'current_phase' => NULL]),
        'unknown phase "plna"',
      ],
      'unknown status word' => [
        $valid(['phases' => ['plan' => 'wobbly']]),
        'unknown status "wobbly"',
      ],
      'unknown preset' => [
        $valid(['preset' => 'turbo']),
        'unknown preset "turbo"',
      ],
      'unknown gate' => [
        $valid(['resolved_gates' => ['bogus' => ['on' => TRUE]]]),
        'unknown gate "bogus"',
      ],
      'qa_history as a mapping' => [
        $valid(['qa_history' => ['k' => ['q' => 'a']]]),
        'qa_history must be a list',
      ],
      'wrong field type' => [
        $valid(['run_id' => 12]),
        'run_id must be a string',
      ],
      'unknown phase in phase_gates' => [
        $valid(['phase_gates' => ['plna' => []]]),
        'unknown phase "plna" in phase_gates',
      ],
      'unknown gate in phase_gates' => [
        $valid(['phase_gates' => ['plan' => ['bogus']]]),
        'unknown gate "bogus" in phase_gates',
      ],
      'phase_gates entry as a mapping' => [
        $valid(['phase_gates' => ['plan' => ['phpcs' => TRUE]]]),
        'phase_gates.plan must be a list',
      ],
    ];
  }

  /**
   * A document from before the phase map existed synthesizes the default.
   *
   * Those runs WERE executing under "the engine decides", so the engine
   * default is the only honest reconstruction — and without it every pre-map
   * run.json would become unreadable, which is a migration this schema
   * promised not to need.
   */
  public function testAbsentPhaseGatesSynthesizeTheDefaultMap(): void {
    $root = $this->makeRoot();
    $store = new RunStateStore($root);
    mkdir($root . '/.droost-workflow', 0755, TRUE);
    $document = [
      'v' => 1,
      'run_id' => 'r',
      'started_at' => 't',
      'mode' => 'automated',
      'mode_override' => NULL,
      'preset' => 'factory',
      'max_gate_retries' => 2,
      'provenance' => 'built-in',
      'resolved_gates' => [],
      'phases' => ['plan' => 'passed', 'code' => 'active'],
      'current_phase' => 'code',
      'gate_results' => [],
      'awaiting' => NULL,
      'qa_history' => [],
      'feedback_attempts' => [],
    ];
    file_put_contents($store->path(), json_encode($document));

    $loaded = $store->load();

    $this->assertNotNull($loaded);
    $this->assertSame(
      ['plan' => [], 'code' => ['phpcs', 'phpstan']],
      $loaded->phaseGates,
    );
  }

  /**
   * A present phase_gates field is the run's record, never second-guessed.
   *
   * Only genuine absence synthesizes; an explicitly empty map stays empty,
   * for the same reason an edited lever file does not retarget a running
   * run.
   */
  public function testPresentPhaseGatesAreNeverSynthesized(): void {
    $root = $this->makeRoot();
    $store = new RunStateStore($root);
    $ended = $this->buildRun('ended');
    $this->assertSame([], $ended->phaseGates);

    $store->save($ended);
    $loaded = $store->load();

    $this->assertNotNull($loaded);
    $this->assertSame([], $loaded->phaseGates);
  }

  /**
   * A write that cannot happen leaves the previous state exactly as it was.
   */
  public function testFailedWriteLeavesPriorStateIntact(): void {
    $root = $this->makeRoot();
    $store = new RunStateStore($root);
    $store->save($this->buildRun('fresh'));
    $before = hash_file('sha256', $store->path());

    chmod($store->directory(), 0555);
    if (is_writable($store->directory())) {
      chmod($store->directory(), 0755);
      $this->markTestSkipped('Running as a user that ignores directory modes.');
    }

    try {
      $store->save($this->buildRun('advanced'));
      $this->fail('Expected a StateError.');
    }
    catch (StateError $e) {
      $this->assertStringContainsString('not writable', $e->getMessage());
    }
    finally {
      chmod($store->directory(), 0755);
    }

    $this->assertSame($before, hash_file('sha256', $store->path()));
    $this->assertSame([], $this->tempResidue($root));
  }

  /**
   * Run state may not be written outside the project through a symlink.
   */
  public function testSymlinkedStateDirectoryIsRefused(): void {
    $root = $this->makeRoot();
    $outside = $this->makeRoot();
    symlink($outside, $root . '/.droost-workflow');

    $this->expectException(StateError::class);
    $this->expectExceptionMessage('is a symlink');
    (new RunStateStore($root))->save($this->buildRun('fresh'));
  }

  /**
   * The state directory is not world-writable, whatever the umask.
   */
  public function testTheStateDirectoryIsNotWorldWritable(): void {
    $root = $this->makeRoot();
    $previous = umask(0);
    try {
      $store = new RunStateStore($root);
      $store->save($this->buildRun('fresh'));
      $mode = fileperms($store->directory()) & 0777;
    }
    finally {
      umask($previous);
    }

    $this->assertSame(0, $mode & 0002, sprintf(
      'State directory is world-writable (mode %o).',
      $mode,
    ));
  }

  /**
   * A project root that is not a usable directory is a caller error.
   *
   * @param string $root
   *   The candidate root.
   */
  #[DataProvider('badRoots')]
  public function testBadProjectRootsAreRefused(string $root): void {
    $this->expectException(\InvalidArgumentException::class);
    new RunStateStore($root);
  }

  /**
   * Roots that must never be accepted.
   *
   * @return array<string, array{string}>
   *   Case name to root.
   */
  public static function badRoots(): array {
    return [
      'empty' => [''],
      'whitespace' => ['   '],
      'filesystem root' => ['/'],
      'doubled slash' => ['//'],
      'nonexistent' => ['/no/such/path/at/all'],
    ];
  }

  /**
   * Builds one of the run shapes used across this test.
   *
   * @param string $shape
   *   The shape key.
   *
   * @return \Drupal\droost_workflow\State\RunState
   *   The run.
   */
  private function buildRun(string $shape): RunState {
    $config = WorkflowConfig::builtIn();
    $fresh = RunState::begin('run-1', '2026-07-27T09:00:00+00:00', $config);

    return match ($shape) {
      'fresh' => $fresh,
      'override' => $fresh->withModeOverride(Mode::Pair),
      'advanced' => $fresh->advanceTo(Phase::Code),
      'ended' => new RunState(
        'run-1',
        '2026-07-27T09:00:00+00:00',
        Mode::Automated,
        NULL,
        'factory',
        2,
        Provenance::BuiltIn,
        $config->resolvedGates(),
        ['plan' => PhaseStatus::Passed, 'complete' => PhaseStatus::Passed],
        NULL,
      ),
      'reserved' => new RunState(
        'run-1',
        '2026-07-27T09:00:00+00:00',
        Mode::Pair,
        Mode::Automated,
        'factory',
        2,
        Provenance::File,
        $config->resolvedGates(),
        ['plan' => PhaseStatus::Passed, 'code' => PhaseStatus::Active],
        Phase::Code,
        [
          'phpcs' => [
            'status' => 'passed',
            'ms' => 12,
            'ratio' => 0.5,
            'findings' => [['line' => 3, 'note' => NULL]],
          ],
        ],
        ['phase' => 'code', 'question' => 'Ship it?'],
        [['q' => 'Ship it?', 'a' => 'yes', 'at' => NULL]],
        ['phpcs' => 2, 'phpstan' => 0],
      ),
      default => $this->fail('Unknown shape: ' . $shape),
    };
  }

}
