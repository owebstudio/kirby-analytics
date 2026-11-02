<template>
  <div class="k-analytics-empty">
    <k-icon type="dashboard" />
    <p class="k-analytics-empty-title">{{ $t("owebstudio.analytics.empty") }}</p>

    <div class="k-analytics-steps">
      <section>
        <h3>1. {{ $t("owebstudio.analytics.empty.server") }}</h3>
        <p>{{ $t("owebstudio.analytics.empty.server.text") }}</p>
      </section>
      <section>
        <h3>2. {{ $t("owebstudio.analytics.empty.test") }}</h3>
        <p>{{ $t("owebstudio.analytics.empty.test.text") }}</p>
      </section>
      <section>
        <h3>3. {{ $t("owebstudio.analytics.empty.script") }}</h3>
        <p>{{ $t("owebstudio.analytics.empty.script.text") }}</p>
        <k-code language="php">{{ script }}</k-code>
      </section>
    </div>

    <k-button
      :text="$t('owebstudio.analytics.status')"
      dialog="analytics/status"
      icon="info"
      size="sm"
      variant="filled"
    />
  </div>
</template>

<script>
export default {
  computed: {
    script() {
      return `<?php snippet('analytics/script') ?>`;
    }
  }
};
</script>
