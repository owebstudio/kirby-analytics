<?php

use Kirby\Cms\App as Kirby;
use Kirby\Cms\Page;
use Kirby\Http\Response;
use Oweb\Analytics\Analytics;
use Oweb\Analytics\PageAnalytics;
use Oweb\Analytics\Panel;
use Oweb\Analytics\Pro\EmailReport;

load([
    'Oweb\\Analytics\\Agent'           => 'lib/Agent.php',
    'Oweb\\Analytics\\Analytics'       => 'lib/Analytics.php',
    'Oweb\\Analytics\\Database'        => 'lib/Database.php',
    'Oweb\\Analytics\\IpRange'         => 'lib/IpRange.php',
    'Oweb\\Analytics\\PageAnalytics'   => 'lib/PageAnalytics.php',
    'Oweb\\Analytics\\Panel'           => 'lib/Panel.php',
    'Oweb\\Analytics\\Range'           => 'lib/Range.php',
    'Oweb\\Analytics\\Referrer'        => 'lib/Referrer.php',
    'Oweb\\Analytics\\Reports'         => 'lib/Reports.php',
    'Oweb\\Analytics\\Tracker'         => 'lib/Tracker.php',
    'Oweb\\Analytics\\Pro\\EmailReport' => 'lib/Pro/EmailReport.php',
    'Oweb\\Analytics\\Pro\\Geo'        => 'lib/Pro/Geo.php',
    'Oweb\\Analytics\\Pro\\Mmdb'       => 'lib/Pro/Mmdb.php'
], __DIR__);

require_once __DIR__ . '/lib/license/autoload.php';

$license = Analytics::license();

if (function_exists('analytics') === false) {
    /**
     * Analytics of your site, e.g. `analytics()->event('Signup')`
     */
    function analytics(): Analytics
    {
        return Analytics::instance();
    }
}

Kirby::plugin('owebstudio/analytics', $license->extend([
    'options' => [
        'consent'    => null,
        'database'   => null,
        'endpoint'   => '_analytics',
        'flush'      => true,
        'geoip'      => null,
        'ignore'     => [],
        'ip'         => null,
        'mode'       => 'server',
        'panelUsers' => false,
        'respectDnt' => true,
        'retention'  => null,
        'timeout'    => 200
    ],
    'permissions' => [
        'manage' => true
    ],
    'translations' => [
        'de' => require __DIR__ . '/translations/de.php',
        'en' => require __DIR__ . '/translations/en.php',
        'fr' => require __DIR__ . '/translations/fr.php',
        'tr' => require __DIR__ . '/translations/tr.php'
    ],
    'hooks' => [
        'route:after' => function ($route, $path, $method, $result, $final) {
            analytics()->remember($result, (bool)$final);
            return $result;
        }
    ],
    'routes' => function (Kirby $kirby) {
        $endpoint = trim((string)$kirby->option('owebstudio.analytics.endpoint', '_analytics'), '/');

        return [
            [
                'pattern' => $endpoint,
                'method'  => 'POST',
                'action'  => function () {
                    $body = file_get_contents('php://input', length: 4096);
                    $data = json_decode((string)$body, true);
                    $done = is_array($data) === true && analytics()->collect($data) === true;

                    return new Response('', 'text/plain', $done === true ? 204 : 202);
                }
            ]
        ];
    },
    'pageMethods' => [
        'analytics' => function () {
            return new PageAnalytics($this);
        }
    ],
    'siteMethods' => [
        'analytics' => function () {
            return Analytics::instance();
        }
    ],
    'snippets' => [
        'analytics/script' => __DIR__ . '/snippets/script.php'
    ],
    'commands' => [
        'analytics:report' => [
            'description' => 'Sends the Analytics email report when it is due (--force sends it now)',
            'args'        => [
                'force' => [
                    'longPrefix'  => 'force',
                    'description' => 'Send the report now',
                    'noValue'     => true
                ]
            ],
            'command' => function ($cli) {
                $analytics = Analytics::instance();

                if ($analytics->isPro() === false) {
                    $cli->error('Email reports are part of Analytics Pro');
                    return;
                }

                $sent = (new EmailReport($analytics))->send(force: (bool)$cli->arg('force'));
                $cli->success($sent > 0 ? 'Sent ' . $sent . ' email(s)' : 'Nothing to send');
            }
        ]
    ],
    'areas' => [
        'analytics' => function () {
            return [
                'label' => t('owebstudio.analytics.title'),
                'icon'  => 'dashboard',
                'menu'  => true,
                'link'  => 'analytics',
                'views' => [
                    [
                        'pattern' => 'analytics',
                        'action'  => function () {
                            return [
                                'component' => 'k-analytics-view',
                                'title'     => t('owebstudio.analytics.title'),
                                'props'     => Panel::view()
                            ];
                        }
                    ]
                ],
                'buttons' => [
                    'analytics' => function ($model = null) {
                        return Panel::button($model);
                    }
                ],
                'dialogs' => [
                    'analytics/delete' => [
                        'load'   => fn () => Panel::deleteDialog(),
                        'submit' => fn () => Panel::deleteSubmit()
                    ],
                    'analytics/email' => [
                        'load'   => fn () => Panel::emailDialog(),
                        'submit' => fn () => Panel::emailSubmit()
                    ],
                    'analytics/geo' => [
                        'load'   => fn () => Panel::geoDialog(),
                        'submit' => fn () => Panel::geoSubmit()
                    ],
                    'analytics/goal' => [
                        'load'   => fn () => Panel::goalDialog(),
                        'submit' => fn () => Panel::goalSubmit()
                    ],
                    'analytics/goal/(:num)/delete' => [
                        'load'   => fn (string $id) => Panel::goalDeleteDialog((int)$id),
                        'submit' => fn (string $id) => Panel::goalDeleteSubmit((int)$id)
                    ],
                    'analytics/pro' => [
                        'load' => fn () => Panel::proDialog()
                    ],
                    'analytics/status' => [
                        'load' => fn () => Panel::statusDialog()
                    ]
                ],
                'drawers' => [
                    'analytics/page/(:any)' => [
                        'load' => fn (string $key) => Panel::pageDrawer($key)
                    ]
                ]
            ];
        }
    ],
    'api' => [
        'routes' => [
            [
                'pattern' => 'analytics/export',
                'method'  => 'GET',
                'action'  => fn () => Panel::export()
            ],
            [
                'pattern' => 'analytics/live',
                'method'  => 'GET',
                'action'  => fn () => Panel::live()
            ]
        ]
    ]
]), ...$license->args());
