<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Cli;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\State\RunState;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The `ticket` verb and `run --ticket`, through the standalone binary.
 *
 * `list`, `show` and `new` are anyone's; `move` refuses without a terminal,
 * like the other operator verbs. With no built-in source configured, `ticket`
 * says so and a plain `run` binds nothing, exactly as before.
 */
final class TicketVerbTest extends WorkflowTestCase {

  /**
   * List, show and new work for any caller, and new numbers past the rest.
   */
  public function testListShowAndNew(): void {
    $root = $this->project("work_item:\n  provider: markdown\n");

    [$code, $out] = $this->dispatch(['ticket', 'list'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, $out);
    $listed = json_decode($out, TRUE);
    $this->assertIsArray($listed);
    $this->assertIsArray($listed['tickets'] ?? NULL);
    $this->assertSame(['TICKET-89', 'TICKET-169'], array_column($listed['tickets'], 'id'));

    [$code, $out] = $this->dispatch(['ticket', 'list', '--status=ready'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_OK, $code);
    $ready = json_decode($out, TRUE);
    $this->assertIsArray($ready);
    $this->assertIsArray($ready['tickets'] ?? NULL);
    $this->assertSame(['TICKET-169'], array_column($ready['tickets'], 'id'));

    [$code, $out] = $this->dispatch(['ticket', 'show', 'TICKET-89'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, $out);
    $shown = json_decode($out, TRUE);
    $this->assertIsArray($shown);
    $this->assertSame('done', $shown['status']);
    $this->assertIsArray($shown['extra'] ?? NULL);
    $this->assertSame('cc6c6cec-5e09-445f-8ae0-2d6177952933', $shown['extra']['ticket'] ?? NULL);
    $this->assertIsString($shown['body'] ?? NULL);
    $this->assertStringContainsString('# TICKET-89-reap-superseded-manager-runs', $shown['body']);

    [$code, $out] = $this->dispatch(['ticket', 'new', '--title=A follow-up', '--type=bug'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, $out);
    $filed = json_decode($out, TRUE);
    $this->assertIsArray($filed);
    $this->assertSame('TICKET-170', $filed['id'], 'one past the highest across open/ and closed/');
    $this->assertSame('backlog', $filed['status']);
    $this->assertFileExists($root . '/droost/tickets/open/TICKET-170-a-follow-up.md');

    [$code, $out] = $this->dispatch(['ticket', 'show', 'TICKET-4040'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);
    $this->assertStringContainsString('no ticket TICKET-4040', $out);
  }

  /**
   * Moving a ticket is the operator's, and needs a terminal.
   */
  public function testMoveRefusesWithoutTerminal(): void {
    $root = $this->project("work_item:\n  provider: markdown\n");
    $file = $root . '/droost/tickets/open/TICKET-169-the-container-stops-granting-root.md';
    $before = (string) file_get_contents($file);

    [$code, $out] = $this->dispatch(['ticket', 'move', 'TICKET-169', 'done'], $root);

    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);
    $this->assertStringContainsString('droost-workflow ticket move is the operator\'s command', $out);
    $this->assertSame($before, file_get_contents($file), 'and nothing moved');

    [$code] = $this->dispatch(['ticket'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, 'a bare ticket names its subcommands');
  }

  /**
   * A `run --ticket` binds, in both spellings.
   */
  public function testRunTicketBinds(): void {
    $root = $this->project("work_item:\n  provider: markdown\n");
    $this->dispatch(['run', '--ticket', 'TICKET-169'], $root);

    $state = $this->stateOf($root);
    $this->assertNotNull($state);
    $this->assertSame('TICKET-169', $state->workItem['id'] ?? NULL);

    [$code, $out] = $this->dispatch(['run', '--ticket'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);
    $this->assertStringContainsString('--ticket needs an id', $out);
  }

  /**
   * Another provider is metadata only: no source, and nothing bound.
   */
  public function testNoBuiltInSourceChangesNothing(): void {
    $root = $this->project("work_item:\n  provider: jira\n  projects: [PROJ]\n");

    [$code, $out] = $this->dispatch(['ticket', 'list'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);
    $this->assertStringContainsString('no work-item source is configured', $out);

    [$code, $out] = $this->dispatch(['run', '--ticket=TICKET-169'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);
    $this->assertStringContainsString('no work-item source is configured', $out);
    $this->assertNull($this->stateOf($root), 'a refused binding begins no run');

    $this->dispatch(['run'], $root);
    $state = $this->stateOf($root);
    $this->assertNotNull($state);
    $this->assertNull($state->workItem, 'run without --ticket binds nothing');
  }

  /**
   * A project with druplit's two fixture tickets.
   *
   * @param string $workItem
   *   The lever file's work_item block.
   *
   * @return string
   *   The root.
   */
  private function project(string $workItem): string {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\nseekers:\n  on: false\n" . $workItem);
    $fixtures = [
      'open/TICKET-169-the-container-stops-granting-root.md',
      'closed/TICKET-89-reap-superseded-manager-runs.md',
    ];
    foreach ($fixtures as $file) {
      mkdir(dirname($root . '/droost/tickets/' . $file), 0755, TRUE);
      copy(dirname(__DIR__) . '/WorkItem/fixtures/' . $file, $root . '/droost/tickets/' . $file);
    }
    return $root;
  }

  /**
   * Runs the dispatcher, capturing both streams.
   *
   * @param list<string> $argv
   *   The arguments.
   * @param string $cwd
   *   The working directory.
   *
   * @return array{int, string}
   *   The exit code and everything printed.
   */
  private function dispatch(array $argv, string $cwd): array {
    $lines = [];
    $sink = function (string $line) use (&$lines): void {
      $lines[] = $line;
    };
    $dispatcher = new ArgvDispatcher(
      $sink,
      $sink,
      static fn (): string => '2026-09-28T00:00:00+00:00',
      static fn (): string => 'run-ticket',
    );

    return [$dispatcher->dispatch($argv, $cwd), implode("\n", $lines)];
  }

  /**
   * The run as it stands on disk now.
   *
   * @param string $root
   *   The project.
   *
   * @return \Droost\Workflow\State\RunState|null
   *   The run, or NULL when there is none.
   *
   * @phpstan-impure
   */
  private function stateOf(string $root): ?RunState {
    return (new RunStateStore($root))->load();
  }

}
