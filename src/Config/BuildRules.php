<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

use Droost\Workflow\Support\TypedArray;

/**
 * The owner's rules for what builds a page (owner, 2026-10-02).
 *
 * Plain Drupal CMS gives an agent no rule for which tool owns a page, and an
 * agent left to itself built every page of a Canvas site as a View, a route
 * or a Webform. These are the owner's answers, by the page's MAIN CONTENT:
 * one entity is its `detail` page, the collection itself is a `collection`
 * page, and a page that is neither is a `page`, whose lists are each a
 * `list_section`. A page whose main content is its form is a `form`.
 *
 * The defaults depend on the site's case, which only the site can read:
 * `none` (no Canvas), `sdc` (Canvas, components from SDC) or `code` (Canvas
 * with code components). A file names only what it changes; every kind it
 * leaves out keeps its case's default, and the resolved table says which.
 *
 * A rule's mode is how a break is treated: `report` records it on a passing
 * gate, `block` fails the gate, `off` does not look. A FALSE declaration —
 * a page declared one owner and built as another — is not a rule and has no
 * mode: the composition gate fails it whatever the rules say.
 */
final class BuildRules {

  /**
   * The site cases, as the site reads them.
   */
  public const CASES = ['none', 'sdc', 'code'];

  /**
   * What a page's main content can be, in the decision tree's order.
   */
  public const KINDS = ['page', 'list_section', 'collection', 'detail', 'form'];

  /**
   * What can own a page.
   *
   * `view_block` is a View's block display placed in a page, `route` a
   * controller of the project's own.
   */
  public const OWNERS = [
    'canvas_page',
    'view_page',
    'view_block',
    'entity_view_display',
    'content_template',
    'webform',
    'route',
  ];

  /**
   * How a broken rule is treated.
   */
  public const MODES = ['off', 'report', 'block'];

  /**
   * The mode a rule takes when nothing names one.
   *
   * Report: a run shows what an advised agent does unforced, and the owner
   * tightens a rule to block once the record says it should hold (D4).
   */
  public const DEFAULT_MODE = 'report';

  /**
   * The role an editor proof is read as when nothing names one.
   */
  public const DEFAULT_EDITOR_ROLE = 'content_editor';

  /**
   * Each case's owners for each kind.
   *
   * On a site without Canvas, regular Drupal: a page is a node's display or
   * a route. On a Canvas site, Canvas first: a page is a Canvas page, and a
   * detail page may be a content template as well as Manage display.
   */
  public const DEFAULTS = [
    'none' => [
      'page' => ['entity_view_display', 'route'],
      'list_section' => ['view_block'],
      'collection' => ['view_page'],
      'detail' => ['entity_view_display'],
      'form' => ['webform'],
    ],
    'sdc' => [
      'page' => ['canvas_page'],
      'list_section' => ['view_block'],
      'collection' => ['view_page'],
      'detail' => ['entity_view_display', 'content_template'],
      'form' => ['webform'],
    ],
    'code' => [
      'page' => ['canvas_page'],
      'list_section' => ['view_block'],
      'collection' => ['view_page'],
      'detail' => ['entity_view_display', 'content_template'],
      'form' => ['webform'],
    ],
  ];

  /**
   * Each case's mode for the editor proof and for code components.
   *
   * No Canvas, no Canvas editor to prove. A code component on an SDC site is
   * reported: SDC is the default, and a component written in the browser is
   * code no gate in the repository ever read.
   */
  private const CASE_MODES = [
    'none' => ['editor_proof' => 'off', 'code_components' => 'off'],
    'sdc' => ['editor_proof' => self::DEFAULT_MODE, 'code_components' => self::DEFAULT_MODE],
    'code' => ['editor_proof' => self::DEFAULT_MODE, 'code_components' => 'off'],
  ];

  /**
   * The keys the rules block may hold.
   */
  private const KEYS = ['page', 'list_section', 'collection', 'detail', 'form', 'editor_proof', 'code_components'];

  /**
   * Constructs the rules as written.
   *
   * @param array<string, array{owners?: list<string>, mode?: string}> $kinds
   *   Each kind the file names, with what it says.
   * @param array{role?: string, mode?: string} $editorProof
   *   The editor proof as written.
   * @param string|null $codeComponents
   *   The code components mode as written, or NULL.
   */
  private function __construct(
    public readonly array $kinds,
    public readonly array $editorProof,
    public readonly ?string $codeComponents,
  ) {}

  /**
   * The rules when the file names none: every case's defaults.
   *
   * @return self
   *   Empty rules.
   */
  public static function none(): self {
    return new self([], [], NULL);
  }

  /**
   * Reads the `rules` block.
   *
   * @param \Droost\Workflow\Support\TypedArray $block
   *   The block.
   * @param string $source
   *   The document label, for error messages.
   *
   * @return self
   *   The rules as written.
   *
   * @throws \Droost\Workflow\Config\ConfigError
   *   When the block names a key, an owner or a mode the vocabulary does not
   *   hold.
   * @throws \Droost\Workflow\Support\DataError
   *   When a value has the wrong type.
   */
  public static function read(TypedArray $block, string $source): self {
    foreach ($block->keys() as $key) {
      if (!in_array($key, self::KEYS, TRUE)) {
        throw ConfigError::invalidRule($source, $key, sprintf('is not a rule (known: %s)', implode(', ', self::KEYS)));
      }
    }
    $kinds = [];
    foreach (self::KINDS as $kind) {
      if (!$block->has($kind)) {
        continue;
      }
      $node = $block->child($kind);
      self::onlyKeys($node, ['owner', 'mode'], $source, $kind);
      $rule = [];
      if ($node->has('owner')) {
        $owners = self::list($node->string('owner'));
        foreach ($owners as $owner) {
          if (!in_array($owner, self::OWNERS, TRUE)) {
            throw ConfigError::invalidRule($source, $kind, sprintf('owner "%s" is not an owner (known: %s)', $owner, implode(', ', self::OWNERS)));
          }
        }
        if ($owners === []) {
          throw ConfigError::invalidRule($source, $kind, 'owner is empty');
        }
        $rule['owners'] = $owners;
      }
      if ($node->has('mode')) {
        $rule['mode'] = self::mode($node->string('mode'), $source, $kind);
      }
      $kinds[$kind] = $rule;
    }
    $proof = [];
    if ($block->has('editor_proof')) {
      $node = $block->child('editor_proof');
      self::onlyKeys($node, ['role', 'mode'], $source, 'editor_proof');
      if ($node->has('role')) {
        $role = trim($node->string('role'));
        if (preg_match('/^[a-z0-9_]+$/', $role) !== 1) {
          throw ConfigError::invalidRule($source, 'editor_proof', sprintf('role "%s" is not a role machine name', $role));
        }
        $proof['role'] = $role;
      }
      if ($node->has('mode')) {
        $proof['mode'] = self::mode($node->string('mode'), $source, 'editor_proof');
      }
    }
    $code = NULL;
    if ($block->has('code_components')) {
      $node = $block->child('code_components');
      self::onlyKeys($node, ['mode'], $source, 'code_components');
      $code = $node->has('mode') ? self::mode($node->string('mode'), $source, 'code_components') : NULL;
    }

    return new self($kinds, $proof, $code);
  }

  /**
   * Rebuilds rules a run froze (toArray()'s output).
   *
   * @param array<array-key, mixed> $frozen
   *   The frozen rules.
   *
   * @return self
   *   The rules.
   */
  public static function fromArray(array $frozen): self {
    return self::read(TypedArray::serialized($frozen === [] ? [] : self::asAuthored($frozen)), '<frozen rules>');
  }

  /**
   * The rules as written, for a run to freeze.
   *
   * @return array<string, mixed>
   *   The rules in the file's own shape, with owners as lists.
   */
  public function toArray(): array {
    $out = [];
    foreach ($this->kinds as $kind => $rule) {
      $out[$kind] = $rule;
    }
    if ($this->editorProof !== []) {
      $out['editor_proof'] = $this->editorProof;
    }
    if ($this->codeComponents !== NULL) {
      $out['code_components'] = ['mode' => $this->codeComponents];
    }
    return $out;
  }

  /**
   * The rules in force for a site's case.
   *
   * @param string $case
   *   One of CASES.
   *
   * @return array{kinds: array<string, array{owners: list<string>, mode: string, source: string}>, editor_proof: array{role: string, mode: string, source: string}, code_components: array{mode: string, source: string}, case: string}
   *   Each kind's owners and mode, the editor proof and the code components
   *   rule, each with where it came from (`default` or `file`).
   *
   * @throws \InvalidArgumentException
   *   When the case is not one of CASES.
   */
  public function resolve(string $case): array {
    if (!isset(self::DEFAULTS[$case])) {
      throw new \InvalidArgumentException(sprintf('unknown site case "%s" (known: %s)', $case, implode(', ', self::CASES)));
    }
    $kinds = [];
    foreach (self::KINDS as $kind) {
      $written = $this->kinds[$kind] ?? [];
      $kinds[$kind] = [
        'owners' => $written['owners'] ?? self::DEFAULTS[$case][$kind],
        'mode' => $written['mode'] ?? self::DEFAULT_MODE,
        'source' => $written === [] ? 'default' : 'file',
      ];
    }

    return [
      'case' => $case,
      'kinds' => $kinds,
      'editor_proof' => [
        'role' => $this->editorProof['role'] ?? self::DEFAULT_EDITOR_ROLE,
        'mode' => $this->editorProof['mode'] ?? self::CASE_MODES[$case]['editor_proof'],
        'source' => $this->editorProof === [] ? 'default' : 'file',
      ],
      'code_components' => [
        'mode' => $this->codeComponents ?? self::CASE_MODES[$case]['code_components'],
        'source' => $this->codeComponents === NULL ? 'default' : 'file',
      ],
    ];
  }

  /**
   * Whether an owner is a known owner.
   *
   * @param string $owner
   *   The owner.
   *
   * @return bool
   *   TRUE for one of OWNERS.
   */
  public static function isOwner(string $owner): bool {
    return in_array($owner, self::OWNERS, TRUE);
  }

  /**
   * Whether a kind is a known kind.
   *
   * @param string $kind
   *   The kind.
   *
   * @return bool
   *   TRUE for one of KINDS.
   */
  public static function isKind(string $kind): bool {
    return in_array($kind, self::KINDS, TRUE);
  }

  /**
   * A comma-separated list, trimmed, without empties or repeats.
   *
   * @param string $value
   *   The value as written.
   *
   * @return list<string>
   *   The entries.
   */
  private static function list(string $value): array {
    $out = [];
    foreach (explode(',', $value) as $entry) {
      $entry = trim($entry);
      if ($entry !== '' && !in_array($entry, $out, TRUE)) {
        $out[] = $entry;
      }
    }
    return $out;
  }

  /**
   * A mode, checked.
   *
   * @param string $mode
   *   The mode as written.
   * @param string $source
   *   The document label.
   * @param string $key
   *   The rule it belongs to.
   *
   * @return string
   *   The mode.
   *
   * @throws \Droost\Workflow\Config\ConfigError
   *   When it is not one of MODES.
   */
  private static function mode(string $mode, string $source, string $key): string {
    $mode = trim($mode);
    if (!in_array($mode, self::MODES, TRUE)) {
      throw ConfigError::invalidRule($source, $key, sprintf('mode "%s" is not a mode (known: %s)', $mode, implode(', ', self::MODES)));
    }
    return $mode;
  }

  /**
   * Refuses a key a rule does not take.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The rule.
   * @param list<string> $allowed
   *   The keys it takes.
   * @param string $source
   *   The document label.
   * @param string $rule
   *   The rule's name.
   *
   * @throws \Droost\Workflow\Config\ConfigError
   *   When it carries anything else.
   */
  private static function onlyKeys(TypedArray $node, array $allowed, string $source, string $rule): void {
    foreach ($node->keys() as $key) {
      if (!in_array($key, $allowed, TRUE)) {
        throw ConfigError::invalidRule($source, $rule, sprintf('takes %s, not "%s"', implode(' and ', $allowed), $key));
      }
    }
  }

  /**
   * Frozen rules in the file's shape: owners back to a comma-separated list.
   *
   * @param array<array-key, mixed> $frozen
   *   toArray()'s output.
   *
   * @return array<array-key, mixed>
   *   The block as a file would hold it.
   */
  private static function asAuthored(array $frozen): array {
    $out = [];
    foreach ($frozen as $key => $rule) {
      if (!is_array($rule)) {
        continue;
      }
      if (isset($rule['owners']) && is_array($rule['owners'])) {
        $rule['owner'] = implode(',', array_map(static fn (mixed $owner): string => is_scalar($owner) ? (string) $owner : '', $rule['owners']));
        unset($rule['owners']);
      }
      $out[$key] = $rule;
    }
    return $out;
  }

}
