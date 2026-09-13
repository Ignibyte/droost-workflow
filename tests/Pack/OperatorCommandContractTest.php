<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use PHPUnit\Framework\TestCase;

/**
 * The README's list of operator-only commands is the guard's list.
 *
 * These two drifted, and the drift had a reader. The guard refuses five
 * commands from the agent's shell; the README named four, omitting the arming
 * of a write gate — which is the one droost's own documentation spends the most
 * words on. So the shipped instructions walked an agent into a refusal the
 * documentation gave it no way to anticipate, and the refusal is deliberately
 * terse because it is meant to be the end of an argument, not the start of one.
 *
 * Nothing lints prose. This does, for the one paragraph where being wrong costs
 * an agent a wall it cannot see.
 */
final class OperatorCommandContractTest extends TestCase {

  /**
   * The guard's refusal branches, by the label each one sets.
   *
   * Read out of the source rather than listed here, so adding a sixth refusal
   * without documenting it fails rather than passes.
   *
   * @return list<string>
   *   The labels.
   */
  private function refusedByTheGuard(): array {
    $source = (string) file_get_contents(
      dirname(__DIR__, 2) . '/pack/hooks/droost-workflow-guard.php',
    );
    $body = strstr($source, 'function operator_commands_guard');
    $this->assertIsString($body, 'the guard still has its operator-command mode');
    // Only the branches inside that function, which ends at its closing brace
    // in column one. Anchored rather than trusting the whole tail of the file:
    // a later function that happened to use the same variable name would
    // silently enlarge this list, and a sync test that over-counts is a sync
    // test that stops failing for the right reason.
    $end = strpos($body, "\n}\n");
    $this->assertIsInt($end, 'the function has a closing brace');
    $body = substr($body, 0, $end);
    preg_match_all('/\$which = \'([^\']+)\'/', $body, $matches);

    return array_values(array_unique($matches[1]));
  }

  /**
   * Every command the guard refuses is named in the README's table.
   */
  public function testTheReadmeNamesEveryRefusal(): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
    $table = strstr($readme, '| Refused from the agent\'s shell |');
    $this->assertIsString($table, 'the README still carries the table');
    $table = substr($table, 0, (int) strpos($table, "\n\n"));

    // The label the guard sets, mapped to the words the README must use. A
    // mapping rather than a substring match, because "gate (arming a write
    // gate)" is a diagnostic string and the README is prose for a human.
    $expected = [
      'gate-waive' => 'gate-waive',
      'baseline' => 'baseline',
      'bypass' => 'bypass',
      'effort' => 'effort',
      'gate (arming a write gate)' => 'allow_',
    ];

    foreach ($this->refusedByTheGuard() as $label) {
      $this->assertArrayHasKey(
        $label,
        $expected,
        sprintf(
          'The guard refuses "%s" and this test has never heard of it. Add it '
          . 'to the README table and to $expected — a refusal the docs do not '
          . 'mention is a wall an agent cannot see coming.',
          $label,
        ),
      );
      $this->assertStringContainsString(
        $expected[$label],
        $table,
        sprintf('the README table names the "%s" refusal', $label),
      );
    }
  }

  /**
   * And the table claims no refusal the guard does not make.
   *
   * The other direction matters as much: a README that forbids something the
   * guard permits teaches an agent to ask permission it does not need, which is
   * how a run stalls waiting for a human who was never required.
   */
  public function testTheReadmeInventsNoRefusal(): void {
    $refused = $this->refusedByTheGuard();
    $this->assertContains('gate-waive', $refused);
    $this->assertContains('bypass', $refused);
    $this->assertContains('baseline', $refused);
    $this->assertContains('effort', $refused);
    $this->assertContains('gate (arming a write gate)', $refused);
    $this->assertCount(
      5,
      $refused,
      'the README table has five rows; the guard must have five branches',
    );
  }

}
