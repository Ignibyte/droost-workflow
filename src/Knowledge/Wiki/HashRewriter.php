<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge\Wiki;

/**
 * Rewrites recorded source hashes inside a page's frontmatter, in place.
 *
 * Line surgery, not a YAML round-trip. Decoding the frontmatter and re-encoding
 * it would reformat the whole block — dropping comments, normalising quoting
 * and rewriting everything — and the pages that most need stamping are the
 * hand-authored cross-cutting ones the generator cannot touch. Reformatting
 * somebody's page to change two hashes is a bad trade, and it makes the diff
 * unreviewable, which is the one thing a provenance change must not be.
 *
 * So this rewrites the `hash:` lines and nothing else. Every other byte of the
 * file — prose, key order, quoting, comments, line endings — survives intact,
 * and the resulting diff shows exactly the claim that moved.
 *
 * The scanner understands the block layout the generator emits and the spec
 * documents. Anything else (a flow-style sequence, a trailing comment on a
 * hash line, an anchor/alias) is REFUSED rather than guessed at: a wrong
 * rewrite here forges a freshness claim, so ambiguity must fail loudly.
 */
final readonly class HashRewriter {

  /**
   * Rewrites the hashes of the named sources.
   *
   * @param string $raw
   *   The full page text.
   * @param array<string, string> $updates
   *   Source path => the new algo-prefixed hash.
   *
   * @return string
   *   The page text with those hashes replaced.
   *
   * @throws \RuntimeException
   *   With a precise reason when the frontmatter cannot be rewritten safely.
   *   Callers convert this into a refusal row rather than letting it escape.
   */
  public function rewrite(string $raw, array $updates): string {
    if ($updates === []) {
      return $raw;
    }
    // Keep the line terminators on the lines (CRLF included): the file is
    // rewritten by joining these back, so anything dropped here is lost.
    $lines = preg_split('/(?<=\n)/', $raw);
    if ($lines === FALSE) {
      throw new \RuntimeException('the page could not be split into lines');
    }
    $close = self::frontmatterEnd($lines);
    $entries = self::sourceEntries($lines, $close);

    $seen = [];
    foreach ($entries as $entry) {
      if (!array_key_exists($entry['path'], $updates)) {
        continue;
      }
      if ($entry['hashLine'] === NULL) {
        throw new \RuntimeException(sprintf('the entry for "%s" has no hash line to rewrite', $entry['path']));
      }
      if (isset($seen[$entry['path']])) {
        // Two entries claiming one path: rewriting both would be a guess about
        // which one the caller meant, and leaving one is worse than either.
        throw new \RuntimeException(sprintf('droost.sources lists "%s" more than once', $entry['path']));
      }
      $seen[$entry['path']] = TRUE;
      $lines[$entry['hashLine']] = self::replaceHash(
        $lines[$entry['hashLine']],
        $updates[$entry['path']],
        $entry['path'],
      );
    }

    $missing = array_diff(array_keys($updates), array_keys($seen));
    if ($missing !== []) {
      throw new \RuntimeException(sprintf(
        'droost.sources does not list %s',
        implode(', ', array_map(static fn (string $p): string => '"' . $p . '"', $missing)),
      ));
    }
    return implode('', $lines);
  }

  /**
   * Locates the closing frontmatter fence.
   *
   * @param array<int, string> $lines
   *   The page lines.
   *
   * @return int
   *   The index of the closing "---" line.
   *
   * @throws \RuntimeException
   *   When the page carries no frontmatter fence.
   */
  private static function frontmatterEnd(array $lines): int {
    if (($lines[0] ?? NULL) === NULL || rtrim($lines[0]) !== '---') {
      throw new \RuntimeException('the page does not open with a frontmatter fence');
    }
    $count = count($lines);
    for ($i = 1; $i < $count; $i++) {
      if (rtrim($lines[$i]) === '---') {
        return $i;
      }
    }
    throw new \RuntimeException('the frontmatter fence is never closed');
  }

  /**
   * Collects the droost.sources sequence entries.
   *
   * @param array<int, string> $lines
   *   The page lines.
   * @param int $close
   *   The closing fence index (the scan never reads past it).
   *
   * @return array<int, array{path: string, hashLine: int|null}>
   *   One entry per sequence item, in document order.
   *
   * @throws \RuntimeException
   *   When the block is absent or in a shape this scanner will not rewrite.
   */
  private static function sourceEntries(array $lines, int $close): array {
    $droost = NULL;
    for ($i = 1; $i < $close; $i++) {
      // Tolerate "droost :" — legal YAML, and the parser accepts it, so the
      // rewriter must not disagree with the checker about what is managed.
      if (preg_match('/^droost\s*:\s*$/', rtrim($lines[$i])) === 1) {
        $droost = $i;
        break;
      }
    }
    if ($droost === NULL) {
      throw new \RuntimeException('the page has no droost provenance block');
    }

    $sources = NULL;
    $indent = 0;
    for ($i = $droost + 1; $i < $close; $i++) {
      $line = rtrim($lines[$i]);
      if ($line === '') {
        continue;
      }
      if (self::indentOf($line) === 0) {
        // Back at the top level: the droost block ended without a sources key.
        break;
      }
      if (preg_match('/^(\s+)sources\s*:\s*(.*)$/', $line, $m) === 1) {
        if (trim($m[2]) !== '') {
          throw new \RuntimeException('droost.sources is not a block sequence (flow style or an alias cannot be rewritten line-surgically)');
        }
        $sources = $i;
        $indent = strlen($m[1]);
        break;
      }
    }
    if ($sources === NULL) {
      throw new \RuntimeException('the droost block has no sources sequence');
    }

    $entries = [];
    for ($i = $sources + 1; $i < $close; $i++) {
      $line = rtrim($lines[$i]);
      if ($line === '') {
        continue;
      }
      if (self::indentOf($line) <= $indent) {
        break;
      }
      // A new sequence item: "-" alone, or "- key: value" with the first key
      // on the dash line. Both shapes are legal and both appear in the wild.
      if (preg_match('/^\s*-\s*$/', $line) === 1) {
        $entries[] = ['path' => NULL, 'hashLine' => NULL];
        continue;
      }
      if (preg_match('/^\s*-\s+(.*)$/', $line, $m) === 1) {
        $entries[] = ['path' => NULL, 'hashLine' => NULL];
        self::absorb($entries, $m[1], $i);
        continue;
      }
      if ($entries === []) {
        throw new \RuntimeException('droost.sources has content before its first entry');
      }
      self::absorb($entries, ltrim($line), $i);
    }

    $out = [];
    foreach ($entries as $entry) {
      if ($entry['path'] === NULL) {
        // An entry with no path cannot be addressed by the caller, and cannot
        // be silently dropped either — its hash line is still a live claim.
        throw new \RuntimeException('a droost.sources entry has no path key');
      }
      $out[] = $entry;
    }
    return $out;
  }

  /**
   * Records a "key: value" line against the entry being scanned.
   *
   * @param array<int, array{path: string|null, hashLine: int|null}> $entries
   *   The entries collected so far; the last one is the open entry.
   * @param string $content
   *   The line content, leading dash and indentation removed.
   * @param int $index
   *   The line index, kept so the hash line can be rewritten in place.
   */
  private static function absorb(array &$entries, string $content, int $index): void {
    $last = array_key_last($entries);
    if ($last === NULL) {
      return;
    }
    if (preg_match('/^path\s*:\s*(.*)$/', $content, $m) === 1) {
      $entries[$last]['path'] = self::unquote(rtrim($m[1]));
    }
    elseif (preg_match('/^hash\s*:\s*/', $content) === 1) {
      $entries[$last]['hashLine'] = $index;
    }
  }

  /**
   * Replaces the value on a hash line, preserving its exact framing.
   *
   * @param string $line
   *   The original line, terminator included.
   * @param string $hash
   *   The new algo-prefixed hash.
   * @param string $path
   *   The owning source path, for the error message.
   *
   * @return string
   *   The rewritten line.
   *
   * @throws \RuntimeException
   *   When the line carries anything beyond a plain scalar (a trailing comment,
   *   a tag) that a naive replacement would destroy.
   */
  private static function replaceHash(string $line, string $hash, string $path): string {
    // ^(indent + "hash:" + gap)(quote?)(value)(same quote?)(trailing + EOL)
    //
    // Anchored with \z, not $: "$" also matches BEFORE a final newline, which
    // would leave the terminator outside the captured tail and silently join
    // this line to the next one when the parts are concatenated back.
    if (preg_match('/^(\s*(?:-\s+)?hash\s*:[ \t]*)(["\']?)([^"\'\s]+)\2([ \t]*(?:\r\n|\n|\r)?)\z/', $line, $m) !== 1) {
      throw new \RuntimeException(sprintf('the hash line for "%s" is not a plain scalar this rewriter will edit', $path));
    }
    return $m[1] . $m[2] . $hash . $m[2] . $m[4];
  }

  /**
   * Strips one layer of matching quotes from a scalar.
   *
   * @param string $value
   *   The raw scalar text.
   *
   * @return string
   *   The unquoted value.
   */
  private static function unquote(string $value): string {
    if (strlen($value) >= 2) {
      $first = $value[0];
      if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
        $inner = substr($value, 1, -1);
        // Single-quoted YAML escapes a quote by doubling it.
        return $first === "'" ? str_replace("''", "'", $inner) : $inner;
      }
    }
    return $value;
  }

  /**
   * The leading-whitespace width of a line.
   *
   * @param string $line
   *   The line.
   *
   * @return int
   *   The number of leading whitespace characters.
   */
  private static function indentOf(string $line): int {
    return strlen($line) - strlen(ltrim($line));
  }

}
