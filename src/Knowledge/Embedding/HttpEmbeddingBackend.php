<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge\Embedding;

use Droost\Engine\Search\Embedding\EmbeddingBackendInterface;

/**
 * Embeds text through a model server, named in droost.yml.
 *
 * `search.embeddings` in `droost/droost.yml`:
 *
 * @code
 * search:
 *   embeddings:
 *     endpoint: http://localhost:11434/api/embeddings   # http(s) only
 *     model: nomic-embed-text
 *     format: ollama          # or openai (POST {model, input[]} to /v1/embeddings)
 *     dimension: 768          # optional; asked of the model when absent
 *     api_key_env: OPENAI_API_KEY   # optional: the NAME of an env variable
 *     scope: custom           # or all: which files code:index embeds
 * @endcode
 *
 * The key is read from the environment variable `api_key_env` names, never
 * from the file: droost.yml is committed. A file that holds `api_key` itself
 * makes the backend unavailable, and says why, rather than send a committed
 * secret. Ported from drupal/droost's HttpEmbeddingBackend with its limits:
 * http(s) only, no redirects, 4000 characters a chunk, one failed chunk
 * skipped alone.
 */
final class HttpEmbeddingBackend implements EmbeddingBackendInterface {

  /**
   * Maximum characters embedded per chunk (about 2000 tokens of code).
   */
  private const int MAX_EMBED_CHARS = 4000;

  /**
   * The dimension, once asked of the model.
   */
  private ?int $probed = NULL;

  /**
   * Why the last request failed, for the command's message.
   */
  private string $lastError = '';

  /**
   * Sends one request.
   *
   * @var \Closure(string, string, array<string, string>): string
   */
  private \Closure $transport;

  /**
   * Constructs an HttpEmbeddingBackend.
   *
   * @param array<string, string> $settings
   *   The `search.embeddings` settings, as strings.
   * @param (\Closure(string, string, array<string, string>): string)|null $transport
   *   Sends a JSON body to a URL with headers and returns the response body,
   *   throwing on failure; NULL uses PHP's own HTTP stream.
   * @param (\Closure(string): (string|false))|null $env
   *   Reads an environment variable; NULL uses getenv().
   */
  public function __construct(
    private readonly array $settings,
    ?\Closure $transport = NULL,
    private readonly ?\Closure $env = NULL,
  ) {
    $this->transport = $transport ?? self::streamTransport(...);
  }

  /**
   * Whether the project configured semantic search, usable or not.
   *
   * @return bool
   *   TRUE when an endpoint is named.
   */
  public function isConfigured(): bool {
    return $this->endpoint() !== '';
  }

  /**
   * Why the backend cannot be used, or '' when it can.
   *
   * @return string
   *   The reason.
   */
  public function unavailableReason(): string {
    if (!$this->isConfigured()) {
      return 'search.embeddings names no endpoint';
    }
    if ($this->model() === '') {
      return 'search.embeddings names no model';
    }
    if (array_key_exists('api_key', $this->settings)) {
      return 'search.embeddings holds an api_key, and droost.yml is committed: name the environment variable that holds it instead (api_key_env)';
    }
    $scheme = strtolower((string) parse_url($this->endpoint(), PHP_URL_SCHEME));
    if ($scheme !== 'http' && $scheme !== 'https') {
      return 'the embeddings endpoint must be an http(s) URL';
    }
    $variable = trim($this->settings['api_key_env'] ?? '');
    if ($variable !== '' && $this->apiKey() === '') {
      return sprintf('search.embeddings.api_key_env names %s, which is not set', $variable);
    }
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->unavailableReason() === '';
  }

  /**
   * Which files code:index embeds: `custom` (default) or `all`.
   *
   * @return string
   *   The scope.
   */
  public function scope(): string {
    return ($this->settings['scope'] ?? '') === 'all' ? 'all' : 'custom';
  }

  /**
   * The model, for the index's bookkeeping.
   *
   * @return string
   *   The model name.
   */
  public function model(): string {
    return trim($this->settings['model'] ?? '');
  }

  /**
   * Why the last request failed, or ''.
   *
   * @return string
   *   The error.
   */
  public function lastError(): string {
    return $this->lastError;
  }

  /**
   * The `dimension` the project names, or 0 when it names none.
   *
   * @return int
   *   The configured width.
   */
  public function configuredDimension(): int {
    $configured = $this->settings['dimension'] ?? '';
    return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 0;
  }

  /**
   * {@inheritdoc}
   *
   * The configured `dimension`, else the length of one probe embedding; 0
   * when neither can be had, which the indexer reads as "do not build".
   */
  public function dimension(): int {
    if ($this->configuredDimension() > 0) {
      return $this->configuredDimension();
    }
    if ($this->probed === NULL) {
      $vectors = $this->embed(['dimension probe']);
      $this->probed = count($vectors[0] ?? []);
    }
    return $this->probed;
  }

  /**
   * {@inheritdoc}
   */
  public function embed(array $texts): array {
    if (!$this->isAvailable() || $texts === []) {
      return [];
    }
    $texts = array_map(
      static fn (string $t): string => mb_substr(mb_scrub($t, 'UTF-8'), 0, self::MAX_EMBED_CHARS),
      array_values($texts),
    );
    try {
      return ($this->settings['format'] ?? '') === 'openai'
        ? $this->embedOpenai($texts)
        : $this->embedOllama($texts);
    }
    catch (\Throwable $e) {
      $this->lastError = $e->getMessage();
      return [];
    }
  }

  /**
   * Embeds through Ollama's /api/embeddings, one request per text.
   *
   * @param list<string> $texts
   *   The texts.
   *
   * @return list<list<float>>
   *   The vectors; an empty one for a text that failed.
   */
  private function embedOllama(array $texts): array {
    $vectors = [];
    foreach ($texts as $text) {
      try {
        $payload = self::decode($this->post(['model' => $this->model(), 'prompt' => $text]));
        $vectors[] = self::toVector($payload['embedding'] ?? NULL);
      }
      catch (\Throwable $e) {
        // One chunk over the model's window is skipped alone.
        $this->lastError = $e->getMessage();
        $vectors[] = [];
      }
    }
    return $vectors;
  }

  /**
   * Embeds through an OpenAI-compatible /v1/embeddings, one batch.
   *
   * @param list<string> $texts
   *   The texts.
   *
   * @return list<list<float>>
   *   The vectors, realigned to the request by each item's `index`.
   */
  private function embedOpenai(array $texts): array {
    $payload = self::decode($this->post(['model' => $this->model(), 'input' => $texts]));
    $data = $payload['data'] ?? NULL;
    if (!is_array($data)) {
      return [];
    }
    $byIndex = [];
    foreach (array_values($data) as $i => $item) {
      $vector = self::toVector(is_array($item) ? ($item['embedding'] ?? NULL) : NULL);
      $index = is_array($item) && is_numeric($item['index'] ?? NULL) ? (int) $item['index'] : $i;
      $byIndex[$index] = $vector;
    }
    $vectors = [];
    foreach (array_keys($texts) as $i) {
      $vectors[] = $byIndex[$i] ?? [];
    }
    return $vectors;
  }

  /**
   * POSTs a payload to the endpoint.
   *
   * @param array<string, mixed> $payload
   *   The body.
   *
   * @return string
   *   The response body.
   */
  private function post(array $payload): string {
    $headers = ['Content-Type' => 'application/json'];
    $key = $this->apiKey();
    if ($key !== '') {
      $headers['Authorization'] = 'Bearer ' . $key;
    }
    return ($this->transport)($this->endpoint(), (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $headers);
  }

  /**
   * POSTs through PHP's HTTP stream: no redirects, bounded time.
   *
   * @param string $url
   *   The endpoint.
   * @param string $body
   *   The JSON body.
   * @param array<string, string> $headers
   *   The headers.
   *
   * @return string
   *   The response body.
   */
  private static function streamTransport(string $url, string $body, array $headers): string {
    $lines = [];
    foreach ($headers as $name => $value) {
      $lines[] = $name . ': ' . $value;
    }
    $context = stream_context_create([
      'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $lines),
        'content' => $body,
        'timeout' => 60,
        'follow_location' => 0,
        'max_redirects' => 0,
        'ignore_errors' => TRUE,
      ],
    ]);
    $response = @file_get_contents($url, FALSE, $context);
    if ($response === FALSE) {
      $error = error_get_last();
      throw new \RuntimeException('the embeddings endpoint did not answer' . ($error !== NULL ? ': ' . $error['message'] : ''));
    }
    // PHP 8.4 names the response headers by a function; 8.3 by a variable
    // filled in the calling scope when the stream wrapper answers.
    $status = self::statusOf(function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : $http_response_header);
    if ($status >= 300) {
      throw new \RuntimeException(sprintf('the embeddings endpoint answered HTTP %d: %s', $status, mb_substr($response, 0, 200)));
    }
    return $response;
  }

  /**
   * Decodes a JSON body.
   *
   * @param string $body
   *   The body.
   *
   * @return array<array-key, mixed>
   *   The structure, or [] when it is not one.
   */
  private static function decode(string $body): array {
    $decoded = json_decode($body, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * A list of finite numbers as a vector, or [] when it is not one.
   *
   * @param mixed $value
   *   The candidate.
   *
   * @return list<float>
   *   The vector.
   */
  private static function toVector(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $vector = [];
    foreach ($value as $n) {
      if (!is_numeric($n) || !is_finite((float) $n)) {
        return [];
      }
      $vector[] = (float) $n;
    }
    return $vector;
  }

  /**
   * The endpoint URL.
   *
   * @return string
   *   The URL.
   */
  private function endpoint(): string {
    return trim($this->settings['endpoint'] ?? '');
  }

  /**
   * The API key from the environment variable the settings name, or ''.
   *
   * @return string
   *   The key.
   */
  private function apiKey(): string {
    $variable = trim($this->settings['api_key_env'] ?? '');
    if ($variable === '') {
      return '';
    }
    $value = $this->env !== NULL ? ($this->env)($variable) : getenv($variable);
    return is_string($value) ? trim($value) : '';
  }

  /**
   * The HTTP status the last response's headers carry, or 0.
   *
   * Takes the headers as mixed: each PHP and analyser version types them
   * differently (8.3's `$http_response_header`, 8.4's function), and the
   * answer must not depend on which.
   *
   * @param mixed $headers
   *   The response headers.
   *
   * @return int
   *   The last status line's code, 0 when none.
   */
  private static function statusOf(mixed $headers): int {
    $status = 0;
    foreach (is_array($headers) ? $headers : [] as $header) {
      if (is_string($header) && preg_match('#^HTTP/\\S+\\s+(\\d{3})#', $header, $m) === 1) {
        $status = (int) $m[1];
      }
    }

    return $status;
  }

}
