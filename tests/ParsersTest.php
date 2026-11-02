<?php

namespace Oweb\Analytics;

use Oweb\Analytics\Pro\Mmdb;
use Oweb\Analytics\Tests\MmdbWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ParsersTest extends TestCase
{
    public static function referrers(): array
    {
        return [
            ['https://www.google.de/search?q=x', [], 'search', 'google.com'],
            ['https://google.co.uk/', [], 'search', 'google.com'],
            ['https://duckduckgo.com/', [], 'search', 'duckduckgo.com'],
            ['https://gemini.google.com/app', [], 'ai', 'gemini.google.com'],
            ['https://chatgpt.com/', [], 'ai', 'chatgpt.com'],
            ['https://mail.google.com/mail/u/0', [], 'email', 'mail.google.com'],
            ['https://t.co/abc', [], 'social', 'x.com'],
            ['https://l.instagram.com/?u=x', [], 'social', 'instagram.com'],
            ['https://mastodon.online/@x', [], 'social', 'mastodon.online'],
            ['https://chaos.social/@x', [], 'social', 'chaos.social'],
            ['https://news.ycombinator.com/item?id=1', [], 'other', 'news.ycombinator.com'],
            ['https://example.com/notes', [], 'direct', ''],
            [null, [], 'direct', ''],
            [null, ['source' => 'chatgpt.com'], 'ai', 'chatgpt.com'],
            [null, ['source' => 'Newsletter', 'medium' => 'email'], 'email', 'newsletter'],
            [null, ['source' => 'mastodon', 'medium' => 'social'], 'social', 'mastodon'],
            [null, ['source' => 'partner'], 'other', 'partner'],
            ['https://www.facebook.com/', ['source' => 'fb', 'medium' => 'newsletter'], 'email', 'fb']
        ];
    }

    #[DataProvider('referrers')]
    public function testReferrer(string|null $url, array $utm, string $channel, string $source): void
    {
        $this->assertSame(['channel' => $channel, 'source' => $source], Referrer::classify($url, 'example.com', $utm));
    }

    public static function agents(): array
    {
        return [
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', false, 'desktop', 'Chrome', 'Windows'],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0', false, 'desktop', 'Edge', 'Windows'],
            ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', false, 'mobile', 'Safari', 'iOS'],
            ['Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', false, 'tablet', 'Safari', 'iOS'],
            ['Mozilla/5.0 (Linux; Android 15; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/27.0 Chrome/125.0.0.0 Mobile Safari/537.36', false, 'mobile', 'Samsung Internet', 'Android'],
            ['Mozilla/5.0 (Linux; Android 14; Pixel Tablet) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', false, 'tablet', 'Chrome', 'Android'],
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 15.0; rv:140.0) Gecko/20100101 Firefox/140.0', false, 'desktop', 'Firefox', 'macOS'],
            ['Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0', false, 'desktop', 'Firefox', 'Linux'],
            ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true, 'desktop', 'Other', 'Other'],
            ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', true, 'desktop', 'Other', 'Other'],
            ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36', true, 'desktop', 'Chrome', 'Linux'],
            ['curl/8.5.0', true, 'desktop', 'Other', 'Other'],
            ['', true, 'desktop', 'Other', 'Other']
        ];
    }

    #[DataProvider('agents')]
    public function testAgent(string $agent, bool $bot, string $device, string $browser, string $os): void
    {
        $parsed = new Agent($agent);

        $this->assertSame($bot, $parsed->isBot());
        $this->assertSame($device, $parsed->device());
        $this->assertSame($browser, $parsed->browser());
        $this->assertSame($os, $parsed->os());
    }

    public function testMobileHint(): void
    {
        $this->assertSame('mobile', (new Agent('Mozilla/5.0 (Linux; Android 10; K) Chrome/140.0.0.0 Safari/537.36', true))->device());
    }

    public function testIpRange(): void
    {
        $this->assertTrue(IpRange::contains('203.0.113.0/24', '203.0.113.99'));
        $this->assertFalse(IpRange::contains('203.0.113.0/24', '203.0.114.1'));
        $this->assertTrue(IpRange::contains('10.0.0.0/9', '10.127.0.1'));
        $this->assertFalse(IpRange::contains('10.0.0.0/9', '10.128.0.1'));
        $this->assertTrue(IpRange::contains('198.51.100.7', '198.51.100.7'));
        $this->assertTrue(IpRange::contains('2001:db8::/32', '2001:db8:1::1'));
        $this->assertFalse(IpRange::contains('2001:db8::/32', '203.0.113.1'));
        $this->assertFalse(IpRange::contains('nonsense', '203.0.113.1'));
    }

    public function testRange(): void
    {
        $range = Range::from('30d', today: '2026-11-02');
        $this->assertSame(['2026-10-04', '2026-11-02', 30], [$range->from, $range->to, $range->days()]);
        $this->assertSame(['2026-09-04', '2026-10-03'], [$range->previous()->from, $range->previous()->to]);
        $this->assertTrue($range->isFree());

        $yesterday = Range::from('yesterday', today: '2026-11-02');
        $this->assertSame(['2026-11-01', '2026-11-01'], [$yesterday->from, $yesterday->to]);
        $this->assertTrue($yesterday->isHourly());

        $all = Range::from('all', today: '2026-11-02', first: '2025-09-29');
        $this->assertSame('2025-09-29', $all->from);
        $this->assertFalse($all->isFree());
        // 400 days in weeks
        $this->assertCount(58, $all->buckets());

        $custom = Range::from('custom', '2026-12-01', '2026-10-01', '2026-11-02');
        $this->assertSame(['2026-10-01', '2026-11-02'], [$custom->from, $custom->to]);

        $this->assertSame('30d', Range::from('nope', today: '2026-11-02')->id);
    }

    public function testMmdb(): void
    {
        $file = sys_get_temp_dir() . '/analytics-test-' . bin2hex(random_bytes(4)) . '.mmdb';

        (new MmdbWriter())
            ->add('203.0.113.0/24', ['country' => ['iso_code' => 'DE', 'names' => ['en' => 'Germany']]])
            ->add('198.51.100.0/25', ['country' => ['iso_code' => 'TR']])
            ->add('2001:db8::/32', ['country' => ['iso_code' => 'CH']])
            ->write($file);

        $db = new Mmdb($file);

        $this->assertSame('DE', $db->get('203.0.113.77')['country']['iso_code']);
        $this->assertSame('Germany', $db->get('203.0.113.1')['country']['names']['en']);
        $this->assertSame('TR', $db->get('198.51.100.12')['country']['iso_code']);
        $this->assertNull($db->get('198.51.100.200'));
        $this->assertSame('CH', $db->get('2001:db8:5::1')['country']['iso_code']);
        $this->assertNull($db->get('192.0.2.1'));
        $this->assertNull($db->get('not an ip'));
        $this->assertSame('Test-Country', $db->metadata()['database_type']);

        unset($db);
        @unlink($file);
    }
}
