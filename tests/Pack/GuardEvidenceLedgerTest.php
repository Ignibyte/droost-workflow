<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The run's evidence is written by the pipeline, never by hand (F-96).
 *
 * The guard protected `run.json`, `bypass.json`, the store and the baseline,
 * and nothing else in the state directory. The tool-call ledger, the guard's
 * record of itself, the scaffold record, the pack lock and the archived runs
 * took a Write, an append or a `sed -i`, run or no run. `grounding_check`
 * reads the tool-call ledger straight from its file, so one appended line was
 * a planned tool "called". Found preparing P6 run 7's record.
 */
final class GuardEvidenceLedgerTest extends WorkflowTestCase {

  /**
   * The files the record is made of.
   */
  private const EVIDENCE = [
    'droost/droost-workflow/tool-calls.jsonl',
    'droost/droost-workflow/guard-calls.jsonl',
    'droost/droost-workflow/scaffolded.jsonl',
    'droost/droost-workflow/pack.lock',
    'droost/droost-workflow/history/run-old.json',
  ];

  /**
   * No tool that writes reaches the evidence, with a run open.
   */
  public function testEvidenceIsRefusedToEveryWriter(): void {
    $root = $this->rootWithEvidence(TRUE);
    foreach (self::EVIDENCE as $file) {
      [$exit] = $this->guard($root, 'pre-tool-use', $this->write($root . '/' . $file));
      $this->assertSame(2, $exit, 'Write to ' . $file);
      foreach (['echo \'{"tool":"droost_search"}\' >> ' . $file, 'sed -i s/a/b/ ' . $file, 'cp /dev/null ' . $file] as $command) {
        [$exit] = $this->guard($root, 'operator-commands', ['tool_input' => ['command' => $command]]);
        $this->assertSame(2, $exit, $command);
      }
    }
  }

  /**
   * A row written before the run opens counts for it: no run is no exception.
   */
  public function testTheLedgerIsProtectedWithNoRunOpen(): void {
    $root = $this->rootWithEvidence(FALSE);
    [$exit] = $this->guard($root, 'operator-commands', ['tool_input' => ['command' => 'echo \'{"tool":"droost_search","run":null}\' >> droost/droost-workflow/tool-calls.jsonl']]);
    $this->assertSame(2, $exit, 'a NULL-run row counts for the next run');
  }

  /**
   * The spec is the agent's, and reading the record is not writing it.
   */
  public function testTheSpecAndReadsStayOpen(): void {
    $root = $this->rootWithEvidence(TRUE);
    foreach (['droost/droost-workflow/spec-t7-camp-filters.md', 'droost/droost-workflow/spec.md'] as $spec) {
      [$exit, , $stderr] = $this->guard($root, 'pre-tool-use', $this->write($root . '/' . $spec));
      $this->assertSame(0, $exit, 'Write to ' . $spec . ': ' . $stderr);
    }
    foreach ([
      'cat droost/droost-workflow/tool-calls.jsonl',
      'grep -c droost_search droost/droost-workflow/tool-calls.jsonl',
      'cd droost/droost-workflow && echo x > spec-b.md',
    ] as $command) {
      [$exit, , $stderr] = $this->guard($root, 'operator-commands', ['tool_input' => ['command' => $command]]);
      $this->assertSame(0, $exit, $command . ': ' . $stderr);
    }
  }

  /**
   * The store answers with its own rule, and says how to read it (F-108).
   *
   * The state directory's rule answered first for every file already in it,
   * the store included. P6 run 8's seeker ran `sqlite3 evidence.sqlite
   * ".tables"` and was told a hand-written line is a forged ledger entry and
   * to write its spec with Edit, under `protected-path:shell`. The store's own
   * refusal names it and the command that renders it. The refusal is right:
   * sqlite3 can write files and run commands, so no call of it on the store
   * is a read the guard can allow.
   */
  public function testTheStoreIsRefusedInItsOwnWords(): void {
    $root = $this->rootWithEvidence(TRUE);
    file_put_contents($root . '/droost/droost-workflow/evidence.sqlite', 'x');

    foreach ([
      'cd droost/droost-workflow && sqlite3 evidence.sqlite ".tables"',
      'sqlite3 droost/droost-workflow/evidence.sqlite "select 1"',
    ] as $command) {
      [$exit, , $stderr] = $this->guard($root, 'operator-commands', ['tool_input' => ['command' => $command]]);
      $this->assertSame(2, $exit, $command);
      $this->assertStringContainsString('reaches the evidence store', $stderr, $command);
      $this->assertStringContainsString('droost-workflow evidence', $stderr, $command);
      $this->assertStringNotContainsString('forged entry', $stderr, $command);
    }

    [$exit, , $stderr] = $this->guard($root, 'pre-tool-use', $this->write($root . '/droost/droost-workflow/evidence.sqlite'));
    $this->assertSame(2, $exit);
    $this->assertStringContainsString('The evidence store is the run\'s own record', $stderr);
    $this->assertStringNotContainsString('forged entry', $stderr);
  }

  /**
   * A loop over the archive is refused in words that say how to read it.
   *
   * P6 run 9's agent ran `for f in droost/droost-workflow/history/*.spec.md;
   * do echo "$f: $(head -1 $f)"; done` and was told only that a hand-written
   * line forges the record and to write its spec with Edit. It read the same
   * files with `grep -r` a call later, allowed. The refusal stands, since a
   * loop's body can write to every name its list expands to, and now it says
   * why, and how to read (F-112). Naming the files to a reader stays open.
   */
  public function testLoopOverTheArchiveSaysHowToRead(): void {
    $root = $this->rootWithEvidence(TRUE);
    file_put_contents($root . '/droost/droost-workflow/history/run-a.spec.md', "# A\n");

    [$exit, , $stderr] = $this->guard($root, 'operator-commands', ['tool_input' => ['command' => 'for f in droost/droost-workflow/history/*.spec.md; do echo "$f: $(head -1 $f)"; done']]);
    $this->assertSame(2, $exit);
    $this->assertStringContainsString('`for` loop\'s body can write to every name its list expands to', $stderr);
    $this->assertStringContainsString('the Read tool', $stderr);
    $this->assertStringContainsString('droost-workflow evidence', $stderr);

    foreach ([
      'head -1 droost/droost-workflow/history/*.spec.md',
      'grep -m1 -r "^# " droost/droost-workflow/history --include="*.spec.md"',
    ] as $command) {
      [$exit, , $stderr] = $this->guard($root, 'operator-commands', ['tool_input' => ['command' => $command]]);
      $this->assertSame(0, $exit, $command . ': ' . $stderr);
    }
  }

  /**
   * A Write tool call's payload.
   *
   * @param string $path
   *   The absolute path written.
   *
   * @return array<string, mixed>
   *   The payload the hook receives.
   */
  private function write(string $path): array {
    return ['tool_name' => 'Write', 'tool_input' => ['file_path' => $path, 'content' => 'x']];
  }

  /**
   * A root with a state directory holding the evidence files.
   *
   * @param bool $live
   *   Whether a run is under way.
   *
   * @return string
   *   The root.
   */
  private function rootWithEvidence(bool $live): string {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow/history', 0755, TRUE);
    foreach (self::EVIDENCE as $file) {
      file_put_contents($root . '/' . $file, "\n");
    }
    if ($live) {
      file_put_contents($root . '/droost/droost-workflow/run.json', (string) json_encode([
        'run_id' => 'run-evidence',
        'current_phase' => 'code',
        'phases' => ['plan' => 'passed', 'code' => 'active'],
        'enforcement' => 'hard',
        'mode' => 'agentic',
      ]));
    }
    return $root;
  }

}
