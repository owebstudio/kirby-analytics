<template>
  <k-drawer
    :breadcrumb="breadcrumb"
    :current="current"
    :icon="icon"
    :options="options"
    :title="title"
    :visible="visible"
    class="k-analytics-drawer"
    size="large"
    @cancel="$panel.drawer.close()"
    @crumb="$emit('crumb', $event)"
    @submit="$panel.drawer.close()"
  >
    <div class="k-analytics-stack">
      <p class="k-analytics-dimmed k-analytics-drawer-range">{{ period }}</p>

      <k-stats :reports="stats" size="small" />

      <k-section v-if="chart.length > 1" :label="$t(range.days > 90 ? 'owebstudio.analytics.chart.visitors.week' : 'owebstudio.analytics.chart.visitors.day')">
        <div class="k-analytics-card">
          <div v-if="compare && prevChart" class="k-analytics-card-header">
            <p class="k-analytics-legend">
              <span><i class="k-analytics-key" />{{ $t("owebstudio.analytics.chart.current") }}</span>
              <span><i class="k-analytics-key" data-previous="true" />{{ $t("owebstudio.analytics.chart.previous") }}</span>
            </p>
          </div>
          <k-analytics-chart :data="chart" :height="170" :label="title" :previous="compare ? prevChart : null" metric="visitors" />
        </div>
      </k-section>

      <k-section :label="$t('owebstudio.analytics.drawer.landings')">
        <k-table
          :columns="landingColumns"
          :empty="$t('owebstudio.analytics.drawer.landings.none')"
          :index="false"
          :rows="landingRows"
        />
      </k-section>

      <k-section v-if="tier === 'pro' && goals.length" :label="$t('owebstudio.analytics.drawer.goals')">
        <k-table :columns="goalColumns" :index="false" :rows="goalRows" />
      </k-section>

      <div v-else-if="tier !== 'pro'" class="k-analytics-card k-analytics-upsell-inline">
        <k-icon type="lock" />
        <span>{{ $t("owebstudio.analytics.drawer.pro") }}</span>
        <k-button
          :text="$t('owebstudio.analytics.pro.see')"
          size="xs"
          theme="love"
          variant="filled"
          @click="$panel.dialog.open('analytics/pro', { query: { feature: 'goals' } })"
        />
      </div>

      <k-section v-if="searches && searches.length" :label="$t('owebstudio.analytics.drawer.searches')">
        <k-table :columns="searchColumns" :index="false" :rows="searchRows" />
      </k-section>
    </div>
  </k-drawer>
</template>

<script>
import { CHANNEL_ICONS, GOAL_ICONS, change, date, duration, number, percent } from "../helpers.js";

export default {
  inheritAttrs: false,
  props: {
    bounce: Number,
    breadcrumb: Array,
    chart: {
      type: Array,
      default: () => []
    },
    compare: Boolean,
    current: Boolean,
    entries: Number,
    exitRate: Number,
    exits: Number,
    goals: {
      type: Array,
      default: () => []
    },
    icon: String,
    landings: {
      type: Array,
      default: () => []
    },
    page: Object,
    prevChart: Array,
    previous: Number,
    range: Object,
    searches: Array,
    share: Number,
    tier: String,
    time: Number,
    title: String,
    views: Number,
    visible: Boolean,
    visitors: Number
  },
  emits: ["crumb"],
  computed: {
    goalColumns() {
      return {
        goal: { label: this.$t("owebstudio.analytics.column.goal"), type: "analytics-label", mobile: true },
        visitors: { label: this.$t("owebstudio.analytics.visitors"), align: "right", width: "1/6", mobile: true },
        cr: { label: this.$t("owebstudio.analytics.column.conversion"), align: "right", width: "1/6", mobile: true }
      };
    },
    goalRows() {
      return this.goals.map((goal) => ({
        goal: { icon: GOAL_ICONS[goal.type] ?? "bolt", text: goal.name },
        visitors: number(goal.visitors),
        cr: percent(goal.cr, 1)
      }));
    },
    landingColumns() {
      return {
        source: { label: this.$t("owebstudio.analytics.column.source"), type: "analytics-label", mobile: true },
        visits: { label: this.$t("owebstudio.analytics.column.visits"), align: "right", width: "1/6", mobile: true },
        share: { label: this.$t("owebstudio.analytics.column.share"), type: "analytics-share", width: "1/4" }
      };
    },
    landingRows() {
      const total = this.landings.reduce((sum, row) => sum + row.visits, 0);

      return this.landings.map((row) => ({
        source: this.sourceValue(row),
        visits: number(row.visits),
        share: total ? row.visits / total : null
      }));
    },
    options() {
      return [
        ...(this.page?.link ? [{ icon: "edit-line", title: this.$t("owebstudio.analytics.drawer.openPanel"), link: this.page.link }] : []),
        ...(this.page?.url ? [{ icon: "open", title: this.$t("owebstudio.analytics.drawer.openPage"), link: this.page.url, target: "_blank" }] : [])
      ];
    },
    period() {
      return this.range.from === this.range.to ? date(this.range.from) : date(this.range.from) + " – " + date(this.range.to);
    },
    searchColumns() {
      return {
        term: { label: this.$t("owebstudio.analytics.column.term"), type: "analytics-label", mobile: true },
        clicks: { label: this.$t("owebstudio.analytics.column.clicks"), align: "right", width: "1/6", mobile: true }
      };
    },
    searchRows() {
      return (this.searches ?? []).map((row) => ({
        term: { icon: "search", text: row.term },
        clicks: number(row.clicks)
      }));
    },
    stats() {
      const versus = change(this.visitors, this.previous);

      return [
        {
          label: this.$t("owebstudio.analytics.visitors"),
          value: number(this.visitors),
          info: versus ? versus + " " + this.$t("owebstudio.analytics.previous") : null
        },
        {
          label: this.$t("owebstudio.analytics.views"),
          value: number(this.views),
          info: this.visitors ? this.$t("owebstudio.analytics.views.per", { count: number(this.views / this.visitors, 2) }) : null
        },
        {
          label: this.$t("owebstudio.analytics.time"),
          value: duration(this.time),
          info: this.bounce !== null && this.bounce !== undefined ? this.$t("owebstudio.analytics.bounce.info", { rate: percent(this.bounce) }) : null
        },
        {
          label: this.$t("owebstudio.analytics.entries"),
          value: number(this.entries),
          info: this.share !== null && this.share !== undefined ? this.$t("owebstudio.analytics.entries.info", { share: percent(this.share, 1) }) : null
        }
      ];
    }
  },
  methods: {
    sourceValue(row) {
      if (row.channel === "direct") {
        return { icon: CHANNEL_ICONS.direct, text: this.$t("owebstudio.analytics.channel.direct") };
      }

      return { letter: row.source.charAt(0), text: row.source, info: this.$t("owebstudio.analytics.channel." + row.channel) };
    }
  }
};
</script>
