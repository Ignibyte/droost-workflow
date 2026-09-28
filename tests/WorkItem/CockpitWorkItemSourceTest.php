<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\WorkItem;

use Droost\Workflow\WorkItem\CockpitHttp;
use Droost\Workflow\WorkItem\CockpitWorkItemSource;
use Droost\Workflow\WorkItem\WorkItemError;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Cockpit mode's tickets, through the contract's stub.
 */
#[CoversClass(CockpitWorkItemSource::class)]
#[CoversClass(CockpitHttp::class)]
final class CockpitWorkItemSourceTest extends CockpitStubCase {

  /**
   * A ticket fetched is bound and cached, and the cache stands in offline.
   *
   * A ticket never seen is never bound.
   */
  public function testGetCachesAndBindsFromTheCacheOffline(): void {
    [$base] = $this->stub(['items' => ['TICKET-12' => self::item('TICKET-12')]]);
    $stateDir = $this->makeRoot();
    $source = new CockpitWorkItemSource(new CockpitHttp(), $base, self::TOKEN, $stateDir);

    $item = $source->get('TICKET-12');
    $this->assertNotNull($item);
    $this->assertSame('droost_cockpit', $item->source, 'the client names its source, whatever the server sent');
    $this->assertSame('ready', $item->status);
    $this->assertSame(['priority' => 'high'], $item->extra);
    $cache = $stateDir . '/work-item-TICKET-12.json';
    $this->assertFileExists($cache);
    $this->assertStringNotContainsString(self::TOKEN, (string) file_get_contents($cache));
    $this->assertNull($source->get('TICKET-4040'), 'a 404 is no ticket');

    // The cockpit gone: the cached ticket binds, one never seen does not.
    $dead = 'http://127.0.0.1:' . $this->freePort() . '/work-items/v1';
    $offline = new CockpitWorkItemSource(new CockpitHttp(), $dead, self::TOKEN, $stateDir);
    $this->assertSame('TICKET-12', $offline->get('TICKET-12')?->id);
    try {
      $offline->get('TICKET-13');
      $this->fail('a ticket never seen was bound');
    }
    catch (WorkItemError $e) {
      $this->assertStringContainsString('TICKET-13', $e->getMessage());
      $this->assertStringContainsString('127.0.0.1', $e->getMessage(), 'it names the cockpit it could not reach');
      $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
    }
  }

  /**
   * List, list by state, and file a ticket, through the API.
   */
  public function testListAndCreate(): void {
    [$base, $statePath] = $this->stub([
      'items' => [
        'TICKET-12' => self::item('TICKET-12'),
        'TICKET-13' => self::item('TICKET-13', 'backlog'),
      ],
      'next_number' => 14,
    ]);
    $source = new CockpitWorkItemSource(new CockpitHttp(), $base, self::TOKEN, $this->makeRoot());

    $this->assertSame(['TICKET-12', 'TICKET-13'], array_map(static fn ($i) => $i->id, $source->list()));
    $this->assertSame(['TICKET-13'], array_map(static fn ($i) => $i->id, $source->list('backlog')));

    $filed = $source->create('A follow-up', 'bug');
    $this->assertSame('TICKET-14', $filed->id);
    $this->assertSame('backlog', $filed->status);
    $this->assertArrayHasKey('TICKET-14', (array) ($this->stubState($statePath)['items'] ?? []));

    $this->expectException(WorkItemError::class);
    $this->expectExceptionMessage('move tickets in the cockpit');
    $source->transition('TICKET-12', 'done', 'x');
  }

  /**
   * Only the cockpit's origin is ever asked: no redirect, no other scheme.
   */
  public function testNoRedirectAndNoOtherScheme(): void {
    [$other, $otherState] = $this->stub();
    [$base, $statePath] = $this->stub(['redirect_to' => $other . '/items/TICKET-12']);
    $source = new CockpitWorkItemSource(new CockpitHttp(), $base, self::TOKEN, $this->makeRoot());
    try {
      $source->get('TICKET-12');
      $this->fail('a redirect was followed or bound');
    }
    catch (WorkItemError $e) {
      $this->assertStringContainsString('302', $e->getMessage());
    }
    $this->assertCount(1, (array) $this->stubState($statePath)['requests']);
    $this->assertSame([], $this->stubState($otherState)['requests'] ?? [], 'the redirect target got nothing');

    $ftp = new CockpitHttp();
    $refused = $ftp->request('GET', 'ftp://127.0.0.1/items/x', NULL, self::TOKEN);
    $this->assertSame(0, $refused['status']);
    $this->assertSame('the cockpit URL must be http or https', $refused['error']);
  }

  /**
   * An answer for another ticket is never bound, nor cached, under this id.
   */
  public function testAnAnswerForAnotherTicketIsRefused(): void {
    [$base] = $this->stub(['items' => ['TICKET-12' => self::item('TICKET-99')]]);
    $stateDir = $this->makeRoot();
    $source = new CockpitWorkItemSource(new CockpitHttp(), $base, self::TOKEN, $stateDir);

    try {
      $source->get('TICKET-12');
      $this->fail('a ticket was bound from an answer about another');
    }
    catch (WorkItemError $e) {
      $this->assertStringContainsString('it answered with TICKET-99', $e->getMessage());
    }
    $this->assertFileDoesNotExist($stateDir . '/work-item-TICKET-12.json');
  }

  /**
   * An unset variable names the variable, never a value.
   */
  public function testMissingEnvironmentIsNamed(): void {
    $source = new CockpitWorkItemSource(new CockpitHttp(), '', '', $this->makeRoot(), 'the environment variable COCKPIT_URL is not set');
    $this->expectException(WorkItemError::class);
    $this->expectExceptionMessage('COCKPIT_URL is not set');
    $source->get('TICKET-12');
  }

}
