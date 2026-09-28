<?php

declare(strict_types=1);

namespace Droost\Workflow\Event;

/**
 * The run-event log: `events.jsonl`, one event per line, append-only.
 *
 * Written by the engine after each state change it persists, never by a
 * listener: a listener is a notification whose failures are swallowed, and a
 * relay that must survive its consumer being down needs a record to read.
 * The log spans runs (each event names its run) and survives `reset`, so a
 * consumer that was offline still finds every event.
 *
 * An append holds an exclusive lock on the file, so two processes never
 * interleave a line or reuse a `seq`. On a state directory over NFS the lock
 * is advisory, as flock is there. A failure to write never fails the run: it
 * is reported once, and the run goes on as though the log were not there.
 */
final class RunEventLog {

  /**
   * The log's file name, inside the state directory.
   */
  public const FILENAME = 'events.jsonl';

  /**
   * Whether this process has already said the log cannot be written.
   */
  private static bool $reported = FALSE;

  /**
   * Says, once, that the log could not be written.
   *
   * @var \Closure(string): void
   */
  private readonly \Closure $warn;

  /**
   * The time an event is stamped with, as ISO-8601.
   *
   * @var \Closure(): string
   */
  private readonly \Closure $clock;

  /**
   * Constructs the log.
   *
   * @param string $stateDir
   *   The state directory, absolute.
   * @param (callable(string): void)|null $warn
   *   Where the one warning goes; standard error, once per process, when
   *   NULL.
   * @param (callable(): string)|null $clock
   *   The time, as ISO-8601; the system clock when NULL.
   */
  public function __construct(
    private readonly string $stateDir,
    ?callable $warn = NULL,
    ?callable $clock = NULL,
  ) {
    $once = static function (string $line): void {
      if (!self::$reported) {
        self::$reported = TRUE;
        if (defined('STDERR')) {
          fwrite(STDERR, $line . "\n");
        }
      }
    };
    $this->warn = $warn === NULL ? $once : \Closure::fromCallable($warn);
    $this->clock = $clock === NULL
      ? static fn (): string => date('c')
      : \Closure::fromCallable($clock);
  }

  /**
   * The log's path.
   *
   * @return string
   *   The absolute path.
   */
  public function path(): string {
    return rtrim($this->stateDir, '/') . '/' . self::FILENAME;
  }

  /**
   * Appends one event.
   *
   * @param string $runId
   *   The run.
   * @param string|null $workItemId
   *   The ticket the run is bound to, or NULL.
   * @param string $type
   *   One of RunEvent::TYPES.
   * @param array<string, mixed> $payload
   *   What the type carries.
   *
   * @return \Droost\Workflow\Event\RunEvent|null
   *   The event as written, or NULL when it could not be.
   */
  public function append(string $runId, ?string $workItemId, string $type, array $payload): ?RunEvent {
    $path = $this->path();
    $handle = is_dir($path) ? FALSE : @fopen($path, 'c+');
    if ($handle === FALSE) {
      $this->fail('it could not be opened');
      return NULL;
    }
    try {
      if (!flock($handle, LOCK_EX)) {
        $this->fail('it could not be locked');
        return NULL;
      }
      [$last, $endsWithNewline] = self::tail($handle);
      $event = new RunEvent(
        'evt-' . bin2hex(random_bytes(8)),
        $last + 1,
        $runId,
        $workItemId,
        ($this->clock)(),
        $type,
        $payload,
      );
      $document = $event->toArray();
      // An empty payload is still an object, never `[]`.
      $document['payload'] = $payload === [] ? new \stdClass() : $payload;
      $line = json_encode(
        $document,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION,
      );
      if (!is_string($line)) {
        $this->fail('an event could not be encoded');
        return NULL;
      }
      // A torn last line (a writer killed mid-line) stays a line of its own,
      // which a reader skips, rather than swallowing this one.
      $record = ($endsWithNewline ? '' : "\n") . $line . "\n";
      if (fseek($handle, 0, SEEK_END) !== 0 || fwrite($handle, $record) !== strlen($record) || !fflush($handle)) {
        $this->fail('an event could not be written');
        return NULL;
      }

      return $event;
    }
    finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }

  /**
   * The events after a sequence number, in order.
   *
   * @param int $afterSeq
   *   Only events whose `seq` is greater.
   *
   * @return \Generator<int, \Droost\Workflow\Event\RunEvent>
   *   The events. A line that is not one (a torn last line) is skipped.
   */
  public function read(int $afterSeq = 0): \Generator {
    foreach ($this->entries($afterSeq) as [, $event]) {
      yield $event;
    }
  }

  /**
   * The same events, as the lines the log holds.
   *
   * @param int $afterSeq
   *   Only events whose `seq` is greater.
   *
   * @return \Generator<int, string>
   *   The lines, without their newline.
   */
  public function lines(int $afterSeq = 0): \Generator {
    foreach ($this->entries($afterSeq) as [$line]) {
      yield $line;
    }
  }

  /**
   * Each readable line and its event.
   *
   * @param int $afterSeq
   *   Only events whose `seq` is greater.
   *
   * @return \Generator<int, array{string, \Droost\Workflow\Event\RunEvent}>
   *   The lines and events.
   */
  private function entries(int $afterSeq): \Generator {
    $path = $this->path();
    if (!is_file($path)) {
      return;
    }
    $handle = @fopen($path, 'r');
    if ($handle === FALSE) {
      return;
    }
    try {
      while (($line = fgets($handle)) !== FALSE) {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
          continue;
        }
        $event = RunEvent::fromArray(json_decode($line, TRUE));
        if ($event !== NULL && $event->seq > $afterSeq) {
          yield [$line, $event];
        }
      }
    }
    finally {
      fclose($handle);
    }
  }

  /**
   * The last event's `seq` in an open log, and whether the file ends a line.
   *
   * Read backwards from the end, a block at a time, so an append costs the
   * same on the ten-thousandth event as on the first.
   *
   * @param resource $handle
   *   The log, locked.
   *
   * @return array{int, bool}
   *   The last sequence number (0 for none), and whether the file is empty
   *   or ends with a newline.
   */
  private static function tail($handle): array {
    $stat = fstat($handle);
    $size = is_array($stat) ? (int) $stat['size'] : 0;
    if ($size === 0) {
      return [0, TRUE];
    }
    fseek($handle, $size - 1);
    $endsWithNewline = fread($handle, 1) === "\n";
    $offset = $size;
    $carry = '';
    while ($offset > 0) {
      $length = min(65536, $offset);
      $offset -= $length;
      fseek($handle, $offset);
      $block = (string) fread($handle, $length) . $carry;
      $lines = explode("\n", $block);
      // Unless this block starts the file, its first line may be partial:
      // carry it into the next block back.
      $carry = $offset > 0 ? (string) array_shift($lines) : '';
      for ($i = count($lines) - 1; $i >= 0; $i--) {
        $decoded = json_decode(trim($lines[$i]), TRUE);
        if (is_array($decoded) && is_int($decoded['seq'] ?? NULL)) {
          return [$decoded['seq'], $endsWithNewline];
        }
      }
    }

    return [0, $endsWithNewline];
  }

  /**
   * Reports that the log could not be written, and carries on.
   *
   * @param string $why
   *   What went wrong.
   */
  private function fail(string $why): void {
    ($this->warn)(sprintf(
      'droost-workflow: the run-event log %s was not written (%s); the run goes on, and its events from here are not in the log.',
      $this->path(),
      $why,
    ));
  }

}
