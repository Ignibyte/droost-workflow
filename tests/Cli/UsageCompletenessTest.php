<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Cli;

use Droost\Workflow\Cli\ArgvDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Every verb the dispatcher answers is a verb the usage text names.
 *
 * `declare-changes` shipped dispatchable and undocumented. That is a worse
 * failure here than in most CLIs, because the enforcement BLOCKS a phase on
 * work nobody declared: an agent that runs the binary bare to find out what it
 * can do — which is exactly what an agent does — saw no way to declare, and the
 * shortest path through a run became declaring nothing. Which is what a
 * reviewer measured, and it was read as the audit's fault.
 *
 * Pinned as a property rather than as a string, because the next verb added
 * will be forgotten the same way.
 */
#[CoversClass(ArgvDispatcher::class)]
final class UsageCompletenessTest extends TestCase {

  /**
   * The verbs `dispatch()` matches on, read from the source.
   *
   * Read rather than listed: a hand-kept list is a second thing to forget, and
   * it would drift in the same direction as the usage text it is checking.
   *
   * @return list<string>
   *   The verb names.
   */
  private function dispatchableVerbs(): array {
    $source = file_get_contents((new \ReflectionClass(ArgvDispatcher::class))->getFileName() ?: '');
    $this->assertIsString($source);

    $match = preg_match('/match \(\$verb\) \{(.*?)\n      \};/s', $source, $block);
    $this->assertSame(1, $match, 'the verb table is still a match on $verb');

    preg_match_all("/^\s*'([a-z-]+)' =>/m", $block[1], $verbs);

    return $verbs[1];
  }

  /**
   * The usage text names every dispatchable verb.
   */
  public function testUsageNamesEveryVerb(): void {
    $lines = [];
    $dispatcher = new ArgvDispatcher(
      function (string $line) use (&$lines): void {
        $lines[] = $line;
      },
      static function (string $line): void {},
      static fn (): string => '2026-09-13T00:00:00+00:00',
      static fn (): string => 'run-usage',
    );
    // Through `dispatch()` with no verb, which is the path an agent takes when
    // it runs the binary to find out what the binary can do.
    $dispatcher->dispatch([], sys_get_temp_dir());
    $usage = implode("\n", $lines);

    $verbs = $this->dispatchableVerbs();
    $this->assertGreaterThan(8, count($verbs), 'the verb table was found and read');

    foreach ($verbs as $verb) {
      $this->assertStringContainsString(
        $verb,
        $usage,
        sprintf('`%s` is dispatchable, so the usage text has to name it', $verb),
      );
    }
  }

}
