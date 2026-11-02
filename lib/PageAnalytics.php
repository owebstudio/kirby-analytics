<?php

namespace Oweb\Analytics;

use Kirby\Cms\Page;
use Throwable;

/**
 * `$page->analytics()`: the numbers of one page
 */
class PageAnalytics
{
    public function __construct(
        protected Page $page
    ) {
    }

    /**
     * Reports of the last 30 days for Kirby's stats field:
     * `reports: page.analytics.reports`
     */
    public function reports(): array
    {
        return Analytics::instance()->data()->pageReports($this->page);
    }

    /**
     * Page views of the last `$days` days
     */
    public function views(int $days = 30): int
    {
        return $this->metric('views', $days);
    }

    /**
     * Visitors of the last `$days` days
     */
    public function visitors(int $days = 30): int
    {
        return $this->metric('visitors', $days);
    }

    protected function metric(string $key, int $days): int
    {
        try {
            $analytics = Analytics::instance();

            if (Database::isAvailable() === false || $analytics->data()->hasData() === false) {
                return 0;
            }

            $today = $analytics->today();
            $range = Range::from('custom', date('Y-m-d', strtotime($today . ' -' . max(0, $days - 1) . ' days')), $today, $today);

            return $analytics->data()->page(Analytics::modelId($this->page), $range, null)[$key];
        } catch (Throwable $e) {
            Analytics::log($e);
            return 0;
        }
    }
}
