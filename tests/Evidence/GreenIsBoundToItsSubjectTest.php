<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Evidence\EvidenceRecorder;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\SubjectHasher;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\PhaseReport;
use Droost\Workflow\State\RunState;
use PHPUnit\Framework\TestCase;

/**
 * A green is bound to the code it was green about, lever or no lever.
 *
 * The recorder hashed the gate's `paths` lever and nothing else. The default
 * levers for the mandatory trio carry none, so on a stock project every gate
 * was recorded with `subject_hash: NULL`, `stillGreen()` had nothing to
 * compare, and the evidence document's "Still true?" column read `unknown`
 * for every verdict — the one mechanism that makes a green expire, switched
 * off by default. The executor now records what it handed the tool, and the
 * recorder hashes that when the lever is silent.
 */
final class GreenIsBoundToItsSubjectTest extends TestCase {

  /**
   * A scratch project root.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->root = sys_get_temp_dir() . '/droost-bound-' . bin2hex(random_bytes(6));
    mkdir($this->root . '/droost/droost-workflow', 0775, TRUE);
    mkdir($this->root . '/src', 0775, TRUE);
    mkdir($this->root . '/tests', 0775, TRUE);
    file_put_contents($this->root . '/src/Money.php', "<?php\nfunction ok(): bool { return TRUE; }\n");
    file_put_contents($this->root . '/tests/MoneyTest.php', "<?php\n");
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    exec('rm -rf ' . escapeshellarg($this->root));
  }

  /**
   * With no lever, the subject the executor recorded is what is hashed.
   */
  public function testTheRecordedSubjectIsHashedWhenTheLeverIsSilent(): void {
    $config = WorkflowConfig::fromArray(['mode' => 'agentic', 'preset' => 'medium'], 'test');
    $state = RunState::begin('r1', '2026-09-14T00:00:00+00:00', $config);
    $green = GateResult::ran('phpstan', GateStatus::Passed, 0, 800, 'clean', [], 'vendor/bin/phpstan analyse src')
      ->withSubjects(['src']);

    (new EvidenceRecorder($this->root))->recordPhase($state, 'code', new PhaseReport(Phase::Code, [$green]));

    $row = $this->rowFor('phpstan');
    $this->assertSame(
      SubjectHasher::hash($this->root, ['src']),
      $row['subject_hash'],
      'the green is bound to the code it was green about',
    );

    // And the binding means something: move the code, and the verdict no
    // longer describes it.
    file_put_contents($this->root . '/src/Money.php', "<?php\nfunction ok(): bool { return FALSE; }\n");
    $this->assertNotSame(
      $row['subject_hash'],
      SubjectHasher::hash($this->root, ['src']),
      'so a same-length rewrite expires it',
    );
  }

  /**
   * A lever the operator set still outranks what the executor recorded.
   */
  public function testTheLeverOutranksTheRecordedSubject(): void {
    $config = WorkflowConfig::fromArray([
      'mode' => 'agentic',
      'preset' => 'medium',
      'gates' => ['phpstan' => ['paths' => 'tests']],
    ], 'test');
    $state = RunState::begin('r1', '2026-09-14T00:00:00+00:00', $config);
    $green = GateResult::ran('phpstan', GateStatus::Passed, 0, 800, 'clean', [], 'vendor/bin/phpstan analyse tests')
      ->withSubjects(['src']);

    (new EvidenceRecorder($this->root))->recordPhase($state, 'code', new PhaseReport(Phase::Code, [$green]));

    $this->assertSame(
      SubjectHasher::hash($this->root, ['tests']),
      $this->rowFor('phpstan')['subject_hash'],
      'the operator\'s lever is the subject when there is one',
    );
  }

  /**
   * A gate with neither records no subject, and says nothing false.
   */
  public function testNoSubjectAnywhereIsRecordedAsNone(): void {
    $config = WorkflowConfig::fromArray(['mode' => 'agentic', 'preset' => 'medium'], 'test');
    $state = RunState::begin('r1', '2026-09-14T00:00:00+00:00', $config);
    $green = GateResult::ran('phpstan', GateStatus::Passed, 0, 800, 'clean', [], 'vendor/bin/phpstan analyse');

    (new EvidenceRecorder($this->root))->recordPhase($state, 'code', new PhaseReport(Phase::Code, [$green]));

    $this->assertNull($this->rowFor('phpstan')['subject_hash'], 'unknown is unknown, not a guess');
  }

  /**
   * The newest check row for a gate.
   *
   * @param string $gate
   *   The gate name.
   *
   * @return array<string, mixed>
   *   The row.
   */
  private function rowFor(string $gate): array {
    foreach ((new EvidenceStore($this->root))->checklist('r1', 'code') as $row) {
      if (($row['name'] ?? NULL) === $gate) {
        return $row;
      }
    }
    $this->fail('no row for ' . $gate);
  }

}
