<?php

namespace App\Importing\Csv;

/**
 * Exposes a byte range of a file as a read-only, seekable stream, so a
 * streaming CSV parser can read one range as if it were a whole file:
 * offset 0 is the range start and EOF is the range end.
 *
 * A UTF-8 BOM at the start of the file is hidden from the parser. PHP's
 * CSV parser would otherwise treat it as field content, so a quoted first
 * field ("\xEF\xBB\xBF\"a\nb\"") would not be recognised as quoted.
 *
 *   fopen(ByteRangeStream::uri('/path/file.csv', 1024, 2048), 'rb')
 *
 * @internal Registered and used by RangeReader.
 */
final class ByteRangeStream
{
    public const string PROTOCOL = 'csv-range';

    /** @var resource|null Set by PHP for every stream wrapper instance. */
    public $context;

    /** @var resource|null */
    private $handle;

    private int $start = 0;

    private int $end = 0;

    private int $position = 0;

    public static function register(): void
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    public static function uri(string $path, int $start, int $end): string
    {
        return sprintf('%s://%d-%d/%s', self::PROTOCOL, $start, $end, rawurlencode($path));
    }

    public function stream_open(string $uri, string $mode, int $options, ?string &$openedPath): bool
    {
        if (strpbrk($mode, 'waxc+') !== false) {
            return false;
        }

        if (preg_match('#^'.self::PROTOCOL.'://(\d+)-(\d+)/(.+)$#s', $uri, $matches) !== 1) {
            return false;
        }

        [, $start, $end, $path] = $matches;
        $handle = @fopen(rawurldecode($path), 'rb');

        if ($handle === false || (int) $end < (int) $start) {
            return false;
        }

        $this->handle = $handle;
        $this->start = (int) $start;
        $this->end = (int) $end;

        if ($this->start === 0 && $this->end >= 3 && fread($handle, 3) === "\xEF\xBB\xBF") {
            $this->start = 3;
        }

        return $this->stream_seek(0, SEEK_SET);
    }

    public function stream_read(int $count): string|false
    {
        $count = min($count, $this->end - $this->position);

        if ($count <= 0 || $this->handle === null) {
            return '';
        }

        $data = fread($this->handle, $count);

        if ($data === false) {
            return false;
        }

        $this->position += strlen($data);

        return $data;
    }

    public function stream_eof(): bool
    {
        return $this->position >= $this->end;
    }

    public function stream_tell(): int
    {
        return $this->position - $this->start;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $target = match ($whence) {
            SEEK_SET => $this->start + $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->end + $offset,
            default => -1,
        };

        if ($this->handle === null || $target < $this->start || $target > $this->end) {
            return false;
        }

        if (fseek($this->handle, $target) !== 0) {
            return false;
        }

        $this->position = $target;

        return true;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => $this->end - $this->start, 'mode' => 0100444];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
