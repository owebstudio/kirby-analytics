(() => {
	const data = document.currentScript.dataset;
	const downloads = (data.downloads || "").split(",");

	const send = (hit) => {
		const body = JSON.stringify({ ...hit, p: data.page, l: data.lang });

		if (!navigator.sendBeacon?.(data.endpoint, new Blob([body], { type: "text/plain" }))) {
			fetch(data.endpoint, { method: "POST", body, keepalive: true }).catch(() => {});
		}
	};

	const track = (name) => {
		if (name) {
			send({ t: "event", n: String(name).slice(0, 100) });
		}
	};

	window.kirbyAnalytics = { track };
	window.analytics ??= window.kirbyAnalytics;

	// page views are counted on the server unless `mode` is `script`
	if (data.mode === "script" && !navigator.webdriver) {
		const query = new URLSearchParams(location.search);
		const utm = {};

		for (const key of ["source", "medium", "campaign", "content", "term"]) {
			const value = query.get("utm_" + key) ?? (key === "source" ? query.get("ref") : null);

			if (value) {
				utm[key] = value;
			}
		}

		send({ t: "pageview", r: document.referrer, u: utm });
	}

	addEventListener("click", (event) => {
		const element = event.target.closest?.("[data-analytics]:not(form)");
		const link = event.target.closest?.("a[href]");

		if (element) {
			return track(element.dataset.analytics);
		}

		if (!link || !/^https?:$/.test(link.protocol)) {
			return;
		}

		const file = link.pathname.split("/").pop();
		const extension = file.includes(".") ? file.split(".").pop().toLowerCase() : "";

		if (downloads.includes(extension)) {
			track("Download: " + decodeURIComponent(file));
		} else if (link.host !== location.host) {
			track("Outbound: " + link.hostname.replace(/^www\./, ""));
		}
	}, true);

	addEventListener("submit", (event) => {
		const form = event.target;

		if (form.dataset?.analytics !== "false") {
			track("Form: " + (form.dataset?.analytics || form.getAttribute("name") || form.id || new URL(form.action || location.href).pathname));
		}
	}, true);
})();
