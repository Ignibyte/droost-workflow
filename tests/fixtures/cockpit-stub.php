<?php

/**
 * @file
 * The cockpit provider API's executable form: docs/cockpit-provider-api.md.
 *
 * A `php -S` router over one JSON state file (COCKPIT_STUB_STATE), so a test
 * seeds it, drives the client, and reads what the server saw:
 *
 *   php -S 127.0.0.1:<port> tests/fixtures/cockpit-stub.php
 *
 * The state holds `tokens` (token => {allowed: bool}), `items` (id =>
 * WorkItem), `events` (event_id => RunEvent), `requests` (what arrived:
 * method, path, whether it was authorised; never a token) and four
 * switches: `fail` (answer every request 503), `drop_next` (store the next
 * batch of events, then cut the answer short, as a lost answer),
 * `redirect_to` (answer every request 302 there) and `echo_token` (answer
 * every request 500 with the Authorization header in the error, as a
 * careless server would). The base path is COCKPIT_STUB_BASE, default
 * /work-items/v1.
 *
 * `$routes` is the stub's route table, and every request is dispatched
 * through it. CockpitContractTest holds it to the document's.
 */

declare(strict_types=1);

/**
 * The stub's state, read from its file and given its types.
 *
 * @param string $path
 *   The state file.
 *
 * @return array{tokens: array<string, array{allowed: bool}>, items: array<string, array<string, mixed>>, events: array<string, array<string, mixed>>, requests: list<array<string, mixed>>, fail: bool, drop_next: bool, redirect_to: string, echo_token: bool, next_number: int}
 *   The state.
 */
function cockpit_stub_state(string $path): array {
  $raw = cockpit_stub_record(json_decode((string) @file_get_contents($path), TRUE));
  $tokens = [];
  foreach (cockpit_stub_record($raw['tokens'] ?? NULL) as $token => $grant) {
    $tokens[$token] = ['allowed' => (cockpit_stub_record($grant)['allowed'] ?? FALSE) === TRUE];
  }
  $requests = [];
  foreach (is_array($raw['requests'] ?? NULL) ? $raw['requests'] : [] as $request) {
    $requests[] = cockpit_stub_record($request);
  }

  return [
    'tokens' => $tokens,
    'items' => cockpit_stub_records($raw['items'] ?? NULL),
    'events' => cockpit_stub_records($raw['events'] ?? NULL),
    'requests' => $requests,
    'fail' => ($raw['fail'] ?? FALSE) === TRUE,
    'drop_next' => ($raw['drop_next'] ?? FALSE) === TRUE,
    'redirect_to' => is_string($raw['redirect_to'] ?? NULL) ? $raw['redirect_to'] : '',
    'echo_token' => ($raw['echo_token'] ?? FALSE) === TRUE,
    'next_number' => is_int($raw['next_number'] ?? NULL) ? $raw['next_number'] : 1,
  ];
}

/**
 * A decoded JSON object as a string-keyed array; anything else as empty.
 *
 * @param mixed $value
 *   The decoded value.
 *
 * @return array<string, mixed>
 *   The record.
 */
function cockpit_stub_record(mixed $value): array {
  $record = [];
  foreach (is_array($value) ? $value : [] as $key => $field) {
    $record[(string) $key] = $field;
  }
  return $record;
}

/**
 * A decoded JSON object of objects, each as a record.
 *
 * @param mixed $value
 *   The decoded value.
 *
 * @return array<string, array<string, mixed>>
 *   The records, by key.
 */
function cockpit_stub_records(mixed $value): array {
  $records = [];
  foreach (cockpit_stub_record($value) as $key => $record) {
    if (is_array($record)) {
      $records[$key] = cockpit_stub_record($record);
    }
  }
  return $records;
}

/**
 * An item as the document shapes it: `extra` is an object even when empty.
 *
 * @param array<string, mixed> $item
 *   The item as stored.
 *
 * @return array<string, mixed>
 *   The item as answered.
 */
function cockpit_stub_shaped(array $item): array {
  return ['extra' => (object) cockpit_stub_record($item['extra'] ?? NULL)] + $item;
}

/**
 * Writes the state back.
 *
 * @param string $path
 *   The state file.
 * @param array<string, mixed> $state
 *   The state.
 */
function cockpit_stub_save(string $path, array $state): void {
  file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Answers with JSON, saving the state first.
 *
 * @param string $path
 *   The state file.
 * @param array<string, mixed> $state
 *   The state.
 * @param int $status
 *   The HTTP status.
 * @param array<string, mixed> $body
 *   The body.
 * @param list<string> $headers
 *   Headers besides the content type.
 */
function cockpit_stub_answer(string $path, array $state, int $status, array $body, array $headers = []): never {
  cockpit_stub_save($path, $state);
  http_response_code($status);
  header('Content-Type: application/json');
  foreach ($headers as $line) {
    header($line);
  }
  echo json_encode($body === [] ? new stdClass() : $body, JSON_UNESCAPED_SLASHES);
  exit;
}

$routes = [
  ['GET', '/items/{id}'],
  ['GET', '/items'],
  ['POST', '/items'],
  ['POST', '/events'],
];
$states = ['backlog', 'ready', 'in_progress', 'review', 'done'];

$statePath = (string) getenv('COCKPIT_STUB_STATE');
$base = rtrim(getenv('COCKPIT_STUB_BASE') ?: '/work-items/v1', '/');
$state = cockpit_stub_state($statePath);

$method = is_string($_SERVER['REQUEST_METHOD'] ?? NULL) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$uri = is_string($_SERVER['REQUEST_URI'] ?? NULL) ? $_SERVER['REQUEST_URI'] : '/';
$path = (string) parse_url($uri, PHP_URL_PATH);
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
$header = is_string($_SERVER['HTTP_AUTHORIZATION'] ?? NULL) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
$token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
$known = $token !== '' && isset($state['tokens'][$token]);
$allowed = $known && $state['tokens'][$token]['allowed'];
$state['requests'][] = ['method' => $method, 'path' => $path, 'query' => $query, 'authorised' => $known];

if ($state['redirect_to'] !== '') {
  cockpit_stub_answer($statePath, $state, 302, ['error' => 'moved'], ['Location: ' . $state['redirect_to']]);
}
if ($state['echo_token']) {
  cockpit_stub_answer($statePath, $state, 500, ['error' => 'could not parse ' . $header]);
}
if ($state['fail']) {
  cockpit_stub_answer($statePath, $state, 503, ['error' => 'the cockpit is down for maintenance']);
}

// The route, found in the table.
$route = NULL;
$id = '';
if (str_starts_with($path, $base . '/')) {
  $relative = substr($path, strlen($base));
  foreach ($routes as [$verb, $template]) {
    $pattern = '#^' . str_replace('\{id\}', '([^/]+)', preg_quote($template, '#')) . '$#';
    if ($verb === $method && preg_match($pattern, $relative, $m) === 1) {
      $route = $verb . ' ' . $template;
      $id = rawurldecode($m[1] ?? '');
      break;
    }
  }
}
if ($route === NULL) {
  cockpit_stub_answer($statePath, $state, 404, ['error' => 'no such route']);
}
if (!$known) {
  cockpit_stub_answer($statePath, $state, 401, ['error' => 'a bearer token is required, and this one is unknown']);
}

$raw = (string) file_get_contents('php://input');
$body = cockpit_stub_record(json_decode($raw, TRUE));

switch ($route) {
  case 'GET /items/{id}':
    // One ticket.
    if (!$allowed) {
      cockpit_stub_answer($statePath, $state, 403, ['error' => 'this seat may not read that ticket']);
    }
    if (!isset($state['items'][$id])) {
      cockpit_stub_answer($statePath, $state, 404, ['error' => 'no ticket ' . $id]);
    }
    cockpit_stub_answer($statePath, $state, 200, ['item' => cockpit_stub_shaped($state['items'][$id])]);

  case 'GET /items':
    // The queue, or one state of it.
    if (!$allowed) {
      cockpit_stub_answer($statePath, $state, 403, ['error' => 'this seat may not read tickets']);
    }
    $status = is_string($query['status'] ?? NULL) ? $query['status'] : NULL;
    if ($status !== NULL && !in_array($status, $states, TRUE)) {
      cockpit_stub_answer($statePath, $state, 400, ['error' => 'unknown state']);
    }
    $items = [];
    foreach ($state['items'] as $item) {
      if ($status === NULL || ($item['status'] ?? NULL) === $status) {
        $items[] = cockpit_stub_shaped($item);
      }
    }
    cockpit_stub_answer($statePath, $state, 200, ['items' => $items]);

  case 'POST /items':
    // A new ticket, filed in backlog.
    if (!$allowed) {
      cockpit_stub_answer($statePath, $state, 403, ['error' => 'this seat may not file tickets']);
    }
    $title = is_string($body['title'] ?? NULL) ? $body['title'] : '';
    $type = is_string($body['type'] ?? NULL) ? $body['type'] : '';
    if ($title === '' || $type === '') {
      cockpit_stub_answer($statePath, $state, 400, ['error' => 'a ticket needs a title and a type']);
    }
    $number = $state['next_number'];
    $state['next_number'] = $number + 1;
    $item = [
      'id' => 'TICKET-' . $number,
      'number' => $number,
      'title' => $title,
      'status' => 'backlog',
      'type' => $type,
      'body' => '',
      'extra' => [],
    ];
    $state['items'][$item['id']] = $item;
    cockpit_stub_answer($statePath, $state, 201, ['item' => cockpit_stub_shaped($item)]);

  default:
    // A batch of run events, stored once each by event_id.
    $events = $body['events'] ?? NULL;
    if (!is_array($events) || !array_is_list($events)) {
      cockpit_stub_answer($statePath, $state, 400, ['error' => 'expected {"events": [...]}']);
    }
    // The events' bytes as sent: the body less its `{"events":` and `}`.
    if (count($events) > 100 || strlen($raw) - strlen('{"events":}') > 262144 + 2) {
      cockpit_stub_answer($statePath, $state, 413, ['error' => 'at most 100 events and 256 KiB a request']);
    }
    if (!$allowed) {
      cockpit_stub_answer($statePath, $state, 403, ['error' => 'this seat may not post events']);
    }
    $accepted = 0;
    $duplicates = 0;
    foreach ($events as $event) {
      $event = cockpit_stub_record($event);
      $eventId = $event['event_id'] ?? NULL;
      if (!is_string($eventId)) {
        cockpit_stub_answer($statePath, $state, 400, ['error' => 'every event needs an event_id']);
      }
      if (isset($state['events'][$eventId])) {
        $duplicates++;
        continue;
      }
      $state['events'][$eventId] = $event;
      $accepted++;
    }
    if ($state['drop_next']) {
      // Stored, then the answer is lost on the way back: the client must see
      // a failure and send the batch again.
      $state['drop_next'] = FALSE;
      cockpit_stub_save($statePath, $state);
      http_response_code(200);
      header('Content-Type: application/json');
      echo '{"accepted":';
      exit;
    }
    cockpit_stub_answer($statePath, $state, 200, ['accepted' => $accepted, 'duplicates' => $duplicates]);
}
