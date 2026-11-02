/**
 * Formatting and URL helpers shared by the components
 */
export const locale = () => window.panel.translation.code ?? "en";

export function number(value, digits = 0) {
  if (value === null || value === undefined) {
    return "–";
  }

  return new Intl.NumberFormat(locale(), {
    maximumFractionDigits: digits
  }).format(value);
}

export function percent(value, digits = 0) {
  if (value === null || value === undefined) {
    return "–";
  }

  return new Intl.NumberFormat(locale(), {
    style: "percent",
    maximumFractionDigits: digits
  }).format(value);
}

/**
 * Seconds as "1m 42s" or "42s"
 */
export function duration(value) {
  if (value === null || value === undefined) {
    return "–";
  }

  const seconds = Math.round(value);
  const minutes = Math.floor(seconds / 60);

  return minutes > 0 ? `${minutes}m ${String(seconds % 60).padStart(2, "0")}s` : `${seconds}s`;
}

export function date(value, options = { day: "numeric", month: "short", year: "numeric" }) {
  if (!value) {
    return "–";
  }

  return new Intl.DateTimeFormat(locale(), { ...options, timeZone: "UTC" }).format(
    new Date(value + "T00:00:00Z")
  );
}

/**
 * Change against the previous period: "↑ 12 %", or `null` without a previous value
 */
export function change(current, previous) {
  if (!previous) {
    return null;
  }

  const value = current / previous - 1;

  if (Math.abs(value) < 0.005) {
    return "± " + percent(0);
  }

  return (value > 0 ? "↑ " : "↓ ") + percent(Math.abs(value));
}

/**
 * Value of a chart metric for axes and tooltips
 */
export function metric(name, value) {
  if (value === null || value === undefined) {
    return "–";
  }

  if (name === "bounce") {
    return percent(value);
  }

  if (name === "duration") {
    return duration(value);
  }

  return number(value);
}

/**
 * Base64url of a UTF-8 string, like `Panel::encode()` in PHP
 */
export function encode(value) {
  const bytes = new TextEncoder().encode(value);
  let binary = "";

  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }

  return btoa(binary).replaceAll("+", "-").replaceAll("/", "_").replace(/=+$/, "");
}

/**
 * Query of the current view without empty values
 */
export function query(values) {
  const result = {};

  for (const [key, value] of Object.entries(values)) {
    if (value !== null && value !== undefined && value !== "" && value !== false) {
      result[key] = String(value);
    }
  }

  return result;
}

export function rangeQuery(range) {
  return range.id === "custom" ? { range: "custom", from: range.from, to: range.to } : { range: range.id };
}

/**
 * Per-viewer conveniences, e.g. the chart mode
 */
export const store = {
  get(key) {
    try {
      return localStorage.getItem("analytics$" + key);
    } catch {
      return null;
    }
  },
  set(key, value) {
    try {
      localStorage.setItem("analytics$" + key, value);
    } catch {
      // private mode or blocked storage
    }
  }
};

export const CHANNEL_ICONS = {
  direct: "url",
  search: "search",
  social: "users",
  ai: "ai",
  email: "email",
  other: "globe"
};

export const GOAL_ICONS = {
  outbound: "open",
  download: "download",
  form: "email",
  event: "bolt",
  page: "page"
};

export const DEVICE_ICONS = {
  desktop: "display",
  mobile: "mobile",
  tablet: "tablet"
};
