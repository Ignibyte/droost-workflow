<?php

declare(strict_types=1);

namespace Droost\Workflow\WorkItem;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Tickets as markdown files in the repo: solo mode's work-item source.
 *
 * The format is the one druplit already keeps its own tickets in, so those
 * read as they stand: `<dir>/{open,closed}/<PREFIX>-<n>-<slug>.md`, a YAML
 * frontmatter holding at least `title`, `status` and `ticket_number`, and the
 * ticket's sections after it. The FILENAME is the identity: its number is the
 * ticket's number, and a frontmatter `ticket_number` that says otherwise (a
 * historical tracker's number) is kept as an extra key. Two historical names
 * are read too: a split ticket, `<PREFIX>-<n><letter>-<slug>.md`, whose id
 * keeps its letter (so a number may repeat across a split), and a numberless
 * `<PREFIX>-<slug>.md`, whose id is its name and whose number is NULL. A file
 * named for the prefix in neither shape is refused by name, never skipped.
 * A `done` ticket lives in `closed/` and every other state in `open/`; the
 * legacy `open` and `closed` statuses read as `ready` and `done`, and a write
 * always uses the new vocabulary.
 *
 * A write changes one line, `status:`, or creates a new file, and nothing
 * else: every other frontmatter key and the whole body stay byte for byte as
 * the file had them. It goes through a temporary file and a rename, keeps the
 * target's mode, and never follows a symlink.
 */
final class MarkdownWorkItemSource implements WorkItemSourceInterface {

  /**
   * Where the tickets live when the lever file names no directory.
   */
  public const DEFAULT_DIR = 'droost/tickets';

  /**
   * What a ticket's id starts with when the lever file names no prefix.
   */
  public const DEFAULT_PREFIX = 'TICKET';

  /**
   * The legacy statuses, and what each reads as.
   */
  private const LEGACY = ['open' => 'ready', 'closed' => 'done'];

  /**
   * The frontmatter keys a ticket carries as fields rather than as extra.
   */
  private const FIELDS = ['title', 'status', 'ticket_number', 'type'];

  /**
   * The ticket directory, absolute.
   */
  private readonly string $dir;

  /**
   * The ticket directory as a reader should see it: project-relative.
   */
  private readonly string $label;

  /**
   * Today's date for a new ticket, as `Y-m-d`.
   *
   * @var \Closure(): string
   */
  private readonly \Closure $today;

  /**
   * Constructs the source.
   *
   * @param string $dir
   *   The ticket directory: relative to the project root when one is given.
   * @param string $prefix
   *   What every ticket id starts with, e.g. TICKET.
   * @param string|null $projectRoot
   *   The project the directory is resolved against, and must stay inside.
   * @param (callable(): string)|null $today
   *   Today's date as `Y-m-d`, for a new ticket's `created`; the system date
   *   when NULL.
   *
   * @throws \Droost\Workflow\WorkItem\WorkItemError
   *   When the prefix is not a plain word, or the directory escapes the
   *   project or is a symlink.
   */
  public function __construct(
    string $dir,
    private readonly string $prefix = self::DEFAULT_PREFIX,
    ?string $projectRoot = NULL,
    ?callable $today = NULL,
  ) {
    if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $prefix) !== 1) {
      throw WorkItemError::refusedPath($prefix, 'a ticket prefix is letters, digits and underscores, starting with a letter');
    }
    $dir = rtrim($dir, '/');
    if ($projectRoot !== NULL) {
      $root = rtrim($projectRoot, '/');
      if ($dir === '' || str_starts_with($dir, '/') || in_array('..', explode('/', $dir), TRUE)) {
        throw WorkItemError::refusedPath($dir, 'the ticket directory is a path inside the project, relative to its root');
      }
      // Every existing step from the root down, so a symlink anywhere on the
      // way cannot carry the writes outside the project.
      $walked = $root;
      foreach (explode('/', $dir) as $segment) {
        if ($segment === '' || $segment === '.') {
          continue;
        }
        $walked .= '/' . $segment;
        if (is_link($walked)) {
          throw WorkItemError::refusedPath($walked, 'a ticket directory is never reached through a symlink');
        }
      }
      $this->dir = $root . '/' . $dir;
      $this->label = $dir;
    }
    else {
      $this->dir = $dir;
      $this->label = $dir;
    }
    $this->today = $today === NULL
      ? static fn (): string => date('Y-m-d')
      : \Closure::fromCallable($today);
  }

  /**
   * {@inheritdoc}
   */
  public function get(string $id): ?WorkItem {
    $id = trim($id);
    // A bare number names the numbered ticket, never a split one: `59` is
    // TICKET-59, and TICKET-59b is asked for by its id.
    if (ctype_digit($id)) {
      $id = $this->prefix . '-' . (int) $id;
    }
    $found = array_values(array_filter(
      $this->files(),
      static fn (array $file): bool => $file['id'] === $id,
    ));
    if ($found === []) {
      return NULL;
    }
    if (count($found) > 1) {
      throw WorkItemError::unreadable($this->label, sprintf(
        'two files claim %s (%s)',
        $id,
        implode(', ', array_column($found, 'relative')),
      ));
    }

    return $this->parse($found[0]);
  }

  /**
   * {@inheritdoc}
   */
  public function list(?string $status = NULL): array {
    if ($status !== NULL) {
      $status = self::LEGACY[$status] ?? $status;
      if (!in_array($status, WorkItem::STATES, TRUE)) {
        throw WorkItemError::unknownState($status);
      }
    }
    $items = [];
    foreach ($this->files() as $file) {
      $item = $this->parse($file);
      if ($status === NULL || $item->status === $status) {
        $items[] = $item;
      }
    }
    // By number, then id (59 before 59b); the numberless last.
    $order = static fn (WorkItem $item): array => [$item->number === NULL, $item->number ?? 0, $item->id];
    usort($items, static fn (WorkItem $a, WorkItem $b): int => $order($a) <=> $order($b));

    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function create(string $title, string $type, string $body = ''): WorkItem {
    $title = trim($title);
    $type = trim($type);
    if ($title === '' || $type === '') {
      throw new \InvalidArgumentException('A ticket needs a title and a type: ticket new --title="…" [--type=feature].');
    }
    // One past the highest number any file's NAME carries; a numberless
    // ticket has none, and a frontmatter number is a different fact.
    $highest = 0;
    foreach ($this->files() as $file) {
      $highest = max($highest, $file['number'] ?? 0);
    }
    $number = $highest + 1;
    $slug = trim(substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-'), 0, 60), '-');
    $name = sprintf('%s-%d-%s.md', $this->prefix, $number, $slug === '' ? 'ticket' : $slug);
    $open = $this->subdir('open', TRUE);
    $path = $open . '/' . $name;
    if (file_exists($path) || is_link($path)) {
      throw WorkItemError::refusedPath($this->label . '/open/' . $name, 'a file of that name is already there');
    }
    $sections = trim($body) === ''
      ? "## Summary\n\n## Why\n\n## EARS Requirements\n\n| ID | EARS Requirement | Verification |\n|---|---|---|\n\n"
        . "## Scope\n\n## Notes\n"
      : rtrim($body) . "\n";
    $content = sprintf(
      "---\ntitle: %s\nstatus: backlog\nticket_number: %d\ntype: %s\ncreated: %s\n---\n\n# %s\n\n%s",
      Yaml::dump($title),
      $number,
      Yaml::dump($type),
      ($this->today)(),
      $title,
      $sections,
    );
    $this->writeAtomically($path, $content, 0666 & ~umask());

    return $this->parse([
      'path' => $path,
      'relative' => $this->label . '/open/' . $name,
      'id' => $this->prefix . '-' . $number,
      'number' => $number,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function transition(string $id, string $to, string $reason): WorkItem {
    if (!in_array($to, WorkItem::STATES, TRUE)) {
      throw WorkItemError::unknownState($to);
    }
    $item = $this->get($id);
    if ($item === NULL || $item->path === NULL) {
      throw WorkItemError::notFound($id, $this->label);
    }
    if ($item->status === $to) {
      return $item;
    }
    $file = $this->pathOf($item->path);
    $content = (string) file_get_contents($file);
    // Only the status line, and only inside the frontmatter: a body that
    // quotes a `status:` line keeps it.
    if (preg_match('/\A---\R.*?^---[ \t]*$/ms', $content, $front) !== 1
      || preg_match('/^status:[^\r\n]*/m', $front[0]) !== 1) {
      throw WorkItemError::unreadable($item->path, 'no status line in its frontmatter');
    }
    $rewritten = (string) preg_replace('/^status:[^\r\n]*/m', 'status: ' . $to, $front[0], 1);
    $content = $rewritten . substr($content, strlen($front[0]));

    $target = $this->subdir($to === 'done' ? 'closed' : 'open', TRUE) . '/' . basename($file);
    if ($target !== $file && (file_exists($target) || is_link($target))) {
      throw WorkItemError::refusedPath($target, 'a file of that name is already in the directory the ticket would move to');
    }
    $mode = fileperms($file);
    $this->writeAtomically($target, $content, $mode === FALSE ? 0644 : $mode & 0777);
    if ($target !== $file) {
      unlink($file);
    }

    return $this->parse([
      'path' => $target,
      'relative' => $this->label . '/' . ($to === 'done' ? 'closed' : 'open') . '/' . basename($file),
      'id' => $item->id,
      'number' => $item->number,
    ]);
  }

  /**
   * Every ticket file under open/ and closed/, with the id its name gives.
   *
   * @return list<array{path: string, relative: string, id: string, number: int|null}>
   *   The files.
   *
   * @throws \Droost\Workflow\WorkItem\WorkItemError
   *   When a markdown file is named for the prefix in no shape a ticket has.
   */
  private function files(): array {
    $prefix = preg_quote($this->prefix, '/');
    $files = [];
    foreach (['open', 'closed'] as $state) {
      $dir = $this->subdir($state, FALSE);
      if ($dir === NULL) {
        continue;
      }
      foreach (scandir($dir) ?: [] as $name) {
        if (!str_starts_with($name, $this->prefix . '-') || !str_ends_with($name, '.md')) {
          continue;
        }
        $relative = $this->label . '/' . $state . '/' . $name;
        if (preg_match('/^' . $prefix . '-(\d+)([a-z]?)(?:-[^\/]+)?\.md$/', $name, $m) === 1) {
          $id = $this->prefix . '-' . (int) $m[1] . $m[2];
          $number = (int) $m[1];
        }
        elseif (preg_match('/^' . $prefix . '-([A-Za-z][^\/]*)\.md$/', $name, $m) === 1) {
          $id = $this->prefix . '-' . $m[1];
          $number = NULL;
        }
        else {
          throw WorkItemError::unreadable($relative, sprintf(
            'its name is none of %1$s-<n>-<slug>.md, %1$s-<n><letter>-<slug>.md or %1$s-<slug>.md',
            $this->prefix,
          ));
        }
        $files[] = ['path' => $dir . '/' . $name, 'relative' => $relative, 'id' => $id, 'number' => $number];
      }
    }

    return $files;
  }

  /**
   * One of the two state directories, refused when it is a symlink.
   *
   * @param string $name
   *   Either `open` or `closed`.
   * @param bool $create
   *   Whether to create it when it is absent.
   *
   * @return ($create is true ? string : string|null)
   *   Its absolute path, or NULL when it is absent and not created.
   */
  private function subdir(string $name, bool $create): ?string {
    foreach ([$this->dir, $this->dir . '/' . $name] as $path) {
      if (is_link($path)) {
        throw WorkItemError::refusedPath($path, 'a ticket directory is never reached through a symlink');
      }
    }
    $path = $this->dir . '/' . $name;
    if (!is_dir($path)) {
      if (!$create) {
        return NULL;
      }
      if (!mkdir($path, 0777, TRUE) && !is_dir($path)) {
        throw WorkItemError::refusedPath($path, 'it could not be created');
      }
    }

    return $path;
  }

  /**
   * Reads one ticket file.
   *
   * @param array{path: string, relative: string, id: string, number: int|null} $file
   *   The file, as files() found it.
   *
   * @return \Droost\Workflow\WorkItem\WorkItem
   *   The ticket.
   */
  private function parse(array $file): WorkItem {
    ['path' => $path, 'relative' => $relative, 'id' => $id, 'number' => $number] = $file;
    if (is_link($path)) {
      throw WorkItemError::refusedPath($relative, 'a ticket file is never read through a symlink');
    }
    $content = file_get_contents($path);
    if (!is_string($content)) {
      throw WorkItemError::unreadable($relative, 'it could not be read');
    }
    if (preg_match('/\A---\R(.*?)\R---[ \t]*(?:\R|\z)/s', $content, $m) !== 1) {
      throw WorkItemError::unreadable($relative, 'it has no YAML frontmatter (a leading `---` block)');
    }
    try {
      $front = Yaml::parse($m[1]);
    }
    catch (ParseException $e) {
      throw WorkItemError::unreadable($relative, 'its frontmatter is not YAML (' . $e->getMessage() . ')');
    }
    if (!is_array($front)) {
      throw WorkItemError::unreadable($relative, 'its frontmatter is not a map of keys');
    }
    // A numbered ticket states its number; a numberless one (a historical
    // name) cannot, and is not asked to.
    foreach ($number === NULL ? ['title', 'status'] : ['title', 'status', 'ticket_number'] as $required) {
      if (!array_key_exists($required, $front) || $front[$required] === NULL || $front[$required] === '') {
        throw WorkItemError::unreadable($relative, sprintf('its frontmatter has no %s', $required));
      }
    }
    $raw = self::rawScalars($m[1]);
    $status = (string) (is_scalar($front['status']) ? $front['status'] : '');
    $status = self::LEGACY[$status] ?? $status;
    if (!in_array($status, WorkItem::STATES, TRUE)) {
      throw WorkItemError::unreadable($relative, sprintf(
        'its status "%s" is none of %s (open and closed are read as ready and done)',
        is_scalar($front['status']) ? (string) $front['status'] : gettype($front['status']),
        implode(', ', WorkItem::STATES),
      ));
    }
    $extra = [];
    foreach ($front as $key => $value) {
      $key = (string) $key;
      // The number its name carries is the ticket's; a ticket_number that
      // says otherwise is another fact (an old tracker's), kept as it is.
      if ($key === 'ticket_number' && $number !== NULL && (string) (is_scalar($value) ? $value : '') === (string) $number) {
        continue;
      }
      if ($key !== 'ticket_number' && in_array($key, self::FIELDS, TRUE)) {
        continue;
      }
      $extra[$key] = self::asWritten($value, $raw[$key] ?? NULL);
    }
    $type = $front['type'] ?? NULL;
    $title = self::asWritten($front['title'], $raw['title'] ?? NULL);
    if (!is_scalar($title)) {
      throw WorkItemError::unreadable($relative, 'its title is not text');
    }

    return new WorkItem(
      $id,
      $number,
      (string) $title,
      $status,
      is_scalar($type) && (string) $type !== '' ? (string) $type : NULL,
      substr($content, strlen($m[0])),
      $extra,
      $relative,
      'markdown',
    );
  }

  /**
   * The project-relative path back to an absolute one.
   *
   * @param string $relative
   *   The path as this source reports it.
   *
   * @return string
   *   The absolute path.
   */
  private function pathOf(string $relative): string {
    return $this->dir . substr($relative, strlen($this->label));
  }

  /**
   * Each top-level frontmatter key's value, as the file spells it.
   *
   * @param string $front
   *   The frontmatter.
   *
   * @return array<string, string>
   *   Raw values by key.
   */
  private static function rawScalars(string $front): array {
    $raw = [];
    if (preg_match_all('/^([A-Za-z0-9_-]+):[ \t]*([^\r\n]*)$/m', $front, $lines, PREG_SET_ORDER) !== FALSE) {
      foreach ($lines as $line) {
        $raw[$line[1]] ??= trim($line[2]);
      }
    }
    return $raw;
  }

  /**
   * A value as the file wrote it, where YAML would have changed it.
   *
   * YAML reads an unquoted `2026-07-19` as a timestamp, so a ticket's
   * `created` would come back as an integer; the date as written is what a
   * reader of the ticket means.
   *
   * @param mixed $value
   *   The parsed value.
   * @param string|null $raw
   *   The value as the file spells it.
   *
   * @return mixed
   *   The value to keep.
   */
  private static function asWritten(mixed $value, ?string $raw): mixed {
    if (is_int($value) && $raw !== NULL && preg_match('/^\d{4}-\d{2}-\d{2}([Tt ][0-9:.+\-Zz ]*)?$/', $raw) === 1) {
      return $raw;
    }
    return $value;
  }

  /**
   * Writes a file through a temporary one and a rename.
   *
   * @param string $target
   *   The file to write.
   * @param string $content
   *   What it holds.
   * @param int $mode
   *   The permissions it keeps.
   */
  private function writeAtomically(string $target, string $content, int $mode): void {
    $dir = dirname($target);
    // tempnam() falls back to the system's temporary directory when this one
    // is not writable, and a rename from there is no longer atomic; compared
    // as real paths, since the system's may itself be reached by a symlink.
    $temp = tempnam($dir, '.ticket-');
    if ($temp === FALSE || realpath(dirname($temp)) !== realpath($dir)) {
      if (is_string($temp)) {
        @unlink($temp);
      }
      throw WorkItemError::refusedPath($target, 'no temporary file could be made beside it');
    }
    if (file_put_contents($temp, $content) !== strlen($content) || !chmod($temp, $mode) || !rename($temp, $target)) {
      @unlink($temp);
      throw WorkItemError::refusedPath($target, 'it could not be written');
    }
  }

}
