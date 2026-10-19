<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The guard's `nudge` mode guides and never refuses (owner, 2026-10-02).
 *
 * "We force the workflow … but droost is the question and answer", and the
 * agent is told, beside a tool's result, that droost answers what it was about
 * to walk the tree for, that a generator makes the file it just wrote, and
 * that its plan goes to droost before plan closes. Each note is a diary row;
 * a call that earns nothing writes none.
 */
final class GuardNudgeTest extends WorkflowTestCase {

  /**
   * A project with an open run in a phase.
   */
  private function project(string $phase = 'code'): string {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    file_put_contents($root . '/droost/droost-workflow/run.json', (string) json_encode([
      'v' => 1,
      'run_id' => 'run-n',
      'current_phase' => $phase,
    ]));
    return $root;
  }

  /**
   * One finished call through the nudge.
   *
   * @param string $root
   *   The project.
   * @param string $tool
   *   The tool's name.
   * @param array<string, mixed> $input
   *   The tool's input.
   * @param array<string, mixed> $response
   *   The tool's response; a Write creates by default.
   *
   * @return string|null
   *   The note the agent reads, or NULL for none.
   */
  private function nudge(string $root, string $tool, array $input, array $response = ['type' => 'create']): ?string {
    [$code, $out] = $this->guard($root, 'nudge', [
      'tool_name' => $tool,
      'tool_input' => $input,
      'tool_response' => $response,
    ]);
    $this->assertSame(0, $code, 'a nudge never refuses');
    if (trim($out) === '') {
      return NULL;
    }
    $decoded = json_decode($out, TRUE);
    $this->assertIsArray($decoded);
    $this->assertIsArray($decoded['hookSpecificOutput']);
    $this->assertSame('PostToolUse', $decoded['hookSpecificOutput']['hookEventName']);
    $this->assertIsString($decoded['hookSpecificOutput']['additionalContext']);
    return $decoded['hookSpecificOutput']['additionalContext'];
  }

  /**
   * The diary's nudge rows.
   *
   * @return list<array<string, mixed>>
   *   The rows.
   */
  private function rows(string $root): array {
    $rows = [];
    foreach (@file($root . '/droost/droost-workflow/guard-calls.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      $row = json_decode($line, TRUE);
      if (!is_array($row) || ($row['mode'] ?? NULL) !== 'nudge') {
        continue;
      }
      $keyed = [];
      foreach ($row as $key => $value) {
        $keyed[(string) $key] = $value;
      }
      $rows[] = $keyed;
    }
    return $rows;
  }

  /**
   * A search of core is noted first in a phase and every tenth time after.
   */
  public function testSearchOfCoreIsNotedFirstAndEveryTenth(): void {
    $root = $this->project();
    $noted = [];
    for ($i = 1; $i <= 11; $i++) {
      $note = $this->nudge($root, 'Grep', ['pattern' => 'hook_tokens', 'path' => 'web/core']);
      if ($note !== NULL) {
        $noted[] = $i;
        $this->assertStringContainsString('droost code:symbol', $note);
      }
    }
    $this->assertSame([1, 10], $noted);
    $this->assertCount(11, $this->rows($root), 'every search is a row, noted or not');
    $this->assertCount(2, array_filter($this->rows($root), static fn (array $r): bool => $r['verdict'] === 'note'));
  }

  /**
   * The shapes of a search: shell programs over core, contrib and vendor.
   */
  public function testShellSearchesOfOtherPeoplesCodeAreSearches(): void {
    foreach ([
      'grep -rn "function hook_tokens" web/core/modules/system',
      'find vendor/drush -name "*.php"',
      'rg -l LocalActionManager web/modules/contrib/canvas',
    ] as $command) {
      $this->assertNotNull($this->nudge($this->project(), 'Bash', ['command' => $command]), $command);
    }
    $this->assertNotNull($this->nudge($this->project(), 'Bash', ['command' => 'cd web/core/modules/system && grep -rn hook_tokens .']), 'a search after a cd into core');
    foreach ([
      'grep -rn "core" web/modules/custom',
      'cat web/core/lib/Drupal.php',
      'ddev drush status',
      // P8 run 1: a listing piped into grep searches no code of vendor's.
      'cd /x/site; ddev drush cget system.theme; ls vendor/bin | grep -i droost',
      'cat vendor/composer/installed.json | grep droost/workflow',
    ] as $command) {
      $root = $this->project();
      $this->assertNull($this->nudge($root, 'Bash', ['command' => $command]), $command);
      $this->assertSame([], $this->rows($root), 'a call that earns nothing writes no row');
    }
  }

  /**
   * A new file of any shape earns no note: how to build is the agent's.
   */
  public function testNoNoteForHowFilesAreBuilt(): void {
    $root = $this->project();
    $this->assertNull($this->nudge($root, 'Write', ['file_path' => $root . '/web/modules/custom/kc/src/Form/AForm.php'], ['type' => 'create']));
  }

  /**
   * The spec is noted until droost's knowledge has been asked.
   */
  public function testTheSpecIsNotedUntilThePlanHasLooked(): void {
    $root = $this->project('plan');
    $spec = ['file_path' => $root . '/droost/droost-workflow/tmp-spec-t1.md'];
    $note = $this->nudge($root, 'Write', $spec);
    $this->assertNotNull($note);
    $this->assertStringContainsString('droost code:wiki', $note);
    $this->assertStringContainsString('droost code:tree', $note);
    $this->assertNull($this->nudge($root, 'Edit', $spec), 'not every edit');
    $full = $this->project('plan');
    $this->assertNotNull($this->nudge($full, 'Write', ['file_path' => $full . '/droost/droost-workflow/spec-t1.md']), 'the full spec at high and above is a spec too (S5-5)');

    $looked = $this->project('plan');
    file_put_contents(
      $looked . '/droost/droost-workflow/tool-calls.jsonl',
      (string) json_encode(['tool' => 'code:symbol', 'outcome' => 'ok', 'run' => 'run-n', 'phase' => 'plan']) . "\n",
    );
    $this->assertNull($this->nudge($looked, 'Write', ['file_path' => $looked . '/droost/droost-workflow/tmp-spec-t1.md']));
  }

  /**
   * A failed call, another run's, or a write tool is not looking.
   */
  public function testOnlyThisRunsSuccessfulKnowledgeCallCounts(): void {
    $root = $this->project('plan');
    file_put_contents($root . '/droost/droost-workflow/tool-calls.jsonl', implode("\n", [
      json_encode(['tool' => 'search:code', 'outcome' => 'fail', 'run' => 'run-n']),
      json_encode(['tool' => 'search:code', 'outcome' => 'ok', 'run' => 'run-other']),
      // A retired Drupal-era tool id is not a knowledge call either.
      json_encode(['tool' => 'droost_search', 'outcome' => 'ok', 'run' => 'run-n']),
    ]) . "\n");
    $this->assertNotNull($this->nudge($root, 'Write', ['file_path' => $root . '/droost/droost-workflow/tmp-spec-t1.md']));
  }

  /**
   * The guard and the Claude mod say each note in the same words.
   *
   * A record reads the same whichever of the two said it, so the two copies
   * are compared here rather than trusted to stay in step.
   */
  public function testTheModAndTheGuardSayTheSame(): void {
    $base = dirname(__DIR__, 2);
    $guard = (string) file_get_contents($base . '/pack/hooks/droost-workflow-guard.php');
    $mod = (string) file_get_contents($base . '/claude-plugins/droost-guard/hooks/register.js');
    foreach (['ground', 'traverse'] as $kind) {
      $this->assertSame(1, preg_match("/'" . $kind . "' => '((?:[^'\\\\]|\\\\.)*)'/", $guard, $php), $kind . ' in the guard');
      $this->assertSame(1, preg_match("/\\b" . $kind . ": '((?:[^'\\\\]|\\\\.)*)'/", $mod, $js), $kind . ' in the mod');
      $this->assertSame(stripslashes($php[1]), stripslashes($js[1]), $kind);
    }
  }

  /**
   * The settings wire the nudge after the call, never before it.
   */
  public function testTheNudgeIsWiredAfterTheCall(): void {
    $this->assertStringContainsString("['PostToolUse', [", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Pack/PackMaterializer.php'));
    $this->assertStringContainsString("\$guard . ' nudge --harness=claude'", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Pack/PackMaterializer.php'));
  }

}
