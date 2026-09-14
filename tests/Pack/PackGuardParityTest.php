<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\State\RunStateStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The guard and the engine agree about which directory holds the run.
 *
 * The guard carries no autoloader by design, so it INLINES the rule that
 * `RunStateStore::resolveStateDir()` implements, and a comment at the top of
 * the guard promised this test compared the two. The test did not exist. The
 * comment had been true of an intention and never of the repository.
 *
 * That mattered within hours: a fix changed the rule — ask where the RECORD is
 * before asking which directory exists — and updated the guard's copy, the
 * engine's copy, and NOT the third copy inside `require_run_guard()`. On a
 * legacy project one `mkdir -p droost/droost-workflow` then pointed that copy
 * at an empty directory, and the operator's recorded bypass stopped being
 * honoured: the wall turned back on over a decision a human had made.
 *
 * A disagreement here is not cosmetic. The guard decides whether to refuse; the
 * engine decides what the run IS. Two answers means enforcing one run's rules
 * against another run's record.
 *
 * The guard's answer is observed rather than read: a run record is placed in
 * one directory and the stop hook is asked whether a turn may end. It refuses
 * only if it found that record, which is the resolution, from outside.
 */
final class PackGuardParityTest extends TestCase {

  /**
   * A scratch project root.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->root = sys_get_temp_dir() . '/droost-parity-' . bin2hex(random_bytes(6));
    mkdir($this->root, 0775, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->root)) {
      exec('rm -rf ' . escapeshellarg($this->root));
    }
  }

  /**
   * Every shape a project's state directories can be in.
   *
   * @return array<string, array{list<string>, string|null, string}>
   *   Directories to create, where the run record goes, the expected answer.
   */
  public static function shapes(): array {
    $legacy = '.droost-workflow';
    $visible = 'droost/droost-workflow';

    return [
      'only the legacy directory, with a run' => [[$legacy], $legacy, $legacy],
      'only the legacy directory, no run' => [[$legacy], NULL, $legacy],
      'only the visible directory, with a run' => [[$visible], $visible, $visible],
      'only the visible directory, no run' => [[$visible], NULL, $visible],
      // The shape that broke it: an empty visible directory beside a legacy one
      // that holds the actual record.
      'both, the record in the legacy one' => [[$legacy, $visible], $legacy, $legacy],
      'both, the record in the visible one' => [[$legacy, $visible], $visible, $visible],
      'both, no record anywhere' => [[$legacy, $visible], NULL, $visible],
      'neither' => [[], NULL, $visible],
    ];
  }

  /**
   * The engine resolves what the shape says it should.
   *
   * @param list<string> $directories
   *   Directories to create.
   * @param string|null $record
   *   Where run.json goes, or NULL for none.
   * @param string $expected
   *   The directory both must choose.
   */
  #[DataProvider('shapes')]
  public function testTheEngineResolvesTheExpectedDirectory(
    array $directories,
    ?string $record,
    string $expected,
  ): void {
    $this->build($directories, $record);

    $this->assertSame($expected, RunStateStore::resolveStateDir($this->root));
  }

  /**
   * And the guard resolves the same one, observed from outside.
   *
   * @param list<string> $directories
   *   Directories to create.
   * @param string|null $record
   *   Where run.json goes, or NULL for none.
   * @param string $expected
   *   The directory both must choose.
   */
  #[DataProvider('shapes')]
  public function testTheGuardResolvesTheSameDirectory(
    array $directories,
    ?string $record,
    string $expected,
  ): void {
    $this->build($directories, $record);

    // The stop hook refuses only when it finds an ACTIVE run — so it refuses
    // exactly when it resolved to the directory holding the record.
    $code = $this->stop();

    if ($record === NULL) {
      $this->assertSame(0, $code, 'no record anywhere, so nothing to hold');

      return;
    }
    $this->assertSame(
      $record === $expected ? 2 : 0,
      $code,
      sprintf(
        'the guard must read the record in %s, which is what the engine '
        . 'resolves to (%s)',
        $record,
        $expected,
      ),
    );
  }

  /**
   * The guard and the binary resolve the same project from a subdirectory.
   *
   * This file set `CLAUDE_PROJECT_DIR` for every probe and never varied the
   * working directory, so it could not see the divergence it exists to catch:
   * the binary walked up to find the project and the guard did not, and with
   * the variable unset — a plain CLI run, Codex, a hook invoked by hand — a
   * `cd` into any subdirectory left the guard reporting "no active run" and
   * standing the stop wall down while the binary advanced that very run.
   *
   * The engine advancing a run the guard is not watching is the worst outcome
   * this pair can produce, and it is exactly what one resolver walking and the
   * other not produces.
   */
  public function testBothResolveTheProjectFromSubdirectories(): void {
    $this->build(['droost/droost-workflow'], 'droost/droost-workflow');
    mkdir($this->root . '/lib/sub', 0775, TRUE);

    $this->assertSame(
      2,
      $this->stopFrom($this->root . '/lib/sub'),
      'the guard finds the run from a subdirectory',
    );
    // And an empty state directory beside it does not hide that run — one
    // `mkdir`, which the guard permits because creating a directory is not an
    // edit, used to make the real project vanish from the resolver.
    mkdir($this->root . '/lib/droost/droost-workflow', 0775, TRUE);
    $this->assertSame(
      2,
      $this->stopFrom($this->root . '/lib/sub'),
      'an empty state directory is not a project',
    );
  }

  /**
   * Runs the guard's stop mode from a given directory, with no host variable.
   *
   * @param string $cwd
   *   Where to run from.
   *
   * @return int
   *   The exit code.
   */
  private function stopFrom(string $cwd): int {
    $script = dirname(__DIR__, 2) . '/pack/hooks/droost-workflow-guard.php';
    $env = getenv();
    unset($env['CLAUDE_PROJECT_DIR']);
    $process = proc_open(
      [PHP_BINARY, $script, 'stop'],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      $cwd,
      $env,
    );
    $this->assertIsResource($process);
    fwrite($pipes[0], (string) json_encode(['tool_name' => 'Stop', 'tool_input' => []]));
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $this->assertContains($code, [0, 2], sprintf("the guard crashed (%d):\n%s", $code, $stderr));

    return $code;
  }

  /**
   * Creates the project shape.
   *
   * @param list<string> $directories
   *   Directories to create.
   * @param string|null $record
   *   Where run.json goes, or NULL for none.
   */
  private function build(array $directories, ?string $record): void {
    foreach ($directories as $directory) {
      mkdir($this->root . '/' . $directory, 0775, TRUE);
    }
    if ($record !== NULL) {
      file_put_contents($this->root . '/' . $record . '/run.json', (string) json_encode([
        'run_id' => 'r1',
        'current_phase' => 'code',
        'phases' => ['code' => 'running'],
        'enforcement' => 'hard',
      ]));
    }
  }

  /**
   * Runs the guard's stop mode, returning its exit code.
   *
   * @return int
   *   0 allows, 2 blocks. Anything else is a crash and fails here.
   */
  private function stop(): int {
    $script = dirname(__DIR__, 2) . '/pack/hooks/droost-workflow-guard.php';
    $env = getenv();
    $env['CLAUDE_PROJECT_DIR'] = $this->root;
    $process = proc_open(
      [PHP_BINARY, $script, 'stop'],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      $this->root,
      $env,
    );
    $this->assertIsResource($process);
    fwrite($pipes[0], (string) json_encode(['tool_name' => 'Stop', 'tool_input' => []]));
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $this->assertContains(
      $code,
      [0, 2],
      sprintf("the guard crashed (exit %d):\n%s", $code, $stderr),
    );

    return $code;
  }

  /**
   * The states that hold a phase are the states the stop hook reports.
   *
   * Two lists, two files, no autoloader between them. `CheckState::
   * blocksAdvance()` decides whether the ENGINE advances; the guard's
   * `unresolved_checks()` hardcodes `state IN ('blocked','pending')` in SQL
   * because it cannot load the enum. They agree today and nothing said so.
   *
   * Divergence here is the quiet kind: add an eighth state that blocks, and
   * the engine holds the phase while the stop hook — asked whether anything is
   * unresolved — finds no rows and lets the agent end its turn. The run is
   * stuck and the one thing that would have said so is looking for the wrong
   * word.
   */
  public function testTheGuardsBlockingStatesAreTheEnums(): void {
    $blocking = [];
    foreach (CheckState::cases() as $case) {
      if ($case->blocksAdvance()) {
        $blocking[] = $case->value;
      }
    }
    sort($blocking);

    $guard = (string) file_get_contents(dirname(__DIR__, 2) . '/pack/hooks/droost-workflow-guard.php');
    $body = strstr($guard, 'function unresolved_checks');
    $this->assertIsString($body, 'the guard still asks the store what is unresolved');
    $this->assertSame(
      1,
      preg_match('/state IN \(([^)]+)\)/', $body, $match),
      'and it still asks with a literal state list',
    );
    preg_match_all("/'([a-z_]+)'/", str_replace("\\'", "'", $match[1]), $names);
    $asked = $names[1];
    sort($asked);

    $this->assertSame(
      $blocking,
      $asked,
      'the guard reports exactly the states that stop the engine',
    );
  }

  /**
   * The guard and the engine agree about what a repository is.
   *
   * Two implementations of one rule, in two files, one of which carries no
   * autoloader and so cannot share the other's code. They have now diverged
   * twice, in opposite directions, and the second divergence was created by
   * the fix for the first:
   *
   *   * the engine walked and the guard did not, so a `cd` into a
   *     subdirectory disarmed the stop wall while the binary kept advancing
   *     the run;
   *   * then the guard learned that `mkdir .git`, a symlinked `.git` and
   *     `gitdir:` naming any directory that exists are not repositories, and
   *     the engine did not — so one `mkdir lib/sub/.git` stopped the engine
   *     two levels below the project, and it read built-in defaults while the
   *     operator's lever file sat unread in the directory above.
   *
   * Neither was catchable by testing either side alone, and both were found by
   * a reviewer diffing the two by hand. So this drives BOTH over the same
   * shapes and compares the answers. It asserts agreement rather than a
   * particular answer, because the thing that keeps breaking is the agreement.
   */
  public function testBothResolversAgreeOnWhatCountsAsRepository(): void {
    $shapes = [
      'nothing planted' => static function (string $root): void {},
      'an empty state directory below' => static function (string $root): void {
        mkdir($root . '/lib/sub/droost/droost-workflow', 0775, TRUE);
      },
      'an empty .git directory below' => static function (string $root): void {
        mkdir($root . '/lib/sub/.git', 0775, TRUE);
      },
      'a junk .git file below' => static function (string $root): void {
        file_put_contents($root . '/lib/sub/.git', "junk\n");
      },
      'a gitdir naming an ordinary directory' => static function (string $root): void {
        mkdir($root . '/decoy', 0775, TRUE);
        file_put_contents($root . '/lib/sub/.git', 'gitdir: ' . $root . "/decoy\n");
      },
      'a .git symlinked elsewhere' => static function (string $root): void {
        mkdir($root . '/decoy', 0775, TRUE);
        symlink($root . '/decoy', $root . '/lib/sub/.git');
      },
      'a .git symlinked to a real repository' => static function (string $root): void {
        mkdir($root . '/decoy/.realgit/objects', 0775, TRUE);
        file_put_contents($root . '/decoy/.realgit/HEAD', "ref: refs/heads/main\n");
        symlink($root . '/decoy/.realgit', $root . '/lib/sub/.git');
      },
      'a real repository below' => static function (string $root): void {
        mkdir($root . '/lib/sub/.git/objects', 0775, TRUE);
        file_put_contents($root . '/lib/sub/.git/HEAD', "ref: refs/heads/main\n");
      },
      'a real worktree pointer below' => static function (string $root): void {
        mkdir($root . '/lib/sub/.realgit/objects', 0775, TRUE);
        file_put_contents($root . '/lib/sub/.realgit/HEAD', "ref: refs/heads/main\n");
        file_put_contents($root . '/lib/sub/.git', "gitdir: .realgit\n");
      },
    ];

    foreach ($shapes as $label => $shape) {
      // A fresh subtree per shape, under this test's own scratch root so
      // tearDown takes them all.
      $root = $this->root . '/' . bin2hex(random_bytes(5));
      mkdir($root . '/lib/sub/modules/custom/x', 0775, TRUE);
      // `require_run: off` is the discriminator: whichever side found the
      // lever file behaves differently from one that fell back to defaults.
      file_put_contents($root . '/droost.workflow.yml', "preset: low\nrequire_run: off\n");
      $shape($root);
      $cwd = $root . '/lib/sub';

      $this->assertSame(
        $this->engineFoundTheLever($cwd),
        $this->guardFoundTheLever($cwd),
        sprintf('with %s, the guard and the engine must resolve the same project', $label),
      );
    }
  }

  /**
   * Whether `bin/droost-workflow` read the project's lever file.
   *
   * @param string $cwd
   *   Where to run from.
   *
   * @return bool
   *   TRUE when it found one.
   */
  private function engineFoundTheLever(string $cwd): bool {
    $process = proc_open(
      [PHP_BINARY, dirname(__DIR__, 2) . '/bin/droost-workflow', 'status'],
      [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      $cwd,
      ['PATH' => (string) getenv('PATH')],
    );
    $this->assertIsResource($process);
    $printed = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return str_contains($printed, '"provenance": "file"');
  }

  /**
   * Whether the guard read the project's lever file.
   *
   * With `require_run: off` in it, a custom-code write is allowed only by a
   * guard that found the file; the built-in default is `hard`.
   *
   * @param string $cwd
   *   Where to run from.
   *
   * @return bool
   *   TRUE when it found one.
   */
  private function guardFoundTheLever(string $cwd): bool {
    $process = proc_open(
      [PHP_BINARY, dirname(__DIR__, 2) . '/pack/hooks/droost-workflow-guard.php', 'pre-tool-use'],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      $cwd,
      ['PATH' => (string) getenv('PATH')],
    );
    $this->assertIsResource($process);
    fwrite($pipes[0], (string) json_encode([
      'tool_input' => ['file_path' => $cwd . '/modules/custom/x/x.module'],
    ]));
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process) === 0;
  }

}
