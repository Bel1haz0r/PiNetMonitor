<?php

namespace App\Monitor;

use RuntimeException;

/**
 * Append-only JSONL evidence log with a hash chain.
 *
 * Each line carries the hash of the previous line and its own:
 *
 *   {"k":"Sample","n":42,"prev":"<hash of line 41>","t":"...","p":{...},"h":"<sha256 of this line without h>"}
 *
 * Changing any earlier line breaks every hash after it, so an edit cannot be
 * confined to one place. This shows internal consistency, not provenance: the
 * chain lives on the same disk as whoever could rewrite it.
 *
 * The raw chain is the source of truth; the SQLite tables are a derived index.
 */
final class EvidenceChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private const HASH_PREFIX = ',"h":"';

    /** @var resource|null */
    private $handle = null;

    private string $lastHash = self::GENESIS;

    private int $count = 0;

    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    public function lastHash(): string
    {
        return $this->lastHash;
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * Opens for appending. An existing chain is verified first; a torn final
     * line (power loss mid-write) is cut off so the chain stays appendable.
     *
     * @return array{valid:bool, entries:int, truncated:bool}
     */
    public function open(): array
    {
        @mkdir(dirname($this->path), 0775, true);
        $truncated = false;

        if (is_file($this->path) && filesize($this->path) > 0) {
            $check = self::verify($this->path);
            if (! $check['valid'] && $check['torn_tail'] && $check['valid_bytes'] !== null) {
                $fh = fopen($this->path, 'r+');
                ftruncate($fh, $check['valid_bytes']);
                fclose($fh);
                $truncated = true;
            } elseif (! $check['valid']) {
                throw new RuntimeException("Evidence chain {$this->path} is broken at line {$check['first_bad_line']}; refusing to append.");
            }
            $this->lastHash = $check['last_hash'];
            $this->count = $check['entries'];
        }

        $this->handle = fopen($this->path, 'ab');
        if ($this->handle === false) {
            throw new RuntimeException("Cannot open evidence chain {$this->path} for writing.");
        }

        return ['valid' => true, 'entries' => $this->count, 'truncated' => $truncated];
    }

    /** Appends one entry. $sync forces it to disk (used on every state change). */
    public function append(string $kind, array $payload, bool $sync = false, ?float $wall = null): string
    {
        if ($this->handle === null) {
            throw new RuntimeException('Evidence chain is not open.');
        }

        $body = json_encode([
            'k' => $kind,
            'n' => $this->count + 1,
            'prev' => $this->lastHash,
            't' => self::iso($wall ?? microtime(true)),
            'p' => (object) $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $hash = hash('sha256', $body);
        fwrite($this->handle, substr($body, 0, -1).self::HASH_PREFIX.$hash."\"}\n");
        fflush($this->handle);
        if ($sync) {
            fsync($this->handle);
        }

        $this->lastHash = $hash;
        $this->count++;

        return $hash;
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fsync($this->handle);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public static function iso(float $unix): string
    {
        return gmdate('Y-m-d\TH:i:s', (int) $unix).sprintf('.%03dZ', (int) (fmod($unix, 1) * 1000));
    }

    /**
     * Verifies a chain file line by line.
     *
     * @return array{valid:bool, entries:int, first_bad_line:int|null, reason:string|null, last_hash:string, torn_tail:bool, valid_bytes:int|null}
     */
    public static function verify(string $path): array
    {
        $result = ['valid' => true, 'entries' => 0, 'first_bad_line' => null, 'reason' => null,
            'last_hash' => self::GENESIS, 'torn_tail' => false, 'valid_bytes' => 0];

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return ['valid' => false, 'reason' => 'file missing or unreadable', 'first_bad_line' => 0, 'valid_bytes' => null] + $result;
        }

        $prev = self::GENESIS;
        $lineNo = 0;
        $fail = function (string $reason, bool $torn = false) use (&$result, &$lineNo) {
            $result['valid'] = false;
            $result['first_bad_line'] = $lineNo;
            $result['reason'] = $reason;
            $result['torn_tail'] = $torn;
        };

        while (($line = fgets($fh)) !== false) {
            $lineNo++;
            $complete = str_ends_with($line, "\n");
            $text = rtrim($line, "\n");

            $split = self::split($text);
            if (! $complete || $split === null) {
                // Only a final line with no terminating newline is a torn write.
                $fail('line is not a well-formed chain entry', ! $complete);
                break;
            }
            [$body, $claimed] = $split;

            if (! hash_equals(hash('sha256', $body), $claimed)) {
                $fail('hash does not match line content');
                break;
            }
            $decoded = json_decode($body, true);
            if (! is_array($decoded) || ($decoded['prev'] ?? null) !== $prev) {
                $fail('link to previous entry is broken');
                break;
            }
            if (($decoded['n'] ?? null) !== $lineNo) {
                $fail('entry number out of sequence');
                break;
            }

            $prev = $claimed;
            $result['entries'] = $lineNo;
            $result['last_hash'] = $claimed;
            $result['valid_bytes'] = ftell($fh);
        }
        fclose($fh);

        return $result;
    }

    /** @return array{0:string,1:string}|null body and claimed hash */
    private static function split(string $line): ?array
    {
        $suffixLen = strlen(self::HASH_PREFIX) + 64 + 2;
        if (strlen($line) < $suffixLen + 2 || ! str_ends_with($line, '"}')) {
            return null;
        }
        $start = strlen($line) - $suffixLen;
        if (substr($line, $start, strlen(self::HASH_PREFIX)) !== self::HASH_PREFIX) {
            return null;
        }
        $hash = substr($line, $start + strlen(self::HASH_PREFIX), 64);
        if (! preg_match('/^[0-9a-f]{64}$/', $hash)) {
            return null;
        }

        return [substr($line, 0, $start).'}', $hash];
    }

    /** Iterates decoded entries of a chain file. */
    public static function read(string $path): \Generator
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return;
        }
        while (($line = fgets($fh)) !== false) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                yield $entry;
            }
        }
        fclose($fh);
    }
}
