const __vite__mapDeps=(i,m=__vite__mapDeps,d=(m.f||(m.f=["js/studio-composition-B7X09ZvF.js","js/reveal-validation-g1jDnck7.js"])))=>i.map(i=>d[i]);
import { a as n, c as A, d as i$1, i as r, l as b, n as setupCopyValues, o as t, r as __decorate, s as i, t as setupValidationReveal } from "./reveal-validation-g1jDnck7.js";
//#region assets/administrator/components/command-palette.ts
var KumweCommandPalette = class KumweCommandPalette extends i {
	#_source_accessor_storage = "administrator-command-data";
	get source() {
		return this.#_source_accessor_storage;
	}
	set source(value) {
		this.#_source_accessor_storage = value;
	}
	#_open_accessor_storage = false;
	get open() {
		return this.#_open_accessor_storage;
	}
	set open(value) {
		this.#_open_accessor_storage = value;
	}
	#_query_accessor_storage = "";
	get query() {
		return this.#_query_accessor_storage;
	}
	set query(value) {
		this.#_query_accessor_storage = value;
	}
	items = [];
	static styles = i$1`
    :host { display: contents; }
    dialog { width: min(680px, calc(100vw - 2rem)); max-height: min(680px, calc(100vh - 2rem)); padding: 0; border: 1px solid var(--kumwe-border-strong); border-radius: var(--kumwe-radius-xl); background: var(--kumwe-surface-elevated); color: var(--kumwe-text); box-shadow: var(--kumwe-shadow-xl); }
    dialog::backdrop { background: rgb(10 20 36 / .58); backdrop-filter: blur(5px); }
    form { position: sticky; top: 0; display: flex; gap: .75rem; align-items: center; padding: 1rem; background: var(--kumwe-surface-elevated); border-bottom: 1px solid var(--kumwe-border); }
    input { min-width: 0; flex: 1; border: 0; outline: 0; background: transparent; color: inherit; font: 600 1.05rem/1.4 var(--kumwe-font-sans); }
    button { border: 0; border-radius: .55rem; padding: .45rem .6rem; background: var(--kumwe-surface-subtle); color: var(--kumwe-text-muted); cursor: pointer; }
    ul { display: grid; gap: .35rem; margin: 0; padding: .65rem; list-style: none; overflow: auto; }
    a { display: grid; gap: .15rem; padding: .85rem .9rem; border-radius: .7rem; color: var(--kumwe-text); text-decoration: none; }
    a:hover, a:focus-visible { outline: 0; background: var(--kumwe-accent-soft); color: var(--kumwe-accent-strong); }
    strong { font-size: .94rem; }
    span { color: var(--kumwe-text-muted); font-size: .82rem; }
    .empty { padding: 2.2rem 1rem; color: var(--kumwe-text-muted); text-align: center; }
  `;
	connectedCallback() {
		super.connectedCallback();
		const source = document.getElementById(this.source);
		if (source?.textContent) try {
			const parsed = JSON.parse(source.textContent);
			if (Array.isArray(parsed)) this.items = parsed.filter(this.isCommandItem);
		} catch {
			this.items = [];
		}
		window.addEventListener("keydown", this.handleShortcut);
		document.querySelectorAll("[data-open-command-palette]").forEach((button) => {
			button.addEventListener("click", this.show);
		});
	}
	disconnectedCallback() {
		window.removeEventListener("keydown", this.handleShortcut);
		super.disconnectedCallback();
	}
	isCommandItem = (value) => {
		if (typeof value !== "object" || value === null) return false;
		const item = value;
		return typeof item.label === "string" && typeof item.href === "string" && typeof item.description === "string" && typeof item.keywords === "string";
	};
	handleShortcut = (event) => {
		if (event.defaultPrevented) return;
		if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
			event.preventDefault();
			this.show();
		}
	};
	show = () => {
		this.open = true;
		this.updateComplete.then(() => {
			this.renderRoot.querySelector("dialog")?.showModal();
			this.renderRoot.querySelector("input")?.focus();
		}).catch(() => void 0);
	};
	close() {
		this.renderRoot.querySelector("dialog")?.close();
		this.open = false;
		this.query = "";
	}
	get results() {
		const query = this.query.trim().toLocaleLowerCase();
		if (!query) return this.items.slice(0, 12);
		return this.items.filter((item) => `${item.label} ${item.description} ${item.keywords}`.toLocaleLowerCase().includes(query)).slice(0, 12);
	}
	render() {
		if (!this.open) return A;
		return b`<dialog @close=${() => {
			this.open = false;
		}} @click=${(event) => {
			if (event.target === event.currentTarget) this.close();
		}}>
      <form method="dialog" @submit=${(event) => event.preventDefault()}>
        <span aria-hidden="true">⌕</span>
        <input aria-label="Search administrator commands" placeholder="Search pages and actions…" .value=${this.query} @input=${(event) => {
			this.query = event.currentTarget.value;
		}}>
        <button type="button" @click=${() => this.close()} aria-label="Close command palette">Esc</button>
      </form>
      ${this.results.length === 0 ? b`<p class="empty">No matching administrator action.</p>` : b`<ul>
        ${this.results.map((item) => b`<li><a href=${item.href}><strong>${item.label}</strong><span>${item.description}</span></a></li>`)}
      </ul>`}
    </dialog>`;
	}
};
__decorate([n({ type: String })], KumweCommandPalette.prototype, "source", null);
__decorate([r()], KumweCommandPalette.prototype, "open", null);
__decorate([r()], KumweCommandPalette.prototype, "query", null);
KumweCommandPalette = __decorate([t("kumwe-command-palette")], KumweCommandPalette);
//#endregion
//#region assets/administrator/components/field-builder.ts
var KumweFieldBuilder = class KumweFieldBuilder extends i {
	static styles = i$1`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.addEventListener("click", this.handleClick);
	}
	disconnectedCallback() {
		this.removeEventListener("click", this.handleClick);
		super.disconnectedCallback();
	}
	handleClick = (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;
		if (target.closest("[data-add-field]")) {
			event.preventDefault();
			this.addRow();
			return;
		}
		const remove = target.closest("[data-remove-field]");
		if (remove) {
			event.preventDefault();
			const row = remove.closest("[data-field-row]");
			if (row && this.querySelectorAll("[data-field-row]").length > 1) row.remove();
		}
	};
	addRow() {
		const template = this.querySelector("template[data-field-template]");
		const rows = this.querySelector("[data-field-rows]");
		if (!template || !rows) return;
		const index = this.querySelectorAll("[data-field-row]").length;
		const fragment = template.content.cloneNode(true);
		fragment.querySelectorAll("[name], [for], [id]").forEach((element) => {
			for (const attribute of [
				"name",
				"for",
				"id"
			]) {
				const value = element.getAttribute(attribute);
				if (value) element.setAttribute(attribute, value.replaceAll("__INDEX__", String(index)));
			}
		});
		rows.append(fragment);
		rows.querySelectorAll("[data-field-row]:last-child input").item(0)?.focus();
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumweFieldBuilder = __decorate([t("kumwe-field-builder")], KumweFieldBuilder);
//#endregion
//#region assets/administrator/components/workflow-builder.ts
var KumweWorkflowBuilder = class KumweWorkflowBuilder extends i {
	static styles = i$1`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.addEventListener("click", this.handleClick);
		this.addEventListener("input", this.handleInput);
	}
	disconnectedCallback() {
		this.removeEventListener("click", this.handleClick);
		this.removeEventListener("input", this.handleInput);
		super.disconnectedCallback();
	}
	handleClick = (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;
		const add = target.closest("[data-add-workflow-row]");
		if (add) {
			event.preventDefault();
			this.addRow(add.dataset.addWorkflowRow ?? "state");
			return;
		}
		const remove = target.closest("[data-remove-workflow-row]");
		if (remove) {
			event.preventDefault();
			remove.closest("[data-workflow-row]")?.remove();
		}
	};
	handleInput = (event) => {
		const target = event.target;
		if (!(target instanceof HTMLInputElement) || !target.matches("[data-state-key]")) return;
		const initial = target.closest("[data-workflow-row]")?.querySelector("input[type=\"radio\"][name=\"initial_state_key\"]");
		if (initial) initial.value = target.value;
	};
	addRow(kind) {
		const template = this.querySelector(`template[data-${kind}-template]`);
		const rows = this.querySelector(`[data-${kind}-rows]`);
		if (!template || !rows) return;
		const index = rows.querySelectorAll("[data-workflow-row]").length;
		const fragment = template.content.cloneNode(true);
		fragment.querySelectorAll("[name], [for], [id]").forEach((element) => {
			for (const attribute of [
				"name",
				"for",
				"id"
			]) {
				const value = element.getAttribute(attribute);
				if (value) element.setAttribute(attribute, value.replaceAll("__INDEX__", String(index)));
			}
		});
		rows.append(fragment);
		rows.querySelectorAll("[data-workflow-row]:last-child input").item(0)?.focus();
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumweWorkflowBuilder = __decorate([t("kumwe-workflow-builder")], KumweWorkflowBuilder);
//#endregion
//#region assets/administrator/components/menu-tree.ts
var KumweMenuTree = class KumweMenuTree extends i {
	static styles = i$1`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.querySelectorAll("[data-menu-item]").forEach((item) => {
			item.setAttribute("draggable", "true");
			item.addEventListener("dragstart", this.dragStart);
			item.addEventListener("dragover", this.dragOver);
			item.addEventListener("drop", this.drop);
			item.addEventListener("dragend", this.dragEnd);
		});
	}
	dragStart = (event) => {
		const item = event.currentTarget;
		if (!(item instanceof HTMLElement) || !event.dataTransfer) return;
		event.dataTransfer.effectAllowed = "move";
		event.dataTransfer.setData("text/plain", item.dataset.menuItem ?? "");
		item.dataset.dragging = "";
	};
	dragOver = (event) => {
		event.preventDefault();
		if (event.dataTransfer) event.dataTransfer.dropEffect = "move";
	};
	drop = (event) => {
		event.preventDefault();
		const target = event.currentTarget;
		if (!(target instanceof HTMLElement) || !event.dataTransfer) return;
		const sourceId = event.dataTransfer.getData("text/plain");
		const source = this.querySelector(`[data-menu-item="${CSS.escape(sourceId)}"]`);
		if (!source || source === target) return;
		target.before(source);
		this.renumber();
	};
	dragEnd = (event) => {
		const item = event.currentTarget;
		if (item instanceof HTMLElement) delete item.dataset.dragging;
	};
	renumber() {
		const order = [];
		this.querySelectorAll("[data-menu-item]").forEach((item, index) => {
			const identifier = item.dataset.menuItem;
			if (identifier) order.push(identifier);
			const position = item.querySelector("[data-position-input]");
			if (position) position.value = String(index);
		});
		const orderInput = this.querySelector("[data-order-input]");
		if (orderInput) orderInput.value = order.join(",");
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumweMenuTree = __decorate([t("kumwe-menu-tree")], KumweMenuTree);
//#endregion
//#region assets/administrator/components/schema-form.ts
var KumweSchemaForm = class KumweSchemaForm extends i {
	static styles = i$1`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.querySelectorAll("textarea[maxlength]").forEach((textarea) => {
			const output = document.createElement("output");
			output.className = "character-count";
			output.setAttribute("aria-live", "polite");
			const update = () => {
				output.value = `${textarea.value.length} / ${textarea.maxLength}`;
			};
			textarea.insertAdjacentElement("afterend", output);
			textarea.addEventListener("input", update);
			update();
		});
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumweSchemaForm = __decorate([t("kumwe-schema-form")], KumweSchemaForm);
//#endregion
//#region assets/administrator/components/media-picker.ts
var KumweMediaPicker = class KumweMediaPicker extends i {
	static styles = i$1`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	target = "";
	connectedCallback() {
		super.connectedCallback();
		document.addEventListener("click", this.handleDocumentClick);
		this.addEventListener("click", this.handlePickerClick);
		this.addEventListener("input", this.handleFilter);
	}
	disconnectedCallback() {
		document.removeEventListener("click", this.handleDocumentClick);
		this.removeEventListener("click", this.handlePickerClick);
		this.removeEventListener("input", this.handleFilter);
		super.disconnectedCallback();
	}
	handleDocumentClick = (event) => {
		const trigger = event.target instanceof Element ? event.target.closest("[data-open-media-picker]") : null;
		if (!trigger) return;
		this.target = trigger.dataset.mediaTarget ?? "";
		this.querySelector("dialog")?.showModal();
	};
	handlePickerClick = (event) => {
		const choice = event.target instanceof Element ? event.target.closest("[data-select-media]") : null;
		if (!choice) return;
		const input = document.getElementById(this.target);
		if (!(input instanceof HTMLInputElement)) return;
		input.value = choice.dataset.mediaUrl ?? "";
		input.dispatchEvent(new Event("input", { bubbles: true }));
		input.dispatchEvent(new Event("change", { bubbles: true }));
		this.querySelector("dialog")?.close();
		input.focus();
	};
	handleFilter = (event) => {
		const input = event.target;
		if (!(input instanceof HTMLInputElement) || !input.matches("[data-media-filter]")) return;
		const query = input.value.trim().toLocaleLowerCase();
		this.querySelectorAll("[data-select-media]").forEach((item) => {
			item.hidden = query !== "" && !(item.dataset.mediaName ?? "").toLocaleLowerCase().includes(query);
		});
	};
	render() {
		return b`<slot></slot>`;
	}
};
KumweMediaPicker = __decorate([t("kumwe-media-picker")], KumweMediaPicker);
//#endregion
//#region assets/administrator/components/job-fields.ts
var KumweJobFields = class KumweJobFields extends i {
	static styles = i$1`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.addEventListener("change", this.handleChange);
		this.updateFields();
	}
	disconnectedCallback() {
		this.removeEventListener("change", this.handleChange);
		super.disconnectedCallback();
	}
	handleChange = (event) => {
		if (event.target instanceof HTMLSelectElement && event.target.matches("[data-job-type]")) this.updateFields();
	};
	updateFields() {
		const type = this.querySelector("[data-job-type]")?.value ?? "";
		this.querySelectorAll("[data-job-fields]").forEach((group) => {
			const active = group.dataset.jobFields === type;
			group.hidden = !active;
			group.querySelectorAll("input, select").forEach((input) => {
				input.disabled = !active;
			});
		});
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumweJobFields = __decorate([t("kumwe-job-fields")], KumweJobFields);
//#endregion
//#region assets/administrator/components/rich-text.ts
var _KumweRichText;
var KumweRichText = class KumweRichText extends i {
	static {
		_KumweRichText = this;
	}
	createRenderRoot() {
		return this;
	}
	/** Tags a browser uses as its own block inside a `contenteditable`; everything else is inline. */
	static blockTags = /* @__PURE__ */ new Set([
		"ADDRESS",
		"ARTICLE",
		"ASIDE",
		"BLOCKQUOTE",
		"DIV",
		"DL",
		"FIGURE",
		"FOOTER",
		"H1",
		"H2",
		"H3",
		"H4",
		"H5",
		"H6",
		"HEADER",
		"HR",
		"MAIN",
		"NAV",
		"OL",
		"P",
		"PRE",
		"SECTION",
		"TABLE",
		"UL"
	]);
	firstUpdated() {
		const source = this.querySelector("textarea");
		const editor = this.querySelector("[data-rich-text-editor]");
		if (!source || !editor) return;
		editor.innerHTML = this.toEditorHtml(source.value);
		editor.addEventListener("input", () => {
			source.value = this.toSource(editor);
			source.dispatchEvent(new Event("input", { bubbles: true }));
		});
		source.addEventListener("invalid", () => editor.focus());
		this.querySelectorAll("[data-rich-text-command]").forEach((button) => {
			button.addEventListener("click", () => {
				const command = button.dataset.richTextCommand;
				editor.focus();
				if (command === "createLink") {
					const url = window.prompt("Link URL");
					if (url) document.execCommand("createLink", false, url);
				} else if (command === "formatBlock") document.execCommand("formatBlock", false, "h2");
				else if (command) document.execCommand(command);
				editor.dispatchEvent(new Event("input", { bubbles: true }));
			});
		});
	}
	render() {
		return b`
      <div class="rich-text-shell js-only">
        <div class="rich-text-toolbar" role="toolbar" aria-label="Text formatting">
          <button type="button" data-rich-text-command="bold" aria-label="Bold"><strong>B</strong></button>
          <button type="button" data-rich-text-command="formatBlock" aria-label="Heading">Heading</button>
          <button type="button" data-rich-text-command="insertUnorderedList" aria-label="Bulleted list">List</button>
          <button type="button" data-rich-text-command="createLink" aria-label="Add link">Link</button>
        </div>
        <div class="rich-text-editor" contenteditable="true" role="textbox" aria-multiline="true"
          aria-label="Rich text editor" data-rich-text-editor></div>
        <p class="field-help">Use the toolbar for headings, emphasis, lists, and safe links.</p>
      </div>
      <slot></slot>
    `;
	}
	toEditorHtml(source) {
		const lines = source.replace(/\r\n?/g, "\n").split("\n");
		const blocks = [];
		let list = [];
		const flushList = () => {
			if (list.length === 0) return;
			blocks.push(`<ul>${list.map((item) => `<li>${this.inlineToHtml(item)}</li>`).join("")}</ul>`);
			list = [];
		};
		for (const line of lines) {
			if (line.startsWith("- ")) {
				list.push(line.slice(2));
				continue;
			}
			flushList();
			if (line.startsWith("## ")) blocks.push(`<h2>${this.inlineToHtml(line.slice(3))}</h2>`);
			else if (line.trim() === "") blocks.push("<p><br></p>");
			else blocks.push(`<p>${this.inlineToHtml(line)}</p>`);
		}
		flushList();
		return blocks.join("");
	}
	inlineToHtml(source) {
		return source.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>").replace(/\[([^\]]+)]\((https?:\/\/[^\s)]+|mailto:[^\s)]+|\/(?!\/)[^\s)]*|#[^\s)]+)\)/g, "<a href=\"$2\">$1</a>");
	}
	/**
	* Serialize the editor back to the source the backing textarea submits.
	*
	* The walk is over `childNodes` rather than `children` because what a browser leaves at the top level
	* of a `contenteditable` is not agreed between engines. Chromium wraps text typed into an empty editor
	* in a `<div>`; Firefox and WebKit leave a bare text node. Reading elements only dropped that text, so
	* the `required` textarea stayed empty and native validation refused the form — the editor looked full
	* and the page would not submit. Runs of top-level inline nodes are gathered into one implicit block,
	* which is what they render as, and block elements keep being serialized one block each.
	*/
	toSource(editor) {
		const blocks = [];
		let inline = [];
		const flushInline = () => {
			if (inline.length === 0) return;
			blocks.push(this.inlineNodesToSource(inline).trimEnd());
			inline = [];
		};
		editor.childNodes.forEach((node) => {
			if (node instanceof Element && _KumweRichText.blockTags.has(node.tagName)) {
				flushInline();
				blocks.push(this.blockToSource(node));
				return;
			}
			inline.push(node);
		});
		flushInline();
		return blocks.filter((value, index, all) => value !== "" || index > 0 && all[index - 1] !== "").join("\n");
	}
	blockToSource(element) {
		if (element.tagName === "UL" || element.tagName === "OL") return Array.from(element.children).map((item) => `- ${this.inlineToSource(item)}`).join("\n");
		return `${/^H[1-6]$/.test(element.tagName) ? "## " : ""}${this.inlineToSource(element)}`.trimEnd();
	}
	inlineToSource(element) {
		return this.inlineNodesToSource(Array.from(element.childNodes));
	}
	/** Serialize a run of inline nodes, wherever in the tree they were found. */
	inlineNodesToSource(nodes) {
		let output = "";
		nodes.forEach((node) => {
			if (node.nodeType === Node.TEXT_NODE) output += node.textContent ?? "";
			else if (node instanceof HTMLBRElement) output += "\n";
			else if (node instanceof HTMLAnchorElement) output += `[${this.inlineToSource(node)}](${node.href})`;
			else if (node instanceof HTMLElement && ["B", "STRONG"].includes(node.tagName)) output += `**${this.inlineToSource(node)}**`;
			else if (node instanceof Element) output += this.inlineToSource(node);
		});
		return output;
	}
};
KumweRichText = _KumweRichText = __decorate([t("kumwe-rich-text")], KumweRichText);
//#endregion
//#region assets/administrator/components/presentation-schemes.ts
var KumwePresentationSchemes = class KumwePresentationSchemes extends i {
	static styles = i$1`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.addEventListener("click", this.handleClick);
		this.addEventListener("input", this.handleInput);
		this.synchronize();
	}
	disconnectedCallback() {
		this.removeEventListener("click", this.handleClick);
		this.removeEventListener("input", this.handleInput);
		super.disconnectedCallback();
	}
	handleClick = (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;
		if (target.closest("[data-add-presentation-scheme]")) {
			event.preventDefault();
			this.addScheme();
			return;
		}
		const remove = target.closest("[data-remove-presentation-scheme]");
		if (!remove) return;
		event.preventDefault();
		if (this.rows().length <= 1) return;
		remove.closest("[data-presentation-scheme-row]")?.remove();
		this.synchronize();
	};
	handleInput = (event) => {
		const target = event.target;
		if (!(target instanceof HTMLInputElement)) return;
		const row = target.closest("[data-presentation-scheme-row]");
		if (!row) return;
		if (target.matches("[data-presentation-scheme-name]")) {
			const heading = row.querySelector("[data-presentation-scheme-heading]");
			if (heading) heading.textContent = target.value.trim() || "Unnamed scheme";
		}
		if (target.matches("[data-presentation-scheme-name], [data-presentation-scheme-handle]")) this.synchronize();
	};
	addScheme() {
		if (this.rows().length >= 12) return;
		const template = this.querySelector("template[data-presentation-scheme-template]");
		const container = this.querySelector("[data-presentation-scheme-rows]");
		if (!template || !container) return;
		const index = this.nextIndex();
		const fragment = template.content.cloneNode(true);
		fragment.querySelectorAll("[name], [for], [id], [value]").forEach((element) => {
			for (const attribute of [
				"name",
				"for",
				"id",
				"value"
			]) {
				const value = element.getAttribute(attribute);
				if (value?.includes("__INDEX__")) element.setAttribute(attribute, value.replaceAll("__INDEX__", String(index)));
			}
		});
		container.append(fragment);
		this.synchronize();
		this.rows().at(-1)?.querySelector("[data-presentation-scheme-name]")?.focus();
	}
	synchronize() {
		const rows = this.rows();
		rows.forEach((row) => {
			const remove = row.querySelector("[data-remove-presentation-scheme]");
			if (remove) remove.disabled = rows.length <= 1;
		});
		const add = this.querySelector("[data-add-presentation-scheme]");
		if (add) add.disabled = rows.length >= 12;
		const active = document.querySelector("[data-active-presentation-scheme]");
		if (!active) return;
		const selected = active.value;
		const schemes = rows.map((row) => ({
			handle: row.querySelector("[data-presentation-scheme-handle]")?.value.trim() ?? "",
			name: row.querySelector("[data-presentation-scheme-name]")?.value.trim() ?? ""
		})).filter((scheme) => scheme.handle !== "");
		active.replaceChildren(...schemes.map((scheme) => {
			const option = document.createElement("option");
			option.value = scheme.handle;
			option.textContent = scheme.name || scheme.handle;
			return option;
		}));
		active.value = schemes.some((scheme) => scheme.handle === selected) ? selected : schemes[0]?.handle ?? "";
	}
	rows() {
		return [...this.querySelectorAll("[data-presentation-scheme-row]")];
	}
	nextIndex() {
		const indices = [...this.querySelectorAll("input[name^=\"scheme_\"][name$=\"_handle\"]")].map((input) => /^scheme_(\d+)_handle$/.exec(input.name)?.[1]).filter((value) => value !== void 0).map(Number);
		return indices.length === 0 ? 0 : Math.max(...indices) + 1;
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumwePresentationSchemes = __decorate([t("kumwe-presentation-schemes")], KumwePresentationSchemes);
//#endregion
//#region assets/administrator/components/business-definition-editor.ts
var KumweDefinitionEditor = class extends i {
	createRenderRoot() {
		return this;
	}
	render() {
		return b`<slot></slot>`;
	}
	connectedCallback() {
		super.connectedCallback();
		this.addEventListener("click", this.handleClick);
		this.addEventListener("toggle", this.handleToggle, true);
	}
	disconnectedCallback() {
		this.removeEventListener("click", this.handleClick);
		this.removeEventListener("toggle", this.handleToggle, true);
		super.disconnectedCallback();
	}
	handleToggle = (event) => {
		const target = event.target;
		if (!(target instanceof HTMLDetailsElement) || !target.open || target.dataset.row !== "field") return;
		this.closeFieldRows(target);
	};
	handleClick = (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;
		const remove = target.closest("[data-remove]");
		if (remove) {
			remove.closest("[data-row]")?.remove();
			return;
		}
		const kind = target.closest("[data-add]")?.dataset.add;
		if (!kind) return;
		const template = this.querySelector(`template[data-template="${kind}"]`);
		const rows = this.querySelector(`[data-rows="${kind}"]`);
		if (!template || !rows || rows.children.length >= this.limit(kind)) return;
		if (kind === "field") this.closeFieldRows();
		const index = this.nextIndex(kind);
		const wrapper = document.createElement("template");
		wrapper.innerHTML = template.innerHTML.replaceAll("__INDEX__", String(index));
		rows.append(wrapper.content.cloneNode(true));
		rows.lastElementChild?.querySelector("input, select, textarea")?.focus();
	};
	closeFieldRows(except) {
		for (const row of this.querySelectorAll("details[data-row=\"field\"][open]")) if (row !== except) row.open = false;
	}
	nextIndex(kind) {
		const names = Array.from(this.querySelectorAll(`[name^="${kind}_"]`)).map((input) => Number(input.name.match(new RegExp(`^${kind}_(\\d+)_`))?.[1] ?? -1));
		return Math.max(-1, ...names) + 1;
	}
	limit(kind) {
		return {
			field: 256,
			relationship: 128,
			view: 64,
			action: 64,
			transition: 128
		}[kind] ?? 0;
	}
};
customElements.define("kumwe-definition-editor", KumweDefinitionEditor);
//#endregion
//#region assets/administrator/components/business-surface.ts
var KumweBusinessSurface = class KumweBusinessSurface extends i {
	static styles = i$1`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.querySelector("[data-business-page-search]")?.addEventListener("input", this.filterPage);
		this.querySelectorAll("form").forEach((form) => {
			form.addEventListener("submit", this.markBusy);
		});
		this.querySelector(".business-error-summary")?.focus();
	}
	disconnectedCallback() {
		this.querySelector("[data-business-page-search]")?.removeEventListener("input", this.filterPage);
		this.querySelectorAll("form").forEach((form) => {
			form.removeEventListener("submit", this.markBusy);
		});
		super.disconnectedCallback();
	}
	filterPage = (event) => {
		const input = event.currentTarget;
		if (!(input instanceof HTMLInputElement)) return;
		const term = input.value.trim().toLocaleLowerCase();
		let visible = 0;
		this.querySelectorAll("[data-business-record-row]").forEach((row) => {
			const matches = term === "" || (row.textContent ?? "").toLocaleLowerCase().includes(term);
			row.hidden = !matches;
			if (matches) visible += 1;
		});
		const empty = this.querySelector("[data-business-search-empty]");
		if (empty) empty.hidden = visible > 0;
	};
	markBusy = (event) => {
		const form = event.currentTarget;
		if (!(form instanceof HTMLFormElement)) return;
		form.setAttribute("aria-busy", "true");
		const submitter = event.submitter;
		if (submitter instanceof HTMLButtonElement) submitter.setAttribute("aria-disabled", "true");
	};
	render() {
		return b`<slot></slot>`;
	}
};
KumweBusinessSurface = __decorate([t("kumwe-business-surface")], KumweBusinessSurface);
var KumweBusinessTable = class KumweBusinessTable extends i {
	static styles = i$1`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumweBusinessTable = __decorate([t("kumwe-business-table")], KumweBusinessTable);
var KumweBusinessOrderedLines = class KumweBusinessOrderedLines extends i {
	static styles = i$1`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.dataset.enhanced = "";
		this.addEventListener("click", this.move);
		this.addEventListener("change", this.selectionChanged);
		this.updateButtons();
	}
	disconnectedCallback() {
		this.removeEventListener("click", this.move);
		this.removeEventListener("change", this.selectionChanged);
		super.disconnectedCallback();
	}
	selectionChanged = (event) => {
		if (event.target instanceof HTMLSelectElement && event.target.matches("[data-business-order-select]")) this.sync();
	};
	move = (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;
		const button = target.closest("[data-move]");
		const item = button?.closest("[data-record-id]");
		if (!button || !item) return;
		if (button.dataset.move === "up" && item.previousElementSibling) item.previousElementSibling.before(item);
		else if (button.dataset.move === "down" && item.nextElementSibling) item.nextElementSibling.after(item);
		else return;
		this.sync();
		button.focus();
	};
	sync() {
		const selections = Array.from(this.querySelectorAll("[data-business-order-select]"));
		this.updateButtons();
		const announcement = this.querySelector("[data-business-order-status]");
		if (announcement) announcement.textContent = `Order updated: ${selections.map((selection) => selection.selectedOptions[0]?.textContent?.trim() ?? "").filter((label) => label !== "").join(", ")}.`;
	}
	updateButtons() {
		const items = Array.from(this.querySelectorAll("[data-record-id]"));
		items.forEach((item, index) => {
			const position = item.querySelector("[data-business-order-position]");
			const up = item.querySelector("[data-move=\"up\"]");
			const down = item.querySelector("[data-move=\"down\"]");
			if (position) position.textContent = `Position ${index + 1}`;
			if (up) up.disabled = index === 0;
			if (down) down.disabled = index === items.length - 1;
		});
		if (!this.querySelector("[data-business-order-status]")) {
			const status = document.createElement("p");
			status.className = "sr-only";
			status.dataset.businessOrderStatus = "";
			status.setAttribute("aria-live", "polite");
			this.append(status);
		}
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumweBusinessOrderedLines = __decorate([t("kumwe-business-ordered-lines")], KumweBusinessOrderedLines);
var KumweBusinessConfirmation = class KumweBusinessConfirmation extends i {
	static styles = i$1`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.querySelector("[data-business-confirm-check]")?.addEventListener("change", this.updateConfirmation);
		this.updateConfirmation();
	}
	disconnectedCallback() {
		this.querySelector("[data-business-confirm-check]")?.removeEventListener("change", this.updateConfirmation);
		super.disconnectedCallback();
	}
	updateConfirmation = () => {
		const checkbox = this.querySelector("[data-business-confirm-check]");
		const submit = this.querySelector("[data-business-confirm-form] button[type=\"submit\"]");
		if (submit) submit.disabled = !checkbox?.checked;
	};
	render() {
		return b`<slot></slot>`;
	}
};
KumweBusinessConfirmation = __decorate([t("kumwe-business-confirmation")], KumweBusinessConfirmation);
//#endregion
//#region assets/administrator/components/dirty-form.ts
var KumweDirtyForm = class KumweDirtyForm extends i {
	form = null;
	status = null;
	markDirty = () => {
		if (this.hasAttribute("data-dirty")) return;
		this.setAttribute("data-dirty", "");
		if (this.status !== null) this.status.textContent = "Unsaved changes";
	};
	clearDirty = () => {
		this.removeAttribute("data-dirty");
		if (this.status !== null) this.status.textContent = "Changes are saved when you submit this form.";
	};
	warnBeforeLeave = (event) => {
		if (!this.hasAttribute("data-dirty")) return;
		event.preventDefault();
		event.returnValue = "";
	};
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.form = this.querySelector("form");
		this.status = this.querySelector("[data-kis-dirty-status]");
		this.form?.addEventListener("input", this.markDirty);
		this.form?.addEventListener("change", this.markDirty);
		this.form?.addEventListener("submit", this.clearDirty);
		window.addEventListener("beforeunload", this.warnBeforeLeave);
	}
	disconnectedCallback() {
		this.form?.removeEventListener("input", this.markDirty);
		this.form?.removeEventListener("change", this.markDirty);
		this.form?.removeEventListener("submit", this.clearDirty);
		window.removeEventListener("beforeunload", this.warnBeforeLeave);
		this.form = null;
		this.status = null;
		super.disconnectedCallback();
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumweDirtyForm = __decorate([t("kumwe-dirty-form")], KumweDirtyForm);
//#endregion
//#region assets/administrator/components/repeatable-group.ts
var INDEX_TOKEN = "INDEX";
var nextIndex = (rows) => {
	let highest = -1;
	for (const input of rows.querySelectorAll("[name^=\"field__\"]")) {
		const segments = input.name.split("__");
		for (const segment of segments) if (/^\d+$/.test(segment)) highest = Math.max(highest, Number(segment));
	}
	return highest + 1;
};
var substituteIndex = (row, index) => {
	for (const element of row.querySelectorAll("[name], [id], [data-media-target], label[for]")) for (const attribute of [
		"name",
		"id",
		"data-media-target",
		"for"
	]) {
		const value = element.getAttribute(attribute);
		if (value !== null && value.includes(INDEX_TOKEN)) element.setAttribute(attribute, value.replaceAll(INDEX_TOKEN, String(index)));
	}
	const title = row.querySelector(".repeatable-row-title");
	if (title) title.textContent = `${title.textContent ?? ""} ${index + 1}`.trim();
};
var setup = (group) => {
	const rows = group.querySelector("[data-repeatable-rows]");
	const template = group.querySelector("[data-repeatable-template]");
	const add = group.querySelector("[data-repeatable-add]");
	if (!rows || !template || !add) return;
	const limit = Number(group.dataset.repeatableMax ?? "");
	const enforceLimit = () => {
		add.disabled = Number.isFinite(limit) && limit > 0 && rows.querySelectorAll("[data-repeatable-row]").length >= limit;
	};
	add.addEventListener("click", () => {
		const row = template.content.cloneNode(true).querySelector("[data-repeatable-row]");
		if (!row) return;
		substituteIndex(row, nextIndex(rows));
		rows.append(row);
		enforceLimit();
		row.querySelector("input, textarea, select")?.focus();
	});
	group.addEventListener("click", (event) => {
		const target = event.target;
		if (target instanceof HTMLElement && target.closest("[data-repeatable-remove]")) {
			target.closest("[data-repeatable-row]")?.remove();
			enforceLimit();
		}
	});
	enforceLimit();
};
for (const group of document.querySelectorAll("[data-repeatable]")) setup(group);
//#endregion
//#region assets/administrator/components/policy-step-flow.ts
/**
* Progressively enhance the server-rendered resource-policy authoring stages.
*
* The complete native form remains in the document for no-JavaScript use. Enhancement hides only the
* inactive stage, changes canonical step URLs through history state so entered values remain intact,
* restores every stage before native constraint validation, and focuses the first invalid stage.
*/
var PolicyStepFlowController = class {
	root;
	pendingInvalid = null;
	submissionRestoreTimer = null;
	revealedStep = null;
	constructor(root) {
		this.root = root;
	}
	connect() {
		if (this.root.hasAttribute("data-enhanced")) return;
		this.root.addEventListener("click", this.handleClick);
		this.root.addEventListener("invalid", this.handleInvalid, true);
		this.root.addEventListener("kis:reveal-target", this.handleRevealTarget);
		window.addEventListener("popstate", this.handleLocationChange);
		window.addEventListener("hashchange", this.handleLocationChange);
		this.root.dataset.enhanced = "";
		const locationStep = this.locationStep();
		this.activate(locationStep ?? this.selectedStep(), false, locationStep !== null);
	}
	navigationLinks() {
		return Array.from(this.root.querySelectorAll("[data-kis-policy-step-link]"));
	}
	panels() {
		return Array.from(this.root.querySelectorAll("[data-kis-policy-step-panel]"));
	}
	selectedStep() {
		return this.root.dataset.kisActiveStep ?? this.root.querySelector("[data-kis-policy-step-link][aria-current=\"step\"]")?.dataset.kisPolicyStepLink ?? this.panels()[0]?.dataset.kisPolicyStepPanel ?? "";
	}
	locationStep() {
		const parameter = this.root.dataset.kisStepParameter ?? "step";
		const requested = new URL(window.location.href).searchParams.get(parameter);
		if (requested !== null && this.panel(requested) !== null) return requested;
		const hashTarget = window.location.hash === "" ? null : document.getElementById(window.location.hash.slice(1));
		return hashTarget instanceof HTMLElement && this.root.contains(hashTarget) ? hashTarget.closest("[data-kis-policy-step-panel]")?.dataset.kisPolicyStepPanel ?? null : null;
	}
	panel(step) {
		return this.panels().find((panel) => panel.dataset.kisPolicyStepPanel === step) ?? null;
	}
	activate(step, updateLocation, focus) {
		const panel = this.panel(step) ?? this.panels()[0];
		const selected = panel?.dataset.kisPolicyStepPanel;
		if (!panel || !selected) return;
		this.root.dataset.kisActiveStep = selected;
		for (const link of this.navigationLinks()) {
			const active = link.dataset.kisPolicyStepLink === selected;
			link.classList.toggle("primary", active);
			if (active) link.setAttribute("aria-current", "step");
			else link.removeAttribute("aria-current");
		}
		for (const candidate of this.panels()) {
			const active = candidate === panel;
			candidate.hidden = !active;
			candidate.toggleAttribute("data-kis-step-current", active);
			candidate.querySelector("[data-kis-current-step-label]")?.toggleAttribute("hidden", !active);
		}
		if (updateLocation) {
			const destinationLink = this.navigationLinks().find((link) => link.dataset.kisPolicyStepLink === selected);
			if (destinationLink) {
				const destination = new URL(destinationLink.href, window.location.href);
				window.history.pushState({}, "", `${destination.pathname}${destination.search}${destination.hash}`);
			}
		}
		const label = this.navigationLinks().find((link) => link.dataset.kisPolicyStepLink === selected)?.textContent?.trim();
		const announcement = this.root.querySelector("[data-kis-policy-step-announcement]");
		if (announcement && label) announcement.textContent = `${label} is active.`;
		if (focus) panel.focus();
	}
	revealAllPanels() {
		for (const panel of this.panels()) panel.hidden = false;
	}
	prepareForSubmission() {
		if (this.submissionRestoreTimer !== null) window.clearTimeout(this.submissionRestoreTimer);
		this.revealedStep = this.selectedStep();
		this.revealAllPanels();
		this.submissionRestoreTimer = window.setTimeout(() => {
			const step = this.revealedStep;
			this.submissionRestoreTimer = null;
			this.revealedStep = null;
			if (step === null || !this.root.isConnected || this.pendingInvalid !== null) return;
			this.activate(step, false, false);
		}, 0);
	}
	handleClick = (event) => {
		const target = event.target;
		if (!(target instanceof Element)) return;
		if (!event.defaultPrevented && target.closest("button[type=\"submit\"], input[type=\"submit\"]")) {
			this.prepareForSubmission();
			return;
		}
		const link = target.closest("[data-kis-policy-step-link], [data-kis-policy-step-target]");
		if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
		const destination = new URL(link.href, window.location.href);
		if (destination.origin !== window.location.origin || destination.pathname !== window.location.pathname) return;
		const step = link.dataset.kisPolicyStepLink ?? link.dataset.kisPolicyStepTarget;
		if (!step || this.panel(step) === null) return;
		event.preventDefault();
		this.activate(step, true, true);
	};
	handleInvalid = (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;
		const step = target.closest("[data-kis-policy-step-panel]")?.dataset.kisPolicyStepPanel;
		if (!step) return;
		if (this.submissionRestoreTimer !== null) window.clearTimeout(this.submissionRestoreTimer);
		this.submissionRestoreTimer = null;
		this.revealedStep = null;
		this.revealAllPanels();
		if (this.pendingInvalid !== null) return;
		this.pendingInvalid = {
			step,
			target
		};
		window.setTimeout(() => {
			const pending = this.pendingInvalid;
			this.pendingInvalid = null;
			if (pending === null || !this.root.isConnected) return;
			this.activate(pending.step, true, false);
			pending.target.focus();
		}, 0);
	};
	handleRevealTarget = (event) => {
		if (!(event.target instanceof Element) || !this.root.contains(event.target)) return;
		const panel = event.target.closest("[data-kis-policy-step-panel]");
		if (panel?.dataset.kisPolicyStepPanel) this.activate(panel.dataset.kisPolicyStepPanel, true, false);
	};
	handleLocationChange = () => {
		const step = this.locationStep();
		if (step !== null) this.activate(step, false, true);
	};
};
/** Enhance every bounded policy flow present in the current administrator response. */
function setupPolicyStepFlows() {
	document.querySelectorAll("[data-kis-policy-step-flow]").forEach((root) => {
		new PolicyStepFlowController(root).connect();
	});
}
//#endregion
//#region \0vite/preload-helper.js
var scriptRel = "modulepreload";
var assetsURL = function(dep) {
	return "/" + dep;
};
var seen = {};
var __vitePreload = function preload(baseModule, deps, importerUrl) {
	let promise = Promise.resolve();
	if (deps && deps.length > 0) {
		const links = document.getElementsByTagName("link");
		const cspNonceMeta = document.querySelector("meta[property=csp-nonce]");
		const cspNonce = cspNonceMeta?.nonce || cspNonceMeta?.getAttribute("nonce");
		function allSettled(promises) {
			return Promise.all(promises.map((p) => Promise.resolve(p).then((value) => ({
				status: "fulfilled",
				value
			}), (reason) => ({
				status: "rejected",
				reason
			}))));
		}
		function importMetaResolve(specifier) {
			if (import.meta.resolve) return import.meta.resolve(specifier);
			return new URL(
				specifier,
				/** #__KEEP__ */
				import.meta.url
			).href;
		}
		promise = allSettled(deps.map((dep) => {
			dep = assetsURL(dep, importerUrl);
			dep = importMetaResolve(dep);
			if (dep in seen) return;
			seen[dep] = true;
			const isCss = dep.endsWith(".css");
			for (let i = links.length - 1; i >= 0; i--) {
				const link = links[i];
				if (link.href === dep && (!isCss || link.rel === "stylesheet")) return;
			}
			const link = document.createElement("link");
			link.rel = isCss ? "stylesheet" : scriptRel;
			if (!isCss) link.as = "script";
			link.crossOrigin = "";
			link.href = dep;
			if (cspNonce) link.setAttribute("nonce", cspNonce);
			document.head.appendChild(link);
			if (isCss) return new Promise((res, rej) => {
				link.addEventListener("load", res);
				link.addEventListener("error", () => rej(/* @__PURE__ */ new Error(`Unable to preload CSS for ${dep}`)));
			});
		}));
	}
	function handlePreloadError(err) {
		const e = new Event("vite:preloadError", { cancelable: true });
		e.payload = err;
		window.dispatchEvent(e);
		if (!e.defaultPrevented) throw err;
	}
	return promise.then((res) => {
		for (const item of res || []) {
			if (item.status !== "rejected") continue;
			handlePreloadError(item.reason);
		}
		return baseModule().catch(handlePreloadError);
	});
};
//#endregion
//#region assets/administrator/main.ts
document.documentElement.classList.add("js");
var focusableSelector = "a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=\"-1\"])";
function setupNavigation() {
	const shell = document.querySelector("[data-administrator-shell]");
	const toggle = document.querySelector("[data-navigation-toggle]");
	const backdrop = document.querySelector("[data-navigation-backdrop]");
	if (!shell || !toggle) return;
	const setOpen = (open) => {
		shell.toggleAttribute("data-navigation-open", open);
		toggle.setAttribute("aria-expanded", String(open));
		document.body.classList.toggle("navigation-locked", open);
		if (open) shell.querySelector(focusableSelector)?.focus();
		else toggle.focus();
	};
	toggle.addEventListener("click", () => setOpen(!shell.hasAttribute("data-navigation-open")));
	backdrop?.addEventListener("click", () => setOpen(false));
	shell.querySelectorAll(".administrator-navigation a").forEach((link) => {
		link.addEventListener("click", () => setOpen(false));
	});
	window.addEventListener("keydown", (event) => {
		if (event.key === "Escape" && shell.hasAttribute("data-navigation-open")) setOpen(false);
	});
}
function setupConfirmations() {
	document.querySelectorAll("[data-confirm]").forEach((element) => {
		element.addEventListener("click", (event) => {
			const message = element.dataset.confirm ?? "Continue with this action?";
			if (!window.confirm(message)) event.preventDefault();
		});
	});
}
function setupDismissibleNotices() {
	document.querySelectorAll("[data-dismiss-notice]").forEach((button) => {
		button.addEventListener("click", () => button.closest("[role=\"status\"], [role=\"alert\"]")?.remove());
	});
}
function setupContentTypeSelector() {
	const selector = document.querySelector("[data-content-type-selector]");
	if (!selector) return;
	selector.addEventListener("change", () => {
		const url = new URL(window.location.href);
		url.searchParams.set("content_type", selector.value);
		window.location.assign(url);
	});
}
function setupSlugSuggestion() {
	const title = document.querySelector("[data-title-input]");
	const slug = document.querySelector("[data-slug-input]");
	if (!title || !slug) return;
	let userEdited = slug.value.trim() !== "";
	slug.addEventListener("input", () => {
		userEdited = slug.value.trim() !== "";
	});
	title.addEventListener("input", () => {
		if (userEdited) return;
		slug.value = title.value.normalize("NFKD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 160);
	});
}
function setupNavigationTargets() {
	document.querySelectorAll("[data-navigation-target-form]").forEach((form) => {
		const type = form.querySelector("[data-navigation-target-type]");
		const contentField = form.querySelector("[data-navigation-content-field]");
		const urlField = form.querySelector("[data-navigation-url-field]");
		const content = form.querySelector("[data-navigation-content]");
		const url = urlField?.querySelector("input");
		const title = form.querySelector("[data-navigation-title]");
		const slug = form.querySelector("[data-navigation-slug]");
		if (!type || !contentField || !urlField || !content || !url) return;
		const sync = () => {
			const contentTarget = type.value === "content";
			contentField.hidden = type.value === "url";
			urlField.hidden = contentTarget;
			content.disabled = type.value === "url";
			url.disabled = contentTarget;
			content.required = contentTarget;
			url.required = !contentTarget;
		};
		type.addEventListener("change", sync);
		content.addEventListener("change", () => {
			const option = content.selectedOptions[0];
			if (!option || option.value === "") return;
			if (title && title.value.trim() === "") title.value = option.textContent?.split(" · ")[0]?.trim() ?? "";
			if (slug && slug.value.trim() === "") slug.value = option.dataset.slug ?? "";
		});
		sync();
	});
}
setupNavigation();
setupConfirmations();
setupDismissibleNotices();
setupContentTypeSelector();
setupSlugSuggestion();
setupCopyValues();
setupValidationReveal();
setupNavigationTargets();
setupPolicyStepFlows();
if (document.querySelector("[data-studio-composition]") !== null) __vitePreload(async () => {
	const { setupStudioComposition } = await import("./studio-composition-B7X09ZvF.js");
	return { setupStudioComposition };
}, __vite__mapDeps([0,1])).then(({ setupStudioComposition }) => setupStudioComposition());
if (document.querySelector("[data-kumwe-studio][data-studio-module-url]") !== null) __vitePreload(async () => {
	const { setupStudioLaunch } = await import("./studio-launch-M9nIHenC.js");
	return { setupStudioLaunch };
}, []).then(({ setupStudioLaunch }) => setupStudioLaunch());
//#endregion
export { __vitePreload as t };
