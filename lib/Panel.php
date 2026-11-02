<?php

namespace Oweb\Analytics;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;
use Kirby\Toolkit\Escape;
use Kirby\Toolkit\HtmlString;
use Kirby\Toolkit\I18n;
use Oweb\Analytics\Pro\EmailReport;
use Oweb\Analytics\Pro\Geo;
use Throwable;

/**
 * Views, dialogs, drawers and buttons of the Panel area
 */
class Panel
{
    public const TABS = ['overview', 'pages', 'sources', 'goals', 'live'];

    public const PRO_TABS = ['goals', 'live'];

    /**
     * `analytics` view button: the page's visitors of the last 30 days,
     * opens the page drawer; on the site view it opens the area
     */
    public static function button(Page|Site|null $model = null): array|null
    {
        if (static::canAccess() === false) {
            return null;
        }

        if ($model instanceof Page === false) {
            return [
                'icon' => 'dashboard',
                'text' => I18n::translate('owebstudio.analytics.title'),
                'link' => '/analytics'
            ];
        }

        $badge = null;

        try {
            $analytics = Analytics::instance();

            if (Database::isAvailable() === true && $analytics->data()->hasData() === true) {
                $visitors = $analytics->data()->page(Analytics::modelId($model), Range::from('30d', today: $analytics->today()), null)['visitors'];
                $badge    = $visitors > 0 ? ['text' => static::compact($visitors), 'theme' => 'info'] : null;
            }
        } catch (Throwable $e) {
            Analytics::log($e);
        }

        return [
            'icon'   => 'dashboard',
            'text'   => I18n::translate('owebstudio.analytics.title'),
            // without a range, the drawer shows the last 30 days
            'drawer' => 'analytics/page/' . static::encode(Analytics::modelId($model)),
            'badge'  => $badge
        ];
    }

    public static function canAccess(): bool
    {
        $kirby = App::instance();

        return $kirby->user() !== null && $kirby->panel()->access()->area('analytics') === true;
    }

    public static function canManage(): bool
    {
        $user = App::instance()->user();

        if ($user === null || static::canAccess() === false) {
            return false;
        }

        if ($user->isAdmin() === true) {
            return true;
        }

        return $user->role()->permissions()->for('owebstudio.analytics', 'manage') === true;
    }

    public static function decode(string $key): string
    {
        $value = base64_decode(strtr($key, '-_', '+/'), true);

        if ($value === false) {
            throw new NotFoundException(message: 'Invalid key');
        }

        return $value;
    }

    public static function deleteDialog(): array
    {
        static::guard(manage: true);

        return [
            'component' => 'k-text-dialog',
            'props'     => [
                'text'         => new HtmlString(I18n::translate('owebstudio.analytics.delete.confirm')),
                'submitButton' => [
                    'icon'  => 'trash',
                    'text'  => I18n::translate('delete'),
                    'theme' => 'negative'
                ]
            ]
        ];
    }

    public static function deleteSubmit(): array
    {
        static::guard(manage: true);

        Analytics::instance()->database()->clear();

        return ['message' => I18n::translate('owebstudio.analytics.delete.done')];
    }

    public static function emailDialog(): array
    {
        static::guard(manage: true, pro: true);

        $report = new EmailReport(Analytics::instance());

        return [
            'component' => 'k-analytics-email-dialog',
            'props'     => [
                'value'   => $report->settings(),
                'users'   => $report->candidates(),
                'preview' => $report->preview(),
                'size'    => 'large'
            ]
        ];
    }

    public static function emailSubmit(): array
    {
        static::guard(manage: true, pro: true);

        $report = new EmailReport(Analytics::instance());
        $report->save([
            'frequency'  => get('frequency'),
            'recipients' => get('recipients')
        ]);

        if (get('test') === true || get('test') === 'true') {
            $report->send(force: true, only: App::instance()->user()?->id());

            return ['message' => I18n::translate('owebstudio.analytics.email.test.done')];
        }

        return ['message' => I18n::translate('owebstudio.analytics.email.saved')];
    }

    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function export(): array
    {
        static::guard(pro: true);

        return Analytics::instance()->data()->export(static::param('kind') ?? 'pages', static::range(), static::language());
    }

    public static function geoDialog(): array
    {
        static::guard(manage: true, pro: true);

        return [
            'component' => 'k-text-dialog',
            'props'     => [
                'text'         => new HtmlString(I18n::translate('owebstudio.analytics.geo.confirm')),
                'submitButton' => [
                    'icon'  => 'download',
                    'text'  => I18n::translate('owebstudio.analytics.geo.download'),
                    'theme' => 'positive'
                ]
            ]
        ];
    }

    public static function geoSubmit(): array
    {
        static::guard(manage: true, pro: true);

        try {
            Geo::instance(Analytics::instance())->download();
        } catch (Throwable $e) {
            throw new InvalidArgumentException(message: $e->getMessage());
        }

        return ['message' => I18n::translate('owebstudio.analytics.geo.done')];
    }

    public static function goalDialog(): array
    {
        static::guard(manage: true, pro: true);

        return [
            'component' => 'k-form-dialog',
            'props'     => [
                'fields' => [
                    'name' => [
                        'label'    => I18n::translate('owebstudio.analytics.goal.name'),
                        'type'     => 'text',
                        'required' => true,
                        'counter'  => false,
                        'maxlength' => 100
                    ],
                    'path' => [
                        'label'    => I18n::translate('owebstudio.analytics.goal.path'),
                        'type'     => 'text',
                        'required' => true,
                        'counter'  => false,
                        'before'   => '/',
                        'placeholder' => 'contact/thanks',
                        'help'     => I18n::translate('owebstudio.analytics.goal.path.help')
                    ],
                    'info' => [
                        'type'  => 'info',
                        'text'  => I18n::translate('owebstudio.analytics.goal.events'),
                        'theme' => 'info'
                    ]
                ],
                'submitButton' => [
                    'icon'  => 'check',
                    'text'  => I18n::translate('owebstudio.analytics.goal.add'),
                    'theme' => 'positive'
                ]
            ]
        ];
    }

    public static function goalSubmit(): array
    {
        static::guard(manage: true, pro: true);

        $name = Analytics::eventName(get('name'));
        $path = trim((string)get('path'), " /\t\n");

        if ($name === null || $path === '' || strlen($path) > 200) {
            throw new InvalidArgumentException(key: 'owebstudio.analytics.goal.invalid');
        }

        Analytics::instance()->database()->execute(
            "INSERT INTO goals (name, type, rule, created) VALUES (?, 'page', ?, ?)",
            [$name, $path, date('Y-m-d')]
        );

        return ['message' => I18n::template('owebstudio.analytics.goal.added', null, ['name' => $name])];
    }

    public static function goalDeleteDialog(int $id): array
    {
        static::guard(manage: true, pro: true);

        $name = Analytics::instance()->database()->value('SELECT name FROM goals WHERE id = ?', [$id]);

        if ($name === null) {
            throw new NotFoundException(message: 'Goal not found');
        }

        return [
            'component' => 'k-text-dialog',
            'props'     => [
                'text'         => new HtmlString(I18n::template('owebstudio.analytics.goal.delete.confirm', null, ['name' => Escape::html($name)])),
                'submitButton' => [
                    'icon'  => 'trash',
                    'text'  => I18n::translate('delete'),
                    'theme' => 'negative'
                ]
            ]
        ];
    }

    public static function goalDeleteSubmit(int $id): array
    {
        static::guard(manage: true, pro: true);

        Analytics::instance()->database()->execute('DELETE FROM goals WHERE id = ?', [$id]);

        return ['message' => I18n::translate('owebstudio.analytics.goal.deleted')];
    }

    public static function live(): array
    {
        static::guard(pro: true);

        return Analytics::instance()->data()->live();
    }

    public static function pageDrawer(string $key): array
    {
        static::guard();

        $analytics = Analytics::instance();
        $range     = static::range();
        $compare   = $analytics->isPro() === true && get('compare') === 'true';
        $data      = $analytics->data()->page(static::decode($key), $range, static::language(), $analytics->isPro(), $compare);

        return [
            'component' => 'k-analytics-page-drawer',
            'props'     => [
                ...$data,
                'icon'    => $data['page']['icon'],
                'title'   => $data['page']['title'],
                'range'   => $range->toArray(),
                'tier'    => $analytics->tier(),
                'compare' => $compare
            ]
        ];
    }

    public static function proDialog(): array
    {
        static::guard();

        $analytics = Analytics::instance();

        $license = Analytics::license()->panel();

        return [
            'component' => 'k-analytics-pro-dialog',
            'props'     => [
                'feature'  => static::param('feature'),
                'first'    => $analytics->data()->hasData() === true ? $analytics->data()->first() : null,
                'buy'      => $license['link'],
                // the license dialog of owebstudio/kirby-license, for admins
                'activate' => $license['dialog'],
                'size'     => 'large'
            ]
        ];
    }

    public static function statusDialog(): array
    {
        static::guard();

        return [
            'component' => 'k-analytics-status-dialog',
            'props'     => [
                'status'    => Analytics::instance()->data()->status(),
                'canManage' => static::canManage(),
                'size'      => 'large'
            ]
        ];
    }

    public static function view(): array
    {
        static::guard();

        $analytics = Analytics::instance();
        $data      = $analytics->data();
        $pro       = $analytics->isPro();
        $range     = static::range();
        $lang      = static::language();
        $tab       = in_array(static::param('tab'), static::TABS, true) === true ? static::param('tab') : 'overview';
        $mode      = static::param('mode');
        $channel   = static::param('channel');
        $compare   = $pro === true && static::param('compare') === 'true' && $range->id !== 'all';

        $options = [
            'filter'  => static::param('q'),
            'sort'    => static::param('sort'),
            'dir'     => static::param('dir') === 'asc' ? 'asc' : 'desc',
            'page'    => static::param('page'),
            'compare' => $compare
        ];

        $props = [
            'tab'       => $tab,
            'tier'      => $pro === true ? 'pro' : 'free',
            'range'     => $range->toArray(),
            'lang'      => $lang,
            'languages' => $data->languages(),
            'canManage' => static::canManage(),
            'compare'   => $compare,
            'filter'    => is_string($options['filter']) === true ? $options['filter'] : '',
            'sort'      => static::param('sort'),
            'dir'       => $options['dir'],
            'mode'      => $mode,
            'channel'   => in_array($channel, Referrer::CHANNELS, true) === true ? $channel : null,
            'today'     => $analytics->today(),
            'first'     => null,
            'empty'     => false,
            'error'     => null,
            'data'      => null,
            'summary'   => null,
            'previous'  => null,
            'spike'     => null,
            'live'      => null
        ];

        if (Database::isAvailable() === false) {
            return [...$props, 'error' => I18n::translate('owebstudio.analytics.error.driver')];
        }

        try {
            if ($data->hasData() === false) {
                return [...$props, 'empty' => true];
            }

            $props['first']    = $data->first();
            $props['summary']  = $data->totals($range, $lang);
            $props['previous'] = $range->id === 'all' ? null : $data->totals($range->previous(), $lang);

            if ($props['previous'] !== null && $props['previous']['visits'] === 0) {
                $props['previous'] = null;
            }

            if ($pro === true) {
                $props['spike'] = $data->spike();
                $props['live']  = $data->live()['now'];
            }

            if (in_array($tab, static::PRO_TABS, true) === true && $pro === false) {
                return $props;
            }

            $props['data'] = match ($tab) {
                'pages'   => $data->pages($range, $lang, [...$options, 'mode' => in_array($mode, ['entry', 'exit'], true) ? $mode : 'top', 'trend' => true]),
                'sources' => match ($mode) {
                    'referrers' => $data->referrers($range, $lang, $props['channel'], $options),
                    'campaigns' => $pro === true ? $data->campaigns($range, $lang, $options) : ['count' => $data->overview($range, $lang, false)['campaignCount']],
                    default     => ['rows' => $data->channels($range, $lang, $options)]
                },
                'goals'   => [
                    'rows'      => $data->goals($range, $lang, $compare),
                    'campaigns' => $data->campaigns($range, $lang, ['limit' => 1, 'sort' => 'conversions'])['rows']
                ],
                'live'    => $data->live(),
                default   => $data->overview($range, $lang, $pro)
            };

            if ($tab === 'overview' && $compare === true) {
                $props['data']['prevChart'] = $data->chart($range->previous(), $lang);
            }

            $props['data'] = static::withKeys($props['data']);
        } catch (Throwable $e) {
            Analytics::log($e);

            $props['error'] = App::instance()->option('debug') === true ? $e->getMessage() : I18n::translate('owebstudio.analytics.error');
        }

        return $props;
    }

    protected static function compact(int $value): string
    {
        return match (true) {
            $value >= 10000 => round($value / 1000) . 'k',
            $value >= 1000  => rtrim(rtrim(number_format($value / 1000, 1, '.', ''), '0'), '.') . 'k',
            default         => (string)$value
        };
    }

    protected static function guard(bool $manage = false, bool $pro = false): void
    {
        if (static::canAccess() === false || ($manage === true && static::canManage() === false)) {
            throw new PermissionException();
        }

        if ($pro === true && Analytics::instance()->isPro() === false) {
            throw new PermissionException(key: 'owebstudio.analytics.pro.required');
        }
    }

    protected static function language(): string|null
    {
        $lang  = static::param('lang');
        $kirby = App::instance();

        if (is_string($lang) === true && $kirby->multilang() === true && $kirby->language($lang) !== null) {
            return $lang;
        }

        return null;
    }

    /**
     * A query parameter as string, or `null` when it is missing or an array
     */
    protected static function param(string $key): string|null
    {
        $value = get($key);

        return is_string($value) === true ? $value : null;
    }

    /**
     * The requested range; Pro ranges fall back to the last 30 days in Free
     */
    protected static function range(): Range
    {
        $analytics = Analytics::instance();
        $first     = null;

        try {
            $first = $analytics->data()->hasData() === true ? $analytics->data()->first() : null;
        } catch (Throwable) {
        }

        $range = Range::from(static::param('range'), static::param('from'), static::param('to'), $analytics->today(), $first);

        if ($range->isFree() === false && $analytics->isPro() === false) {
            return Range::from('30d', today: $analytics->today());
        }

        return $range;
    }

    /**
     * Adds the drawer keys to page rows
     */
    protected static function withKeys(array $data): array
    {
        $key = function (array $row) {
            if (isset($row['id'], $row['path'], $row['title']) === true) {
                $row['key'] = static::encode($row['id']);
            }

            return $row;
        };

        foreach (['rows', 'pages'] as $list) {
            if (isset($data[$list]) === true && is_array($data[$list]) === true) {
                $data[$list] = array_map($key, $data[$list]);
            }
        }

        return $data;
    }
}
