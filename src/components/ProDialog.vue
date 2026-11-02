<template>
  <k-dialog
    :cancel-button="false"
    :size="size"
    :submit-button="false"
    :visible="visible"
    class="k-analytics-dialog k-analytics-pro-dialog"
    @cancel="$panel.dialog.close()"
  >
    <k-label type="section">Analytics Pro</k-label>
    <p class="k-analytics-dimmed">{{ $t("owebstudio.analytics.pro.intro") }}</p>

    <ul class="k-analytics-pro-list">
      <li v-for="item in features" :key="item.id">
        <k-icon :type="item.icon" />
        <strong>{{ $t("owebstudio.analytics.pro." + item.id) }}</strong>
        <span>{{ $t("owebstudio.analytics.pro." + item.id + ".text") }}</span>
      </li>
    </ul>

    <k-box v-if="first" :text="$t('owebstudio.analytics.pro.stored', { date: since })" icon="info" theme="info" />

    <p class="k-help">{{ $t("owebstudio.analytics.pro.trial") }}</p>

    <template #footer>
      <k-dialog-footer>
        <k-button-group class="k-dialog-buttons">
          <k-button
            :text="$t('owebstudio.analytics.pro.key')"
            icon="key"
            variant="filled"
            @click="activateKey"
          />
          <k-button
            :link="buy"
            :text="$t('owebstudio.analytics.pro.buy')"
            icon="cart"
            target="_blank"
            theme="love"
            variant="filled"
          />
        </k-button-group>
      </k-dialog-footer>
    </template>
  </k-dialog>
</template>

<script>
import { date } from "../helpers.js";

const FEATURES = [
  { id: "goals", icon: "check" },
  { id: "campaigns", icon: "megaphone" },
  { id: "countries", icon: "globe" },
  { id: "live", icon: "live" },
  { id: "compare", icon: "layers" },
  { id: "history", icon: "calendar" },
  { id: "email", icon: "email" },
  { id: "export", icon: "file-spreadsheet" }
];

export default {
  inheritAttrs: false,
  props: {
    activate: String,
    buy: String,
    feature: String,
    first: String,
    size: {
      type: String,
      default: "large"
    },
    visible: Boolean
  },
  computed: {
    features() {
      const first = FEATURES.find((item) => item.id === this.feature);
      return first ? [first, ...FEATURES.filter((item) => item !== first)] : FEATURES;
    },
    since() {
      return date(this.first);
    }
  },
  methods: {
    activateKey() {
      // admins get the license dialog; everyone else sees the license in the System view
      if (this.activate) {
        return this.$panel.dialog.open(this.activate);
      }

      this.$panel.dialog.close();
      this.$panel.view.open("system");
    }
  }
};
</script>
