<template>
  <k-dialog
    :size="size"
    :submit-button="{ icon: 'check', text: $t('save'), theme: 'positive' }"
    :visible="visible"
    class="k-analytics-dialog k-analytics-email-dialog"
    @cancel="$panel.dialog.close()"
    @submit="submit(false)"
  >
    <k-label type="section">{{ $t("owebstudio.analytics.email") }}</k-label>

    <div class="k-analytics-email-grid">
      <div>
        <k-fieldset :fields="fields" :value="form" @input="form = { ...form, ...$event }" />
        <k-button
          :disabled="!form.recipients.length"
          :text="$t('owebstudio.analytics.email.test')"
          class="k-analytics-email-test"
          icon="email"
          size="sm"
          variant="filled"
          @click="submit(true)"
        />
      </div>

      <div>
        <k-label type="section">{{ $t("owebstudio.analytics.email.preview") }}</k-label>
        <div class="k-analytics-mail">
          <p class="k-analytics-mail-muted">{{ preview.subject }}</p>
          <p class="k-analytics-mail-muted">{{ period }}</p>
          <table><tbody>
            <tr><td>{{ $t("owebstudio.analytics.visitors") }}</td><td>{{ number(preview.totals.visitors) }}</td></tr>
            <tr><td>{{ $t("owebstudio.analytics.views") }}</td><td>{{ number(preview.totals.views) }}</td></tr>
            <tr><td>{{ $t("owebstudio.analytics.bounce") }}</td><td>{{ percent(preview.totals.bounce) }}</td></tr>
          </tbody></table>
          <h4>{{ $t("owebstudio.analytics.list.pages") }}</h4>
          <table><tbody>
            <tr v-for="page in preview.pages" :key="page.title"><td>{{ page.title }}</td><td>{{ number(page.visitors) }}</td></tr>
          </tbody></table>
          <h4>{{ $t("owebstudio.analytics.list.sources") }}</h4>
          <table><tbody>
            <tr v-for="source in preview.sources" :key="source.source"><td>{{ source.source }}</td><td>{{ number(source.visitors) }}</td></tr>
          </tbody></table>
        </div>
      </div>
    </div>

    <p class="k-help">{{ $t("owebstudio.analytics.email.help") }}</p>
  </k-dialog>
</template>

<script>
import { date, number, percent } from "../helpers.js";

/**
 * Settings and preview of the email report (Pro)
 */
export default {
  inheritAttrs: false,
  props: {
    preview: Object,
    size: {
      type: String,
      default: "large"
    },
    users: {
      type: Array,
      default: () => []
    },
    value: Object,
    visible: Boolean
  },
  emits: ["submit"],
  data() {
    return {
      form: {
        frequency: this.value?.frequency ?? "off",
        recipients: this.value?.recipients ?? []
      }
    };
  },
  computed: {
    fields() {
      return {
        frequency: {
          label: this.$t("owebstudio.analytics.email.frequency"),
          type: "toggles",
          grow: false,
          labels: true,
          options: [
            { value: "weekly", text: this.$t("owebstudio.analytics.email.weekly") },
            { value: "monthly", text: this.$t("owebstudio.analytics.email.monthly") },
            { value: "off", text: this.$t("owebstudio.analytics.email.off") }
          ],
          help: this.$t("owebstudio.analytics.email.when")
        },
        recipients: {
          label: this.$t("owebstudio.analytics.email.recipients"),
          type: "checkboxes",
          options: this.users,
          help: this.$t("owebstudio.analytics.email.recipients.help")
        }
      };
    },
    period() {
      return date(this.preview.range.from) + " – " + date(this.preview.range.to);
    }
  },
  methods: {
    number,
    percent,
    async submit(test) {
      if (!test) {
        return this.$emit("submit", { ...this.form, test: false });
      }

      // saves and sends a test email to the current user, the dialog stays open
      const response = await this.$panel.post(this.$panel.dialog.path, { ...this.form, test: true });
      this.$panel.notification.success(response?.message ?? this.$t("owebstudio.analytics.email.test.done"));
    }
  }
};
</script>
