<?php

namespace Oweb\Analytics;

use DateInterval;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * SQLite storage. Daily totals only, plus the visitor hashes
 * and the salt of the current day, which are deleted at the day change.
 */
class Database
{
    public const SCHEMA = 1;

    protected int $depth = 0;
    protected PDO|null $pdo = null;

    public function __construct(
        protected string $file,
        protected int $timeout = 200
    ) {
    }

    public static function isAvailable(): bool
    {
        return class_exists(PDO::class) === true && in_array('sqlite', PDO::getAvailableDrivers(), true) === true;
    }

    /**
     * Removes all counts and today's visitors; goals and settings stay
     */
    public function clear(): void
    {
        $this->transaction(function () {
            foreach (['totals', 'hours', 'pages', 'sources', 'campaigns', 'landings', 'dims', 'events', 'visitors', 'seen'] as $table) {
                $this->execute('DELETE FROM ' . $table);
            }

            $this->execute("DELETE FROM meta WHERE key IN ('first', 'dropped')");
        });

        $this->pdo()->exec('VACUUM');
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->rowCount();
    }

    public function exists(): bool
    {
        return is_file($this->file) === true;
    }

    public function file(): string
    {
        return $this->file;
    }

    /**
     * Adds to the counters of a row and creates the row first if needed
     *
     * @param array<string, mixed> $key primary key columns
     * @param array<string, int> $counters column => amount (can be negative)
     */
    public function increment(string $table, array $key, array $counters): void
    {
        $columns = array_keys($key);
        $marks   = implode(', ', array_fill(0, count($key), '?'));
        $sets    = implode(', ', array_map(fn ($column) => $column . ' = MAX(0, ' . $column . ' + ?)', array_keys($counters)));
        $where   = implode(' AND ', array_map(fn ($column) => $column . ' = ?', $columns));

        $this->execute('INSERT OR IGNORE INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . $marks . ')', array_values($key));
        $this->execute('UPDATE ' . $table . ' SET ' . $sets . ' WHERE ' . $where, [...array_values($counters), ...array_values($key)]);
    }

    public function meta(string $key): string|null
    {
        $value = $this->value('SELECT value FROM meta WHERE key = ?', [$key]);

        return $value === null ? null : (string)$value;
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if (static::isAvailable() === false) {
            throw new RuntimeException('The PDO SQLite driver (pdo_sqlite) is not installed');
        }

        $dir = dirname($this->file);

        if (is_dir($dir) === false && @mkdir($dir, 0755, true) === false && is_dir($dir) === false) {
            throw new RuntimeException('The folder for the database could not be created: ' . $dir);
        }

        $pdo = new PDO('sqlite:' . $this->file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => max(1, (int)ceil($this->timeout / 1000))
        ]);

        // a locked database must never slow a page down for long:
        // after the timeout the hit is dropped instead
        $pdo->exec('PRAGMA busy_timeout = ' . max(0, $this->timeout));
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        $this->pdo = $pdo;
        $this->migrate();

        return $pdo;
    }

    /**
     * Deletes days older than the retention period, at most once a day
     */
    public function prune(string $today, int|null $retention): void
    {
        if ($retention === null || $retention <= 0 || $this->meta('pruned') === $today) {
            return;
        }

        $cutoff = (new DateTimeImmutable($today))->sub(new DateInterval('P' . $retention . 'D'))->format('Y-m-d');

        $this->transaction(function () use ($cutoff, $today) {
            foreach (['totals', 'hours', 'pages', 'sources', 'campaigns', 'landings', 'dims', 'events'] as $table) {
                $this->execute('DELETE FROM ' . $table . ' WHERE day < ?', [$cutoff]);
            }

            $this->setMeta('pruned', $today);
        });
    }

    public function query(string $sql, array $bindings = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll();
    }

    /**
     * The salt of the given day. A new day gets a new random salt, and the salt
     * and the visitor hashes of every earlier day are deleted in the same step.
     */
    public function salt(string $day): string
    {
        $stored = $this->meta('salt');

        if ($stored !== null && str_starts_with($stored, $day . ':') === true) {
            return substr($stored, strlen($day) + 1);
        }

        return $this->transaction(function () use ($day) {
            // another request may have rotated it in the meantime
            $stored = $this->meta('salt');

            if ($stored !== null && str_starts_with($stored, $day . ':') === true) {
                return substr($stored, strlen($day) + 1);
            }

            $salt = bin2hex(random_bytes(32));

            $this->execute('DELETE FROM visitors WHERE day <> ?', [$day]);
            $this->execute('DELETE FROM seen WHERE day <> ?', [$day]);
            $this->setMeta('salt', $day . ':' . $salt);

            return $salt;
        });
    }

    /**
     * Records that a visitor saw something today (a page, a source, an event, an hour).
     * Returns `true` the first time.
     */
    public function see(string $day, string $hash, string $key): bool
    {
        return $this->execute('INSERT OR IGNORE INTO seen (day, hash, key) VALUES (?, ?, ?)', [$day, $hash, $key]) === 1;
    }

    public function setMeta(string $key, string $value): void
    {
        $this->execute('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)', [$key, $value]);
    }

    /**
     * Size of the database file including the write-ahead log, in bytes
     */
    public function size(): int
    {
        $size = 0;

        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->file . $suffix) === true) {
                $size += (int)filesize($this->file . $suffix);
            }
        }

        return $size;
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        // nested calls join the open transaction; PDO::inTransaction() doesn't
        // know about `BEGIN IMMEDIATE` before PHP 8.4, so count them ourselves
        if ($this->depth > 0) {
            return $callback();
        }

        $pdo->exec('BEGIN IMMEDIATE');
        $this->depth++;

        try {
            $result = $callback();
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        } finally {
            $this->depth--;
        }
    }

    public function value(string $sql, array $bindings = []): mixed
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    protected function migrate(): void
    {
        if ((int)$this->pdo->query('PRAGMA user_version')->fetchColumn() >= static::SCHEMA) {
            return;
        }

        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS totals (
                day      TEXT    NOT NULL,
                lang     TEXT    NOT NULL,
                visitors INTEGER NOT NULL DEFAULT 0,
                visits   INTEGER NOT NULL DEFAULT 0,
                views    INTEGER NOT NULL DEFAULT 0,
                bounces  INTEGER NOT NULL DEFAULT 0,
                duration INTEGER NOT NULL DEFAULT 0,
                conversions INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, lang)
            );
            CREATE TABLE IF NOT EXISTS hours (
                day      TEXT    NOT NULL,
                lang     TEXT    NOT NULL,
                hour     INTEGER NOT NULL,
                visitors INTEGER NOT NULL DEFAULT 0,
                views    INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, lang, hour)
            );
            CREATE TABLE IF NOT EXISTS pages (
                day      TEXT    NOT NULL,
                lang     TEXT    NOT NULL,
                page     TEXT    NOT NULL,
                visitors INTEGER NOT NULL DEFAULT 0,
                views    INTEGER NOT NULL DEFAULT 0,
                entries  INTEGER NOT NULL DEFAULT 0,
                exits    INTEGER NOT NULL DEFAULT 0,
                bounces  INTEGER NOT NULL DEFAULT 0,
                duration INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, lang, page)
            );
            CREATE INDEX IF NOT EXISTS pages_page ON pages (page, day);
            CREATE TABLE IF NOT EXISTS sources (
                day      TEXT    NOT NULL,
                lang     TEXT    NOT NULL,
                channel  TEXT    NOT NULL,
                source   TEXT    NOT NULL,
                visitors INTEGER NOT NULL DEFAULT 0,
                visits   INTEGER NOT NULL DEFAULT 0,
                bounces  INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, lang, channel, source)
            );
            CREATE TABLE IF NOT EXISTS campaigns (
                day      TEXT    NOT NULL,
                lang     TEXT    NOT NULL,
                campaign TEXT    NOT NULL,
                source   TEXT    NOT NULL,
                medium   TEXT    NOT NULL,
                content  TEXT    NOT NULL,
                term     TEXT    NOT NULL,
                visitors INTEGER NOT NULL DEFAULT 0,
                visits   INTEGER NOT NULL DEFAULT 0,
                bounces  INTEGER NOT NULL DEFAULT 0,
                conversions INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, lang, campaign, source, medium, content, term)
            );
            CREATE TABLE IF NOT EXISTS landings (
                day     TEXT    NOT NULL,
                lang    TEXT    NOT NULL,
                page    TEXT    NOT NULL,
                channel TEXT    NOT NULL,
                source  TEXT    NOT NULL,
                visits  INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, lang, page, channel, source)
            );
            CREATE INDEX IF NOT EXISTS landings_page ON landings (page, day);
            CREATE TABLE IF NOT EXISTS dims (
                day      TEXT    NOT NULL,
                lang     TEXT    NOT NULL,
                kind     TEXT    NOT NULL,
                value    TEXT    NOT NULL,
                visitors INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, lang, kind, value)
            );
            CREATE TABLE IF NOT EXISTS events (
                day      TEXT    NOT NULL,
                lang     TEXT    NOT NULL,
                name     TEXT    NOT NULL,
                page     TEXT    NOT NULL,
                events   INTEGER NOT NULL DEFAULT 0,
                visitors INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, lang, name, page)
            );
            CREATE TABLE IF NOT EXISTS visitors (
                hash     TEXT    NOT NULL PRIMARY KEY,
                day      TEXT    NOT NULL,
                lang     TEXT    NOT NULL,
                vlang    TEXT    NOT NULL,
                started  INTEGER NOT NULL,
                seen     INTEGER NOT NULL,
                views    INTEGER NOT NULL DEFAULT 0,
                entry    TEXT    NOT NULL,
                last     TEXT    NOT NULL,
                channel  TEXT    NOT NULL,
                source   TEXT    NOT NULL,
                campaign TEXT    NOT NULL DEFAULT '',
                hits     INTEGER NOT NULL DEFAULT 0,
                minute   INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS visitors_seen ON visitors (seen);
            CREATE TABLE IF NOT EXISTS seen (
                day  TEXT NOT NULL,
                hash TEXT NOT NULL,
                key  TEXT NOT NULL,
                PRIMARY KEY (hash, key)
            );
            CREATE TABLE IF NOT EXISTS goals (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                name    TEXT NOT NULL,
                type    TEXT NOT NULL,
                rule    TEXT NOT NULL,
                created TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS meta (
                key   TEXT NOT NULL PRIMARY KEY,
                value TEXT NOT NULL
            );
            PRAGMA user_version = 1;
            SQL);
    }
}
