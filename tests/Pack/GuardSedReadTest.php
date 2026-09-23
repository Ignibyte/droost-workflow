<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A `sed` that edits nothing reads, as `cat` and `head` do (F-83).
 *
 * P6 run 6's researcher printed a slice of the run record with
 * `sed -n 60,200p droost/droost-workflow/run.json` and was refused as though
 * it had edited the record, while `cat` and `head` over the same file pass.
 * `sed` writes the files it is given only in place, and its program writes
 * only through `w`, which the guard reads as code already.
 */
final class GuardSedReadTest extends WorkflowTestCase {

  /**
   * Printing from the record is reading it.
   */
  public function testSedThatEditsNothingReadsTheRecord(): void {
    foreach ([
      'sed -n 60,200p droost/droost-workflow/run.json',
      "sed -n '/phase/p' droost/droost-workflow/run.json",
      "sed 's/plan/code/' droost/droost-workflow/run.json",
    ] as $command) {
      [$exit, , $stderr] = $this->guard($this->makeRoot(), 'operator-commands', ['tool_input' => ['command' => $command]]);
      $this->assertSame(0, $exit, $command . ' reads the record: ' . $stderr);
    }
  }

  /**
   * Editing it in place, or writing through the program, is still refused.
   */
  public function testSedThatEditsIsStillRefused(): void {
    foreach ([
      "sed -i 's/plan/code/' droost/droost-workflow/run.json",
      "sed -i.bak 's/plan/code/' droost/droost-workflow/run.json",
      "sed -ni 's/plan/code/p' droost/droost-workflow/run.json",
      "sed --in-place 's/plan/code/' droost/droost-workflow/run.json",
      "sed -n 'w droost/droost-workflow/run.json' README.md",
    ] as $command) {
      [$exit] = $this->guard($this->makeRoot(), 'operator-commands', ['tool_input' => ['command' => $command]]);
      $this->assertSame(2, $exit, $command . ' writes the record');
    }
  }

}
