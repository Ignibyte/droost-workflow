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
 * `list_section`. A page whose main content is its form is a `form`, and one
 * whose form saves configuration is a `settings` page. A route that answers
 * no HTML page at all (a feed, a download, JSON) is a `resource`: declared,
 * so the record says what it is, and outside the page rules.
 *
 * The defaults depend on the site's case, which only the site can read:
 * `none` (no Canvas), `sdc` (Canvas, components from SDC) or `code` (Canvas
 * with code components). A file names only what it changes; every kind it
 * leaves out keeps its case's default, and the resolved table says which.
 *
 * A rule's mode is `report` by default, which records a break on a passing
 * gate, `off`, which does not look, or `block`, which fails the gate (owner,
 * 2026-10-15: retired on 2026-10-02 as "guide, not enforce", back as an
 * opt-in for a site that holds its builds to its rules). A FALSE declaration
 * — a page declared one owner and built as another — is not a rule and has
 * no mode: the composition gate fails it whatever the rules say.
 *
 * HOUSE RULES (`house`, 2026-10-15) are the site's own, in its own words,
 * each with an id and a judge: `gate`, checked mechanically on the diff (a
 * known check, `sdc_markup`), or `seeker`, read against the diff by the
 * seeker, whose finding cites the id. droost tells the agent no way to
 * build; it holds the work to the rules the site wrote.
 */
final class BuildRules {

  /**
   * The site cases, as the site reads them.
   */
  public const CASES = ['none', 'sdc', 'code'];

  /**
   * What a page's main content can be, in the decision tree's order.
   */
  public const KINDS = ['page', 'list_section', 'collection', 'detail', 'form', 'settings', 'resource'];

  /**
   * What can own a page.
   *
   * `view_block` is a View's block display placed in a page, `route` a
   * controller of the project's own, `config_form` a settings form
   * (ConfigFormBase) on a route, and `list_builder` an entity type's own
   * listing on its `entity.<type>.collection` route.
   */
  public const OWNERS = [
    'canvas_page',
    'view_page',
    'view_block',
    'entity_view_display',
    'content_template',
    'webform',
    'route',
    'config_form',
    'list_builder',
  ];

  /**
   * How a broken rule is treated: recorded, or not held at all.
   *
   * `report` records a break beside a passing gate; `block` fails it, for a
   * site that holds its builds to its rules (owner, 2026-10-15); `off` does
   * not look. A false declaration fails whatever the mode, because that is
   * the record, not the rule.
   */
  public const MODES = ['off', 'report', 'block'];

  /**
   * The judges a house rule may name.
   */
  public const JUDGES = ['gate', 'seeker'];

  /**
   * The mechanical checks a gate-judged house rule may name.
   *
   * `sdc_markup`: the run adds no markup outside a single-directory
   * component (a Twig template, a theme hook's template) and no Canvas code
   * component.
   */
  public const HOUSE_CHECKS = ['sdc_markup'];

  /**
   * The mode a rule takes when nothing names one.
   *
   * Report: a run shows what an advised agent does unforced.
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
   *
   * On every case a collection may also be an entity type's own list
   * builder: Views lists content entities only, so the listing of a
   * configuration entity type cannot be a View page (F-241). A settings
   * page is its settings form, never a Webform, whose submissions are
   * content (F-237). A resource is a route of the project's own, or a
   * View's feed display (F-228).
   */
  public const DEFAULTS = [
    'none' => [
      'page' => ['entity_view_display', 'route'],
      'list_section' => ['view_block'],
      'collection' => ['view_page', 'list_builder'],
      'detail' => ['entity_view_display'],
      'form' => ['webform'],
      'settings' => ['config_form'],
      'resource' => ['route', 'view_page'],
    ],
    'sdc' => [
      'page' => ['canvas_page'],
      'list_section' => ['view_block'],
      'collection' => ['view_page', 'list_builder'],
      'detail' => ['entity_view_display', 'content_template'],
      'form' => ['webform'],
      'settings' => ['config_form'],
      'resource' => ['route', 'view_page'],
    ],
    'code' => [
      'page' => ['canvas_page'],
      'list_section' => ['view_block'],
      'collection' => ['view_page', 'list_builder'],
      'detail' => ['entity_view_display', 'content_template'],
      'form' => ['webform'],
      'settings' => ['config_form'],
      'resource' => ['route', 'view_page'],
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
  private const KEYS = [
    'page',
    'list_section',
    'collection',
    'detail',
    'form',
    'settings',
    'resource',
    'editor_proof',
    'code_components',
    'house',
  ];

  /**
   * Constructs the rules as written.
   *
   * @param array<string, array{owners?: list<string>, mode?: string}> $kinds
   *   Each kind the file names, with what it says.
   * @param array{role?: string, mode?: string} $editorProof
   *   The editor proof as written.
   * @param string|null $codeComponents
   *   The code components mode as written, or NULL.
   * @param list<array{id: string, rule: string, judge: string, check?: string, cmd?: string, files?: list<string>, timeout?: int, mode?: string}> $house
   *   The site's own rules, in the file's order.
   */
  private function __construct(
    public readonly array $kinds,
    public readonly array $editorProof,
    public readonly ?string $codeComponents,
    public readonly array $house = [],
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

    return new self($kinds, $proof, $code, $block->has('house') ? self::house($block->child('house')->toArray(), $source) : []);
  }

  /**
   * Reads the house rules: `{ id, rule, judge, check?, cmd?, files?, mode? }`.
   *
   * A rule judged by a gate names exactly one of `check` (one droost knows,
   * HOUSE_CHECKS) or `cmd` (the site's own command, handed the run's changed
   * files; exit zero holds the rule). `files` narrows the files a command is
   * handed to those matching its globs (`*.php`, `web/modules/custom/*`).
   *
   * @param array<array-key, mixed> $entries
   *   The list as written.
   * @param string $source
   *   The document label.
   *
   * @return list<array{id: string, rule: string, judge: string, check?: string, cmd?: string, files?: list<string>, timeout?: int, mode?: string}>
   *   The rules.
   *
   * @throws \Droost\Workflow\Config\ConfigError
   *   When an entry is not a rule this reads.
   */
  private static function house(array $entries, string $source): array {
    if ($entries !== [] && !array_is_list($entries)) {
      throw ConfigError::invalidRule($source, 'house', 'is a list of rules, each { id, rule, judge }');
    }
    $out = [];
    $ids = [];
    foreach ($entries as $index => $entry) {
      $at = sprintf('house[%d]', $index);
      if (!is_array($entry)) {
        throw ConfigError::invalidRule($source, $at, 'is not a rule: write { id, rule, judge }');
      }
      foreach (array_keys($entry) as $key) {
        if (!in_array($key, ['id', 'rule', 'judge', 'check', 'cmd', 'files', 'timeout', 'mode'], TRUE)) {
          throw ConfigError::invalidRule($source, $at, sprintf('takes id, rule, judge, check, cmd, files, timeout and mode, not "%s"', (string) $key));
        }
      }
      $id = is_string($entry['id'] ?? NULL) ? trim($entry['id']) : '';
      if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,31}$/', $id) !== 1) {
        throw ConfigError::invalidRule($source, $at, 'needs an id: a letter, then letters, digits, "-" or "_" (HR1, sdc-only)');
      }
      if (isset($ids[$id])) {
        throw ConfigError::invalidRule($source, $at, sprintf('repeats the id "%s"', $id));
      }
      $ids[$id] = TRUE;
      $text = is_string($entry['rule'] ?? NULL) ? trim($entry['rule']) : '';
      if ($text === '') {
        throw ConfigError::invalidRule($source, $at, sprintf('(%s) needs its rule, in words', $id));
      }
      $judge = is_string($entry['judge'] ?? NULL) ? $entry['judge'] : '';
      if (!in_array($judge, self::JUDGES, TRUE)) {
        throw ConfigError::invalidRule($source, $at, sprintf('(%s) judge must be gate or seeker', $id));
      }
      $rule = ['id' => $id, 'rule' => $text, 'judge' => $judge];
      if ($judge === 'gate') {
        $rule += self::gateCheck($entry, $id, $source, $at);
        if (isset($entry['mode'])) {
          $rule['mode'] = self::mode(is_string($entry['mode']) ? $entry['mode'] : '', $source, $at);
        }
      }
      elseif (isset($entry['check']) || isset($entry['cmd']) || isset($entry['files']) || isset($entry['timeout']) || isset($entry['mode'])) {
        throw ConfigError::invalidRule($source, $at, sprintf('(%s) is judged by the seeker: check, cmd, files, timeout and mode are a gate\'s; how hard a finding holds is seekers.holds', $id));
      }
      $out[] = $rule;
    }
    return $out;
  }

  /**
   * What a gate-judged house rule runs: a check droost knows, or a command.
   *
   * @param array<array-key, mixed> $entry
   *   The rule as written.
   * @param string $id
   *   Its id.
   * @param string $source
   *   The document label.
   * @param string $at
   *   Where it is in the list.
   *
   * @return array{check: string}|array{cmd: string, files?: list<string>, timeout?: int}
   *   The check, or the command, its file globs and its timeout.
   *
   * @throws \Droost\Workflow\Config\ConfigError
   *   When it names neither, both, or one this cannot run.
   */
  private static function gateCheck(array $entry, string $id, string $source, string $at): array {
    $hasCheck = isset($entry['check']);
    $hasCmd = isset($entry['cmd']);
    if ($hasCheck === $hasCmd) {
      throw ConfigError::invalidRule($source, $at, sprintf(
        '(%s) is judged by a gate, which needs one of check (known: %s) or cmd (your own command, handed the run\'s changed files); a rule only judgment can read is judge: seeker',
        $id,
        implode(', ', self::HOUSE_CHECKS),
      ));
    }
    if ($hasCheck) {
      $check = is_string($entry['check']) ? $entry['check'] : '';
      if (!in_array($check, self::HOUSE_CHECKS, TRUE)) {
        throw ConfigError::invalidRule($source, $at, sprintf('(%s) check must be one droost knows: %s', $id, implode(', ', self::HOUSE_CHECKS)));
      }
      if (isset($entry['files']) || isset($entry['timeout'])) {
        throw ConfigError::invalidRule($source, $at, sprintf('(%s) files and timeout belong to a cmd; a check reads what it reads', $id));
      }
      return ['check' => $check];
    }
    $cmd = is_string($entry['cmd']) ? trim($entry['cmd']) : '';
    // The command is the repository's own, reviewed in the same diff as every
    // other lever, as a custom gate's is: the constraint is shape, not trust.
    if ($cmd === '' || str_contains($cmd, "\n") || str_contains($cmd, "\0")) {
      throw ConfigError::invalidRule($source, $at, sprintf('(%s) cmd must be a non-empty single-line command', $id));
    }
    $out = ['cmd' => $cmd];
    if (isset($entry['files'])) {
      // Split on the commas between globs, never inside a `{a,b}`.
      $files = is_string($entry['files']) ? preg_split('/,(?![^{]*\})/', $entry['files']) : $entry['files'];
      $globs = is_array($files) ? array_values(array_filter(array_map(
        static fn (mixed $glob): string => is_string($glob) ? trim($glob) : '',
        $files,
      ), static fn (string $glob): bool => $glob !== '')) : [];
      if ($globs === []) {
        throw ConfigError::invalidRule($source, $at, sprintf('(%s) files is a glob or a list of globs (*.php, web/modules/custom/*)', $id));
      }
      $out['files'] = $globs;
    }
    if (isset($entry['timeout'])) {
      if (!is_int($entry['timeout']) || $entry['timeout'] < 1) {
        throw ConfigError::invalidRule($source, $at, sprintf('(%s) timeout is a whole number of seconds', $id));
      }
      $out['timeout'] = $entry['timeout'];
    }
    return $out;
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
    if ($this->house !== []) {
      $out['house'] = $this->house;
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
      if ($key === 'house') {
        $out[$key] = $rule;
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
