<?php

namespace Oweb\Analytics\Pro;

use DateInterval;
use DateTimeImmutable;
use Kirby\Cms\User;
use Kirby\Toolkit\Escape;
use Kirby\Toolkit\I18n;
use Oweb\Analytics\Analytics;
use Oweb\Analytics\Range;
use Throwable;

/**
 * Weekly or monthly summary by email, sent with the site's own
 * Kirby email setup to Panel users who were picked in the Panel.
 *
 * Part of Analytics Pro. Commercial license, see LICENSE.md.
 */
class EmailReport
{
    public const FREQUENCIES = ['weekly', 'monthly', 'off'];

    public function __construct(
        protected Analytics $analytics
    ) {
    }

    /**
     * Panel users who can see the area and can get the report
     */
    public function candidates(): array
    {
        $users = [];

        foreach ($this->analytics->kirby()->users() as $user) {
            try {
                $allowed = $user->isAdmin() === true || $user->role()->permissions()->for('access', 'analytics') !== false;
            } catch (Throwable) {
                $allowed = false;
            }

            if ($allowed === true) {
                $users[] = ['value' => $user->id(), 'text' => $user->username() . ' (' . $user->role()->title() . ')'];
            }
        }

        return $users;
    }

    /**
     * Sends the report when it is due: weekly on Monday, monthly on the 1st,
     * from 8:00 on. Called by the CLI command and after counted page views.
     */
    public function due(): bool
    {
        $settings = $this->settings();

        if ($settings['frequency'] === 'off' || $settings['recipients'] === [] || (int)date('G') < 8) {
            return false;
        }

        if ($settings['frequency'] === 'monthly' && date('j') !== '1') {
            return false;
        }

        if ($settings['frequency'] === 'weekly' && date('N') !== '1') {
            return false;
        }

        return $this->analytics->database()->meta('email.sent') !== $this->period();
    }

    /**
     * Subject and summary of the report, also used for the preview
     */
    public function preview(): array
    {
        $settings = $this->settings();
        $range    = $this->range($settings['frequency'] === 'monthly' ? 'monthly' : 'weekly');
        $data     = $this->analytics->data();
        $totals   = $data->totals($range, null);
        $previous = $data->totals($range->previous(), null);
        $site     = (string)$this->analytics->kirby()->site()->title()->value();
        $change   = $previous['visitors'] > 0 ? (int)round(($totals['visitors'] / $previous['visitors'] - 1) * 100) : null;

        return [
            'subject'  => I18n::template('owebstudio.analytics.email.subject', null, [
                'site'     => $site,
                'visitors' => number_format($totals['visitors']),
                'change'   => $change === null ? '' : ' (' . ($change >= 0 ? '+' : '') . $change . '%)'
            ]),
            'range'    => $range->toArray(),
            'totals'   => $totals,
            'previous' => $previous,
            'pages'    => array_map(fn ($row) => ['title' => $row['title'], 'visitors' => $row['visitors']], $data->pages($range, null, ['limit' => 5])['rows']),
            'sources'  => array_map(fn ($row) => ['source' => $row['source'] ?: $row['channel'], 'visitors' => $row['visitors']], $data->referrers($range, null, null, ['limit' => 5])['rows'])
        ];
    }

    public function save(array $input): void
    {
        $frequency  = in_array($input['frequency'] ?? null, static::FREQUENCIES, true) ? $input['frequency'] : 'off';
        $allowed    = array_column($this->candidates(), 'value');
        $recipients = array_values(array_intersect((array)($input['recipients'] ?? []), $allowed));

        $this->analytics->database()->setMeta('email', json_encode(['frequency' => $frequency, 'recipients' => $recipients]));
    }

    /**
     * Sends the report. Returns the number of emails sent.
     */
    public function send(bool $force = false, string|null $only = null): int
    {
        $db = $this->analytics->database();

        if ($force === false) {
            // claim the period first, so that parallel requests send it only once
            $claimed = $db->transaction(function () use ($db) {
                if ($this->due() === false) {
                    return false;
                }

                $db->setMeta('email.sent', $this->period());
                return true;
            });

            if ($claimed === false) {
                return 0;
            }
        }

        $kirby    = $this->analytics->kirby();
        $settings = $this->settings();
        $preview  = $this->preview();
        $sent     = 0;

        foreach ($settings['recipients'] as $id) {
            if ($only !== null && $id !== $only) {
                continue;
            }

            $user = $kirby->user($id);

            if ($user instanceof User === false) {
                continue;
            }

            try {
                $kirby->email([
                    'from'    => $this->analytics->option('email.from') ?? 'no-reply@' . ($kirby->url('index') ? parse_url($kirby->url('index'), PHP_URL_HOST) : 'localhost'),
                    'to'      => $user->email(),
                    'subject' => $preview['subject'],
                    'body'    => [
                        'html' => $this->html($preview),
                        'text' => $this->text($preview)
                    ]
                ]);
                $sent++;
            } catch (Throwable $e) {
                Analytics::log($e);
            }
        }

        // the test email always goes to the current user, even if not a recipient
        if ($only !== null && $sent === 0 && ($user = $kirby->user($only)) !== null) {
            $kirby->email([
                'from'    => $this->analytics->option('email.from') ?? 'no-reply@' . parse_url((string)$kirby->url('index'), PHP_URL_HOST),
                'to'      => $user->email(),
                'subject' => $preview['subject'],
                'body'    => ['html' => $this->html($preview), 'text' => $this->text($preview)]
            ]);
            $sent++;
        }

        return $sent;
    }

    /**
     * @return array{frequency: string, recipients: array<int, string>}
     */
    public function settings(): array
    {
        $settings = json_decode((string)$this->analytics->database()->meta('email'), true);

        return [
            'frequency'  => in_array($settings['frequency'] ?? null, static::FREQUENCIES, true) ? $settings['frequency'] : 'off',
            'recipients' => array_values(array_filter((array)($settings['recipients'] ?? []), 'is_string'))
        ];
    }

    protected function html(array $preview): string
    {
        $row = fn (string $label, string $value) => '<tr><td style="padding:4px 0;color:#555">' . Escape::html($label) . '</td><td style="padding:4px 0;text-align:right"><strong>' . Escape::html($value) . '</strong></td></tr>';
        $list = fn (array $items, string $key) => implode('', array_map(fn ($item) => $row((string)$item[$key], number_format($item['visitors'])), $items));
        $t = $preview['totals'];

        return '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:14px;color:#111;max-width:480px">'
            . '<h2 style="font-size:18px">' . Escape::html($preview['subject']) . '</h2>'
            . '<p style="color:#555">' . Escape::html($preview['range']['from'] . ' – ' . $preview['range']['to']) . '</p>'
            . '<table style="width:100%;border-collapse:collapse">'
            . $row(I18n::translate('owebstudio.analytics.visitors'), number_format($t['visitors']))
            . $row(I18n::translate('owebstudio.analytics.views'), number_format($t['views']))
            . $row(I18n::translate('owebstudio.analytics.bounce'), $t['bounce'] === null ? '–' : round($t['bounce'] * 100) . '%')
            . '</table>'
            . '<h3 style="font-size:15px;margin-top:20px">' . Escape::html(I18n::translate('owebstudio.analytics.list.pages')) . '</h3>'
            . '<table style="width:100%;border-collapse:collapse">' . $list($preview['pages'], 'title') . '</table>'
            . '<h3 style="font-size:15px;margin-top:20px">' . Escape::html(I18n::translate('owebstudio.analytics.list.sources')) . '</h3>'
            . '<table style="width:100%;border-collapse:collapse">' . $list($preview['sources'], 'source') . '</table>'
            . '<p style="margin-top:24px;color:#555">' . Escape::html(I18n::translate('owebstudio.analytics.email.footer')) . '</p>'
            . '</div>';
    }

    /**
     * Id of the current report period: ISO week or month
     */
    protected function period(): string
    {
        return $this->settings()['frequency'] === 'monthly' ? date('Y-m') : date('o-\WW');
    }

    /**
     * Last full week (Monday to Sunday) or last full month
     */
    protected function range(string $frequency): Range
    {
        $today = new DateTimeImmutable($this->analytics->today());

        if ($frequency === 'monthly') {
            $first = $today->modify('first day of last month');
            $last  = $today->modify('last day of last month');
        } else {
            $monday = $today->modify('monday this week');
            $first  = $monday->sub(new DateInterval('P7D'));
            $last   = $monday->sub(new DateInterval('P1D'));
        }

        return new Range('custom', $first->format('Y-m-d'), $last->format('Y-m-d'));
    }

    protected function text(array $preview): string
    {
        $t     = $preview['totals'];
        $lines = [
            $preview['subject'],
            $preview['range']['from'] . ' – ' . $preview['range']['to'],
            '',
            I18n::translate('owebstudio.analytics.visitors') . ': ' . number_format($t['visitors']),
            I18n::translate('owebstudio.analytics.views') . ': ' . number_format($t['views']),
            '',
            I18n::translate('owebstudio.analytics.list.pages') . ':'
        ];

        foreach ($preview['pages'] as $page) {
            $lines[] = '- ' . $page['title'] . ': ' . number_format($page['visitors']);
        }

        $lines[] = '';
        $lines[] = I18n::translate('owebstudio.analytics.list.sources') . ':';

        foreach ($preview['sources'] as $source) {
            $lines[] = '- ' . $source['source'] . ': ' . number_format($source['visitors']);
        }

        return implode("\n", $lines);
    }
}
