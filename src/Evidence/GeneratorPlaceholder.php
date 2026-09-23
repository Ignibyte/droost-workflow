<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

/**
 * Whether a PHPUnit test file is still a code generator's placeholder.
 *
 * F-65 set aside a test that droost's own scaffold wrote and nobody touched,
 * by the hash the scaffold recorded. But the router now sends a unit test to
 * `drush generate test:unit`, as it should, and drush records nothing. Its
 * template is a test that cannot fail:
 *
 *     public function testSomething(): void {
 *       self::assertTrue(TRUE, 'This is TRUE!');
 *     }
 *
 * so a skeleton left as generated met `gates.phpunit.in_diff` again, on the
 * path droost itself recommends (F-77). drush's generator is
 * chi-teck/drupal-code-generator, whose four PHP test templates (unit,
 * kernel, browser, webdriver) each hold exactly one test method, named
 * `testSomething`, with a fixed body. A file whose only test method is that
 * one, with one of those bodies, is the generator's, whichever tool wrote it.
 *
 * Read by content, not by provenance, so it needs no record and cannot be
 * fooled by where the file came from. One real test method beside the
 * placeholder, or an edited body, makes the file the run's.
 */
final class GeneratorPlaceholder {

  /**
   * The placeholder bodies, whitespace removed.
   *
   * From drupal-code-generator's templates/Test/_unit, _kernel, _browser and
   * _webdriver. The browser one logs in and checks core's site settings page,
   * which passes on any Drupal site and tests nothing the run wrote.
   */
  private const BODIES = [
    "self::assertTrue(TRUE,'ThisisTRUE!');",
    'self::assertTrue(TRUE);',
    '//Placeyourcodehere.',
    "\$admin_user=\$this->drupalCreateUser(['administersiteconfiguration']);\$this->drupalLogin(\$admin_user);\$this->drupalGet('/admin/config/system/site-information');\$this->assertSession()->elementExists('xpath','//h1[text()=\"Basicsitesettings\"]');",
  ];

  /**
   * Whether this test file's only test is the generator's placeholder.
   *
   * @param string $php
   *   The file's contents.
   *
   * @return bool
   *   TRUE when it declares exactly one test method, `testSomething`, whose
   *   body is one of the generator's.
   */
  public static function isPlaceholderTest(string $php): bool {
    $methods = self::testMethods($php);
    if (count($methods) !== 1 || !array_key_exists('testSomething', $methods)) {
      return FALSE;
    }

    return in_array($methods['testSomething'], self::BODIES, TRUE);
  }

  /**
   * The public test methods a file declares, each with its body, compacted.
   *
   * @param string $php
   *   The file's contents.
   *
   * @return array<string, string>
   *   Method name to body, whitespace removed. A body that cannot be read
   *   reads as '', which matches no placeholder.
   */
  private static function testMethods(string $php): array {
    $tokens = token_get_all($php);
    $count = count($tokens);
    $methods = [];
    for ($i = 0; $i < $count; $i++) {
      if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
        continue;
      }
      $name = NULL;
      for ($j = $i + 1; $j < $count; $j++) {
        if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
          $name = $tokens[$j][1];
          break;
        }
        if ($tokens[$j] === '(') {
          break;
        }
      }
      if ($name === NULL || !str_starts_with($name, 'test')) {
        continue;
      }
      $methods[$name] = self::body($tokens, $j);
    }

    return $methods;
  }

  /**
   * The body of the function whose name is at $from, compacted.
   *
   * @param array<int, mixed> $tokens
   *   The file's tokens.
   * @param int $from
   *   The index of the function's name.
   *
   * @return string
   *   The body between its outermost braces, whitespace removed.
   */
  private static function body(array $tokens, int $from): string {
    $count = count($tokens);
    $depth = 0;
    $body = '';
    for ($k = $from; $k < $count; $k++) {
      $token = $tokens[$k];
      $text = is_array($token) ? (is_string($token[1] ?? NULL) ? $token[1] : '') : (is_string($token) ? $token : '');
      if ($text === '{' || (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], TRUE))) {
        $depth++;
        if ($depth === 1) {
          continue;
        }
      }
      if ($text === '}') {
        $depth--;
        if ($depth === 0) {
          return (string) preg_replace('/\s+/', '', $body);
        }
      }
      if ($depth >= 1) {
        $body .= $text;
      }
      if ($depth === 0 && $text === ';') {
        // An abstract or interface method: no body at all.
        return '';
      }
    }

    return '';
  }

}
