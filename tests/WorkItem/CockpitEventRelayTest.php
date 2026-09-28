<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\WorkItem;

use Droost\Workflow\Event\RunEventLog;
use Droost\Workflow\WorkItem\CockpitEventRelay;
use Droost\Workflow\WorkItem\CockpitHttp;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The relay: the log is the outbox, a cursor its only state.
 */
#[CoversClass(CockpitEventRelay::class)]
final class CockpitEventRelayTest extends CockpitStubCase {

  /**
   * Two hundred and fifty events go in three batches; the cursor follows.
   */
  public function testEventsGoInBatchesAndTheCursorFollows(): void {
    [$base, $statePath] = $this->stub();
    $stateDir = $this->withEvents(250);
    $relay = new CockpitEventRelay(new CockpitHttp(), $base, self::TOKEN, $stateDir);

    $result = $relay->flush();

    $this->assertSame(['attempted' => TRUE, 'delivered' => 250, 'cursor' => 250, 'pending' => 0, 'error' => NULL], $result);
    $posts = array_values(array_filter((array) $this->stubState($statePath)['requests'], static fn ($r): bool => is_array($r) && $r['path'] === '/work-items/v1/events'));
    $this->assertCount(3, $posts, 'at most 100 events a request');
    $this->assertCount(250, (array) $this->stubState($statePath)['events']);
    $this->assertSame("250\n", file_get_contents($stateDir . '/' . CockpitEventRelay::CURSOR));
    $this->assertSame(['cursor' => 250, 'pending' => 0, 'last_error' => NULL], $relay->status());

    // Nothing new: nothing sent.
    $this->assertFalse($relay->flush()['attempted']);
  }

  /**
   * A batch never passes 256 KiB, whatever its count.
   */
  public function testBatchesStayUnderTheByteLimit(): void {
    [$base, $statePath] = $this->stub();
    $stateDir = $this->withEvents(12, str_repeat('x', 60000));
    $result = (new CockpitEventRelay(new CockpitHttp(), $base, self::TOKEN, $stateDir))->flush();

    $this->assertSame(12, $result['delivered']);
    $posts = array_filter((array) $this->stubState($statePath)['requests'], static fn ($r): bool => is_array($r) && $r['path'] === '/work-items/v1/events');
    $this->assertGreaterThanOrEqual(3, count($posts), 'twelve 60 KB events need at least three requests');
  }

  /**
   * An event no request can carry goes as a stand-in, never as a wedge.
   */
  public function testAnEventOverTheLimitGoesAsStandIn(): void {
    [$base, $statePath] = $this->stub();
    $stateDir = $this->withEvents(1);
    (new RunEventLog($stateDir))->append('run-a', 'TICKET-12', 'phase.attempted', [
      'phase' => 'code',
      'attempt' => 2,
      'report' => NULL,
      'pad' => str_repeat('x', CockpitEventRelay::MAX_BYTES),
    ]);
    (new RunEventLog($stateDir))->append('run-a', 'TICKET-12', 'phase.ended', ['phase' => 'code']);
    $logged = file($stateDir . '/events.jsonl', FILE_IGNORE_NEW_LINES) ?: [];

    $result = (new CockpitEventRelay(new CockpitHttp(), $base, self::TOKEN, $stateDir))->flush();

    $this->assertSame(['attempted' => TRUE, 'delivered' => 3, 'cursor' => 3, 'pending' => 0, 'error' => NULL], $result);
    $stored = array_values((array) $this->stubState($statePath)['events']);
    $this->assertCount(3, $stored);
    $big = json_decode($logged[1], TRUE);
    $this->assertIsArray($big);
    $this->assertIsArray($stored[1]);
    $this->assertSame($big['event_id'], $stored[1]['event_id']);
    $this->assertSame(['omitted_bytes' => strlen($logged[1])], $stored[1]['payload']);
    $this->assertSame(json_decode($logged[0], TRUE), $stored[0], 'an event under the limit goes as logged');
    $this->assertSame(json_decode($logged[2], TRUE), $stored[2]);
    $this->assertStringContainsString(str_repeat('x', 1000), $logged[1], 'the log keeps the whole event');
  }

  /**
   * A dead cockpit: cursor unmoved, one warning, silence after, one timeout.
   */
  public function testDeadCockpitCostsOneTryAndSaysSoOnce(): void {
    $stateDir = $this->withEvents(5);
    $warnings = [];
    $dead = 'http://127.0.0.1:' . $this->freePort() . '/work-items/v1';
    $relay = new CockpitEventRelay(new CockpitHttp(), $dead, self::TOKEN, $stateDir, NULL, static function (string $line) use (&$warnings): void {
      $warnings[] = $line;
    });

    $started = microtime(TRUE);
    $first = $relay->flush();
    $second = $relay->flush();
    $elapsed = microtime(TRUE) - $started;

    $this->assertTrue($first['attempted']);
    $this->assertSame(0, $first['cursor']);
    $this->assertSame(5, $first['pending']);
    $this->assertNotNull($first['error']);
    $this->assertFalse($second['attempted'], 'silenced after the first failure');
    $this->assertCount(1, $warnings);
    $this->assertLessThan(CockpitHttp::TIMEOUT + 2, $elapsed, 'no more than one timeout, however many flushes');
    $this->assertFileDoesNotExist($stateDir . '/' . CockpitEventRelay::CURSOR);
    $this->assertSame(5, $relay->status()['pending']);
    $this->assertNotNull($relay->status()['last_error']);
  }

  /**
   * A 503: the same, and its reason kept for status.
   */
  public function testUnavailableCockpitKeepsItsReason(): void {
    [$base] = $this->stub(['fail' => TRUE]);
    $stateDir = $this->withEvents(3);
    $warnings = [];
    $relay = new CockpitEventRelay(new CockpitHttp(), $base, self::TOKEN, $stateDir, NULL, static function (string $line) use (&$warnings): void {
      $warnings[] = $line;
    });

    $result = $relay->flush();

    $this->assertSame(0, $result['cursor']);
    $this->assertIsString($result['error']);
    $this->assertStringContainsString('503', $result['error']);
    $this->assertStringContainsString('down for maintenance', $result['error']);
    $this->assertStringContainsString('503', (string) $relay->status()['last_error']);
    $this->assertCount(1, $warnings);
  }

  /**
   * A server that echoes the credential: the echo is cut from every trace.
   */
  public function testAnEchoedTokenIsCutFromTheError(): void {
    [$base] = $this->stub(['echo_token' => TRUE]);
    $stateDir = $this->withEvents(2);
    $warnings = [];
    $result = (new CockpitEventRelay(new CockpitHttp(), $base, self::TOKEN, $stateDir, NULL, static function (string $line) use (&$warnings): void {
      $warnings[] = $line;
    }))->flush();

    $this->assertStringContainsString('answered 500: could not parse Bearer [token]', (string) $result['error']);
    $this->assertCount(1, $warnings);
    $this->assertStringNotContainsString(self::TOKEN, $warnings[0]);
    $this->assertStringNotContainsString(self::TOKEN, (string) file_get_contents($stateDir . '/' . CockpitEventRelay::LAST_ERROR));
  }

  /**
   * A token that would write a header of its own is never sent.
   */
  public function testTokenWithLineBreakIsNeverSent(): void {
    [$base, $statePath] = $this->stub();
    $response = (new CockpitHttp())->request('GET', $base . '/items', NULL, self::TOKEN . "\r\nX-Injected: 1");

    $this->assertSame(0, $response['status']);
    $this->assertSame('the cockpit token is empty or holds a space or control character', $response['error']);
    $this->assertSame([], (array) ($this->stubState($statePath)['requests'] ?? []), 'nothing reached the server');
  }

  /**
   * A lost answer: the batch comes again and counts as duplicates.
   */
  public function testLostAnswerIsResentAsDuplicates(): void {
    [$base, $statePath] = $this->stub(['drop_next' => TRUE]);
    $stateDir = $this->withEvents(7);

    $lost = (new CockpitEventRelay(new CockpitHttp(), $base, self::TOKEN, $stateDir, NULL, static function (string $line): void {}))->flush();
    $this->assertSame(0, $lost['cursor'], 'a cut-short answer moves nothing');
    $this->assertCount(7, (array) $this->stubState($statePath)['events'], 'though the server stored the batch');

    $again = (new CockpitEventRelay(new CockpitHttp(), $base, self::TOKEN, $stateDir))->flush();
    $this->assertSame(7, $again['cursor']);
    $this->assertCount(7, (array) $this->stubState($statePath)['events'], 'the retry is counted once');
    $this->assertNull($again['error']);
    $this->assertFileDoesNotExist($stateDir . '/' . CockpitEventRelay::LAST_ERROR, 'a delivery clears the last error');
  }

  /**
   * The token is in no warning, file or result.
   */
  public function testTheTokenIsWrittenNowhere(): void {
    [$base] = $this->stub(['fail' => TRUE]);
    $stateDir = $this->withEvents(2);
    $warnings = [];
    $result = (new CockpitEventRelay(new CockpitHttp(), $base, self::TOKEN, $stateDir, NULL, static function (string $line) use (&$warnings): void {
      $warnings[] = $line;
    }))->flush();

    $this->assertStringNotContainsString(self::TOKEN, implode("\n", $warnings) . json_encode($result));
    foreach (glob($stateDir . '/*') ?: [] as $file) {
      $this->assertStringNotContainsString(self::TOKEN, (string) file_get_contents($file), basename($file));
    }
  }

  /**
   * A state directory holding a log of N events.
   *
   * @param int $count
   *   How many.
   * @param string $pad
   *   Filler for each payload, to size them.
   *
   * @return string
   *   The directory.
   */
  private function withEvents(int $count, string $pad = ''): string {
    $dir = $this->makeRoot();
    $log = new RunEventLog($dir);
    for ($i = 1; $i <= $count; $i++) {
      $log->append('run-a', 'TICKET-12', 'phase.attempted', [
        'phase' => 'code',
        'attempt' => 1,
        'report' => NULL,
        'pad' => $pad,
      ]);
    }
    return $dir;
  }

}
