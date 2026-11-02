<template>
  <p class="k-analytics-trend-field-preview">
    <svg viewBox="0 0 100 18" preserveAspectRatio="none" aria-hidden="true">
      <polyline :points="points" vector-effect="non-scaling-stroke" />
    </svg>
  </p>
</template>

<script>
export default {
  props: {
    column: Object,
    row: Object,
    value: Array
  },
  computed: {
    points() {
      const values = this.value ?? [];
      const max = Math.max(1, ...values);
      const last = Math.max(1, values.length - 1);

      return values.map((value, index) => `${((index / last) * 100).toFixed(1)},${(17 - (value / max) * 15).toFixed(1)}`).join(" ");
    }
  }
};
</script>
