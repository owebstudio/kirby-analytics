<template>
  <p class="k-analytics-label-field-preview">
    <span v-if="value.letter" class="k-analytics-letter" aria-hidden="true">{{ value.letter }}</span>
    <k-icon v-else-if="value.icon" :type="value.icon" />
    <k-tag v-if="value.tag" :text="value.tag" element="span" />
    <span class="k-analytics-label-text" :data-dimmed="value.dimmed">{{ value.text }}</span>
    <span v-if="value.info" class="k-analytics-label-info">{{ value.info }}</span>
  </p>
</template>

<script>
/**
 * A cell with an icon (or a letter tile for hosts), a tag, a text and a dimmed info
 */
export default {
  props: {
    column: Object,
    row: Object,
    value: Object
  }
};
</script>
