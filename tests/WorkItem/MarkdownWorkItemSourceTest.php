<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\WorkItem;

use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkItem\MarkdownWorkItemSource;
use Droost\Workflow\WorkItem\WorkItemError;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tickets as markdown files, in the format druplit already keeps its own in.
 *
 * The two fixtures are verbatim copies of druplit's TICKET-89 (in closed/,
 * `status: done`, an extra `ticket:` key) and TICKET-169 (in open/, `status:
 * open`). They must read as they stand, and a move must change their status
 * line and nothing else.
 */
#[CoversClass(MarkdownWorkItemSource::class)]
final class MarkdownWorkItemSourceTest extends WorkflowTestCase {

  private const OPEN = 'open/TICKET-169-the-container-stops-granting-root.md';

  private const CLOSED = 'closed/TICKET-89-reap-superseded-manager-runs.md';

  /**
   * Druplit's tickets read as they stand, legacy statuses mapped.
   */
  public function testDruplitTicketsReadUnchanged(): void {
    [, $source] = $this->project();

    $open = $source->get('TICKET-169');
    $this->assertNotNull($open);
    $this->assertSame('ready', $open->status, 'status: open reads as ready');
    $this->assertSame(169, $open->number);
    $this->assertSame('bug', $open->type);
    $this->assertSame('TICKET-169-the-container-stops-granting-root', $open->title);
    $this->assertSame('droost/tickets/' . self::OPEN, $open->path);
    $this->assertSame(['created', 'intake', 'pipeline_spec'], array_keys($open->extra));
    $this->assertSame('2026-09-28', $open->extra['created'], 'a date stays the date the file wrote, not a timestamp');
    $this->assertStringStartsWith("\n# TICKET-169-the-container-stops-granting-root\n", $open->body);

    $closed = $source->get('TICKET-89');
    $this->assertNotNull($closed);
    $this->assertSame('done', $closed->status);
    $this->assertSame(
      ['ticket', 'created', 'intake', 'pipeline_spec'],
      array_keys($closed->extra),
      'every other frontmatter key is kept, in the order the file holds them',
    );
    $this->assertSame('cc6c6cec-5e09-445f-8ae0-2d6177952933', $closed->extra['ticket']);
    $this->assertNull($closed->extra['intake']);

    $this->assertSame(['TICKET-89', 'TICKET-169'], array_map(static fn ($item) => $item->id, $source->list()));
    $this->assertSame(['TICKET-169'], array_map(static fn ($item) => $item->id, $source->list('ready')));
    $this->assertSame(['TICKET-89'], array_map(static fn ($item) => $item->id, $source->list('closed')), 'a legacy state filters as its new name');
    $this->assertSame('TICKET-169', $source->get('169')?->id, 'a bare number names a ticket');
    $this->assertNull($source->get('TICKET-4040'));
    $this->assertNull($source->get('OTHER-169'));
  }

  /**
   * A move rewrites the status line and nothing else, byte for byte.
   */
  public function testMoveChangesOnlyTheStatusLine(): void {
    [$dir, $source] = $this->project();
    $before = (string) file_get_contents($dir . '/' . self::OPEN);

    $moved = $source->transition('TICKET-169', 'in_progress', 'run started');
    $this->assertSame('in_progress', $moved->status);
    $after = (string) file_get_contents($dir . '/' . self::OPEN);
    $this->assertSame(
      str_replace("\nstatus: open\n", "\nstatus: in_progress\n", $before),
      $after,
      'only the status line changed',
    );
    $this->assertSame(1, substr_count($before, "\nstatus: open\n"), 'and the fixture had exactly one to change');

    // Crossing done moves the file to closed/, still byte for byte.
    $done = $source->transition('TICKET-169', 'done', 'accepted');
    $this->assertFileDoesNotExist($dir . '/' . self::OPEN);
    $closed = $dir . '/closed/' . basename(self::OPEN);
    $this->assertSame('droost/tickets/closed/' . basename(self::OPEN), $done->path);
    $this->assertSame(str_replace("\nstatus: open\n", "\nstatus: done\n", $before), file_get_contents($closed));

    // And back out of done, to open/.
    $source->transition('TICKET-169', 'review', 'reopened');
    $this->assertFileExists($dir . '/' . self::OPEN);
    $this->assertFileDoesNotExist($closed);

    // A body that quotes a status line keeps it.
    $quoting = $dir . '/open/TICKET-7-quotes.md';
    file_put_contents($quoting, "---\ntitle: Q\nstatus: ready\nticket_number: 7\n---\n\nstatus: ready is what the body says\n");
    $source->transition('TICKET-7', 'review', 'x');
    $this->assertSame("---\ntitle: Q\nstatus: review\nticket_number: 7\n---\n\nstatus: ready is what the body says\n", file_get_contents($quoting));
  }

  /**
   * A write keeps the target's mode, in place and across a move.
   */
  public function testMoveKeepsTheFileMode(): void {
    [$dir, $source] = $this->project();
    chmod($dir . '/' . self::OPEN, 0640);

    $source->transition('TICKET-169', 'in_progress', 'x');
    clearstatcache();
    $this->assertSame(0640, fileperms($dir . '/' . self::OPEN) & 0777);

    $source->transition('TICKET-169', 'done', 'x');
    clearstatcache();
    $this->assertSame(0640, fileperms($dir . '/closed/' . basename(self::OPEN)) & 0777);
  }

  /**
   * A symlinked ticket or directory is refused, and so is a path out.
   */
  public function testSymlinksAndEscapesAreRefused(): void {
    [$dir, $source] = $this->project();
    $elsewhere = $this->makeRoot() . '/TICKET-5-elsewhere.md';
    file_put_contents($elsewhere, "---\ntitle: E\nstatus: ready\nticket_number: 5\n---\n");
    symlink($elsewhere, $dir . '/open/TICKET-5-elsewhere.md');
    try {
      $source->transition('TICKET-5', 'review', 'x');
      $this->fail('a symlinked ticket was written through');
    }
    catch (WorkItemError $e) {
      $this->assertStringContainsString('symlink', $e->getMessage());
    }
    $this->assertStringContainsString('status: ready', (string) file_get_contents($elsewhere), 'the target was not touched');

    $root = $this->makeRoot();
    $real = $this->makeRoot();
    mkdir($real . '/open');
    symlink($real, $root . '/tickets');
    foreach (['tickets', '../tickets', '/etc'] as $refused) {
      try {
        new MarkdownWorkItemSource($refused, 'TICKET', $root);
        $this->fail($refused . ' was accepted');
      }
      catch (WorkItemError $e) {
        $this->assertStringContainsString('refusing', $e->getMessage());
      }
    }

    // A state directory that is a symlink is refused when it is used.
    $root = $this->makeRoot();
    mkdir($root . '/t');
    symlink($real . '/open', $root . '/t/open');
    try {
      (new MarkdownWorkItemSource('t', 'TICKET', $root))->list();
      $this->fail('a symlinked open/ was read');
    }
    catch (WorkItemError $e) {
      $this->assertStringContainsString('symlink', $e->getMessage());
    }
  }

  /**
   * A file missing a required key, or in no known state, is named.
   */
  public function testUnreadableTicketsAreNamed(): void {
    [$dir, $source] = $this->project();
    file_put_contents($dir . '/open/TICKET-3-no-status.md', "---\ntitle: No status\nticket_number: 3\n---\n");
    try {
      $source->get('TICKET-3');
      $this->fail('a ticket with no status was read');
    }
    catch (WorkItemError $e) {
      $this->assertStringContainsString('droost/tickets/open/TICKET-3-no-status.md', $e->getMessage());
      $this->assertStringContainsString('no status', $e->getMessage());
    }

    // Druplit's one `shelved` ticket is in no state of the five: named, not
    // guessed at.
    file_put_contents($dir . '/open/TICKET-3-no-status.md', "---\ntitle: Shelved\nstatus: shelved\nticket_number: 3\n---\n");
    try {
      $source->list();
      $this->fail('a shelved ticket was read as some state');
    }
    catch (WorkItemError $e) {
      $this->assertStringContainsString('"shelved"', $e->getMessage());
      $this->assertStringContainsString('TICKET-3-no-status.md', $e->getMessage());
    }

    file_put_contents($dir . '/open/TICKET-3-no-status.md', "no frontmatter at all\n");
    $this->expectException(WorkItemError::class);
    $this->expectExceptionMessage('no YAML frontmatter');
    $source->get('3');
  }

  /**
   * A new ticket is numbered past every file's name; a frontmatter is not.
   */
  public function testNewTicketIsNumberedPastTheHighest(): void {
    [$dir, $source] = $this->project();
    // A name and a frontmatter that disagree, as 51 of druplit's do: the
    // frontmatter's is an old tracker's number, kept as an extra key, and
    // the name decides the ticket's number and the next one.
    file_put_contents($dir . '/closed/TICKET-170-x.md', "---\ntitle: X\nstatus: done\nticket_number: 172\n---\n");
    $forge = $source->get('TICKET-170');
    $this->assertNotNull($forge);
    $this->assertSame(170, $forge->number);
    $this->assertSame(['ticket_number' => 172], $forge->extra);

    $item = $source->create('Add the camp calendar: iCal', 'feature');
    $this->assertSame('TICKET-171', $item->id);
    $this->assertSame(171, $item->number);
    $this->assertSame('backlog', $item->status);
    $this->assertSame('Add the camp calendar: iCal', $item->title);
    $this->assertSame('droost/tickets/open/TICKET-171-add-the-camp-calendar-ical.md', $item->path);
    $this->assertSame(['created' => '2026-09-28'], $item->extra, 'a ticket_number that matches its name is its number, not an extra key');
    $written = (string) file_get_contents($dir . '/open/TICKET-171-add-the-camp-calendar-ical.md');
    $this->assertStringStartsWith("---\ntitle: 'Add the camp calendar: iCal'\nstatus: backlog\nticket_number: 171\ntype: feature\ncreated: 2026-09-28\n---\n", $written);
    $this->assertStringContainsString("\n## EARS Requirements\n", $written);
    $this->assertSame('TICKET-171', $source->get('TICKET-171')?->id, 'and it reads back');

    // Into an empty project, the directories are made.
    $fresh = new MarkdownWorkItemSource('droost/tickets', 'ITEM', $this->makeRoot(), static fn (): string => '2026-01-01');
    $this->assertSame('ITEM-1', $fresh->create('First', 'bug')->id);
    $this->assertSame([], (new MarkdownWorkItemSource('none', 'ITEM', $this->makeRoot()))->list(), 'no directory is no tickets');
  }

  /**
   * Druplit's historical names: a split ticket and a numberless one.
   *
   * A split keeps its letter in its id, so a number repeats across it; a
   * numberless ticket's id is its name and its number is NULL, and it sorts
   * last. A file named for the prefix in neither shape is refused by name.
   */
  public function testHistoricalNamesReadAndOthersAreRefused(): void {
    [$dir, $source] = $this->project();
    file_put_contents($dir . '/closed/TICKET-59-mailbox.md', "---\ntitle: TICKET-59-mailbox\nstatus: done\nticket_number: 61\n---\n");
    file_put_contents($dir . '/closed/TICKET-59b-mailbox-hardening.md', "---\ntitle: TICKET-59b-mailbox-hardening\nstatus: done\nticket_number: 64\n---\n");
    file_put_contents($dir . '/closed/TICKET-gate-hardening.md', "---\ntitle: TICKET-gate-hardening\nstatus: closed\nclosed: 2026-07-13\nforge_number: 3\n---\n");

    $split = $source->get('TICKET-59b');
    $this->assertNotNull($split);
    $this->assertSame(59, $split->number);
    $this->assertSame(['ticket_number' => 64], $split->extra);
    $this->assertSame('TICKET-59', $source->get('59')?->id, 'a bare number is the numbered ticket, not its split');

    $numberless = $source->get('TICKET-gate-hardening');
    $this->assertNotNull($numberless);
    $this->assertNull($numberless->number);
    $this->assertSame('done', $numberless->status);
    $this->assertSame(['closed' => '2026-07-13', 'forge_number' => 3], $numberless->extra, 'no ticket_number is asked of a name that has no number');

    $this->assertSame(
      ['TICKET-59', 'TICKET-59b', 'TICKET-89', 'TICKET-169', 'TICKET-gate-hardening'],
      array_map(static fn ($item) => $item->id, $source->list()),
    );
    $this->assertSame('TICKET-170', $source->create('Next', 'bug')->id, 'numberless ids number nothing');

    $moved = $source->transition('TICKET-59b', 'review', 'x');
    $this->assertSame('TICKET-59b', $moved->id);
    $this->assertFileExists($dir . '/open/TICKET-59b-mailbox-hardening.md');

    file_put_contents($dir . '/open/TICKET-12abc-x.md', "---\ntitle: T\nstatus: ready\nticket_number: 12\n---\n");
    try {
      $source->list();
      $this->fail('a misnamed ticket was skipped or read');
    }
    catch (WorkItemError $e) {
      $this->assertStringContainsString('droost/tickets/open/TICKET-12abc-x.md', $e->getMessage());
      $this->assertStringContainsString('its name is none of', $e->getMessage());
    }
    // Files that are not named for the prefix are not tickets at all.
    unlink($dir . '/open/TICKET-12abc-x.md');
    file_put_contents($dir . '/open/README.md', "# notes\n");
    file_put_contents($dir . '/open/TICKET-169-the-container-stops-granting-root.md.orig', 'x');
    $this->assertCount(6, $source->list());
  }

  /**
   * Only the five states are written, and a legacy one is not one of them.
   */
  public function testOnlyTheFiveStatesAreWritten(): void {
    [, $source] = $this->project();
    foreach (['closed', 'open', 'shelved', ''] as $state) {
      try {
        $source->transition('TICKET-169', $state, 'x');
        $this->fail($state . ' was written');
      }
      catch (WorkItemError $e) {
        $this->assertStringContainsString('unknown ticket state', $e->getMessage());
      }
    }
    $this->expectException(WorkItemError::class);
    $source->list('shelved');
  }

  /**
   * A project holding the two fixtures, and a source over it.
   *
   * @return array{string, \Droost\Workflow\WorkItem\MarkdownWorkItemSource}
   *   The ticket directory, absolute, and the source.
   */
  private function project(): array {
    $root = $this->makeRoot();
    $dir = $root . '/droost/tickets';
    foreach ([self::OPEN, self::CLOSED] as $file) {
      mkdir(dirname($dir . '/' . $file), 0755, TRUE);
      copy(__DIR__ . '/fixtures/' . $file, $dir . '/' . $file);
    }

    return [$dir, new MarkdownWorkItemSource('droost/tickets', 'TICKET', $root, static fn (): string => '2026-09-28')];
  }

}
