<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The guard's row says which drush generators an allowed command ran.
 *
 * The grounding_check gate in droost asks whether a file a run added came
 * from the surface that makes it. A blueprint leaves `scaffolded.jsonl` and a
 * droost tool leaves `tool-calls.jsonl`, but a `drush generate` left no trace
 * the product could read: the guard saw the command and wrote only its
 * verdict.
 * P6 run 11's first attempt planned six generators and ran four, and only
 * its transcript could say which.
 */
final class GuardGeneratorLedgerTest extends WorkflowTestCase {

  /**
   * A command that writes records each generator it runs, once.
   *
   * @param string $command
   *   The shell command.
   * @param list<string> $expected
   *   The generators the row names.
   */
  #[DataProvider('writes')]
  public function testAnAllowedGeneratorRunIsRecorded(string $command, array $expected): void {
    $root = $this->rootWithRun('code');

    [$exit] = $this->guard($root, 'operator-commands', ['tool_name' => 'Bash', 'tool_input' => ['command' => $command]]);

    $this->assertSame(0, $exit, $command);
    $row = $this->lastRow($root);
    $this->assertSame('allow', $row['verdict'], $command);
    $this->assertSame($expected, $row['generated'] ?? NULL, $command);
  }

  /**
   * Commands that run a generator for real.
   *
   * @return array<string, array{string, list<string>}>
   *   The cases.
   */
  public static function writes(): array {
    return [
      'ddev drush, with answers' => [
        'ddev drush generate entity:content --answer=my_module --answer="Camp registration"',
        ['entity:content'],
      ],
      'the short alias, a separate answer' => [
        'vendor/bin/drush gen form:simple -a my_module',
        ['form:simple'],
      ],
      'inside ddev exec' => [
        'ddev exec "drush generate plugin:block --answer=x"',
        ['plugin:block'],
      ],
      'piped into tail' => [
        'ddev drush generate hook --answer=my_module --answer=update_N 2>&1 | tail -3',
        ['hook'],
      ],
      'two in one line' => [
        'cd web && drush generate yml:permissions -a x && drush generate yml:links:menu -a x',
        ['yml:permissions', 'yml:links:menu'],
      ],
      'the same one twice' => [
        'drush generate service:custom -a a; drush generate service:custom -a b',
        ['service:custom'],
      ],
    ];
  }

  /**
   * A command that only looks, or names no generator, records none.
   *
   * @param string $command
   *   The shell command.
   */
  #[DataProvider('looks')]
  public function testLookIsNotRecorded(string $command): void {
    $root = $this->rootWithRun('code');

    [$exit] = $this->guard($root, 'operator-commands', ['tool_name' => 'Bash', 'tool_input' => ['command' => $command]]);

    $this->assertSame(0, $exit, $command);
    $this->assertArrayNotHasKey('generated', $this->lastRow($root), $command);
  }

  /**
   * Commands that write nothing a generator makes.
   *
   * @return array<string, array{string}>
   *   The cases.
   */
  public static function looks(): array {
    return [
      'a dry run' => ['ddev drush generate entity:content --dry-run --answer=my_module'],
      'help' => ['drush generate --help'],
      'help for one' => ['drush generate entity:content -h'],
      'the bare listing' => ['ddev drush generate 2>&1 | head -80'],
      'named in a message' => ['git commit -m "drush generate module"'],
      'named in an echo' => ['echo drush generate module'],
      'another drush verb' => ['ddev drush cr'],
    ];
  }

  /**
   * A refused command is no evidence that anything was generated.
   */
  public function testRefusedCommandRecordsNothing(): void {
    $root = $this->rootWithRun('code', 'hard');

    $command = 'drush generate module -a x; drush droost:workflow:bypass hotfix';
    [$exit] = $this->guard($root, 'operator-commands', ['tool_name' => 'Bash', 'tool_input' => ['command' => $command]]);

    $this->assertSame(2, $exit);
    $row = $this->lastRow($root);
    $this->assertSame('refuse', $row['verdict']);
    $this->assertArrayNotHasKey('generated', $row);
  }

  /**
   * A project root with a run open at the given phase.
   *
   * @param string $phase
   *   The phase.
   * @param string $enforcement
   *   The enforcement level.
   *
   * @return string
   *   The root.
   */
  private function rootWithRun(string $phase, string $enforcement = 'soft'): string {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    file_put_contents($root . '/droost/droost-workflow/run.json', (string) json_encode([
      'run_id' => 'run-generated',
      'current_phase' => $phase,
      'phases' => [$phase => 'active'],
      'enforcement' => $enforcement,
      'mode' => 'agentic',
    ]));
    return $root;
  }

  /**
   * The ledger's last row, as written.
   *
   * @param string $root
   *   The project root.
   *
   * @return array<string, mixed>
   *   The row.
   */
  private function lastRow(string $root): array {
    $path = $root . '/droost/droost-workflow/guard-calls.jsonl';
    $this->assertFileExists($path, 'the guard wrote its ledger');
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $this->assertNotSame([], $lines);
    $decoded = json_decode((string) end($lines), TRUE);
    $this->assertIsArray($decoded);
    $row = [];
    foreach ($decoded as $key => $value) {
      $row[(string) $key] = $value;
    }
    return $row;
  }

}
