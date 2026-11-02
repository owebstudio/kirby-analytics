<?php

/**
 * Optional script, about 1 KB, inline: `<?php snippet('analytics/script') ?>`
 * before `</body>`. Needed for statically cached pages (`mode: script`)
 * and for events: outbound links, downloads, forms and `data-analytics`.
 * Sends only the page, the referrer and event names. No cookies.
 *
 * @var \Kirby\Cms\App $kirby
 * @var \Kirby\Cms\Page|null $page
 * @var string|null $nonce Content Security Policy nonce
 */

use Kirby\Cms\App;
use Kirby\Filesystem\F;
use Oweb\Analytics\Analytics;

$analytics = Analytics::instance();
$kirby   ??= App::instance();
$page    ??= $kirby->site()->page();

if ($page === null) {
    return;
}

$endpoint = trim((string)$analytics->option('endpoint', '_analytics'), '/');

?>
<script<?= attr([
    'data-endpoint'  => rtrim((string)$kirby->url('index'), '/') . '/' . $endpoint,
    'data-page'      => Analytics::modelId($page),
    'data-lang'      => $kirby->multilang() === true ? $kirby->language()?->code() : null,
    'data-mode'      => $analytics->mode(),
    'data-downloads' => implode(',', (array)$analytics->option('downloads', ['pdf', 'zip', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'csv', 'txt', 'rtf', 'epub', 'mp3', 'mp4', 'mov', 'dmg', 'exe', 'pkg', 'gz', 'rar', '7z', 'ics', 'vcf'])),
    'nonce'          => $nonce ?? null
], ' ') ?>><?= F::read(dirname(__DIR__) . '/assets/script.js') ?></script>
