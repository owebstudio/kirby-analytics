<?php

namespace Oweb\Analytics;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Toolkit\I18n;
use Kirby\Toolkit\Str;
use NumberFormatter;
use Throwable;

/**
 * Data for the Panel views, the stats field, the email report and the CSV export
 */
class Reports
{
    public const PER_PAGE = 20;

    protected array $models = [];

    public function __construct(
        protected Analytics $analytics
    ) {
    }

    /**
     * Visitors and page views per hour (single days), day or week
     */
    public function chart(Range $range, string|null $lang): array
    {
        [$where, $bindings] = $this->where($range, $lang);

        if ($range->isHourly() === true) {
            $hours = [];

            foreach ($this->db()->query('SELECT hour, SUM(visitors) AS visitors, SUM(views) AS views FROM hours WHERE ' . $where . ' GROUP BY hour', $bindings) as $row) {
                $hours[(int)$row['hour']] = $row;
            }

            $now     = $range->from === $this->analytics->today() ? (int)date('G') : 23;
            $buckets = [];

            for ($hour = 0; $hour < 24; $hour++) {
                $future    = $hour > $now;
                $buckets[] = [
                    'from'     => $range->from,
                    'to'       => $range->to,
                    'hour'     => $hour,
                    'visitors' => $future ? null : (int)($hours[$hour]['visitors'] ?? 0),
                    'views'    => $future ? null : (int)($hours[$hour]['views'] ?? 0),
                    'bounce'   => null,
                    'duration' => null,
                    'partial'  => $hour === $now && $range->from === $this->analytics->today()
                ];
            }

            return $buckets;
        }

        $days = [];

        foreach ($this->db()->query('SELECT day, SUM(visitors) AS visitors, SUM(visits) AS visits, SUM(views) AS views, SUM(bounces) AS bounces, SUM(duration) AS duration FROM totals WHERE ' . $where . ' GROUP BY day', $bindings) as $row) {
            $days[$row['day']] = $row;
        }

        $today   = $this->analytics->today();
        $buckets = [];

        foreach ($range->buckets() as $bucket) {
            $sum = ['visitors' => 0, 'visits' => 0, 'views' => 0, 'bounces' => 0, 'duration' => 0];

            foreach ($days as $day => $row) {
                if ($day >= $bucket['from'] && $day <= $bucket['to']) {
                    foreach ($sum as $key => $value) {
                        $sum[$key] = $value + (int)$row[$key];
                    }
                }
            }

            $buckets[] = [
                ...$bucket,
                'visitors' => $sum['visitors'],
                'views'    => $sum['views'],
                'bounce'   => $sum['visits'] > 0 ? $sum['bounces'] / $sum['visits'] : null,
                'duration' => $sum['visits'] > 0 ? $sum['duration'] / $sum['visits'] : null,
                'partial'  => $today >= $bucket['from'] && $today <= $bucket['to']
            ];
        }

        return $buckets;
    }

    /**
     * Campaigns from the UTM parameters
     */
    public function campaigns(Range $range, string|null $lang, array $options = []): array
    {
        $rows = $this->campaignRows($range, $lang, $options['filter'] ?? null);

        if (($options['compare'] ?? false) === true) {
            $previous = [];

            foreach ($this->campaignRows($range->previous(), $lang) as $row) {
                $previous[$row['key']] = $row['visitors'];
            }

            foreach ($rows as $index => $row) {
                $rows[$index]['previous'] = $previous[$row['key']] ?? 0;
            }
        }

        return $this->paginate($this->sort($rows, $options['sort'] ?? 'visitors', $options['dir'] ?? 'desc'), $options);
    }

    public function channels(Range $range, string|null $lang, array $options = []): array
    {
        [$where, $bindings] = $this->where($range, $lang);

        $rows = $this->db()->query('SELECT channel, SUM(visitors) AS visitors, SUM(visits) AS visits, SUM(bounces) AS bounces FROM sources WHERE ' . $where . ' GROUP BY channel', $bindings);
        $rows = array_map(fn ($row) => [
            'key'      => $row['channel'],
            'channel'  => $row['channel'],
            'visitors' => (int)$row['visitors'],
            'visits'   => (int)$row['visits'],
            'bounce'   => (int)$row['visits'] > 0 ? (int)$row['bounces'] / (int)$row['visits'] : null
        ], $rows);

        if (($options['compare'] ?? false) === true) {
            [$where, $bindings] = $this->where($range->previous(), $lang);

            $previous = array_column($this->db()->query('SELECT channel, SUM(visitors) AS visitors FROM sources WHERE ' . $where . ' GROUP BY channel', $bindings), 'visitors', 'channel');

            foreach ($rows as $index => $row) {
                $rows[$index]['previous'] = (int)($previous[$row['channel']] ?? 0);
            }
        }

        return $this->sort($rows, $options['sort'] ?? 'visitors', $options['dir'] ?? 'desc');
    }

    /**
     * Devices, browsers, operating systems, visitor languages or countries
     */
    public function dims(Range $range, string|null $lang, string $kind, int $limit = 0): array
    {
        [$where, $bindings] = $this->where($range, $lang);

        $rows = $this->db()->query(
            'SELECT value, SUM(visitors) AS visitors FROM dims WHERE ' . $where . ' AND kind = :kind GROUP BY value ORDER BY visitors DESC, value ASC' . ($limit > 0 ? ' LIMIT ' . $limit : ''),
            [...$bindings, 'kind' => $kind]
        );

        return array_map(fn ($row) => ['key' => (string)$row['value'], 'value' => (string)$row['value'], 'visitors' => (int)$row['visitors']], $rows);
    }

    /**
     * CSV for the export (Pro)
     */
    public function export(string $kind, Range $range, string|null $lang): array
    {
        $all  = ['limit' => 0];
        $rows = match ($kind) {
            'sources' => [
                ['channel', 'source', 'visitors', 'visits', 'bounce_rate'],
                ...array_map(fn ($row) => [$row['channel'], $row['source'], $row['visitors'], $row['visits'], static::round($row['bounce'])], $this->referrers($range, $lang, null, $all)['rows'])
            ],
            'campaigns' => [
                ['campaign', 'source', 'medium', 'visitors', 'visits', 'bounce_rate', 'conversions', 'conversion_rate'],
                ...array_map(fn ($row) => [$row['campaign'], $row['source'], $row['medium'], $row['visitors'], $row['visits'], static::round($row['bounce']), $row['conversions'], static::round($row['rate'])], $this->campaigns($range, $lang, $all)['rows'])
            ],
            'goals' => [
                ['goal', 'type', 'visitors', 'events', 'conversion_rate'],
                ...array_map(fn ($row) => [$row['name'], $row['type'], $row['visitors'], $row['events'], static::round($row['cr'])], $this->goals($range, $lang))
            ],
            'devices' => [
                ['kind', 'value', 'visitors'],
                ...array_merge(...array_map(
                    fn ($kind) => array_map(fn ($row) => [$kind, $row['value'], $row['visitors']], $this->dims($range, $lang, $kind)),
                    ['device', 'browser', 'os', 'language', 'country']
                ))
            ],
            'days' => [
                ['date', 'visitors', 'page_views', 'bounce_rate', 'visit_duration_seconds'],
                ...array_map(fn ($row) => [
                    $row['from'] . (isset($row['hour']) ? sprintf(' %02d:00', $row['hour']) : ($row['to'] !== $row['from'] ? ' to ' . $row['to'] : '')),
                    $row['visitors'] ?? '',
                    $row['views'] ?? '',
                    static::round($row['bounce']),
                    $row['duration'] === null ? '' : (int)round($row['duration'])
                ], $this->chart($range, $lang))
            ],
            default => [
                ['page', 'title', 'visitors', 'page_views', 'entries', 'exits', 'bounce_rate', 'time_on_page_seconds'],
                ...array_map(fn ($row) => [$row['path'], $row['title'], $row['visitors'], $row['views'], $row['entries'], $row['exits'], static::round($row['bounce']), $row['time'] === null ? '' : (int)round($row['time'])], $this->pages($range, $lang, $all)['rows'])
            ]
        };

        $kind = in_array($kind, ['sources', 'campaigns', 'goals', 'devices', 'days'], true) === true ? $kind : 'pages';

        return [
            'filename' => 'analytics-' . $kind . '-' . $range->from . '--' . $range->to . '.csv',
            'csv'      => implode("\r\n", array_map(fn ($row) => implode(',', array_map([static::class, 'csvCell'], $row)), $rows))
        ];
    }

    /**
     * A CSV cell, protected against spreadsheet formulas (page titles and
     * UTM values come from outside)
     */
    public static function csvCell(mixed $value): string
    {
        $value = (string)$value;

        if (preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            $value = "'" . $value;
        }

        return preg_match('/[",\r\n]/', $value) === 1 ? '"' . str_replace('"', '""', $value) . '"' : $value;
    }

    /**
     * First day with data, for "all time"
     */
    public function first(): string|null
    {
        $day = $this->db()->value('SELECT MIN(day) FROM totals');

        return $day === null ? null : (string)$day;
    }

    /**
     * Goals: every event that was sent, plus the page visit goals
     */
    public function goals(Range $range, string|null $lang, bool $compare = false): array
    {
        $total = $this->totals($range, $lang)['visitors'];
        $goals = $this->goalRows($range, $lang);

        if ($compare === true) {
            $previous = array_column($this->goalRows($range->previous(), $lang), 'visitors', 'key');

            foreach ($goals as $index => $goal) {
                $goals[$index]['previous'] = $previous[$goal['key']] ?? 0;
            }
        }

        foreach ($goals as $index => $goal) {
            $goals[$index]['cr'] = $total > 0 ? $goal['visitors'] / $total : null;
        }

        usort($goals, fn ($a, $b) => $b['visitors'] <=> $a['visitors'] ?: strcmp($a['name'], $b['name']));

        return $goals;
    }

    public function hasData(): bool
    {
        return $this->db()->exists() === true && $this->db()->value('SELECT 1 FROM totals LIMIT 1') !== null;
    }

    public function languages(): array
    {
        $languages = [];

        foreach ($this->kirby()->languages() as $language) {
            $languages[] = ['code' => $language->code(), 'name' => $language->name()];
        }

        return $languages;
    }

    /**
     * Visitors right now: seen in the last 5 minutes (Pro)
     */
    public function live(): array
    {
        $db    = $this->db();
        $now   = time();
        $since = $now - 300;
        $today = $this->analytics->today();

        $pages = array_map(fn ($row) => [
            ...$this->model($row['last']),
            'visitors' => (int)$row['visitors']
        ], $db->query('SELECT last, COUNT(*) AS visitors FROM visitors WHERE day = ? AND seen >= ? GROUP BY last ORDER BY visitors DESC LIMIT 10', [$today, $since]));

        $sources = array_map(fn ($row) => [
            'channel'  => $row['channel'],
            'source'   => $row['source'],
            'visitors' => (int)$row['visitors']
        ], $db->query('SELECT channel, source, COUNT(*) AS visitors FROM visitors WHERE day = ? AND seen >= ? GROUP BY channel, source ORDER BY visitors DESC LIMIT 10', [$today, $since]));

        $minutes = array_fill(0, 30, 0);

        foreach ($db->query('SELECT seen / 60 AS minute, COUNT(*) AS visitors FROM visitors WHERE day = ? AND seen >= ? GROUP BY minute', [$today, $now - 1800]) as $row) {
            $index = 29 - (intdiv($now, 60) - (int)$row['minute']);

            if ($index >= 0 && $index < 30) {
                $minutes[$index] = (int)$row['visitors'];
            }
        }

        return [
            'now'     => (int)$db->value('SELECT COUNT(*) FROM visitors WHERE day = ? AND seen >= ?', [$today, $since]),
            'pages'   => $pages,
            'sources' => $sources,
            'minutes' => $minutes,
            'today'   => $this->totals(new Range('today', $today, $today), null),
            'time'    => date('H:i')
        ];
    }

    /**
     * Stats, chart and the lists of the overview tab
     */
    public function overview(Range $range, string|null $lang, bool $pro): array
    {
        $previous = $range->id === 'all' ? null : $this->totals($range->previous(), $lang);

        return [
            'chart'     => $this->chart($range, $lang),
            'previous'  => $previous !== null && $previous['visits'] > 0 ? $previous : null,
            'pages'     => $this->pages($range, $lang, ['limit' => 8, 'trend' => false])['rows'],
            'channels'  => $this->channels($range, $lang),
            'referrers' => $this->referrers($range, $lang, null, ['limit' => 8])['rows'],
            'campaigns' => $pro === true ? $this->campaigns($range, $lang, ['limit' => 8])['rows'] : [],
            'campaignCount' => count($this->campaignRows($range, $lang)),
            'devices'   => $this->dims($range, $lang, 'device'),
            'browsers'  => $this->dims($range, $lang, 'browser', 8),
            'systems'   => $this->dims($range, $lang, 'os', 8),
            'languages' => $this->dims($range, $lang, 'language', 8),
            'countries' => $pro === true ? $this->dims($range, $lang, 'country', 10) : [],
            'goals'     => $pro === true ? array_slice($this->goals($range, $lang), 0, 6) : []
        ];
    }

    /**
     * Details of a page for the drawer
     */
    public function page(string $id, Range $range, string|null $lang, bool $pro = false, bool $compare = false): array
    {
        [$where, $bindings] = $this->where($range, $lang);

        $row = $this->db()->query(
            'SELECT SUM(visitors) AS visitors, SUM(views) AS views, SUM(entries) AS entries, SUM(exits) AS exits, SUM(bounces) AS bounces, SUM(duration) AS duration FROM pages WHERE ' . $where . ' AND page = :page',
            [...$bindings, 'page' => $id]
        )[0] ?? [];

        $metrics = static::pageMetrics($row);
        $totals  = $this->totals($range, $lang);

        // visitors of the page per day or week
        $days = array_column($this->db()->query('SELECT day, SUM(visitors) AS visitors FROM pages WHERE ' . $where . ' AND page = :page GROUP BY day', [...$bindings, 'page' => $id]), 'visitors', 'day');
        $chart = array_map(function ($bucket) use ($days) {
            $sum = 0;

            foreach ($days as $day => $visitors) {
                if ($day >= $bucket['from'] && $day <= $bucket['to']) {
                    $sum += (int)$visitors;
                }
            }

            return [...$bucket, 'visitors' => $sum];
        }, $range->buckets());

        $previous = null;
        $previousChart = null;

        if ($range->id !== 'all') {
            $prev = $range->previous();
            [$pwhere, $pbindings] = $this->where($prev, $lang);

            $previous = (int)$this->db()->value('SELECT COALESCE(SUM(visitors), 0) FROM pages WHERE ' . $pwhere . ' AND page = :page', [...$pbindings, 'page' => $id]);
        }

        if ($compare === true && $range->id !== 'all') {
            $pdays    = array_column($this->db()->query('SELECT day, SUM(visitors) AS visitors FROM pages WHERE ' . $pwhere . ' AND page = :page GROUP BY day', [...$pbindings, 'page' => $id]), 'visitors', 'day');
            $previousChart = array_map(function ($bucket) use ($pdays) {
                $sum = 0;

                foreach ($pdays as $day => $visitors) {
                    if ($day >= $bucket['from'] && $day <= $bucket['to']) {
                        $sum += (int)$visitors;
                    }
                }

                return [...$bucket, 'visitors' => $sum];
            }, $prev->buckets());
        }

        $landings = array_map(fn ($row) => [
            'channel' => $row['channel'],
            'source'  => $row['source'],
            'visits'  => (int)$row['visits']
        ], $this->db()->query(
            'SELECT channel, source, SUM(visits) AS visits FROM landings WHERE ' . $where . ' AND page = :page GROUP BY channel, source ORDER BY visits DESC LIMIT 8',
            [...$bindings, 'page' => $id]
        ));

        $goals = [];

        if ($pro === true) {
            $goals = array_map(fn ($row) => [
                'name'     => $row['name'],
                'type'     => static::eventType($row['name']),
                'visitors' => (int)$row['visitors'],
                'events'   => (int)$row['events'],
                'cr'       => $metrics['visitors'] > 0 ? (int)$row['visitors'] / $metrics['visitors'] : null
            ], $this->db()->query(
                'SELECT name, SUM(events) AS events, SUM(visitors) AS visitors FROM events WHERE ' . $where . " AND page = :page AND name NOT LIKE 'goal:%' GROUP BY name ORDER BY visitors DESC LIMIT 10",
                [...$bindings, 'page' => $id]
            ));
        }

        return [
            'page'      => $this->model($id),
            ...$metrics,
            'previous'  => $previous,
            'share'     => $totals['visits'] > 0 ? $metrics['entries'] / $totals['visits'] : null,
            'chart'     => $range->isHourly() === true ? [] : $chart,
            'prevChart' => $range->isHourly() === true ? null : $previousChart,
            'landings'  => $landings,
            'goals'     => $goals,
            'searches'  => $this->searches($id, $range, $lang)
        ];
    }

    /**
     * Reports of the last 30 days for a page, for Kirby's stats field:
     * `reports: page.analytics.reports`
     */
    public function pageReports(Page $page): array
    {
        try {
            if ($this->hasData() === false) {
                return [];
            }

            $range  = Range::from('30d', today: $this->analytics->today());
            $data   = $this->page(Analytics::modelId($page), $range, null, compare: true);
            $locale = $this->kirby()->user()?->language();
            $top    = null;

            foreach ($data['landings'] as $landing) {
                if ($landing['channel'] !== 'direct') {
                    $top = $landing;
                    break;
                }
            }

            $link = '/analytics?tab=pages';

            return [
                [
                    'label' => I18n::translate('owebstudio.analytics.visitors'),
                    'value' => I18n::formatNumber($data['visitors'], $locale),
                    'info'  => $data['previous'] > 0 ? static::change($data['visitors'], $data['previous'], $locale) . ' ' . I18n::translate('owebstudio.analytics.previous') : null,
                    'link'  => $link
                ],
                [
                    'label' => I18n::translate('owebstudio.analytics.time'),
                    'value' => static::duration($data['time']),
                    'info'  => $data['bounce'] === null ? null : I18n::template('owebstudio.analytics.bounce.info', null, ['rate' => static::percent($data['bounce'], $locale)]),
                    'link'  => $link
                ],
                [
                    'label' => I18n::translate('owebstudio.analytics.source.top'),
                    'value' => $top['source'] ?? '–',
                    'info'  => $top !== null ? I18n::template('owebstudio.analytics.visits.count', null, ['count' => I18n::formatNumber($top['visits'], $locale)]) : null,
                    'link'  => $link
                ]
            ];
        } catch (Throwable $e) {
            Analytics::log($e);
            return [];
        }
    }

    /**
     * Pages with their counters, sorted and paginated
     */
    public function pages(Range $range, string|null $lang, array $options = []): array
    {
        [$where, $bindings] = $this->where($range, $lang);

        $mode = $options['mode'] ?? 'top';
        $rows = $this->db()->query('SELECT page, SUM(visitors) AS visitors, SUM(views) AS views, SUM(entries) AS entries, SUM(exits) AS exits, SUM(bounces) AS bounces, SUM(duration) AS duration FROM pages WHERE ' . $where . ' GROUP BY page', $bindings);
        $rows = array_map(fn ($row) => [...$this->model($row['page']), ...static::pageMetrics($row)], $rows);

        if ($mode === 'entry') {
            $rows = array_values(array_filter($rows, fn ($row) => $row['entries'] > 0));
        } elseif ($mode === 'exit') {
            $rows = array_values(array_filter($rows, fn ($row) => $row['exits'] > 0));
        }

        if ($filter = $this->filter($options['filter'] ?? null)) {
            $rows = array_values(array_filter($rows, fn ($row) => Str::contains($row['title'], $filter, true) || Str::contains($row['path'], $filter, true)));
        }

        $sort   = $options['sort'] ?? match ($mode) {
            'entry' => 'entries',
            'exit'  => 'exits',
            default => 'visitors'
        };
        $result = $this->paginate($this->sort($rows, $sort, $options['dir'] ?? 'desc'), $options);

        if (($options['compare'] ?? false) === true && $result['rows'] !== []) {
            [$pwhere, $pbindings] = $this->where($range->previous(), $lang);

            $ids      = array_column($result['rows'], 'id');
            $marks    = implode(',', array_fill(0, count($ids), '?'));
            $previous = [];

            foreach ($this->db()->query(
                'SELECT page, SUM(visitors) AS visitors, SUM(entries) AS entries, SUM(exits) AS exits FROM pages WHERE day >= ? AND day <= ?' . ($lang !== null ? ' AND lang = ?' : '') . ' AND page IN (' . $marks . ') GROUP BY page',
                [$pbindings['from'], $pbindings['to'], ...($lang !== null ? [$lang] : []), ...$ids]
            ) as $row) {
                $previous[$row['page']] = $row;
            }

            foreach ($result['rows'] as $index => $row) {
                $result['rows'][$index]['previous'] = [
                    'visitors' => (int)($previous[$row['id']]['visitors'] ?? 0),
                    'entries'  => (int)($previous[$row['id']]['entries'] ?? 0),
                    'exits'    => (int)($previous[$row['id']]['exits'] ?? 0)
                ];
            }
        }

        if (($options['trend'] ?? false) === true) {
            $trends = $this->trends(array_column($result['rows'], 'id'), $range, $lang);

            foreach ($result['rows'] as $index => $row) {
                $result['rows'][$index]['trend'] = $trends[$row['id']] ?? [];
            }
        }

        return $result;
    }

    /**
     * Most visited pages as a collection, for templates:
     * `analytics()->data()->popular(5)`
     */
    public function popular(int $limit = 5, int $days = 30, string|null $lang = null): Pages
    {
        $pages = new Pages();

        try {
            if ($this->hasData() === false) {
                return $pages;
            }

            $range = Range::from('custom', date('Y-m-d', strtotime('-' . max(1, $days - 1) . ' days')), $this->analytics->today(), $this->analytics->today());

            [$where, $bindings] = $this->where($range, $lang);

            $rows = $this->db()->query('SELECT page, SUM(visitors) AS visitors FROM pages WHERE ' . $where . ' GROUP BY page ORDER BY visitors DESC LIMIT ' . max(1, $limit * 3), $bindings);

            foreach ($rows as $row) {
                $page = Analytics::model($row['page']);

                if ($page !== null && $page->isHomePage() === false && $page->isErrorPage() === false && $page->isListed() === true) {
                    $pages->add($page);
                }

                if ($pages->count() >= $limit) {
                    break;
                }
            }
        } catch (Throwable $e) {
            Analytics::log($e);
        }

        return $pages;
    }

    /**
     * Sources (referrer hosts and UTM sources), optionally of one channel
     */
    public function referrers(Range $range, string|null $lang, string|null $channel = null, array $options = []): array
    {
        $rows = $this->referrerRows($range, $lang, $channel);

        if ($filter = $this->filter($options['filter'] ?? null)) {
            $rows = array_values(array_filter($rows, fn ($row) => Str::contains($row['source'], $filter, true)));
        }

        if (($options['compare'] ?? false) === true) {
            $previous = array_column($this->referrerRows($range->previous(), $lang, $channel), 'visitors', 'key');

            foreach ($rows as $index => $row) {
                $rows[$index]['previous'] = $previous[$row['key']] ?? 0;
            }
        }

        return $this->paginate($this->sort($rows, $options['sort'] ?? 'visitors', $options['dir'] ?? 'desc'), $options);
    }

    /**
     * Reports of the last 30 days for Kirby's stats field:
     * `reports: site.analytics.reports`
     */
    public function site(): array
    {
        try {
            if ($this->hasData() === false) {
                return [];
            }

            $range    = Range::from('30d', today: $this->analytics->today());
            $totals   = $this->totals($range, null);
            $previous = $this->totals($range->previous(), null);
            $top      = $this->pages($range, null, ['limit' => 1])['rows'][0] ?? null;
            $locale   = $this->kirby()->user()?->language();
            $versus   = ' ' . I18n::translate('owebstudio.analytics.previous');

            return array_values(array_filter([
                [
                    'label' => I18n::translate('owebstudio.analytics.visitors'),
                    'value' => I18n::formatNumber($totals['visitors'], $locale),
                    'info'  => $previous['visitors'] > 0 ? static::change($totals['visitors'], $previous['visitors'], $locale) . $versus : null,
                    'link'  => '/analytics'
                ],
                [
                    'label' => I18n::translate('owebstudio.analytics.views'),
                    'value' => I18n::formatNumber($totals['views'], $locale),
                    'info'  => $previous['views'] > 0 ? static::change($totals['views'], $previous['views'], $locale) . $versus : null,
                    'link'  => '/analytics'
                ],
                $top !== null ? [
                    'label' => I18n::translate('owebstudio.analytics.page.top'),
                    'value' => $top['title'],
                    'info'  => I18n::template('owebstudio.analytics.visitors.count', null, ['count' => I18n::formatNumber($top['visitors'], $locale)]),
                    'link'  => '/analytics?tab=pages'
                ] : null
            ]));
        } catch (Throwable $e) {
            Analytics::log($e);
            return [];
        }
    }

    /**
     * Today is far above the usual traffic at this time of day (Pro)
     */
    public function spike(): array|null
    {
        $today = $this->analytics->today();
        $hour  = (int)date('G');

        $current = (int)$this->db()->value('SELECT COALESCE(SUM(visitors), 0) FROM hours WHERE day = ? AND hour <= ?', [$today, $hour]);
        $usual   = (int)$this->db()->value(
            'SELECT COALESCE(SUM(visitors), 0) FROM hours WHERE day >= ? AND day < ? AND hour <= ?',
            [date('Y-m-d', strtotime($today . ' -7 days')), $today, $hour]
        ) / 7;

        if ($current < 20 || $usual <= 0 || $current < $usual * 2) {
            return null;
        }

        return ['today' => $current, 'usual' => (int)round($usual), 'factor' => round($current / $usual, 1)];
    }

    public function status(): array
    {
        $db    = $this->db();
        $root  = $this->kirby()->root('index');
        $file  = $db->file();
        $ready = Database::isAvailable() === true && $db->exists() === true;
        $geo   = Pro\Geo::instance($this->analytics);

        $ignore = (array)$this->analytics->option('ignore', []);

        return [
            'mode'       => $this->analytics->mode(),
            'endpoint'   => $this->analytics->option('endpoint', '_analytics'),
            'database'   => str_starts_with($file, $root) === true ? ltrim(substr($file, strlen($root)), '/\\') : $file,
            'driver'     => Database::isAvailable(),
            'size'       => $db->size(),
            'last'       => $ready === true ? $db->value('SELECT MAX(day) FROM totals') : null,
            'lastSeen'   => $ready === true ? (int)$db->value('SELECT MAX(seen) FROM visitors') : null,
            'first'      => $ready === true ? $this->first() : null,
            'retention'  => $this->analytics->option('retention'),
            'respectDnt' => $this->analytics->option('respectDnt', true) === true,
            'panelUsers' => $this->analytics->option('panelUsers', false) === true,
            'consent'    => $this->analytics->option('consent') !== null,
            'ignored'    => count((array)($ignore['paths'] ?? [])) + count((array)($ignore['templates'] ?? [])) + count((array)($ignore['ips'] ?? [])),
            'timezone'   => date_default_timezone_get(),
            'geo'        => $geo->exists() === true ? ['file' => basename($geo->file()), ...($geo->info() ?? [])] : null,
            'tier'       => $this->analytics->tier()
        ];
    }

    /**
     * Visitors, visits, page views, bounces and durations of a range
     */
    public function totals(Range $range, string|null $lang): array
    {
        [$where, $bindings] = $this->where($range, $lang);

        $row = $this->db()->query('SELECT COALESCE(SUM(visitors), 0) AS visitors, COALESCE(SUM(visits), 0) AS visits, COALESCE(SUM(views), 0) AS views, COALESCE(SUM(bounces), 0) AS bounces, COALESCE(SUM(duration), 0) AS duration, COALESCE(SUM(conversions), 0) AS conversions FROM totals WHERE ' . $where, $bindings)[0];

        $visits = (int)$row['visits'];

        return [
            'conversions' => (int)$row['conversions'],
            'visitors' => (int)$row['visitors'],
            'visits'   => $visits,
            'views'    => (int)$row['views'],
            'bounces'  => (int)$row['bounces'],
            'bounce'   => $visits > 0 ? (int)$row['bounces'] / $visits : null,
            'duration' => $visits > 0 ? (int)$row['duration'] / $visits : null
        ];
    }

    protected function campaignRows(Range $range, string|null $lang, mixed $filter = null): array
    {
        [$where, $bindings] = $this->where($range, $lang);

        $rows = array_map(fn ($row) => [
            'key'         => $row['campaign'] . '|' . $row['source'] . '|' . $row['medium'],
            'campaign'    => $row['campaign'],
            'source'      => $row['source'],
            'medium'      => $row['medium'],
            'visitors'    => (int)$row['visitors'],
            'visits'      => (int)$row['visits'],
            'bounce'      => (int)$row['visits'] > 0 ? (int)$row['bounces'] / (int)$row['visits'] : null,
            'conversions' => (int)$row['conversions'],
            'rate'        => (int)$row['visitors'] > 0 ? (int)$row['conversions'] / (int)$row['visitors'] : null
        ], $this->db()->query(
            'SELECT campaign, source, medium, SUM(visitors) AS visitors, SUM(visits) AS visits, SUM(bounces) AS bounces, SUM(conversions) AS conversions FROM campaigns WHERE ' . $where . ' GROUP BY campaign, source, medium',
            $bindings
        ));

        if ($filter = $this->filter($filter)) {
            $rows = array_values(array_filter($rows, fn ($row) => Str::contains($row['key'], $filter, true)));
        }

        return $rows;
    }

    /**
     * "↑ 12%" or "↓ 3%" compared with the previous period
     */
    protected static function change(int $current, int $previous, string|null $locale = null): string
    {
        $change = $current / $previous - 1;

        if (abs($change) < 0.005) {
            return '± ' . static::percent(0, $locale);
        }

        return ($change > 0 ? '↑ ' : '↓ ') . static::percent(abs($change), $locale);
    }

    protected function db(): Database
    {
        return $this->analytics->database();
    }

    protected static function duration(float|null $seconds): string
    {
        if ($seconds === null) {
            return '–';
        }

        $seconds = (int)round($seconds);

        return $seconds >= 60 ? intdiv($seconds, 60) . 'm ' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT) . 's' : $seconds . 's';
    }

    public static function eventType(string $name): string
    {
        return match (true) {
            str_starts_with($name, 'Outbound: ') => 'outbound',
            str_starts_with($name, 'Download: ') => 'download',
            str_starts_with($name, 'Form: ')     => 'form',
            default                              => 'event'
        };
    }

    protected function filter(mixed $filter): string|null
    {
        if (is_string($filter) === false) {
            return null;
        }

        $filter = trim($filter);

        return $filter === '' ? null : $filter;
    }

    protected function goalRows(Range $range, string|null $lang): array
    {
        [$where, $bindings] = $this->where($range, $lang);

        $goals = array_map(fn ($row) => [
            'key'      => 'event:' . $row['name'],
            'id'       => null,
            'name'     => $row['name'],
            'type'     => static::eventType($row['name']),
            'rule'     => null,
            'visitors' => (int)$row['visitors'],
            'events'   => (int)$row['events']
        ], $this->db()->query("SELECT name, SUM(events) AS events, SUM(visitors) AS visitors FROM events WHERE " . $where . " AND name NOT LIKE 'goal:%' GROUP BY name", $bindings));

        // page goals are counted from the moment they were added
        $reached = array_column(
            $this->db()->query("SELECT name, SUM(events) AS events, SUM(visitors) AS visitors FROM events WHERE " . $where . " AND name LIKE 'goal:%' GROUP BY name", $bindings),
            null,
            'name'
        );

        foreach ($this->db()->query("SELECT id, name, rule, created FROM goals WHERE type = 'page' ORDER BY id") as $goal) {
            $row = $reached['goal:' . $goal['id']] ?? null;

            $goals[] = [
                'key'      => 'page:' . $goal['id'],
                'id'       => (int)$goal['id'],
                'name'     => $goal['name'],
                'type'     => 'page',
                'rule'     => '/' . trim((string)$goal['rule'], '/'),
                'created'  => $goal['created'],
                'visitors' => (int)($row['visitors'] ?? 0),
                'events'   => (int)($row['events'] ?? 0)
            ];
        }

        return $goals;
    }

    protected function kirby(): App
    {
        return $this->analytics->kirby();
    }

    /**
     * Title, path, icon and links of a stored page id
     */
    protected function model(string $id): array
    {
        if (isset($this->models[$id]) === true) {
            return $this->models[$id];
        }

        $page = Analytics::model($id);

        if ($page === null) {
            return $this->models[$id] = [
                'id'     => $id,
                'title'  => $id,
                'path'   => $id,
                'icon'   => 'cancel-small',
                'exists' => false,
                'link'   => null,
                'url'    => null
            ];
        }

        $link = null;
        $icon = 'page';

        try {
            $link = $page->panel()->url(true);
            $icon = $page->blueprint()->icon() ?? 'page';
        } catch (Throwable) {
        }

        return $this->models[$id] = [
            'id'     => $id,
            'title'  => (string)$page->title()->value(),
            'path'   => $page->isHomePage() === true ? '/' : '/' . $page->id(),
            'icon'   => is_string($icon) === true && preg_match('/^[a-z0-9-]+$/', $icon) === 1 ? $icon : 'page',
            'exists' => true,
            'link'   => $link,
            'url'    => $page->url()
        ];
    }

    protected function paginate(array $rows, array $options): array
    {
        $limit = (int)($options['limit'] ?? static::PER_PAGE);
        $page  = max(1, (int)($options['page'] ?? 1));
        $total = count($rows);

        return [
            'rows'       => $limit > 0 ? array_slice($rows, ($page - 1) * $limit, $limit) : $rows,
            'pagination' => ['page' => $page, 'limit' => $limit > 0 ? $limit : max(1, $total), 'total' => $total]
        ];
    }

    protected static function pageMetrics(array $row): array
    {
        $views   = (int)($row['views'] ?? 0);
        $entries = (int)($row['entries'] ?? 0);
        $exits   = (int)($row['exits'] ?? 0);
        $stayed  = $views - $exits;

        return [
            'visitors' => (int)($row['visitors'] ?? 0),
            'views'    => $views,
            'entries'  => $entries,
            'exits'    => $exits,
            'bounce'   => $entries > 0 ? (int)($row['bounces'] ?? 0) / $entries : null,
            'time'     => $stayed > 0 ? (int)($row['duration'] ?? 0) / $stayed : null,
            'exitRate' => $views > 0 ? $exits / $views : null
        ];
    }

    /**
     * Percentage in the format of the user's language
     */
    protected static function percent(float $value, string|null $locale = null, int $digits = 0): string
    {
        if (class_exists(NumberFormatter::class) === true) {
            $formatter = new NumberFormatter($locale ?? 'en', NumberFormatter::PERCENT);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);

            if (($formatted = $formatter->format($value)) !== false) {
                return $formatted;
            }
        }

        return I18n::formatNumber(round($value * 100, $digits), $locale) . '%';
    }

    protected function referrerRows(Range $range, string|null $lang, string|null $channel): array
    {
        [$where, $bindings] = $this->where($range, $lang);

        if ($channel !== null) {
            $where .= ' AND channel = :channel';
            $bindings['channel'] = $channel;
        }

        return array_map(fn ($row) => [
            'key'      => $row['channel'] . ':' . $row['source'],
            'channel'  => $row['channel'],
            'source'   => $row['source'],
            'visitors' => (int)$row['visitors'],
            'visits'   => (int)$row['visits'],
            'bounce'   => (int)$row['visits'] > 0 ? (int)$row['bounces'] / (int)$row['visits'] : null
        ], $this->db()->query('SELECT channel, source, SUM(visitors) AS visitors, SUM(visits) AS visits, SUM(bounces) AS bounces FROM sources WHERE ' . $where . ' GROUP BY channel, source', $bindings));
    }

    protected static function round(float|null $value): string
    {
        return $value === null ? '' : (string)round($value, 4);
    }

    /**
     * Searches that led to a page, when Search Insights is installed
     */
    protected function searches(string $id, Range $range, string|null $lang): array|null
    {
        if (class_exists('Oweb\SearchInsights\SearchInsights') === false) {
            return null;
        }

        try {
            $insights = \Oweb\SearchInsights\SearchInsights::instance();

            if ($insights->clicks() === false || $insights->data()->hasData() === false) {
                return null;
            }

            $data = $insights->data()->page($id, \Oweb\SearchInsights\Range::from('custom', $range->from, $range->to, $this->analytics->today()), $lang);

            return array_map(fn ($row) => ['term' => $row['term'], 'clicks' => (int)$row['clicks']], array_slice($data['queries'] ?? [], 0, 8));
        } catch (Throwable $e) {
            Analytics::log($e);
            return null;
        }
    }

    protected function sort(array $rows, string $key, string $dir): array
    {
        $dir = $dir === 'asc' ? 1 : -1;

        usort($rows, function ($a, $b) use ($key, $dir) {
            $av = $a[$key] ?? null;
            $bv = $b[$key] ?? null;

            if (is_string($av) === true || is_string($bv) === true) {
                return strnatcasecmp((string)$av, (string)$bv) * $dir;
            }

            return (($av ?? -1) <=> ($bv ?? -1)) * $dir ?: ($b['visitors'] ?? 0) <=> ($a['visitors'] ?? 0);
        });

        return $rows;
    }

    /**
     * Visitors per page in 12 parts of the range, for the trend lines
     */
    protected function trends(array $ids, Range $range, string|null $lang): array
    {
        if ($ids === []) {
            return [];
        }

        $parts  = $range->parts(12);
        $marks  = implode(',', array_fill(0, count($ids), '?'));
        $trends = array_fill_keys($ids, array_fill(0, count($parts), 0));

        $rows = $this->db()->query(
            'SELECT page, day, SUM(visitors) AS visitors FROM pages WHERE day >= ? AND day <= ?' . ($lang !== null ? ' AND lang = ?' : '') . ' AND page IN (' . $marks . ') GROUP BY page, day',
            [$range->from, $range->to, ...($lang !== null ? [$lang] : []), ...$ids]
        );

        foreach ($rows as $row) {
            foreach ($parts as $index => $part) {
                if ($row['day'] >= $part['from'] && $row['day'] <= $part['to']) {
                    $trends[$row['page']][$index] += (int)$row['visitors'];
                    break;
                }
            }
        }

        return $trends;
    }

    /**
     * SQL condition for a range and a language
     */
    protected function where(Range $range, string|null $lang): array
    {
        $where    = 'day >= :from AND day <= :to';
        $bindings = ['from' => $range->from, 'to' => $range->to];

        if ($lang !== null) {
            $where .= ' AND lang = :lang';
            $bindings['lang'] = $lang;
        }

        return [$where, $bindings];
    }
}
