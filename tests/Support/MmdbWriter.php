<?php

namespace Oweb\Analytics\Tests;

/**
 * Writes small MaxMind DB files for the tests (IPv6 tree, 24-bit records).
 * IPv4 networks are stored in the ::/96 subtree, like in DB-IP and GeoLite2.
 */
class MmdbWriter
{
    protected array $networks = [];

    public function add(string $cidr, array $record): static
    {
        $this->networks[] = [$cidr, $record];
        return $this;
    }

    public function write(string $file): void
    {
        // node id => [left, right]; a child is null, ['node', id] or ['leaf', data offset]
        $nodes = [[null, null]];
        $data  = '';

        foreach ($this->networks as [$cidr, $record]) {
            [$ip, $bits] = explode('/', $cidr);
            $packed = inet_pton($ip);
            $bits   = (int)$bits;

            if (strlen($packed) === 4) {
                $packed = str_repeat("\0", 12) . $packed;
                $bits  += 96;
            }

            $offset = strlen($data);
            $data  .= static::encode($record);
            $node   = 0;

            for ($i = 0; $i < $bits; $i++) {
                $bit = (ord($packed[$i >> 3]) >> (7 - ($i % 8))) & 1;

                if ($i === $bits - 1) {
                    $nodes[$node][$bit] = ['leaf', $offset];
                    break;
                }

                if ($nodes[$node][$bit] === null) {
                    $nodes[]            = [null, null];
                    $nodes[$node][$bit] = ['node', count($nodes) - 1];
                }

                $node = $nodes[$node][$bit][1];
            }
        }

        $count = count($nodes);
        $tree  = '';

        foreach ($nodes as $children) {
            foreach ($children as $child) {
                $value = match ($child[0] ?? null) {
                    null   => $count,
                    'leaf' => $count + 16 + $child[1],
                    'node' => $child[1]
                };
                $tree .= substr(pack('N', $value), 1);
            }
        }

        $meta = static::encode([
            'node_count'                  => ['uint32', $count],
            'record_size'                 => ['uint16', 24],
            'ip_version'                  => ['uint16', 6],
            'database_type'               => 'Test-Country',
            'binary_format_major_version' => ['uint16', 2],
            'binary_format_minor_version' => ['uint16', 0],
            'build_epoch'                 => ['uint32', 1793577600]
        ]);

        file_put_contents($file, $tree . str_repeat("\0", 16) . $data . "\xAB\xCD\xEFMaxMind.com" . $meta);
    }

    protected static function encode(mixed $value): string
    {
        if (is_array($value) === true && in_array($value[0] ?? null, ['uint16', 'uint32'], true) === true) {
            $type  = $value[0] === 'uint16' ? 5 : 6;
            $bytes = ltrim(pack('N', $value[1]), "\0");
            return chr(($type << 5) | strlen($bytes)) . $bytes;
        }

        if (is_array($value) === true) {
            $out = chr((7 << 5) | count($value));

            foreach ($value as $key => $item) {
                $out .= static::encode((string)$key) . static::encode($item);
            }

            return $out;
        }

        $value = (string)$value;

        return chr((2 << 5) | strlen($value)) . $value;
    }
}
