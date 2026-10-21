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

  /**
   * Every verb answers help, and help changes nothing.
   *
   * `droost workflow:run help` opened a run: `run` ignored a word it did not
   * know, and the AGENTS block promises `droost <command> help` explains one
   * (a live project, 2026-10-21). Each spelling, on every verb, in a project
   * directory that must be exactly as it was afterwards.
   */
  public function testEveryVerbAnswersHelpAndTouchesNothing(): void {
    $root = sys_get_temp_dir() . '/dwf-help-' . bin2hex(random_bytes(4));
    mkdir($root . '/droost', 0755, TRUE);
    file_put_contents($root . '/droost/droost.yml', "preset: low\n");
    $before = $this->tree($root);

    try {
      $this->assertEveryVerbAnswersHelp($root, $before);
    }
    finally {
      exec('rm -rf ' . escapeshellarg($root));
    }
  }

  /**
   * Asks every verb for help, in each spelling, against one project.
   *
   * @param string $root
   *   The project.
   * @param list<string> $before
   *   Its paths before.
   */
  private function assertEveryVerbAnswersHelp(string $root, array $before): void {
    foreach ($this->dispatchableVerbs() as $verb) {
      foreach (['help', '--help', '-h'] as $ask) {
        $lines = [];
        $sink = function (string $line) use (&$lines): void {
          $lines[] = $line;
        };
        $code = (new ArgvDispatcher(
          $sink,
          $sink,
          static fn (): string => '2026-10-21T00:00:00+00:00',
          static fn (): string => 'run-help',
        ))->dispatch([$verb, $ask, '--project=' . $root], $root);

        $this->assertSame(0, $code, sprintf('`%s %s` is a help request', $verb, $ask));
        $this->assertStringStartsWith('droost workflow:' . $verb, implode("\n", $lines), sprintf('`%s %s` names the verb', $verb, $ask));
        $this->assertSame($before, $this->tree($root), sprintf('`%s %s` changed the project', $verb, $ask));
      }
    }
  }

  /**
   * Every path under a directory, sorted.
   *
   * @param string $root
   *   The directory.
   *
   * @return list<string>
   *   The paths, relative to it.
   */
  private function tree(string $root): array {
    $paths = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
      if ($file instanceof \SplFileInfo) {
        $paths[] = substr($file->getPathname(), strlen($root) + 1);
      }
    }
    sort($paths);

    return $paths;
  }

}
