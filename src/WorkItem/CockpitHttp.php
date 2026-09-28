<?php

declare(strict_types=1);

namespace Droost\Workflow\WorkItem;

/**
 * The cockpit's HTTP client: PHP streams, and nothing else.
 *
 * No dependency: the `http` stream wrapper, with redirects never followed,
 * every status read rather than thrown, a bearer token in the header, and a
 * five-second bound on connecting and on reading. Only `http` and `https`
 * URLs are sent. The token is never part of anything this returns, so no
 * error, log line or cache built from a result can carry it.
 */
final class CockpitHttp {

  /**
   * The bound on connecting and on each read, in seconds.
   */
  public const TIMEOUT = 5;

  /**
   * How a body is encoded: as the run-event log writes a line.
   *
   * So an event goes byte for byte as it was logged.
   */
  public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

  /**
   * Sends one request.
   *
   * @param string $method
   *   GET or POST.
   * @param string $url
   *   The absolute URL.
   * @param array<string, mixed>|null $json
   *   The body, sent as JSON, or NULL for none.
   * @param string $token
   *   The bearer token.
   *
   * @return array{status: int, body: array<string, mixed>|null, error: string|null}
   *   The status (0 when nothing answered), the decoded JSON body, and a
   *   one-line reason when the request did not get a JSON answer.
   */
  public function request(string $method, string $url, ?array $json, string $token): array {
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], TRUE)) {
      return ['status' => 0, 'body' => NULL, 'error' => 'the cockpit URL must be http or https'];
    }
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
      return ['status' => 0, 'body' => NULL, 'error' => 'PHP has allow_url_fopen off, so the cockpit cannot be reached'];
    }
    // A token is one header value: a line break in it would write headers of
    // its own. Refused without saying what it held.
    if ($token === '' || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
      return [
        'status' => 0,
        'body' => NULL,
        'error' => 'the cockpit token is empty or holds a space or control character',
      ];
    }
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
    $content = NULL;
    if ($json !== NULL) {
      $content = json_encode($json, self::JSON_FLAGS);
      $headers[] = 'Content-Type: application/json';
      $headers[] = 'Content-Length: ' . strlen((string) $content);
    }
    $context = stream_context_create([
      'http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $content ?? '',
        'follow_location' => 0,
        'max_redirects' => 0,
        'ignore_errors' => TRUE,
        'timeout' => self::TIMEOUT,
        'protocol_version' => 1.1,
      ],
    ]);
    // The wrapper's `timeout` bounds each read; connecting is bounded by
    // default_socket_timeout, which is 60 s unless said otherwise.
    $previous = ini_set('default_socket_timeout', (string) self::TIMEOUT);
    $http_response_header = [];
    if (function_exists('http_clear_last_response_headers')) {
      // A request nothing answers leaves the last one's headers otherwise.
      http_clear_last_response_headers();
    }
    try {
      $raw = @file_get_contents($url, FALSE, $context);
    }
    finally {
      if ($previous !== FALSE) {
        ini_set('default_socket_timeout', $previous);
      }
    }
    // PHP 8.4 has the function, and 8.5 deprecates the variable it replaces.
    $received = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : $http_response_header;
    $status = 0;
    foreach ($received as $line) {
      if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
        $status = (int) $m[1];
      }
    }
    if ($raw === FALSE || $status === 0) {
      return ['status' => 0, 'body' => NULL, 'error' => sprintf('nothing answered at %s', self::origin($url))];
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      return [
        'status' => $status,
        'body' => NULL,
        'error' => sprintf('%s answered %d with no JSON body', self::origin($url), $status),
      ];
    }
    $body = [];
    foreach ($decoded as $key => $value) {
      $body[(string) $key] = $value;
    }
    $error = NULL;
    if ($status < 200 || $status >= 300) {
      // The server's own words, less the token, should it echo one back.
      $said = is_string($body['error'] ?? NULL) ? ': ' . self::oneLine(str_replace($token, '[token]', $body['error'])) : '';
      $error = sprintf('%s answered %d%s', self::origin($url), $status, $said);
    }

    return ['status' => $status, 'body' => $body, 'error' => $error];
  }

  /**
   * A URL's origin, for a message: scheme, host and port, nothing after.
   *
   * @param string $url
   *   The URL.
   *
   * @return string
   *   The origin.
   */
  public static function origin(string $url): string {
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
      return 'the cockpit';
    }
    return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
  }

  /**
   * A server's message, kept to one short line.
   *
   * @param string $text
   *   The message.
   *
   * @return string
   *   The line.
   */
  private static function oneLine(string $text): string {
    return substr(trim((string) preg_replace('/\s+/', ' ', $text)), 0, 200);
  }

}
