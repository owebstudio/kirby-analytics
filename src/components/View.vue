<template>
  <k-panel-inside class="k-analytics-view">
    <k-header>
      {{ $t("owebstudio.analytics.title") }}

      <template #buttons>
        <k-button-group>
          <k-button
            v-if="isPro && live !== null && !empty"
            :link="url({ tab: 'live' })"
            :text="$t('owebstudio.analytics.live.count', { count: format(live) })"
            class="k-analytics-live-button"
            icon="live"
            size="sm"
            theme="positive"
            variant="dimmed"
          />
          <k-view-button :options="rangeOptions" :text="rangeLabel" icon="calendar" />
          <k-view-button
            v-if="languages.length > 1"
            :options="langOptions"
            :text="langLabel"
            icon="translate"
          />
          <k-button
            :current="compare"
            :disabled="empty || (isPro && !previous)"
            :icon="isPro ? 'layers' : 'lock'"
            :text="$t('owebstudio.analytics.compare')"
            :theme="compare ? 'info' : null"
            size="sm"
            variant="filled"
            @click="toggleCompare"
          />
          <k-view-button
            v-if="isPro"
            :disabled="!data"
            :options="exportOptions"
            :text="$t('owebstudio.analytics.export')"
            icon="download"
          />
          <k-button
            v-else
            :disabled="empty"
            :text="$t('owebstudio.analytics.export')"
            icon="lock"
            size="sm"
            variant="filled"
            @click="openPro('export')"
          />
          <k-view-button :options="moreOptions" :title="$t('more')" icon="dots" />
        </k-button-group>
      </template>
    </k-header>

    <k-tabs :tab="tab" :tabs="tabs" theme="positive" />

    <k-box v-if="error" :text="error" icon="alert" theme="negative" />

    <k-analytics-empty v-else-if="empty" />

    <template v-else>
      <k-box v-if="spike" :text="spikeText" class="k-analytics-intro" icon="bolt" theme="notice" />

      <!-- Overview -->
      <div v-if="tab === 'overview'" class="k-analytics-stack">
        <dl class="k-stats k-analytics-stats" data-size="large">
          <k-stat
            v-for="report in stats"
            :key="report.metric"
            v-bind="report"
            :data-selected="metric === report.metric"
          />
        </dl>

        <k-section :label="chartLabel">
          <template #options>
            <k-button-group layout="collapsed" class="k-analytics-switch">
              <k-button
                :current="chartMode === 'chart'"
                :title="$t('owebstudio.analytics.chart')"
                icon="chart"
                size="xs"
                variant="filled"
                @click="setChartMode('chart')"
              />
              <k-button
                :current="chartMode === 'table'"
                :title="$t('owebstudio.analytics.chart.table')"
                icon="table"
                size="xs"
                variant="filled"
                @click="setChartMode('table')"
              />
            </k-button-group>
          </template>

          <div class="k-analytics-card">
            <header class="k-analytics-card-header">
              <p v-if="showPrevious" class="k-analytics-legend">
                <span><i class="k-analytics-key" />{{ rangeLabel }} · {{ metricValue(summary) }}</span>
                <span><i class="k-analytics-key" data-previous="true" />{{ $t("owebstudio.analytics.chart.previous") }} · {{ metricValue(previous) }}</span>
              </p>
              <p class="k-analytics-dimmed">{{ period }}</p>
            </header>

            <k-analytics-chart
              v-if="chartMode === 'chart'"
              :data="data.chart"
              :label="chartLabel"
              :metric="chartMetric"
              :previous="showPrevious ? data.prevChart : null"
            />
            <k-table
              v-else
              :columns="chartColumns"
              :index="false"
              :rows="chartRows"
              class="k-analytics-chart-table"
            />
          </div>
        </k-section>

        <k-grid variant="columns">
          <k-column width="1/2">
            <k-section :buttons="[showAll('pages')]" :label="$t('owebstudio.analytics.list.pages')">
              <k-table
                :columns="breakdownColumns($t('owebstudio.analytics.column.page'), 'analytics-page')"
                :empty="$t('owebstudio.analytics.nothing')"
                :index="false"
                :rows="topPageRows"
                class="k-analytics-clickable"
                @cell="openPage($event.row.key)"
              />
            </k-section>
          </k-column>
          <k-column width="1/2">
            <k-section :label="$t('owebstudio.analytics.list.sources')">
              <template #options>
                <k-button-group layout="collapsed" class="k-analytics-switch">
                  <k-button
                    v-for="item in sourceModes"
                    :key="item.id"
                    :current="cards.sources === item.id"
                    :icon="item.pro && !isPro ? 'lock' : null"
                    :text="item.text"
                    size="xs"
                    variant="filled"
                    @click="setCard('sources', item.id)"
                  />
                </k-button-group>
              </template>

              <k-analytics-upsell
                v-if="cards.sources === 'campaigns' && !isPro"
                :text="$t('owebstudio.analytics.upsell.campaigns.text')"
                :title="$t('owebstudio.analytics.upsell.campaigns', { count: format(data.campaignCount) })"
                feature="campaigns"
              />
              <k-table
                v-else
                :columns="breakdownColumns(sourceLabel, 'analytics-label')"
                :empty="$t('owebstudio.analytics.nothing')"
                :index="false"
                :rows="sourceRows"
              />
            </k-section>
          </k-column>
        </k-grid>

        <k-grid variant="columns">
          <k-column width="1/2">
            <k-section :label="$t('owebstudio.analytics.list.devices')">
              <template #options>
                <k-button-group layout="collapsed" class="k-analytics-switch">
                  <k-button
                    v-for="item in deviceModes"
                    :key="item.id"
                    :current="cards.devices === item.id"
                    :text="item.text"
                    size="xs"
                    variant="filled"
                    @click="setCard('devices', item.id)"
                  />
                </k-button-group>
              </template>
              <k-table
                :columns="breakdownColumns(deviceLabel, 'analytics-label')"
                :empty="$t('owebstudio.analytics.nothing')"
                :index="false"
                :rows="deviceRows"
              />
            </k-section>
          </k-column>
          <k-column width="1/2">
            <k-section :label="$t('owebstudio.analytics.list.locations')">
              <template #options>
                <k-button-group layout="collapsed" class="k-analytics-switch">
                  <k-button
                    v-for="item in locationModes"
                    :key="item.id"
                    :current="cards.locations === item.id"
                    :icon="item.pro && !isPro ? 'lock' : null"
                    :text="item.text"
                    size="xs"
                    variant="filled"
                    @click="setCard('locations', item.id)"
                  />
                </k-button-group>
              </template>

              <k-analytics-upsell
                v-if="cards.locations === 'countries' && !isPro"
                :text="$t('owebstudio.analytics.upsell.countries.text')"
                :title="$t('owebstudio.analytics.upsell.countries')"
                feature="countries"
              />
              <template v-else>
                <k-table
                  :columns="breakdownColumns(locationLabel, 'analytics-label')"
                  :empty="cards.locations === 'countries' ? $t('owebstudio.analytics.countries.none') : $t('owebstudio.analytics.nothing')"
                  :index="false"
                  :rows="locationRows"
                />
                <p v-if="cards.locations === 'countries' && data.countries.length" class="k-analytics-attribution">
                  <a href="https://db-ip.com" rel="noopener" target="_blank">{{ $t("owebstudio.analytics.countries.attribution") }}</a>
                </p>
              </template>
            </k-section>
          </k-column>
        </k-grid>

        <k-section v-if="isPro" :buttons="[showAll('goals')]" :label="$t('owebstudio.analytics.tab.goals')">
          <k-table
            :columns="goalColumns"
            :empty="$t('owebstudio.analytics.goals.none')"
            :index="false"
            :rows="goalRows"
          />
        </k-section>
        <k-section v-else :label="$t('owebstudio.analytics.tab.goals')">
          <div class="k-analytics-card k-analytics-upsell-inline">
            <k-icon type="lock" />
            <span>{{ $t("owebstudio.analytics.upsell.goals.short") }}</span>
            <k-button :text="$t('owebstudio.analytics.pro.see')" size="xs" theme="love" variant="filled" @click="openPro('goals')" />
          </div>
        </k-section>

        <p class="k-help k-analytics-footnote">{{ $t("owebstudio.analytics.visitors.help") }}</p>
      </div>

      <!-- Pages -->
      <div v-else-if="tab === 'pages'" class="k-analytics-list">
        <div class="k-analytics-toolbar">
          <k-input
            :placeholder="$t('owebstudio.analytics.filter.pages')"
            :value="filterInput"
            icon="filter"
            type="search"
            @input="onFilter"
          />
          <k-button-group layout="collapsed" class="k-analytics-switch">
            <k-button
              v-for="item in pageModes"
              :key="item.id"
              :current="pageMode === item.id"
              :text="item.text"
              size="sm"
              variant="filled"
              @click="go({ mode: item.id === 'top' ? null : item.id, sort: null, dir: null, page: null })"
            />
          </k-button-group>
          <p class="k-analytics-dimmed">{{ $t("owebstudio.analytics.count.pages", { count: format(data.pagination.total), views: format(summary.views) }) }}</p>
        </div>

        <k-table
          :columns="pageColumns"
          :empty="filter ? $t('owebstudio.analytics.nothing.filter') : $t('owebstudio.analytics.nothing')"
          :index="(data.pagination.page - 1) * data.pagination.limit + 1"
          :pagination="data.pagination.total > data.pagination.limit ? { ...data.pagination, details: true } : false"
          :rows="pageRows"
          class="k-analytics-clickable"
          @cell="openPage($event.row.key)"
          @header="onHeader"
          @paginate="go({ page: $event.page })"
        >
          <template #header="{ column, label }">
            <span :data-sortable="Boolean(column.sort)" class="k-analytics-th">
              {{ label }}
              <k-icon v-if="column.sort && sortKey === column.sort" :type="sortDir === 'asc' ? 'angle-up' : 'angle-down'" />
            </span>
          </template>
          <template #options="{ row }">
            <k-options-dropdown :options="pageOptions(row)" />
          </template>
        </k-table>
      </div>

      <!-- Sources -->
      <div v-else-if="tab === 'sources'" class="k-analytics-list">
        <div class="k-analytics-toolbar">
          <k-input
            v-if="sourceMode !== 'campaigns' || isPro"
            :placeholder="$t(sourceMode === 'campaigns' ? 'owebstudio.analytics.filter.campaigns' : 'owebstudio.analytics.filter.sources')"
            :value="filterInput"
            icon="filter"
            type="search"
            @input="onFilter"
          />
          <k-button-group layout="collapsed" class="k-analytics-switch">
            <k-button
              v-for="item in sourceModes"
              :key="item.id"
              :current="sourceMode === item.id"
              :icon="item.pro && !isPro ? 'lock' : null"
              :text="item.text"
              size="sm"
              variant="filled"
              @click="go({ mode: item.id === 'channels' ? null : item.id, channel: null, q: null, sort: null, dir: null, page: null })"
            />
          </k-button-group>
          <k-button
            v-if="channel && sourceMode === 'referrers'"
            :text="$t('owebstudio.analytics.channel') + ': ' + $t('owebstudio.analytics.channel.' + channel)"
            icon="cancel-small"
            size="sm"
            theme="info"
            variant="filled"
            @click="go({ channel: null, page: null })"
          />
          <p v-if="data.pagination || data.rows" class="k-analytics-dimmed">{{ sourcesInfo }}</p>
        </div>

        <k-analytics-upsell
          v-if="sourceMode === 'campaigns' && !isPro"
          :text="$t('owebstudio.analytics.upsell.campaigns.text')"
          :title="$t('owebstudio.analytics.upsell.campaigns', { count: format(data.count) })"
          feature="campaigns"
          size="page"
        />
        <k-table
          v-else
          :columns="sourceTableColumns"
          :empty="filter ? $t('owebstudio.analytics.nothing.filter') : $t('owebstudio.analytics.nothing')"
          :index="false"
          :pagination="data.pagination && data.pagination.total > data.pagination.limit ? { ...data.pagination, details: true } : false"
          :rows="sourceTableRows"
          :class="{ 'k-analytics-clickable': sourceMode === 'channels' }"
          @cell="onSourceCell"
          @header="onHeader"
          @paginate="go({ page: $event.page })"
        >
          <template #header="{ column, label }">
            <span :data-sortable="Boolean(column.sort)" class="k-analytics-th">
              {{ label }}
              <k-icon v-if="column.sort && sortKey === column.sort" :type="sortDir === 'asc' ? 'angle-up' : 'angle-down'" />
            </span>
          </template>
        </k-table>
      </div>

      <!-- Goals (Pro) -->
      <template v-else-if="tab === 'goals'">
        <k-analytics-goals-tab v-if="isPro" :can-manage="canManage" :compare="compare" :data="data" :summary="summary" />
        <k-analytics-upsell
          v-else
          :text="$t('owebstudio.analytics.upsell.goals.text')"
          :title="$t('owebstudio.analytics.upsell.goals')"
          feature="goals"
          size="page"
        />
      </template>

      <!-- Live (Pro) -->
      <template v-else-if="tab === 'live'">
        <k-analytics-live-tab v-if="isPro" :data="data" @page="openPage" />
        <k-analytics-upsell
          v-else
          :text="$t('owebstudio.analytics.upsell.live.text')"
          :title="$t('owebstudio.analytics.upsell.live')"
          feature="live"
          size="page"
        />
      </template>
    </template>
  </k-panel-inside>
</template>

<script>
import {
  CHANNEL_ICONS,
  DEVICE_ICONS,
  GOAL_ICONS,
  change,
  date,
  duration,
  metric as formatMetric,
  number,
  percent,
  query,
  rangeQuery,
  store
} from "../helpers.js";

const RANGES = ["today", "yesterday", "7d", "30d"];
const PRO_RANGES = ["90d", "12m", "all"];

export default {
  props: {
    canManage: Boolean,
    channel: String,
    compare: Boolean,
    data: Object,
    dir: String,
    empty: Boolean,
    error: String,
    filter: String,
    first: String,
    lang: String,
    languages: {
      type: Array,
      default: () => []
    },
    live: Number,
    mode: String,
    previous: Object,
    range: Object,
    sort: String,
    spike: Object,
    summary: Object,
    tab: String,
    tier: String,
    today: String
  },
  data() {
    return {
      cards: {
        sources: store.get("sources") ?? "channels",
        devices: store.get("devices") ?? "devices",
        locations: store.get("locations") ?? "languages"
      },
      chartMode: store.get("chart") ?? "chart",
      filterInput: this.filter ?? "",
      metric: store.get("metric") ?? "visitors"
    };
  },
  computed: {
    chartColumns() {
      return {
        date: { label: this.$t(this.range.hourly ? "owebstudio.analytics.column.hour" : "owebstudio.analytics.column.date"), mobile: true },
        visitors: { label: this.$t("owebstudio.analytics.visitors"), align: "right", mobile: true },
        views: { label: this.$t("owebstudio.analytics.views"), align: "right" },
        bounce: { label: this.$t("owebstudio.analytics.bounce"), align: "right" },
        duration: { label: this.$t("owebstudio.analytics.duration"), align: "right" },
        ...(this.showPrevious ? { previous: { label: this.$t("owebstudio.analytics.chart.previous"), align: "right" } } : {})
      };
    },
    chartMetric() {
      // bounce rate and visit duration are daily numbers
      return this.range.hourly && ["bounce", "duration"].includes(this.metric) ? "visitors" : this.metric;
    },
    chartLabel() {
      const per = this.range.hourly ? "hour" : this.range.days > 90 ? "week" : "day";
      return this.$t("owebstudio.analytics.chart." + this.chartMetric + "." + per);
    },
    chartRows() {
      const previous = this.data.prevChart ?? [];

      return this.data.chart
        .map((item, index) => ({ item, previous: previous[index] }))
        .filter(({ item }) => item.visitors !== null)
        .reverse()
        .map(({ item, previous }) => ({
          date: this.bucketLabel(item),
          visitors: number(item.visitors),
          views: number(item.views),
          bounce: percent(item.bounce),
          duration: duration(item.duration),
          previous: previous ? formatMetric(this.chartMetric, previous[this.chartMetric]) : "–"
        }));
    },
    current() {
      return {
        tab: this.tab === "overview" ? null : this.tab,
        ...rangeQuery(this.range),
        lang: this.lang,
        compare: this.compare ? "true" : null,
        mode: this.mode,
        channel: this.channel,
        q: this.filter,
        sort: this.sort,
        dir: this.sort ? this.dir : null,
        page: this.data?.pagination?.page > 1 ? this.data.pagination.page : null
      };
    },
    deviceLabel() {
      return this.deviceModes.find((item) => item.id === this.cards.devices)?.text;
    },
    deviceModes() {
      return [
        { id: "devices", text: this.$t("owebstudio.analytics.devices.type") },
        { id: "browsers", text: this.$t("owebstudio.analytics.devices.browser") },
        { id: "systems", text: this.$t("owebstudio.analytics.devices.os") }
      ];
    },
    deviceRows() {
      const list = this.data[this.cards.devices] ?? [];

      return this.shareRows(list, (row) =>
        this.cards.devices === "devices"
          ? { icon: DEVICE_ICONS[row.value] ?? "display", text: this.$t("owebstudio.analytics.device." + row.value) }
          : { text: row.value }
      );
    },
    drawerQuery() {
      return query({ ...rangeQuery(this.range), lang: this.lang, compare: this.compare ? "true" : null });
    },
    exportOptions() {
      return ["pages", "sources", "campaigns", "goals", "devices", "days"].map((kind) => ({
        icon: "file-spreadsheet",
        text: this.$t("owebstudio.analytics.export." + kind) + " (CSV)",
        click: () => this.exportCsv(kind)
      }));
    },
    goalColumns() {
      return {
        goal: { label: this.$t("owebstudio.analytics.column.goal"), type: "analytics-label", mobile: true },
        visitors: { label: this.$t("owebstudio.analytics.visitors"), align: "right", width: "1/6", mobile: true },
        events: { label: this.$t("owebstudio.analytics.column.events"), align: "right", width: "1/6" },
        cr: { label: this.$t("owebstudio.analytics.column.conversion"), align: "right", width: "1/6", mobile: true }
      };
    },
    goalRows() {
      return (this.data.goals ?? []).map((goal) => ({
        goal: { icon: GOAL_ICONS[goal.type] ?? "bolt", text: goal.name },
        visitors: number(goal.visitors),
        events: number(goal.events),
        cr: percent(goal.cr, 1)
      }));
    },
    isPro() {
      return this.tier === "pro";
    },
    langLabel() {
      return this.languages.find((language) => language.code === this.lang)?.name ?? this.$t("owebstudio.analytics.languages.all");
    },
    langOptions() {
      return [
        {
          text: this.$t("owebstudio.analytics.languages.all"),
          current: !this.lang,
          click: () => this.go({ lang: null, page: null })
        },
        "-",
        ...this.languages.map((language) => ({
          text: language.name,
          current: this.lang === language.code,
          click: () => this.go({ lang: language.code, page: null })
        }))
      ];
    },
    locationLabel() {
      return this.locationModes.find((item) => item.id === this.cards.locations)?.text;
    },
    locationModes() {
      return [
        { id: "languages", text: this.$t("owebstudio.analytics.locations.languages") },
        { id: "countries", text: this.$t("owebstudio.analytics.locations.countries"), pro: true }
      ];
    },
    locationRows() {
      const list = this.cards.locations === "countries" ? this.data.countries : this.data.languages;

      return this.shareRows(list ?? [], (row) => {
        if (!row.value) {
          return { icon: "globe", text: this.$t("owebstudio.analytics.unknown"), dimmed: true };
        }

        return { tag: row.value.toUpperCase(), text: this.displayName(row.value, this.cards.locations === "countries" ? "region" : "language") };
      });
    },
    moreOptions() {
      return [
        {
          icon: "info",
          text: this.$t("owebstudio.analytics.status"),
          dialog: "analytics/status"
        },
        this.isPro
          ? { icon: "email", text: this.$t("owebstudio.analytics.email") + " …", dialog: "analytics/email", disabled: !this.canManage }
          : { icon: "lock", text: this.$t("owebstudio.analytics.email") + " …", click: () => this.openPro("email") },
        ...(this.isPro ? [] : [{ icon: "key", text: "Analytics Pro …", click: () => this.openPro() }]),
        "-",
        {
          icon: "trash",
          text: this.$t("owebstudio.analytics.delete"),
          dialog: "analytics/delete",
          disabled: !this.canManage || this.empty
        }
      ];
    },
    pageColumns() {
      const sortable = (key) => ({ sort: key });
      const change = this.compare ? { change: { label: this.$t("owebstudio.analytics.column.change"), type: "analytics-change", align: "right", width: "1/12" } } : {};

      if (this.pageMode === "entry") {
        return {
          page: { label: this.$t("owebstudio.analytics.column.landing"), type: "analytics-page", mobile: true, ...sortable("title") },
          entries: { label: this.$t("owebstudio.analytics.entries"), align: "right", width: "1/10", mobile: true, ...sortable("entries") },
          share: { label: this.$t("owebstudio.analytics.column.visitShare"), type: "analytics-share", width: "1/6" },
          bounce: { label: this.$t("owebstudio.analytics.bounce"), align: "right", width: "1/10", ...sortable("bounce") },
          ...change
        };
      }

      if (this.pageMode === "exit") {
        return {
          page: { label: this.$t("owebstudio.analytics.column.exit"), type: "analytics-page", mobile: true, ...sortable("title") },
          exits: { label: this.$t("owebstudio.analytics.exits"), align: "right", width: "1/10", mobile: true, ...sortable("exits") },
          exitRate: { label: this.$t("owebstudio.analytics.column.exitRate"), align: "right", width: "1/8", ...sortable("exitRate") },
          ...change
        };
      }

      return {
        page: { label: this.$t("owebstudio.analytics.column.page"), type: "analytics-page", mobile: true, ...sortable("title") },
        visitors: { label: this.$t("owebstudio.analytics.visitors"), align: "right", width: "1/10", mobile: true, ...sortable("visitors") },
        views: { label: this.$t("owebstudio.analytics.views"), align: "right", width: "1/10", ...sortable("views") },
        bounce: { label: this.$t("owebstudio.analytics.bounce"), align: "right", width: "1/10", ...sortable("bounce") },
        time: { label: this.$t("owebstudio.analytics.time"), align: "right", width: "1/8", ...sortable("time") },
        trend: { label: this.$t("owebstudio.analytics.column.trend"), type: "analytics-trend", width: "1/10" },
        ...change
      };
    },
    pageMode() {
      return ["entry", "exit"].includes(this.mode) ? this.mode : "top";
    },
    pageModes() {
      return [
        { id: "top", text: this.$t("owebstudio.analytics.pages.top") },
        { id: "entry", text: this.$t("owebstudio.analytics.pages.entry") },
        { id: "exit", text: this.$t("owebstudio.analytics.pages.exit") }
      ];
    },
    pageRows() {
      const visits = this.summary?.visits ?? 0;

      return (this.data?.rows ?? []).map((row) => ({
        key: row.key,
        page: row,
        visitors: number(row.visitors),
        views: number(row.views),
        entries: number(row.entries),
        exits: number(row.exits),
        bounce: percent(row.bounce),
        time: duration(row.time),
        exitRate: percent(row.exitRate),
        share: visits ? row.entries / visits : null,
        trend: row.trend,
        change: {
          current: this.pageMode === "entry" ? row.entries : this.pageMode === "exit" ? row.exits : row.visitors,
          previous: row.previous ? (this.pageMode === "entry" ? row.previous.entries : this.pageMode === "exit" ? row.previous.exits : row.previous.visitors) : null
        },
        source: row
      }));
    },
    period() {
      if (this.range.from === this.range.to) {
        return date(this.range.from, { weekday: "short", day: "numeric", month: "short", year: "numeric" });
      }

      return date(this.range.from) + " – " + date(this.range.to);
    },
    rangeLabel() {
      if (this.range.id === "custom") {
        return this.period;
      }

      return this.$t("owebstudio.analytics.range." + this.range.id);
    },
    rangeOptions() {
      const pro = PRO_RANGES.map((id) =>
        this.isPro
          ? { text: this.$t("owebstudio.analytics.range." + id), current: this.range.id === id, click: () => this.go({ range: id, from: null, to: null, page: null }) }
          : { text: this.$t("owebstudio.analytics.range." + id), icon: "lock", click: () => this.openPro("history") }
      );

      return [
        ...RANGES.map((id) => ({
          text: this.$t("owebstudio.analytics.range." + id),
          current: this.range.id === id,
          click: () => this.go({ range: id, from: null, to: null, page: null })
        })),
        "-",
        ...pro,
        {
          icon: this.isPro ? "calendar" : "lock",
          text: this.$t("owebstudio.analytics.range.custom"),
          current: this.range.id === "custom",
          click: () => (this.isPro ? this.customRange() : this.openPro("history"))
        }
      ];
    },
    showPrevious() {
      return this.isPro && this.compare && Boolean(this.data?.prevChart) && Boolean(this.previous);
    },
    sortDir() {
      return this.sort ? this.dir : "desc";
    },
    sortKey() {
      if (this.sort) {
        return this.sort;
      }

      if (this.tab === "pages") {
        return { entry: "entries", exit: "exits" }[this.pageMode] ?? "visitors";
      }

      return "visitors";
    },
    sourceLabel() {
      return this.sourceModes.find((item) => item.id === this.cards.sources)?.text;
    },
    sourceMode() {
      return ["referrers", "campaigns"].includes(this.mode) ? this.mode : "channels";
    },
    sourceModes() {
      return [
        { id: "channels", text: this.$t("owebstudio.analytics.sources.channels") },
        { id: "referrers", text: this.$t("owebstudio.analytics.sources.referrers") },
        { id: "campaigns", text: this.$t("owebstudio.analytics.sources.campaigns"), pro: true }
      ];
    },
    sourceRows() {
      if (this.cards.sources === "campaigns") {
        return this.shareRows(this.data.campaigns ?? [], (row) => ({ icon: "megaphone", text: row.campaign || row.source, info: row.source + " / " + row.medium }));
      }

      if (this.cards.sources === "referrers") {
        return this.shareRows(this.data.referrers ?? [], (row) => this.sourceValue(row));
      }

      return this.shareRows(this.data.channels ?? [], (row) => ({ icon: CHANNEL_ICONS[row.channel], text: this.$t("owebstudio.analytics.channel." + row.channel) }));
    },
    sourcesInfo() {
      if (this.sourceMode === "channels") {
        return this.$t("owebstudio.analytics.count.visitors", { count: number(this.summary.visitors) });
      }

      return this.$t(this.sourceMode === "campaigns" ? "owebstudio.analytics.count.campaigns" : "owebstudio.analytics.count.sources", {
        count: number(this.data.pagination?.total ?? 0)
      });
    },
    sourceTableColumns() {
      const sortable = (key) => ({ sort: key });
      const change = this.compare ? { change: { label: this.$t("owebstudio.analytics.column.change"), type: "analytics-change", align: "right", width: "1/12" } } : {};

      if (this.sourceMode === "campaigns") {
        return {
          campaign: { label: this.$t("owebstudio.analytics.column.campaign"), type: "analytics-label", mobile: true, ...sortable("campaign") },
          medium: { label: this.$t("owebstudio.analytics.column.sourceMedium"), width: "1/5" },
          visitors: { label: this.$t("owebstudio.analytics.visitors"), align: "right", width: "1/10", mobile: true, ...sortable("visitors") },
          bounce: { label: this.$t("owebstudio.analytics.bounce"), align: "right", width: "1/10", ...sortable("bounce") },
          conversions: { label: this.$t("owebstudio.analytics.column.conversions"), align: "right", width: "1/10", mobile: true, ...sortable("conversions") },
          rate: { label: this.$t("owebstudio.analytics.column.conversion"), align: "right", width: "1/10", ...sortable("rate") },
          ...change
        };
      }

      return {
        label: { label: this.$t(this.sourceMode === "channels" ? "owebstudio.analytics.column.channel" : "owebstudio.analytics.column.source"), type: "analytics-label", mobile: true, ...(this.sourceMode === "referrers" ? sortable("source") : {}) },
        visitors: { label: this.$t("owebstudio.analytics.visitors"), align: "right", width: "1/10", mobile: true, ...sortable("visitors") },
        share: { label: this.$t("owebstudio.analytics.column.share"), type: "analytics-share", width: "1/5" },
        bounce: { label: this.$t("owebstudio.analytics.bounce"), align: "right", width: "1/10", ...sortable("bounce") },
        ...change
      };
    },
    sourceTableRows() {
      const total = this.summary?.visitors ?? 0;

      return (this.data?.rows ?? []).map((row) => ({
        channel: row.channel,
        label: this.sourceMode === "channels" ? { icon: CHANNEL_ICONS[row.channel], text: this.$t("owebstudio.analytics.channel." + row.channel) } : this.sourceValue(row),
        campaign: { icon: "megaphone", text: row.campaign || "–" },
        medium: [row.source, row.medium].filter(Boolean).join(" / "),
        visitors: number(row.visitors),
        share: total ? Math.min(1, row.visitors / total) : null,
        bounce: percent(row.bounce),
        conversions: number(row.conversions),
        rate: percent(row.rate, 1),
        change: { current: row.visitors, previous: row.previous }
      }));
    },
    spikeText() {
      return this.$t("owebstudio.analytics.spike", { factor: number(this.spike.factor, 1), today: number(this.spike.today), usual: number(this.spike.usual) });
    },
    stats() {
      const totals = this.summary;
      const previous = this.previous;
      const versus = (current, prev, lowerIsBetter = false) => {
        const text = change(current, prev);

        if (!text) {
          return { info: null, theme: null };
        }

        const value = current / prev - 1;
        const good = lowerIsBetter ? value < -0.05 : value > 0.05;
        const bad = lowerIsBetter ? value > 0.05 : value < -0.05;

        return { info: text + " " + this.$t("owebstudio.analytics.previous"), theme: good ? "positive" : bad ? "negative" : null };
      };
      const points = () => {
        if (!previous || previous.bounce === null || totals.bounce === null) {
          return { info: null, theme: null };
        }

        const value = (totals.bounce - previous.bounce) * 100;

        return {
          info: (value >= 0 ? "↑ " : "↓ ") + this.$t("owebstudio.analytics.points", { change: number(Math.abs(value), 1) }) + " " + this.$t("owebstudio.analytics.previous"),
          theme: value < -1 ? "positive" : value > 1 ? "negative" : null
        };
      };

      return [
        { metric: "visitors", icon: "users", label: this.$t("owebstudio.analytics.visitors"), value: number(totals.visitors), ...versus(totals.visitors, previous?.visitors) },
        { metric: "views", icon: "preview", label: this.$t("owebstudio.analytics.views"), value: number(totals.views), ...versus(totals.views, previous?.views) },
        { metric: "bounce", icon: "logout", label: this.$t("owebstudio.analytics.bounce"), value: percent(totals.bounce), ...points() },
        { metric: "duration", icon: "clock", label: this.$t("owebstudio.analytics.duration"), value: duration(totals.duration), ...versus(totals.duration ?? 0, previous?.duration) }
      ].map((report) => ({ ...report, click: () => this.setMetric(report.metric) }));
    },
    tabs() {
      return ["overview", "pages", "sources", "goals", "live"].map((name) => {
        const pro = ["goals", "live"].includes(name);

        return {
          name,
          label: this.$t("owebstudio.analytics.tab." + name),
          link: this.url({ tab: name === "overview" ? null : name, mode: null, channel: null }),
          icon: pro && !this.isPro ? "lock" : null,
          badge: name === "live" && this.isPro && this.live ? { text: number(this.live), theme: "positive" } : null
        };
      });
    },
    topPageRows() {
      const total = this.summary?.visitors ?? 0;

      return (this.data.pages ?? []).map((row) => ({
        key: row.key,
        label: row,
        visitors: number(row.visitors),
        share: total ? Math.min(1, row.visitors / total) : null
      }));
    }
  },
  watch: {
    filter(value) {
      if (document.activeElement?.closest(".k-analytics-toolbar") === null) {
        this.filterInput = value ?? "";
      }
    }
  },
  beforeUnmount() {
    clearTimeout(this.filterTimeout);
  },
  methods: {
    breakdownColumns(label, type) {
      return {
        label: { label, type, mobile: true },
        visitors: { label: this.$t("owebstudio.analytics.visitors"), align: "right", width: "1/5", mobile: true },
        share: { label: this.$t("owebstudio.analytics.column.share"), type: "analytics-share", width: "1/4" }
      };
    },
    bucketLabel(item) {
      if (item.hour !== undefined) {
        return String(item.hour).padStart(2, "0") + ":00";
      }

      if (item.from === item.to) {
        return date(item.from, { weekday: "short", day: "numeric", month: "short", year: "numeric" });
      }

      return this.$t("owebstudio.analytics.chart.weekOf", { date: date(item.from) });
    },
    customRange() {
      this.$panel.dialog.open({
        component: "k-form-dialog",
        props: {
          fields: {
            from: { label: this.$t("owebstudio.analytics.range.from"), type: "date", time: false, required: true, min: this.first, max: this.today, width: "1/2" },
            to: { label: this.$t("owebstudio.analytics.range.to"), type: "date", time: false, required: true, min: this.first, max: this.today, width: "1/2" }
          },
          value: { from: this.range.from, to: this.range.to },
          submitButton: { icon: "check", text: this.$t("owebstudio.analytics.apply") }
        },
        on: {
          submit: (value) => {
            this.$panel.dialog.close();
            this.go({ range: "custom", from: String(value.from ?? "").slice(0, 10), to: String(value.to ?? "").slice(0, 10), page: null });
          }
        }
      });
    },
    displayName(code, type) {
      try {
        return new Intl.DisplayNames([window.panel.translation.code ?? "en"], { type }).of(type === "region" ? code.toUpperCase() : code) ?? code;
      } catch {
        return code;
      }
    },
    async exportCsv(kind) {
      const response = await this.$panel.api.get("analytics/export", {
        kind,
        ...query({ ...rangeQuery(this.range), lang: this.lang })
      });

      const blob = new Blob(["﻿" + response.csv], { type: "text/csv;charset=utf-8" });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");

      link.href = url;
      link.download = response.filename;
      document.body.append(link);
      link.click();
      link.remove();

      setTimeout(() => URL.revokeObjectURL(url), 1000);
    },
    format(value) {
      return number(value);
    },
    go(changes) {
      this.$panel.view.open("analytics", {
        query: query({ ...this.current, ...changes })
      });
    },
    metricValue(totals) {
      return totals ? formatMetric(this.chartMetric, totals[this.chartMetric]) : "–";
    },
    onFilter(value) {
      this.filterInput = value ?? "";
      clearTimeout(this.filterTimeout);
      this.filterTimeout = setTimeout(() => this.go({ q: this.filterInput, page: null }), 300);
    },
    onHeader({ column }) {
      if (!column.sort) {
        return;
      }

      const ascending = ["title", "source", "campaign", "bounce"].includes(column.sort);
      const dir = this.sortKey === column.sort ? (this.sortDir === "asc" ? "desc" : "asc") : ascending ? "asc" : "desc";

      this.go({ sort: column.sort, dir, page: null });
    },
    onSourceCell({ row }) {
      if (this.sourceMode !== "channels") {
        return;
      }

      if (row.channel === "direct") {
        return this.$panel.notification.info(this.$t("owebstudio.analytics.channel.direct.info"));
      }

      this.go({ mode: "referrers", channel: row.channel, q: null, sort: null, dir: null, page: null });
    },
    openPage(key) {
      if (key) {
        this.$panel.drawer.open("analytics/page/" + key, { query: this.drawerQuery });
      }
    },
    openPro(feature = null) {
      this.$panel.dialog.open("analytics/pro", { query: query({ feature }) });
    },
    pageOptions(row) {
      const page = row.source;

      return [
        { icon: "dashboard", text: this.$t("owebstudio.analytics.details"), click: () => this.openPage(row.key) },
        { icon: "edit-line", text: this.$t("owebstudio.analytics.drawer.openPanel"), link: page.link, disabled: !page.link },
        { icon: "open", text: this.$t("owebstudio.analytics.drawer.openPage"), link: page.url, target: "_blank", disabled: !page.url }
      ];
    },
    setCard(card, mode) {
      this.cards[card] = mode;
      store.set(card, mode);
    },
    setChartMode(mode) {
      this.chartMode = mode;
      store.set("chart", mode);
    },
    setMetric(metric) {
      this.metric = metric;
      store.set("metric", metric);
    },
    shareRows(list, label) {
      const total = this.summary?.visitors ?? 0;

      return [...list]
        .sort((a, b) => b.visitors - a.visitors)
        .slice(0, 8)
        .map((row) => ({
          label: label(row),
          visitors: number(row.visitors),
          share: total ? Math.min(1, row.visitors / total) : null
        }));
    },
    showAll(tab) {
      return {
        icon: "angle-right",
        text: this.$t("owebstudio.analytics.list.showAll"),
        link: this.url({ tab, mode: null, channel: null })
      };
    },
    sourceValue(row) {
      if (row.channel === "direct") {
        return { icon: CHANNEL_ICONS.direct, text: this.$t("owebstudio.analytics.channel.direct") };
      }

      return { letter: row.source.charAt(0), text: row.source, info: this.channel ? null : this.$t("owebstudio.analytics.channel." + row.channel) };
    },
    toggleCompare() {
      if (!this.isPro) {
        return this.openPro("compare");
      }

      this.go({ compare: this.compare ? null : "true" });
    },
    url(changes) {
      const params = new URLSearchParams(query({ ...this.current, q: null, sort: null, dir: null, page: null, ...changes }));
      const string = params.toString();

      return "/analytics" + (string ? "?" + string : "");
    }
  }
};
</script>
