<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * With no `paths` lever, the front-end trio are handed the project's own code.
 *
 * They were handed nothing (F-87). The trio borrow phpcs's paths, and the
 * standalone `init` file writes phpcs with none, so at xhigh each tool ran
 * with no file argument. Measured: eslint 8.57.1, the version Drupal core
 * pins, exited 0 with `[]`, while naming the same file exited 1 with both
 * planted errors; prettier 3.6.2 exited 0 having parsed nothing.
 */
final class FrontEndTrioSubjectTest extends WorkflowTestCase {

  /**
   * Each tool gets the project's own files it reads, and nothing vendored.
   */
  public function testEachToolIsHandedTheProjectsOwnFiles(): void {
    $root = $this->rootWithTrio();
    mkdir($root . '/assets', 0775, TRUE);
    file_put_contents($root . '/assets/app.js', "var unused = 1\n");
    file_put_contents($root . '/assets/app.css', "a { color: #FFFFFFF; }\n");
    file_put_contents($root . '/assets/readme.md', "notes\n");
    mkdir($root . '/node_modules/lib', 0775, TRUE);
    file_put_contents($root . '/node_modules/lib/index.js', "module.exports = 1;\n");

    $this->assertSame(['assets/app.js'], $this->filesHanded('eslint', $root));
    $this->assertSame(['assets/app.css'], $this->filesHanded('stylelint', $root));
    $this->assertSame(['assets/app.css', 'assets/app.js'], $this->filesHanded('prettier', $root));
  }

  /**
   * A project with no file a tool reads is a labelled pass, and nothing runs.
   */
  public function testNothingToReadIsLabelledPass(): void {
    $root = $this->rootWithTrio();
    mkdir($root . '/src', 0775, TRUE);
    file_put_contents($root . '/src/Thing.php', "<?php\n");
    foreach (['eslint', 'stylelint', 'prettier'] as $gate) {
      $ran = FALSE;
      $executor = new ShellGateExecutor(
        function () use (&$ran): array {
          $ran = TRUE;
          return [0, '[]', ''];
        },
        static fn (): int => 0,
      );
      $result = $executor->execute(new GateSettings($gate, TRUE), $root);
      $this->assertFalse($ran, $gate . ' was not run over no files');
      $this->assertTrue($result->labelledPass, $gate . '\'s pass says it analysed nothing');
      $this->assertStringContainsString('nothing was analysed', $result->summary);
    }
  }

  /**
   * A lever still decides, as it always did.
   */
  public function testTheLeverStillWins(): void {
    $root = $this->rootWithTrio();
    foreach (['a', 'b'] as $dir) {
      mkdir($root . '/' . $dir, 0775, TRUE);
      file_put_contents($root . '/' . $dir . '/x.js', "var x = 1;\n");
    }
    $this->assertSame(['b/x.js'], $this->filesHanded('eslint', $root, ['paths' => 'b']));
  }

  /**
   * The file arguments a trio gate's tool was given.
   *
   * @param string $gate
   *   The gate.
   * @param string $root
   *   The project root.
   * @param array<string, string> $options
   *   The gate's levers.
   *
   * @return list<string>
   *   Every argument that names a file under the root.
   */
  private function filesHanded(string $gate, string $root, array $options = []): array {
    $seen = [];
    $executor = new ShellGateExecutor(
      function (array $argv) use (&$seen): array {
        $seen = $argv;
        return [0, '[]', ''];
      },
      static fn (): int => 0,
    );
    $executor->execute(new GateSettings($gate, TRUE, $options), $root);

    return array_values(array_filter(
      array_slice($seen, 1),
      static fn (string $arg): bool => is_file($root . '/' . $arg),
    ));
  }

  /**
   * A root with the three tools installed.
   *
   * @return string
   *   The root.
   */
  private function rootWithTrio(): string {
    $root = $this->makeRoot();
    mkdir($root . '/node_modules/.bin', 0775, TRUE);
    foreach (['eslint', 'stylelint', 'prettier'] as $tool) {
      file_put_contents($root . '/node_modules/.bin/' . $tool, '');
    }
    return $root;
  }

}
