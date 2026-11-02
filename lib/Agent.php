<?php

namespace Oweb\Analytics;

/**
 * Small user agent parser: bot check, device type, browser and OS.
 * The user agent itself is never stored.
 */
class Agent
{
    public const BOTS = '/bot\b|bot\/|crawl|spider|slurp|archiver|facebookexternalhit|embedly|headless|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|preview|validator|checker|scanner|curl|wget|python|httpclient|okhttp|java\/|go-http|axios|node-fetch|scrapy|feed|phantomjs|puppeteer|playwright|selenium|semrush|ahrefs|mj12|petalbot|bytespider|gptbot|ccbot|claudebot|perplexitybot|amazonbot|applebot|yandex(?!browser)|baiduspider|duckduckbot|bingpreview|mediapartners|adsbot|google-inspectiontool|ia_archiver|whatsapp|telegrambot|discordbot|slackbot|twitterbot|linkedinbot|redditbot|skypeuripreview/i';

    public function __construct(
        protected string $agent,
        protected bool|null $mobileHint = null
    ) {
    }

    public function browser(): string
    {
        $agent = $this->agent;

        return match (true) {
            preg_match('/Edg(e|A|iOS)?\//', $agent) === 1     => 'Edge',
            preg_match('/OPR\/|Opera/', $agent) === 1          => 'Opera',
            str_contains($agent, 'SamsungBrowser') === true    => 'Samsung Internet',
            str_contains($agent, 'Vivaldi') === true           => 'Vivaldi',
            str_contains($agent, 'YaBrowser') === true         => 'Yandex Browser',
            preg_match('/Firefox|FxiOS/', $agent) === 1        => 'Firefox',
            preg_match('/Chrome|CriOS|Chromium/', $agent) === 1 => 'Chrome',
            str_contains($agent, 'Safari') === true            => 'Safari',
            default                                            => 'Other'
        };
    }

    public function device(): string
    {
        $agent = $this->agent;

        if (preg_match('/iPad|Tablet|Kindle|Silk|PlayBook|Nexus (7|9|10)|SM-T\d/i', $agent) === 1) {
            return 'tablet';
        }

        if (
            $this->mobileHint === true ||
            preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry|Opera Mini/i', $agent) === 1
        ) {
            return 'mobile';
        }

        // Android without "Mobile" is a tablet
        if (str_contains($agent, 'Android') === true) {
            return 'tablet';
        }

        return 'desktop';
    }

    public function isBot(): bool
    {
        return $this->agent === '' || preg_match(static::BOTS, $this->agent) === 1;
    }

    public function os(): string
    {
        $agent = $this->agent;

        return match (true) {
            preg_match('/iPhone|iPad|iPod/', $agent) === 1    => 'iOS',
            str_contains($agent, 'Android') === true          => 'Android',
            str_contains($agent, 'CrOS') === true             => 'ChromeOS',
            str_contains($agent, 'Windows') === true          => 'Windows',
            preg_match('/Mac OS X|Macintosh/', $agent) === 1  => 'macOS',
            preg_match('/Linux|X11/', $agent) === 1           => 'Linux',
            default                                           => 'Other'
        };
    }
}
