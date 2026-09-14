<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use PHPUnit\Framework\TestCase;

/**
 * A `with…()` on a result carries every field, not the eleven it remembered.
 *
 * `withBaselineCounts()` rebuilt the result from eleven positional
 * constructor arguments and stopped, so a baseline-partitioned verdict lost
 * its `remedy`, its `labelledPass` and its `declaredFault` on the way out.
 * Every project with an adopted baseline got NULL for any field added below
 * the eleventh — the exact "one field, N rebuild sites, the inline ones drop
 * it" trap the run record's own docblock warns about, arriving in the value
 * object beside it. The constructor is now called with the whole field list
 * in one private place, and every `with…()` goes through it.
 */
final class GateResultCarriesEveryFieldTest extends TestCase {

  /**
   * The baseline partition keeps the remedy the executor attached.
   */
  public function testBaselineCountsKeepTheRemedy(): void {
    $ran = GateResult::ran('phpstan', GateStatus::Failed, 1, 900, '3 errors', [], 'vendor/bin/phpstan analyse', 'install the extension');

    $partitioned = $ran->withBaselineCounts(2, 1);

    $this->assertSame(2, $partitioned->inherited);
    $this->assertSame(1, $partitioned->new);
    $this->assertSame('install the extension', $partitioned->remedy, 'the remedy survives the partition');
    $this->assertSame('vendor/bin/phpstan analyse', $partitioned->invocation);
  }

  /**
   * The subjects, the partition and the output all survive each other.
   */
  public function testEveryWithKeepsEveryOtherField(): void {
    $result = GateResult::ran('phpcs', GateStatus::Passed, 0, 120, 'clean', [], 'vendor/bin/phpcs -q .', 'n/a')
      ->withOutput('stdout text', 'stderr text')
      ->withSubjects(['src', 'tests'])
      ->withBaselineCounts(4, 0);

    $this->assertSame(['src', 'tests'], $result->subjects);
    $this->assertSame(4, $result->inherited);
    $this->assertSame('n/a', $result->remedy);
    $this->assertSame('stdout text', $result->stdout, 'the output taken before is still there after');
    $this->assertSame('stderr text', $result->stderr);
    $this->assertSame('vendor/bin/phpcs -q .', $result->invocation);
  }

}
