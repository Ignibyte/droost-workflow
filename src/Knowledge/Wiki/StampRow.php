<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge\Wiki;

/**
 * The outcome of stamping (or refusing to stamp) one wiki page.
 *
 * A value object rendered as one row of the `droost:wiki:stamp` report. It is
 * deliberately NOT the engine's GenerationRow: that one is keyed by module, and
 * the stamper is keyed by PAGE, because the pages worth stamping are exactly
 * the hand-authored cross-cutting ones that belong to several modules or none.
 * Reusing a value object whose main field would have to be read as "sometimes a
 * module, sometimes a path" is how a field stops meaning anything.
 */
final readonly class StampRow {

  /**
   * Constructs a StampRow.
   *
   * @param string $file
   *   The bundle-relative page path.
   * @param string $action
   *   What happened: 'stamped', 'would-stamp' (dry run), 'skipped' (nothing to
   *   do), 'refused' (a precondition was not met) or 'failed'.
   * @param string $verdict
   *   The freshness verdict on success, else the reason token ('unmanaged',
   *   'invalid', 'orphaned', 'unreadable', 'rewrite', 'write-error',
   *   'not-fresh', 'missing').
   * @param array<int, array{path: string, from: string, to: string}> $sources
   *   The source claims this row moved (or would move), oldest hash to newest.
   * @param string|null $message
   *   A human-readable detail for a refusal/failure, else NULL.
   */
  public function __construct(
    public string $file,
    public string $action,
    public string $verdict,
    public array $sources,
    public ?string $message,
  ) {}

  /**
   * A successful stamp-and-verify.
   *
   * @param string $file
   *   The page path.
   * @param array<int, array{path: string, from: string, to: string}> $sources
   *   The refreshed source claims.
   *
   * @return self
   *   The row.
   */
  public static function stamped(string $file, array $sources): self {
    return new self($file, 'stamped', 'fresh', $sources, NULL);
  }

  /**
   * A dry run: what a real run would rewrite.
   *
   * @param string $file
   *   The page path.
   * @param array<int, array{path: string, from: string, to: string}> $sources
   *   The source claims that would move.
   *
   * @return self
   *   The row.
   */
  public static function wouldStamp(string $file, array $sources): self {
    return new self($file, 'would-stamp', 'stale', $sources, NULL);
  }

  /**
   * Nothing to do — the page is already fresh.
   *
   * Success, not a refusal: it is what makes the command idempotent, so a
   * second run (or a sweep that overlaps a fixed page) stays exit 0.
   *
   * @param string $file
   *   The page path.
   *
   * @return self
   *   The row.
   */
  public static function skipped(string $file): self {
    return new self($file, 'skipped', 'fresh', [], 'already fresh');
  }

  /**
   * A refusal (a precondition was not met; nothing written).
   *
   * @param string $file
   *   The page path.
   * @param string $verdict
   *   The reason token.
   * @param string $message
   *   The human-readable detail.
   *
   * @return self
   *   The row.
   */
  public static function refused(string $file, string $verdict, string $message): self {
    return new self($file, 'refused', $verdict, [], $message);
  }

  /**
   * A failure (the rewrite ran but did not leave a fresh page).
   *
   * @param string $file
   *   The page path.
   * @param string $verdict
   *   The reason token.
   * @param string $message
   *   The human-readable detail.
   *
   * @return self
   *   The row.
   */
  public static function failed(string $file, string $verdict, string $message): self {
    return new self($file, 'failed', $verdict, [], $message);
  }

  /**
   * Whether this row should keep the command's exit code at 0.
   *
   * @return bool
   *   TRUE when the page was stamped, previewed, or needed nothing.
   */
  public function ok(): bool {
    return in_array($this->action, ['stamped', 'would-stamp', 'skipped'], TRUE);
  }

}
