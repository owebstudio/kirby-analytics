import ChangePreview from "./previews/ChangePreview.vue";
import LabelPreview from "./previews/LabelPreview.vue";
import PagePreview from "./previews/PagePreview.vue";
import SharePreview from "./previews/SharePreview.vue";
import TrendPreview from "./previews/TrendPreview.vue";

/**
 * Table cells, used with `type: "analytics-…"` in `k-table` columns
 */
export default {
  "k-analytics-change-field-preview": ChangePreview,
  "k-analytics-label-field-preview": LabelPreview,
  "k-analytics-page-field-preview": PagePreview,
  "k-analytics-share-field-preview": SharePreview,
  "k-analytics-trend-field-preview": TrendPreview
};
