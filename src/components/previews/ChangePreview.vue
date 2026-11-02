<template>
  <p class="k-analytics-change-field-preview">
    <k-tag v-if="isNew" :text="$t('owebstudio.analytics.new')" element="span" theme="positive" />
    <span v-else>{{ text }}</span>
  </p>
</template>

<script>
import { change } from "../../helpers.js";

export default {
  props: {
    column: Object,
    row: Object,
    value: Object
  },
  computed: {
    isNew() {
      const { current, previous } = this.value ?? {};
      return current > 0 && (!previous || previous < current * 0.03);
    },
    text() {
      return change(this.value?.current ?? 0, this.value?.previous) ?? "–";
    }
  }
};
</script>
