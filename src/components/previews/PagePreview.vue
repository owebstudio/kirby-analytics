<template>
  <p :data-exists="value.exists" class="k-analytics-page-field-preview">
    <k-icon :type="value.icon ?? 'page'" />
    <span class="k-analytics-page-title">{{ value.title }}</span>
    <span v-if="value.path && value.path !== value.title" class="k-analytics-page-path">{{ value.path }}</span>
  </p>
</template>

<script>
export default {
  props: {
    column: Object,
    row: Object,
    value: Object
  }
};
</script>
