<template>
  <div class="k-analytics-stack">
    <div class="k-analytics-card k-analytics-live-hero">
      <p class="k-analytics-live-count">
        <b>{{ number(live.now) }}</b>
        <span>{{ $t("owebstudio.analytics.live.now") }}</span>
      </p>
      <p class="k-analytics-live-meta">
        <span class="k-analytics-live-dot" aria-hidden="true" />
        <span>{{ $t("owebstudio.analytics.live.updated", { seconds: seconds }) }}</span>
      </p>
    </div>

    <k-stats :reports="stats" size="small" />

    <k-section :label="$t('owebstudio.analytics.live.minutes')">
      <div class="k-analytics-card">
        <k-analytics-chart :data="minutes" :height="160" kind="bar" metric="visitors" :label="$t('owebstudio.analytics.live.minutes')" />
      </div>
    </k-section>

    <k-grid variant="columns">
      <k-column width="1/2">
        <k-section :label="$t('owebstudio.analytics.live.pages')">
          <k-table
            :columns="pageColumns"
            :empty="$t('owebstudio.analytics.live.none')"
            :index="false"
            :rows="pageRows"
            @cell="$emit('page', $event.row.key)"
          />
        </k-section>
      </k-column>
      <k-column width="1/2">
        <k-section :label="$t('owebstudio.analytics.live.sources')">
          <k-table :columns="sourceColumns" :empty="$t('owebstudio.analytics.live.none')" :index="false" :rows="sourceRows" />
        </k-section>
      </k-column>
    </k-grid>
  </div>
</template>

<script>
import { CHANNEL_ICONS, encode, number } from "../helpers.js";

/**
 * Visitors right now (Pro). Polls every 10 seconds while the tab is visible.
 */
export default {
  props: {
    data: Object
  },
  emits: ["page"],
  data() {
    return {
      live: this.data,
      loaded: Date.now(),
      now: Date.now()
    };
  },
  computed: {
    minutes() {
      return this.live.minutes.map((visitors, index) => {
        const ago = 29 - index;

        return {
          visitors,
          short: ago === 0 ? this.$t("owebstudio.analytics.live.thisMinute") : "-" + ago + "m",
          long: ago === 0 ? this.$t("owebstudio.analytics.live.thisMinute") : this.$t("owebstudio.analytics.live.ago", { minutes: ago })
        };
      });
    },
    pageColumns() {
      return {
        page: { label: this.$t("owebstudio.analytics.column.page"), type: "analytics-page", mobile: true },
        visitors: { label: this.$t("owebstudio.analytics.visitors"), align: "right", width: "1/5", mobile: true }
      };
    },
    pageRows() {
      return this.live.pages.map((row) => ({
        key: encode(row.id),
        page: row,
        visitors: number(row.visitors)
      }));
    },
    seconds() {
      return Math.max(0, Math.round((this.now - this.loaded) / 1000));
    },
    sourceColumns() {
      return {
        source: { label: this.$t("owebstudio.analytics.column.source"), type: "analytics-label", mobile: true },
        visitors: { label: this.$t("owebstudio.analytics.visitors"), align: "right", width: "1/5", mobile: true }
      };
    },
    sourceRows() {
      return this.live.sources.map((row) => ({
        source: row.channel === "direct"
          ? { icon: CHANNEL_ICONS.direct, text: this.$t("owebstudio.analytics.channel.direct") }
          : { letter: row.source.charAt(0), text: row.source, info: this.$t("owebstudio.analytics.channel." + row.channel) },
        visitors: number(row.visitors)
      }));
    },
    stats() {
      const today = this.live.today;
      const top = this.live.pages[0];

      return [
        {
          label: this.$t("owebstudio.analytics.live.today"),
          value: number(today.visitors),
          info: this.$t("owebstudio.analytics.live.until", { time: this.live.time })
        },
        {
          label: this.$t("owebstudio.analytics.live.views"),
          value: number(today.views),
          info: today.visitors ? this.$t("owebstudio.analytics.views.per", { count: number(today.views / today.visitors, 1) }) : null
        },
        {
          label: this.$t("owebstudio.analytics.live.top"),
          value: top ? top.title : "–",
          info: top ? this.$t("owebstudio.analytics.visitors.count", { count: number(top.visitors) }) : null
        }
      ];
    }
  },
  watch: {
    data(value) {
      this.live = value;
      this.loaded = Date.now();
    }
  },
  mounted() {
    this.clock = setInterval(() => {
      this.now = Date.now();

      if (document.visibilityState === "visible" && this.now - this.loaded >= 10000) {
        this.refresh();
      }
    }, 1000);
  },
  beforeUnmount() {
    clearInterval(this.clock);
  },
  methods: {
    number,
    async refresh() {
      this.loaded = Date.now();

      try {
        this.live = await this.$panel.api.get("analytics/live", {}, {}, true);
      } catch {
        // keep the last numbers; the next poll tries again
      }
    }
  }
};
</script>
