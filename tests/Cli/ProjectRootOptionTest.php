<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Cli;

use Droost\Workflow\Cli\ArgvDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * The --project option names the repository, here as on the other two.
 *
 * The MCP tools take a `project` argument and the drush commands a `--project`
 * option. `bin/droost-workflow` took the working directory and nothing else, so
 * an instruction written once for an agent worked on two surfaces and was an
 * unrecognised flag on the third — the one a repository with no Drupal has.
 *
 * The symptom is worse than the inconsistency. An agent's shell can `cd`, and a
 * binary resolving its root from wherever the shell happens to be reads a
 * DIFFERENT state directory from the one the guard is enforcing: two components
 * disagreeing about which repository this is, silently, with the binary
 * reporting built-in defaults as though the project had no levers.
 */
final class ProjectRootOptionTest extends TestCase {

  /**
   * A scratch project with a lever file, plus a subdirectory to run from.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->root = sys_get_temp_dir() . '/droost-proj-' . bin2hex(random_bytes(6));
    mkdir($this->root . '/sub/deeper', 0775, TRUE);
    file_put_contents(
      $this->root . '/droost.workflow.yml',
      "preset: medium\nmode: agentic\n",
    );
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
   * Runs a verb, returning the exit code and everything printed.
   *
   * @param list<string> $argv
   *   The arguments after the binary name.
   * @param string $cwd
   *   The working directory the dispatcher is handed, as the binary hands it
   *   `getcwd()`.
   *
   * @return array{int, string}
   *   Exit code and output.
   */
  private function dispatch(array $argv, string $cwd): array {
    $lines = [];
    $sink = function (string $line) use (&$lines): void {
      $lines[] = $line;
    };
    $dispatcher = new ArgvDispatcher(
      $sink,
      $sink,
      static fn (): string => '2026-09-13T00:00:00+00:00',
      static fn (): string => 'run-proj',
    );

    return [$dispatcher->dispatch($argv, $cwd), implode("\n", $lines)];
  }

  /**
   * From a subdirectory, both forms reach the real lever file.
   *
   * This test used to assert the OPPOSITE for the bare form — that running
   * from a subdirectory reads built-in defaults — and used that as the contrast
   * proving `--project` did something. It was documenting a bug as a baseline:
   * the silence it described is what let `run` create a second state root under
   * the subdirectory, whose run.json then blocked the real run as undeclared
   * scope with no waiver. The walk up to the project fixed the bare form, and
   * the contrast went with it.
   *
   * `--project` still matters — it names a root that is NOT an ancestor — and
   * `testNamingTheProjectBeatsTheWalk` is where that is held now.
   */
  public function testProjectReachesTheRealRootFromSubdirectories(): void {
    $deep = $this->root . '/sub/deeper';

    [, $without] = $this->dispatch(['status'], $deep);
    $this->assertStringContainsString(
      '"provenance": "file"',
      $without,
      'the project above is found without being named',
    );

    [, $with] = $this->dispatch(['status', '--project=' . $this->root], $deep);
    $this->assertStringContainsString(
      '"provenance": "file"',
      $with,
      'naming the root finds the levers the run is actually held to',
    );
  }

  /**
   * A `--project` that is not a directory is refused by name.
   *
   * The words match the MCP tools' refusal, so an agent that learns the
   * phrasing on one surface reads the other correctly.
   */
  public function testUnusableProjectIsRefused(): void {
    [$code, $printed] = $this->dispatch(
      ['status', '--project=' . $this->root . '/nope'],
      $this->root,
    );

    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);
    $this->assertStringContainsString('Not a directory', $printed);
    $this->assertStringContainsString($this->root . '/nope', $printed, 'and names it');
  }

  /**
   * Every verb takes it, not a chosen few.
   *
   * Parsed before the verb dispatch for exactly this reason: a flag that works
   * on `status` and not on `run` is a worse contract than no flag.
   */
  public function testEveryVerbAcceptsIt(): void {
    foreach (['status', 'report', 'evidence'] as $verb) {
      [$code, $printed] = $this->dispatch(
        [$verb, '--project=' . $this->root . '/nope'],
        $this->root,
      );
      $this->assertSame(
        ArgvDispatcher::EXIT_USAGE,
        $code,
        sprintf('%s reads --project', $verb),
      );
      $this->assertStringContainsString('Not a directory', $printed);
    }
  }

  /**
   * The init command says what it just set the repo to.
   *
   * It printed "wrote 21 file(s)" and nothing else, while the lever file it
   * writes moves a repo from the built-in defaults — preset max, enforcement
   * hard, every gate on — to preset custom, enforcement soft, and six optional
   * tiers off. Running the documented FIRST COMMAND opted you out of six things
   * in silence, under a README line saying a repo which has said nothing has
   * not opted out of anything.
   */
  public function testInitReportsTheLeversItLeavesBehind(): void {
    [$code, $printed] = $this->dispatch(['init'], $this->root);

    $this->assertSame(0, $code);
    $this->assertStringContainsString('This repo now resolves to', $printed);
    $this->assertStringContainsString('preset', $printed);
    $this->assertStringContainsString('enforcement', $printed);
    $this->assertStringContainsString(
      'gates off',
      $printed,
      'and names what it turned off, which is the part that was silent',
    );
  }

  /**
   * The space form of --spec is read, as `--spec=<path>` is.
   *
   * It was dropped without a word, and the failure that followed said "…and no
   * --spec declared" — false, and it sends a reader looking for a spec they had
   * just named. Every other CLI in a developer's day takes both spellings.
   *
   * TWO specs, because with one in the directory the facade resolves it
   * whatever the flag says, and a fixture with one spec passes this test with
   * the flag handling deleted. That is exactly the situation the bug was found
   * in: a state directory accretes a spec per ticket, and the run has to be
   * told which is its own.
   */
  public function testTheSpaceFormOfSpecIsRead(): void {
    mkdir($this->root . '/droost/droost-workflow', 0775, TRUE);
    foreach (['spec-aaa-other.md', 'spec-zzz-mine.md'] as $name) {
      file_put_contents(
        $this->root . '/droost/droost-workflow/' . $name,
        "## Acceptance criteria\n\n## Tooling plan\n\n## Grounding\n",
      );
    }
    exec('git -C ' . escapeshellarg($this->root) . ' init -q 2>/dev/null');
    exec('git -C ' . escapeshellarg($this->root) . ' commit -q --allow-empty -m i 2>/dev/null');

    $this->dispatch(
      ['run', '--spec', 'droost/droost-workflow/spec-zzz-mine.md'],
      $this->root,
    );

    $state = json_decode(
      (string) file_get_contents($this->root . '/droost/droost-workflow/run.json'),
      TRUE,
    );
    $this->assertIsArray($state);
    $this->assertSame(
      'droost/droost-workflow/spec-zzz-mine.md',
      $state['spec_path'] ?? NULL,
      'the run is governed by the spec that was named, not by whichever sorts first',
    );
  }

  /**
   * But `--spec` with nothing after it says so rather than guessing.
   */
  public function testSpecWithNoPathIsRefused(): void {
    [$code, $printed] = $this->dispatch(['run', '--spec'], $this->root);

    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);
    $this->assertStringContainsString('--spec needs a path', $printed);
  }

  /**
   * From a subdirectory, the project above is found.
   *
   * The working directory was taken as the repository, full stop, so running
   * from `lib/sub` reported built-in defaults as though the project had no
   * levers. `run` went further: it CREATED a second state root at
   * `lib/sub/droost/droost-workflow/`, and that nested run.json then blocked
   * the real run as undeclared scope, with no waiver. A second ticket stopped
   * by a directory the product had made in the wrong place.
   *
   * A repository is where its lever file is, the way git's is where `.git` is.
   */
  public function testTheProjectAboveIsFoundFromSubdirectories(): void {
    mkdir($this->root . '/lib/sub', 0775, TRUE);

    [, $printed] = $this->dispatch(['status'], $this->root . '/lib/sub');

    $this->assertStringContainsString(
      '"provenance": "file"',
      $printed,
      'the levers the run would actually be held to',
    );
    $this->assertDirectoryDoesNotExist(
      $this->root . '/lib/sub/droost',
      'and no second state root is created under the subdirectory',
    );
  }

  /**
   * But `init` never climbs: it is how a project comes into existence.
   *
   * Climbing there made `init` in an empty subdirectory adopt the parent and
   * report "kept your existing droost.workflow.yml" about a file the operator
   * had never seen. Found by running it.
   */
  public function testInitNeverClimbsToTheProjectAbove(): void {
    mkdir($this->root . '/lib/sub', 0775, TRUE);

    [$code] = $this->dispatch(['init'], $this->root . '/lib/sub');

    $this->assertSame(0, $code);
    $this->assertFileExists(
      $this->root . '/lib/sub/droost.workflow.yml',
      'init initialises where it was run',
    );
  }

  /**
   * And an explicit --project still wins over the walk.
   */
  public function testNamingTheProjectBeatsTheWalk(): void {
    mkdir($this->root . '/lib/sub', 0775, TRUE);

    [$code, $printed] = $this->dispatch(
      ['status', '--project=' . $this->root . '/nope'],
      $this->root . '/lib/sub',
    );

    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, 'the named path is judged, not a found one');
    $this->assertStringContainsString('Not a directory', $printed);
  }

}
