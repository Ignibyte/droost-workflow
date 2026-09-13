<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Cli;

use Droost\Workflow\Cli\ArgvDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Every typed failure this package raises reaches the CLI as a sentence.
 *
 * The errors here are not exceptions in the ordinary sense — each is
 * constructed through a named factory that writes a paragraph for a human
 * ("your spec's criteria table has three unverified rows, here they are"). The
 * dispatcher's handler prints rather than re-explains, which is right, and
 * depends entirely on the error being in the catch list.
 *
 * Three were not: `SpecError`, `EvidenceError` and `DataError`, all siblings of
 * the five that were, all thrown from the same facade the dispatcher calls. So
 * a failed spec contract came out of `bin/droost-workflow` as an uncaught
 * exception and a stack trace, and out of drush as the sentence it was written
 * to carry. Same failure, same library, two different products — and the
 * standalone binary is the surface a repository with no Drupal has.
 *
 * Enumerated rather than listed, so adding a ninth error class without wiring
 * it fails here instead of on somebody's terminal.
 */
final class CliErrorCoverageTest extends TestCase {

  /**
   * Every class in src/ that extends RuntimeException, by FQN.
   *
   * @return list<string>
   *   The class names.
   */
  private function typedErrors(): array {
    $found = [];
    $src = dirname(__DIR__, 2) . '/src';
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));
    foreach ($files as $file) {
      if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
        continue;
      }
      $text = (string) file_get_contents($file->getPathname());
      if (!preg_match('/^final class (\w+) extends \\\\RuntimeException/m', $text, $class)) {
        continue;
      }
      if (!preg_match('/^namespace ([^;]+);/m', $text, $namespace)) {
        continue;
      }
      $found[] = $namespace[1] . '\\' . $class[1];
    }
    sort($found);

    return $found;
  }

  /**
   * The dispatcher catches each of them.
   */
  public function testEveryTypedErrorIsCaught(): void {
    $handler = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Cli/ArgvDispatcher.php');
    $errors = $this->typedErrors();
    $this->assertNotEmpty($errors, 'the scan found the error classes at all');

    foreach ($errors as $fqn) {
      $short = substr((string) strrchr($fqn, '\\'), 1);
      $this->assertMatchesRegularExpression(
        '/catch \(\s*[^)]*\b' . preg_quote($short, '/') . '\b/s',
        $handler,
        sprintf(
          '%s is a typed failure with a human-phrased message and the CLI does '
          . 'not catch it, so it reaches the user as a stack trace. Add it to '
          . 'the catch list in ArgvDispatcher::dispatch().',
          $fqn,
        ),
      );
    }
  }

  /**
   * And a caught one exits as a usage error rather than a crash.
   *
   * The list being right is half of it; the other half is that the handler
   * still does what it did — print the message, return the usage code — for
   * the classes newly added to it.
   */
  public function testCaughtErrorsPrintTheirMessageAndExitUsage(): void {
    $root = sys_get_temp_dir() . '/cli-err-' . bin2hex(random_bytes(5));
    mkdir($root, 0775, TRUE);
    $printedLines = [];
    $sink = function (string $line) use (&$printedLines): void {
      $printedLines[] = $line;
    };
    $dispatcher = new ArgvDispatcher(
      $sink,
      $sink,
      static fn (): string => '2026-09-13T00:00:00+00:00',
      static fn (): string => 'run-clierr',
    );

    // `run` with a spec that is not there, which is how a SpecError is really
    // constructed — one of the three classes this file exists for.
    //
    // The first version passed `['droost-workflow', 'continue']`. argv arrives
    // WITHOUT the script name, so the verb was `droost-workflow`, it fell to
    // `unknown()`, and printed the usage banner — which satisfies all three
    // assertions below without a typed error ever existing. Deleting the entire
    // catch list left this test green. A test for a catch block has to make
    // something throw.
    exec('git -C ' . escapeshellarg($root) . ' init -q 2>/dev/null');
    exec('git -C ' . escapeshellarg($root) . ' commit -q --allow-empty -m i 2>/dev/null');
    $code = $dispatcher->dispatch(['run', '--spec=does-not-exist.md'], $root);

    $printed = implode("\n", $printedLines);
    exec('rm -rf ' . escapeshellarg($root));

    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);
    $this->assertStringNotContainsString(
      'Stack trace',
      $printed,
      'what it says is a sentence, not a trace',
    );
    // The error's OWN words, so a usage banner cannot stand in for them.
    $this->assertStringContainsString(
      'spec',
      strtolower($printed),
      'and the sentence is the one SpecError wrote, not a generic usage line',
    );
    $this->assertStringNotContainsString(
      'unknown command',
      $printed,
      'the verb really ran — argv arrives without the script name',
    );
  }

}
