<template>
  <k-dialog
    :cancel-button="false"
    :size="size"
    :submit-button="{ icon: 'check', text: $t('close') }"
    :visible="visible"
    class="k-analytics-dialog"
    @cancel="$panel.dialog.close()"
    @submit="$panel.dialog.close()"
  >
    <k-label type="section">{{ $t("owebstudio.analytics.status") }}</k-label>

    <dl class="k-analytics-status">
      <template v-for="row in rows" :key="row.label">
        <dt>{{ row.label }}</dt>
        <dd :data-ok="row.ok">
          <k-icon :type="row.ok ? 'check' : 'cancel-small'" />
          <span>{{ row.text }}</span>
          <k-button
            v-if="row.button"
            v-bind="row.button"
            class="k-analytics-status-button"
            size="xs"
            variant="filled"
          />
        </dd>
      </template>
    </dl>

    <p class="k-help">{{ $t("owebstudio.analytics.status.privacy") }}</p>
  </k-dialog>
</template>

<script>
import { date, number } from "../helpers.js";

export default {
  inheritAttrs: false,
  props: {
    canManage: Boolean,
    size: {
      type: String,
      default: "large"
    },
    status: Object,
    visible: Boolean
  },
  computed: {
    rows() {
      const status = this.status;
      const t = (key, data) => this.$t("owebstudio.analytics.status." + key, data);
      const pro = status.tier === "pro";
      const last = status.lastSeen ? new Date(status.lastSeen * 1000).toLocaleString(window.panel.translation.code ?? "en") : status.last ? date(status.last) : t("never");

      return [
        {
          label: t("counting"),
          text: t("mode." + status.mode) + " · " + t("last", { date: last }),
          ok: Boolean(status.last)
        },
        {
          label: t("visitors"),
          text: t("visitors.text", { timezone: status.timezone }),
          ok: true
        },
        {
          label: t("storage"),
          text: "SQLite · " + status.database + " · " + this.$helper.file.niceSize(status.size) + (status.first ? " · " + t("since", { date: date(status.first) }) : ""),
          ok: status.driver
        },
        {
          label: t("retention"),
          text: status.retention ? t("retention.days", { days: number(status.retention) }) : t("retention.keep"),
          ok: true
        },
        {
          label: t("dnt"),
          text: t(status.respectDnt ? "dnt.on" : "dnt.off"),
          ok: status.respectDnt
        },
        {
          label: t("consent"),
          text: t(status.consent ? "consent.on" : "consent.off"),
          ok: status.consent
        },
        {
          label: t("panelUsers"),
          text: t(status.panelUsers ? "panelUsers.on" : "panelUsers.off"),
          ok: !status.panelUsers
        },
        {
          label: t("bots"),
          text: t("bots.text") + (status.ignored ? " · " + t("ignored", { count: status.ignored }) : ""),
          ok: true
        },
        {
          label: t("countries"),
          text: status.geo
            ? [status.geo.dbip ? "DB-IP Lite" : status.geo.type || status.geo.file, status.geo.built ? date(status.geo.built) : null].filter(Boolean).join(" · ")
            : t(pro ? "countries.missing" : "countries.pro"),
          ok: Boolean(status.geo),
          button: pro && this.canManage
            ? { text: this.$t(status.geo ? "owebstudio.analytics.geo.update" : "owebstudio.analytics.geo.download"), icon: "download", dialog: "analytics/geo" }
            : null
        },
        {
          label: t("license"),
          text: pro ? "Analytics Pro" : "Analytics Free",
          ok: pro,
          button: pro ? null : { text: this.$t("owebstudio.analytics.pro.see"), icon: "key", theme: "love", click: () => this.$panel.dialog.open("analytics/pro") }
        }
      ];
    }
  }
};
</script>
