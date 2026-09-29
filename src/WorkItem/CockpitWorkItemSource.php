<?php

declare(strict_types=1);

namespace Droost\Workflow\WorkItem;

/**
 * Cockpit mode's work-item source: tickets from the cockpit's HTTP API.
 *
 * `docs/cockpit-provider-api.md` is the contract. A ticket fetched at bind is
 * cached in the state directory as `work-item-<id>.json`, so a restart binds
 * from the cache when the cockpit is down; a ticket never seen is never
 * bound. Tickets are moved in the cockpit: the engine's own moves are
 * refused here, and the cockpit reads the run's progress from its events.
 */
final class CockpitWorkItemSource implements WorkItemSourceInterface {

  /**
   * The source name every ticket from here carries.
   */
  public const SOURCE = 'droost_cockpit';

  /**
   * Constructs the source.
   *
   * @param \Droost\Workflow\WorkItem\CockpitHttp $http
   *   The client.
   * @param string $base
   *   The API's base: the cockpit URL and the path, no trailing slash.
   * @param string $token
   *   The bearer token.
   * @param string $stateDir
   *   The state directory, absolute, where the cache lives.
   * @param string|null $missing
   *   Why the source cannot reach the cockpit before it tries (an unset
   *   environment variable, named), or NULL.
   */
  public function __construct(
    private readonly CockpitHttp $http,
    private readonly string $base,
    private readonly string $token,
    private readonly string $stateDir,
    private readonly ?string $missing = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get(string $id): ?WorkItem {
    $id = trim($id);
    $response = $this->missing === NULL
      ? $this->http->request('GET', $this->base . '/items/' . rawurlencode($id), NULL, $this->token)
      : ['status' => 0, 'body' => NULL, 'error' => $this->missing];
    if ($response['status'] === 404) {
      return NULL;
    }
    if ($response['status'] === 200 && is_array($response['body']['item'] ?? NULL)) {
      $item = $this->item($response['body']['item']);
      if ($item->id !== $id) {
        // Bound and cached under the id asked for, it would be another
        // ticket's record wearing this one's name.
        throw WorkItemError::cockpitUnreachable($id, sprintf('it answered with %s', $item->id));
      }
      $this->cache($item);
      return $item;
    }
    // The cockpit did not answer as it should: bind from the cache, or not
    // at all. A ticket this project has never seen is never bound.
    $cached = $this->cached($id);
    if ($cached !== NULL && $cached->id === $id) {
      return $cached;
    }
    throw WorkItemError::cockpitUnreachable($id, $response['error'] ?? 'no ticket in its answer');
  }

  /**
   * {@inheritdoc}
   */
  public function list(?string $status = NULL): array {
    if ($status !== NULL && !in_array($status, WorkItem::STATES, TRUE)) {
      throw WorkItemError::unknownState($status);
    }
    $response = $this->call('GET', '/items' . ($status === NULL ? '' : '?status=' . rawurlencode($status)), NULL);
    $items = $response['items'] ?? NULL;
    if (!is_array($items)) {
      throw WorkItemError::cockpitUnreachable('the ticket list', 'no items in its answer');
    }
    $list = [];
    foreach ($items as $item) {
      if (is_array($item)) {
        $list[] = $this->item($item);
      }
    }
    return $list;
  }

  /**
   * {@inheritdoc}
   */
  public function create(string $title, string $type, string $body = ''): WorkItem {
    if (trim($title) === '' || trim($type) === '') {
      throw new \InvalidArgumentException('A ticket needs a title and a type: ticket new --title="…" [--type=feature].');
    }
    // `body` only when there is one: additive in /v1, and a server that keeps
    // no text ignores it.
    $request = ['title' => trim($title), 'type' => trim($type)];
    if (trim($body) !== '') {
      $request['body'] = $body;
    }
    $response = $this->call('POST', '/items', $request);
    if (!is_array($response['item'] ?? NULL)) {
      throw WorkItemError::cockpitUnreachable('the new ticket', 'no item in its answer');
    }
    return $this->item($response['item']);
  }

  /**
   * {@inheritdoc}
   */
  public function transition(string $id, string $to, string $reason): WorkItem {
    throw WorkItemError::moveInTheCockpit();
  }

  /**
   * One call whose answer must be 2xx JSON.
   *
   * @param string $method
   *   GET or POST.
   * @param string $path
   *   The path under the base, with its query.
   * @param array<string, mixed>|null $json
   *   The body.
   *
   * @return array<string, mixed>
   *   The decoded answer.
   */
  private function call(string $method, string $path, ?array $json): array {
    if ($this->missing !== NULL) {
      throw WorkItemError::cockpitUnreachable($path, $this->missing);
    }
    $response = $this->http->request($method, $this->base . $path, $json, $this->token);
    if ($response['error'] !== NULL || $response['body'] === NULL) {
      throw WorkItemError::cockpitUnreachable($path, $response['error'] ?? 'no answer');
    }
    return $response['body'];
  }

  /**
   * A ticket from the API's JSON, as this source reports it.
   *
   * @param array<array-key, mixed> $data
   *   One item, WorkItem::toArray()'s shape.
   *
   * @return \Droost\Workflow\WorkItem\WorkItem
   *   The ticket.
   */
  private function item(array $data): WorkItem {
    $id = $data['id'] ?? NULL;
    $title = $data['title'] ?? NULL;
    $status = $data['status'] ?? NULL;
    if (!is_string($id) || $id === '' || !is_string($title) || !is_string($status)) {
      throw WorkItemError::cockpitUnreachable(is_string($id) ? $id : 'a ticket', 'an item without id, title and status');
    }
    if (!in_array($status, WorkItem::STATES, TRUE)) {
      throw WorkItemError::unknownState($status);
    }
    $extra = [];
    foreach (is_array($data['extra'] ?? NULL) ? $data['extra'] : [] as $key => $value) {
      $extra[(string) $key] = $value;
    }
    $number = $data['number'] ?? NULL;
    $type = $data['type'] ?? NULL;
    $body = $data['body'] ?? '';

    return new WorkItem(
      $id,
      is_int($number) ? $number : NULL,
      $title,
      $status,
      is_string($type) && $type !== '' ? $type : NULL,
      is_string($body) ? $body : '',
      $extra,
      NULL,
      self::SOURCE,
    );
  }

  /**
   * The cache file for a ticket id.
   *
   * @param string $id
   *   The id.
   *
   * @return string
   *   Its path.
   */
  private function cachePath(string $id): string {
    return rtrim($this->stateDir, '/') . '/work-item-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $id) . '.json';
  }

  /**
   * Keeps a fetched ticket for an offline restart.
   *
   * @param \Droost\Workflow\WorkItem\WorkItem $item
   *   The ticket.
   */
  private function cache(WorkItem $item): void {
    $path = $this->cachePath($item->id);
    if (!is_dir(dirname($path)) || is_link($path)) {
      return;
    }
    $temp = @tempnam(dirname($path), '.work-item-');
    if ($temp === FALSE) {
      return;
    }
    $json = json_encode($item->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || file_put_contents($temp, $json . "\n") === FALSE || !@rename($temp, $path)) {
      @unlink($temp);
    }
  }

  /**
   * The cached ticket, or NULL when there is none.
   *
   * @param string $id
   *   The id.
   *
   * @return \Droost\Workflow\WorkItem\WorkItem|null
   *   The ticket as last fetched.
   */
  private function cached(string $id): ?WorkItem {
    $path = $this->cachePath($id);
    if (!is_file($path) || is_link($path)) {
      return NULL;
    }
    $data = json_decode((string) file_get_contents($path), TRUE);
    return is_array($data) ? $this->item($data) : NULL;
  }

}
