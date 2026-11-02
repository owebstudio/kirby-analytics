<?php

namespace Oweb\Analytics;

use PHPUnit\Framework\TestCase;

class TrackerTest extends TestCase
{
    protected string $dir;
    protected Database $db;
    protected Tracker $tracker;

    protected function setUp(): void
    {
        $this->dir     = sys_get_temp_dir() . '/analytics-' . bin2hex(random_bytes(4));
        $this->db      = new Database($this->dir . '/logs/analytics.sqlite');
        $this->tracker = new Tracker($this->db);
    }

    protected function tearDown(): void
    {
        unset($this->tracker, $this->db);
        gc_collect_cycles();

        foreach (glob($this->dir . '/logs/*') as $file) {
            @unlink($file);
        }

        @rmdir($this->dir . '/logs');
        @rmdir($this->dir);
    }

    protected function hit(array $data = []): array
    {
        return [
            'day'      => '2026-11-02',
            'hour'     => 10,
            'time'     => 1_000_000,
            'lang'     => '',
            'page'     => 'page://home',
            'ip'       => '203.0.113.5',
            'agent'    => 'Mozilla/5.0 (Macintosh) Firefox/140.0',
            'host'     => 'example.com',
            'referrer' => null,
            'utm'      => [],
            'device'   => 'desktop',
            'browser'  => 'Firefox',
            'os'       => 'macOS',
            'language' => 'en',
            'country'  => null,
            ...$data
        ];
    }

    protected function row(string $table, array $where): array|null
    {
        $sql = implode(' AND ', array_map(fn ($key) => $key . ' = ?', array_keys($where)));
        return $this->db->query('SELECT * FROM ' . $table . ' WHERE ' . $sql, array_values($where))[0] ?? null;
    }

    public function testFirstPageView(): void
    {
        $this->assertTrue($this->tracker->pageview($this->hit(['referrer' => 'https://www.google.de/search?q=x'])));

        $totals = $this->row('totals', ['day' => '2026-11-02']);
        $this->assertSame(1, $totals['visitors']);
        $this->assertSame(1, $totals['visits']);
        $this->assertSame(1, $totals['views']);
        $this->assertSame(1, $totals['bounces']);

        $page = $this->row('pages', ['page' => 'page://home']);
        $this->assertSame(1, $page['entries']);
        $this->assertSame(1, $page['exits']);
        $this->assertSame(1, $page['bounces']);

        $source = $this->row('sources', ['channel' => 'search']);
        $this->assertSame('google.com', $source['source']);
        $this->assertSame(1, $source['visitors']);

        $this->assertSame(1, $this->row('dims', ['kind' => 'browser', 'value' => 'Firefox'])['visitors']);
        $this->assertSame(1, $this->row('hours', ['hour' => 10])['visitors']);
        $this->assertSame(1, $this->row('landings', ['page' => 'page://home'])['visits']);
    }

    public function testSecondPageViewOfTheSameVisit(): void
    {
        $this->tracker->pageview($this->hit(['referrer' => 'https://bsky.app/profile/x']));
        $this->tracker->pageview($this->hit(['page' => 'page://notes', 'time' => 1_000_090, 'referrer' => 'https://example.com/']));

        $totals = $this->row('totals', ['day' => '2026-11-02']);
        $this->assertSame(1, $totals['visitors']);
        $this->assertSame(1, $totals['visits']);
        $this->assertSame(2, $totals['views']);
        $this->assertSame(0, $totals['bounces']);
        $this->assertSame(90, $totals['duration']);

        $home = $this->row('pages', ['page' => 'page://home']);
        $this->assertSame(0, $home['exits']);
        $this->assertSame(0, $home['bounces']);
        $this->assertSame(90, $home['duration']);

        $notes = $this->row('pages', ['page' => 'page://notes']);
        $this->assertSame(0, $notes['entries']);
        $this->assertSame(1, $notes['exits']);

        $this->assertSame(0, $this->row('sources', ['source' => 'bsky.app'])['bounces']);
        $this->assertSame(1, $this->db->value('SELECT COUNT(*) FROM sources'));
    }

    public function testSameVisitorSamePageCountsOneVisitor(): void
    {
        $this->tracker->pageview($this->hit());
        $this->tracker->pageview($this->hit(['time' => 1_000_030]));

        $home = $this->row('pages', ['page' => 'page://home']);
        $this->assertSame(1, $home['visitors']);
        $this->assertSame(2, $home['views']);
    }

    public function testNewVisitAfterTimeout(): void
    {
        $this->tracker->pageview($this->hit());
        $this->tracker->pageview($this->hit(['time' => 1_000_000 + Tracker::TIMEOUT, 'referrer' => 'https://news.ycombinator.com/item?id=1']));

        $totals = $this->row('totals', ['day' => '2026-11-02']);
        $this->assertSame(1, $totals['visitors']);
        $this->assertSame(2, $totals['visits']);
        $this->assertSame(2, $totals['bounces']);
        $this->assertSame(0, $totals['duration']);

        $this->assertSame('other', $this->row('sources', ['source' => 'news.ycombinator.com'])['channel']);
        // device data is counted once per visitor and day
        $this->assertSame(1, $this->row('dims', ['kind' => 'device'])['visitors']);
    }

    public function testCampaignStartsNewVisit(): void
    {
        $this->tracker->pageview($this->hit());
        $this->tracker->pageview($this->hit(['time' => 1_000_060, 'utm' => ['source' => 'newsletter', 'campaign' => 'autumn']]));
        $this->tracker->pageview($this->hit(['time' => 1_000_090, 'referrer' => 'https://example.com/notes']));
        $this->tracker->pageview($this->hit(['time' => 1_000_120, 'referrer' => 'https://duckduckgo.com/']));

        $totals = $this->row('totals', ['day' => '2026-11-02']);
        $this->assertSame(1, $totals['visitors']);
        $this->assertSame(3, $totals['visits']);
        $this->assertSame(4, $totals['views']);
        $this->assertSame(1, $this->row('campaigns', ['campaign' => 'autumn'])['visits']);
        $this->assertSame(0, $this->row('campaigns', ['campaign' => 'autumn'])['bounces']);
    }

    public function testOtherVisitor(): void
    {
        $this->tracker->pageview($this->hit());
        $this->tracker->pageview($this->hit(['ip' => '198.51.100.7']));

        $this->assertSame(2, $this->row('totals', ['day' => '2026-11-02'])['visitors']);
    }

    public function testSaltRotationForgetsVisitors(): void
    {
        $this->tracker->pageview($this->hit());
        $salt = $this->db->meta('salt');

        $this->tracker->pageview($this->hit(['day' => '2026-11-03', 'time' => 1_000_060]));

        $this->assertNotSame($salt, $this->db->meta('salt'));
        $this->assertStringStartsWith('2026-11-03:', $this->db->meta('salt'));
        $this->assertSame(1, $this->db->value('SELECT COUNT(*) FROM visitors'));
        $this->assertSame(0, $this->db->value("SELECT COUNT(*) FROM seen WHERE day = '2026-11-02'"));
        // a new day, a new visitor
        $this->assertSame(1, $this->row('totals', ['day' => '2026-11-03'])['visitors']);
    }

    public function testNoIpOrUserAgentIsStored(): void
    {
        $this->tracker->pageview($this->hit());

        $dump = '';

        foreach (['totals', 'pages', 'sources', 'dims', 'visitors', 'seen', 'meta'] as $table) {
            $dump .= json_encode($this->db->query('SELECT * FROM ' . $table));
        }

        $this->assertStringNotContainsString('203.0.113.5', $dump);
        $this->assertStringNotContainsString('Mozilla', $dump);
    }

    public function testCampaign(): void
    {
        $this->tracker->pageview($this->hit(['utm' => ['source' => 'Newsletter', 'medium' => 'email', 'campaign' => 'Autumn']]));

        $campaign = $this->row('campaigns', ['campaign' => 'autumn']);
        $this->assertSame('newsletter', $campaign['source']);
        $this->assertSame('email', $campaign['medium']);
        $this->assertSame(1, $campaign['visitors']);
        $this->assertSame('email', $this->row('sources', ['source' => 'newsletter'])['channel']);

        $this->tracker->event([...$this->hit(), 'name' => 'Signup', 'time' => 1_000_010]);
        $this->tracker->event([...$this->hit(), 'name' => 'Signup', 'time' => 1_000_020]);

        $this->assertSame(1, $this->row('campaigns', ['campaign' => 'autumn'])['conversions']);
    }

    public function testEvents(): void
    {
        $this->tracker->pageview($this->hit());
        $this->tracker->event([...$this->hit(), 'name' => 'Download: kit.pdf']);
        $this->tracker->event([...$this->hit(), 'name' => 'Download: kit.pdf']);
        $this->tracker->event([...$this->hit(['ip' => '198.51.100.7']), 'name' => 'Download: kit.pdf']);

        $event = $this->row('events', ['name' => 'Download: kit.pdf']);
        $this->assertSame(3, $event['events']);
        $this->assertSame(2, $event['visitors']);
    }

    public function testConversionsCountVisitorsOnce(): void
    {
        $this->tracker->pageview($this->hit(['page' => 'page://a', 'goals' => [1]]));
        $this->tracker->pageview($this->hit(['page' => 'page://b', 'goals' => [1], 'time' => 1_000_030]));
        $this->tracker->event([...$this->hit(), 'page' => 'page://b', 'name' => 'Signup', 'time' => 1_000_040]);
        $this->tracker->pageview($this->hit(['ip' => '198.51.100.7', 'page' => 'page://b', 'goals' => [1]]));

        $this->assertSame(2, (int)$this->db->value("SELECT SUM(visitors) FROM events WHERE name = 'goal:1'"));
        $this->assertSame(3, (int)$this->db->value("SELECT SUM(events) FROM events WHERE name = 'goal:1'"));
        $this->assertSame(2, $this->row('totals', ['day' => '2026-11-02'])['conversions']);
    }

    public function testRateLimit(): void
    {
        for ($i = 0; $i < Tracker::LIMIT + 5; $i++) {
            $this->tracker->pageview($this->hit());
        }

        $this->assertSame(Tracker::LIMIT, $this->row('totals', ['day' => '2026-11-02'])['views']);
    }

    public function testLanguages(): void
    {
        $this->tracker->pageview($this->hit(['lang' => 'en']));
        $this->tracker->pageview($this->hit(['lang' => 'de', 'time' => 1_000_030]));

        $this->assertSame(1, $this->row('totals', ['lang' => 'en'])['visits']);
        $this->assertSame(0, $this->row('totals', ['lang' => 'en'])['bounces']);
        $this->assertSame(1, $this->row('totals', ['lang' => 'de'])['visitors']);
        $this->assertSame(1, $this->row('totals', ['lang' => 'de'])['views']);
        $this->assertSame(0, $this->row('pages', ['lang' => 'en', 'page' => 'page://home'])['exits']);
    }

    public function testPrune(): void
    {
        $this->tracker->pageview($this->hit(['day' => '2026-01-01']));
        $this->tracker->pageview($this->hit(['day' => '2026-11-02']));

        $this->db->prune('2026-11-02', null);
        $this->assertSame(2, $this->db->value('SELECT COUNT(*) FROM totals'));

        $this->db->prune('2026-11-02', 30);
        $this->assertSame(1, $this->db->value('SELECT COUNT(*) FROM totals'));
    }

    public function testClear(): void
    {
        $this->tracker->pageview($this->hit());
        $this->db->execute("INSERT INTO goals (name, type, rule, created) VALUES ('Thanks', 'page', 'contact/thanks', '2026-11-02')");
        $this->db->clear();

        $this->assertSame(0, $this->db->value('SELECT COUNT(*) FROM totals'));
        $this->assertSame(0, $this->db->value('SELECT COUNT(*) FROM visitors'));
        $this->assertSame(1, $this->db->value('SELECT COUNT(*) FROM goals'));
    }
}
