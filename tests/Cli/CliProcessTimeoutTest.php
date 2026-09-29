<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Cli;

use Droost\Workflow\Cli\CliProcess;
use PHPUnit\Framework\TestCase;

/**
 * A timeout kills the gate's whole process tree (F-176).
 *
 * `proc_terminate()` signalled only the direct child, the `/bin/sh -c` of a
 * custom gate, and a grandchild kept running. For a suite killed at its
 * budget that left the suite running, and the one in-place retry started a
 * second beside it.
 */
final class CliProcessTimeoutTest extends TestCase {

  /**
   * A grandchild does not outlive the timeout.
   */
  public function testGrandchildrenDieWithTheGate(): void {
    $marker = sys_get_temp_dir() . '/cli-timeout-' . bin2hex(random_bytes(4));
    [$exit, , $stderr] = CliProcess::run(
      ['/bin/sh', '-c', 'sleep 37 & echo $! > ' . escapeshellarg($marker) . '; wait'],
      sys_get_temp_dir(),
      1,
    );

    $this->assertSame(124, $exit);
    $this->assertStringContainsString('killed after 1s', $stderr);
    $grandchild = (int) trim((string) @file_get_contents($marker));
    @unlink($marker);
    $this->assertGreaterThan(0, $grandchild, 'the script ran and recorded its child');
    usleep(100_000);
    $this->assertFalse(posix_kill($grandchild, 0), 'the grandchild was killed with its parent');
  }

}
