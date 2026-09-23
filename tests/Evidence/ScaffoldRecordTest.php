<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\ScaffoldRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests which scaffolded files are still exactly what the scaffold wrote.
 */
#[CoversClass(ScaffoldRecord::class)]
final class ScaffoldRecordTest extends TestCase {

  /**
   * The scratch project root.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->root = sys_get_temp_dir() . '/droost-scaffold-record-' . bin2hex(random_bytes(6));
    mkdir($this->root . '/droost/droost-workflow', 0775, TRUE);
  }

  /**
   * Writes a project file.
   *
   * @param string $path
   *   The project-relative path.
   * @param string $content
   *   The content.
   */
  private function write(string $path, string $content): void {
    $absolute = $this->root . '/' . $path;
    if (!is_dir(dirname($absolute))) {
      mkdir(dirname($absolute), 0775, TRUE);
    }
    file_put_contents($absolute, $content);
  }

  /**
   * Appends rows to the record.
   *
   * @param list<array<string, mixed>|string> $rows
   *   Rows, or raw lines.
   */
  private function record(array $rows): void {
    $lines = array_map(static fn (array|string $row): string => is_string($row) ? $row : (string) json_encode($row), $rows);
    file_put_contents($this->root . '/droost/droost-workflow/' . ScaffoldRecord::FILE, implode("\n", $lines) . "\n", FILE_APPEND);
  }

  /**
   * The files still as written.
   *
   * @param string|null $run
   *   The open run.
   *
   * @return list<string>
   *   The untouched paths.
   */
  private function untouched(?string $run = 'run-a'): array {
    return ScaffoldRecord::untouched($this->root, $this->root . '/droost/droost-workflow', $run);
  }

  /**
   * Only a file still as the scaffold wrote it is listed.
   *
   * An edited one, a deleted one and an unrecorded one are not.
   */
  public function testOnlyFilesStillAsWrittenAreListed(): void {
    $generated = "<?php\n// generated\n";
    $this->write('web/modules/custom/a/tests/src/Unit/Hook/AHooksTest.php', $generated);
    $this->write('web/modules/custom/a/tests/src/Unit/Hook/BHooksTest.php', $generated);
    $this->write('web/modules/custom/a/tests/src/Kernel/OwnTest.php', "<?php\n// the agent's\n");
    $hash = 'sha256:' . hash('sha256', $generated);
    $row = static fn (string $name): array => [
      'path' => "web/modules/custom/a/tests/src/Unit/Hook/{$name}Test.php",
      'hash' => $hash,
      'blueprint' => 'hook',
      'run' => 'run-a',
    ];
    $this->record([$row('AHooks'), $row('BHooks'), $row('Gone')]);
    // One edit, and the file is the agent's.
    $this->write('web/modules/custom/a/tests/src/Unit/Hook/BHooksTest.php', $generated . "// edited\n");

    $this->assertSame(['web/modules/custom/a/tests/src/Unit/Hook/AHooksTest.php'], $this->untouched());
  }

  /**
   * Rows made under another run are skipped; rows under no run are kept.
   */
  public function testRowsAreAttributedToTheirRun(): void {
    $content = "<?php\n";
    $hash = 'sha256:' . hash('sha256', $content);
    foreach (['Mine', 'Theirs', 'Before'] as $name) {
      $this->write("m/tests/src/Unit/{$name}Test.php", $content);
    }
    $this->record([
      ['path' => 'm/tests/src/Unit/MineTest.php', 'hash' => $hash, 'run' => 'run-a'],
      ['path' => 'm/tests/src/Unit/TheirsTest.php', 'hash' => $hash, 'run' => 'run-b'],
      ['path' => 'm/tests/src/Unit/BeforeTest.php', 'hash' => $hash, 'run' => NULL],
    ]);

    $this->assertSame(['m/tests/src/Unit/BeforeTest.php', 'm/tests/src/Unit/MineTest.php'], $this->untouched());
    $this->assertCount(3, $this->untouched(NULL), 'with no run open, every row is read');
  }

  /**
   * The latest row for a path wins, and a re-scaffold records what is there.
   */
  public function testLatestRowForPathWins(): void {
    $this->write('m/tests/src/Unit/ATest.php', 'second');
    $this->record([
      ['path' => 'm/tests/src/Unit/ATest.php', 'hash' => 'sha256:' . hash('sha256', 'first'), 'run' => 'run-a'],
      ['path' => './m/tests/src/Unit/ATest.php', 'hash' => 'sha256:' . hash('sha256', 'second'), 'run' => 'run-a'],
    ]);
    $this->assertSame(['m/tests/src/Unit/ATest.php'], $this->untouched());
  }

  /**
   * Malformed lines, other hash schemes and escaping paths are ignored.
   */
  public function testMalformedRowsAreIgnored(): void {
    $this->write('m/tests/src/Unit/ATest.php', 'x');
    $this->write('escape.php', 'x');
    $this->record([
      'not json',
      '[]',
      ['path' => 'm/tests/src/Unit/ATest.php', 'hash' => 'md5:' . md5('x'), 'run' => 'run-a'],
      ['path' => 'm/tests/src/Unit/ATest.php', 'run' => 'run-a'],
      ['path' => 7, 'hash' => 'sha256:' . hash('sha256', 'x')],
      ['path' => 'm/../../escape.php', 'hash' => 'sha256:' . hash('sha256', 'x'), 'run' => 'run-a'],
    ]);
    $this->assertSame([], $this->untouched());
  }

  /**
   * No record is nothing discounted, as before the record existed.
   */
  public function testNoRecordListsNothing(): void {
    $this->assertSame([], $this->untouched());
  }

}
