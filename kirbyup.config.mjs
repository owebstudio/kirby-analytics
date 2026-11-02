export default {
  vite: {
    build: {
      // keep the native light-dark() function, Kirby 6 has no polyfill for it
      cssTarget: ["chrome123", "edge123", "firefox120", "safari17.5"]
    }
  }
};
