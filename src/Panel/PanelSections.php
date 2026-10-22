<?php

declare(strict_types=1);

namespace Droost\Workflow\Panel;

/**
 * The few shapes a panel tab may hold, and the cleaning of a project's.
 *
 * A tab is a list of sections, each one of five kinds: `text`, `markdown`,
 * `fields` (label and value pairs), `checklist` (items done or not) and
 * `list` (items with a detail, a tag and a tone). The mod draws these and
 * nothing else, so a project's tab file can say anything that fits them and
 * nothing that does not: what does not fit is dropped, and the tab says so
 * rather than drawing half of it.
 */
final class PanelSections {

  /**
   * The section kinds a tab may hold.
   */
  public const KINDS = ['text', 'markdown', 'fields', 'checklist', 'list'];

  /**
   * The tones an item may carry.
   */
  public const TONES = ['ok', 'warn', 'bad', 'info', 'muted'];

  /**
   * At most this many sections in a tab, and items in a section.
   */
  public const MAX_SECTIONS = 20;
  public const MAX_ITEMS = 100;

  /**
   * At most this many characters in one string.
   */
  public const MAX_TEXT = 2000;

  /**
   * At most this many characters of markdown: what the mod's Markdown draws.
   */
  public const MAX_MARKDOWN = 9500;

  /**
   * Cleans a tab file's document into a badge and sections.
   *
   * @param mixed $document
   *   The decoded JSON the file printed.
   *
   * @return array{badge: string|null, sections: list<array<string, mixed>>, dropped: list<string>}
   *   What the panel draws, and what was dropped and why.
   */
  public static function clean(mixed $document): array {
    $dropped = [];
    if (!is_array($document) || array_is_list($document)) {
      return ['badge' => NULL, 'sections' => [], 'dropped' => ['the file printed no JSON object']];
    }
    $badge = self::short($document['badge'] ?? NULL, 12);
    $sections = [];
    $raw = is_array($document['sections'] ?? NULL) ? $document['sections'] : [];
    if (!is_array($document['sections'] ?? NULL)) {
      $dropped[] = 'no "sections" list';
    }
    foreach (array_values($raw) as $index => $section) {
      if (count($sections) >= self::MAX_SECTIONS) {
        $dropped[] = sprintf('sections after the %dth', self::MAX_SECTIONS);
        break;
      }
      $cleaned = self::section($section);
      if (is_string($cleaned)) {
        $dropped[] = sprintf('section %d: %s', $index + 1, $cleaned);
        continue;
      }
      $sections[] = $cleaned;
    }

    return ['badge' => $badge, 'sections' => $sections, 'dropped' => $dropped];
  }

  /**
   * One section, cleaned, or why it was dropped.
   *
   * @param mixed $section
   *   The section as printed.
   *
   * @return array<string, mixed>|string
   *   The section, or the reason it was dropped.
   */
  private static function section(mixed $section): array|string {
    if (!is_array($section)) {
      return 'not an object';
    }
    $kind = $section['kind'] ?? NULL;
    if (!is_string($kind) || !in_array($kind, self::KINDS, TRUE)) {
      return sprintf('kind must be one of %s', implode(', ', self::KINDS));
    }
    $out = ['kind' => $kind, 'title' => self::short($section['title'] ?? NULL, 80)];
    if ($kind === 'text' || $kind === 'markdown') {
      $text = self::short($section['text'] ?? NULL, $kind === 'markdown' ? self::MAX_MARKDOWN : self::MAX_TEXT);
      return $text === NULL ? sprintf('a %s section needs "text"', $kind) : $out + ['text' => $text];
    }
    $items = [];
    foreach (is_array($section['items'] ?? NULL) ? array_slice(array_values($section['items']), 0, self::MAX_ITEMS) : [] as $item) {
      $cleaned = is_array($item) ? self::item($kind, $item) : NULL;
      if ($cleaned !== NULL) {
        $items[] = $cleaned;
      }
    }
    if ($items === []) {
      return sprintf('a %s section needs "items"', $kind);
    }

    return $out + ['items' => $items];
  }

  /**
   * One item of a fields, checklist or list section, or NULL.
   *
   * @param string $kind
   *   The section's kind.
   * @param array<mixed> $item
   *   The item as printed.
   *
   * @return array<string, mixed>|null
   *   The item, or NULL when it has no text.
   */
  private static function item(string $kind, array $item): ?array {
    if ($kind === 'fields') {
      $label = self::short($item['label'] ?? NULL, 80);
      $value = self::short($item['value'] ?? NULL, self::MAX_TEXT);
      return $label === NULL || $value === NULL ? NULL : ['label' => $label, 'value' => $value];
    }
    $text = self::short($item['text'] ?? NULL, self::MAX_TEXT);
    if ($text === NULL) {
      return NULL;
    }
    if ($kind === 'checklist') {
      return [
        'text' => $text,
        'done' => ($item['done'] ?? FALSE) === TRUE,
        'note' => self::short($item['note'] ?? NULL, 300),
      ];
    }
    $tone = $item['tone'] ?? NULL;

    return [
      'text' => $text,
      'detail' => self::short($item['detail'] ?? NULL, self::MAX_TEXT),
      'tag' => self::short($item['tag'] ?? NULL, 40),
      'tone' => is_string($tone) && in_array($tone, self::TONES, TRUE) ? $tone : NULL,
    ];
  }

  /**
   * A string kept to a length, or NULL for anything that is not one.
   *
   * @param mixed $value
   *   The value.
   * @param int $max
   *   The most characters kept.
   *
   * @return string|null
   *   The string, or NULL.
   */
  private static function short(mixed $value, int $max): ?string {
    if (is_int($value) || is_float($value)) {
      $value = (string) $value;
    }
    if (!is_string($value) || trim($value) === '') {
      return NULL;
    }
    $value = trim($value);

    return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1) . '…' : $value;
  }

}
