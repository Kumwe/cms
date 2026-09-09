import { t as __vitePreload } from "./administrator-CZSYedDP.js";
//#region assets/administrator/components/studio-launch.ts
/**
* Start module for the contextual Studio mount on the Content editor.
*
* The pinned Studio browser module never scans the document on its own: importing it has no side
* effect, and `autoMountStudio()` is the opt-in that discovers `[data-kumwe-studio]` targets and
* mounts the Producer-emitted deployment next to each one. This module is that opt-in. It imports
* the module from the exact URL PHP resolved (the page carries a `modulepreload` with the manifest
* integrity for the same URL, so the module map already holds the integrity-checked bytes), mounts
* every target, and owns what the shell deliberately leaves to the host: swapping between the
* page builder and the structured form, dirty-state confirmation, and navigation when the shell
* asks to return.
*/
var MOUNT_SELECTOR = "[data-kumwe-studio][data-studio-module-url]";
function isStudioBrowserModule(candidate) {
	return typeof candidate === "object" && candidate !== null && typeof candidate.autoMountStudio === "function" && typeof candidate.StudioAuthoringControlRegistry === "function";
}
function returnPathOf(detail) {
	const extension = detail?.result?.session?.extensions?.["kumwe.app/return"];
	if (typeof extension !== "object" || extension === null) return void 0;
	const path = extension.path;
	return typeof path === "string" && path.startsWith("/administrator/") ? path : void 0;
}
async function setupStudioLaunch() {
	const mount = document.querySelector(MOUNT_SELECTOR);
	const region = mount?.closest("[data-studio-authoring-region]") ?? null;
	const form = document.querySelector("[data-studio-authoring-fallback-form]");
	const formShell = form?.closest(".editor-grid") ?? form;
	const status = region?.querySelector("[data-studio-launch-status]") ?? null;
	const toggle = region?.querySelector("[data-studio-surface-toggle]") ?? null;
	if (mount === null || region === null || formShell === null || status === null || toggle === null) return;
	const moduleUrl = mount.dataset.studioModuleUrl ?? "";
	let currentReturnPath = mount.dataset.studioReturnPath ?? "/administrator/content";
	let surface = "studio";
	const labels = {
		showForm: toggle.dataset.labelForm ?? "Use the structured form",
		showStudio: toggle.dataset.labelStudio ?? "Use the page builder",
		failed: status.dataset.messageFailed ?? "The Studio page builder could not start; the structured form remains available.",
		ready: status.dataset.messageReady ?? "The Studio page builder is ready."
	};
	const show = (next) => {
		surface = next;
		region.dataset.studioSurface = next;
		mount.hidden = next !== "studio";
		formShell.hidden = next !== "form";
		toggle.textContent = next === "studio" ? labels.showForm : labels.showStudio;
		toggle.setAttribute("aria-pressed", next === "form" ? "true" : "false");
	};
	const fail = (error) => {
		status.textContent = labels.failed;
		status.dataset.studioLaunchState = "failed";
		region.dataset.studioSurface = "form";
		mount.hidden = true;
		formShell.hidden = false;
		toggle.hidden = true;
		console.error("Studio page builder failed to mount.", error);
	};
	toggle.addEventListener("click", () => {
		show(surface === "studio" ? "form" : "studio");
	});
	mount.addEventListener("studio-contextual-return-request", (event) => {
		if (event.detail?.returnContext === void 0) return;
		if (mount.querySelector("kumwe-studio-contextual")?.getAttribute("data-dirty") === "true" && !window.confirm(status.dataset.messageDiscard ?? "Discard unsaved changes and return?")) {
			event.preventDefault();
			return;
		}
		window.location.assign(currentReturnPath);
	});
	mount.addEventListener("studio-contextual-save-complete", (event) => {
		const next = returnPathOf(event.detail);
		if (next !== void 0) currentReturnPath = next;
		status.textContent = status.dataset.messageSaved ?? "Saved.";
		status.dataset.studioLaunchState = "saved";
	});
	mount.addEventListener("studio-host-error", (event) => {
		const detail = event.detail;
		status.textContent = detail?.error?.message?.defaultMessage ?? labels.failed;
		status.dataset.studioLaunchState = "error";
	});
	status.dataset.studioLaunchState = "loading";
	try {
		const imported = await __vitePreload(() => import(
			/* @vite-ignore */
			moduleUrl
), []);
		if (!isStudioBrowserModule(imported)) throw new TypeError("The Studio browser module does not export the hosted runtime.");
		const registry = new imported.StudioAuthoringControlRegistry({ strictContentSecurityPolicy: true });
		const report = await imported.autoMountStudio({ hosted: () => ({ authoringControlRegistry: registry }) });
		if (report.failures.length > 0 || report.handles.length === 0) {
			fail(report.failures[0]?.error ?? /* @__PURE__ */ new Error("No Studio target was mounted."));
			return;
		}
		status.textContent = labels.ready;
		status.dataset.studioLaunchState = "ready";
		show("studio");
	} catch (error) {
		fail(error);
	}
}
//#endregion
export { setupStudioLaunch };
