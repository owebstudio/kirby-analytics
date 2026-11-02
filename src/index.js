import "./styles.css";
import Chart from "./components/Chart.vue";
import Empty from "./components/Empty.vue";
import PageDrawer from "./components/PageDrawer.vue";
import Previews from "./components/Previews.js";
import ProDialog from "./components/ProDialog.vue";
import StatusDialog from "./components/StatusDialog.vue";
import Upsell from "./components/Upsell.vue";
import View from "./components/View.vue";
import EmailDialog from "./pro/EmailDialog.vue";
import GoalsTab from "./pro/GoalsTab.vue";
import LiveTab from "./pro/LiveTab.vue";

panel.plugin("owebstudio/analytics", {
  components: {
    ...Previews,
    "k-analytics-chart": Chart,
    "k-analytics-email-dialog": EmailDialog,
    "k-analytics-empty": Empty,
    "k-analytics-goals-tab": GoalsTab,
    "k-analytics-live-tab": LiveTab,
    "k-analytics-page-drawer": PageDrawer,
    "k-analytics-pro-dialog": ProDialog,
    "k-analytics-status-dialog": StatusDialog,
    "k-analytics-upsell": Upsell,
    "k-analytics-view": View
  }
});
