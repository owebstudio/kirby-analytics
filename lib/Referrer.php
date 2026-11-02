<?php

namespace Oweb\Analytics;

/**
 * Turns the referrer and the UTM parameters of a visit into a channel
 * and a source. Only the host is kept, never the full URL.
 */
class Referrer
{
    public const CHANNELS = ['direct', 'search', 'social', 'ai', 'email', 'other'];

    public const AI = [
        'chatgpt.com', 'chat.openai.com', 'openai.com', 'perplexity.ai', 'claude.ai', 'gemini.google.com',
        'bard.google.com', 'copilot.microsoft.com', 'you.com', 'phind.com', 'poe.com', 'deepseek.com',
        'chat.mistral.ai', 'meta.ai', 'grok.com', 'kimi.com', 'chat.qwen.ai'
    ];

    public const EMAIL = [
        'mail.google.com', 'outlook.live.com', 'outlook.office.com', 'outlook.office365.com', 'mail.yahoo.com',
        'mail.proton.me', 'mail.aol.com', 'mail.zoho.com', 'app.fastmail.com', 'mail.gmx.net', 'web.de'
    ];

    public const SEARCH = [
        'google.com', 'bing.com', 'duckduckgo.com', 'yahoo.com', 'ecosia.org', 'startpage.com', 'qwant.com',
        'yandex.com', 'baidu.com', 'search.brave.com', 'kagi.com', 'ask.com', 'naver.com', 'seznam.cz',
        'mojeek.com', 'metager.org', 'swisscows.com', 'presearch.com', 'sogou.com', 'daum.net'
    ];

    public const SOCIAL = [
        'facebook.com', 'instagram.com', 'x.com', 'linkedin.com', 'reddit.com', 'pinterest.com', 'youtube.com',
        'tiktok.com', 'bsky.app', 'threads.net', 'threads.com', 'tumblr.com', 'vk.com', 'xing.com', 't.me',
        'discord.com', 'snapchat.com', 'quora.com', 'flipboard.com', 'mastodon.social', 'whatsapp.com'
    ];

    /**
     * Hosts that point to the same service
     */
    public const ALIASES = [
        't.co'        => 'x.com',
        'twitter.com' => 'x.com',
        'lnkd.in'     => 'linkedin.com',
        'youtu.be'    => 'youtube.com',
        'fb.me'       => 'facebook.com',
        'pin.it'      => 'pinterest.com',
        'old.reddit.com' => 'reddit.com'
    ];

    /**
     * @param string|null $url the referrer (header or `document.referrer`)
     * @param string $own the host of the site; links from it are not a source
     * @param array{source?: string, medium?: string} $utm
     * @return array{channel: string, source: string}
     */
    public static function classify(string|null $url, string $own, array $utm = []): array
    {
        $host   = static::host($url);
        $own    = static::host('//' . $own);
        $medium = strtolower(trim($utm['medium'] ?? ''));
        $source = static::clean($utm['source'] ?? '');

        if ($host !== null && $host === $own) {
            $host = null;
        }

        if (in_array($medium, ['email', 'e-mail', 'mail', 'newsletter'], true) === true) {
            return ['channel' => 'email', 'source' => $source ?: ($host ?? 'email')];
        }

        if ($host !== null) {
            return ['channel' => static::channel($host), 'source' => $host];
        }

        if ($source !== '') {
            $channel = match (true) {
                in_array($medium, ['social', 'social-media', 'sm', 'social_media'], true) => 'social',
                in_array($medium, ['cpc', 'ppc', 'paid', 'paidsearch', 'search', 'organic'], true) => 'search',
                default => static::channel(static::host('//' . $source) ?? $source)
            };

            return ['channel' => $channel, 'source' => $source];
        }

        return ['channel' => 'direct', 'source' => ''];
    }

    /**
     * Channel of a normalized host or a `utm_source` name
     */
    public static function channel(string $host): string
    {
        $match = function (array $list) use ($host): bool {
            foreach ($list as $entry) {
                if ($host === $entry || str_ends_with($host, '.' . $entry) === true) {
                    return true;
                }
            }

            return false;
        };

        $name = explode('.', $host)[0];

        return match (true) {
            $match(static::AI) === true                                => 'ai',
            $match(static::EMAIL) === true,
            str_starts_with($host, 'webmail.') === true,
            str_starts_with($host, 'mail.') === true,
            in_array($name, ['newsletter', 'email', 'mail'], true) === true => 'email',
            $match(static::SEARCH) === true,
            in_array($name, ['google', 'bing', 'duckduckgo', 'ecosia', 'qwant'], true) === true => 'search',
            $match(static::SOCIAL) === true,
            str_contains($host, 'mastodon') === true,
            str_ends_with($host, '.social') === true,
            in_array($name, ['facebook', 'instagram', 'twitter', 'linkedin', 'mastodon', 'bluesky', 'reddit', 'pinterest', 'youtube', 'tiktok', 'threads'], true) === true => 'social',
            default                                                    => 'other'
        };
    }

    /**
     * Normalized host of a URL: lowercase, without `www.`,
     * search engines and some social networks folded into one host
     */
    public static function host(string|null $url): string|null
    {
        if ($url === null || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) === false || $host === '') {
            return null;
        }

        $host = strtolower(rtrim($host, '.'));
        $host = preg_replace('/^(www\d*|m|l|lm|mobile)\./', '', $host);

        if (isset(static::ALIASES[$host]) === true) {
            return static::ALIASES[$host];
        }

        // google.de, google.co.uk, … but not mail.google.com or gemini.google.com
        foreach (['google', 'bing', 'yahoo', 'yandex', 'baidu', 'duckduckgo', 'ecosia'] as $engine) {
            if (preg_match('/^(search\.)?' . $engine . '\.[a-z]{2,3}(\.[a-z]{2})?$/', $host) === 1) {
                return $engine === 'ecosia' ? 'ecosia.org' : $engine . '.com';
            }
        }

        return $host;
    }

    /**
     * A UTM value as stored: trimmed, lowercase, at most 100 characters
     */
    public static function clean(mixed $value): string
    {
        if (is_string($value) === false) {
            return '';
        }

        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', trim($value)) ?? '';

        return mb_strtolower(mb_substr($value, 0, 100, 'UTF-8'), 'UTF-8');
    }
}
