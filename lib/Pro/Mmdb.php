<?php

namespace Oweb\Analytics\Pro;

use RuntimeException;

/**
 * Minimal reader for MaxMind DB files (`.mmdb`), as used by DB-IP Lite and
 * MaxMind GeoLite2. Reads only the bytes it needs, so the file is never
 * loaded into memory. https://maxmind.github.io/MaxMind-DB/
 *
 * Part of Analytics Pro. Commercial license, see LICENSE.md.
 */
class Mmdb
{
    public const MARKER = "\xAB\xCD\xEFMaxMind.com";

    /** @var resource */
    protected $handle;

    protected int $dataStart;
    protected int $ipv4Start = 0;
    protected array $metadata;
    protected int $nodeCount;
    protected int $nodeSize;
    protected int $recordSize;

    public function __construct(string $file)
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The database cannot be opened: ' . $file);
        }

        $this->handle = $handle;

        $size  = (int)fstat($handle)['size'];
        $chunk = min($size, 128 * 1024);

        fseek($handle, $size - $chunk);
        $tail = (string)fread($handle, $chunk);
        $pos  = strrpos($tail, static::MARKER);

        if ($pos === false) {
            throw new RuntimeException('Not a MaxMind DB file: ' . $file);
        }

        $metaStart = $size - $chunk + $pos + strlen(static::MARKER);
        [$metadata] = $this->decode($metaStart, $metaStart);

        if (is_array($metadata) === false || isset($metadata['node_count'], $metadata['record_size']) === false) {
            throw new RuntimeException('Invalid metadata: ' . $file);
        }

        $this->metadata   = $metadata;
        $this->nodeCount  = (int)$metadata['node_count'];
        $this->recordSize = (int)$metadata['record_size'];
        $this->nodeSize   = intdiv($this->recordSize * 2, 8);
        $this->dataStart  = $this->nodeCount * $this->nodeSize + 16;

        if (in_array($this->recordSize, [24, 28, 32], true) === false) {
            throw new RuntimeException('Unsupported record size: ' . $this->recordSize);
        }

        // IPv4 addresses live in the ::/96 subtree of IPv6 databases
        if ((int)($metadata['ip_version'] ?? 4) === 6) {
            $node = 0;

            for ($i = 0; $i < 96 && $node < $this->nodeCount; $i++) {
                $node = $this->record($node, 0);
            }

            $this->ipv4Start = $node;
        }
    }

    public function __destruct()
    {
        if (is_resource($this->handle) === true) {
            fclose($this->handle);
        }
    }

    /**
     * The record for an IP address, or `null` when the address is not in the database
     */
    public function get(string $ip): mixed
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        $bits = strlen($packed) * 8;

        if ($bits === 128 && (int)($this->metadata['ip_version'] ?? 4) === 4) {
            return null;
        }

        $node = $bits === 32 ? $this->ipv4Start : 0;

        for ($i = 0; $i < $bits && $node < $this->nodeCount; $i++) {
            $bit  = (ord($packed[$i >> 3]) >> (7 - ($i % 8))) & 1;
            $node = $this->record($node, $bit);
        }

        if ($node <= $this->nodeCount) {
            return null;
        }

        $offset = $node - $this->nodeCount - 16 + $this->dataStart;

        return $this->decode($offset, $this->dataStart)[0];
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    protected function bytes(int $offset, int $length): string
    {
        if ($length === 0) {
            return '';
        }

        fseek($this->handle, $offset);
        $bytes = fread($this->handle, $length);

        if ($bytes === false || strlen($bytes) !== $length) {
            throw new RuntimeException('Unexpected end of the database');
        }

        return $bytes;
    }

    /**
     * Decodes one value of the data section
     *
     * @return array{0: mixed, 1: int} value and the offset after it
     */
    protected function decode(int $offset, int $base, int $depth = 0): array
    {
        if ($depth > 32) {
            throw new RuntimeException('The data is nested too deeply');
        }

        $control = ord($this->bytes($offset++, 1));
        $type    = $control >> 5;

        // pointer
        if ($type === 1) {
            $size  = ($control >> 3) & 0x3;
            $bytes = $this->bytes($offset, $size + 1);
            $value = static::uint($bytes);

            $pointer = match ($size) {
                0 => (($control & 0x7) << 8) | $value,
                1 => ((($control & 0x7) << 16) | $value) + 2048,
                2 => ((($control & 0x7) << 24) | $value) + 526336,
                3 => $value
            };

            return [$this->decode($base + $pointer, $base, $depth + 1)[0], $offset + $size + 1];
        }

        if ($type === 0) {
            $type = 7 + ord($this->bytes($offset++, 1));
        }

        $size = $control & 0x1F;

        if ($size >= 29) {
            $extra  = $size - 28;
            $value  = static::uint($this->bytes($offset, $extra));
            $offset += $extra;
            $size   = match ($extra) {
                1 => 29 + $value,
                2 => 285 + $value,
                3 => 65821 + $value
            };
        }

        switch ($type) {
            case 2:
                return [$this->bytes($offset, $size), $offset + $size];
            case 3:
                return [unpack('E', $this->bytes($offset, 8))[1], $offset + 8];
            case 4:
                return [$this->bytes($offset, $size), $offset + $size];
            case 5:
            case 6:
            case 9:
            case 10:
                return [static::uint($this->bytes($offset, $size)), $offset + $size];
            case 7:
                $map = [];

                for ($i = 0; $i < $size; $i++) {
                    [$key, $offset]   = $this->decode($offset, $base, $depth + 1);
                    [$value, $offset] = $this->decode($offset, $base, $depth + 1);
                    $map[(string)$key] = $value;
                }

                return [$map, $offset];
            case 8:
                $value = static::uint($this->bytes($offset, $size));
                return [$size === 4 && $value >= 0x80000000 ? $value - 0x100000000 : $value, $offset + $size];
            case 11:
                $list = [];

                for ($i = 0; $i < $size; $i++) {
                    [$list[], $offset] = $this->decode($offset, $base, $depth + 1);
                }

                return [$list, $offset];
            case 14:
                return [$size !== 0, $offset];
            case 15:
                return [unpack('G', $this->bytes($offset, 4))[1], $offset + 4];
            default:
                throw new RuntimeException('Unknown data type: ' . $type);
        }
    }

    protected function record(int $node, int $index): int
    {
        $bytes = $this->bytes($node * $this->nodeSize, $this->nodeSize);

        return match ($this->recordSize) {
            24 => static::uint(substr($bytes, $index * 3, 3)),
            28 => $index === 0
                ? ((ord($bytes[3]) & 0xF0) << 20) | static::uint(substr($bytes, 0, 3))
                : ((ord($bytes[3]) & 0x0F) << 24) | static::uint(substr($bytes, 4, 3)),
            32 => static::uint(substr($bytes, $index * 4, 4))
        };
    }

    protected static function uint(string $bytes): int
    {
        $value = 0;

        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
        }

        return $value;
    }
}
