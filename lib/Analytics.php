<?php

namespace Oweb\Analytics;

use Closure;
use Kirby\Cms\App;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Uuid\Uuid;
use Oweb\Analytics\License\License;
use Oweb\Analytics\Pro\EmailReport;
use Oweb\Analytics\Pro\Geo;
use Throwable;

/**
 * Counts the visits of a site. One instance per request,
 * available as `analytics()`.
 */
class Analytics
{
    /**
     * LemonSqueezy checkout and product of Analytics Pro
     */
    public const CHECKOUT = 'https://market.owebstudio.com/checkout/buy/2b5ebedd-a30c-48d5-9142-7adaea60bdfe';
    public const PRODUCT  = 1496355;

    public const MODES = ['server', 'script', 'both'];

    protected static self|null $instance = null;
    protected static License|null $license = null;

    protected Database|null $database = null;
    protected Reports|null $reports = null;
    protected Tracker|null $tracker = null;

    /**
     * The page of the current request, counted after the response was sent
     */
    protected Page|null $candidate = null;

    public function __construct(
        protected App $kirby
    ) {
    }

    public static function instance(): static
    {
        $kirby = App::instance();

        if (static::$instance === null || static::$instance->kirby !== $kirby) {
            static::$instance = new static($kirby);
        }

        return static::$instance;
    }

    /**
     * Handles a hit from the script: a page view (script mode) or an event
     */
    public function collect(array $data): bool
    {
        if (Database::isAvailable() === false || $this->skip(script: true) !== null) {
            return false;
        }

        try {
            $page = static::model($data['p'] ?? null);

            if ($page === null || $this->ignores($page) === true) {
                return false;
            }

            $lang = $this->language($data['l'] ?? null);
            $type = $data['t'] ?? 'pageview';

            if ($type === 'event') {
                $name = static::eventName($data['n'] ?? null);

                if ($name === null) {
                    return false;
                }

                return $this->tracker()->event([
                    ...$this->base($page, $lang),
                    'name' => $name
                ]);
            }

            if ($type !== 'pageview' || $this->mode() !== 'script') {
                return false;
            }

            return $this->pageview($page, $lang, $data['r'] ?? null, is_array($data['u'] ?? null) ? $data['u'] : []);
        } catch (Throwable $e) {
            static::log($e);
            return false;
        }
    }

    public function data(): Reports
    {
        return $this->reports ??= new Reports($this);
    }

    public function database(): Database
    {
        if ($this->database !== null) {
            return $this->database;
        }

        $file = $this->option('database');

        if ($file instanceof Closure) {
            $file = $file($this->kirby);
        }

        return $this->database = new Database(
            $file ?? $this->kirby->root('logs') . '/analytics.sqlite',
            (int)$this->option('timeout', 200)
        );
    }

    /**
     * Counts an event from PHP, e.g. in a form controller:
     * `analytics()->event('Contact form sent')`
     */
    public function event(string $name, Page|null $page = null): bool
    {
        $name = static::eventName($name);
        $page ??= $this->kirby->site()->page();

        if ($name === null || $page === null || Database::isAvailable() === false || $this->skip() !== null) {
            return false;
        }

        try {
            return $this->tracker()->event([...$this->base($page, $this->language()), 'name' => $name]);
        } catch (Throwable $e) {
            static::log($e);
            return false;
        }
    }

    public static function eventName(mixed $name): string|null
    {
        if (is_string($name) === false) {
            return null;
        }

        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
        $name = mb_substr($name, 0, 100, 'UTF-8');

        return $name === '' ? null : $name;
    }

    /**
     * Pro: a valid license key, or a local or staging host.
     * `'tier' => 'free'` shows the Free version, e.g. to test it locally.
     */
    public function isPro(): bool
    {
        return $this->option('tier') !== 'free' && static::license()->isPro();
    }

    public function kirby(): App
    {
        return $this->kirby;
    }

    /**
     * The license of Analytics Pro; without a key, Analytics is Free
     */
    public static function license(): License
    {
        return static::$license ??= new License(
            plugin: 'owebstudio/analytics',
            products: [static::PRODUCT],
            name: 'Analytics',
            url: static::CHECKOUT,
            optional: true,
            // legacy bundle keys don't unlock Analytics Pro
            bundle: []
        );
    }

    public function tier(): string
    {
        return $this->isPro() === true ? 'pro' : 'free';
    }

    public static function log(Throwable $e): void
    {
        error_log('Analytics: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }

    public function mode(): string
    {
        $mode = $this->option('mode', 'server');

        return in_array($mode, static::MODES, true) === true ? $mode : 'server';
    }

    /**
     * Page or file for a stored id or UUID
     */
    public static function model(mixed $id): Page|null
    {
        if (is_string($id) === false || $id === '' || strlen($id) > 300) {
            return null;
        }

        try {
            $model = Uuid::is($id) === true ? Uuid::from($id)?->model() : App::instance()->page($id);
        } catch (Throwable) {
            return null;
        }

        return $model instanceof Page ? $model : null;
    }

    /**
     * Stable id of a page: its UUID when UUIDs are enabled, otherwise its id.
     * Renaming or moving a page keeps its stats.
     */
    public static function modelId(ModelWithContent $model): string
    {
        try {
            if ($uuid = $model->uuid()) {
                return $uuid->toString();
            }
        } catch (Throwable) {
        }

        return $model->id();
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->kirby->option('owebstudio.analytics.' . $key, $default);
    }

    /**
     * `route:after` hook: remembers the page of the request.
     * It is counted after the response was sent, only if it was a 200 HTML page.
     */
    public function remember(mixed $result, bool $final): void
    {
        if (
            $final === false ||
            $this->candidate !== null ||
            $result instanceof Page === false ||
            $result->isErrorPage() === true ||
            $this->mode() === 'script' ||
            Database::isAvailable() === false
        ) {
            return;
        }

        try {
            // checked now: reading the session after the response was sent is not safe
            if ($this->skip() !== null || $this->ignores($result) === true) {
                return;
            }

            $this->candidate = $result;

            register_shutdown_function(fn () => $this->shutdown());
        } catch (Throwable $e) {
            static::log($e);
        }
    }

    /**
     * Reports of the last 30 days for Kirby's stats field:
     * `reports: site.analytics.reports`
     */
    public function reports(): array
    {
        return $this->data()->site();
    }

    /**
     * Reasons not to count the current request
     */
    public function skip(bool $script = false): string|null
    {
        $request = $this->kirby->request();

        if ($script === false && $request->method() !== 'GET') {
            return 'method';
        }

        $agent = (string)$request->header('User-Agent');

        if ((new Agent($agent))->isBot() === true) {
            return 'bot';
        }

        // browsers always send it, many bots don't
        if ($script === false && trim((string)$request->header('Accept-Language')) === '') {
            return 'bot';
        }

        foreach (['Sec-Purpose', 'Purpose', 'X-Purpose', 'X-Moz'] as $header) {
            if (preg_match('/prefetch|prerender|preview/i', (string)$request->header($header)) === 1) {
                return 'prefetch';
            }
        }

        if (
            $this->option('respectDnt', true) === true &&
            ($request->header('DNT') === '1' || $request->header('Sec-GPC') === '1')
        ) {
            return 'dnt';
        }

        // previews of drafts and changes
        if ($request->get('_token') !== null || $request->get('_version') !== null) {
            return 'preview';
        }

        // only ask for the user when there is a session cookie,
        // so that cached pages stay cached for everybody else
        if (
            $this->option('panelUsers', false) !== true &&
            isset($_COOKIE['kirby_session']) === true &&
            $this->kirby->user() !== null
        ) {
            return 'user';
        }

        if ($this->ignoresIp($this->ip()) === true) {
            return 'ip';
        }

        $consent = $this->option('consent');

        if ($consent instanceof Closure && $consent($this->kirby) !== true) {
            return 'consent';
        }

        return null;
    }

    public function today(): string
    {
        return date('Y-m-d');
    }

    public function tracker(): Tracker
    {
        return $this->tracker ??= new Tracker($this->database());
    }

    /**
     * Base data of a hit: time, page, language and the visitor's request data
     */
    protected function base(Page $page, string $lang): array
    {
        $request = $this->kirby->request();
        $time    = time();

        return [
            'day'   => date('Y-m-d', $time),
            'hour'  => (int)date('G', $time),
            'time'  => $time,
            'lang'  => $lang,
            'page'  => static::modelId($page),
            'ip'    => $this->ip(),
            'agent' => (string)$request->header('User-Agent'),
            'host'  => (string)($request->url()->host() ?? '')
        ];
    }

    protected function country(string $ip): string|null
    {
        try {
            return Geo::instance($this)->country($ip);
        } catch (Throwable) {
            return null;
        }
    }

    protected function ignores(Page $page): bool
    {
        $ignore = (array)$this->option('ignore', []);

        if (in_array($page->intendedTemplate()->name(), (array)($ignore['templates'] ?? []), true) === true) {
            return true;
        }

        foreach ((array)($ignore['paths'] ?? []) as $pattern) {
            if (is_string($pattern) === true && fnmatch(trim($pattern, '/'), $page->id()) === true) {
                return true;
            }
        }

        return false;
    }

    protected function ignoresIp(string $ip): bool
    {
        foreach ((array)($this->option('ignore', [])['ips'] ?? []) as $range) {
            if (is_string($range) === true && IpRange::contains($range, $ip) === true) {
                return true;
            }
        }

        return false;
    }

    protected function ip(): string
    {
        $ip = $this->option('ip');

        if ($ip instanceof Closure) {
            $ip = $ip($this->kirby);
        }

        return is_string($ip) === true && $ip !== '' ? $ip : (string)$this->kirby->visitor()->ip();
    }

    /**
     * Language code of the request, or of a code sent by the script
     */
    protected function language(mixed $code = null): string
    {
        if ($this->kirby->multilang() === false) {
            return '';
        }

        if (is_string($code) === true && $this->kirby->language($code) !== null) {
            return $code;
        }

        return $this->kirby->language()?->code() ?? $this->kirby->defaultLanguage()?->code() ?? '';
    }

    protected function pageview(Page $page, string $lang, mixed $referrer, array $utm): bool
    {
        $request = $this->kirby->request();
        $base    = $this->base($page, $lang);
        $agent   = new Agent($base['agent'], $request->header('Sec-CH-UA-Mobile') === '?1' ? true : null);
        $accept  = (string)$request->header('Accept-Language');

        $this->database()->prune($base['day'], $this->option('retention'));

        return $this->tracker()->pageview([
            ...$base,
            'referrer' => is_string($referrer) === true ? $referrer : null,
            'utm'      => array_filter(array_map(fn ($value) => is_string($value) ? $value : null, [
                'source'   => $utm['source'] ?? null,
                'medium'   => $utm['medium'] ?? null,
                'campaign' => $utm['campaign'] ?? null,
                'content'  => $utm['content'] ?? null,
                'term'     => $utm['term'] ?? null
            ])),
            'device'   => $agent->device(),
            'browser'  => $agent->browser(),
            'os'       => $agent->os(),
            'language' => preg_match('/^\s*([a-z]{2,3})\b/i', $accept, $match) === 1 ? strtolower($match[1]) : '',
            'country'  => $this->country($base['ip']),
            'goals'    => $this->goals($page)
        ]);
    }

    /**
     * Ids of the page goals that a page matches
     */
    protected function goals(Page $page): array
    {
        $path = $page->isHomePage() === true ? '' : $page->id();
        $ids  = [];

        foreach ($this->database()->query("SELECT id, rule FROM goals WHERE type = 'page'") as $goal) {
            if (fnmatch(trim((string)$goal['rule'], '/'), $path) === true) {
                $ids[] = (int)$goal['id'];
            }
        }

        return $ids;
    }

    protected function shutdown(): void
    {
        $page = $this->candidate;
        $code = http_response_code();
        $type = (string)($this->kirby->response()->type() ?? 'text/html');

        if ($page === null || ($code !== false && $code !== 200) || str_starts_with($type, 'text/html') === false) {
            return;
        }

        try {
            // send the response to the visitor first
            if ($this->option('flush', true) === true && function_exists('fastcgi_finish_request') === true) {
                fastcgi_finish_request();
            }

            $request = $this->kirby->request();
            $query   = $request->query();

            $this->pageview($page, $this->language(), $request->header('Referer'), [
                'source'   => $query->get('utm_source') ?? $query->get('ref'),
                'medium'   => $query->get('utm_medium'),
                'campaign' => $query->get('utm_campaign'),
                'content'  => $query->get('utm_content'),
                'term'     => $query->get('utm_term')
            ]);

            // without a cron job, the email report goes out after a page view
            if ($this->isPro() === true) {
                $report = new EmailReport($this);

                if ($report->due() === true) {
                    $report->send();
                }
            }
        } catch (Throwable $e) {
            static::log($e);
        }
    }
}
