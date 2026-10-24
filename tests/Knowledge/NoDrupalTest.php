<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Knowledge;

use PHPUnit\Framework\TestCase;

/**
 * Holds the package to booting no Drupal: no Drupal class is named in code.
 *
 * The package leaves Drupal (owner, 2026-10-19): what the code says is read
 * from files, and nothing calls into a booted site. A class reference in code
 * is a dependency a reader with a broken site cannot satisfy. Strings and
 * comments are prose (CitationResolver resolves `Drupal\Core\…` citations
 * to files, and says so); only code tokens are judged.
 */
final class NoDrupalTest extends TestCase {

  /**
   * No file under src/ or bin/ names a Drupal class in code.
   */
  public function testNoDrupalClassIsNamedInCode(): void {
    $root = dirname(__DIR__, 2);
    $files = glob($root . '/bin/*') ?: [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
        $files[] = $file->getPathname();
      }
    }
    $this->assertNotEmpty($files);

    $hits = [];
    foreach ($files as $path) {
      $content = (string) file_get_contents($path);
      if (!str_contains($content, '<?php')) {
        continue;
      }
      $hits = array_merge($hits, self::references($content, substr($path, strlen($root) + 1)));
    }
    $this->assertSame([], $hits, 'Code that names Drupal cannot run where the site is broken or absent.');
  }

  /**
   * The judge itself catches each way code names Drupal.
   */
  public function testTheJudgeCatchesEachForm(): void {
    $this->assertCount(1, self::references("<?php\nuse Drupal\\Core\\Site\\Settings;\n", 'a.php'));
    $this->assertCount(1, self::references("<?php\n\\Drupal::service('x');\n", 'b.php'));
    $this->assertCount(1, self::references("<?php\n\$x = new \\Drupal\\node\\Entity\\Node();\n", 'c.php'));
    $this->assertSame([], self::references("<?php\n// Drupal\\Core is fine here.\n\$s = 'Drupal\\\\Core\\\\X';\n", 'd.php'));
  }

  /**
   * The Drupal references in one file's code.
   *
   * @param string $content
   *   The PHP source.
   * @param string $label
   *   The file's name, for the report.
   *
   * @return list<string>
   *   One line per reference.
   */
  private static function references(string $content, string $label): array {
    $hits = [];
    $tokens = token_get_all($content);
    foreach ($tokens as $i => $token) {
      if (!is_array($token)) {
        continue;
      }
      [$id, $text, $line] = $token;
      if (in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], TRUE) && preg_match('/^\\\\?Drupal(\\\\|$)/', $text) === 1) {
        $hits[] = sprintf('%s:%d %s', $label, $line, $text);
      }
      if ($id === T_STRING && $text === 'Drupal') {
        $next = $tokens[$i + 1] ?? NULL;
        if (is_array($next) && $next[0] === T_DOUBLE_COLON) {
          $hits[] = sprintf('%s:%d Drupal::', $label, $line);
        }
      }
    }
    return $hits;
  }

}
