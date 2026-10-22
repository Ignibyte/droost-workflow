<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Panel;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\RuleFiles;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Panel\ActivityLog;
use Droost\Workflow\Panel\PanelSections;
use Droost\Workflow\Panel\PanelTabRunner;
use Droost\Workflow\Panel\RunPanel;
use Droost\Workflow\Tests\WorkflowTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The run panel the droost mod draws, and the project tabs that extend it.
 */
#[CoversClass(RunPanel::class)]
#[CoversClass(PanelSections::class)]
#[CoversClass(PanelTabRunner::class)]
#[CoversClass(ActivityLog::class)]
final class RunPanelTest extends WorkflowTestCase {

  /**
   * A pass that examined nothing, or a gate turned off, is not read as a pass.
   */
  public function testGatesTellPassesFromWhatIsNotOne(): void {
    $run = [
      'run_id' => 'run-1',
      'phases' => ['plan' => 'passed', 'code' => 'active', 'test' => 'pending', 'complete' => 'pending'],
      'current_phase' => 'code',
      'gate_reports' => [
        'code' => [
          'gates' => [
        ['gate' => 'phpcs', 'status' => 'passed', 'labelled' => FALSE, 'summary' => 'phpcs passed'],
        ['gate' => 'phpstan', 'status' => 'passed', 'labelled' => TRUE, 'summary' => 'nothing to analyse'],
        ['gate' => 'eslint', 'status' => 'off', 'labelled' => FALSE, 'summary' => 'off — by preset medium'],
        ['gate' => 'phpunit', 'status' => 'failed', 'labelled' => FALSE, 'summary' => '2 failed'],
          ],
        ],
      ],
    ];

    $document = RunPanel::document($run, [], [], [], [], '2026-10-21T00:00:00+00:00');
    $gates = $this->tab($document, 'gates');
    $tags = array_column($this->items($gates, 0), 'tag', 'text');

    $this->assertSame(['phpcs' => 'passed', 'phpstan' => 'examined nothing', 'eslint' => 'off', 'phpunit' => 'failed'], $tags);
    $this->assertSame('1/3', $gates['badge'], 'one measured pass of three gates that ran');
    $this->assertSame('running', $document['run']['state'] ?? NULL);
    $this->assertSame(['passed', 'active', 'pending', 'pending'], array_column($document['phases'], 'status'));
  }

  /**
   * A failed phase is a failed run, and a question is a hold.
   */
  public function testFailedAndWaitingRunsSaySo(): void {
    $failed = RunPanel::summary(['run_id' => 'r', 'phases' => ['code' => 'failed'], 'current_phase' => 'code']);
    $this->assertSame('failed', $failed['state']);

    $waiting = [
      'run_id' => 'r',
      'phases' => ['plan' => 'active'],
      'current_phase' => 'plan',
      'awaiting' => ['question' => 'Which rink?'],
    ];
    $document = RunPanel::document($waiting, [], [], [], [], 'now');
    $this->assertSame('waiting', $document['run']['state'] ?? NULL);
    $this->assertSame(['question' => 'Which rink?'], $document['hold']);
  }

  /**
   * The Activity tab is the agent's account, and says so.
   */
  public function testActivityIsTheAgentsOwnAccount(): void {
    $root = $this->makeRoot();
    $log = new ActivityLog($root);
    $this->assertTrue($log->append('Creating the plan type', '10 fields', 'run-1', '2026-10-21T17:02:00+00:00'));
    $this->assertTrue($log->append('Elsewhere', NULL, 'run-2', '2026-10-21T17:03:00+00:00'));

    $document = RunPanel::document(['run_id' => 'run-1', 'phases' => [], 'current_phase' => 'code'], [], [], $log->forRun('run-1'), [], 'now');
    $activity = $this->tab($document, 'activity');

    $items = $this->items($activity, 0);
    $this->assertSame('Creating the plan type', $items[0]['text'] ?? NULL);
    $this->assertSame('17:02', $items[0]['tag'] ?? NULL);
    $this->assertCount(1, $items, 'another run\'s line is not this run\'s');
    $this->assertStringContainsString('own account', (string) json_encode($activity['sections'][0]));
  }

  /**
   * A project's tab is cleaned: what does not fit is dropped and named.
   */
  public function testProjectSectionsAreCleaned(): void {
    $cleaned = PanelSections::clean([
      'badge' => '3/4',
      'sections' => [
        ['kind' => 'checklist', 'title' => 'AC', 'items' => [['text' => 'one', 'done' => TRUE], ['text' => '']]],
        ['kind' => 'html', 'text' => '<script>'],
        ['kind' => 'list', 'items' => [['text' => 'x', 'tone' => 'purple']]],
      ],
    ]);

    $this->assertSame('3/4', $cleaned['badge']);
    $this->assertCount(2, $cleaned['sections']);
    $this->assertSame([['text' => 'one', 'done' => TRUE, 'note' => NULL]], $cleaned['sections'][0]['items'] ?? NULL, 'an item with no text is dropped');
    $list = $cleaned['sections'][1]['items'] ?? NULL;
    $this->assertIsArray($list);
    $this->assertIsArray($list[0]);
    $this->assertNull($list[0]['tone'], 'an unknown tone is none');
    $this->assertSame(['section 2: kind must be one of text, markdown, fields, checklist, list'], $cleaned['dropped']);
    $this->assertSame([], PanelSections::clean([['kind' => 'text']])['sections'], 'a list is not a tab');
  }

  /**
   * A tab file runs with the run on stdin, and one that fails says how.
   */
  public function testTabFilesRunAndFailInWords(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/panel', 0755, TRUE);
    file_put_contents($root . '/droost/panel/ok.php', '<?php $in = json_decode(stream_get_contents(STDIN), TRUE); echo json_encode(["badge" => "1", "sections" => [["kind" => "text", "text" => "run " . ($in["run"]["id"] ?? "none") . " tab " . $in["tab"]]]]);');
    file_put_contents($root . '/droost/panel/bad.php', '<?php echo "not json";');
    file_put_contents($root . '/droost/panel/exits.php', '<?php fwrite(STDERR, "no ticket cached\n"); exit(3);');
    $runner = new PanelTabRunner($root);
    $tab = static fn (string $file): array => ['title' => 'T', 'run' => 'droost/panel/' . $file, 'timeout' => 5];

    $ok = $runner->run('ticket', $tab('ok.php'), ['id' => 'run-9']);
    $this->assertNull($ok['error']);
    $this->assertSame('run run-9 tab ticket', $ok['sections'][0]['text'] ?? NULL);

    $this->assertStringContainsString('not JSON', (string) $runner->run('t', $tab('bad.php'), NULL)['error']);
    $this->assertSame('droost/panel/exits.php exited 3: no ticket cached', $runner->run('t', $tab('exits.php'), NULL)['error']);
    $this->assertSame('droost/panel/gone.php is not there', $runner->run('t', $tab('gone.php'), NULL)['error']);
  }

  /**
   * Droost.yml declares the tabs; droost's own ids and bad files are refused.
   */
  public function testTabsAreDeclaredInTheLeverFile(): void {
    $root = $this->makeRootWithConfig("preset: low\npanel:\n  open: always\n  tabs:\n    ticket: { title: Ticket, run: droost/panel/ticket.ts, timeout: 3 }\n");
    $config = WorkflowConfig::load($root);
    $this->assertSame('always', $config->panelOpen);
    $this->assertSame(['ticket' => ['title' => 'Ticket', 'run' => 'droost/panel/ticket.ts', 'timeout' => 3]], $config->panelTabs);
    $this->assertArrayHasKey('droost/panel/ticket.ts', RuleFiles::hash($root, $config), 'frozen at begin with the other rule files');

    foreach ([
      "panel:\n  tabs:\n    gates: { title: G, run: droost/panel/g.php }\n" => 'droost\'s own tabs',
      "panel:\n  tabs:\n    t: { title: T, run: /etc/passwd }\n" => 'must name a file in the project',
      "panel:\n  tabs:\n    t: { title: T, run: droost/panel/t.php, colour: red }\n" => 'unknown option "colour"',
      "panel:\n  tabs:\n    t: { run: droost/panel/t.php }\n" => 'title',
      "panel:\n  open: sometimes\n" => 'must be auto, always or never',
    ] as $yaml => $expected) {
      try {
        WorkflowConfig::load($this->makeRootWithConfig("preset: low\n" . $yaml));
        $this->fail('accepted: ' . $yaml);
      }
      catch (ConfigError $e) {
        $this->assertStringContainsString($expected, $e->getMessage());
      }
    }
  }

  /**
   * The say and panel verbs, through the binary's dispatcher.
   */
  public function testSayAndPanelVerbs(): void {
    $root = $this->makeRootWithConfig("preset: low\n");
    $lines = [];
    $sink = function (string $line) use (&$lines): void {
      $lines[] = $line;
    };
    $dispatcher = new ArgvDispatcher($sink, $sink, static fn (): string => '2026-10-21T17:02:00+00:00', static fn (): string => 'run-x');

    $said = $dispatcher->dispatch(['say', 'Building the View', '--detail=views.view.plans', '--project=' . $root], $root);
    $this->assertSame(0, $said);
    $this->assertSame(
      [['at' => '2026-10-21T17:02:00+00:00', 'text' => 'Building the View', 'detail' => 'views.view.plans']],
      (new ActivityLog($root))->forRun(NULL),
    );
    $this->assertSame(2, $dispatcher->dispatch(['say', '--project=' . $root], $root), 'nothing to say is a usage error');

    $lines = [];
    $this->assertSame(0, $dispatcher->dispatch(['panel', '--json', '--project=' . $root], $root));
    $document = json_decode(implode("\n", $lines), TRUE);
    $this->assertIsArray($document);
    $this->assertSame(RunPanel::SCHEMA, $document['schema'] ?? NULL);
    $this->assertNull($document['run'] ?? NULL);
  }

  /**
   * The spec tab draws the spec itself, and Activity what the guard saw.
   */
  public function testSpecIsReadAndActivityShowsWhatWasDone(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    $row = static fn (string $run, string $tool, string $mode, string $verdict, ?string $subject, string $at): string => (string) json_encode(
      [
        'run' => $run,
        'tool' => $tool,
        'mode' => $mode,
        'verdict' => $verdict,
        'subject' => $subject,
        'at' => '2026-10-21T' . $at . '+00:00',
      ],
    );
    file_put_contents($root . '/droost/droost-workflow/guard-calls.jsonl', implode("\n", [
      $row('run-1', 'Edit', 'pre-tool-use', 'allow', 'web/x.css', '17:01:00'),
      $row('run-1', 'Bash', 'operator-commands', 'refuse', 'droost workflow:bypass', '17:02:00'),
      $row('run-1', 'Bash', 'pre-tool-use', 'allow', 'droost workflow:bypass', '17:02:00'),
      $row('run-2', 'Write', 'pre-tool-use', 'allow', NULL, '17:03:00'),
    ]) . "\n");
    $actions = (new ActivityLog($root))->actions('run-1');
    $this->assertCount(2, $actions, 'one shell call asked twice is one call; another run\'s is not this run\'s');
    $this->assertTrue($actions[1]['refused']);

    $run = [
      'run_id' => 'run-1',
      'phases' => [],
      'current_phase' => 'code',
      'spec' => 'droost/droost-workflow/tmp-spec.md',
    ];
    $document = RunPanel::document($run, [], [], [], [], 'now', "# R05\n\nThe contact page.", $actions, 'always');
    $this->assertSame('always', $document['open']);
    $spec = $this->tab($document, 'spec');
    $this->assertSame(['kind' => 'markdown', 'title' => NULL, 'text' => "# R05\n\nThe contact page."], $spec['sections'][0]);
    $did = $this->items($this->tab($document, 'activity'), 1);
    $this->assertSame('Edit · web/x.css', $did[0]['text'] ?? NULL);
    $this->assertSame('refused', $did[1]['tag'] ?? NULL);
  }

  /**
   * One tab of a panel document, by id.
   *
   * @param array{tabs: list<array{id: string, badge: string|null, sections: list<array<string, mixed>>}>} $document
   *   The document.
   * @param string $id
   *   The tab's id.
   *
   * @return array{id: string, badge: string|null, sections: list<array<string, mixed>>}
   *   The tab.
   */
  private function tab(array $document, string $id): array {
    foreach ($document['tabs'] as $tab) {
      if ($tab['id'] === $id) {
        return $tab;
      }
    }
    $this->fail('no tab ' . $id);
  }

  /**
   * The items of one section of a tab.
   *
   * @param array{sections: list<array<string, mixed>>} $tab
   *   The tab.
   * @param int $section
   *   The section's index.
   *
   * @return list<array<mixed>>
   *   The items.
   */
  private function items(array $tab, int $section): array {
    $items = $tab['sections'][$section]['items'] ?? NULL;
    $this->assertIsArray($items);
    $out = [];
    foreach ($items as $item) {
      $this->assertIsArray($item);
      $out[] = $item;
    }
    return $out;
  }

}
