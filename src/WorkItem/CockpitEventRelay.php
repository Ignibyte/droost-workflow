<?php

declare(strict_types=1);

namespace Droost\Workflow\WorkItem;

use Droost\Workflow\Event\RunEventLog;

/**
 * Carries the run-event log to the cockpit, from a cursor.
 *
 * The log is the outbox: nothing is copied, a cursor file (`relay-cursor`,
 * the last `seq` the cockpit accepted) is the whole state, and an event
 * never delivered is simply one past the cursor. Batches hold at most 100
 * events and 256 KiB. The cursor moves only on a 2xx answer; the cockpit
 * deduplicates by `event_id`, so a batch re-sent after a lost answer is
 * counted once. An event that alone passes 256 KiB would be refused by
 * every try, so it goes as a stand-in, its envelope whole and its payload
 * `{"omitted_bytes": n}`, and stays whole in the log.
 *
 * It never fails or blocks the run. The first failure silences this relay
 * for the rest of its life (one verb of the CLI), so a cockpit that is down
 * costs at most one timeout, and the next verb tries again. The failure is
 * said once on stderr and kept as `last_error` for `status`.
 */
final class CockpitEventRelay {

  /**
   * The cursor file, in the state directory.
   */
  public const CURSOR = 'relay-cursor';

  /**
   * The last failure, one line, in the state directory.
   *
   * Gone after a delivery.
   */
  public const LAST_ERROR = 'relay-error';

  /**
   * The most events one request carries.
   */
  public const MAX_EVENTS = 100;

  /**
   * The most bytes one request's events carry.
   */
  public const MAX_BYTES = 262144;

  /**
   * Whether a failure has stopped this relay.
   */
  private bool $silenced = FALSE;

  /**
   * Where the one warning goes.
   *
   * @var \Closure(string): void
   */
  private readonly \Closure $warn;

  /**
   * Constructs the relay.
   *
   * @param \Droost\Workflow\WorkItem\CockpitHttp $http
   *   The client.
   * @param string $base
   *   The API's base: the cockpit URL and the path, no trailing slash.
   * @param string $token
   *   The bearer token.
   * @param string $stateDir
   *   The state directory, absolute.
   * @param string|null $missing
   *   Why the cockpit cannot be reached before trying (an unset
   *   environment variable, named), or NULL.
   * @param (callable(string): void)|null $warn
   *   Where the one warning goes; standard error when NULL.
   */
  public function __construct(
    private readonly CockpitHttp $http,
    private readonly string $base,
    private readonly string $token,
    private readonly string $stateDir,
    private readonly ?string $missing = NULL,
    ?callable $warn = NULL,
  ) {
    $this->warn = $warn === NULL
      ? static function (string $line): void {
        if (defined('STDERR')) {
          fwrite(STDERR, $line . "\n");
        }
      }
    : \Closure::fromCallable($warn);
  }

  /**
   * Sends every event past the cursor, in batches, until one fails.
   *
   * @return array{attempted: bool, delivered: int, cursor: int, pending: int, error: string|null}
   *   Whether anything was tried, how many events the cockpit took, where
   *   the cursor now is, how many still wait, and why it stopped, if it did.
   */
  public function flush(): array {
    $cursor = $this->cursor();
    if ($this->silenced) {
      return $this->result(FALSE, 0, $cursor);
    }
    $delivered = 0;
    $batch = [];
    $bytes = 0;
    $last = $cursor;
    foreach ((new RunEventLog($this->stateDir))->lines($cursor) as $line) {
      $seq = self::seqOf($line);
      if (strlen($line) + 1 > self::MAX_BYTES) {
        $line = self::standIn($line);
      }
      if ($batch !== [] && (count($batch) >= self::MAX_EVENTS || $bytes + strlen($line) + 1 > self::MAX_BYTES)) {
        if (!$this->send($batch, $last)) {
          return $this->result(TRUE, $delivered, $this->cursor());
        }
        $delivered += count($batch);
        $batch = [];
        $bytes = 0;
      }
      $batch[] = json_decode($line);
      $bytes += strlen($line) + 1;
      $last = $seq;
    }
    if ($batch !== []) {
      if (!$this->send($batch, $last)) {
        return $this->result(TRUE, $delivered, $this->cursor());
      }
      $delivered += count($batch);
    }

    return $this->result($delivered > 0, $delivered, $this->cursor());
  }

  /**
   * Where the relay stands, for `status`.
   *
   * @return array{cursor: int, pending: int, last_error: string|null}
   *   The cursor, the events past it, and the last failure.
   */
  public function status(): array {
    $cursor = $this->cursor();
    $error = @file_get_contents($this->path(self::LAST_ERROR));

    return [
      'cursor' => $cursor,
      'pending' => $this->pendingAfter($cursor),
      'last_error' => is_string($error) && trim($error) !== '' ? trim($error) : NULL,
    ];
  }

  /**
   * How many events wait past the cursor.
   *
   * @return int
   *   The count.
   */
  public function pending(): int {
    return $this->pendingAfter($this->cursor());
  }

  /**
   * Posts one batch; on a 2xx answer the cursor moves to its last event.
   *
   * @param list<mixed> $batch
   *   The events, decoded as objects so an empty payload stays one.
   * @param int $last
   *   The batch's last `seq`.
   *
   * @return bool
   *   Whether the cockpit took it.
   */
  private function send(array $batch, int $last): bool {
    if ($this->missing !== NULL) {
      return $this->fail($this->missing);
    }
    $response = $this->http->request('POST', $this->base . '/events', ['events' => $batch], $this->token);
    $body = $response['body'];
    if ($response['error'] !== NULL || $response['status'] < 200 || $response['status'] >= 300
      || !is_int($body['accepted'] ?? NULL) || !is_int($body['duplicates'] ?? NULL)) {
      return $this->fail($response['error'] ?? sprintf('%s answered %d without {accepted, duplicates}', CockpitHttp::origin($this->base), $response['status']));
    }
    $this->write(self::CURSOR, (string) $last);
    @unlink($this->path(self::LAST_ERROR));

    return TRUE;
  }

  /**
   * Stops the relay for its life, and says so once.
   *
   * @param string $why
   *   The one-line reason, which never carries the token.
   *
   * @return bool
   *   Always FALSE: nothing was delivered.
   */
  private function fail(string $why): bool {
    $this->silenced = TRUE;
    $this->write(self::LAST_ERROR, $why);
    ($this->warn)(sprintf(
      'droost-workflow: the cockpit relay stopped for this command (%s); %d event(s) wait in events.jsonl and go with the next command, or `droost-workflow relay`.',
      $why,
      $this->pending(),
    ));

    return FALSE;
  }

  /**
   * The relay's result.
   *
   * @param bool $attempted
   *   Whether anything was sent.
   * @param int $delivered
   *   How many events the cockpit took.
   * @param int $cursor
   *   The cursor now.
   *
   * @return array{attempted: bool, delivered: int, cursor: int, pending: int, error: string|null}
   *   The result.
   */
  private function result(bool $attempted, int $delivered, int $cursor): array {
    return [
      'attempted' => $attempted,
      'delivered' => $delivered,
      'cursor' => $cursor,
      'pending' => $this->pendingAfter($cursor),
      'error' => $this->silenced ? $this->status()['last_error'] : NULL,
    ];
  }

  /**
   * The cursor: the last `seq` the cockpit accepted, 0 for none.
   *
   * @return int
   *   The cursor.
   */
  private function cursor(): int {
    $raw = @file_get_contents($this->path(self::CURSOR));
    return is_string($raw) && ctype_digit(trim($raw)) ? (int) trim($raw) : 0;
  }

  /**
   * How many events wait past a cursor.
   *
   * @param int $cursor
   *   The cursor.
   *
   * @return int
   *   The count.
   */
  private function pendingAfter(int $cursor): int {
    return iterator_count((new RunEventLog($this->stateDir))->lines($cursor));
  }

  /**
   * An event too large for any request, as the cockpit receives it.
   *
   * @param string $line
   *   The event's line.
   *
   * @return string
   *   The same envelope, its payload only the size of what was left out.
   */
  private static function standIn(string $line): string {
    $event = json_decode($line);
    if (!$event instanceof \stdClass) {
      return $line;
    }
    $event->payload = (object) ['omitted_bytes' => strlen($line)];

    return (string) json_encode($event, CockpitHttp::JSON_FLAGS);
  }

  /**
   * An event line's sequence number.
   *
   * @param string $line
   *   The line.
   *
   * @return int
   *   Its `seq`.
   */
  private static function seqOf(string $line): int {
    $decoded = json_decode($line, TRUE);
    return is_array($decoded) && is_int($decoded['seq'] ?? NULL) ? $decoded['seq'] : 0;
  }

  /**
   * A file in the state directory.
   *
   * @param string $name
   *   Its name.
   *
   * @return string
   *   Its path.
   */
  private function path(string $name): string {
    return rtrim($this->stateDir, '/') . '/' . $name;
  }

  /**
   * Writes a small file through a temporary one and a rename.
   *
   * @param string $name
   *   The file.
   * @param string $content
   *   What it holds.
   */
  private function write(string $name, string $content): void {
    $path = $this->path($name);
    if (!is_dir(dirname($path)) || is_link($path)) {
      return;
    }
    $temp = @tempnam(dirname($path), '.' . $name . '-');
    if ($temp === FALSE) {
      return;
    }
    if (file_put_contents($temp, $content . "\n") === FALSE || !@rename($temp, $path)) {
      @unlink($temp);
    }
  }

}
