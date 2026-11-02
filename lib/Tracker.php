<?php

namespace Oweb\Analytics;

/**
 * Turns page views and events into daily totals.
 *
 * A visitor is a salted hash of IP, user agent and host. The salt changes
 * every day, so a person is one visitor per day. A visit ends after
 * 30 minutes without a page view. Bounces, exit pages and durations are
 * corrected with the next page view of the same visit, so no nightly job is needed.
 */
class Tracker
{
    /**
     * A visit ends after this many seconds without a page view
     */
    public const TIMEOUT = 1800;

    /**
     * Page views and events per visitor and minute; more are dropped
     */
    public const LIMIT = 60;

    public function __construct(
        protected Database $db
    ) {
    }

    public static function hash(string $salt, string $ip, string $agent, string $host): string
    {
        return substr(hash_hmac('sha256', $ip . '|' . $agent . '|' . $host, $salt), 0, 16);
    }

    /**
     * Counts an event (outbound link, download, form, own event).
     * Returns `false` when the visitor sent too many hits.
     *
     * @param array{day: string, time: int, lang: string, page: string, name: string, ip: string, agent: string, host: string} $hit
     */
    public function event(array $hit): bool
    {
        $db = $this->db;

        return $db->transaction(function () use ($db, $hit) {
            $hash    = static::hash($db->salt($hit['day']), $hit['ip'], $hit['agent'], $hit['host']);
            $visitor = $db->query('SELECT * FROM visitors WHERE hash = ?', [$hash])[0] ?? null;

            if ($visitor !== null && $this->limited($visitor, $hit['time']) === true) {
                return false;
            }

            $this->convert($hit['day'], $hit['lang'], $hash, $visitor, $hit['name'], $hit['page']);

            if ($visitor !== null) {
                $this->touch($hash, $visitor, $hit['time']);
            }

            return true;
        });
    }

    /**
     * Counts an event or a reached page goal. A visitor counts once per goal
     * and day (in the row of the page where it happened first), once as
     * converted, and once as a conversion of the campaign of the visit.
     */
    protected function convert(string $day, string $lang, string $hash, array|null $visitor, string $name, string $page): void
    {
        $db = $this->db;

        $db->increment('events', ['day' => $day, 'lang' => $lang, 'name' => $name, 'page' => $page], [
            'events'   => 1,
            'visitors' => $db->see($day, $hash, 'e:' . $name) ? 1 : 0
        ]);

        if ($db->see($day, $hash, 'converted') === true) {
            $db->increment('totals', ['day' => $day, 'lang' => $visitor['vlang'] ?? $lang], ['conversions' => 1]);
        }

        $campaign = $visitor !== null ? static::campaignKey($visitor['campaign']) : null;

        if ($campaign !== null && $db->see($day, $hash, 'cv:' . $visitor['campaign']) === true) {
            $db->increment('campaigns', ['day' => $day, 'lang' => $visitor['vlang'], ...$campaign], ['conversions' => 1]);
        }
    }

    /**
     * Counts a page view. Returns `false` when the visitor sent too many hits.
     *
     * @param array{
     *   day: string, hour: int, time: int, lang: string, page: string,
     *   ip: string, agent: string, host: string, referrer: string|null,
     *   utm?: array<string, string>, device: string, browser: string, os: string,
     *   language?: string, country?: string|null
     * } $hit
     */
    public function pageview(array $hit): bool
    {
        $db = $this->db;

        return $db->transaction(function () use ($db, $hit) {
            $day     = $hit['day'];
            $lang    = $hit['lang'];
            $page    = $hit['page'];
            $time    = $hit['time'];
            $hash    = static::hash($db->salt($day), $hit['ip'], $hit['agent'], $hit['host']);
            $visitor = $db->query('SELECT * FROM visitors WHERE hash = ?', [$hash])[0] ?? null;
            $see     = fn (string $key) => $db->see($day, $hash, $key) ? 1 : 0;

            if ($visitor !== null && $this->limited($visitor, $time) === true) {
                return false;
            }

            $db->increment('hours', ['day' => $day, 'lang' => $lang, 'hour' => $hit['hour']], [
                'visitors' => $see('h:' . $hit['hour'] . ':' . $lang),
                'views'    => 1
            ]);

            $source   = Referrer::classify($hit['referrer'] ?? null, $hit['host'], $hit['utm'] ?? []);
            $campaign = static::campaign($hit['utm'] ?? []);

            // a new campaign or another external source starts a new visit, like in most tools
            $sameVisit = $visitor !== null &&
                $time - (int)$visitor['seen'] < static::TIMEOUT &&
                ($campaign === null || json_encode(array_values($campaign)) === $visitor['campaign']) &&
                ($source['channel'] === 'direct' || ($source['channel'] === $visitor['channel'] && $source['source'] === $visitor['source']));

            // same visit: correct bounce, exit page and durations of the previous page view
            if ($sameVisit === true) {
                $spent   = max(0, $time - (int)$visitor['seen']);
                $bounced = (int)$visitor['views'] === 1 ? -1 : 0;
                $vlang   = $visitor['vlang'];

                $db->increment('totals', ['day' => $day, 'lang' => $lang], [
                    'visitors' => $see('v:' . $lang),
                    'views'    => 1
                ]);
                $db->increment('totals', ['day' => $day, 'lang' => $vlang], [
                    'bounces'  => $bounced,
                    'duration' => $spent
                ]);
                $db->increment('pages', ['day' => $day, 'lang' => $visitor['lang'], 'page' => $visitor['last']], [
                    'exits'    => -1,
                    'duration' => $spent
                ]);

                if ($bounced !== 0) {
                    $db->increment('pages', ['day' => $day, 'lang' => $vlang, 'page' => $visitor['entry']], ['bounces' => -1]);
                    $db->increment('sources', ['day' => $day, 'lang' => $vlang, 'channel' => $visitor['channel'], 'source' => $visitor['source']], ['bounces' => -1]);

                    if ($campaign = static::campaignKey($visitor['campaign'])) {
                        $db->increment('campaigns', ['day' => $day, 'lang' => $vlang, ...$campaign], ['bounces' => -1]);
                    }
                }

                $db->increment('pages', ['day' => $day, 'lang' => $lang, 'page' => $page], [
                    'visitors' => $see('p:' . $lang . ':' . $page),
                    'views'    => 1,
                    'exits'    => 1
                ]);

                $db->execute(
                    'UPDATE visitors SET seen = ?, views = views + 1, last = ?, lang = ? WHERE hash = ?',
                    [$time, $page, $lang, $hash]
                );
                $this->touch($hash, $visitor, $time);
                $this->reached($hit, $hash);

                return true;
            }

            // a new visit (of a new or a returning visitor)
            $db->increment('totals', ['day' => $day, 'lang' => $lang], [
                'visitors' => $see('v:' . $lang),
                'visits'   => 1,
                'views'    => 1,
                'bounces'  => 1
            ]);
            $db->increment('pages', ['day' => $day, 'lang' => $lang, 'page' => $page], [
                'visitors' => $see('p:' . $lang . ':' . $page),
                'views'    => 1,
                'entries'  => 1,
                'exits'    => 1,
                'bounces'  => 1
            ]);
            $db->increment('sources', ['day' => $day, 'lang' => $lang, 'channel' => $source['channel'], 'source' => $source['source']], [
                'visitors' => $see('s:' . $lang . ':' . $source['channel'] . ':' . $source['source']),
                'visits'   => 1,
                'bounces'  => 1
            ]);
            $db->increment('landings', ['day' => $day, 'lang' => $lang, 'page' => $page, 'channel' => $source['channel'], 'source' => $source['source']], [
                'visits' => 1
            ]);

            if ($campaign !== null) {
                $db->increment('campaigns', ['day' => $day, 'lang' => $lang, ...$campaign], [
                    'visitors' => $see('c:' . $lang . ':' . implode('|', $campaign)),
                    'visits'   => 1,
                    'bounces'  => 1
                ]);
            }

            if ($visitor === null) {
                $dims = [
                    'device'   => $hit['device'],
                    'browser'  => $hit['browser'],
                    'os'       => $hit['os'],
                    'language' => $hit['language'] ?? '',
                    'country'  => $hit['country'] ?? null
                ];

                foreach ($dims as $kind => $value) {
                    if ($value === null) {
                        continue;
                    }

                    $db->increment('dims', ['day' => $day, 'lang' => $lang, 'kind' => $kind, 'value' => $value], ['visitors' => 1]);
                }
            }

            $db->execute(
                'INSERT OR REPLACE INTO visitors (hash, day, lang, vlang, started, seen, views, entry, last, channel, source, campaign, hits, minute) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $hash,
                    $day,
                    $lang,
                    $lang,
                    $time,
                    $time,
                    $page,
                    $page,
                    $source['channel'],
                    $source['source'],
                    $campaign !== null ? json_encode(array_values($campaign)) : '',
                    $visitor !== null && (int)$visitor['minute'] === intdiv($time, 60) ? (int)$visitor['hits'] + 1 : 1,
                    intdiv($time, 60)
                ]
            );

            $this->reached($hit, $hash);

            return true;
        });
    }

    /**
     * Page goals that the page of the hit matches (`goals`: list of goal ids)
     */
    protected function reached(array $hit, string $hash): void
    {
        if (empty($hit['goals']) === true) {
            return;
        }

        $visitor = $this->db->query('SELECT * FROM visitors WHERE hash = ?', [$hash])[0] ?? null;

        foreach ($hit['goals'] as $id) {
            $this->convert($hit['day'], $hit['lang'], $hash, $visitor, 'goal:' . $id, $hit['page']);
        }
    }

    /**
     * Campaign key from the UTM parameters, or `null` without a campaign or source
     *
     * @return array{campaign: string, source: string, medium: string, content: string, term: string}|null
     */
    public static function campaign(array $utm): array|null
    {
        $campaign = [
            'campaign' => Referrer::clean($utm['campaign'] ?? ''),
            'source'   => Referrer::clean($utm['source'] ?? ''),
            'medium'   => Referrer::clean($utm['medium'] ?? ''),
            'content'  => Referrer::clean($utm['content'] ?? ''),
            'term'     => Referrer::clean($utm['term'] ?? '')
        ];

        if ($campaign['campaign'] === '' && $campaign['source'] === '') {
            return null;
        }

        return $campaign;
    }

    protected static function campaignKey(string $stored): array|null
    {
        $values = json_decode($stored, true);

        if (is_array($values) === false || count($values) !== 5) {
            return null;
        }

        return array_combine(['campaign', 'source', 'medium', 'content', 'term'], array_map('strval', $values));
    }

    protected function limited(array $visitor, int $time): bool
    {
        return (int)$visitor['minute'] === intdiv($time, 60) && (int)$visitor['hits'] >= static::LIMIT;
    }

    protected function touch(string $hash, array $visitor, int $time): void
    {
        $minute = intdiv($time, 60);
        $hits   = (int)$visitor['minute'] === $minute ? (int)$visitor['hits'] + 1 : 1;

        $this->db->execute('UPDATE visitors SET hits = ?, minute = ? WHERE hash = ?', [$hits, $minute, $hash]);
    }
}
