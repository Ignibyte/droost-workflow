<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Knowledge;

use Droost\Workflow\Knowledge\Embedding\HttpEmbeddingBackend;
use Droost\Workflow\Knowledge\KnowledgeIndexer;
use Droost\Workflow\Knowledge\KnowledgeSettings;
use Droost\Workflow\Knowledge\Site;
use Droost\Workflow\Knowledge\Tool\SearchTool;
use PHPUnit\Framework\TestCase;

/**
 * Semantic search: code:index embeds, search:code adds what is near.
 *
 * The model server is a fake that embeds by counting hashed words, so text
 * that shares words lands close, and every failure the real server can have
 * is one closure away. Each path that makes search lexical-only is made to
 * happen and to say so.
 */
final class SemanticSearchTest extends TestCase {

  use KnowledgeFixture;
  use TypedReads;

  /**
   * The width of the fake's vectors.
   */
  private const int WIDTH = 16;

  /**
   * The project.
   */
  private string $root;

  /**
   * Every request the fake server received: url, body, headers.
   *
   * @var list<array{url: string, body: array<array-key, mixed>, headers: array<string, string>}>
   */
  private array $requests = [];

  /**
   * Whether the fake server is down.
   */
  private bool $down = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->root = sys_get_temp_dir() . '/droost-semantic-' . bin2hex(random_bytes(4));
    mkdir($this->root, 0755, TRUE);
    $this->put($this->root, 'composer.json', (string) json_encode(['extra' => ['drupal-scaffold' => ['locations' => ['web-root' => 'web/']]]]));
    $this->put($this->root, 'web/core/lib/Drupal.php', "<?php\nclass Drupal {\n  const VERSION = '11.2.0';\n}\n");
    $this->put($this->root, 'web/modules/custom/camps/camps.info.yml', "name: Camps\ntype: module\n");
    $this->put($this->root, 'web/modules/custom/camps/src/CampRegistration.php', "<?php\n\nnamespace Drupal\\camps;\n\n/**\n * Registers a child for a summer camp session and charges the deposit.\n */\nclass CampRegistration {\n\n  /**\n   * Registers a child for a summer camp session.\n   */\n  public function register(): void {\n  }\n\n}\n");
    $this->put($this->root, 'web/modules/custom/camps/src/InvoiceMailer.php', "<?php\n\nnamespace Drupal\\camps;\n\n/**\n * Mails the invoice PDF to the billing contact.\n */\nclass InvoiceMailer {\n}\n");
    $this->put($this->root, 'web/modules/contrib/views/src/ViewExecutable.php', "<?php\n\nnamespace Drupal\\views;\n\n/**\n * Registers a child for a summer camp session.\n */\nclass ViewExecutable {\n}\n");
    $this->configure("    endpoint: http://embed.test/api/embeddings\n    model: fake-words\n");
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
      if ($file instanceof \SplFileInfo) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
      }
    }
    rmdir($this->root);
    parent::tearDown();
  }

  /**
   * The index embeds custom code; search adds the chunk nearest in meaning.
   */
  public function testIndexEmbedsCustomCodeAndSearchFindsItByMeaning(): void {
    $report = $this->index();
    $this->assertSame('built', $report['semantic'], (string) $report['semantic_reason']);
    $this->assertGreaterThan(0, $report['vectors']);
    $this->assertSame(2, $report['embedded_files'], 'Custom code only by default: the contrib file is not embedded.');
    foreach ($this->requests as $request) {
      $this->assertSame(['model', 'prompt'], array_keys($request['body']), 'Ollama form: one text a request.');
    }

    $answer = $this->search('child summer camp session');
    $this->assertTrue($this->dig($answer, 'success'));
    $this->assertSame('lexical+semantic', $this->dig($answer, 'data.mode'));
    $this->assertStringContainsString('Lexical and semantic', $this->digString($answer, 'message'));
    $this->assertSame('modules/custom/camps/src/CampRegistration.php', $this->dig($answer, 'data.semantic.0.file'));
    foreach ($this->digArray($answer, 'data.semantic') as $hit) {
      $this->assertIsArray($hit);
      $this->assertStringNotContainsString('contrib', $this->digString($hit, 'file'));
    }

    // Unchanged files are not embedded again; a changed one is.
    $this->requests = [];
    $this->assertSame(0, $this->index()['embedded_files']);
    $this->assertSame([], $this->requests);
    file_put_contents($this->root . '/web/modules/custom/camps/src/InvoiceMailer.php', "<?php\n\nnamespace Drupal\\camps;\n\n/**\n * Mails the invoice and a receipt.\n */\nclass InvoiceMailer {\n}\n");
    $this->assertSame(1, $this->index()['embedded_files']);
  }

  /**
   * Scope all embeds the whole docroot.
   */
  public function testScopeAll(): void {
    $this->configure("    endpoint: http://embed.test/api/embeddings\n    model: fake-words\n    scope: all\n");
    $this->assertGreaterThanOrEqual(3, $this->index()['embedded_files']);
  }

  /**
   * Not configured: no layer, and search says it is lexical and why.
   */
  public function testNotConfiguredIsLexicalAndSaysSo(): void {
    $this->index();
    $this->configure(NULL);
    $report = $this->index();
    $this->assertSame('off', $report['semantic']);
    $this->assertSame(0, $report['vectors']);
    $answer = $this->search('camp');
    $this->assertSame('lexical', $this->dig($answer, 'data.mode'));
    $this->assertStringContainsString('Lexical only: search.embeddings names no endpoint', $this->digString($answer, 'message'));
  }

  /**
   * A key in the committed file is refused; a key in the environment is sent.
   */
  public function testTheKeyComesFromTheEnvironmentOnly(): void {
    $this->configure("    endpoint: https://embed.test/v1/embeddings\n    model: fake-words\n    api_key: sk-committed\n");
    $report = $this->index();
    $this->assertSame('unavailable', $report['semantic']);
    $this->assertStringContainsString('api_key_env', (string) $report['semantic_reason']);
    $this->assertSame([], $this->requests, 'A committed key is never sent.');

    $this->configure("    endpoint: https://embed.test/v1/embeddings\n    model: fake-words\n    format: openai\n    api_key_env: DROOST_TEST_EMBED_KEY\n");
    $report = $this->index(['DROOST_TEST_EMBED_KEY' => 'sk-from-env']);
    $this->assertSame('built', $report['semantic'], (string) $report['semantic_reason']);
    $this->assertSame('Bearer sk-from-env', $this->requests[0]['headers']['Authorization'] ?? NULL);
    $this->assertArrayHasKey('input', $this->requests[0]['body'], 'OpenAI form: one batch.');

    $this->configure("    endpoint: https://embed.test/v1/embeddings\n    model: fake-words\n    api_key_env: DROOST_TEST_UNSET\n");
    $this->assertStringContainsString('DROOST_TEST_UNSET, which is not set', (string) $this->index()['semantic_reason']);
  }

  /**
   * A server that fails keeps the layer it had, and search still answers.
   */
  public function testFailingServerKeepsThePreviousLayer(): void {
    $vectors = $this->index()['vectors'];
    $this->down = TRUE;
    $report = $this->index(full: TRUE);
    $this->assertSame('incomplete', $report['semantic']);
    $this->assertStringContainsString('kept', (string) $report['semantic_reason']);
    $this->assertSame($vectors, $report['vectors']);

    $answer = $this->search('camp');
    $this->assertTrue($this->dig($answer, 'success'), 'Lexical still answers when semantic cannot.');
    $this->assertSame('lexical', $this->dig($answer, 'data.mode'));
    $this->assertStringContainsString('no complete semantic layer', $this->digString($answer, 'data.semantic_off'));
  }

  /**
   * Vectors from another model are never scored against.
   */
  public function testAnotherModelsVectorsAreNotUsed(): void {
    $this->index();
    $this->configure("    endpoint: http://embed.test/api/embeddings\n    model: another-model\n");
    $answer = $this->search('camp');
    $this->assertSame('lexical', $this->dig($answer, 'data.mode'));
    $this->assertStringContainsString('built with fake-words, not another-model', $this->digString($answer, 'message'));
  }

  /**
   * Only http(s) endpoints are used.
   */
  public function testOnlyHttpEndpoints(): void {
    $backend = new HttpEmbeddingBackend(['endpoint' => 'file:///etc/passwd', 'model' => 'm']);
    $this->assertFalse($backend->isAvailable());
    $this->assertSame('the embeddings endpoint must be an http(s) URL', $backend->unavailableReason());
    $this->assertSame([], $backend->embed(['x']));
  }

  /**
   * Writes droost.yml with a search.embeddings block, or none.
   *
   * @param string|null $embeddings
   *   The block's indented body, or NULL for no semantic search.
   */
  private function configure(?string $embeddings): void {
    $this->put($this->root, 'droost/droost.yml', $embeddings === NULL ? "rules: {}\n" : "search:\n  embeddings:\n" . $embeddings);
  }

  /**
   * Indexes with the fake server.
   *
   * @param array<string, string> $env
   *   The environment the backend reads.
   * @param bool $full
   *   Rebuild.
   *
   * @return array<string, int|string>
   *   The indexer's report.
   */
  private function index(array $env = [], bool $full = FALSE): array {
    $settings = KnowledgeSettings::fromProjectRoot($this->root);
    return (new KnowledgeIndexer($settings, $this->backend($settings, $env)))->index($full);
  }

  /**
   * Runs search:code with the fake server.
   *
   * @param string $query
   *   The query.
   *
   * @return array<string, mixed>
   *   The answer.
   */
  private function search(string $query): array {
    $site = Site::fromProjectRoot($this->root);
    $site->useEmbeddings($this->backend($site->settings, []));
    return (new SearchTool())->answer(['query' => $query], $site);
  }

  /**
   * The backend the project configures, talking to the fake server.
   *
   * @param \Droost\Workflow\Knowledge\KnowledgeSettings $settings
   *   The project's settings.
   * @param array<string, string> $env
   *   The environment.
   *
   * @return \Droost\Workflow\Knowledge\Embedding\HttpEmbeddingBackend
   *   The backend.
   */
  private function backend(KnowledgeSettings $settings, array $env): HttpEmbeddingBackend {
    return new HttpEmbeddingBackend(
      $settings->embeddings(),
      function (string $url, string $body, array $headers): string {
        if ($this->down) {
          throw new \RuntimeException('connection refused');
        }
        $decoded = json_decode($body, TRUE);
        $this->assertIsArray($decoded);
        $this->requests[] = ['url' => $url, 'body' => $decoded, 'headers' => $headers];
        $input = $decoded['input'] ?? NULL;
        if (is_array($input)) {
          $data = [];
          // Answered in reverse, as the API allows: realigned by index.
          foreach (array_reverse($input, TRUE) as $i => $text) {
            $data[] = ['index' => $i, 'embedding' => self::embed(is_string($text) ? $text : '')];
          }
          return (string) json_encode(['data' => $data]);
        }
        $prompt = $decoded['prompt'] ?? '';
        return (string) json_encode(['embedding' => self::embed(is_string($prompt) ? $prompt : '')]);
      },
      static fn (string $name): string|false => $env[$name] ?? FALSE,
    );
  }

  /**
   * Embeds a bag of hashed words: texts that share words land close.
   *
   * @param string $text
   *   The text.
   *
   * @return list<float>
   *   The vector.
   */
  private static function embed(string $text): array {
    $vector = array_fill(0, self::WIDTH, 0.0);
    preg_match_all('/[a-z]{4,}/', strtolower($text), $words);
    foreach ($words[0] as $word) {
      $vector[crc32($word) % self::WIDTH] += 1.0;
    }
    $vector[0] += 0.01;
    return array_values($vector);
  }

}
