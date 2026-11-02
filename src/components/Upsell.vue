<template>
  <div :data-size="size" class="k-analytics-upsell">
    <div class="k-analytics-upsell-preview" aria-hidden="true" inert>
      <k-table :columns="sample.columns" :index="false" :rows="sample.rows" />
    </div>
    <div class="k-analytics-upsell-box">
      <div class="k-analytics-upsell-card">
        <k-icon type="lock" />
        <h3>{{ title }}</h3>
        <p>{{ text }}</p>
        <k-button-group>
          <k-button
            :text="$t('owebstudio.analytics.pro.see')"
            icon="key"
            size="sm"
            theme="love"
            variant="filled"
            @click="$panel.dialog.open('analytics/pro', { query: { feature } })"
          />
        </k-button-group>
      </div>
    </div>
  </div>
</template>

<script>
/**
 * Locked Pro feature in the Free tier: a blurred sample (never real data)
 * with a short explanation. No banners anywhere else.
 */
const SAMPLES = {
  campaigns: [
    ["autumn-newsletter", "newsletter / email", "311", "12"],
    ["product-launch", "mastodon / social", "112", "4"],
    ["instagram-bio", "instagram / social", "73", "2"],
    ["partner-feature", "travelmag / referral", "41", "1"],
    ["spring-sale", "newsletter / email", "28", "3"]
  ],
  countries: [
    ["Germany", "2,813", "48%"],
    ["United States", "1,626", "27%"],
    ["Switzerland", "408", "7%"],
    ["Austria", "299", "5%"],
    ["Netherlands", "240", "4%"]
  ],
  goals: [
    ["Outbound: instagram.com", "126", "2.1%"],
    ["Newsletter signup", "76", "1.3%"],
    ["Form: contact", "57", "1%"],
    ["Download: press-kit.pdf", "23", "0.4%"],
    ["Visited /contact/thanks", "55", "0.9%"]
  ],
  live: [
    ["Exploring the universe", "3"],
    ["Home", "2"],
    ["Photography", "1"],
    ["Notes", "1"]
  ]
};

export default {
  props: {
    feature: String,
    size: {
      type: String,
      default: "card"
    },
    text: String,
    title: String
  },
  computed: {
    sample() {
      const rows = SAMPLES[this.feature] ?? SAMPLES.goals;
      const width = rows[0].length;
      const columns = {};

      for (let i = 0; i < width; i++) {
        columns["c" + i] = { label: i === 0 ? this.title : "", align: i > 0 ? "right" : null, mobile: true };
      }

      return {
        columns,
        rows: rows.map((row) => Object.fromEntries(row.map((value, i) => ["c" + i, value])))
      };
    }
  }
};
</script>

<style>
.k-analytics-upsell {
  position: relative;
  overflow: hidden;
  border-radius: var(--rounded);
  min-height: 17rem;
}
.k-analytics-upsell[data-size="page"] {
  min-height: 26rem;
}
.k-analytics-upsell-preview {
  filter: blur(3px);
  opacity: 0.55;
  pointer-events: none;
  user-select: none;
}
.k-analytics-upsell-box {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  padding: var(--spacing-6);
  background: light-dark(hsla(0, 0%, 94%, 0.35), hsla(0, 0%, 12%, 0.35));
}
.k-analytics-upsell-card {
  display: grid;
  justify-items: center;
  gap: var(--spacing-3);
  max-width: 26rem;
  padding: var(--spacing-6);
  border-radius: var(--rounded-lg);
  background: light-dark(var(--color-white), var(--color-gray-850));
  box-shadow: var(--shadow-xl);
  line-height: var(--leading-normal);
  text-align: center;
}
.k-analytics-upsell-card > .k-icon {
  --icon-size: 24px;
  color: var(--color-text-dimmed);
}
.k-analytics-upsell-card h3 {
  font-size: var(--text-md);
  font-weight: var(--font-semi);
}
.k-analytics-upsell-card p {
  color: var(--color-text-dimmed);
}
</style>
