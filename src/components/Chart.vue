<template>
  <div
    ref="chart"
    :style="{ height: height + 'px' }"
    class="k-analytics-chart"
    @mouseleave="active = null"
  >
    <svg v-if="width" :width="width" :height="height" :aria-label="label" role="img">
      <g class="k-analytics-chart-grid">
        <template v-for="tick in ticks" :key="tick">
          <line :x1="margin.left" :x2="width - margin.right" :y1="Math.round(y(tick)) + 0.5" :y2="Math.round(y(tick)) + 0.5" />
          <text :x="margin.left - 8" :y="y(tick) + 4" text-anchor="end">{{ format(tick) }}</text>
        </template>
      </g>

      <g v-if="kind === 'bar'">
        <template v-for="(bar, index) in bars" :key="index">
          <path v-if="bar" :d="bar" class="k-analytics-chart-bar" />
        </template>
      </g>
      <g v-else>
        <path v-if="previousPath" :d="previousPath" class="k-analytics-chart-previous" />
        <path v-if="areaPath" :d="areaPath" class="k-analytics-chart-area" />
        <path v-if="linePath" :d="linePath" class="k-analytics-chart-line" />
        <path v-if="partialPath" :d="partialPath" class="k-analytics-chart-line" data-partial="true" />
        <circle v-if="points.length === 1" :cx="points[0][0]" :cy="points[0][1]" r="4" class="k-analytics-chart-dot" />
      </g>

      <g class="k-analytics-chart-grid">
        <template v-for="(item, index) in data" :key="index">
          <text
            v-if="(data.length - 1 - index) % labelEvery === 0"
            :x="x(index)"
            :y="height - 6"
            :text-anchor="anchor(index)"
          >
            {{ short(item) }}
          </text>
        </template>
      </g>

      <g v-if="active !== null && kind !== 'bar'">
        <line :x1="x(active)" :x2="x(active)" :y1="margin.top" :y2="margin.top + plotHeight" class="k-analytics-chart-cross" />
        <circle v-if="hasValue(previousValues[active])" :cx="x(active)" :cy="y(previousValues[active])" r="4" class="k-analytics-chart-dot" data-previous="true" />
        <circle v-if="hasValue(values[active])" :cx="x(active)" :cy="y(values[active])" r="4" class="k-analytics-chart-dot" />
      </g>

      <g>
        <rect
          v-for="(item, index) in data"
          :key="index"
          :x="hitX(index)"
          :y="margin.top"
          :width="hitWidth"
          :height="plotHeight"
          :data-active="active === index"
          :data-bar="kind === 'bar'"
          class="k-analytics-chart-hit"
          @mouseenter="active = index"
        />
      </g>
    </svg>

    <div v-if="tooltip" :style="tooltip.style" class="k-analytics-tooltip">
      <strong>{{ tooltip.title }}</strong>
      <div>
        <span><i class="k-analytics-key" />{{ metricLabel }}</span>
        <span>{{ format(values[active]) }}</span>
      </div>
      <div v-if="previous && previous[active]">
        <span><i class="k-analytics-key" data-previous="true" />{{ long(previous[active]) }}</span>
        <span>{{ format(previousValues[active]) }}</span>
      </div>
    </div>
  </div>
</template>

<script>
import { date, metric as formatMetric } from "../helpers.js";

/**
 * Line chart of a metric per hour, day or week, with the previous period
 * as a dashed line. `kind: bar` draws bars (visitors per minute).
 */
export default {
  props: {
    data: {
      type: Array,
      default: () => []
    },
    height: {
      type: Number,
      default: 240
    },
    kind: {
      type: String,
      default: "line"
    },
    label: String,
    metric: {
      type: String,
      default: "visitors"
    },
    previous: {
      type: Array,
      default: null
    }
  },
  data() {
    return {
      active: null,
      width: 0
    };
  },
  computed: {
    areaPath() {
      const points = this.points;

      if (points.length < 2) {
        return null;
      }

      const base = this.margin.top + this.plotHeight;

      return this.path(points) + `L${points.at(-1)[0]},${base}L${points[0][0]},${base}Z`;
    },
    bars() {
      const base = this.margin.top + this.plotHeight;
      const width = Math.min(24, Math.max(2, this.slot - 2));

      return this.values.map((value, index) => {
        const height = ((value ?? 0) / this.scale.max) * this.plotHeight;

        if (height <= 0) {
          return null;
        }

        const x = this.x(index) - width / 2;
        const r = Math.max(0, Math.min(4, width / 2, height));
        const y = base - height;

        return `M${x},${base}V${y + r}Q${x},${y} ${x + r},${y}H${x + width - r}Q${x + width},${y} ${x + width},${y + r}V${base}Z`;
      });
    },
    hitWidth() {
      return this.kind === "bar" || this.data.length < 2 ? this.slot : this.slot;
    },
    labelEvery() {
      return Math.max(1, Math.ceil(this.data.length / Math.max(2, Math.floor(this.plotWidth / 70))));
    },
    linePath() {
      const points = this.solidPoints;
      return points.length > 1 ? this.path(points) : null;
    },
    margin() {
      return { top: 12, right: 8, bottom: 24, left: this.metric === "duration" ? 56 : 44 };
    },
    metricLabel() {
      return this.$t("owebstudio.analytics." + this.metric);
    },
    partialPath() {
      const points = this.points;
      return this.isPartial && points.length > 1 ? this.path(points.slice(-2)) : null;
    },
    isPartial() {
      const last = this.data[this.points.length - 1];
      return Boolean(last?.partial);
    },
    plotHeight() {
      return this.height - this.margin.top - this.margin.bottom;
    },
    plotWidth() {
      return this.width - this.margin.left - this.margin.right;
    },
    points() {
      const points = [];

      this.values.forEach((value, index) => {
        if (this.hasValue(value)) {
          points.push([this.x(index), this.y(value)]);
        }
      });

      return points;
    },
    previousPath() {
      if (!this.previous) {
        return null;
      }

      const points = [];

      this.previousValues.forEach((value, index) => {
        if (index < this.data.length && this.hasValue(value)) {
          points.push([this.x(index), this.y(value)]);
        }
      });

      return points.length > 1 ? this.path(points) : null;
    },
    previousValues() {
      return (this.previous ?? []).map((item) => item?.[this.metric] ?? null);
    },
    scale() {
      const max = Math.max(0, ...this.values.filter(this.hasValue), ...this.previousValues.filter(this.hasValue));

      if (this.metric === "bounce") {
        const scale = this.nice(max * 100);
        return { step: scale.step / 100, max: Math.min(1, scale.max / 100) || 1 };
      }

      if (this.metric === "duration") {
        const step = [15, 30, 60, 120, 300, 600].find((value) => max / value <= 5) ?? 1200;
        return { step, max: step * Math.max(1, Math.ceil(max / step)) };
      }

      return this.nice(max);
    },
    slot() {
      return this.kind === "bar" || this.data.length < 2
        ? this.plotWidth / Math.max(1, this.data.length)
        : this.plotWidth / (this.data.length - 1);
    },
    solidPoints() {
      return this.isPartial ? this.points.slice(0, -1) : this.points;
    },
    ticks() {
      const ticks = [];

      for (let tick = 0; tick <= this.scale.max + 1e-9; tick += this.scale.step) {
        ticks.push(tick);
      }

      return ticks;
    },
    tooltip() {
      const item = this.data[this.active];

      if (!item) {
        return null;
      }

      const value = this.values[this.active];

      return {
        title: this.long(item),
        style: {
          left: Math.min(Math.max(this.x(this.active), 90), this.width - 90) + "px",
          top: Math.max(this.hasValue(value) ? this.y(value) : this.margin.top + this.plotHeight / 2, 48) + "px"
        }
      };
    },
    values() {
      return this.data.map((item) => item[this.metric] ?? null);
    }
  },
  mounted() {
    this.observer = new ResizeObserver(([entry]) => {
      this.width = Math.max(240, Math.floor(entry.contentRect.width));
    });
    this.observer.observe(this.$refs.chart);
  },
  beforeUnmount() {
    this.observer?.disconnect();
  },
  methods: {
    anchor(index) {
      if (this.kind === "bar" || this.data.length < 2) {
        return "middle";
      }

      return index === 0 ? "start" : index === this.data.length - 1 ? "end" : "middle";
    },
    format(value) {
      return formatMetric(this.metric, value);
    },
    hasValue(value) {
      return value !== null && value !== undefined;
    },
    hitX(index) {
      if (this.kind === "bar" || this.data.length < 2) {
        return this.margin.left + index * this.slot;
      }

      return Math.max(this.margin.left - 4, this.x(index) - this.slot / 2);
    },
    long(item) {
      if (item.long) {
        return item.long;
      }

      if (item.hour !== undefined) {
        return date(item.from, { weekday: "short", day: "numeric", month: "short" }) + ", " + String(item.hour).padStart(2, "0") + ":00" + (item.partial ? " · " + this.$t("owebstudio.analytics.chart.soFar") : "");
      }

      if (item.from === item.to) {
        return date(item.from, { weekday: "short", day: "numeric", month: "short", year: "numeric" }) + (item.partial ? " · " + this.$t("owebstudio.analytics.chart.soFar") : "");
      }

      return this.$t("owebstudio.analytics.chart.weekOf", { date: date(item.from) });
    },
    nice(max) {
      const raw = Math.max(1, max) / 4;
      const magnitude = 10 ** Math.floor(Math.log10(raw));
      const normalized = raw / magnitude;
      const step = Math.max(
        1,
        (normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 2.5 ? 2.5 : normalized <= 5 ? 5 : 10) * magnitude
      );

      return { step, max: step * Math.ceil(Math.max(1, max) / step) };
    },
    path(points) {
      return points.map((point, index) => `${index ? "L" : "M"}${point[0].toFixed(1)},${point[1].toFixed(1)}`).join("");
    },
    short(item) {
      if (item.short) {
        return item.short;
      }

      if (item.hour !== undefined) {
        return String(item.hour).padStart(2, "0") + ":00";
      }

      return date(item.from, { day: "numeric", month: "short" });
    },
    x(index) {
      if (this.kind === "bar" || this.data.length < 2) {
        return this.margin.left + (index + 0.5) * this.slot;
      }

      return this.margin.left + index * this.slot;
    },
    y(value) {
      return this.margin.top + this.plotHeight - (value / this.scale.max) * this.plotHeight;
    }
  }
};
</script>

<style>
.k-analytics-chart {
  position: relative;
}
.k-analytics-chart svg {
  display: block;
  overflow: visible;
}
.k-analytics-chart-grid line {
  stroke: var(--color-border-dimmed);
  stroke-width: 1;
}
.k-analytics-chart-grid text {
  fill: var(--color-text-dimmed);
  font-size: 11px;
  font-variant-numeric: tabular-nums;
}
.k-analytics-chart-line {
  fill: none;
  stroke: var(--analytics-color);
  stroke-width: 2;
  stroke-linejoin: round;
  stroke-linecap: round;
}
.k-analytics-chart-line[data-partial="true"] {
  stroke-dasharray: 2 4;
}
.k-analytics-chart-area {
  fill: var(--analytics-color);
  opacity: 0.1;
}
.k-analytics-chart-previous {
  fill: none;
  stroke: var(--analytics-color-previous);
  stroke-width: 2;
  stroke-dasharray: 5 4;
  stroke-linejoin: round;
}
.k-analytics-chart-bar {
  fill: var(--analytics-color);
}
.k-analytics-chart-cross {
  stroke: var(--color-text-dimmed);
  stroke-width: 1;
}
.k-analytics-chart-dot {
  fill: var(--analytics-color);
  stroke: light-dark(var(--color-white), var(--color-gray-850));
  stroke-width: 2;
}
.k-analytics-chart-dot[data-previous="true"] {
  fill: var(--analytics-color-previous);
}
.k-analytics-chart-hit {
  fill: transparent;
}
.k-analytics-chart-hit[data-bar="true"][data-active="true"] {
  fill: light-dark(rgba(0, 0, 0, 0.04), rgba(255, 255, 255, 0.05));
}
.k-analytics-tooltip {
  position: absolute;
  z-index: 5;
  pointer-events: none;
  transform: translate(-50%, calc(-100% - 8px));
  padding: var(--spacing-2) var(--spacing-3);
  border-radius: var(--rounded);
  background: var(--color-gray-950);
  color: var(--color-white);
  box-shadow: var(--shadow-xl);
  font-size: var(--text-xs);
  line-height: 1.5;
  white-space: nowrap;
}
.k-analytics-tooltip strong {
  display: block;
  margin-bottom: 2px;
  font-weight: var(--font-semi);
}
.k-analytics-tooltip div {
  display: flex;
  justify-content: space-between;
  gap: var(--spacing-4);
}
</style>
