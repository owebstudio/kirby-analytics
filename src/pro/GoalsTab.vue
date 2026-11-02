<template>
  <div class="k-analytics-stack">
    <k-stats :reports="stats" size="medium" />

    <k-section :buttons="buttons" :label="$t('owebstudio.analytics.tab.goals')">
      <k-table
        :columns="columns"
        :empty="$t('owebstudio.analytics.goals.none')"
        :index="false"
        :rows="rows"
      >
        <template #options="{ row }">
          <k-options-dropdown :options="row.options" />
        </template>
      </k-table>
    </k-section>

    <k-box :text="help" icon="info" theme="info" />
  </div>
</template>

<script>
import { GOAL_ICONS, number, percent } from "../helpers.js";

/**
 * Goals and events with conversion rates (Pro)
 */
export default {
  props: {
    canManage: Boolean,
    compare: Boolean,
    data: Object,
    summary: Object
  },
  computed: {
    buttons() {
      return [
        {
          icon: "add",
          text: this.$t("owebstudio.analytics.goal.add"),
          dialog: "analytics/goal",
          disabled: !this.canManage
        }
      ];
    },
    columns() {
      return {
        goal: { label: this.$t("owebstudio.analytics.column.goal"), type: "analytics-label", mobile: true },
        type: { label: this.$t("owebstudio.analytics.column.type"), width: "1/6" },
        visitors: { label: this.$t("owebstudio.analytics.visitors"), align: "right", width: "1/10", mobile: true },
        events: { label: this.$t("owebstudio.analytics.column.events"), align: "right", width: "1/10" },
        cr: { label: this.$t("owebstudio.analytics.column.conversion"), align: "right", width: "1/8", mobile: true },
        ...(this.compare ? { change: { label: this.$t("owebstudio.analytics.column.change"), type: "analytics-change", align: "right", width: "1/10" } } : {})
      };
    },
    help() {
      return this.$t("owebstudio.analytics.goals.help");
    },
    rows() {
      return this.data.rows.map((goal) => ({
        goal: { icon: GOAL_ICONS[goal.type] ?? "bolt", text: goal.name, info: goal.rule },
        type: this.$t("owebstudio.analytics.goal.type." + goal.type),
        visitors: number(goal.visitors),
        events: number(goal.events),
        cr: percent(goal.cr, 1),
        change: { current: goal.visitors, previous: goal.previous },
        options: [
          ...(goal.type === "page"
            ? [{ icon: "trash", text: this.$t("delete"), dialog: `analytics/goal/${goal.id}/delete`, disabled: !this.canManage }]
            : [{ icon: "copy", text: this.$t("owebstudio.analytics.goal.copy"), click: () => this.copy(goal.name) }])
        ]
      }));
    },
    stats() {
      // visitors who reached at least one goal, each counted once
      const total = this.summary.conversions ?? 0;
      const best = this.data.campaigns?.[0];

      return [
        {
          icon: "check",
          label: this.$t("owebstudio.analytics.goals.conversions"),
          value: number(total),
          info: this.$t("owebstudio.analytics.goals.conversions.info")
        },
        {
          icon: "chart",
          label: this.$t("owebstudio.analytics.column.conversion"),
          value: this.summary.visitors ? percent(total / this.summary.visitors, 1) : "–",
          info: this.$t("owebstudio.analytics.goals.rate.info", { count: number(this.summary.visitors) })
        },
        {
          icon: "megaphone",
          label: this.$t("owebstudio.analytics.goals.campaign"),
          value: best && best.conversions > 0 ? best.campaign || best.source : "–",
          info: best && best.conversions > 0 ? this.$t("owebstudio.analytics.goals.campaign.info", { count: number(best.conversions) }) : this.$t("owebstudio.analytics.goals.campaign.none")
        }
      ];
    }
  },
  methods: {
    async copy(text) {
      await this.$helper.clipboard.write(`data-analytics="${text}"`);
      this.$panel.notification.success(this.$t("owebstudio.analytics.goal.copied"));
    }
  }
};
</script>
