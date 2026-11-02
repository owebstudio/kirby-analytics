<?php

namespace Oweb\Analytics\Pro;

use Kirby\Filesystem\F;
use Kirby\Http\Remote;
use Oweb\Analytics\Analytics;
use RuntimeException;
use Throwable;

/**
 * Country of an IP address from a local database. The IP never leaves
 * the server; only the two-letter country code is stored.
 *
 * Part of Analytics Pro. Commercial license, see LICENSE.md.
 */
class Geo
{
    /**
     * DB-IP IP to Country Lite (CC BY 4.0), monthly
     */
    public const SOURCE = 'https://download.db-ip.com/free/dbip-country-lite-{month}.mmdb.gz';

    protected static self|null $instance = null;

    protected Mmdb|false|null $reader = null;

    public function __construct(
        protected Analytics $analytics
    ) {
    }

    public static function instance(Analytics $analytics): static
    {
        if (static::$instance === null || static::$instance->analytics !== $analytics) {
            static::$instance = new static($analytics);
        }

        return static::$instance;
    }

    /**
     * Two-letter country code, or `null` when unknown
     */
    public function country(string $ip): string|null
    {
        $reader = $this->reader();

        if ($reader === null) {
            return null;
        }

        $record = $reader->get($ip);
        $code   = $record['country']['iso_code'] ?? $record['registered_country']['iso_code'] ?? null;

        return is_string($code) === true && preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }

    /**
     * Downloads the current DB-IP Lite country database. One request to db-ip.com;
     * no visitor data is sent.
     */
    public function download(): array
    {
        $file    = $this->file();
        $errors  = [];

        // the file of the current month is published at the start of the month
        foreach ([date('Y-m'), date('Y-m', strtotime('first day of last month'))] as $month) {
            try {
                $response = Remote::get(str_replace('{month}', $month, static::SOURCE), ['timeout' => 60]);

                if ($response->code() !== 200) {
                    $errors[] = $month . ': HTTP ' . $response->code();
                    continue;
                }

                $data = @gzdecode($response->content());

                if ($data === false || str_contains($data, Mmdb::MARKER) === false) {
                    $errors[] = $month . ': not a database';
                    continue;
                }

                F::write($file . '.tmp', $data);
                new Mmdb($file . '.tmp');
                F::move($file . '.tmp', $file, true);

                $this->reader = null;

                return $this->info();
            } catch (Throwable $e) {
                $errors[] = $month . ': ' . $e->getMessage();
                F::remove($file . '.tmp');
            }
        }

        throw new RuntimeException('The country database could not be downloaded (' . implode('; ', $errors) . ')');
    }

    public function exists(): bool
    {
        return is_file($this->file()) === true;
    }

    public function file(): string
    {
        $file = $this->analytics->option('geoip');

        return is_string($file) === true && $file !== ''
            ? $file
            : $this->analytics->kirby()->root('logs') . '/analytics-country.mmdb';
    }

    /**
     * Type and date of the database, for the tracking status
     */
    public function info(): array|null
    {
        $reader = $this->reader();

        if ($reader === null) {
            return null;
        }

        $meta = $reader->metadata();

        return [
            'type'  => (string)($meta['database_type'] ?? ''),
            'built' => isset($meta['build_epoch']) === true ? date('Y-m-d', (int)$meta['build_epoch']) : null,
            'size'  => (int)filesize($this->file()),
            'dbip'  => str_contains(strtolower((string)($meta['database_type'] ?? '')), 'dbip') ||
                       str_contains(strtolower(json_encode($meta['description'] ?? '')), 'db-ip')
        ];
    }

    protected function reader(): Mmdb|null
    {
        if ($this->reader === null) {
            try {
                $this->reader = $this->exists() === true ? new Mmdb($this->file()) : false;
            } catch (Throwable $e) {
                Analytics::log($e);
                $this->reader = false;
            }
        }

        return $this->reader === false ? null : $this->reader;
    }
}
