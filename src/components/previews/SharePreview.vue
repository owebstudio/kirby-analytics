<template>
  <p class="k-analytics-share-field-preview">
    <template v-if="value !== null && value !== undefined && value !== ''">
      <span class="k-analytics-bar">
        <span :style="{ width: Math.min(100, value * 100) + '%' }" />
      </span>
      <span class="k-analytics-share-value">{{ text }}</span>
    </template>
    <span v-else class="k-analytics-dimmed">–</span>
  </p>
</template>

<script>
import { percent } from "../../helpers.js";

export default {
  props: {
    column: Object,
    row: Object,
    value: [Number, String]
  },
  computed: {
    text() {
      return percent(this.value);
    }
  }
};
</script>
