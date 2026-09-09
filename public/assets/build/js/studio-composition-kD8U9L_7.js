import { c as A$10, d as i$17, l as b$12, s as i$16, u as w$12 } from "./reveal-validation-g1jDnck7.js";
import { t as __vitePreload } from "./administrator-CZSYedDP.js";
//#region node_modules/@kumwe/studio-core/dist/canonical.js
function canonicalStringify(e, t = {}) {
	let r = t.maximumDepth ?? 64;
	if (!Number.isInteger(r) || r < 1) throw RangeError(`Canonical serialization depth must be a positive integer.`);
	return n$8(e, r, 0);
}
function canonicalUtf8Bytes(t, n = {}) {
	let r = canonicalStringify(t, n), i = [];
	for (let e of r) {
		let t = e.codePointAt(0);
		if (t === void 0) break;
		t <= 127 ? i.push(t) : t <= 2047 ? i.push(192 | t >> 6, 128 | t & 63) : t <= 65535 ? i.push(224 | t >> 12, 128 | t >> 6 & 63, 128 | t & 63) : i.push(240 | t >> 18, 128 | t >> 12 & 63, 128 | t >> 6 & 63, 128 | t & 63);
	}
	return Uint8Array.from(i);
}
function n$8(e, t, i) {
	if (e === null) return `null`;
	switch (typeof e) {
		case `boolean`: return e ? `true` : `false`;
		case `number`:
			if (!Number.isFinite(e)) throw TypeError(`Canonical JSON cannot represent a non-finite number.`);
			return JSON.stringify(Object.is(e, -0) ? 0 : e);
		case `string`: return JSON.stringify(e);
		case `object`: break;
		default: throw TypeError(`Canonical JSON cannot represent a ${typeof e} value.`);
	}
	if (i >= t) throw RangeError(`Canonical serialization exceeds the depth limit of ${t}.`);
	if (Array.isArray(e)) return `[${e.map((e) => {
		if (e === void 0) throw TypeError(`Canonical JSON arrays cannot contain undefined entries.`);
		return n$8(e, t, i + 1);
	}).join(`,`)}]`;
	let a = Object.getPrototypeOf(e);
	if (a !== Object.prototype && a !== null) throw TypeError(`Canonical JSON only serializes plain objects and arrays.`);
	let o = Object.keys(e).sort(r$12), s = [];
	for (let r of o) {
		if (r === `__proto__` || r === `prototype` || r === `constructor`) throw TypeError(`Canonical JSON forbids the object member name ${r}.`);
		let a = e[r];
		a !== void 0 && s.push(`${JSON.stringify(r)}:${n$8(a, t, i + 1)}`);
	}
	return `{${s.join(`,`)}}`;
}
function r$12(e, t) {
	return e < t ? -1 : +(e > t);
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/clone.js
function cloneContractValue(e) {
	return JSON.parse(JSON.stringify(e));
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/binding-projection.js
function projectBlueprintFieldBindings(t, i, a) {
	let o = cloneContractValue(t), s = cloneContractValue(i), l = cloneContractValue(a), u = [], d = cloneContractValue(o.model), f = n$7(o, s, u), p = /* @__PURE__ */ new Map();
	for (let e of l) p.set(g$13(e.type, e.version), e);
	let h = {
		blueprintId: o.id,
		definitions: p,
		diagnostics: u,
		fields: f ? c$10(s.fields) : [],
		modelCompatible: f,
		modelReference: d
	}, _ = [];
	return m$15(o.roots, (e) => {
		_.push(r$11(e, h));
	}), cloneContractValue({
		diagnostics: u,
		model: d,
		nodes: _
	});
}
function n$7(e, t, n) {
	let r = !0;
	for (let i of [
		{
			actual: t.id,
			code: `studio.binding/model-id-mismatch`,
			expected: e.model.id,
			member: `id`
		},
		{
			actual: t.version,
			code: `studio.binding/model-version-mismatch`,
			expected: e.model.version,
			member: `version`
		},
		{
			actual: t.revision,
			code: `studio.binding/model-revision-mismatch`,
			expected: e.model.revision,
			member: `revision`
		}
	]) i.actual !== i.expected && (r = !1, n.push(h$13(i.code, `The projected model ${i.member} {actual} does not match the Blueprint lock {expected}.`, `error`, {
		actual: i.actual,
		expected: i.expected,
		member: i.member
	}, { artifactId: e.id })));
	return r;
}
function r$11(e, t) {
	let n = t.definitions.get(g$13(e.type, e.version))?.ports ?? [], r = new Set(n.map((e) => e.id)), o = Object.keys(e.bindings).filter((e) => !r.has(e)).sort(v$13), s = [...n.map((n) => i$15(e, n, t)), ...o.map((n) => a$13(e, n, t))];
	return {
		nodeId: e.id,
		ports: s
	};
}
function i$15(t, n, r) {
	let i = r.modelCompatible ? r.fields.filter((e) => e.field.authoring?.hidden !== !0 && u$15(e.field, n)).map(({ field: e, fieldPath: t }) => s$12(e, t)) : [], a = t.bindings[n.id];
	if (a === void 0) return n.required && r.diagnostics.push(h$13(`studio.binding/required-port-unbound`, `Required block port {port} is not bound to a source.`, `warning`, { port: n.id }, {
		artifactId: r.blueprintId,
		nodeId: t.id
	})), {
		candidates: i,
		multiple: n.multiple,
		port: n.id,
		required: n.required,
		status: `unbound`,
		valueType: n.valueType
	};
	if (a.source.kind !== `entry-field`) return {
		binding: cloneContractValue(a),
		candidates: i,
		multiple: n.multiple,
		port: n.id,
		required: n.required,
		status: `non-field-source`,
		valueType: n.valueType
	};
	let c = [...a.source.fieldPath];
	if (!r.modelCompatible) return o$10(n, a, c, i);
	let m = l$15(r.fields, c);
	return m === void 0 ? (r.diagnostics.push(h$13(`studio.binding/field-missing`, `Binding port {port} addresses field path {fieldPath}, which the locked model no longer declares.`, `error`, {
		fieldPath: c.join(`.`),
		port: n.id
	}, {
		artifactId: r.blueprintId,
		fieldPath: c,
		nodeId: t.id
	})), o$10(n, a, c, i)) : d$14(m.field) === n.multiple ? f$16(m.field, n.valueType) ? {
		binding: cloneContractValue(a),
		boundFieldPath: c,
		candidates: i,
		multiple: n.multiple,
		port: n.id,
		required: n.required,
		status: `resolved`,
		valueType: n.valueType
	} : (r.diagnostics.push(h$13(`studio.binding/field-kind-incompatible`, `Binding port {port} expects {valueType}, but field {fieldPath} now projects as {fieldKind}.`, `error`, {
		fieldKind: p$15(m.field),
		fieldPath: c.join(`.`),
		port: n.id,
		valueType: n.valueType
	}, {
		artifactId: r.blueprintId,
		fieldPath: c,
		nodeId: t.id
	})), o$10(n, a, c, i)) : (r.diagnostics.push(h$13(`studio.binding/field-cardinality-incompatible`, `Binding port {port} and field {fieldPath} no longer have compatible cardinality.`, `error`, {
		fieldPath: c.join(`.`),
		port: n.id
	}, {
		artifactId: r.blueprintId,
		fieldPath: c,
		nodeId: t.id
	})), o$10(n, a, c, i));
}
function a$13(t, n, r) {
	let i = t.bindings[n];
	return i === void 0 ? {
		candidates: [],
		port: n,
		status: `invalid`
	} : (r.diagnostics.push(h$13(`studio.binding/port-missing`, `Binding port {port} is not declared by the locked block definition.`, `error`, { port: n }, {
		artifactId: r.blueprintId,
		nodeId: t.id
	})), {
		binding: cloneContractValue(i),
		...i.source.kind === `entry-field` ? { boundFieldPath: [...i.source.fieldPath] } : {},
		candidates: [],
		port: n,
		status: `invalid`
	});
}
function o$10(t, n, r, i) {
	return {
		binding: cloneContractValue(n),
		boundFieldPath: [...r],
		candidates: i,
		multiple: t.multiple,
		port: t.id,
		required: t.required,
		status: `invalid`,
		valueType: t.valueType
	};
}
function s$12(t, n) {
	return {
		cardinality: t.cardinality,
		...t.authoring?.control === void 0 ? {} : { control: t.authoring.control },
		fieldPath: [...n],
		...t.itemKind === void 0 ? {} : { itemKind: t.itemKind },
		kind: t.kind,
		label: cloneContractValue(t.label)
	};
}
function c$10(e) {
	let t = [], n = (e, r) => {
		let i = e.map((e, t) => ({
			field: e,
			index: t
		})).sort((e, t) => (e.field.authoring?.order ?? 2 ** 53 - 1) - (t.field.authoring?.order ?? 2 ** 53 - 1) || e.index - t.index);
		for (let { field: e } of i) {
			let i = [...r, e.id];
			t.push({
				field: e,
				fieldPath: i
			}), e.kind === `object` && e.cardinality === `one` && e.fields !== void 0 && n(e.fields, i);
		}
	};
	return n(e, []), t;
}
function l$15(e, t) {
	return e.find((e) => _$13(e.fieldPath, t));
}
function u$15(e, t) {
	return d$14(e) === t.multiple && f$16(e, t.valueType);
}
function d$14(e) {
	return e.cardinality === `many`;
}
function f$16(e, t) {
	let n = p$15(e);
	return n === t ? !0 : t === `text` ? n === `string` || n === `enum` : t === `number` ? n === `decimal` || n === `integer` : !1;
}
function p$15(e) {
	return e.kind === `collection` ? e.itemKind ?? `object` : e.kind;
}
function m$15(e, t) {
	for (let n of e) {
		t(n);
		for (let e of Object.keys(n.slots).sort(v$13)) m$15(n.slots[e] ?? [], t);
	}
}
function h$13(e, t, n, r, i) {
	return {
		code: e,
		...i === void 0 ? {} : { location: i },
		message: {
			defaultMessage: t,
			key: e
		},
		...r === void 0 ? {} : { parameters: r },
		severity: n
	};
}
function g$13(e, t) {
	return `${e}@${t}`;
}
function _$13(e, t) {
	return e.length === t.length && e.every((e, n) => e === t[n]);
}
function v$13(e, t) {
	return e < t ? -1 : +(e > t);
}
//#endregion
//#region node_modules/@kumwe/studio-protocol/dist/types.js
var STUDIO_CONTRACT_VERSION = `0.1-draft`;
var STUDIO_WIRE_PROTOCOL_VERSION = `0.1.0-draft.2`;
var STUDIO_STALE_SESSION_GENERATION_DIAGNOSTIC_CODE = `studio.host/stale-session-generation`;
//#endregion
//#region node_modules/@kumwe/studio-protocol/dist/guards.js
var n$6 = /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\/[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/u;
var r$10 = /^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/u;
var i$14 = /^[a-f0-9]{64}$/u;
var a$12 = /^studio\.preview\/node\/([a-f0-9]{64})\/(0|[1-9][0-9]{0,4})$/u;
var o$9 = /* @__PURE__ */ new Set([
	`cancelled`,
	`conflict`,
	`forbidden`,
	`incompatible`,
	`internal`,
	`invalid-request`,
	`limit-exceeded`,
	`not-found`,
	`rate-limited`,
	`unauthenticated`,
	`unavailable`,
	`validation-failed`
]);
function isHostPortError(t) {
	return E$11(t) && T$11(t, [
		`contractVersion`,
		`kind`,
		`category`,
		`message`,
		`retryable`
	], [
		`correlationId`,
		`diagnostics`,
		`retryAfterMilliseconds`,
		`revision`
	]) && t.contractVersion === STUDIO_CONTRACT_VERSION && t.kind === `host-error` && typeof t.category == `string` && o$9.has(t.category) && w$11(t.message) && typeof t.retryable == `boolean` && (t.correlationId === void 0 || N$7(t.correlationId)) && (t.revision === void 0 || t.category === `conflict` && P$7(t.revision)) && (t.retryAfterMilliseconds === void 0 || (t.category === `rate-limited` || t.category === `unavailable`) && t.retryable && D$9(t.retryAfterMilliseconds) && t.retryAfterMilliseconds <= 864e5) && (t.diagnostics === void 0 || I$5(t.diagnostics, S$12, 1e3));
}
function isPreviewMessage(t) {
	if (!E$11(t) || !T$11(t, [
		`contractVersion`,
		`kind`,
		`channelId`,
		`sessionGeneration`,
		`sequence`,
		`type`,
		`payload`
	]) || t.contractVersion !== STUDIO_CONTRACT_VERSION || t.kind !== `preview-message` || !N$7(t.channelId) || !P$7(t.sessionGeneration) || !D$9(t.sequence) || typeof t.type != `string` || !E$11(t.payload)) return !1;
	switch (t.type) {
		case `studio.preview/ready`: return f$15(t.payload);
		case `studio.preview/render`: return p$14(t.payload);
		case `studio.preview/rendered`: return isPreviewRenderedPayload(t.payload);
		case `studio.preview/select`: return b$11(t.payload);
		case `studio.preview/measure`: return h$12(t.payload);
		case `studio.preview/measurements`: return g$12(t.payload);
		case `studio.preview/error`: return x$12(t.payload);
		case `studio.preview/reload`:
		case `studio.preview/teardown`: return d$13(t.payload);
		case `studio.preview/activated`: return s$11(t.payload);
		case `studio.preview/viewport`: return c$9(t.payload);
		case `studio.preview/dispose`: return u$14(t.payload);
		default: return !1;
	}
}
function s$11(e) {
	return T$11(e, [`interaction`, `marker`]) && isPreviewMarker(e.marker) && (e.interaction === `activate` || e.interaction === `context-menu` || e.interaction === `focus`);
}
function c$9(e) {
	let t = Object.keys(e);
	if (t.length === 0 || t.some((e) => ![
		`height`,
		`viewport`,
		`width`
	].includes(e))) return !1;
	let n = Object.hasOwn(e, `viewport`), r = Object.hasOwn(e, `width`), i = Object.hasOwn(e, `height`);
	return n === (r || i) ? !1 : n ? M$7(e.viewport) : (!r || l$14(e.width)) && (!i || l$14(e.height));
}
function l$14(e) {
	return typeof e == `number` && Number.isSafeInteger(e) && e >= 240 && e <= 1e4;
}
function u$14(e) {
	return !j$9(e.reason) || Object.keys(e).some((e) => e !== `draftDigest` && e !== `reason`) ? !1 : e.draftDigest === void 0 || typeof e.draftDigest == `string` && i$14.test(e.draftDigest);
}
function d$13(e) {
	return T$11(e, [`reason`]) && j$9(e.reason);
}
function f$15(e) {
	return T$11(e, [
		`protocolVersion`,
		`renderer`,
		`viewports`
	]) && e.protocolVersion === STUDIO_WIRE_PROTOCOL_VERSION && j$9(e.renderer) && F$7(e.viewports, M$7, 20);
}
function p$14(e) {
	return T$11(e, [
		`artifactId`,
		`draftDigest`,
		`draftRevision`,
		`requestId`,
		`viewport`
	]) && N$7(e.artifactId) && typeof e.draftDigest == `string` && i$14.test(e.draftDigest) && P$7(e.draftRevision) && N$7(e.requestId) && M$7(e.viewport);
}
function isPreviewRenderedPayload(e) {
	if (!E$11(e) || !T$11(e, [
		`requestId`,
		`draftDigest`,
		`markers`,
		`markerMap`,
		`diagnostics`
	]) || !N$7(e.requestId) || typeof e.draftDigest != `string` || !i$14.test(e.draftDigest) || !F$7(e.markers, isPreviewMarker, 1e5) || new Set(e.markers).size !== e.markers.length || !I$5(e.diagnostics, S$12, 1e4) || !m$14(e.markerMap)) return !1;
	let t = e.markerMap;
	if (Object.keys(t).length !== e.markers.length) return !1;
	let n = Object.values(t);
	return new Set(n).size === n.length && e.markers.every((n, r) => {
		let i = a$12.exec(n);
		return i !== null && i[1] === e.draftDigest && Number(i[2]) === r && Object.hasOwn(t, n);
	});
}
function m$14(e) {
	if (!E$11(e)) return !1;
	let t = Object.entries(e);
	return t.length <= 1e5 && t.every(([e, t]) => isPreviewMarker(e) && N$7(t));
}
function h$12(e) {
	return T$11(e, [`requestId`, `markers`]) && N$7(e.requestId) && F$7(e.markers, isPreviewMarker, 1e3) && e.markers.length >= 1 && new Set(e.markers).size === e.markers.length;
}
function g$12(e) {
	if (!T$11(e, [
		`requestId`,
		`draftDigest`,
		`measurements`,
		`unknown`,
		`viewport`
	]) || !N$7(e.requestId) || typeof e.draftDigest != `string` || !i$14.test(e.draftDigest) || !_$12(e.measurements) || !F$7(e.unknown, isPreviewMarker, 1e3) || new Set(e.unknown).size !== e.unknown.length || !y$12(e.viewport)) return !1;
	let t = [...Object.keys(e.measurements), ...e.unknown];
	return new Set(t).size === t.length && t.every((t) => a$12.exec(t)?.[1] === e.draftDigest);
}
function _$12(e) {
	if (!E$11(e)) return !1;
	let t = Object.entries(e);
	return t.length <= 1e3 && t.every(([e, t]) => isPreviewMarker(e) && I$5(t, v$12, 1e3) && t.length >= 1);
}
function v$12(e) {
	return E$11(e) && T$11(e, [
		`x`,
		`y`,
		`width`,
		`height`
	]) && k$9(e.x) && k$9(e.y) && A$9(e.width) && A$9(e.height);
}
function y$12(e) {
	return E$11(e) && T$11(e, [
		`width`,
		`height`,
		`scrollX`,
		`scrollY`,
		`devicePixelRatio`
	]) && A$9(e.width) && A$9(e.height) && k$9(e.scrollX) && k$9(e.scrollY) && typeof e.devicePixelRatio == `number` && Number.isFinite(e.devicePixelRatio) && e.devicePixelRatio > 0 && e.devicePixelRatio <= 100;
}
function b$11(e) {
	return T$11(e, [`nodeId`], [`reveal`]) && N$7(e.nodeId) && (e.reveal === void 0 || typeof e.reveal == `boolean`);
}
function x$12(e) {
	return T$11(e, [
		`code`,
		`message`,
		`retryable`
	], [`correlationId`]) && j$9(e.code) && w$11(e.message) && typeof e.retryable == `boolean` && (e.correlationId === void 0 || N$7(e.correlationId));
}
function S$12(e) {
	return !E$11(e) || !T$11(e, [
		`code`,
		`severity`,
		`message`
	], [
		`location`,
		`parameters`,
		`remediations`
	]) || !j$9(e.code) || typeof e.severity != `string` || ![
		`information`,
		`warning`,
		`error`,
		`blocking`
	].includes(e.severity) || !w$11(e.message) || e.location !== void 0 && !C$12(e.location) || e.parameters !== void 0 && (!E$11(e.parameters) || Object.keys(e.parameters).length > 20 || !Object.keys(e.parameters).every((e) => R$5(e)) || !Object.values(e.parameters).every((e) => e === null || typeof e == `boolean` || typeof e == `string` || typeof e == `number` && Number.isFinite(e))) ? !1 : e.remediations === void 0 || F$7(e.remediations, j$9, 10);
}
function C$12(e) {
	return !E$11(e) || !T$11(e, [], [
		`artifactId`,
		`nodeId`,
		`fieldPath`,
		`jsonPointer`
	]) ? !1 : (e.artifactId === void 0 || N$7(e.artifactId)) && (e.nodeId === void 0 || N$7(e.nodeId)) && (e.fieldPath === void 0 || F$7(e.fieldPath, M$7, 32)) && (e.jsonPointer === void 0 || typeof e.jsonPointer == `string` && e.jsonPointer.length <= 1e3);
}
function w$11(e) {
	return E$11(e) && T$11(e, [`key`], [`defaultMessage`]) && j$9(e.key) && (e.defaultMessage === void 0 || typeof e.defaultMessage == `string` && e.defaultMessage.length > 0 && e.defaultMessage.length <= 500);
}
function T$11(e, t, n = []) {
	let r = /* @__PURE__ */ new Set([...t, ...n]);
	return t.every((t) => Object.hasOwn(e, t)) && Object.keys(e).every((e) => r.has(e));
}
function E$11(e) {
	if (typeof e != `object` || !e || Array.isArray(e)) return !1;
	let t = Object.getPrototypeOf(e);
	return t === Object.prototype || t === null;
}
function D$9(e) {
	return typeof e == `number` && Number.isSafeInteger(e) && e >= 0;
}
var O$10 = 1e8;
function k$9(e) {
	return typeof e == `number` && Number.isFinite(e) && Math.abs(e) <= O$10;
}
function A$9(e) {
	return typeof e == `number` && Number.isFinite(e) && e >= 0 && e <= O$10;
}
function j$9(e) {
	return typeof e == `string` && e.length <= 160 && n$6.test(e);
}
function M$7(e) {
	return typeof e == `string` && e.length <= 100 && !z$4(e) && r$10.test(e);
}
function N$7(e) {
	return typeof e == `string` && e.length <= 240 && !z$4(e) && /^[A-Za-z0-9][A-Za-z0-9._:/-]*$/u.test(e);
}
function isPreviewMarker(e, t) {
	if (typeof e != `string`) return !1;
	let n = a$12.exec(e);
	return n !== null && (t === void 0 || n[1] === t);
}
function P$7(e) {
	return typeof e == `string` && e.length >= 1 && e.length <= 200;
}
function F$7(e, t, n) {
	return I$5(e, t, n);
}
function I$5(e, t, n) {
	if (!Array.isArray(e) || e.length > n || !L$5(e)) return !1;
	for (let n of e) if (!t(n)) return !1;
	return !0;
}
function L$5(e) {
	if (Object.getPrototypeOf(e) !== Array.prototype || Object.getOwnPropertySymbols(e).length) return !1;
	let t = Object.getOwnPropertyNames(e);
	return t.length === e.length + 1 && t[e.length] === `length` && t.slice(0, -1).every((e, t) => e === String(t));
}
function R$5(e) {
	if (e.length === 0 || e.length > 200 || z$4(e)) return !1;
	for (let t = 0; t < e.length; t += 1) {
		let n = e.charCodeAt(t);
		if (n <= 31 || n === 127) return !1;
	}
	return !0;
}
function z$4(e) {
	return e === `__proto__` || e === `prototype` || e === `constructor`;
}
//#endregion
//#region node_modules/@kumwe/studio-protocol/dist/host-failure.js
var HostPortFailure = class extends Error {
	error;
	constructor(t) {
		if (!isHostPortError(t)) throw TypeError(`HostPortFailure requires a canonical HostPortError.`);
		super(t.message.defaultMessage ?? t.message.key), this.name = `HostPortFailure`, this.error = t;
	}
};
function isHostPortFailure(t) {
	return t instanceof Error && `error` in t && isHostPortError(t.error);
}
var authoring_http_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/authoring-http.schema.json",
	title: "Studio contextual authoring HTTP exchange",
	description: "The language-neutral HTTP/AJAX binding for one contextual authoring operation. Each exchange fixes the route, exact request argument, capability identifier, idempotency boundary, and normalized result without prescribing a server implementation language.",
	$ref: "#/$defs/exchange",
	$defs: /* @__PURE__ */ JSON.parse("{\"exchange\":{\"oneOf\":[{\"$ref\":\"#/$defs/resolveTargetExchange\"},{\"$ref\":\"#/$defs/listTypesExchange\"},{\"$ref\":\"#/$defs/startExchange\"},{\"$ref\":\"#/$defs/planSaveExchange\"},{\"$ref\":\"#/$defs/saveItemExchange\"},{\"$ref\":\"#/$defs/saveNewTypeVersionExchange\"},{\"$ref\":\"#/$defs/saveAsNewTypeExchange\"}]},\"resolveTargetExchange\":{\"$ref\":\"#/$defs/operationExchange\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"route\":{\"const\":\"authoring/resolve-target\"},\"request\":{\"$ref\":\"#/$defs/resolveTargetRequest\"},\"response\":{\"$ref\":\"#/$defs/resolveTargetResponse\"}}},\"listTypesExchange\":{\"$ref\":\"#/$defs/operationExchange\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"route\":{\"const\":\"authoring/list-types\"},\"request\":{\"$ref\":\"#/$defs/listTypesRequest\"},\"response\":{\"$ref\":\"#/$defs/listTypesResponse\"}}},\"startExchange\":{\"$ref\":\"#/$defs/operationExchange\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"route\":{\"const\":\"authoring/start\"},\"request\":{\"$ref\":\"#/$defs/startRequest\"},\"response\":{\"$ref\":\"#/$defs/startResponse\"}}},\"planSaveExchange\":{\"$ref\":\"#/$defs/operationExchange\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"route\":{\"const\":\"authoring/plan-save\"},\"request\":{\"$ref\":\"#/$defs/planSaveRequest\"},\"response\":{\"$ref\":\"#/$defs/planSaveResponse\"}}},\"saveItemExchange\":{\"$ref\":\"#/$defs/operationExchange\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"route\":{\"const\":\"authoring/save-item\"},\"request\":{\"$ref\":\"#/$defs/saveItemRequest\"},\"response\":{\"$ref\":\"#/$defs/saveItemResponse\"}}},\"saveNewTypeVersionExchange\":{\"$ref\":\"#/$defs/operationExchange\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"route\":{\"const\":\"authoring/save-new-type-version\"},\"request\":{\"$ref\":\"#/$defs/saveNewTypeVersionRequest\"},\"response\":{\"$ref\":\"#/$defs/saveNewTypeVersionResponse\"}}},\"saveAsNewTypeExchange\":{\"$ref\":\"#/$defs/operationExchange\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"route\":{\"const\":\"authoring/save-as-new-type\"},\"request\":{\"$ref\":\"#/$defs/saveAsNewTypeRequest\"},\"response\":{\"$ref\":\"#/$defs/saveAsNewTypeResponse\"}}},\"operationExchange\":{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"kind\",\"route\",\"request\",\"response\"],\"properties\":{\"kind\":{\"const\":\"authoring-http-exchange\"},\"route\":{\"type\":\"string\"},\"request\":true,\"response\":true}},\"resolveTargetRequest\":{\"allOf\":[{\"$ref\":\"host-request.schema.json\"},{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"arguments\",\"context\"],\"properties\":{\"arguments\":{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"request\"],\"properties\":{\"request\":{\"$ref\":\"authoring-target.schema.json#/$defs/resolveRequest\"}}},\"context\":{\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"operationId\":{\"const\":\"studio.operation/authoring.resolve-target\"},\"expectedRevision\":false,\"idempotencyKey\":false}}}}]},\"listTypesRequest\":{\"allOf\":[{\"$ref\":\"host-request.schema.json\"},{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"arguments\",\"context\"],\"properties\":{\"arguments\":{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"query\"],\"properties\":{\"query\":{\"$ref\":\"reusable-content-type.schema.json#/$defs/listQuery\"}}},\"context\":{\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"operationId\":{\"const\":\"studio.operation/authoring.list-types\"},\"expectedRevision\":false,\"idempotencyKey\":false}}}}]},\"startRequest\":{\"allOf\":[{\"$ref\":\"host-request.schema.json\"},{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"arguments\",\"context\"],\"properties\":{\"arguments\":{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"request\"],\"properties\":{\"request\":{\"$ref\":\"authoring-session.schema.json#/$defs/startRequest\"}}},\"context\":{\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"required\":[\"idempotencyKey\"],\"properties\":{\"idempotencyKey\":{\"$ref\":\"common.schema.json#/$defs/stableId\"},\"operationId\":{\"const\":\"studio.operation/authoring.start\"},\"expectedRevision\":false}}}}]},\"planSaveRequest\":{\"allOf\":[{\"$ref\":\"host-request.schema.json\"},{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"arguments\",\"context\"],\"properties\":{\"arguments\":{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"intent\"],\"properties\":{\"intent\":{\"$ref\":\"authoring-save.schema.json#/$defs/saveIntent\"}}},\"context\":{\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"operationId\":{\"const\":\"studio.operation/authoring.plan-save\"},\"expectedRevision\":false,\"idempotencyKey\":false}}}}]},\"saveItemRequest\":{\"allOf\":[{\"$ref\":\"host-request.schema.json\"},{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"arguments\",\"context\"],\"properties\":{\"arguments\":{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"request\"],\"properties\":{\"request\":{\"$ref\":\"authoring-save.schema.json#/$defs/saveItemRequest\"}}},\"context\":{\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"required\":[\"idempotencyKey\"],\"properties\":{\"idempotencyKey\":{\"$ref\":\"common.schema.json#/$defs/stableId\"},\"operationId\":{\"const\":\"studio.operation/authoring.save-item\"},\"expectedRevision\":false}}}}]},\"saveNewTypeVersionRequest\":{\"allOf\":[{\"$ref\":\"host-request.schema.json\"},{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"arguments\",\"context\"],\"properties\":{\"arguments\":{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"request\"],\"properties\":{\"request\":{\"$ref\":\"authoring-save.schema.json#/$defs/saveNewTypeVersionRequest\"}}},\"context\":{\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"required\":[\"idempotencyKey\"],\"properties\":{\"idempotencyKey\":{\"$ref\":\"common.schema.json#/$defs/stableId\"},\"operationId\":{\"const\":\"studio.operation/authoring.save-new-type-version\"},\"expectedRevision\":false}}}}]},\"saveAsNewTypeRequest\":{\"allOf\":[{\"$ref\":\"host-request.schema.json\"},{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"arguments\",\"context\"],\"properties\":{\"arguments\":{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"request\"],\"properties\":{\"request\":{\"$ref\":\"authoring-save.schema.json#/$defs/saveAsNewTypeRequest\"}}},\"context\":{\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"required\":[\"idempotencyKey\"],\"properties\":{\"idempotencyKey\":{\"$ref\":\"common.schema.json#/$defs/stableId\"},\"operationId\":{\"const\":\"studio.operation/authoring.save-as-new-type\"},\"expectedRevision\":false}}}}]},\"resolveTargetResult\":{\"$ref\":\"#/$defs/operationResult\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"value\":{\"$ref\":\"authoring-target.schema.json#/$defs/resolution\"}}},\"listTypesResult\":{\"$ref\":\"#/$defs/operationResult\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"value\":{\"$ref\":\"reusable-content-type.schema.json#/$defs/listPage\"}}},\"startResult\":{\"$ref\":\"#/$defs/operationResult\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"value\":{\"$ref\":\"authoring-session.schema.json#/$defs/snapshot\"}}},\"planSaveResult\":{\"$ref\":\"#/$defs/operationResult\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"value\":{\"$ref\":\"authoring-save.schema.json#/$defs/savePlan\"}}},\"saveItemResult\":{\"$ref\":\"#/$defs/operationResult\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"value\":{\"allOf\":[{\"$ref\":\"authoring-save.schema.json#/$defs/saveResult\"},{\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"outcome\":{\"const\":\"save-item\"}}}]}}},\"saveNewTypeVersionResult\":{\"$ref\":\"#/$defs/operationResult\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"value\":{\"allOf\":[{\"$ref\":\"authoring-save.schema.json#/$defs/saveResult\"},{\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"outcome\":{\"const\":\"save-new-type-version\"}}}]}}},\"saveAsNewTypeResult\":{\"$ref\":\"#/$defs/operationResult\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"value\":{\"allOf\":[{\"$ref\":\"authoring-save.schema.json#/$defs/saveResult\"},{\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"outcome\":{\"const\":\"save-as-new-type\"}}}]}}},\"operationResult\":{\"allOf\":[{\"$ref\":\"host-result.schema.json\"},{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"value\"],\"properties\":{\"revision\":false,\"value\":true}}]},\"resolveTargetResponse\":{\"oneOf\":[{\"$ref\":\"#/$defs/resolveTargetSuccessResponse\"},{\"$ref\":\"#/$defs/errorResponse\"}]},\"resolveTargetSuccessResponse\":{\"$ref\":\"#/$defs/successResponse\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"body\":{\"$ref\":\"#/$defs/resolveTargetResult\"}}},\"listTypesResponse\":{\"oneOf\":[{\"$ref\":\"#/$defs/listTypesSuccessResponse\"},{\"$ref\":\"#/$defs/errorResponse\"}]},\"listTypesSuccessResponse\":{\"$ref\":\"#/$defs/successResponse\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"body\":{\"$ref\":\"#/$defs/listTypesResult\"}}},\"startResponse\":{\"oneOf\":[{\"$ref\":\"#/$defs/startSuccessResponse\"},{\"$ref\":\"#/$defs/errorResponse\"}]},\"startSuccessResponse\":{\"$ref\":\"#/$defs/successResponse\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"body\":{\"$ref\":\"#/$defs/startResult\"}}},\"planSaveResponse\":{\"oneOf\":[{\"$ref\":\"#/$defs/planSaveSuccessResponse\"},{\"$ref\":\"#/$defs/errorResponse\"}]},\"planSaveSuccessResponse\":{\"$ref\":\"#/$defs/successResponse\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"body\":{\"$ref\":\"#/$defs/planSaveResult\"}}},\"saveItemResponse\":{\"oneOf\":[{\"$ref\":\"#/$defs/saveItemSuccessResponse\"},{\"$ref\":\"#/$defs/errorResponse\"}]},\"saveItemSuccessResponse\":{\"$ref\":\"#/$defs/successResponse\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"body\":{\"$ref\":\"#/$defs/saveItemResult\"}}},\"saveNewTypeVersionResponse\":{\"oneOf\":[{\"$ref\":\"#/$defs/saveNewTypeVersionSuccessResponse\"},{\"$ref\":\"#/$defs/errorResponse\"}]},\"saveNewTypeVersionSuccessResponse\":{\"$ref\":\"#/$defs/successResponse\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"body\":{\"$ref\":\"#/$defs/saveNewTypeVersionResult\"}}},\"saveAsNewTypeResponse\":{\"oneOf\":[{\"$ref\":\"#/$defs/saveAsNewTypeSuccessResponse\"},{\"$ref\":\"#/$defs/errorResponse\"}]},\"saveAsNewTypeSuccessResponse\":{\"$ref\":\"#/$defs/successResponse\",\"type\":\"object\",\"propertyNames\":{\"$ref\":\"common.schema.json#/$defs/safeJsonMemberName\"},\"properties\":{\"body\":{\"$ref\":\"#/$defs/saveAsNewTypeResult\"}}},\"successResponse\":{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"status\",\"body\"],\"properties\":{\"status\":{\"const\":200},\"body\":{\"$ref\":\"host-result.schema.json\"}}},\"errorResponse\":{\"oneOf\":[{\"type\":\"object\",\"additionalProperties\":false,\"required\":[\"status\",\"body\"],\"properties\":{\"status\":{\"enum\":[400,401,403,404,409,413,422,429,500,502,503,504]},\"body\":{\"$ref\":\"host-error.schema.json\"}}}]}}")
};
var authoring_http_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/authoring-http-vector.schema.json",
	title: "Studio contextual authoring HTTP conformance vector",
	description: "A language-neutral matrix for replaying the seven contextual authoring HTTP routes and their transport-security refusal boundaries in any host implementation.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"operations",
		"securityCases",
		"errorMappings",
		"successorContextCases"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "authoring-http-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"operations": {
			"type": "array",
			"minItems": 7,
			"maxItems": 7,
			"items": { "$ref": "#/$defs/operation" }
		},
		"securityCases": {
			"type": "array",
			"minItems": 7,
			"maxItems": 20,
			"items": { "$ref": "#/$defs/securityCase" }
		},
		"errorMappings": {
			"type": "array",
			"minItems": 12,
			"maxItems": 12,
			"items": { "$ref": "#/$defs/errorMapping" }
		},
		"successorContextCases": {
			"type": "array",
			"minItems": 2,
			"maxItems": 10,
			"uniqueItems": true,
			"items": { "$ref": "#/$defs/successorContextCase" }
		}
	},
	$defs: {
		"route": { "enum": [
			"authoring/resolve-target",
			"authoring/list-types",
			"authoring/start",
			"authoring/plan-save",
			"authoring/save-item",
			"authoring/save-new-type-version",
			"authoring/save-as-new-type"
		] },
		"errorCategory": { "enum": [
			"invalid-request",
			"unauthenticated",
			"forbidden",
			"not-found",
			"conflict",
			"validation-failed",
			"incompatible",
			"limit-exceeded",
			"rate-limited",
			"unavailable",
			"cancelled",
			"internal"
		] },
		"operation": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"route",
				"capability",
				"argumentMember",
				"requestSchema",
				"responseSchema",
				"mutating",
				"idempotencyKey",
				"expectedRevision",
				"resourceContextMatch",
				"successStatus"
			],
			"properties": {
				"route": { "$ref": "#/$defs/route" },
				"capability": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"argumentMember": { "enum": [
					"request",
					"query",
					"intent"
				] },
				"requestSchema": {
					"type": "string",
					"minLength": 1,
					"maxLength": 500
				},
				"responseSchema": {
					"type": "string",
					"minLength": 1,
					"maxLength": 500
				},
				"mutating": { "type": "boolean" },
				"idempotencyKey": { "enum": ["required", "forbidden"] },
				"expectedRevision": { "const": "forbidden" },
				"resourceContextMatch": { "type": "boolean" },
				"successStatus": { "const": 200 }
			}
		},
		"securityCase": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"authenticated",
				"requestIntegrity",
				"request",
				"expect"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/localName" },
				"authenticated": { "type": "boolean" },
				"requestIntegrity": { "type": "boolean" },
				"request": { "enum": [
					"valid",
					"malformed-json",
					"context-mismatch",
					"missing-idempotency-key",
					"wrong-operation-id",
					"host-conflict",
					"host-rate-limited",
					"host-internal-failure"
				] },
				"expect": { "oneOf": [{
					"type": "object",
					"additionalProperties": false,
					"required": [
						"outcome",
						"status",
						"dispatch"
					],
					"properties": {
						"outcome": { "const": "result" },
						"status": { "const": 200 },
						"dispatch": { "const": true }
					}
				}, {
					"type": "object",
					"additionalProperties": false,
					"required": [
						"outcome",
						"status",
						"category",
						"dispatch"
					],
					"properties": {
						"outcome": { "const": "error" },
						"status": {
							"type": "integer",
							"minimum": 400,
							"maximum": 599
						},
						"category": { "$ref": "#/$defs/errorCategory" },
						"dispatch": { "type": "boolean" }
					}
				}] }
			}
		},
		"errorMapping": {
			"type": "object",
			"additionalProperties": false,
			"required": ["category", "status"],
			"properties": {
				"category": { "$ref": "#/$defs/errorCategory" },
				"status": {
					"type": "integer",
					"minimum": 400,
					"maximum": 599
				}
			}
		},
		"successorContextCase": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"beforeContext",
				"planReference",
				"resultContext",
				"expect"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/localName" },
				"beforeContext": { "$ref": "common.schema.json#/$defs/returnContext" },
				"planReference": { "$ref": "authoring-save.schema.json#/$defs/planReference" },
				"resultContext": { "$ref": "common.schema.json#/$defs/returnContext" },
				"expect": { "oneOf": [{
					"type": "object",
					"additionalProperties": false,
					"required": ["outcome", "acceptedContext"],
					"properties": {
						"outcome": { "const": "accepted" },
						"acceptedContext": { "$ref": "common.schema.json#/$defs/returnContext" }
					}
				}, {
					"type": "object",
					"additionalProperties": false,
					"required": ["outcome", "code"],
					"properties": {
						"outcome": { "const": "rejected" },
						"code": { "const": "studio.host/unexpected-authoring-save-result" }
					}
				}] }
			}
		}
	}
};
var authoring_message_catalog_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/authoring-message-catalog.schema.json",
	title: "Studio authoring message catalog",
	type: "object",
	additionalProperties: false,
	required: [
		"$schema",
		"kind",
		"contractVersion",
		"catalogVersion",
		"locale",
		"messages"
	],
	properties: {
		"$schema": { "const": "https://schemas.kumwe.org/studio/v1/authoring-message-catalog.schema.json" },
		"kind": { "const": "authoring-message-catalog" },
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"catalogVersion": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"locale": { "$ref": "common.schema.json#/$defs/locale" },
		"messages": {
			"type": "object",
			"minProperties": 1,
			"maxProperties": 1e3,
			"propertyNames": { "$ref": "common.schema.json#/$defs/qualifiedName" },
			"additionalProperties": { "$ref": "#/$defs/message" }
		}
	},
	$defs: { "message": {
		"type": "object",
		"additionalProperties": false,
		"required": ["defaultMessage", "parameters"],
		"properties": {
			"defaultMessage": {
				"type": "string",
				"minLength": 1,
				"maxLength": 2e3,
				"pattern": "^[^<>]*(?![\\s\\S])"
			},
			"parameters": {
				"type": "array",
				"maxItems": 50,
				"uniqueItems": true,
				"items": { "$ref": "common.schema.json#/$defs/localName" }
			}
		}
	} }
};
var authoring_save_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/authoring-save.schema.json",
	title: "Studio contextual authoring save documents",
	description: "Host-authoritative planning, confirmation, and reconciliation documents for the three explicit contextual save outcomes.",
	oneOf: [
		{ "$ref": "#/$defs/saveIntent" },
		{ "$ref": "#/$defs/savePlan" },
		{ "$ref": "#/$defs/saveItemRequest" },
		{ "$ref": "#/$defs/saveNewTypeVersionRequest" },
		{ "$ref": "#/$defs/saveAsNewTypeRequest" },
		{ "$ref": "#/$defs/saveResult" }
	],
	$defs: {
		"artifactKind": { "enum": [
			"model",
			"blueprint",
			"entry",
			"reusable-content-type"
		] },
		"saveItemDraft": {
			"type": "object",
			"additionalProperties": false,
			"required": ["outcome", "entry"],
			"properties": {
				"outcome": { "const": "save-item" },
				"entry": { "$ref": "entry.schema.json" },
				"itemBlueprint": { "$ref": "blueprint.schema.json" }
			}
		},
		"saveNewTypeVersionDraft": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"outcome",
				"model",
				"blueprint"
			],
			"properties": {
				"outcome": { "const": "save-new-type-version" },
				"model": { "$ref": "content-model.schema.json" },
				"blueprint": { "$ref": "blueprint.schema.json" }
			}
		},
		"saveAsNewTypeDraft": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"outcome",
				"label",
				"authoringPolicy",
				"model",
				"blueprint"
			],
			"properties": {
				"outcome": { "const": "save-as-new-type" },
				"label": { "$ref": "common.schema.json#/$defs/messageReference" },
				"authoringPolicy": { "$ref": "reusable-content-type.schema.json#/$defs/authoringPolicy" },
				"model": { "$ref": "content-model.schema.json" },
				"blueprint": { "$ref": "blueprint.schema.json" }
			}
		},
		"saveDraft": { "oneOf": [
			{ "$ref": "#/$defs/saveItemDraft" },
			{ "$ref": "#/$defs/saveNewTypeVersionDraft" },
			{ "$ref": "#/$defs/saveAsNewTypeDraft" }
		] },
		"saveIntent": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"contractVersion",
				"kind",
				"sessionId",
				"expected",
				"draft"
			],
			"properties": {
				"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
				"kind": { "const": "authoring-save-intent" },
				"sessionId": { "$ref": "common.schema.json#/$defs/stableId" },
				"expected": { "$ref": "authoring-session.schema.json#/$defs/artifactCoordinates" },
				"draft": { "$ref": "#/$defs/saveDraft" }
			}
		},
		"planReference": {
			"description": "The exact reviewed plan identity and host-minted return context adopted only after that plan is accepted.",
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"revision",
				"successorContext"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"revision": { "$ref": "common.schema.json#/$defs/revision" },
				"successorContext": {
					"description": "The exact host return context that the accepted result session must carry.",
					"$ref": "common.schema.json#/$defs/returnContext"
				}
			}
		},
		"savePlan": {
			"description": "A host-reviewed save transaction whose successorContext binds post-acceptance return navigation without granting authority.",
			"type": "object",
			"additionalProperties": false,
			"required": [
				"contractVersion",
				"kind",
				"id",
				"revision",
				"successorContext",
				"sessionId",
				"outcome",
				"expected",
				"affectedArtifacts",
				"consequences",
				"confirmationRequired"
			],
			"properties": {
				"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
				"kind": { "const": "authoring-save-plan" },
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"revision": { "$ref": "common.schema.json#/$defs/revision" },
				"successorContext": {
					"description": "The exact host return context copied into the request/result planReference and adopted after acceptance.",
					"$ref": "common.schema.json#/$defs/returnContext"
				},
				"sessionId": { "$ref": "common.schema.json#/$defs/stableId" },
				"outcome": { "$ref": "authoring-target.schema.json#/$defs/saveOutcome" },
				"expected": { "$ref": "authoring-session.schema.json#/$defs/artifactCoordinates" },
				"affectedArtifacts": {
					"type": "array",
					"minItems": 1,
					"maxItems": 4,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/artifactKind" }
				},
				"consequences": {
					"type": "array",
					"maxItems": 100,
					"items": { "$ref": "common.schema.json#/$defs/diagnostic" }
				},
				"confirmationRequired": { "type": "boolean" }
			}
		},
		"acceptedConsequences": {
			"type": "array",
			"maxItems": 100,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
		},
		"saveItemRequest": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"contractVersion",
				"kind",
				"plan",
				"acceptedConsequences",
				"draft"
			],
			"properties": {
				"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
				"kind": { "const": "authoring-save-item-request" },
				"plan": { "$ref": "#/$defs/planReference" },
				"acceptedConsequences": { "$ref": "#/$defs/acceptedConsequences" },
				"draft": { "$ref": "#/$defs/saveItemDraft" }
			}
		},
		"saveNewTypeVersionRequest": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"contractVersion",
				"kind",
				"plan",
				"acceptedConsequences",
				"draft"
			],
			"properties": {
				"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
				"kind": { "const": "authoring-save-new-type-version-request" },
				"plan": { "$ref": "#/$defs/planReference" },
				"acceptedConsequences": { "$ref": "#/$defs/acceptedConsequences" },
				"draft": { "$ref": "#/$defs/saveNewTypeVersionDraft" }
			}
		},
		"saveAsNewTypeRequest": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"contractVersion",
				"kind",
				"plan",
				"acceptedConsequences",
				"draft"
			],
			"properties": {
				"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
				"kind": { "const": "authoring-save-as-new-type-request" },
				"plan": { "$ref": "#/$defs/planReference" },
				"acceptedConsequences": { "$ref": "#/$defs/acceptedConsequences" },
				"draft": { "$ref": "#/$defs/saveAsNewTypeDraft" }
			}
		},
		"saveResult": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"contractVersion",
				"kind",
				"outcome",
				"plan",
				"session"
			],
			"properties": {
				"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
				"kind": { "const": "authoring-save-result" },
				"outcome": { "$ref": "authoring-target.schema.json#/$defs/saveOutcome" },
				"plan": { "$ref": "#/$defs/planReference" },
				"session": { "$ref": "authoring-session.schema.json#/$defs/snapshot" }
			}
		}
	}
};
var authoring_session_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/authoring-session.schema.json",
	title: "Studio coordinated contextual authoring session",
	description: "One resource-bound authoring snapshot with separately identified Model, Blueprint, and Entry documents and exact host-accepted coordinates.",
	$ref: "#/$defs/snapshot",
	$defs: {
		"startSource": { "oneOf": [
			{
				"type": "object",
				"additionalProperties": false,
				"required": ["kind"],
				"properties": { "kind": { "const": "blank" } }
			},
			{
				"type": "object",
				"additionalProperties": false,
				"required": ["kind", "type"],
				"properties": {
					"kind": { "const": "from-type" },
					"type": { "$ref": "reusable-content-type.schema.json#/$defs/reference" }
				}
			},
			{
				"type": "object",
				"additionalProperties": false,
				"required": ["kind"],
				"properties": { "kind": { "const": "existing" } }
			}
		] },
		"startRequest": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"targetId",
				"resourceContext",
				"source"
			],
			"properties": {
				"targetId": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"resourceContext": { "$ref": "common.schema.json#/$defs/resourceContext" },
				"source": { "$ref": "#/$defs/startSource" },
				"presentation": { "$ref": "authoring-target.schema.json#/$defs/presentationState" }
			}
		},
		"artifactCoordinates": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"model",
				"blueprint",
				"entry"
			],
			"properties": {
				"type": { "$ref": "reusable-content-type.schema.json#/$defs/reference" },
				"model": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
				"blueprint": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
				"entry": { "$ref": "common.schema.json#/$defs/resolvedEntryReference" }
			}
		},
		"artifactState": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"coordinates",
				"model",
				"blueprint",
				"entry",
				"dirty",
				"diagnostics"
			],
			"properties": {
				"coordinates": { "$ref": "#/$defs/artifactCoordinates" },
				"model": { "$ref": "content-model.schema.json" },
				"blueprint": { "$ref": "blueprint.schema.json" },
				"entry": { "$ref": "entry.schema.json" },
				"dirty": {
					"type": "array",
					"maxItems": 3,
					"uniqueItems": true,
					"items": { "enum": [
						"model",
						"blueprint",
						"entry"
					] }
				},
				"diagnostics": {
					"type": "array",
					"maxItems": 1e3,
					"items": { "$ref": "common.schema.json#/$defs/diagnostic" }
				}
			}
		},
		"capabilities": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"modes",
				"presentationStates",
				"saveOutcomes"
			],
			"properties": {
				"modes": {
					"type": "array",
					"minItems": 1,
					"maxItems": 3,
					"uniqueItems": true,
					"items": { "enum": [
						"model",
						"blueprint",
						"content"
					] }
				},
				"presentationStates": {
					"type": "array",
					"minItems": 1,
					"maxItems": 4,
					"uniqueItems": true,
					"items": { "$ref": "authoring-target.schema.json#/$defs/presentationState" }
				},
				"saveOutcomes": {
					"type": "array",
					"minItems": 1,
					"maxItems": 3,
					"uniqueItems": true,
					"items": { "$ref": "authoring-target.schema.json#/$defs/saveOutcome" }
				}
			}
		},
		"presentation": {
			"type": "object",
			"additionalProperties": false,
			"required": ["current"],
			"properties": {
				"current": { "$ref": "authoring-target.schema.json#/$defs/presentationState" },
				"returnContext": { "$ref": "common.schema.json#/$defs/returnContext" }
			}
		},
		"snapshot": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"contractVersion",
				"kind",
				"sessionId",
				"sessionGeneration",
				"target",
				"resourceContext",
				"start",
				"state",
				"capabilities",
				"presentation",
				"contributionGeneration"
			],
			"properties": {
				"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
				"kind": { "const": "authoring-session" },
				"sessionId": { "$ref": "common.schema.json#/$defs/stableId" },
				"sessionGeneration": { "$ref": "common.schema.json#/$defs/revision" },
				"target": { "$ref": "authoring-target.schema.json#/$defs/declaration" },
				"resourceContext": { "$ref": "common.schema.json#/$defs/resourceContext" },
				"start": { "$ref": "#/$defs/startSource" },
				"type": { "$ref": "reusable-content-type.schema.json#/$defs/definition" },
				"state": { "$ref": "#/$defs/artifactState" },
				"capabilities": { "$ref": "#/$defs/capabilities" },
				"presentation": { "$ref": "#/$defs/presentation" },
				"contributionGeneration": { "$ref": "common.schema.json#/$defs/revision" },
				"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
			}
		}
	}
};
var authoring_target_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/authoring-target.schema.json",
	title: "Studio contextual authoring target declaration",
	description: "A bounded host-core or extension declaration that makes one class of host resources eligible for contextual Studio authoring. Discovery never grants authority.",
	$ref: "#/$defs/declaration",
	$defs: {
		"presentationState": { "enum": [
			"inline",
			"minimized",
			"maximized",
			"fullscreen"
		] },
		"saveOutcome": { "enum": [
			"save-item",
			"save-new-type-version",
			"save-as-new-type"
		] },
		"startKind": { "enum": [
			"blank",
			"from-type",
			"existing"
		] },
		"eligibility": { "enum": ["create", "edit"] },
		"capabilityRequirement": {
			"type": "object",
			"additionalProperties": false,
			"required": ["id", "versions"],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"versions": { "$ref": "common.schema.json#/$defs/versionRange" }
			}
		},
		"contributionDependency": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"id",
				"versions",
				"required"
			],
			"properties": {
				"kind": { "enum": [
					"block-definition",
					"pattern",
					"field-adapter",
					"inspector",
					"design-vocabulary",
					"migration"
				] },
				"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"versions": { "$ref": "common.schema.json#/$defs/versionRange" },
				"required": { "type": "boolean" }
			}
		},
		"declaration": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"contractVersion",
				"kind",
				"id",
				"owner",
				"label",
				"surface",
				"resourceTypes",
				"eligibility",
				"modes",
				"startKinds",
				"presentationStates",
				"saveOutcomes",
				"requiredCapabilities",
				"contributionDependencies"
			],
			"properties": {
				"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
				"kind": { "const": "authoring-target" },
				"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
				"label": { "$ref": "common.schema.json#/$defs/messageReference" },
				"surface": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"resourceTypes": {
					"type": "array",
					"minItems": 1,
					"maxItems": 100,
					"uniqueItems": true,
					"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
				},
				"eligibility": {
					"type": "array",
					"minItems": 1,
					"maxItems": 2,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/eligibility" }
				},
				"modes": {
					"type": "array",
					"minItems": 1,
					"maxItems": 3,
					"uniqueItems": true,
					"items": { "enum": [
						"model",
						"blueprint",
						"content"
					] }
				},
				"startKinds": {
					"type": "array",
					"minItems": 1,
					"maxItems": 3,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/startKind" }
				},
				"presentationStates": {
					"type": "array",
					"minItems": 1,
					"maxItems": 4,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/presentationState" }
				},
				"saveOutcomes": {
					"type": "array",
					"minItems": 1,
					"maxItems": 3,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/saveOutcome" }
				},
				"requiredCapabilities": {
					"type": "array",
					"maxItems": 100,
					"items": { "$ref": "#/$defs/capabilityRequirement" }
				},
				"contributionDependencies": {
					"type": "array",
					"maxItems": 500,
					"items": { "$ref": "#/$defs/contributionDependency" }
				},
				"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
			}
		},
		"resolveRequest": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"targetId",
				"intent",
				"resourceContext"
			],
			"properties": {
				"targetId": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"intent": { "$ref": "#/$defs/eligibility" },
				"resourceContext": { "$ref": "common.schema.json#/$defs/resourceContext" },
				"requestedPresentation": { "$ref": "#/$defs/presentationState" }
			}
		},
		"resolution": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"target",
				"resourceContext",
				"availableStarts",
				"initialPresentation"
			],
			"properties": {
				"target": { "$ref": "#/$defs/declaration" },
				"resourceContext": { "$ref": "common.schema.json#/$defs/resourceContext" },
				"availableStarts": {
					"type": "array",
					"minItems": 1,
					"maxItems": 3,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/startKind" }
				},
				"initialPresentation": { "$ref": "#/$defs/presentationState" },
				"returnContext": { "$ref": "common.schema.json#/$defs/returnContext" }
			}
		}
	}
};
var block_definition_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/block-definition.schema.json",
	title: "Studio block definition",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"type",
		"version",
		"revision",
		"owner",
		"label",
		"category",
		"propertySchema",
		"slots",
		"ports",
		"editingModes",
		"themeControls",
		"rendererRequirements",
		"accessibility"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "block-definition" },
		"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"revision": { "$ref": "common.schema.json#/$defs/revision" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"category": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"icon": { "oneOf": [{
			"type": "object",
			"additionalProperties": false,
			"required": ["kind", "value"],
			"properties": {
				"kind": { "const": "symbol" },
				"value": { "oneOf": [{ "$ref": "common.schema.json#/$defs/localName" }, { "$ref": "common.schema.json#/$defs/qualifiedName" }] }
			}
		}, {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"path",
				"integrity"
			],
			"properties": {
				"kind": { "const": "asset" },
				"path": { "$ref": "common.schema.json#/$defs/packageRelativePath" },
				"integrity": { "$ref": "common.schema.json#/$defs/integrity" }
			}
		}] },
		"propertySchema": {
			"type": "object",
			"minProperties": 1,
			"maxProperties": 500,
			"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" }
		},
		"propertyControls": {
			"type": "array",
			"maxItems": 500,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": ["property", "control"],
				"properties": {
					"property": { "$ref": "common.schema.json#/$defs/localName" },
					"control": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"label": { "$ref": "common.schema.json#/$defs/messageReference" },
					"help": { "$ref": "common.schema.json#/$defs/messageReference" }
				}
			}
		},
		"slots": {
			"type": "array",
			"maxItems": 100,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"label",
					"minimum",
					"maximum",
					"ordered",
					"accepts"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/localName" },
					"label": { "$ref": "common.schema.json#/$defs/messageReference" },
					"minimum": {
						"type": "integer",
						"minimum": 0,
						"maximum": 1e4
					},
					"maximum": {
						"type": "integer",
						"minimum": 0,
						"maximum": 1e4
					},
					"ordered": { "type": "boolean" },
					"accepts": {
						"type": "object",
						"additionalProperties": false,
						"required": ["types"],
						"properties": { "types": {
							"type": "array",
							"minItems": 1,
							"maxItems": 1e3,
							"uniqueItems": true,
							"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
						} }
					}
				}
			}
		},
		"ports": {
			"type": "array",
			"maxItems": 100,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"label",
					"valueType",
					"required",
					"multiple"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/localName" },
					"label": { "$ref": "common.schema.json#/$defs/messageReference" },
					"valueType": { "oneOf": [{ "enum": [
						"text",
						"rich-text",
						"boolean",
						"integer",
						"decimal",
						"money",
						"date",
						"date-time",
						"media",
						"resource"
					] }, { "$ref": "common.schema.json#/$defs/qualifiedName" }] },
					"required": { "type": "boolean" },
					"multiple": { "type": "boolean" },
					"authoring": {
						"type": "object",
						"additionalProperties": false,
						"properties": {
							"control": { "$ref": "common.schema.json#/$defs/qualifiedName" },
							"profile": { "$ref": "common.schema.json#/$defs/qualifiedName" },
							"readOnly": { "type": "boolean" }
						}
					}
				}
			}
		},
		"editingModes": {
			"type": "array",
			"minItems": 1,
			"maxItems": 2,
			"uniqueItems": true,
			"items": { "enum": ["blueprint", "content"] }
		},
		"themeControls": {
			"type": "array",
			"maxItems": 100,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/localName" }
		},
		"rendererRequirements": {
			"type": "array",
			"minItems": 1,
			"maxItems": 100,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"surface",
					"capability",
					"versions"
				],
				"properties": {
					"surface": { "enum": [
						"web",
						"email",
						"document",
						"native",
						"preview"
					] },
					"capability": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"versions": { "$ref": "common.schema.json#/$defs/versionRange" }
				}
			}
		},
		"accessibility": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"category",
				"accessibleName",
				"keyboard",
				"reducedMotion",
				"outputChecks"
			],
			"properties": {
				"category": { "enum": [
					"structural",
					"landmark",
					"interactive",
					"media",
					"text",
					"decorative",
					"data-display",
					"composite"
				] },
				"accessibleName": { "enum": [
					"not-applicable",
					"required-property",
					"required-binding",
					"derived"
				] },
				"keyboard": { "$ref": "common.schema.json#/$defs/messageReference" },
				"reducedMotion": { "enum": [
					"not-applicable",
					"required",
					"disable-motion"
				] },
				"outputChecks": {
					"type": "array",
					"minItems": 1,
					"maxItems": 100,
					"uniqueItems": true,
					"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
				}
			}
		},
		"fallback": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"type",
				"versions",
				"lossless"
			],
			"properties": {
				"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"versions": { "$ref": "common.schema.json#/$defs/versionRange" },
				"lossless": {
					"type": "boolean",
					"const": true
				}
			}
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var binding_projection_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/binding-projection-vector.schema.json",
	title: "Studio field-binding projection conformance vector",
	description: "One complete Blueprint, exact host-projected content model, locked block definitions, and the normalized field-binding affordances and diagnostics every implementation must reproduce.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"profile",
		"blueprint",
		"model",
		"blockDefinitions",
		"expect"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "binding-projection-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 1e3
		},
		"profile": { "const": "studio.profile/binding-projection-v1" },
		"blueprint": { "$ref": "blueprint.schema.json" },
		"model": { "$ref": "content-model.schema.json" },
		"blockDefinitions": {
			"type": "array",
			"minItems": 1,
			"maxItems": 1e3,
			"items": { "$ref": "block-definition.schema.json" }
		},
		"expect": { "$ref": "#/$defs/projection" }
	},
	$defs: {
		"fieldPath": {
			"type": "array",
			"minItems": 1,
			"maxItems": 32,
			"items": { "$ref": "common.schema.json#/$defs/localName" }
		},
		"candidate": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"fieldPath",
				"kind",
				"cardinality"
			],
			"properties": {
				"fieldPath": { "$ref": "#/$defs/fieldPath" },
				"kind": { "$ref": "content-model.schema.json#/$defs/field/properties/kind" },
				"itemKind": { "$ref": "content-model.schema.json#/$defs/field/properties/itemKind" },
				"cardinality": { "enum": ["one", "many"] },
				"control": { "$ref": "common.schema.json#/$defs/qualifiedName" }
			}
		},
		"port": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"port",
				"status",
				"candidates"
			],
			"properties": {
				"port": { "$ref": "common.schema.json#/$defs/localName" },
				"status": { "enum": [
					"invalid",
					"non-field-source",
					"resolved",
					"unbound"
				] },
				"bindingSourceKind": { "enum": [
					"context-value",
					"entry-field",
					"query-reference",
					"resource-reference",
					"static-value"
				] },
				"boundFieldPath": { "$ref": "#/$defs/fieldPath" },
				"candidates": {
					"type": "array",
					"maxItems": 1e3,
					"items": { "$ref": "#/$defs/candidate" }
				},
				"multiple": { "type": "boolean" },
				"required": { "type": "boolean" },
				"valueType": {
					"type": "string",
					"minLength": 1,
					"maxLength": 200
				}
			}
		},
		"node": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId", "ports"],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"ports": {
					"type": "array",
					"maxItems": 100,
					"items": { "$ref": "#/$defs/port" }
				}
			}
		},
		"diagnostic": {
			"type": "object",
			"additionalProperties": false,
			"required": ["code", "severity"],
			"properties": {
				"code": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"severity": { "enum": [
					"information",
					"warning",
					"error",
					"blocking"
				] },
				"location": { "$ref": "common.schema.json#/$defs/diagnosticLocation" }
			}
		},
		"projection": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"model",
				"nodes",
				"diagnostics"
			],
			"properties": {
				"model": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
				"nodes": {
					"type": "array",
					"maxItems": 1e5,
					"items": { "$ref": "#/$defs/node" }
				},
				"diagnostics": {
					"type": "array",
					"maxItems": 1e5,
					"items": { "$ref": "#/$defs/diagnostic" }
				}
			}
		}
	}
};
var authoring_web_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/authoring-web-vector.schema.json",
	title: "Studio portable web-authoring conformance vector",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"requirements",
		"given",
		"lanes",
		"expect"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "authoring-web-vector" },
		"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 1e3
		},
		"requirements": {
			"type": "array",
			"minItems": 1,
			"maxItems": 100,
			"uniqueItems": true,
			"items": {
				"type": "string",
				"pattern": "^SR-[0-9]{3}$"
			}
		},
		"given": { "$ref": "#/$defs/given" },
		"lanes": {
			"type": "array",
			"minItems": 1,
			"maxItems": 20,
			"items": { "$ref": "#/$defs/lane" }
		},
		"expect": { "$ref": "#/$defs/observation" }
	},
	$defs: {
		"region": { "enum": [
			"canvas",
			"command-palette",
			"inspector",
			"outline",
			"palette",
			"preview",
			"viewport"
		] },
		"given": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"document",
				"direction",
				"locale",
				"readOnly",
				"reducedMotion",
				"selection",
				"viewport"
			],
			"properties": {
				"document": { "$ref": "blueprint.schema.json" },
				"direction": { "enum": ["ltr", "rtl"] },
				"locale": { "$ref": "common.schema.json#/$defs/locale" },
				"readOnly": { "type": "boolean" },
				"reducedMotion": { "type": "boolean" },
				"selection": { "oneOf": [{ "$ref": "common.schema.json#/$defs/stableId" }, { "type": "null" }] },
				"viewport": {
					"type": "object",
					"additionalProperties": false,
					"required": [
						"height",
						"width",
						"zoomPercent"
					],
					"properties": {
						"height": {
							"type": "integer",
							"minimum": 256,
							"maximum": 1e4
						},
						"width": {
							"type": "integer",
							"minimum": 320,
							"maximum": 1e4
						},
						"zoomPercent": {
							"type": "integer",
							"minimum": 100,
							"maximum": 400
						}
					}
				}
			}
		},
		"lane": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"name",
				"surface",
				"steps"
			],
			"properties": {
				"name": { "$ref": "common.schema.json#/$defs/localName" },
				"surface": { "enum": [
					"keyboard",
					"pointer",
					"structural-control"
				] },
				"steps": {
					"type": "array",
					"minItems": 1,
					"maxItems": 100,
					"items": { "$ref": "#/$defs/action" }
				}
			}
		},
		"action": { "oneOf": [
			{ "$ref": "#/$defs/focusNode" },
			{ "$ref": "#/$defs/keyAction" },
			{ "$ref": "#/$defs/dragNode" },
			{ "$ref": "#/$defs/activateCommand" },
			{ "$ref": "#/$defs/editProperty" }
		] },
		"focusNode": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"nodeId",
				"region"
			],
			"properties": {
				"kind": { "const": "focus-node" },
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"region": { "$ref": "#/$defs/region" }
			}
		},
		"keyAction": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"key",
				"modifiers",
				"region"
			],
			"properties": {
				"kind": { "const": "key" },
				"key": { "enum": [
					"ArrowDown",
					"ArrowLeft",
					"ArrowRight",
					"ArrowUp",
					"Delete",
					"End",
					"Enter",
					"Escape",
					"Home",
					"Tab",
					"d",
					"y",
					"z"
				] },
				"modifiers": {
					"type": "array",
					"maxItems": 4,
					"uniqueItems": true,
					"items": { "enum": [
						"Alt",
						"Control",
						"Meta",
						"Shift"
					] }
				},
				"region": { "$ref": "#/$defs/region" }
			}
		},
		"dragNode": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"nodeId",
				"destination"
			],
			"properties": {
				"kind": { "const": "drag-node" },
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"destination": { "$ref": "command.schema.json#/$defs/destination" }
			}
		},
		"activateCommand": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"region",
				"type",
				"payload"
			],
			"properties": {
				"kind": { "const": "activate-command" },
				"region": { "$ref": "#/$defs/region" },
				"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"payload": { "$ref": "#/$defs/jsonObject" }
			}
		},
		"editProperty": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"nodeId",
				"property",
				"value"
			],
			"properties": {
				"kind": { "const": "edit-property" },
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"property": { "$ref": "common.schema.json#/$defs/localName" },
				"viewport": { "$ref": "common.schema.json#/$defs/localName" },
				"value": { "$ref": "common.schema.json#/$defs/jsonValue" }
			}
		},
		"jsonObject": {
			"type": "object",
			"maxProperties": 1e3,
			"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
			"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
		},
		"observation": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"announcements",
				"commands",
				"dirty",
				"document",
				"focus",
				"selection"
			],
			"properties": {
				"announcements": {
					"type": "array",
					"maxItems": 100,
					"items": {
						"type": "object",
						"additionalProperties": false,
						"required": ["key", "politeness"],
						"properties": {
							"key": { "$ref": "common.schema.json#/$defs/qualifiedName" },
							"politeness": { "enum": ["assertive", "polite"] }
						}
					}
				},
				"commands": {
					"type": "array",
					"maxItems": 100,
					"items": {
						"type": "object",
						"additionalProperties": false,
						"required": ["type", "payload"],
						"properties": {
							"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
							"payload": { "$ref": "#/$defs/jsonObject" }
						}
					}
				},
				"dirty": { "type": "boolean" },
				"document": { "$ref": "blueprint.schema.json" },
				"focus": {
					"type": "object",
					"additionalProperties": false,
					"required": ["region"],
					"properties": {
						"region": { "$ref": "#/$defs/region" },
						"nodeId": { "$ref": "common.schema.json#/$defs/stableId" }
					}
				},
				"selection": { "oneOf": [{ "$ref": "common.schema.json#/$defs/stableId" }, { "type": "null" }] }
			}
		}
	}
};
var blueprint_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/blueprint.schema.json",
	title: "Studio Blueprint",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"revision",
		"owner",
		"status",
		"label",
		"model",
		"dependencyLock",
		"roots"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "blueprint" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"revision": { "$ref": "common.schema.json#/$defs/revision" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"status": { "enum": [
			"draft",
			"published",
			"retired"
		] },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"model": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
		"dependencyLock": {
			"type": "object",
			"additionalProperties": false,
			"required": ["theme", "blocks"],
			"properties": {
				"theme": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
				"blocks": {
					"type": "array",
					"maxItems": 1e3,
					"items": {
						"type": "object",
						"additionalProperties": false,
						"required": [
							"type",
							"version",
							"revision"
						],
						"properties": {
							"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
							"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
							"revision": { "$ref": "common.schema.json#/$defs/revision" },
							"integrity": { "$ref": "common.schema.json#/$defs/integrity" }
						}
					}
				},
				"plugins": {
					"type": "array",
					"maxItems": 100,
					"items": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" }
				}
			}
		},
		"roots": {
			"type": "array",
			"minItems": 0,
			"maxItems": 1e4,
			"items": { "$ref": "#/$defs/node" }
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	},
	allOf: [{
		"if": {
			"properties": { "status": { "const": "published" } },
			"required": ["status"]
		},
		"then": { "properties": { "roots": {
			"type": "array",
			"minItems": 1
		} } }
	}],
	$defs: {
		"sizeRoleAxis": { "enum": ["inline", "block"] },
		"node": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"type",
				"version",
				"properties",
				"bindings",
				"slots",
				"authoring"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"properties": {
					"type": "object",
					"maxProperties": 500,
					"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
					"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
				},
				"bindings": {
					"type": "object",
					"maxProperties": 100,
					"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
					"additionalProperties": { "$ref": "#/$defs/binding" }
				},
				"slots": {
					"type": "object",
					"maxProperties": 100,
					"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
					"additionalProperties": {
						"type": "array",
						"maxItems": 1e4,
						"items": { "$ref": "#/$defs/node" }
					}
				},
				"responsive": {
					"type": "object",
					"maxProperties": 100,
					"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
					"additionalProperties": {
						"type": "object",
						"maxProperties": 20,
						"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
						"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
					}
				},
				"sizeRoles": {
					"type": "object",
					"maxProperties": 2,
					"propertyNames": { "$ref": "#/$defs/sizeRoleAxis" },
					"additionalProperties": { "$ref": "common.schema.json#/$defs/localName" }
				},
				"responsiveSizeRoles": {
					"type": "object",
					"maxProperties": 2,
					"propertyNames": { "$ref": "#/$defs/sizeRoleAxis" },
					"additionalProperties": {
						"type": "object",
						"maxProperties": 20,
						"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
						"additionalProperties": { "$ref": "common.schema.json#/$defs/localName" }
					}
				},
				"authoring": {
					"type": "object",
					"additionalProperties": false,
					"required": ["mode"],
					"properties": {
						"mode": { "enum": [
							"locked",
							"content",
							"variant",
							"structural",
							"designer"
						] },
						"allowedBlocks": {
							"type": "array",
							"maxItems": 1e3,
							"uniqueItems": true,
							"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
						},
						"requiredPermission": { "$ref": "common.schema.json#/$defs/qualifiedName" },
						"slots": {
							"type": "object",
							"maxProperties": 100,
							"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
							"additionalProperties": {
								"type": "object",
								"additionalProperties": false,
								"required": ["composable"],
								"properties": {
									"composable": { "const": true },
									"allowedBlocks": {
										"type": "array",
										"maxItems": 1e3,
										"uniqueItems": true,
										"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
									}
								}
							}
						}
					}
				},
				"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
			}
		},
		"binding": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"source",
				"transforms",
				"onNull",
				"onError"
			],
			"properties": {
				"source": { "$ref": "#/$defs/bindingSource" },
				"transforms": {
					"type": "array",
					"maxItems": 20,
					"items": { "$ref": "#/$defs/transform" }
				},
				"onNull": { "enum": [
					"empty",
					"hide",
					"fallback",
					"error"
				] },
				"onError": { "enum": [
					"hide",
					"fallback",
					"error"
				] },
				"fallback": { "$ref": "common.schema.json#/$defs/jsonValue" }
			}
		},
		"bindingSource": { "oneOf": [
			{
				"type": "object",
				"additionalProperties": false,
				"required": ["kind", "fieldPath"],
				"properties": {
					"kind": { "const": "entry-field" },
					"fieldPath": {
						"type": "array",
						"minItems": 1,
						"maxItems": 32,
						"items": { "$ref": "common.schema.json#/$defs/localName" }
					}
				}
			},
			{
				"type": "object",
				"additionalProperties": false,
				"required": ["kind", "key"],
				"properties": {
					"kind": { "const": "context-value" },
					"key": { "$ref": "common.schema.json#/$defs/qualifiedName" }
				}
			},
			{
				"type": "object",
				"additionalProperties": false,
				"required": ["kind", "value"],
				"properties": {
					"kind": { "const": "static-value" },
					"value": { "$ref": "common.schema.json#/$defs/jsonValue" }
				}
			},
			{
				"type": "object",
				"additionalProperties": false,
				"required": [
					"kind",
					"resourceType",
					"id"
				],
				"properties": {
					"kind": { "const": "resource-reference" },
					"resourceType": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"id": { "$ref": "common.schema.json#/$defs/stableId" }
				}
			},
			{
				"type": "object",
				"additionalProperties": false,
				"required": [
					"kind",
					"query",
					"version",
					"parameters"
				],
				"properties": {
					"kind": { "const": "query-reference" },
					"query": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
					"parameters": {
						"type": "object",
						"maxProperties": 50,
						"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
						"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
					}
				}
			}
		] },
		"transform": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"operator",
				"version",
				"arguments"
			],
			"properties": {
				"operator": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"arguments": {
					"type": "object",
					"maxProperties": 20,
					"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
					"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
				}
			}
		}
	}
};
var command_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/command.schema.json",
	title: "Studio command",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"type",
		"artifactId",
		"sessionGeneration",
		"baseStateVersion",
		"payload"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "command" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"artifactId": { "$ref": "common.schema.json#/$defs/stableId" },
		"expectedRevision": { "$ref": "common.schema.json#/$defs/revision" },
		"sessionGeneration": { "$ref": "common.schema.json#/$defs/revision" },
		"baseStateVersion": {
			"type": "integer",
			"minimum": 0
		},
		"groupId": { "$ref": "common.schema.json#/$defs/stableId" },
		"payload": {
			"type": "object",
			"maxProperties": 1e3,
			"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
			"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
		}
	},
	allOf: [
		{
			"if": { "properties": { "type": { "const": "studio.command/insert-node" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/insertNode" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/remove-node" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/removeNode" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/restore-node" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/restoreNode" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/move-node" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/moveNode" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/duplicate-node" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/duplicateNode" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/reorder-children" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/reorderChildren" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/set-property" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/setProperty" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/unset-property" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/unsetProperty" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/reset-inherited-property" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/resetInheritedProperty" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/set-size-role" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/setSizeRole" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/unset-size-role" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/unsetSizeRole" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/set-binding" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/setBinding" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/remove-binding" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/removeBinding" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/set-field-value" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/setFieldValue" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/apply-pattern" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/applyPattern" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/add-model-field" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/addModelField" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/batch" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/batch" } } }
		}
	],
	$defs: {
		"destination": {
			"type": "object",
			"additionalProperties": false,
			"required": ["position"],
			"properties": {
				"parentNodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"slot": { "$ref": "common.schema.json#/$defs/localName" },
				"position": {
					"type": "integer",
					"minimum": 0,
					"maximum": 1e5
				}
			},
			"dependentRequired": {
				"parentNodeId": ["slot"],
				"slot": ["parentNodeId"]
			}
		},
		"insertNode": {
			"type": "object",
			"additionalProperties": false,
			"required": ["node", "destination"],
			"properties": {
				"node": { "$ref": "blueprint.schema.json#/$defs/node" },
				"destination": { "$ref": "#/$defs/destination" }
			}
		},
		"removeNode": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId"],
			"properties": { "nodeId": { "$ref": "common.schema.json#/$defs/stableId" } }
		},
		"restoreNode": {
			"type": "object",
			"additionalProperties": false,
			"required": ["node", "destination"],
			"properties": {
				"node": { "$ref": "blueprint.schema.json#/$defs/node" },
				"destination": { "$ref": "#/$defs/destination" }
			}
		},
		"moveNode": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId", "destination"],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"destination": { "$ref": "#/$defs/destination" }
			}
		},
		"duplicateNode": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId", "idMap"],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"destination": { "$ref": "#/$defs/destination" },
				"idMap": {
					"type": "object",
					"minProperties": 1,
					"maxProperties": 5e3,
					"propertyNames": { "$ref": "common.schema.json#/$defs/stableId" },
					"additionalProperties": { "$ref": "common.schema.json#/$defs/stableId" }
				}
			}
		},
		"reorderChildren": {
			"type": "object",
			"additionalProperties": false,
			"required": ["order"],
			"properties": {
				"parentNodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"slot": { "$ref": "common.schema.json#/$defs/localName" },
				"order": {
					"type": "array",
					"minItems": 1,
					"maxItems": 1e4,
					"uniqueItems": true,
					"items": { "$ref": "common.schema.json#/$defs/stableId" }
				}
			},
			"dependentRequired": {
				"parentNodeId": ["slot"],
				"slot": ["parentNodeId"]
			}
		},
		"setProperty": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"nodeId",
				"property",
				"value"
			],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"property": { "$ref": "common.schema.json#/$defs/localName" },
				"viewport": { "$ref": "common.schema.json#/$defs/localName" },
				"value": { "$ref": "common.schema.json#/$defs/jsonValue" }
			}
		},
		"unsetProperty": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId", "property"],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"property": { "$ref": "common.schema.json#/$defs/localName" },
				"viewport": { "$ref": "common.schema.json#/$defs/localName" }
			}
		},
		"resetInheritedProperty": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId", "property"],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"property": { "$ref": "common.schema.json#/$defs/localName" }
			}
		},
		"setSizeRole": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"nodeId",
				"axis",
				"role"
			],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"axis": { "$ref": "blueprint.schema.json#/$defs/sizeRoleAxis" },
				"role": { "$ref": "common.schema.json#/$defs/localName" },
				"viewport": { "$ref": "common.schema.json#/$defs/localName" }
			}
		},
		"unsetSizeRole": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId", "axis"],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"axis": { "$ref": "blueprint.schema.json#/$defs/sizeRoleAxis" },
				"viewport": { "$ref": "common.schema.json#/$defs/localName" }
			}
		},
		"setBinding": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"nodeId",
				"port",
				"binding"
			],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"port": { "$ref": "common.schema.json#/$defs/localName" },
				"binding": { "$ref": "blueprint.schema.json#/$defs/binding" }
			}
		},
		"removeBinding": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId", "port"],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"port": { "$ref": "common.schema.json#/$defs/localName" }
			}
		},
		"setFieldValue": {
			"type": "object",
			"additionalProperties": false,
			"required": ["fieldPath", "value"],
			"properties": {
				"fieldPath": {
					"type": "array",
					"minItems": 1,
					"maxItems": 32,
					"items": { "$ref": "common.schema.json#/$defs/localName" }
				},
				"locale": { "$ref": "common.schema.json#/$defs/locale" },
				"value": { "$ref": "common.schema.json#/$defs/jsonValue" }
			}
		},
		"addModelField": {
			"type": "object",
			"additionalProperties": false,
			"required": ["field"],
			"properties": {
				"field": { "$ref": "content-model.schema.json#/$defs/field" },
				"position": {
					"type": "integer",
					"minimum": 0,
					"maximum": 1e3
				}
			}
		},
		"applyPattern": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"pattern",
				"nodes",
				"destination",
				"idMap"
			],
			"properties": {
				"pattern": {
					"type": "object",
					"additionalProperties": false,
					"required": [
						"id",
						"version",
						"revision"
					],
					"properties": {
						"id": { "$ref": "common.schema.json#/$defs/stableId" },
						"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
						"revision": { "$ref": "common.schema.json#/$defs/revision" }
					}
				},
				"nodes": {
					"type": "array",
					"minItems": 1,
					"maxItems": 100,
					"items": { "$ref": "blueprint.schema.json#/$defs/node" }
				},
				"destination": { "$ref": "#/$defs/destination" },
				"idMap": {
					"type": "object",
					"minProperties": 1,
					"maxProperties": 5e3,
					"propertyNames": { "$ref": "common.schema.json#/$defs/stableId" },
					"additionalProperties": { "$ref": "common.schema.json#/$defs/stableId" }
				}
			}
		},
		"batch": {
			"type": "object",
			"additionalProperties": false,
			"required": ["operations"],
			"properties": { "operations": {
				"type": "array",
				"minItems": 1,
				"maxItems": 100,
				"items": { "$ref": "#/$defs/batchOperation" }
			} }
		},
		"batchOperation": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "payload"],
			"properties": {
				"type": { "allOf": [{ "$ref": "common.schema.json#/$defs/qualifiedName" }, { "not": { "enum": [
					"studio.command/apply-pattern",
					"studio.command/batch",
					"studio.command/reset-inherited-property"
				] } }] },
				"payload": {
					"type": "object",
					"maxProperties": 1e3,
					"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
					"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
				}
			},
			"allOf": [
				{
					"if": { "properties": { "type": { "const": "studio.command/insert-node" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/insertNode" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/remove-node" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/removeNode" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/restore-node" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/restoreNode" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/move-node" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/moveNode" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/duplicate-node" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/duplicateNode" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/reorder-children" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/reorderChildren" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/set-property" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/setProperty" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/unset-property" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/unsetProperty" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/set-size-role" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/setSizeRole" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/unset-size-role" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/unsetSizeRole" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/set-binding" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/setBinding" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/remove-binding" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/removeBinding" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/set-field-value" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/setFieldValue" } } }
				}
			]
		}
	}
};
var command_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/command-vector.schema.json",
	title: "Studio canonical command vector",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"initial",
		"command",
		"expect"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "command-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500
		},
		"mode": { "enum": [
			"blueprint",
			"content",
			"hybrid",
			"model",
			"read-only"
		] },
		"initial": { "$ref": "blueprint.schema.json" },
		"command": { "$ref": "command.schema.json" },
		"expect": { "oneOf": [{
			"type": "object",
			"additionalProperties": false,
			"required": ["document"],
			"properties": { "document": { "$ref": "blueprint.schema.json" } }
		}, {
			"type": "object",
			"additionalProperties": false,
			"required": ["errorCode"],
			"properties": { "errorCode": { "$ref": "#/$defs/commandErrorCode" } }
		}] },
		"inverse": { "$ref": "command.schema.json" }
	},
	$defs: { "commandErrorCode": { "enum": [
		"artifact-not-draft",
		"binding-not-found",
		"duplicate-field",
		"duplicate-node",
		"illegal-move",
		"invalid-batch",
		"invalid-id-map",
		"invalid-index",
		"invalid-order",
		"locale-mismatch",
		"mode-forbidden",
		"node-not-found",
		"parent-not-found",
		"property-not-found",
		"read-only-session",
		"stale-generation",
		"stale-state",
		"unsupported-command"
	] } }
};
var common_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/common.schema.json",
	title: "Studio common contract types",
	type: "object",
	additionalProperties: false,
	$defs: {
		"contractVersion": {
			"type": "string",
			"const": "0.1-draft"
		},
		"semanticVersion": {
			"type": "string",
			"pattern": "^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)(?:-((?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\\+([0-9A-Za-z-]+(?:\\.[0-9A-Za-z-]+)*))?(?![\\s\\S])",
			"maxLength": 100
		},
		"versionRange": {
			"type": "string",
			"minLength": 1,
			"maxLength": 120,
			"pattern": "^[0-9A-Za-z.*+<>=~^| -]+(?![\\s\\S])"
		},
		"qualifiedName": {
			"type": "string",
			"pattern": "^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*/[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*(?![\\s\\S])",
			"maxLength": 160
		},
		"localName": {
			"type": "string",
			"pattern": "^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*(?![\\s\\S])",
			"not": { "enum": [
				"__proto__",
				"prototype",
				"constructor"
			] },
			"maxLength": 100
		},
		"safeJsonMemberName": {
			"type": "string",
			"minLength": 1,
			"maxLength": 200,
			"pattern": "^[^\\u0000-\\u001F\\u007F]+(?![\\s\\S])",
			"not": { "enum": [
				"__proto__",
				"prototype",
				"constructor"
			] }
		},
		"packageRelativePath": {
			"type": "string",
			"pattern": "^[A-Za-z0-9@][A-Za-z0-9._@-]*(?:/[A-Za-z0-9@][A-Za-z0-9._@-]*)*(?![\\s\\S])",
			"maxLength": 240
		},
		"stableId": {
			"type": "string",
			"pattern": "^[A-Za-z0-9][A-Za-z0-9._:/-]*(?![\\s\\S])",
			"not": { "enum": [
				"__proto__",
				"prototype",
				"constructor"
			] },
			"maxLength": 240
		},
		"revision": {
			"type": "string",
			"minLength": 1,
			"maxLength": 200
		},
		"integrity": {
			"type": "string",
			"pattern": "^(?:sha256-[A-Za-z0-9+/]{42}[AEIMQUYcgkosw048]=|sha384-[A-Za-z0-9+/]{64}|sha512-[A-Za-z0-9+/]{85}[AQgw]==)(?![\\s\\S])",
			"maxLength": 200
		},
		"locale": {
			"type": "string",
			"pattern": "^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*(?![\\s\\S])",
			"maxLength": 50
		},
		"canonicalDecimal": {
			"type": "string",
			"pattern": "^(?:-?(?:0|[1-9][0-9]*)\\.[0-9]+|0|-?[1-9][0-9]*)(?![\\s\\S])",
			"maxLength": 40
		},
		"currencyCode": {
			"type": "string",
			"pattern": "^[A-Z]{3}(?![\\s\\S])"
		},
		"moneyValue": {
			"type": "object",
			"additionalProperties": false,
			"required": ["amount", "currency"],
			"properties": {
				"amount": { "$ref": "#/$defs/canonicalDecimal" },
				"currency": { "$ref": "#/$defs/currencyCode" }
			}
		},
		"rfc3339Date": {
			"type": "string",
			"pattern": "^[0-9]{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])(?![\\s\\S])"
		},
		"rfc3339Instant": {
			"type": "string",
			"pattern": "^[0-9]{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01][0-9]|2[0-3]):[0-5][0-9]:(?:[0-5][0-9]|60)(?:\\.[0-9]{1,9})?(?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])(?![\\s\\S])",
			"maxLength": 40
		},
		"messageReference": {
			"type": "object",
			"additionalProperties": false,
			"required": ["key"],
			"properties": {
				"key": { "$ref": "#/$defs/qualifiedName" },
				"defaultMessage": {
					"type": "string",
					"minLength": 1,
					"maxLength": 500
				}
			}
		},
		"ownerReference": {
			"type": "object",
			"additionalProperties": false,
			"required": ["id", "version"],
			"properties": {
				"id": { "$ref": "#/$defs/qualifiedName" },
				"version": { "$ref": "#/$defs/semanticVersion" }
			}
		},
		"artifactReference": {
			"type": "object",
			"additionalProperties": false,
			"required": ["id", "version"],
			"properties": {
				"id": { "$ref": "#/$defs/stableId" },
				"version": { "$ref": "#/$defs/semanticVersion" },
				"revision": { "$ref": "#/$defs/revision" },
				"integrity": { "$ref": "#/$defs/integrity" }
			}
		},
		"lockedArtifactReference": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"version",
				"revision"
			],
			"properties": {
				"id": { "$ref": "#/$defs/stableId" },
				"version": { "$ref": "#/$defs/semanticVersion" },
				"revision": { "$ref": "#/$defs/revision" },
				"integrity": { "$ref": "#/$defs/integrity" }
			}
		},
		"resolvedEntryReference": {
			"type": "object",
			"additionalProperties": false,
			"required": ["id", "revision"],
			"properties": {
				"id": { "$ref": "#/$defs/stableId" },
				"revision": { "$ref": "#/$defs/revision" },
				"integrity": { "$ref": "#/$defs/integrity" }
			}
		},
		"resourceIdentity": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "id"],
			"properties": {
				"type": { "$ref": "#/$defs/qualifiedName" },
				"id": { "$ref": "#/$defs/stableId" }
			}
		},
		"resourceScope": {
			"type": "object",
			"additionalProperties": false,
			"required": ["kind", "id"],
			"properties": {
				"kind": { "$ref": "#/$defs/qualifiedName" },
				"id": { "$ref": "#/$defs/stableId" }
			}
		},
		"resourceContext": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"key",
				"surface",
				"scopes"
			],
			"properties": {
				"key": { "$ref": "#/$defs/stableId" },
				"surface": { "$ref": "#/$defs/qualifiedName" },
				"revision": { "$ref": "#/$defs/revision" },
				"scopes": {
					"type": "array",
					"maxItems": 20,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/resourceScope" }
				},
				"resource": { "$ref": "#/$defs/resourceIdentity" }
			}
		},
		"returnContext": {
			"description": "A non-secret host-minted pointer for deterministic return navigation. It is not a URL, credential, or permission.",
			"type": "object",
			"additionalProperties": false,
			"required": ["key"],
			"properties": {
				"key": { "$ref": "#/$defs/stableId" },
				"label": { "$ref": "#/$defs/messageReference" }
			}
		},
		"jsonValue": { "oneOf": [
			{ "type": "null" },
			{ "type": "boolean" },
			{ "type": "number" },
			{ "type": "string" },
			{
				"type": "array",
				"maxItems": 1e4,
				"items": { "$ref": "#/$defs/jsonValue" }
			},
			{
				"type": "object",
				"maxProperties": 1e4,
				"propertyNames": { "$ref": "#/$defs/safeJsonMemberName" },
				"additionalProperties": { "$ref": "#/$defs/jsonValue" }
			}
		] },
		"extensions": {
			"type": "object",
			"maxProperties": 100,
			"propertyNames": { "$ref": "#/$defs/qualifiedName" },
			"additionalProperties": { "$ref": "#/$defs/jsonValue" }
		},
		"diagnosticLocation": {
			"type": "object",
			"additionalProperties": false,
			"properties": {
				"artifactId": { "$ref": "#/$defs/stableId" },
				"nodeId": { "$ref": "#/$defs/stableId" },
				"fieldPath": {
					"type": "array",
					"maxItems": 32,
					"items": { "$ref": "#/$defs/localName" }
				},
				"jsonPointer": {
					"type": "string",
					"maxLength": 1e3
				}
			}
		},
		"diagnostic": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"code",
				"severity",
				"message"
			],
			"properties": {
				"code": { "$ref": "#/$defs/qualifiedName" },
				"severity": { "enum": [
					"information",
					"warning",
					"error",
					"blocking"
				] },
				"message": { "$ref": "#/$defs/messageReference" },
				"location": { "$ref": "#/$defs/diagnosticLocation" },
				"parameters": {
					"type": "object",
					"maxProperties": 20,
					"propertyNames": { "$ref": "#/$defs/safeJsonMemberName" },
					"additionalProperties": { "oneOf": [
						{ "type": "string" },
						{ "type": "number" },
						{ "type": "boolean" },
						{ "type": "null" }
					] }
				},
				"remediations": {
					"type": "array",
					"maxItems": 10,
					"items": { "$ref": "#/$defs/qualifiedName" }
				}
			}
		}
	}
};
var content_model_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/content-model.schema.json",
	title: "Studio content model",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"revision",
		"owner",
		"status",
		"label",
		"fields",
		"relationships"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "content-model" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"revision": { "$ref": "common.schema.json#/$defs/revision" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"status": { "enum": [
			"draft",
			"published",
			"retired"
		] },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"fields": {
			"type": "array",
			"minItems": 0,
			"maxItems": 1e3,
			"items": { "$ref": "#/$defs/field" }
		},
		"relationships": {
			"type": "array",
			"maxItems": 1e3,
			"items": { "$ref": "#/$defs/relationship" }
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	},
	allOf: [{
		"if": {
			"properties": { "status": { "const": "published" } },
			"required": ["status"]
		},
		"then": { "properties": { "fields": {
			"type": "array",
			"minItems": 1
		} } }
	}],
	$defs: {
		"field": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"kind",
				"label",
				"required",
				"localized",
				"cardinality"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/localName" },
				"kind": { "oneOf": [{ "enum": [
					"string",
					"rich-text",
					"boolean",
					"integer",
					"decimal",
					"money",
					"date",
					"date-time",
					"enum",
					"media",
					"resource",
					"object",
					"collection"
				] }, { "$ref": "common.schema.json#/$defs/qualifiedName" }] },
				"label": { "$ref": "common.schema.json#/$defs/messageReference" },
				"description": { "$ref": "common.schema.json#/$defs/messageReference" },
				"required": { "type": "boolean" },
				"localized": { "type": "boolean" },
				"cardinality": { "enum": ["one", "many"] },
				"semanticRole": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"defaultValue": { "$ref": "common.schema.json#/$defs/jsonValue" },
				"authoring": { "$ref": "#/$defs/authoringMetadata" },
				"constraints": {
					"type": "object",
					"additionalProperties": false,
					"properties": {
						"minimum": {
							"type": "string",
							"pattern": "^-?(0|[1-9][0-9]*)(?:\\.[0-9]+)?(?![\\s\\S])",
							"maxLength": 200
						},
						"maximum": {
							"type": "string",
							"pattern": "^-?(0|[1-9][0-9]*)(?:\\.[0-9]+)?(?![\\s\\S])",
							"maxLength": 200
						},
						"scale": {
							"type": "integer",
							"minimum": 0,
							"maximum": 100
						},
						"minLength": {
							"type": "integer",
							"minimum": 0,
							"maximum": 1e7
						},
						"maxLength": {
							"type": "integer",
							"minimum": 0,
							"maximum": 1e7
						},
						"validator": { "$ref": "common.schema.json#/$defs/qualifiedName" },
						"validatorArguments": {
							"type": "object",
							"maxProperties": 20,
							"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
							"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
						},
						"minItems": {
							"type": "integer",
							"minimum": 0,
							"maximum": 1e5
						},
						"maxItems": {
							"type": "integer",
							"minimum": 0,
							"maximum": 1e5
						},
						"allowedMediaKinds": {
							"type": "array",
							"maxItems": 50,
							"uniqueItems": true,
							"items": { "enum": [
								"image",
								"video",
								"audio",
								"document",
								"archive",
								"other"
							] }
						}
					}
				},
				"enumValues": {
					"type": "array",
					"minItems": 1,
					"maxItems": 1e3,
					"items": {
						"type": "object",
						"additionalProperties": false,
						"required": ["value", "label"],
						"properties": {
							"value": { "$ref": "common.schema.json#/$defs/localName" },
							"label": { "$ref": "common.schema.json#/$defs/messageReference" }
						}
					}
				},
				"fields": {
					"type": "array",
					"maxItems": 1e3,
					"items": { "$ref": "#/$defs/field" }
				},
				"itemKind": { "oneOf": [{ "enum": [
					"string",
					"rich-text",
					"boolean",
					"integer",
					"decimal",
					"money",
					"date",
					"date-time",
					"enum",
					"media",
					"resource",
					"object"
				] }, { "$ref": "common.schema.json#/$defs/qualifiedName" }] },
				"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
			}
		},
		"authoringMetadata": {
			"type": "object",
			"additionalProperties": false,
			"properties": {
				"control": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"placeholder": { "$ref": "common.schema.json#/$defs/messageReference" },
				"group": { "$ref": "common.schema.json#/$defs/localName" },
				"order": {
					"type": "integer",
					"minimum": 0,
					"maximum": 1e5
				},
				"readOnly": { "type": "boolean" },
				"hidden": { "type": "boolean" },
				"width": { "enum": [
					"full",
					"half",
					"third",
					"two-thirds"
				] }
			}
		},
		"relationship": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"kind",
				"label",
				"sourceField",
				"targetModel",
				"required",
				"onDelete"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/localName" },
				"kind": { "enum": [
					"one-to-one",
					"many-to-one",
					"one-to-many",
					"many-to-many"
				] },
				"label": { "$ref": "common.schema.json#/$defs/messageReference" },
				"sourceField": { "$ref": "common.schema.json#/$defs/localName" },
				"targetModel": { "$ref": "common.schema.json#/$defs/artifactReference" },
				"targetField": { "$ref": "common.schema.json#/$defs/localName" },
				"required": { "type": "boolean" },
				"onDelete": { "enum": [
					"restrict",
					"nullify",
					"detach"
				] },
				"authoring": {
					"type": "object",
					"additionalProperties": false,
					"required": ["control", "allowCreate"],
					"properties": {
						"control": { "$ref": "common.schema.json#/$defs/qualifiedName" },
						"allowCreate": { "type": "boolean" },
						"displayField": { "$ref": "common.schema.json#/$defs/localName" },
						"searchProvider": { "$ref": "common.schema.json#/$defs/qualifiedName" }
					}
				},
				"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
			}
		}
	}
};
var corpus_manifest_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/corpus-manifest.schema.json",
	title: "Studio corpus integrity manifest",
	description: "The digest of every file in the published conformance corpus, grouped by the directory it ships in. A host that vendors the corpus verifies its copy against this manifest, so a stale or altered fixture is detected before it silently changes what a conformance claim means. The schema manifest covers the schemas; this covers everything replayed against them.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"groups"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "corpus-manifest" },
		"groups": {
			"type": "array",
			"minItems": 1,
			"maxItems": 50,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"files",
					"group",
					"path"
				],
				"properties": {
					"files": {
						"type": "array",
						"maxItems": 5e3,
						"items": {
							"type": "object",
							"additionalProperties": false,
							"required": ["digest", "file"],
							"properties": {
								"digest": { "$ref": "common.schema.json#/$defs/integrity" },
								"file": {
									"type": "string",
									"minLength": 1,
									"maxLength": 200
								}
							}
						}
					},
					"group": {
						"description": "The stable name of the corpus group.",
						"$ref": "common.schema.json#/$defs/localName"
					},
					"path": {
						"description": "The package-relative directory the group ships in.",
						"type": "string",
						"minLength": 1,
						"maxLength": 200
					}
				}
			}
		}
	}
};
var canonical_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/canonical-vector.schema.json",
	title: "Studio canonical serialization vector",
	description: "One canonical serialization case: a bounded JSON value and either the exact canonical string it serializes to with the digest of its UTF-8 bytes, or the stable reason the canonical form refuses it. Checksums across the contract are computed over exactly these bytes, so an implementation that reproduces this corpus computes the same digests as every other - which is what makes a vendored-corpus check and a stored-document round-trip comparable across languages.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"value",
		"expect"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "canonical-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500
		},
		"value": { "description": "The input value. Deliberately looser than the canonical JSON value shape so a rejection vector can carry a value the canonical form refuses, such as a forbidden member name. Values the canonical form cannot represent at all - non-finite numbers, undefined - are not expressible in JSON and are proven in implementation tests rather than here." },
		"expect": { "oneOf": [{
			"type": "object",
			"additionalProperties": false,
			"required": ["canonical", "digest"],
			"properties": {
				"canonical": {
					"description": "The exact canonical string, byte for byte.",
					"type": "string",
					"maxLength": 2e4
				},
				"digest": {
					"description": "The SRI-style sha256 digest of the canonical UTF-8 bytes.",
					"$ref": "common.schema.json#/$defs/integrity"
				}
			}
		}, {
			"type": "object",
			"additionalProperties": false,
			"required": ["rejected"],
			"properties": { "rejected": {
				"description": "The stable reason the canonical form refuses the value.",
				"enum": ["depth-exceeded", "forbidden-member"]
			} }
		}] },
		"maximumDepth": {
			"description": "The depth bound to serialize under. Absent means the contract default of 64.",
			"type": "integer",
			"minimum": 1,
			"maximum": 64
		}
	}
};
var design_vocabulary_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/design-vocabulary.schema.json",
	title: "Studio design vocabulary contribution",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"owner",
		"label",
		"designControls",
		"recipes"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "design-vocabulary" },
		"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"designControls": {
			"type": "array",
			"maxItems": 1e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"kind",
					"label",
					"choices"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/localName" },
					"kind": { "enum": [
						"color-role",
						"typography-role",
						"spacing-role",
						"size-role",
						"radius-role",
						"shadow-role",
						"motion-role",
						"layer-role",
						"enum",
						"boolean",
						"integer"
					] },
					"label": { "$ref": "common.schema.json#/$defs/messageReference" },
					"description": { "$ref": "common.schema.json#/$defs/messageReference" },
					"choices": {
						"type": "array",
						"minItems": 1,
						"maxItems": 500,
						"items": {
							"type": "object",
							"additionalProperties": false,
							"required": ["id", "label"],
							"properties": {
								"id": { "$ref": "common.schema.json#/$defs/localName" },
								"label": { "$ref": "common.schema.json#/$defs/messageReference" },
								"deprecated": { "type": "boolean" }
							}
						}
					}
				}
			}
		},
		"recipes": {
			"type": "array",
			"maxItems": 1e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"blockType",
					"label",
					"designValues"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/localName" },
					"blockType": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"label": { "$ref": "common.schema.json#/$defs/messageReference" },
					"designValues": {
						"type": "object",
						"maxProperties": 100,
						"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
						"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
					}
				}
			}
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var entry_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/entry.schema.json",
	title: "Studio content entry",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"revision",
		"model",
		"status",
		"values"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "entry" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"revision": { "$ref": "common.schema.json#/$defs/revision" },
		"model": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
		"status": { "enum": [
			"draft",
			"in-review",
			"published",
			"archived"
		] },
		"locale": { "$ref": "common.schema.json#/$defs/locale" },
		"translationOf": { "$ref": "common.schema.json#/$defs/stableId" },
		"workflowState": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"values": {
			"type": "object",
			"maxProperties": 1e4,
			"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
			"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
		},
		"compositionOverrides": {
			"type": "object",
			"maxProperties": 1e3,
			"propertyNames": { "$ref": "common.schema.json#/$defs/stableId" },
			"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var field_adapter_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/field-adapter.schema.json",
	title: "Studio field adapter contribution",
	description: "The declarative payload behind a `field-adapter` plugin contribution: a control an extension offers for editing a declared field kind. The declaration is inert data - it names the control, the field kinds it accepts, the capability its executable half requires, and the bounded option schema an author configures it through. It never carries markup, styles or code.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"owner",
		"label",
		"control",
		"fieldKinds"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "field-adapter" },
		"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"control": {
			"description": "The control identifier a field's authoring metadata names to select this adapter.",
			"$ref": "common.schema.json#/$defs/qualifiedName"
		},
		"fieldKinds": {
			"description": "The field kinds this control accepts. A field of any other kind never resolves to it.",
			"type": "array",
			"minItems": 1,
			"maxItems": 50,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
		},
		"optionSchema": {
			"description": "The bounded schema an author's control options validate against, inside the Studio Schema Profile exactly like a contributed block's property schema.",
			"type": "object",
			"maxProperties": 100,
			"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
			"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
		},
		"requiredCapability": {
			"description": "The capability the executable half requires. A declaration without one is inspectable but never executed.",
			"$ref": "common.schema.json#/$defs/qualifiedName"
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var host_capabilities_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/host-capabilities.schema.json",
	title: "Studio host capabilities",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"host",
		"protocolVersions",
		"ports",
		"capabilities"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "host-capabilities" },
		"host": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"version",
				"generation"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"generation": { "$ref": "common.schema.json#/$defs/revision" }
			}
		},
		"protocolVersions": {
			"type": "array",
			"minItems": 1,
			"maxItems": 20,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/semanticVersion" }
		},
		"ports": {
			"type": "array",
			"maxItems": 100,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"version",
					"operations"
				],
				"properties": {
					"id": { "$ref": "host-operations.schema.json#/$defs/portCapability" },
					"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
					"operations": {
						"description": "The operations this host actually implements, closed by the operation registry so a capability document cannot advertise an operation that is not on the wire.",
						"type": "array",
						"minItems": 1,
						"maxItems": 100,
						"uniqueItems": true,
						"items": { "$ref": "host-operations.schema.json#/$defs/operationCapability" }
					}
				}
			}
		},
		"capabilities": {
			"type": "array",
			"maxItems": 500,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": ["id", "version"],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
					"configuration": { "$ref": "common.schema.json#/$defs/jsonValue" }
				}
			}
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var host_error_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/host-error.schema.json",
	title: "Studio host port error",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"category",
		"message",
		"retryable"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "host-error" },
		"category": { "enum": [
			"invalid-request",
			"unauthenticated",
			"forbidden",
			"not-found",
			"conflict",
			"validation-failed",
			"incompatible",
			"limit-exceeded",
			"rate-limited",
			"unavailable",
			"cancelled",
			"internal"
		] },
		"correlationId": { "$ref": "common.schema.json#/$defs/stableId" },
		"message": { "$ref": "common.schema.json#/$defs/messageReference" },
		"retryable": { "type": "boolean" },
		"retryAfterMilliseconds": {
			"type": "integer",
			"minimum": 0,
			"maximum": 864e5
		},
		"revision": { "$ref": "common.schema.json#/$defs/revision" },
		"diagnostics": {
			"type": "array",
			"maxItems": 1e3,
			"items": { "$ref": "common.schema.json#/$defs/diagnostic" }
		}
	},
	allOf: [{
		"if": {
			"properties": { "revision": {} },
			"required": ["revision"]
		},
		"then": { "properties": { "category": { "const": "conflict" } } }
	}, {
		"if": {
			"properties": { "retryAfterMilliseconds": {} },
			"required": ["retryAfterMilliseconds"]
		},
		"then": { "properties": {
			"category": { "enum": ["rate-limited", "unavailable"] },
			"retryable": { "const": true }
		} }
	}]
};
var host_operations_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/host-operations.schema.json",
	title: "Studio host port operation registry",
	description: "The closed registry binding every host port operation to its names: the wire operation a transport addresses, the typed method a client calls, and the capability identifier a host advertises. A host cannot publish a truthful capability document without it, because the vocabularies must map one to one. Adding an operation is an additive protocol change; renaming or removing one is breaking.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"operations"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "host-operations" },
		"operations": {
			"type": "array",
			"minItems": 1,
			"maxItems": 200,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"capability",
					"expectsRevision",
					"method",
					"mutating",
					"operation",
					"port",
					"portCapability",
					"required",
					"route"
				],
				"properties": {
					"capability": { "$ref": "#/$defs/operationCapability" },
					"expectsRevision": {
						"description": "The operation is concurrency-protected: the request envelope carries expectedRevision and a mismatch conflicts.",
						"type": "boolean"
					},
					"method": {
						"description": "The typed method name a client port interface exposes, which differs from the wire name where the wire spelling is not a valid identifier.",
						"type": "string",
						"pattern": "^[a-z][A-Za-z0-9]*(?![\\s\\S])",
						"maxLength": 100
					},
					"mutating": {
						"description": "The operation changes host state, so it is authorized, audited and idempotent when retried.",
						"type": "boolean"
					},
					"operation": { "$ref": "common.schema.json#/$defs/localName" },
					"port": { "$ref": "#/$defs/portName" },
					"portCapability": { "$ref": "#/$defs/portCapability" },
					"required": {
						"description": "The port must be present for an editable session; an absent optional port degrades with diagnostics.",
						"type": "boolean"
					},
					"route": { "$ref": "#/$defs/operationRoute" }
				}
			}
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	},
	$defs: {
		"operationCapability": {
			"description": "The capability identifier a host advertises for one port operation.",
			"enum": [
				"studio.operation/artifact.dependencies",
				"studio.operation/artifact.load",
				"studio.operation/artifact.publish",
				"studio.operation/artifact.save",
				"studio.operation/artifact.unpublish",
				"studio.operation/authoring.list-types",
				"studio.operation/authoring.plan-save",
				"studio.operation/authoring.resolve-target",
				"studio.operation/authoring.save-as-new-type",
				"studio.operation/authoring.save-item",
				"studio.operation/authoring.save-new-type-version",
				"studio.operation/authoring.start",
				"studio.operation/localization.messages",
				"studio.operation/media.abort-upload",
				"studio.operation/media.authorize-upload",
				"studio.operation/media.complete-upload",
				"studio.operation/media.get",
				"studio.operation/media.import-external",
				"studio.operation/media.list",
				"studio.operation/media.upload-status",
				"studio.operation/model.get",
				"studio.operation/model.list",
				"studio.operation/permission.explain",
				"studio.operation/permission.refresh",
				"studio.operation/preview.cancel",
				"studio.operation/preview.render",
				"studio.operation/recovery.discard",
				"studio.operation/recovery.load",
				"studio.operation/recovery.store",
				"studio.operation/resource.search",
				"studio.operation/telemetry.emit"
			]
		},
		"operationRoute": {
			"description": "The transport route segment addressing one port operation.",
			"enum": [
				"artifact/dependencies",
				"artifact/load",
				"artifact/publish",
				"artifact/save",
				"artifact/unpublish",
				"authoring/list-types",
				"authoring/plan-save",
				"authoring/resolve-target",
				"authoring/save-as-new-type",
				"authoring/save-item",
				"authoring/save-new-type-version",
				"authoring/start",
				"localization/messages",
				"media/abort-upload",
				"media/authorize-upload",
				"media/complete-upload",
				"media/get",
				"media/import-external",
				"media/list",
				"media/upload-status",
				"model/get",
				"model/list",
				"permission/explain",
				"permission/refresh",
				"preview/cancel",
				"preview/render",
				"recovery/discard",
				"recovery/load",
				"recovery/store",
				"resource/search",
				"telemetry/emit"
			]
		},
		"portCapability": {
			"description": "The capability identifier a host advertises for a whole port.",
			"enum": [
				"studio.port/artifact",
				"studio.port/authoring",
				"studio.port/localization",
				"studio.port/media",
				"studio.port/model",
				"studio.port/permission",
				"studio.port/preview",
				"studio.port/recovery",
				"studio.port/resource",
				"studio.port/telemetry"
			]
		},
		"portName": { "enum": [
			"artifact",
			"authoring",
			"localization",
			"media",
			"model",
			"permission",
			"preview",
			"recovery",
			"resource",
			"telemetry"
		] }
	}
};
var host_request_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/host-request.schema.json",
	title: "Studio host port request",
	description: "The body of one host port operation call: the argument the operation takes and the envelope every operation carries. A host validates this shape before dispatching, so a malformed envelope is refused as invalid-request rather than partially honoured.",
	type: "object",
	additionalProperties: false,
	required: ["context"],
	properties: {
		"arguments": {
			"description": "The operation argument. Absent for operations that take only the envelope.",
			"$ref": "common.schema.json#/$defs/jsonValue"
		},
		"context": { "$ref": "#/$defs/requestContext" }
	},
	$defs: { "requestContext": {
		"description": "The request envelope. Actor identity and authorization evidence are attached by the trusted transport and never appear here: a Studio-supplied actor value is display context, never authentication.",
		"type": "object",
		"additionalProperties": false,
		"required": [
			"operationId",
			"protocolVersion",
			"requestId",
			"resourceContextKey",
			"sessionGeneration"
		],
		"properties": {
			"expectedRevision": {
				"description": "The revision the caller believes is current. Required by every operation the registry marks expectsRevision; a mismatch conflicts and the host returns the safe current revision.",
				"$ref": "common.schema.json#/$defs/revision"
			},
			"idempotencyKey": {
				"description": "Present when a mutation may be retried. A host that has already accepted this key for this operation returns the original outcome rather than applying the mutation twice.",
				"$ref": "common.schema.json#/$defs/stableId"
			},
			"locale": {
				"description": "The locale for localized diagnostics. Absent means the session locale.",
				"$ref": "common.schema.json#/$defs/locale"
			},
			"operationId": {
				"description": "The capability identifier of the operation being invoked, closed by the operation registry.",
				"$ref": "host-operations.schema.json#/$defs/operationCapability"
			},
			"protocolVersion": {
				"description": "The negotiated wire version. A version the host does not support is refused as incompatible before any work.",
				"$ref": "common.schema.json#/$defs/semanticVersion"
			},
			"requestId": {
				"description": "Unique per call, used for correlation. It never identifies the actor.",
				"$ref": "common.schema.json#/$defs/stableId"
			},
			"resourceContextKey": {
				"description": "The opaque, non-secret, non-bearer context key. The host resolves and verifies the canonical context from this key rather than trusting client-supplied scope values; a stale, altered or cross-session key is rejected without disclosing private resource existence.",
				"$ref": "common.schema.json#/$defs/stableId"
			},
			"sessionGeneration": {
				"description": "The generation the session was opened at. A superseded generation is refused, because a permission or context change mints a new one.",
				"$ref": "common.schema.json#/$defs/revision"
			},
			"traceContext": {
				"description": "Trace correlation permitted by privacy policy. It carries no actor identity, credential or private resource value.",
				"type": "object",
				"maxProperties": 10,
				"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
				"additionalProperties": {
					"type": "string",
					"maxLength": 200
				}
			}
		}
	} }
};
var host_result_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/host-result.schema.json",
	title: "Studio host port result",
	description: "The body a host returns when a port operation succeeds. A failure is never expressed here: it carries the canonical host error document instead, so a client distinguishes outcomes by shape rather than by guessing from a status code.",
	type: "object",
	additionalProperties: false,
	required: ["value"],
	properties: {
		"revision": {
			"description": "The accepted resource revision. Every operation the registry marks expectsRevision returns the revision it advanced to, so a client never re-reads to learn what it just wrote.",
			"$ref": "common.schema.json#/$defs/revision"
		},
		"value": {
			"description": "The normalized operation result. Operations that answer with nothing return null rather than omitting the member, so absence is explicit.",
			"$ref": "common.schema.json#/$defs/jsonValue"
		}
	}
};
var host_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/host-vector.schema.json",
	title: "Studio canonical host conformance vector",
	description: "One canonical host-port exchange: the host state a conforming implementation is seeded with, the request envelope and argument Studio sends, and either the accepted result or the exact error category the closed taxonomy requires. Every precondition is a condition a real host can reproduce - a seeded revision, a withheld permission, an unknown identifier, an unsupported wire version - so the corpus is replayable by any implementation in any language without executing Studio code.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"profile",
		"port",
		"operation",
		"given",
		"context",
		"expect"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "host-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500
		},
		"profile": {
			"description": "The conformance profile whose assertion set this vector belongs to.",
			"$ref": "common.schema.json#/$defs/qualifiedName"
		},
		"port": { "enum": [
			"artifact",
			"localization",
			"media",
			"model",
			"permission",
			"preview",
			"recovery",
			"resource",
			"telemetry"
		] },
		"operation": { "$ref": "common.schema.json#/$defs/localName" },
		"given": {
			"description": "The reproducible host state before the request. A conforming host seeds exactly this state; nothing here is a test double.",
			"type": "object",
			"additionalProperties": false,
			"required": ["artifacts", "permissions"],
			"properties": {
				"artifacts": {
					"description": "Artifact identities the host already stores, with the revision each is stored at.",
					"type": "array",
					"maxItems": 20,
					"items": {
						"type": "object",
						"additionalProperties": false,
						"required": [
							"id",
							"kind",
							"revision"
						],
						"properties": {
							"id": { "$ref": "common.schema.json#/$defs/stableId" },
							"kind": { "enum": [
								"blueprint",
								"content-model",
								"entry"
							] },
							"revision": { "$ref": "common.schema.json#/$defs/revision" }
						}
					}
				},
				"permissions": {
					"description": "Operations the acting identity is authorized for. An operation absent here is withheld.",
					"type": "array",
					"maxItems": 50,
					"uniqueItems": true,
					"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
				},
				"sessionGeneration": {
					"description": "The host's live session generation. A request naming any other generation is stale.",
					"$ref": "common.schema.json#/$defs/revision"
				}
			}
		},
		"context": {
			"description": "The request envelope every port operation carries.",
			"type": "object",
			"additionalProperties": false,
			"required": [
				"operationId",
				"protocolVersion",
				"requestId",
				"resourceContextKey",
				"sessionGeneration"
			],
			"properties": {
				"expectedRevision": { "$ref": "common.schema.json#/$defs/revision" },
				"idempotencyKey": { "$ref": "common.schema.json#/$defs/stableId" },
				"locale": { "$ref": "common.schema.json#/$defs/locale" },
				"operationId": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"protocolVersion": {
					"description": "Deliberately looser than the negotiated wire version so a vector can carry a version the host must refuse.",
					"type": "string",
					"maxLength": 100
				},
				"requestId": {
					"description": "Deliberately looser than the canonical stable identifier so a vector can carry a structurally invalid envelope the host must refuse.",
					"type": "string",
					"maxLength": 240
				},
				"resourceContextKey": { "$ref": "common.schema.json#/$defs/stableId" },
				"sessionGeneration": { "$ref": "common.schema.json#/$defs/revision" }
			}
		},
		"argument": {
			"description": "The port argument, shaped by the operation. Absent for operations that take only the envelope.",
			"$ref": "common.schema.json#/$defs/jsonValue"
		},
		"expect": { "oneOf": [{
			"type": "object",
			"additionalProperties": false,
			"required": ["outcome"],
			"properties": {
				"outcome": { "const": "result" },
				"revision": {
					"description": "The accepted revision the result carries. A mutation MUST advance it beyond the stored revision.",
					"$ref": "common.schema.json#/$defs/revision"
				},
				"revisionAdvances": {
					"description": "True when the operation mutates and the returned revision must differ from the stored one.",
					"type": "boolean"
				},
				"value": {
					"description": "The value shape the result carries.",
					"enum": [
						"artifact",
						"artifact-references",
						"content-model",
						"content-models",
						"media-asset",
						"media-page",
						"message-bundle",
						"null",
						"permission-explanation",
						"permission-snapshot",
						"recovery-envelope",
						"rendered",
						"search-page",
						"upload-accepted",
						"upload-grant"
					]
				}
			}
		}, {
			"type": "object",
			"additionalProperties": false,
			"required": ["outcome", "category"],
			"properties": {
				"outcome": { "const": "error" },
				"category": { "enum": [
					"cancelled",
					"conflict",
					"forbidden",
					"incompatible",
					"internal",
					"invalid-request",
					"limit-exceeded",
					"not-found",
					"rate-limited",
					"unauthenticated",
					"unavailable",
					"validation-failed"
				] },
				"retryable": {
					"description": "The retry classification the error MUST carry.",
					"type": "boolean"
				},
				"revision": {
					"description": "The safe current revision a conflict MUST return so the client can resolve without a second read.",
					"$ref": "common.schema.json#/$defs/revision"
				},
				"messageMustNotContain": {
					"description": "Values the user-facing message MUST NOT echo, so a rejection never discloses private resource existence or request internals.",
					"type": "array",
					"minItems": 1,
					"maxItems": 10,
					"items": {
						"type": "string",
						"minLength": 1,
						"maxLength": 200
					}
				}
			}
		}] }
	}
};
var host_sequence_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/host-sequence-vector.schema.json",
	title: "Studio canonical host sequence conformance vector",
	description: "A deterministic sequence of host-port invocations, settlements, logical-clock advances and renderer completions. Every stateful precondition is bounded JSON so another runtime can reproduce retries, rate-limit windows and in-flight cancellation without wall-clock sleeps or executable fixture callbacks.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"profile",
		"assertions",
		"idempotencyPolicy",
		"given",
		"steps",
		"expectFinal"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "host-sequence-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500
		},
		"profile": { "const": "studio.profile/host-baseline-v2" },
		"assertions": {
			"type": "array",
			"minItems": 1,
			"maxItems": 12,
			"uniqueItems": true,
			"items": { "$ref": "#/$defs/assertion" }
		},
		"idempotencyPolicy": { "$ref": "#/$defs/idempotencyPolicy" },
		"given": { "$ref": "#/$defs/given" },
		"steps": {
			"type": "array",
			"minItems": 2,
			"maxItems": 24,
			"items": { "oneOf": [
				{ "$ref": "#/$defs/invokeStep" },
				{ "$ref": "#/$defs/settleStep" },
				{ "$ref": "#/$defs/advanceClockStep" },
				{ "$ref": "#/$defs/releasePreviewRenderStep" }
			] }
		},
		"expectFinal": { "$ref": "#/$defs/finalState" }
	},
	$defs: {
		"assertion": { "enum": [
			"operation-id-mismatch-refusal",
			"idempotent-in-flight-coalescing",
			"idempotent-completed-replay",
			"idempotency-changed-argument-refusal",
			"idempotency-changed-context-refusal",
			"idempotency-resource-scope-separation",
			"canonical-number-equivalence",
			"failed-attempt-retry",
			"fixed-window-rate-limit",
			"fixed-window-reset",
			"in-flight-preview-cancellation",
			"cross-context-cancellation-isolation",
			"late-preview-result-discard"
		] },
		"idempotencyPolicy": {
			"description": "The exact map key and canonical intent preimage used by this profile. Scope fields select an accepted record; the argument and listed semantic context fields form its canonical JSON fingerprint. Optional semantic fields that are absent are omitted, not serialized as null. Canonical JSON applies its number grammar, including normalization of negative zero to zero. Per-attempt request and trace correlation never affect intent.",
			"type": "object",
			"additionalProperties": false,
			"required": [
				"scopeFields",
				"argumentCanonicalization",
				"intentContextFields",
				"absentOptionalFields",
				"numberNormalization",
				"excludedContextFields"
			],
			"properties": {
				"scopeFields": { "const": [
					"idempotencyKey",
					"operationId",
					"resourceContextKey",
					"sessionGeneration"
				] },
				"argumentCanonicalization": { "const": "canonical-json" },
				"intentContextFields": { "const": [
					"expectedRevision",
					"locale",
					"protocolVersion"
				] },
				"absentOptionalFields": { "const": "omit" },
				"numberNormalization": { "const": "canonical-json" },
				"excludedContextFields": { "const": ["requestId", "traceContext"] }
			}
		},
		"port": { "enum": [
			"artifact",
			"localization",
			"media",
			"model",
			"permission",
			"preview",
			"recovery",
			"resource",
			"telemetry"
		] },
		"artifactSeed": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"kind",
				"revision",
				"status"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"kind": { "enum": [
					"blueprint",
					"content-model",
					"entry"
				] },
				"revision": { "$ref": "common.schema.json#/$defs/revision" },
				"status": { "enum": [
					"archived",
					"draft",
					"in-review",
					"published",
					"retired"
				] }
			},
			"allOf": [{
				"if": {
					"properties": { "kind": { "const": "entry" } },
					"required": ["kind"]
				},
				"then": { "properties": { "status": { "enum": [
					"archived",
					"draft",
					"in-review",
					"published"
				] } } },
				"else": { "properties": { "status": { "enum": [
					"draft",
					"published",
					"retired"
				] } } }
			}]
		},
		"rateLimit": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"operationId",
				"maximumRequests",
				"windowMilliseconds",
				"retryAfterMilliseconds"
			],
			"properties": {
				"operationId": { "$ref": "host-operations.schema.json#/$defs/operationCapability" },
				"maximumRequests": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e3
				},
				"windowMilliseconds": {
					"type": "integer",
					"minimum": 1,
					"maximum": 864e5
				},
				"retryAfterMilliseconds": {
					"description": "The retry delay at logical time zero after the fixed-window bound is reached. It equals the initial window duration.",
					"type": "integer",
					"minimum": 1,
					"maximum": 864e5
				}
			}
		},
		"given": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"artifacts",
				"permissions",
				"sessionGeneration"
			],
			"properties": {
				"artifacts": {
					"type": "array",
					"maxItems": 20,
					"items": { "$ref": "#/$defs/artifactSeed" }
				},
				"permissions": {
					"type": "array",
					"maxItems": 50,
					"uniqueItems": true,
					"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
				},
				"rateLimits": {
					"type": "array",
					"maxItems": 20,
					"items": { "$ref": "#/$defs/rateLimit" }
				},
				"sessionGeneration": { "$ref": "common.schema.json#/$defs/revision" }
			}
		},
		"requestContext": {
			"description": "The canonical host request envelope. Malformed single exchanges remain in the original host corpus; an intentional registered-capability mismatch is an executable invalid-request assertion here.",
			"$ref": "host-request.schema.json#/$defs/requestContext"
		},
		"resultExpectation": {
			"type": "object",
			"additionalProperties": false,
			"required": ["outcome"],
			"properties": {
				"outcome": { "const": "result" },
				"revisionAdvancesFrom": { "$ref": "common.schema.json#/$defs/revision" },
				"sameAs": { "$ref": "common.schema.json#/$defs/stableId" },
				"value": { "enum": ["null", "rendered"] }
			}
		},
		"errorExpectation": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"outcome",
				"category",
				"retryable"
			],
			"properties": {
				"outcome": { "const": "error" },
				"category": { "enum": [
					"cancelled",
					"conflict",
					"forbidden",
					"incompatible",
					"internal",
					"invalid-request",
					"limit-exceeded",
					"not-found",
					"rate-limited",
					"unauthenticated",
					"unavailable",
					"validation-failed"
				] },
				"retryable": { "type": "boolean" },
				"retryAfterMilliseconds": {
					"type": "integer",
					"minimum": 0,
					"maximum": 864e5
				}
			}
		},
		"expectation": { "oneOf": [{ "$ref": "#/$defs/resultExpectation" }, { "$ref": "#/$defs/errorExpectation" }] },
		"invokeStep": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"action",
				"id",
				"port",
				"operation",
				"context",
				"completion"
			],
			"properties": {
				"action": { "const": "invoke" },
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"port": { "$ref": "#/$defs/port" },
				"operation": { "$ref": "common.schema.json#/$defs/localName" },
				"context": { "$ref": "#/$defs/requestContext" },
				"argument": { "$ref": "common.schema.json#/$defs/jsonValue" },
				"completion": { "enum": ["pending", "settled"] },
				"expect": { "$ref": "#/$defs/expectation" }
			},
			"allOf": [{
				"if": {
					"properties": { "completion": { "const": "settled" } },
					"required": ["completion"]
				},
				"then": {
					"properties": { "expect": { "$ref": "#/$defs/expectation" } },
					"required": ["expect"]
				},
				"else": { "not": { "required": ["expect"] } }
			}, {
				"if": {
					"properties": {
						"port": { "const": "preview" },
						"operation": { "const": "render" }
					},
					"required": ["port", "operation"]
				},
				"then": {
					"properties": { "argument": { "$ref": "preview-message.schema.json#/$defs/render" } },
					"required": ["argument"]
				}
			}]
		},
		"settleStep": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"action",
				"id",
				"invocation",
				"expect"
			],
			"properties": {
				"action": { "const": "settle" },
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"invocation": { "$ref": "common.schema.json#/$defs/stableId" },
				"expect": { "$ref": "#/$defs/expectation" }
			}
		},
		"advanceClockStep": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"action",
				"id",
				"milliseconds"
			],
			"properties": {
				"action": { "const": "advance-clock" },
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"milliseconds": {
					"type": "integer",
					"minimum": 1,
					"maximum": 864e5
				}
			}
		},
		"releasePreviewRenderStep": {
			"description": "A deterministic renderer completion injected after a prior preview.render invocation. It is a harness precondition, not a host port or transport operation.",
			"type": "object",
			"additionalProperties": false,
			"required": [
				"action",
				"id",
				"invocation",
				"value"
			],
			"properties": {
				"action": { "const": "release-preview-render" },
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"invocation": { "$ref": "common.schema.json#/$defs/stableId" },
				"value": { "$ref": "preview-message.schema.json#/$defs/rendered" }
			}
		},
		"artifactFinalState": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"status",
				"revisionFrom"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"status": { "enum": [
					"archived",
					"draft",
					"in-review",
					"published",
					"retired"
				] },
				"revisionFrom": { "$ref": "common.schema.json#/$defs/stableId" }
			}
		},
		"recoveryFinalState": {
			"type": "object",
			"additionalProperties": false,
			"required": ["resourceContextKey", "value"],
			"properties": {
				"resourceContextKey": { "$ref": "common.schema.json#/$defs/stableId" },
				"value": { "$ref": "common.schema.json#/$defs/jsonValue" }
			}
		},
		"finalState": {
			"type": "object",
			"additionalProperties": false,
			"required": ["pendingPreviewRenders", "previewDeliveries"],
			"properties": {
				"artifacts": {
					"type": "array",
					"maxItems": 20,
					"items": { "$ref": "#/$defs/artifactFinalState" }
				},
				"recovery": {
					"type": "array",
					"maxItems": 20,
					"items": { "$ref": "#/$defs/recoveryFinalState" }
				},
				"pendingPreviewRenders": { "const": 0 },
				"previewDeliveries": {
					"type": "array",
					"maxItems": 20,
					"items": {
						"type": "string",
						"pattern": "^[a-f0-9]{64}(?![\\s\\S])"
					}
				}
			}
		}
	}
};
var inspector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/inspector.schema.json",
	title: "Studio inspector contribution",
	description: "The declarative payload behind an `inspector` plugin contribution: a panel an extension offers for inspecting or configuring the block types it declares. The declaration is inert data; whether its executable half runs, and in which realm, stays a host decision recorded in the plugin manifest.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"owner",
		"label",
		"blockTypes",
		"placement"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "inspector" },
		"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"blockTypes": {
			"description": "The block types this inspector applies to. It never sees a node of any other type.",
			"type": "array",
			"minItems": 1,
			"maxItems": 500,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
		},
		"placement": {
			"description": "Whether the panel augments the built-in inspector or replaces it for the declared block types. Replacement never removes the host's own policy and accessibility surfaces.",
			"enum": ["augment", "replace"]
		},
		"requiredCapability": {
			"description": "The capability the executable half requires. A declaration without one is inspectable but never executed.",
			"$ref": "common.schema.json#/$defs/qualifiedName"
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var media_asset_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/media-asset.schema.json",
	title: "Studio host-owned media asset",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"revision",
		"state",
		"mediaKind",
		"mediaType",
		"byteSize",
		"filename",
		"metadata"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "media-asset" },
		"id": {
			"description": "Host-assigned stable asset identity. Its presence does not grant access, readiness, publication, or delivery authority.",
			"$ref": "common.schema.json#/$defs/stableId"
		},
		"revision": { "$ref": "common.schema.json#/$defs/revision" },
		"state": {
			"description": "Host lifecycle projection. An accepted stable asset can remain processing; every use and publication decision is host-policy gated.",
			"enum": [
				"processing",
				"ready",
				"rejected",
				"quarantined",
				"archived"
			]
		},
		"mediaKind": { "enum": [
			"image",
			"video",
			"audio",
			"document",
			"archive",
			"other"
		] },
		"mediaType": {
			"type": "string",
			"pattern": "^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+(?![\\s\\S])",
			"maxLength": 200
		},
		"byteSize": {
			"type": "integer",
			"minimum": 0,
			"maximum": 1099511627776
		},
		"filename": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500
		},
		"metadata": {
			"type": "object",
			"additionalProperties": false,
			"properties": {
				"width": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e6
				},
				"height": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e6
				},
				"durationMilliseconds": {
					"type": "integer",
					"minimum": 0,
					"maximum": 864e6
				},
				"altText": {
					"type": "string",
					"maxLength": 5e3
				},
				"decorative": { "type": "boolean" },
				"caption": {
					"type": "string",
					"maxLength": 2e4
				},
				"credit": {
					"type": "string",
					"maxLength": 2e3
				},
				"license": {
					"type": "string",
					"maxLength": 500
				},
				"focalPoint": {
					"type": "object",
					"additionalProperties": false,
					"required": ["x", "y"],
					"properties": {
						"x": {
							"type": "number",
							"minimum": 0,
							"maximum": 1
						},
						"y": {
							"type": "number",
							"minimum": 0,
							"maximum": 1
						}
					}
				}
			}
		},
		"renditions": {
			"type": "array",
			"maxItems": 100,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"mediaType",
					"width",
					"height"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/localName" },
					"mediaType": {
						"type": "string",
						"pattern": "^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+(?![\\s\\S])",
						"maxLength": 200
					},
					"width": {
						"type": "integer",
						"minimum": 1,
						"maximum": 1e6
					},
					"height": {
						"type": "integer",
						"minimum": 1,
						"maximum": 1e6
					}
				}
			}
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var media_reference_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/media-reference.schema.json",
	title: "Studio persisted media reference",
	description: "A small, usage-specific reference stored in an artifact. It contains no URL, binary data, storage path, credential, or host delivery authority.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"assetId",
		"usage",
		"accessibility"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "media-reference" },
		"assetId": { "$ref": "common.schema.json#/$defs/stableId" },
		"assetRevision": { "$ref": "common.schema.json#/$defs/revision" },
		"usage": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"renditionIntent": {
			"type": "object",
			"additionalProperties": false,
			"required": ["role"],
			"properties": {
				"role": { "$ref": "common.schema.json#/$defs/localName" },
				"fit": { "enum": [
					"contain",
					"cover",
					"fill",
					"scale-down"
				] },
				"preferredMediaTypes": {
					"type": "array",
					"maxItems": 10,
					"uniqueItems": true,
					"items": {
						"type": "string",
						"pattern": "^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+(?![\\s\\S])",
						"maxLength": 200
					}
				}
			}
		},
		"focalPoint": {
			"type": "object",
			"additionalProperties": false,
			"required": ["x", "y"],
			"properties": {
				"x": {
					"type": "number",
					"minimum": 0,
					"maximum": 1
				},
				"y": {
					"type": "number",
					"minimum": 0,
					"maximum": 1
				}
			}
		},
		"cropIntent": { "oneOf": [{
			"type": "object",
			"additionalProperties": false,
			"required": [
				"mode",
				"width",
				"height"
			],
			"properties": {
				"mode": { "const": "aspect-ratio" },
				"width": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e4
				},
				"height": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e4
				}
			}
		}, {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"mode",
				"x",
				"y",
				"width",
				"height"
			],
			"properties": {
				"mode": { "const": "rectangle" },
				"x": {
					"type": "number",
					"minimum": 0,
					"maximum": 1
				},
				"y": {
					"type": "number",
					"minimum": 0,
					"maximum": 1
				},
				"width": {
					"type": "number",
					"exclusiveMinimum": 0,
					"maximum": 1
				},
				"height": {
					"type": "number",
					"exclusiveMinimum": 0,
					"maximum": 1
				}
			}
		}] },
		"accessibility": { "oneOf": [
			{
				"type": "object",
				"additionalProperties": false,
				"required": ["mode", "altText"],
				"properties": {
					"mode": { "const": "informative" },
					"altText": {
						"type": "string",
						"minLength": 1,
						"maxLength": 5e3
					},
					"caption": {
						"type": "string",
						"maxLength": 2e4
					}
				}
			},
			{
				"type": "object",
				"additionalProperties": false,
				"required": ["mode"],
				"properties": { "mode": { "const": "decorative" } }
			},
			{
				"type": "object",
				"additionalProperties": false,
				"required": ["mode", "altFieldPath"],
				"properties": {
					"mode": { "const": "bound" },
					"altFieldPath": {
						"type": "array",
						"minItems": 1,
						"maxItems": 32,
						"items": { "$ref": "common.schema.json#/$defs/localName" }
					},
					"captionFieldPath": {
						"type": "array",
						"minItems": 1,
						"maxItems": 32,
						"items": { "$ref": "common.schema.json#/$defs/localName" }
					}
				}
			}
		] }
	}
};
var media_upload_grant_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/media-upload-grant.schema.json",
	title: "Studio media upload grant",
	description: "The host's authorization to transfer one upload. Bytes never cross the JSON port: the host issues a short-lived, single-purpose destination it controls and the client transfers directly to it, so custody, quotas and storage placement stay host-owned and a large body never traverses the port transport. A grant authorizes exactly the declared upload and expires; it is not a credential the client may reuse, cache, or apply to another upload.",
	type: "object",
	additionalProperties: false,
	required: [
		"expiresAt",
		"method",
		"plan",
		"uploadId",
		"url"
	],
	properties: {
		"expiresAt": {
			"description": "When the grant stops being honoured. A client that has not completed by then re-authorizes rather than retrying against a dead destination.",
			"$ref": "common.schema.json#/$defs/rfc3339Instant"
		},
		"headers": {
			"description": "Headers the client must send verbatim on the transfer. They carry no user credential and are never logged by the client.",
			"type": "object",
			"maxProperties": 20,
			"propertyNames": {
				"type": "string",
				"pattern": "^[A-Za-z][A-Za-z0-9-]*(?![\\s\\S])",
				"maxLength": 100
			},
			"additionalProperties": {
				"type": "string",
				"maxLength": 2e3
			}
		},
		"method": { "enum": ["POST", "PUT"] },
		"plan": {
			"description": "The bounded transfer plan the host derived from its policy. The client transfers within it and never negotiates a larger bound.",
			"type": "object",
			"additionalProperties": false,
			"required": ["maximumBytes", "resumable"],
			"properties": {
				"chunkBytes": {
					"type": "integer",
					"minimum": 1024,
					"maximum": 1073741824
				},
				"maximumBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1099511627776
				},
				"resumable": { "type": "boolean" }
			}
		},
		"uploadId": {
			"description": "The opaque host-issued upload identity used to complete or abort the transfer.",
			"$ref": "common.schema.json#/$defs/stableId"
		},
		"url": {
			"description": "The absolute https destination the host controls. It is never an author-supplied value and never a Studio-derived address.",
			"type": "string",
			"pattern": "^https://",
			"maxLength": 2e3
		}
	}
};
var media_upload_session_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/media-upload-session.schema.json",
	title: "Studio media upload session",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"request",
		"state",
		"progress"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "media-upload-session" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"request": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"filename",
				"mediaType",
				"byteSize",
				"purpose"
			],
			"properties": {
				"filename": {
					"type": "string",
					"minLength": 1,
					"maxLength": 255,
					"pattern": "^[^\\u0000-\\u001F\\u007F/\\\\]+(?![\\s\\S])"
				},
				"mediaType": {
					"type": "string",
					"pattern": "^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*(?![\\s\\S])",
					"maxLength": 120
				},
				"byteSize": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1099511627776
				},
				"purpose": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"checksum": { "$ref": "common.schema.json#/$defs/integrity" }
			}
		},
		"plan": { "$ref": "#/$defs/plan" },
		"state": { "enum": [
			"requested",
			"authorized",
			"transferring",
			"verifying",
			"complete",
			"failed",
			"cancelled"
		] },
		"progress": {
			"type": "object",
			"additionalProperties": false,
			"required": ["transferredBytes", "totalBytes"],
			"properties": {
				"transferredBytes": {
					"type": "integer",
					"minimum": 0,
					"maximum": 1099511627776
				},
				"totalBytes": {
					"type": "integer",
					"minimum": 0,
					"maximum": 1099511627776
				}
			}
		},
		"asset": { "$ref": "#/$defs/acceptedAsset" },
		"failure": { "$ref": "common.schema.json#/$defs/diagnostic" }
	},
	allOf: [
		{
			"if": {
				"properties": { "state": { "const": "complete" } },
				"required": ["state"]
			},
			"then": {
				"properties": { "asset": { "$ref": "#/$defs/acceptedAsset" } },
				"required": ["asset"]
			}
		},
		{
			"if": {
				"properties": { "state": { "const": "failed" } },
				"required": ["state"]
			},
			"then": {
				"properties": { "failure": { "$ref": "common.schema.json#/$defs/diagnostic" } },
				"required": ["failure"]
			}
		},
		{
			"if": {
				"properties": { "state": { "enum": [
					"authorized",
					"transferring",
					"verifying"
				] } },
				"required": ["state"]
			},
			"then": {
				"properties": { "plan": { "$ref": "#/$defs/plan" } },
				"required": ["plan"]
			}
		},
		{
			"if": {
				"properties": { "state": { "const": "requested" } },
				"required": ["state"]
			},
			"then": { "not": { "required": ["asset"] } }
		}
	],
	$defs: {
		"acceptedAsset": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"revision",
				"state"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"revision": { "$ref": "common.schema.json#/$defs/revision" },
				"state": { "enum": [
					"processing",
					"ready",
					"rejected",
					"quarantined"
				] }
			}
		},
		"plan": {
			"type": "object",
			"additionalProperties": false,
			"required": ["maximumBytes", "resumable"],
			"properties": {
				"maximumBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1099511627776
				},
				"chunkBytes": {
					"type": "integer",
					"minimum": 1024,
					"maximum": 1073741824
				},
				"resumable": { "type": "boolean" }
			}
		}
	}
};
var media_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/media-vector.schema.json",
	title: "Studio canonical media policy vector",
	description: "One canonical upload-policy decision: a host-declared policy, an upload request, and either the deterministic policy-derived plan or a stable failure code, optionally extended with cancellation and retry legality for the media-upload-session state machine.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"policy",
		"request",
		"expect"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "media-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500
		},
		"policy": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"acceptedMediaTypes",
				"maximumBytes",
				"resumable"
			],
			"properties": {
				"acceptedMediaTypes": {
					"type": "array",
					"minItems": 1,
					"maxItems": 100,
					"items": { "$ref": "#/$defs/mediaType" }
				},
				"chunkBytes": {
					"type": "integer",
					"minimum": 1024,
					"maximum": 1073741824
				},
				"maximumBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1099511627776
				},
				"resumable": { "type": "boolean" }
			}
		},
		"request": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"filename",
				"mediaType",
				"byteSize",
				"purpose"
			],
			"properties": {
				"filename": {
					"description": "Deliberately looser than the media-upload-session request shape so rejection vectors can carry filenames the canonical contract refuses.",
					"type": "string",
					"maxLength": 500
				},
				"mediaType": { "$ref": "#/$defs/mediaType" },
				"byteSize": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1099511627776
				},
				"purpose": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"checksum": { "$ref": "common.schema.json#/$defs/integrity" }
			}
		},
		"expect": { "oneOf": [{
			"type": "object",
			"additionalProperties": false,
			"required": ["outcome", "plan"],
			"properties": {
				"outcome": { "const": "accepted" },
				"plan": { "$ref": "#/$defs/plan" }
			}
		}, {
			"type": "object",
			"additionalProperties": false,
			"required": ["outcome", "code"],
			"properties": {
				"outcome": { "const": "rejected" },
				"code": { "$ref": "#/$defs/failureCode" },
				"messageMustNotContain": {
					"description": "Raw request or policy values the user-facing failure message MUST NOT echo.",
					"type": "array",
					"minItems": 1,
					"maxItems": 10,
					"items": {
						"type": "string",
						"minLength": 1,
						"maxLength": 200
					}
				}
			}
		}] },
		"cancel": {
			"description": "Cancellation legality: the session state during which cancel() is issued and the resulting terminal state. Cancellation from an active state ends in cancelled; cancellation after completion is a no-op.",
			"type": "object",
			"additionalProperties": false,
			"required": ["during", "finalState"],
			"properties": {
				"during": { "enum": [
					"complete",
					"requested",
					"transferring",
					"verifying"
				] },
				"finalState": { "enum": ["cancelled", "complete"] }
			}
		},
		"retry": {
			"description": "Retry legality for a rejected request: retry is legal only from the failed state and always runs under a fresh session identity with the identical request.",
			"type": "object",
			"additionalProperties": false,
			"required": ["freshSession"],
			"properties": { "freshSession": { "const": true } }
		}
	},
	$defs: {
		"failureCode": { "enum": ["studio.media/upload-failed", "studio.media/upload-too-large"] },
		"mediaType": {
			"type": "string",
			"pattern": "^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*(?![\\s\\S])",
			"maxLength": 120
		},
		"plan": {
			"type": "object",
			"additionalProperties": false,
			"required": ["maximumBytes", "resumable"],
			"properties": {
				"maximumBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1099511627776
				},
				"chunkBytes": {
					"type": "integer",
					"minimum": 1024,
					"maximum": 1073741824
				},
				"resumable": { "type": "boolean" }
			}
		}
	}
};
var migration_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/migration.schema.json",
	title: "Studio migration declaration",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"owner",
		"label",
		"artifactKinds",
		"sourceVersions",
		"targetVersion",
		"lossClassification"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "migration" },
		"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"artifactKinds": {
			"type": "array",
			"minItems": 1,
			"maxItems": 5,
			"uniqueItems": true,
			"items": { "enum": [
				"block-definition",
				"blueprint",
				"content-model",
				"entry",
				"theme"
			] }
		},
		"sourceVersions": { "$ref": "common.schema.json#/$defs/versionRange" },
		"targetVersion": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"lossClassification": { "enum": ["lossless", "lossy"] },
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var pattern_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/pattern.schema.json",
	title: "Studio composition pattern",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"revision",
		"owner",
		"label",
		"blockDependencies",
		"roots"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "pattern" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"revision": { "$ref": "common.schema.json#/$defs/revision" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"blockDependencies": {
			"type": "array",
			"maxItems": 200,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"type",
					"version",
					"revision"
				],
				"properties": {
					"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
					"revision": { "$ref": "common.schema.json#/$defs/revision" },
					"integrity": { "$ref": "common.schema.json#/$defs/integrity" }
				}
			}
		},
		"roots": {
			"type": "array",
			"minItems": 1,
			"maxItems": 100,
			"items": { "$ref": "blueprint.schema.json#/$defs/node" }
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var plugin_manifest_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/plugin-manifest.schema.json",
	title: "Studio plugin manifest",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"owner",
		"label",
		"activation",
		"entryModules",
		"contributions",
		"requiredCapabilities",
		"optionalCapabilities",
		"dependencies",
		"permissions"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "plugin-manifest" },
		"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"activation": { "enum": ["declarative", "executable"] },
		"entryModules": {
			"type": "array",
			"maxItems": 10,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"realm",
					"path",
					"integrity"
				],
				"properties": {
					"realm": { "enum": [
						"application",
						"worker",
						"sandboxed-frame"
					] },
					"path": { "$ref": "common.schema.json#/$defs/packageRelativePath" },
					"integrity": { "$ref": "common.schema.json#/$defs/integrity" }
				}
			}
		},
		"contributions": {
			"type": "array",
			"maxItems": 5e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"kind",
					"id",
					"version",
					"resource",
					"integrity"
				],
				"properties": {
					"kind": { "enum": [
						"authoring-target",
						"block",
						"command",
						"design-vocabulary",
						"field-adapter",
						"inspector",
						"locale",
						"migration",
						"panel",
						"pattern",
						"renderer-capability",
						"test-fixture",
						"transform"
					] },
					"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
					"resource": { "$ref": "common.schema.json#/$defs/packageRelativePath" },
					"integrity": { "$ref": "common.schema.json#/$defs/integrity" },
					"executable": {
						"type": "boolean",
						"default": false
					}
				}
			}
		},
		"requiredCapabilities": {
			"type": "array",
			"maxItems": 100,
			"items": { "$ref": "#/$defs/capabilityRequirement" }
		},
		"optionalCapabilities": {
			"type": "array",
			"maxItems": 100,
			"items": { "$ref": "#/$defs/capabilityRequirement" }
		},
		"dependencies": {
			"type": "array",
			"maxItems": 100,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"versions",
					"optional"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"versions": { "$ref": "common.schema.json#/$defs/versionRange" },
					"optional": { "type": "boolean" }
				}
			}
		},
		"permissions": {
			"type": "array",
			"maxItems": 100,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
		},
		"locales": {
			"type": "array",
			"maxItems": 100,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/locale" }
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	},
	$defs: { "capabilityRequirement": {
		"type": "object",
		"additionalProperties": false,
		"required": ["id", "versions"],
		"properties": {
			"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
			"versions": { "$ref": "common.schema.json#/$defs/versionRange" }
		}
	} }
};
var preview_message_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/preview-message.schema.json",
	title: "Studio preview channel message",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"channelId",
		"sessionGeneration",
		"sequence",
		"type",
		"payload"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "preview-message" },
		"channelId": { "$ref": "common.schema.json#/$defs/stableId" },
		"sessionGeneration": { "$ref": "common.schema.json#/$defs/revision" },
		"sequence": {
			"type": "integer",
			"minimum": 0
		},
		"type": { "enum": [
			"studio.preview/activated",
			"studio.preview/dispose",
			"studio.preview/error",
			"studio.preview/measure",
			"studio.preview/measurements",
			"studio.preview/ready",
			"studio.preview/reload",
			"studio.preview/render",
			"studio.preview/rendered",
			"studio.preview/select",
			"studio.preview/teardown",
			"studio.preview/viewport"
		] },
		"payload": {
			"type": "object",
			"maxProperties": 1e3,
			"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
			"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
		}
	},
	allOf: [
		{
			"if": { "properties": { "type": { "const": "studio.preview/ready" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/ready" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/render" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/render" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/rendered" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/rendered" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/select" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/select" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/measure" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/measure" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/measurements" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/measurements" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/error" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/error" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/reload" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/reload" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/teardown" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/teardown" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/activated" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/activated" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/viewport" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/viewport" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/dispose" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/dispose" } } }
		}
	],
	$defs: {
		"previewMarker": {
			"description": "A deterministic marker for one node in a canonical draft: the draft digest followed by its zero-based Blueprint preorder ordinal.",
			"type": "string",
			"pattern": "^studio\\.preview/node/[0-9a-f]{64}/(?:0|[1-9][0-9]{0,4})(?![\\s\\S])",
			"maxLength": 90
		},
		"ready": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"protocolVersion",
				"renderer",
				"viewports"
			],
			"properties": {
				"protocolVersion": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"renderer": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"viewports": {
					"type": "array",
					"maxItems": 20,
					"items": { "$ref": "common.schema.json#/$defs/localName" }
				}
			}
		},
		"render": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"artifactId",
				"draftDigest",
				"draftRevision",
				"requestId",
				"viewport"
			],
			"properties": {
				"artifactId": { "$ref": "common.schema.json#/$defs/stableId" },
				"draftDigest": {
					"type": "string",
					"pattern": "^[a-f0-9]{64}(?![\\s\\S])"
				},
				"draftRevision": { "$ref": "common.schema.json#/$defs/revision" },
				"requestId": {
					"$ref": "common.schema.json#/$defs/stableId",
					"description": "A session-unique render attempt identifier. Retries use a new identifier even when draft and viewport are unchanged."
				},
				"viewport": { "$ref": "common.schema.json#/$defs/localName" }
			}
		},
		"rendered": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"requestId",
				"draftDigest",
				"markers",
				"markerMap",
				"diagnostics"
			],
			"properties": {
				"draftDigest": {
					"type": "string",
					"pattern": "^[a-f0-9]{64}(?![\\s\\S])"
				},
				"requestId": {
					"$ref": "common.schema.json#/$defs/stableId",
					"description": "The exact render attempt this response settles."
				},
				"markers": {
					"type": "array",
					"maxItems": 1e5,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/previewMarker" }
				},
				"markerMap": {
					"type": "object",
					"maxProperties": 1e5,
					"propertyNames": { "$ref": "#/$defs/previewMarker" },
					"additionalProperties": { "$ref": "common.schema.json#/$defs/stableId" }
				},
				"diagnostics": {
					"type": "array",
					"maxItems": 1e4,
					"items": { "$ref": "common.schema.json#/$defs/diagnostic" }
				}
			}
		},
		"select": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId"],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"reveal": {
					"type": "boolean",
					"default": true
				}
			}
		},
		"measure": {
			"type": "object",
			"additionalProperties": false,
			"required": ["requestId", "markers"],
			"properties": {
				"requestId": {
					"$ref": "common.schema.json#/$defs/stableId",
					"description": "A session-unique measurement attempt identifier, never reused for another render or measurement."
				},
				"markers": {
					"type": "array",
					"minItems": 1,
					"maxItems": 1e3,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/previewMarker" }
				}
			}
		},
		"measurements": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"requestId",
				"draftDigest",
				"measurements",
				"unknown",
				"viewport"
			],
			"properties": {
				"requestId": { "$ref": "common.schema.json#/$defs/stableId" },
				"draftDigest": {
					"type": "string",
					"pattern": "^[a-f0-9]{64}(?![\\s\\S])"
				},
				"measurements": {
					"type": "object",
					"maxProperties": 1e3,
					"propertyNames": { "$ref": "#/$defs/previewMarker" },
					"additionalProperties": {
						"type": "array",
						"minItems": 1,
						"maxItems": 1e3,
						"items": { "$ref": "#/$defs/markerRect" }
					}
				},
				"unknown": {
					"type": "array",
					"maxItems": 1e3,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/previewMarker" }
				},
				"viewport": { "$ref": "#/$defs/viewportMetrics" }
			}
		},
		"markerRect": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"x",
				"y",
				"width",
				"height"
			],
			"properties": {
				"x": {
					"type": "number",
					"minimum": -1e8,
					"maximum": 1e8
				},
				"y": {
					"type": "number",
					"minimum": -1e8,
					"maximum": 1e8
				},
				"width": {
					"type": "number",
					"minimum": 0,
					"maximum": 1e8
				},
				"height": {
					"type": "number",
					"minimum": 0,
					"maximum": 1e8
				}
			}
		},
		"viewportMetrics": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"width",
				"height",
				"scrollX",
				"scrollY",
				"devicePixelRatio"
			],
			"properties": {
				"width": {
					"type": "number",
					"minimum": 0,
					"maximum": 1e8
				},
				"height": {
					"type": "number",
					"minimum": 0,
					"maximum": 1e8
				},
				"scrollX": {
					"type": "number",
					"minimum": -1e8,
					"maximum": 1e8
				},
				"scrollY": {
					"type": "number",
					"minimum": -1e8,
					"maximum": 1e8
				},
				"devicePixelRatio": {
					"type": "number",
					"exclusiveMinimum": 0,
					"maximum": 100
				}
			}
		},
		"error": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"code",
				"message",
				"retryable"
			],
			"properties": {
				"code": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"message": { "$ref": "common.schema.json#/$defs/messageReference" },
				"retryable": { "type": "boolean" },
				"correlationId": { "$ref": "common.schema.json#/$defs/stableId" }
			}
		},
		"reload": {
			"type": "object",
			"additionalProperties": false,
			"required": ["reason"],
			"properties": { "reason": { "$ref": "common.schema.json#/$defs/qualifiedName" } }
		},
		"teardown": {
			"type": "object",
			"additionalProperties": false,
			"required": ["reason"],
			"properties": { "reason": { "$ref": "common.schema.json#/$defs/qualifiedName" } }
		},
		"activated": {
			"type": "object",
			"additionalProperties": false,
			"required": ["interaction", "marker"],
			"properties": {
				"interaction": {
					"description": "How the author reached the region. The renderer reports intent, never raw input events.",
					"enum": [
						"activate",
						"context-menu",
						"focus"
					]
				},
				"marker": {
					"description": "A marker from the renderer's currently accepted inventory. The channel drops invented and stale markers.",
					"$ref": "#/$defs/previewMarker"
				}
			}
		},
		"viewport": {
			"description": "A semantic viewport role and explicit dimensions are alternatives, never a merge. Each branch is closed, so a payload naming both matches neither.",
			"oneOf": [{
				"type": "object",
				"additionalProperties": false,
				"required": ["viewport"],
				"properties": { "viewport": {
					"$ref": "common.schema.json#/$defs/localName",
					"description": "The theme-declared semantic viewport role to render at."
				} }
			}, {
				"type": "object",
				"additionalProperties": false,
				"minProperties": 1,
				"properties": {
					"height": {
						"description": "Bounded CSS-pixel height.",
						"type": "integer",
						"minimum": 240,
						"maximum": 1e4
					},
					"width": {
						"description": "Bounded CSS-pixel width.",
						"type": "integer",
						"minimum": 240,
						"maximum": 1e4
					}
				}
			}]
		},
		"dispose": {
			"type": "object",
			"additionalProperties": false,
			"required": ["reason"],
			"properties": {
				"draftDigest": {
					"description": "Revoke only the resources held for this render. Absent revokes every draft resource the renderer holds.",
					"type": "string",
					"pattern": "^[0-9a-f]{64}(?![\\s\\S])"
				},
				"reason": { "$ref": "common.schema.json#/$defs/qualifiedName" }
			}
		}
	}
};
var preview_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/preview-vector.schema.json",
	title: "Studio preview identity conformance vector",
	description: "One complete Blueprint draft, its exact render-attempt identity, and the SHA-256 plus deterministic marker inventory every implementation must reproduce under the preview identity profile.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"profile",
		"protocolVersion",
		"draft",
		"render",
		"expect"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "preview-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 1e3
		},
		"profile": { "const": "studio.profile/preview-identity-v1" },
		"protocolVersion": { "const": "0.1.0-draft.2" },
		"draft": { "$ref": "blueprint.schema.json" },
		"render": { "$ref": "preview-message.schema.json#/$defs/render" },
		"expect": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"draftDigest",
				"markers",
				"markerMap"
			],
			"properties": {
				"draftDigest": {
					"type": "string",
					"pattern": "^[0-9a-f]{64}(?![\\s\\S])"
				},
				"markers": {
					"type": "array",
					"maxItems": 1e5,
					"uniqueItems": true,
					"items": { "$ref": "preview-message.schema.json#/$defs/previewMarker" }
				},
				"markerMap": {
					"type": "object",
					"maxProperties": 1e5,
					"propertyNames": { "$ref": "preview-message.schema.json#/$defs/previewMarker" },
					"additionalProperties": { "$ref": "common.schema.json#/$defs/stableId" }
				}
			}
		}
	}
};
var renderer_web_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/renderer-web-vector.schema.json",
	title: "Studio renderer-web conformance vector",
	type: "object",
	additionalProperties: false,
	required: [
		"id",
		"coverage",
		"roots",
		"bindings",
		"media",
		"expect"
	],
	properties: {
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"coverage": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"blockTypes",
				"behaviors",
				"presentation",
				"security"
			],
			"properties": {
				"blockTypes": {
					"type": "array",
					"maxItems": 100,
					"uniqueItems": true,
					"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
				},
				"behaviors": {
					"type": "array",
					"maxItems": 50,
					"uniqueItems": true,
					"items": { "enum": [
						"accordion-native",
						"countdown",
						"dialog",
						"lightbox",
						"navigation-disclosure",
						"notice-dismiss",
						"popover",
						"slideshow",
						"tabs"
					] }
				},
				"presentation": {
					"type": "array",
					"maxItems": 50,
					"uniqueItems": true,
					"items": { "enum": [
						"alignment",
						"inverse",
						"markers",
						"motion",
						"position",
						"print",
						"responsive-visibility",
						"scrolling",
						"sizing",
						"spacing"
					] }
				},
				"security": {
					"type": "array",
					"maxItems": 50,
					"uniqueItems": true,
					"items": { "enum": [
						"active-media-deny",
						"blob-default-deny",
						"escaped-text",
						"safe-url-deny",
						"typed-data-fallback"
					] }
				}
			}
		},
		"context": {
			"type": "object",
			"additionalProperties": false,
			"properties": {
				"allowBlobMedia": { "type": "boolean" },
				"scopedStyles": {
					"description": "Trusted structured host style input keyed by the exact Blueprint node ID.",
					"type": "object",
					"maxProperties": 100,
					"propertyNames": { "$ref": "common.schema.json#/$defs/stableId" },
					"additionalProperties": {
						"type": "object",
						"additionalProperties": false,
						"required": ["rules"],
						"properties": { "rules": {
							"type": "array",
							"maxItems": 100,
							"items": {
								"type": "object",
								"additionalProperties": false,
								"required": ["target", "declarations"],
								"properties": {
									"target": { "enum": [
										"action",
										"content",
										"heading",
										"media",
										"self"
									] },
									"declarations": {
										"type": "object",
										"maxProperties": 50,
										"propertyNames": { "enum": [
											"background-color",
											"border-color",
											"border-radius",
											"border-style",
											"border-width",
											"color",
											"font-family",
											"font-size",
											"font-style",
											"font-weight",
											"gap",
											"letter-spacing",
											"line-height",
											"margin-block",
											"margin-inline",
											"max-inline-size",
											"min-block-size",
											"opacity",
											"padding-block",
											"padding-inline",
											"text-align",
											"text-decoration",
											"text-transform"
										] },
										"additionalProperties": {
											"type": "string",
											"maxLength": 256,
											"pattern": "^(?:#[0-9A-Fa-f]{3,8}|-?[0-9]+(?:\\.[0-9]+)?(?:ch|em|rem|%|px)?|[a-z][a-z0-9 -]{0,126}|var\\(--studio-[a-z0-9-]{1,100}\\))$"
										}
									}
								}
							}
						} }
					}
				}
			}
		},
		"roots": {
			"type": "array",
			"minItems": 1,
			"maxItems": 100,
			"items": { "$ref": "blueprint.schema.json#/$defs/node" }
		},
		"bindings": {
			"type": "array",
			"maxItems": 1e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"nodeId",
					"port",
					"value"
				],
				"properties": {
					"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
					"port": { "$ref": "common.schema.json#/$defs/localName" },
					"value": { "$ref": "common.schema.json#/$defs/jsonValue" }
				}
			}
		},
		"media": {
			"type": "array",
			"maxItems": 1e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"assetId",
					"src",
					"altText"
				],
				"properties": {
					"assetId": { "$ref": "common.schema.json#/$defs/stableId" },
					"src": {
						"type": "string",
						"minLength": 1,
						"maxLength": 2048
					},
					"altText": {
						"type": "string",
						"maxLength": 5e3
					},
					"mediaType": {
						"type": "string",
						"maxLength": 255
					},
					"caption": {
						"type": "string",
						"maxLength": 2e4
					},
					"width": {
						"type": "integer",
						"minimum": 1,
						"maximum": 1e5
					},
					"height": {
						"type": "integer",
						"minimum": 1,
						"maximum": 1e5
					}
				}
			}
		},
		"expect": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"htmlContains",
				"htmlExcludes",
				"htmlBytes",
				"htmlSha256",
				"cssBytes",
				"cssContains",
				"cssSha256",
				"publicStyleAsset",
				"enhancements",
				"activationMarkers"
			],
			"properties": {
				"activationMarkers": {
					"description": "The complete renderer-emitted data-attribute vocabulary a public enhancement runtime is allowed to discover in this vector.",
					"type": "array",
					"maxItems": 100,
					"uniqueItems": true,
					"items": {
						"type": "string",
						"minLength": 12,
						"maxLength": 100,
						"pattern": "^data-studio-[a-z0-9]+(?:-[a-z0-9]+)*$"
					}
				},
				"htmlContains": {
					"type": "array",
					"maxItems": 100,
					"items": {
						"type": "string",
						"maxLength": 1e4
					}
				},
				"htmlExcludes": {
					"type": "array",
					"maxItems": 100,
					"items": {
						"type": "string",
						"maxLength": 1e4
					}
				},
				"htmlBytes": {
					"description": "Exact UTF-8 byte length of the canonical renderer HTML, closing activation-marker topology as wire output.",
					"type": "integer",
					"minimum": 1,
					"maximum": 1048576
				},
				"htmlSha256": {
					"description": "Lowercase SHA-256 of the exact canonical renderer HTML bytes.",
					"type": "string",
					"pattern": "^[a-f0-9]{64}$"
				},
				"cssContains": {
					"type": "array",
					"maxItems": 100,
					"items": {
						"type": "string",
						"maxLength": 1e4
					}
				},
				"cssBytes": {
					"description": "Exact UTF-8 byte length of the canonical compact renderer stylesheet.",
					"type": "integer",
					"minimum": 1,
					"maximum": 262144
				},
				"cssSha256": {
					"description": "Lowercase SHA-256 of the exact canonical renderer stylesheet bytes.",
					"type": "string",
					"pattern": "^[a-f0-9]{64}$"
				},
				"publicStyleAsset": { "$ref": "studio-browser-assets.schema.json#/$defs/publicStyleAsset" },
				"enhancements": {
					"type": "array",
					"maxItems": 100,
					"items": { "enum": [
						"chart",
						"countdown",
						"diagram",
						"dialog",
						"lightbox",
						"math",
						"motion",
						"navigation",
						"notice",
						"popover",
						"slideshow",
						"tabs"
					] }
				}
			}
		}
	}
};
var rich_text_projection_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/rich-text-projection.schema.json",
	title: "Studio rich-text renderer-conformance fixture",
	type: "object",
	additionalProperties: false,
	required: [
		"description",
		"document",
		"projection"
	],
	properties: {
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500
		},
		"document": { "$ref": "rich-text.schema.json" },
		"projection": {
			"type": "array",
			"maxItems": 1e5,
			"items": { "$ref": "#/$defs/blockProjection" }
		}
	},
	$defs: {
		"blockProjection": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"type",
				"text",
				"spans",
				"embeds"
			],
			"properties": {
				"type": { "enum": [
					"checklistItem",
					"codeBlock",
					"heading",
					"horizontalRule",
					"paragraph",
					"tableCell"
				] },
				"text": {
					"type": "string",
					"maxLength": 25e4
				},
				"spans": {
					"type": "array",
					"maxItems": 1e5,
					"items": { "$ref": "#/$defs/span" }
				},
				"embeds": {
					"type": "array",
					"maxItems": 1e5,
					"items": { "$ref": "#/$defs/embed" }
				}
			}
		},
		"span": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"start",
				"end",
				"marks"
			],
			"properties": {
				"start": {
					"type": "integer",
					"minimum": 0,
					"maximum": 25e4
				},
				"end": {
					"type": "integer",
					"minimum": 1,
					"maximum": 25e4
				},
				"marks": {
					"type": "array",
					"minItems": 1,
					"maxItems": 5,
					"uniqueItems": true,
					"items": { "enum": [
						"bold",
						"code",
						"highlight",
						"italic",
						"strike"
					] }
				}
			}
		},
		"embed": {
			"type": "object",
			"additionalProperties": false,
			"required": ["index", "kind"],
			"properties": {
				"index": {
					"type": "integer",
					"minimum": 0,
					"maximum": 25e4
				},
				"kind": { "enum": ["hardBreak"] }
			}
		}
	}
};
var provenance_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/provenance.schema.json",
	title: "Studio artifact provenance record",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"artifact",
		"chain"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "provenance" },
		"artifact": {
			"type": "object",
			"additionalProperties": false,
			"required": ["id", "revision"],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"revision": { "$ref": "common.schema.json#/$defs/revision" }
			}
		},
		"chain": {
			"type": "array",
			"minItems": 1,
			"maxItems": 1e4,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"origin",
					"toRevision",
					"recordedAt",
					"actor"
				],
				"properties": {
					"origin": { "enum": [
						"authoring",
						"import",
						"migration",
						"pattern",
						"plugin",
						"system"
					] },
					"fromRevision": { "$ref": "common.schema.json#/$defs/revision" },
					"toRevision": { "$ref": "common.schema.json#/$defs/revision" },
					"recordedAt": { "$ref": "common.schema.json#/$defs/rfc3339Instant" },
					"actor": {
						"type": "object",
						"additionalProperties": false,
						"required": ["id"],
						"properties": {
							"id": { "$ref": "common.schema.json#/$defs/stableId" },
							"displayName": {
								"type": "string",
								"minLength": 1,
								"maxLength": 200
							}
						}
					},
					"sessionId": { "$ref": "common.schema.json#/$defs/stableId" },
					"commandCount": {
						"type": "integer",
						"minimum": 0,
						"maximum": 1e5
					},
					"source": { "$ref": "common.schema.json#/$defs/artifactReference" },
					"migrationId": { "$ref": "common.schema.json#/$defs/qualifiedName" }
				}
			}
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var rich_text_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/rich-text.schema.json",
	title: "Studio portable rich text",
	$ref: "#/$defs/doc",
	$defs: {
		"doc": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type"],
			"properties": {
				"type": { "const": "doc" },
				"content": {
					"type": "array",
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/block" }
				}
			}
		},
		"block": { "oneOf": [
			{ "$ref": "#/$defs/paragraph" },
			{ "$ref": "#/$defs/heading" },
			{ "$ref": "#/$defs/blockquote" },
			{ "$ref": "#/$defs/bulletList" },
			{ "$ref": "#/$defs/orderedList" },
			{ "$ref": "#/$defs/horizontalRule" },
			{ "$ref": "#/$defs/checklist" },
			{ "$ref": "#/$defs/table" },
			{ "$ref": "#/$defs/callout" },
			{ "$ref": "#/$defs/codeBlock" }
		] },
		"paragraph": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type"],
			"properties": {
				"type": { "const": "paragraph" },
				"content": {
					"type": "array",
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/inline" }
				}
			}
		},
		"heading": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "attrs"],
			"properties": {
				"type": { "const": "heading" },
				"attrs": {
					"type": "object",
					"additionalProperties": false,
					"required": ["level"],
					"properties": { "level": { "enum": [
						2,
						3,
						4
					] } }
				},
				"content": {
					"type": "array",
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/inline" }
				}
			}
		},
		"blockquote": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "content"],
			"properties": {
				"type": { "const": "blockquote" },
				"content": {
					"type": "array",
					"minItems": 1,
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/block" }
				}
			}
		},
		"bulletList": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "content"],
			"properties": {
				"type": { "const": "bulletList" },
				"content": {
					"type": "array",
					"minItems": 1,
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/listItem" }
				}
			}
		},
		"orderedList": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "content"],
			"properties": {
				"type": { "const": "orderedList" },
				"attrs": {
					"type": "object",
					"additionalProperties": false,
					"required": ["start"],
					"properties": { "start": {
						"type": "integer",
						"minimum": 1,
						"maximum": 1e6
					} }
				},
				"content": {
					"type": "array",
					"minItems": 1,
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/listItem" }
				}
			}
		},
		"listItem": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "content"],
			"properties": {
				"type": { "const": "listItem" },
				"content": {
					"type": "array",
					"minItems": 1,
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/block" }
				}
			}
		},
		"horizontalRule": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type"],
			"properties": { "type": { "const": "horizontalRule" } }
		},
		"checklist": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "content"],
			"properties": {
				"type": { "const": "checklist" },
				"content": {
					"type": "array",
					"minItems": 1,
					"maxItems": 500,
					"items": { "$ref": "#/$defs/checklistItem" }
				}
			}
		},
		"checklistItem": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "attrs"],
			"properties": {
				"type": { "const": "checklistItem" },
				"attrs": {
					"type": "object",
					"additionalProperties": false,
					"required": ["checked", "level"],
					"properties": {
						"checked": { "type": "boolean" },
						"level": {
							"type": "integer",
							"minimum": 0,
							"maximum": 4
						}
					}
				},
				"content": {
					"type": "array",
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/inline" }
				}
			}
		},
		"table": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"type",
				"attrs",
				"content"
			],
			"properties": {
				"type": { "const": "table" },
				"attrs": {
					"type": "object",
					"additionalProperties": false,
					"required": ["header"],
					"properties": { "header": { "type": "boolean" } }
				},
				"content": {
					"type": "array",
					"minItems": 1,
					"maxItems": 200,
					"items": { "$ref": "#/$defs/tableRow" }
				}
			}
		},
		"tableRow": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "content"],
			"properties": {
				"type": { "const": "tableRow" },
				"content": {
					"type": "array",
					"minItems": 1,
					"maxItems": 50,
					"items": { "$ref": "#/$defs/tableCell" }
				}
			}
		},
		"tableCell": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type"],
			"properties": {
				"type": { "const": "tableCell" },
				"content": {
					"type": "array",
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/inline" }
				}
			}
		},
		"callout": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"type",
				"attrs",
				"content"
			],
			"properties": {
				"type": { "const": "callout" },
				"attrs": {
					"type": "object",
					"additionalProperties": false,
					"required": ["tone"],
					"properties": { "tone": { "enum": [
						"danger",
						"info",
						"success",
						"warning"
					] } }
				},
				"content": {
					"type": "array",
					"minItems": 1,
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/block" }
				}
			}
		},
		"codeBlock": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"type",
				"attrs",
				"text"
			],
			"properties": {
				"type": { "const": "codeBlock" },
				"attrs": {
					"type": "object",
					"additionalProperties": false,
					"required": ["language"],
					"properties": { "language": {
						"type": "string",
						"pattern": "^[A-Za-z0-9][A-Za-z0-9+_.#-]{0,63}$"
					} }
				},
				"text": {
					"type": "string",
					"maxLength": 25e4
				}
			}
		},
		"hardBreak": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type"],
			"properties": { "type": { "const": "hardBreak" } }
		},
		"text": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "text"],
			"properties": {
				"type": { "const": "text" },
				"text": {
					"type": "string",
					"minLength": 1,
					"maxLength": 25e4
				},
				"marks": {
					"type": "array",
					"maxItems": 4,
					"items": { "$ref": "#/$defs/mark" }
				}
			}
		},
		"inline": { "oneOf": [{ "$ref": "#/$defs/text" }, { "$ref": "#/$defs/hardBreak" }] },
		"mark": { "oneOf": [{
			"type": "object",
			"additionalProperties": false,
			"required": ["type"],
			"properties": { "type": { "enum": [
				"bold",
				"code",
				"italic",
				"strike"
			] } }
		}, {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "attrs"],
			"properties": {
				"type": { "const": "highlight" },
				"attrs": {
					"type": "object",
					"additionalProperties": false,
					"required": ["tone"],
					"properties": { "tone": { "enum": [
						"accent",
						"danger",
						"info",
						"success",
						"warning"
					] } }
				}
			}
		}] }
	}
};
var reusable_content_type_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/reusable-content-type.schema.json",
	title: "Studio host-owned reusable content type projection",
	description: "The host-facing definition that coordinates exact Model and Blueprint revisions. It is not a portable Studio artifact and never contains Entry values.",
	$ref: "#/$defs/definition",
	$defs: {
		"reference": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"version",
				"revision"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"revision": { "$ref": "common.schema.json#/$defs/revision" }
			}
		},
		"authoringPolicy": {
			"type": "object",
			"additionalProperties": false,
			"required": ["modes", "itemComposition"],
			"properties": {
				"modes": {
					"type": "array",
					"minItems": 1,
					"maxItems": 3,
					"uniqueItems": true,
					"items": { "enum": [
						"model",
						"blueprint",
						"content"
					] }
				},
				"itemComposition": { "enum": ["denied", "overrides"] }
			}
		},
		"definition": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"contractVersion",
				"kind",
				"id",
				"version",
				"revision",
				"label",
				"status",
				"model",
				"blueprint",
				"authoringPolicy"
			],
			"properties": {
				"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
				"kind": { "const": "reusable-content-type" },
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"revision": { "$ref": "common.schema.json#/$defs/revision" },
				"label": { "$ref": "common.schema.json#/$defs/messageReference" },
				"status": { "enum": [
					"draft",
					"published",
					"retired"
				] },
				"model": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
				"blueprint": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
				"authoringPolicy": { "$ref": "#/$defs/authoringPolicy" },
				"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
			}
		},
		"summary": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"reference",
				"label",
				"model",
				"blueprint"
			],
			"properties": {
				"reference": { "$ref": "#/$defs/reference" },
				"label": { "$ref": "common.schema.json#/$defs/messageReference" },
				"model": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
				"blueprint": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" }
			}
		},
		"listQuery": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"targetId",
				"resourceContext",
				"limit"
			],
			"properties": {
				"targetId": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"resourceContext": { "$ref": "common.schema.json#/$defs/resourceContext" },
				"cursor": {
					"type": "string",
					"minLength": 1,
					"maxLength": 500
				},
				"limit": {
					"type": "integer",
					"minimum": 1,
					"maximum": 100
				},
				"search": {
					"type": "string",
					"maxLength": 500
				}
			}
		},
		"listPage": {
			"type": "object",
			"additionalProperties": false,
			"required": ["items"],
			"properties": {
				"items": {
					"type": "array",
					"maxItems": 100,
					"items": { "$ref": "#/$defs/summary" }
				},
				"nextCursor": {
					"type": "string",
					"minLength": 1,
					"maxLength": 500
				}
			}
		}
	}
};
var schema_profile_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/schema-profile.schema.json",
	title: "Studio Schema Profile meta-schema",
	allOf: [{ "$ref": "#/$defs/schema" }, {
		"type": "object",
		"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
		"required": ["type", "additionalProperties"],
		"properties": {
			"type": { "const": "object" },
			"additionalProperties": { "const": false }
		}
	}],
	$defs: {
		"schema": {
			"type": "object",
			"propertyNames": { "enum": [
				"$defs",
				"$ref",
				"$schema",
				"additionalProperties",
				"allOf",
				"anyOf",
				"const",
				"default",
				"dependentRequired",
				"description",
				"else",
				"enum",
				"examples",
				"exclusiveMaximum",
				"exclusiveMinimum",
				"if",
				"items",
				"maxItems",
				"maxLength",
				"maxProperties",
				"maximum",
				"minItems",
				"minLength",
				"minProperties",
				"minimum",
				"multipleOf",
				"not",
				"oneOf",
				"prefixItems",
				"properties",
				"propertyNames",
				"readOnly",
				"required",
				"then",
				"title",
				"type",
				"uniqueItems",
				"writeOnly"
			] },
			"properties": {
				"$defs": { "$ref": "#/$defs/schemaMap" },
				"$ref": {
					"type": "string",
					"maxLength": 500,
					"pattern": "^#(?:/(?:[A-Za-z0-9._!$&'()*+,;=:@-]|~[01])*)*(?![\\s\\S])"
				},
				"$schema": { "const": "https://json-schema.org/draft/2020-12/schema" },
				"additionalProperties": { "$ref": "#/$defs/subschema" },
				"allOf": { "$ref": "#/$defs/schemaArray" },
				"anyOf": { "$ref": "#/$defs/schemaArray" },
				"const": { "$ref": "#/$defs/jsonValue" },
				"default": { "$ref": "#/$defs/jsonValue" },
				"dependentRequired": {
					"type": "object",
					"maxProperties": 512,
					"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
					"additionalProperties": {
						"type": "array",
						"maxItems": 512,
						"uniqueItems": true,
						"items": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" }
					}
				},
				"description": {
					"type": "string",
					"maxLength": 1e4
				},
				"else": { "$ref": "#/$defs/subschema" },
				"enum": {
					"type": "array",
					"minItems": 1,
					"maxItems": 1024,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/jsonValue" }
				},
				"examples": {
					"type": "array",
					"maxItems": 100,
					"items": { "$ref": "#/$defs/jsonValue" }
				},
				"exclusiveMaximum": { "type": "number" },
				"exclusiveMinimum": { "type": "number" },
				"if": { "$ref": "#/$defs/subschema" },
				"items": { "$ref": "#/$defs/subschema" },
				"maxItems": {
					"type": "integer",
					"minimum": 0
				},
				"maxLength": {
					"type": "integer",
					"minimum": 0
				},
				"maxProperties": {
					"type": "integer",
					"minimum": 0
				},
				"maximum": { "type": "number" },
				"minItems": {
					"type": "integer",
					"minimum": 0
				},
				"minLength": {
					"type": "integer",
					"minimum": 0
				},
				"minProperties": {
					"type": "integer",
					"minimum": 0
				},
				"minimum": { "type": "number" },
				"multipleOf": {
					"type": "number",
					"exclusiveMinimum": 0
				},
				"not": { "$ref": "#/$defs/subschema" },
				"oneOf": { "$ref": "#/$defs/schemaArray" },
				"prefixItems": { "$ref": "#/$defs/schemaArray" },
				"properties": { "$ref": "#/$defs/schemaMap" },
				"propertyNames": { "$ref": "#/$defs/subschema" },
				"readOnly": { "type": "boolean" },
				"required": {
					"type": "array",
					"maxItems": 512,
					"uniqueItems": true,
					"items": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" }
				},
				"then": { "$ref": "#/$defs/subschema" },
				"title": {
					"type": "string",
					"maxLength": 1e3
				},
				"type": { "oneOf": [{ "$ref": "#/$defs/typeName" }, {
					"type": "array",
					"minItems": 1,
					"maxItems": 7,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/typeName" }
				}] },
				"uniqueItems": { "type": "boolean" },
				"writeOnly": { "type": "boolean" }
			}
		},
		"schemaArray": {
			"type": "array",
			"minItems": 1,
			"maxItems": 64,
			"items": { "$ref": "#/$defs/subschema" }
		},
		"schemaMap": {
			"type": "object",
			"maxProperties": 512,
			"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
			"additionalProperties": { "$ref": "#/$defs/schema" }
		},
		"jsonValue": { "oneOf": [
			{ "type": "null" },
			{ "type": "boolean" },
			{ "type": "number" },
			{ "type": "string" },
			{
				"type": "array",
				"maxItems": 1e4,
				"items": { "$ref": "#/$defs/jsonValue" }
			},
			{
				"type": "object",
				"maxProperties": 1e3,
				"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
				"additionalProperties": { "$ref": "#/$defs/jsonValue" }
			}
		] },
		"subschema": { "oneOf": [{ "type": "boolean" }, { "$ref": "#/$defs/schema" }] },
		"typeName": { "enum": [
			"array",
			"boolean",
			"integer",
			"null",
			"number",
			"object",
			"string"
		] },
		"limits": { "const": {
			"maxAlternatives": 64,
			"maxDescriptionLength": 1e4,
			"maxEnumMembers": 1024,
			"maxExamples": 100,
			"maxJsonDepth": 64,
			"maxJsonItems": 1e4,
			"maxJsonProperties": 1e3,
			"maxObjectKeyLength": 200,
			"maxPropertyNames": 512,
			"maxReferenceLength": 500,
			"maxReferences": 128,
			"maxSchemaBytes": 262144,
			"maxSchemaDepth": 32,
			"maxSchemaMapProperties": 512,
			"maxSchemaNodes": 1024,
			"maxTitleLength": 1e3
		} }
	}
};
var schema_profile_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/schema-profile-vector.schema.json",
	title: "Studio property-schema profile conformance vector",
	description: "One portable admission or instance-validation assertion for studio.profile/schema-property. Rejection vectors deliberately carry schemas outside the profile, so the schema member is bounded JSON rather than a reference to the profile meta-schema.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"profile",
		"schema",
		"expect"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "schema-profile-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500
		},
		"profile": { "const": "studio.profile/schema-property" },
		"boundary": { "$ref": "#/$defs/limitBoundary" },
		"schema": { "description": "The candidate schema. This is intentionally unconstrained so invalid-root and unsafe-member vectors remain expressible; the vector file itself remains bounded by the corpus size policy. Values JSON cannot represent are covered by implementation tests." },
		"expect": { "oneOf": [{
			"type": "object",
			"additionalProperties": false,
			"required": ["outcome", "instances"],
			"properties": {
				"outcome": { "const": "accepted" },
				"instances": {
					"type": "array",
					"minItems": 1,
					"maxItems": 50,
					"items": {
						"type": "object",
						"additionalProperties": false,
						"required": ["value", "valid"],
						"properties": {
							"value": { "$ref": "common.schema.json#/$defs/jsonValue" },
							"valid": { "type": "boolean" },
							"diagnostic": { "$ref": "#/$defs/instanceDiagnostic" }
						},
						"allOf": [{
							"if": { "properties": { "valid": { "const": true } } },
							"then": { "properties": { "diagnostic": false } },
							"else": {
								"required": ["diagnostic"],
								"properties": { "diagnostic": { "$ref": "#/$defs/instanceDiagnostic" } }
							}
						}]
					}
				}
			}
		}, {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"outcome",
				"code",
				"schemaPath"
			],
			"properties": {
				"outcome": { "const": "rejected" },
				"code": { "enum": [
					"invalid-root",
					"unsupported-keyword",
					"invalid-keyword-value",
					"unsafe-member",
					"limit-exceeded",
					"invalid-reference",
					"recursive-schema"
				] },
				"schemaPath": { "$ref": "#/$defs/jsonPointer" }
			}
		}] }
	},
	$defs: {
		"limitBoundary": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"limit",
				"position",
				"value"
			],
			"properties": {
				"limit": { "enum": [
					"maxAlternatives",
					"maxDescriptionLength",
					"maxEnumMembers",
					"maxExamples",
					"maxJsonDepth",
					"maxJsonItems",
					"maxJsonProperties",
					"maxObjectKeyLength",
					"maxPropertyNames",
					"maxReferenceLength",
					"maxReferences",
					"maxSchemaBytes",
					"maxSchemaDepth",
					"maxSchemaMapProperties",
					"maxSchemaNodes",
					"maxTitleLength"
				] },
				"position": { "enum": ["at-limit", "over-limit"] },
				"value": {
					"type": "integer",
					"minimum": 1
				}
			}
		},
		"instanceDiagnostic": {
			"type": "object",
			"additionalProperties": false,
			"required": ["instancePath", "keyword"],
			"properties": {
				"instancePath": { "$ref": "#/$defs/jsonPointer" },
				"keyword": {
					"type": "string",
					"minLength": 1,
					"maxLength": 100,
					"pattern": "^[A-Za-z$][A-Za-z0-9$]*(?![\\s\\S])"
				}
			}
		},
		"jsonPointer": {
			"type": "string",
			"maxLength": 1e3,
			"pattern": "^(?:/(?:[^~\\u0000-\\u001F\\u007F]|~[01])*)*(?![\\s\\S])"
		}
	}
};
var studio_config_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/studio-config.schema.json",
	title: "Studio resolved session configuration",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"protocolVersion",
		"sessionId",
		"sessionGeneration",
		"mode",
		"composite",
		"sessionState",
		"actor",
		"locale",
		"displayPreferences",
		"resourceContext",
		"permissions",
		"artifacts",
		"blocks",
		"plugins",
		"hostCapabilities",
		"limits",
		"features",
		"preview"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"protocolVersion": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"sessionId": { "$ref": "common.schema.json#/$defs/stableId" },
		"sessionGeneration": { "$ref": "common.schema.json#/$defs/revision" },
		"mode": { "enum": [
			"model",
			"blueprint",
			"content"
		] },
		"composite": { "enum": ["single", "hybrid"] },
		"sessionState": { "enum": ["editable", "read-only"] },
		"actor": {
			"type": "object",
			"additionalProperties": false,
			"required": ["id", "displayName"],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"displayName": {
					"type": "string",
					"minLength": 1,
					"maxLength": 200
				}
			}
		},
		"locale": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"requested",
				"resolved",
				"fallbacks",
				"direction",
				"timeZone"
			],
			"properties": {
				"requested": { "$ref": "common.schema.json#/$defs/locale" },
				"resolved": { "$ref": "common.schema.json#/$defs/locale" },
				"fallbacks": {
					"type": "array",
					"maxItems": 10,
					"uniqueItems": true,
					"items": { "$ref": "common.schema.json#/$defs/locale" }
				},
				"direction": { "enum": ["ltr", "rtl"] },
				"timeZone": {
					"type": "string",
					"minLength": 1,
					"maxLength": 100
				}
			}
		},
		"displayPreferences": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"calendar",
				"numberingSystem",
				"hourCycle"
			],
			"properties": {
				"calendar": { "$ref": "common.schema.json#/$defs/localName" },
				"numberingSystem": { "$ref": "common.schema.json#/$defs/localName" },
				"hourCycle": { "enum": [
					"h11",
					"h12",
					"h23",
					"h24"
				] },
				"measurementSystem": { "enum": [
					"metric",
					"us",
					"uk"
				] }
			}
		},
		"resourceContext": { "$ref": "common.schema.json#/$defs/resourceContext" },
		"permissions": {
			"type": "array",
			"maxItems": 500,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
		},
		"artifacts": {
			"type": "object",
			"additionalProperties": false,
			"properties": {
				"model": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
				"blueprint": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
				"entry": { "$ref": "common.schema.json#/$defs/resolvedEntryReference" },
				"theme": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" }
			}
		},
		"plugins": {
			"type": "array",
			"maxItems": 100,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" }
		},
		"blocks": {
			"type": "array",
			"maxItems": 5e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"type",
					"version",
					"revision"
				],
				"properties": {
					"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
					"revision": { "$ref": "common.schema.json#/$defs/revision" },
					"integrity": { "$ref": "common.schema.json#/$defs/integrity" }
				}
			}
		},
		"hostCapabilities": { "$ref": "host-capabilities.schema.json" },
		"limits": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"maxNodes",
				"maxDepth",
				"maxSlotsPerNode",
				"maxChildrenPerSlot",
				"maxPropertyBytes",
				"maxExtensionBytes",
				"maxCommandBatch",
				"maxHistoryEntries",
				"maxRichTextBytes",
				"maxRichTextDepth",
				"maxPreviewRequestsPerMinute",
				"maxPreviewBytes",
				"maxMediaUploadBytes",
				"maxMediaBatch",
				"maxPluginCount",
				"maxContributionsPerPlugin",
				"maxLocaleBytes"
			],
			"properties": {
				"maxNodes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e5
				},
				"maxDepth": {
					"type": "integer",
					"minimum": 1,
					"maximum": 128
				},
				"maxSlotsPerNode": {
					"type": "integer",
					"minimum": 0,
					"maximum": 100
				},
				"maxChildrenPerSlot": {
					"type": "integer",
					"minimum": 0,
					"maximum": 1e4
				},
				"maxPropertyBytes": {
					"type": "integer",
					"minimum": 0,
					"maximum": 10485760
				},
				"maxExtensionBytes": {
					"type": "integer",
					"minimum": 0,
					"maximum": 10485760
				},
				"maxCommandBatch": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e4
				},
				"maxHistoryEntries": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e5
				},
				"maxRichTextBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 10485760
				},
				"maxRichTextDepth": {
					"type": "integer",
					"minimum": 1,
					"maximum": 128
				},
				"maxPreviewRequestsPerMinute": {
					"type": "integer",
					"minimum": 1,
					"maximum": 6e4
				},
				"maxPreviewBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 52428800
				},
				"maxMediaUploadBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1099511627776
				},
				"maxMediaBatch": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e3
				},
				"maxPluginCount": {
					"type": "integer",
					"minimum": 0,
					"maximum": 100
				},
				"maxContributionsPerPlugin": {
					"type": "integer",
					"minimum": 0,
					"maximum": 5e3
				},
				"maxLocaleBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 104857600
				}
			}
		},
		"features": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"executablePlugins",
				"customInspectors",
				"externalMediaImport",
				"clipboardMediaUpload",
				"collaboration",
				"offlineRecovery"
			],
			"properties": {
				"executablePlugins": { "type": "boolean" },
				"customInspectors": { "type": "boolean" },
				"externalMediaImport": { "type": "boolean" },
				"clipboardMediaUpload": { "type": "boolean" },
				"collaboration": { "type": "boolean" },
				"offlineRecovery": { "type": "boolean" }
			}
		},
		"preview": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"enabled",
				"sameOriginRequired",
				"allowApproximateRenderer"
			],
			"properties": {
				"enabled": { "type": "boolean" },
				"sameOriginRequired": { "type": "boolean" },
				"allowApproximateRenderer": { "type": "boolean" },
				"initialViewport": { "$ref": "common.schema.json#/$defs/localName" }
			}
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	},
	allOf: [{
		"if": {
			"properties": { "composite": { "const": "hybrid" } },
			"required": ["composite"]
		},
		"then": { "properties": { "mode": { "enum": ["blueprint", "content"] } } }
	}]
};
var studio_browser_assets_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/studio-browser-assets.schema.json",
	title: "Studio prebuilt browser asset manifest",
	description: "Integrity, CSP, runtime, and public-renderer materialization metadata for Studio's host-neutral prebuilt authoring ESM and published-page enhancement IIFE surfaces.",
	type: "object",
	additionalProperties: false,
	required: [
		"kind",
		"schemaVersion",
		"assets",
		"module",
		"enhancementRuntime",
		"publicRenderer",
		"contentSecurityPolicy",
		"productionRuntime",
		"release"
	],
	properties: {
		"kind": { "const": "studio-browser-assets" },
		"schemaVersion": { "const": 1 },
		"assets": {
			"type": "array",
			"minItems": 1,
			"maxItems": 500,
			"allOf": [{
				"contains": {
					"type": "object",
					"propertyNames": { "enum": [
						"path",
						"role",
						"mediaType",
						"bytes",
						"budgetBytes",
						"contentHash",
						"integrity",
						"minified"
					] },
					"properties": { "role": { "const": "browser-module" } },
					"required": ["role"]
				},
				"minContains": 1,
				"maxContains": 1
			}, {
				"contains": {
					"type": "object",
					"propertyNames": { "enum": [
						"path",
						"role",
						"mediaType",
						"bytes",
						"budgetBytes",
						"contentHash",
						"integrity",
						"minified"
					] },
					"properties": { "role": { "const": "enhancement-runtime" } },
					"required": ["role"]
				},
				"minContains": 1,
				"maxContains": 1
			}],
			"items": { "$ref": "#/$defs/asset" }
		},
		"module": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"entryPoint",
				"format",
				"exports"
			],
			"properties": {
				"entryPoint": {
					"description": "Must equal the path of the one assets member whose role is browser-module; builders and consumers enforce this cross-member equality after schema validation.",
					"$ref": "#/$defs/path"
				},
				"format": { "const": "esm" },
				"exports": {
					"type": "array",
					"minItems": 16,
					"maxItems": 16,
					"uniqueItems": true,
					"prefixItems": [
						{ "const": "mountStudio" },
						{ "const": "mountStudioFromConfigElement" },
						{ "const": "autoMountStudio" },
						{ "const": "parseStudioDeploymentConfiguration" },
						{ "const": "resolveStudioDeploymentRuntime" },
						{ "const": "createBrowserHttpHostAdapter" },
						{ "const": "createCoreProductionBlockDefinitions" },
						{ "const": "createCoreProductionPatterns" },
						{ "const": "createStudioStandaloneRuntime" },
						{ "const": "defineKumweStudio" },
						{ "const": "defineKumweStudioContextual" },
						{ "const": "defineKumweStudioStandalone" },
						{ "const": "defineStudioBrowserElements" },
						{ "const": "mountStudioHosted" },
						{ "const": "mountStudioStandalone" },
						{ "const": "openContextualStudioSession" }
					],
					"items": false
				}
			}
		},
		"enhancementRuntime": {
			"description": "The one trusted public-page behavior file. Producer includes it only when renderer-web output requests at least one member of the closed family set; the file discovers renderer-emitted data attributes and is safe to include unconditionally.",
			"type": "object",
			"additionalProperties": false,
			"required": [
				"entryPoint",
				"format",
				"loading",
				"enhancements",
				"needSignal",
				"activation",
				"safeToIncludeUnconditionally",
				"noJavaScriptFallback",
				"contentSecurityPolicy"
			],
			"properties": {
				"entryPoint": { "$ref": "#/$defs/path" },
				"format": { "const": "iife" },
				"loading": { "const": "defer" },
				"enhancements": {
					"type": "array",
					"minItems": 8,
					"maxItems": 8,
					"uniqueItems": true,
					"prefixItems": [
						{ "const": "countdown" },
						{ "const": "dialog" },
						{ "const": "lightbox" },
						{ "const": "navigation" },
						{ "const": "notice" },
						{ "const": "popover" },
						{ "const": "slideshow" },
						{ "const": "tabs" }
					],
					"items": false
				},
				"needSignal": {
					"type": "object",
					"additionalProperties": false,
					"required": ["source", "rule"],
					"properties": {
						"source": { "const": "renderer-web.enhancements" },
						"rule": { "const": "closed-family-intersection-non-empty" }
					}
				},
				"activation": { "const": "renderer-data-attributes" },
				"safeToIncludeUnconditionally": { "const": true },
				"noJavaScriptFallback": { "const": "semantic-renderer-output" },
				"contentSecurityPolicy": { "const": "default-src 'none'; script-src 'self'; require-trusted-types-for 'script'; trusted-types 'none'" }
			}
		},
		"publicRenderer": {
			"description": "The language-neutral rule Producer and other server renderers use to materialize the exact canonical renderer-web CSS bytes without a host-selected minifier.",
			"type": "object",
			"additionalProperties": false,
			"required": ["style"],
			"properties": { "style": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"source",
					"materialization",
					"fileNameTemplate",
					"mediaType",
					"contentHashAlgorithm",
					"integrityAlgorithm",
					"outputSchema",
					"minified",
					"budgetBytes"
				],
				"properties": {
					"source": { "const": "renderer-web.css" },
					"materialization": { "const": "exact-utf8-bytes" },
					"fileNameTemplate": { "const": "studio-public-{{CONTENT_HASH_16}}.min.css" },
					"mediaType": { "const": "text/css" },
					"contentHashAlgorithm": { "const": "sha256" },
					"integrityAlgorithm": { "const": "sha256" },
					"outputSchema": { "const": "https://schemas.kumwe.org/studio/v1/studio-browser-assets.schema.json#/$defs/publicStyleAsset" },
					"minified": { "const": true },
					"budgetBytes": { "const": 262144 }
				}
			} }
		},
		"contentSecurityPolicy": { "$ref": "#/$defs/contentSecurityPolicy" },
		"productionRuntime": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"servingModel",
				"requires",
				"forbidden"
			],
			"properties": {
				"servingModel": { "const": "static-files" },
				"requires": {
					"type": "array",
					"maxItems": 0,
					"uniqueItems": true,
					"items": {
						"type": "string",
						"minLength": 1,
						"maxLength": 100
					}
				},
				"forbidden": {
					"type": "array",
					"minItems": 1,
					"maxItems": 20,
					"uniqueItems": true,
					"items": {
						"type": "string",
						"minLength": 1,
						"maxLength": 100
					}
				}
			}
		},
		"release": { "$ref": "#/$defs/release" }
	},
	$defs: {
		"release": {
			"description": "The exact coordinated Studio release identity copied into every deployment configuration served with this asset generation.",
			"type": "object",
			"additionalProperties": false,
			"required": ["version", "corpusManifestDigest"],
			"properties": {
				"version": {
					"type": "string",
					"pattern": "^(?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*)(?:-(?:beta|rc)\\.(?:0|[1-9][0-9]*))?$"
				},
				"corpusManifestDigest": {
					"type": "string",
					"pattern": "^sha256-[A-Za-z0-9+/]{42}[AEIMQUYcgkosw048]=$"
				}
			}
		},
		"contentSecurityPolicy": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"profile",
				"headerTemplate",
				"styleNonce",
				"inertConfigurationScript"
			],
			"properties": {
				"profile": { "const": "same-origin-http" },
				"headerTemplate": { "const": "default-src 'none'; script-src 'self'; require-trusted-types-for 'script'; trusted-types lit-html; style-src 'self' 'nonce-{{STYLE_NONCE}}'; img-src 'self' data:; font-src 'self'; connect-src 'self'; media-src 'self'; worker-src 'none'; frame-src 'none'; manifest-src 'none'; object-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'" },
				"styleNonce": {
					"type": "object",
					"additionalProperties": false,
					"required": [
						"placeholder",
						"minimumEntropyBits",
						"scope"
					],
					"properties": {
						"placeholder": { "const": "{{STYLE_NONCE}}" },
						"minimumEntropyBits": { "const": 128 },
						"scope": { "const": "response" }
					}
				},
				"inertConfigurationScript": {
					"type": "object",
					"additionalProperties": false,
					"required": [
						"element",
						"mediaType",
						"requiresHash",
						"requiresNonce"
					],
					"properties": {
						"element": { "const": "script" },
						"mediaType": { "const": "application/json" },
						"requiresHash": { "const": false },
						"requiresNonce": { "const": false }
					}
				}
			}
		},
		"path": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500,
			"pattern": "^(?!/)(?!.*(?:^|/)\\.\\.(?:/|$))(?!.*[\\\\?#])[A-Za-z0-9._/-]+$"
		},
		"publicStyleAsset": {
			"description": "The closed per-page delivery-manifest record for exact canonical renderer-web CSS materialized by Producer or another server renderer.",
			"type": "object",
			"additionalProperties": false,
			"required": [
				"path",
				"role",
				"mediaType",
				"bytes",
				"budgetBytes",
				"contentHash",
				"integrity",
				"minified"
			],
			"properties": {
				"path": {
					"type": "string",
					"minLength": 36,
					"maxLength": 500,
					"pattern": "^(?!/)(?!.*(?:^|/)\\.\\.(?:/|$))(?!.*[\\\\?#])(?:[A-Za-z0-9._-]+/)*studio-public-[a-f0-9]{16}\\.min\\.css$"
				},
				"role": { "const": "public-style" },
				"mediaType": { "const": "text/css" },
				"bytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 262144
				},
				"budgetBytes": { "const": 262144 },
				"contentHash": {
					"type": "string",
					"pattern": "^[a-f0-9]{64}$"
				},
				"integrity": {
					"type": "string",
					"pattern": "^sha256-[A-Za-z0-9+/]{42}[AEIMQUYcgkosw048]=$"
				},
				"minified": { "const": true }
			}
		},
		"asset": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"path",
				"role",
				"mediaType",
				"bytes",
				"integrity"
			],
			"allOf": [{
				"if": {
					"properties": { "role": { "enum": ["browser-module", "enhancement-runtime"] } },
					"required": ["role"]
				},
				"then": {
					"properties": {
						"budgetBytes": {},
						"contentHash": {},
						"mediaType": { "const": "text/javascript" },
						"minified": {}
					},
					"required": [
						"budgetBytes",
						"contentHash",
						"minified"
					]
				}
			}, {
				"if": {
					"properties": { "mediaType": { "const": "text/javascript" } },
					"required": ["mediaType"]
				},
				"then": { "properties": { "role": { "enum": ["browser-module", "enhancement-runtime"] } } }
			}],
			"properties": {
				"path": { "$ref": "#/$defs/path" },
				"role": { "enum": [
					"browser-module",
					"enhancement-runtime",
					"license",
					"notice",
					"release-record",
					"documentation",
					"schema"
				] },
				"mediaType": { "enum": [
					"text/javascript",
					"text/plain",
					"text/markdown",
					"application/json"
				] },
				"bytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1073741824
				},
				"budgetBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1073741824
				},
				"contentHash": {
					"type": "string",
					"pattern": "^[a-f0-9]{64}$"
				},
				"integrity": {
					"type": "string",
					"pattern": "^sha256-[A-Za-z0-9+/]{42}[AEIMQUYcgkosw048]=$"
				},
				"minified": { "const": true }
			}
		}
	}
};
var studio_deployment_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/studio-deployment.schema.json",
	title: "Studio browser deployment configuration",
	description: "A bounded, declarative browser bootstrap for one Studio mount, bound to the exact prebuilt browser release that consumes it. An omitted transport selects local standalone authoring; an HTTP transport names exact host routes but never grants server authority.",
	$ref: "#/$defs/configuration",
	$defs: {
		"mountSelector": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500,
			"pattern": "^[^\\u0000-\\u001F\\u007F]+(?![\\s\\S])"
		},
		"endpointUrl": {
			"description": "A bounded HTTP(S) URL reference resolved against the containing document URL. Runtime validation rejects non-HTTP(S), credential-bearing, and fragment-bearing URLs.",
			"type": "string",
			"minLength": 1,
			"maxLength": 2048,
			"pattern": "^[^\\u0000-\\u0020\\u007F]+(?![\\s\\S])"
		},
		"headerName": {
			"type": "string",
			"minLength": 1,
			"maxLength": 100,
			"pattern": "^[!#$%&'*+.^_`|~0-9A-Za-z-]+(?![\\s\\S])"
		},
		"customAuthenticationHeaderName": {
			"description": "A custom authentication field that cannot impersonate bearer authentication or a transport-, browser-, proxy-, or forwarding-owned field.",
			"allOf": [{ "$ref": "#/$defs/headerName" }, {
				"type": "string",
				"not": { "anyOf": [
					{ "pattern": "^[Aa][Cc][Cc][Ee][Pp][Tt]$" },
					{ "pattern": "^[Aa][Cc][Cc][Ee][Ss][Ss]-[Cc][Oo][Nn][Tt][Rr][Oo][Ll]-" },
					{ "pattern": "^[Aa][Uu][Tt][Hh][Oo][Rr][Ii][Zz][Aa][Tt][Ii][Oo][Nn]$" },
					{ "pattern": "^[Cc][Oo][Nn][Nn][Ee][Cc][Tt][Ii][Oo][Nn]$" },
					{ "pattern": "^[Cc][Oo][Nn][Tt][Ee][Nn][Tt]-[Ll][Ee][Nn][Gg][Tt][Hh]$" },
					{ "pattern": "^[Cc][Oo][Nn][Tt][Ee][Nn][Tt]-[Tt][Yy][Pp][Ee]$" },
					{ "pattern": "^[Cc][Oo][Oo][Kk][Ii][Ee]$" },
					{ "pattern": "^[Dd][Aa][Tt][Ee]$" },
					{ "pattern": "^[Ee][Xx][Pp][Ee][Cc][Tt]$" },
					{ "pattern": "^[Ff][Oo][Rr][Ww][Aa][Rr][Dd][Ee][Dd]$" },
					{ "pattern": "^[Hh][Oo][Ss][Tt]$" },
					{ "pattern": "^[Kk][Ee][Ee][Pp]-[Aa][Ll][Ii][Vv][Ee]$" },
					{ "pattern": "^[Oo][Rr][Ii][Gg][Ii][Nn]$" },
					{ "pattern": "^[Pp][Rr][Oo][Xx][Yy]-" },
					{ "pattern": "^[Rr][Ee][Ff][Ee][Rr][Ee][Rr]$" },
					{ "pattern": "^[Ss][Ee][Cc]-" },
					{ "pattern": "^[Ss][Ee][Tt]-[Cc][Oo][Oo][Kk][Ii][Ee]$" },
					{ "pattern": "^[Tt][Ee]$" },
					{ "pattern": "^[Tt][Rr][Aa][Ii][Ll][Ee][Rr]$" },
					{ "pattern": "^[Tt][Rr][Aa][Nn][Ss][Ff][Ee][Rr]-[Ee][Nn][Cc][Oo][Dd][Ii][Nn][Gg]$" },
					{ "pattern": "^[Uu][Pp][Gg][Rr][Aa][Dd][Ee]$" },
					{ "pattern": "^[Uu][Ss][Ee][Rr]-[Aa][Gg][Ee][Nn][Tt]$" },
					{ "pattern": "^[Vv][Ii][Aa]$" },
					{ "pattern": "^[Xx]-[Ff][Oo][Rr][Ww][Aa][Rr][Dd][Ee][Dd]-" },
					{ "pattern": "^[Xx]-[Ss][Tt][Uu][Dd][Ii][Oo]-[Oo][Pp][Ee][Rr][Aa][Tt][Ii][Oo][Nn]$" }
				] }
			}]
		},
		"csrf": {
			"type": "object",
			"additionalProperties": false,
			"required": ["headerName", "token"],
			"properties": {
				"headerName": { "$ref": "#/$defs/customAuthenticationHeaderName" },
				"token": {
					"type": "string",
					"minLength": 1,
					"maxLength": 4096
				}
			}
		},
		"sameOriginSessionAuthentication": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"credentials",
				"csrf"
			],
			"properties": {
				"kind": { "const": "same-origin-session" },
				"credentials": { "const": "same-origin" },
				"csrf": { "$ref": "#/$defs/csrf" }
			}
		},
		"bearerTokenAuthentication": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"credentials",
				"token",
				"issuedAt",
				"expiresAt"
			],
			"properties": {
				"kind": { "const": "bearer-token" },
				"credentials": { "const": "omit" },
				"token": {
					"type": "string",
					"minLength": 1,
					"maxLength": 8192
				},
				"issuedAt": { "$ref": "common.schema.json#/$defs/rfc3339Instant" },
				"expiresAt": { "$ref": "common.schema.json#/$defs/rfc3339Instant" }
			}
		},
		"headerTokenAuthentication": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"credentials",
				"headerName",
				"token",
				"issuedAt",
				"expiresAt"
			],
			"properties": {
				"kind": { "const": "header-token" },
				"credentials": { "const": "omit" },
				"headerName": { "$ref": "#/$defs/customAuthenticationHeaderName" },
				"token": {
					"type": "string",
					"minLength": 1,
					"maxLength": 8192
				},
				"issuedAt": { "$ref": "common.schema.json#/$defs/rfc3339Instant" },
				"expiresAt": { "$ref": "common.schema.json#/$defs/rfc3339Instant" }
			}
		},
		"authentication": { "oneOf": [
			{ "$ref": "#/$defs/sameOriginSessionAuthentication" },
			{ "$ref": "#/$defs/bearerTokenAuthentication" },
			{ "$ref": "#/$defs/headerTokenAuthentication" }
		] },
		"singleEndpointRouting": {
			"type": "object",
			"additionalProperties": false,
			"required": ["kind", "endpoint"],
			"properties": {
				"kind": { "const": "single-endpoint" },
				"endpoint": { "$ref": "#/$defs/endpointUrl" }
			}
		},
		"operationEndpoints": {
			"type": "object",
			"minProperties": 2,
			"required": ["authoring/resolve-target", "authoring/start"],
			"propertyNames": { "$ref": "host-operations.schema.json#/$defs/operationRoute" },
			"properties": {
				"authoring/resolve-target": { "$ref": "#/$defs/endpointUrl" },
				"authoring/start": { "$ref": "#/$defs/endpointUrl" }
			},
			"additionalProperties": { "$ref": "#/$defs/endpointUrl" },
			"allOf": [
				{
					"if": {
						"type": "object",
						"propertyNames": {},
						"properties": { "authoring/save-item": {} },
						"required": ["authoring/save-item"]
					},
					"then": {
						"type": "object",
						"propertyNames": {},
						"properties": { "authoring/plan-save": {} },
						"required": ["authoring/plan-save"]
					}
				},
				{
					"if": {
						"type": "object",
						"propertyNames": {},
						"properties": { "authoring/save-new-type-version": {} },
						"required": ["authoring/save-new-type-version"]
					},
					"then": {
						"type": "object",
						"propertyNames": {},
						"properties": { "authoring/plan-save": {} },
						"required": ["authoring/plan-save"]
					}
				},
				{
					"if": {
						"type": "object",
						"propertyNames": {},
						"properties": { "authoring/save-as-new-type": {} },
						"required": ["authoring/save-as-new-type"]
					},
					"then": {
						"type": "object",
						"propertyNames": {},
						"properties": { "authoring/plan-save": {} },
						"required": ["authoring/plan-save"]
					}
				}
			]
		},
		"operationMapRouting": {
			"type": "object",
			"additionalProperties": false,
			"required": ["kind", "endpoints"],
			"properties": {
				"kind": { "const": "operation-map" },
				"endpoints": { "$ref": "#/$defs/operationEndpoints" }
			}
		},
		"routing": { "oneOf": [{ "$ref": "#/$defs/singleEndpointRouting" }, { "$ref": "#/$defs/operationMapRouting" }] },
		"standaloneTransport": {
			"type": "object",
			"additionalProperties": false,
			"required": ["kind"],
			"properties": { "kind": { "const": "standalone" } }
		},
		"httpTransport": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"routing",
				"authentication"
			],
			"properties": {
				"kind": { "const": "http" },
				"routing": { "$ref": "#/$defs/routing" },
				"authentication": { "$ref": "#/$defs/authentication" },
				"requestTimeoutMilliseconds": {
					"type": "integer",
					"minimum": 100,
					"maximum": 12e4,
					"default": 1e4
				},
				"maximumResponseBytes": {
					"type": "integer",
					"minimum": 1024,
					"maximum": 67108864,
					"default": 67108864
				}
			}
		},
		"transport": { "oneOf": [{ "$ref": "#/$defs/standaloneTransport" }, { "$ref": "#/$defs/httpTransport" }] },
		"contributionPayload": { "oneOf": [
			{ "$ref": "block-definition.schema.json" },
			{ "$ref": "pattern.schema.json" },
			{ "$ref": "field-adapter.schema.json" },
			{ "$ref": "inspector.schema.json" },
			{ "$ref": "design-vocabulary.schema.json" },
			{ "$ref": "migration.schema.json" }
		] },
		"contributionBundle": {
			"type": "object",
			"additionalProperties": false,
			"required": ["generation", "payloads"],
			"properties": {
				"generation": { "$ref": "common.schema.json#/$defs/revision" },
				"payloads": {
					"type": "array",
					"maxItems": 500,
					"items": { "$ref": "#/$defs/contributionPayload" }
				}
			}
		},
		"launch": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"targetId",
				"intent",
				"resourceContext",
				"start",
				"initialPresentation"
			],
			"properties": {
				"targetId": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"intent": { "$ref": "authoring-target.schema.json#/$defs/eligibility" },
				"resourceContext": { "$ref": "common.schema.json#/$defs/resourceContext" },
				"start": { "$ref": "authoring-session.schema.json#/$defs/startSource" },
				"initialPresentation": { "$ref": "authoring-target.schema.json#/$defs/presentationState" }
			}
		},
		"configuration": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"mount",
				"release"
			],
			"properties": {
				"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
				"kind": { "const": "studio-deployment" },
				"instanceId": { "$ref": "common.schema.json#/$defs/stableId" },
				"mount": { "$ref": "#/$defs/mountSelector" },
				"release": { "$ref": "studio-browser-assets.schema.json#/$defs/release" },
				"locale": { "$ref": "common.schema.json#/$defs/locale" },
				"launch": { "$ref": "#/$defs/launch" },
				"session": { "$ref": "studio-config.schema.json" },
				"contributions": { "$ref": "#/$defs/contributionBundle" },
				"transport": { "$ref": "#/$defs/transport" }
			},
			"allOf": [{
				"if": {
					"type": "object",
					"propertyNames": {},
					"required": ["transport"],
					"properties": { "transport": {
						"type": "object",
						"propertyNames": {},
						"required": ["kind"],
						"properties": { "kind": { "const": "http" } }
					} }
				},
				"then": {
					"type": "object",
					"propertyNames": {},
					"properties": {
						"launch": {},
						"locale": false,
						"session": {}
					},
					"required": ["launch", "session"]
				}
			}, {
				"if": {
					"type": "object",
					"propertyNames": {},
					"anyOf": [{ "not": {
						"type": "object",
						"propertyNames": {},
						"properties": { "transport": {} },
						"required": ["transport"]
					} }, {
						"type": "object",
						"propertyNames": {},
						"properties": { "transport": {
							"type": "object",
							"propertyNames": {},
							"required": ["kind"],
							"properties": { "kind": { "const": "standalone" } }
						} }
					}]
				},
				"then": {
					"type": "object",
					"propertyNames": {},
					"properties": {
						"contributions": false,
						"launch": false,
						"session": false
					}
				}
			}]
		}
	}
};
var studio_chart_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/studio-chart.schema.json",
	title: "Studio canonical chart",
	type: "object",
	additionalProperties: false,
	required: [
		"type",
		"labels",
		"datasets"
	],
	properties: {
		"type": { "enum": [
			"bar",
			"doughnut",
			"line",
			"pie"
		] },
		"title": {
			"type": "string",
			"maxLength": 500
		},
		"labels": {
			"type": "array",
			"maxItems": 200,
			"items": {
				"type": "string",
				"maxLength": 500
			}
		},
		"datasets": {
			"type": "array",
			"minItems": 1,
			"maxItems": 20,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": ["label", "values"],
				"properties": {
					"label": {
						"type": "string",
						"maxLength": 500
					},
					"values": {
						"type": "array",
						"maxItems": 200,
						"items": {
							"type": "number",
							"minimum": -0x38d7ea4c68000,
							"maximum": 0x38d7ea4c68000
						}
					}
				}
			}
		}
	}
};
var studio_drawing_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/studio-drawing.schema.json",
	title: "Studio canonical drawing",
	type: "object",
	additionalProperties: false,
	required: [
		"width",
		"height",
		"alt",
		"strokes"
	],
	properties: {
		"width": {
			"type": "integer",
			"minimum": 1,
			"maximum": 4096
		},
		"height": {
			"type": "integer",
			"minimum": 1,
			"maximum": 4096
		},
		"alt": {
			"type": "string",
			"minLength": 1,
			"maxLength": 5e3
		},
		"strokes": {
			"type": "array",
			"maxItems": 5e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"color",
					"width",
					"points"
				],
				"properties": {
					"color": {
						"type": "string",
						"pattern": "^(?:#[0-9A-Fa-f]{6}|[a-z][a-z0-9-]{0,62}/[a-z][a-z0-9-]{0,62})$",
						"maxLength": 127
					},
					"width": {
						"type": "number",
						"minimum": .25,
						"maximum": 64
					},
					"points": {
						"type": "array",
						"minItems": 1,
						"maxItems": 1e4,
						"items": {
							"type": "object",
							"additionalProperties": false,
							"required": ["x", "y"],
							"properties": {
								"x": {
									"type": "number",
									"minimum": 0,
									"maximum": 4096
								},
								"y": {
									"type": "number",
									"minimum": 0,
									"maximum": 4096
								}
							}
						}
					}
				}
			}
		}
	}
};
var studio_money_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/studio-money.schema.json",
	title: "Studio canonical money",
	type: "object",
	additionalProperties: false,
	required: ["amount", "currency"],
	properties: {
		"amount": {
			"type": "string",
			"pattern": "^-?(?:0|[1-9][0-9]{0,17})(?:\\.[0-9]{1,6})?$",
			"maxLength": 26
		},
		"currency": {
			"type": "string",
			"pattern": "^[A-Z]{3}$"
		}
	}
};
var studio_presentation_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/studio-presentation.schema.json",
	title: "Studio presentation intent",
	type: "object",
	maxProperties: 12,
	additionalProperties: false,
	properties: {
		"align": { "enum": [
			"center",
			"end",
			"start",
			"stretch"
		] },
		"animation": { "enum": [
			"fade",
			"none",
			"parallax",
			"scale",
			"slide"
		] },
		"height": { "enum": [
			"auto",
			"content",
			"full",
			"viewport"
		] },
		"inverse": { "type": "boolean" },
		"margin": { "enum": [
			"comfortable",
			"compact",
			"none",
			"spacious"
		] },
		"marker": { "enum": [
			"check",
			"decimal",
			"disc",
			"none"
		] },
		"padding": { "enum": [
			"comfortable",
			"compact",
			"none",
			"spacious"
		] },
		"position": { "enum": [
			"flow",
			"relative",
			"sticky"
		] },
		"print": { "enum": [
			"hide",
			"only",
			"show"
		] },
		"scrolling": { "enum": [
			"auto",
			"clip",
			"snap",
			"visible"
		] },
		"visibility": {
			"type": "object",
			"maxProperties": 3,
			"additionalProperties": false,
			"properties": {
				"compact": { "enum": ["hidden", "visible"] },
				"expanded": { "enum": ["hidden", "visible"] },
				"medium": { "enum": ["hidden", "visible"] }
			}
		},
		"width": { "enum": [
			"auto",
			"content",
			"full"
		] }
	}
};
var studio_release_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/studio-release.schema.json",
	title: "Studio coordinated release record",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"release",
		"packages",
		"browserArtifacts",
		"protocolVersion",
		"corpusManifestDigest",
		"claimedProfiles"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "studio-release" },
		"release": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"packages": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"@kumwe/studio-core",
				"@kumwe/studio-media",
				"@kumwe/studio-preview",
				"@kumwe/studio-protocol",
				"@kumwe/studio-renderer-web",
				"@kumwe/studio-rich-text",
				"@kumwe/studio",
				"@kumwe/studio-testkit"
			],
			"properties": {
				"@kumwe/studio-core": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"@kumwe/studio-media": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"@kumwe/studio-preview": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"@kumwe/studio-protocol": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"@kumwe/studio-renderer-web": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"@kumwe/studio-rich-text": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"@kumwe/studio": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"@kumwe/studio-testkit": { "$ref": "common.schema.json#/$defs/semanticVersion" }
			}
		},
		"browserArtifacts": {
			"description": "Stable locators for the prebuilt browser delivery surfaces. Exact filenames, bytes, content hashes, SRI values and budgets are authoritative in the named asset manifest; approved release metadata pins the archive bytes without creating a release-record/archive digest cycle.",
			"type": "object",
			"additionalProperties": false,
			"required": [
				"manifest",
				"authoringArchive",
				"enhancementRuntime"
			],
			"properties": {
				"manifest": {
					"type": "object",
					"additionalProperties": false,
					"required": ["name", "schema"],
					"properties": {
						"name": { "const": "studio-assets.json" },
						"schema": { "const": "https://schemas.kumwe.org/studio/v1/studio-browser-assets.schema.json" }
					}
				},
				"authoringArchive": {
					"type": "object",
					"additionalProperties": false,
					"required": [
						"archiveStem",
						"assetRole",
						"loading"
					],
					"properties": {
						"archiveStem": {
							"type": "string",
							"pattern": "^studio-browser-(?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*)(?:-(?:beta|rc)\\.(?:0|[1-9][0-9]*))?$"
						},
						"assetRole": { "const": "browser-module" },
						"loading": { "const": "module" }
					}
				},
				"enhancementRuntime": {
					"type": "object",
					"additionalProperties": false,
					"required": [
						"assetRole",
						"loading",
						"package",
						"packageBasePath"
					],
					"properties": {
						"assetRole": { "const": "enhancement-runtime" },
						"loading": { "const": "defer" },
						"package": { "const": "@kumwe/studio-renderer-web" },
						"packageBasePath": { "const": "dist/browser/" }
					}
				}
			}
		},
		"protocolVersion": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"corpusManifestDigest": { "$ref": "common.schema.json#/$defs/integrity" },
		"claimedProfiles": {
			"type": "array",
			"maxItems": 32,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
		}
	}
};
var studio_table_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/studio-table.schema.json",
	title: "Studio table document",
	type: "object",
	additionalProperties: false,
	required: ["columns", "rows"],
	properties: {
		"caption": {
			"type": "string",
			"maxLength": 500
		},
		"columns": {
			"type": "array",
			"minItems": 1,
			"maxItems": 50,
			"items": {
				"type": "string",
				"maxLength": 500
			}
		},
		"rows": {
			"type": "array",
			"maxItems": 1e3,
			"items": {
				"type": "array",
				"minItems": 1,
				"maxItems": 50,
				"items": {
					"type": "string",
					"maxLength": 5e3
				}
			}
		}
	}
};
var theme_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/theme.schema.json",
	title: "Studio theme design profile",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"revision",
		"owner",
		"label",
		"viewports",
		"designControls",
		"recipes",
		"renderers",
		"blockSupport"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "theme" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"revision": { "$ref": "common.schema.json#/$defs/revision" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"viewports": {
			"type": "array",
			"minItems": 1,
			"maxItems": 20,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"label",
					"order",
					"base",
					"previewWidth"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/localName" },
					"label": { "$ref": "common.schema.json#/$defs/messageReference" },
					"order": {
						"type": "integer",
						"minimum": 0,
						"maximum": 1e3
					},
					"base": { "type": "boolean" },
					"previewWidth": {
						"type": "integer",
						"minimum": 240,
						"maximum": 1e4
					}
				}
			}
		},
		"designControls": {
			"type": "array",
			"maxItems": 1e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"kind",
					"label",
					"choices"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/localName" },
					"kind": { "enum": [
						"color-role",
						"typography-role",
						"spacing-role",
						"size-role",
						"radius-role",
						"shadow-role",
						"motion-role",
						"layer-role",
						"enum",
						"boolean",
						"integer"
					] },
					"label": { "$ref": "common.schema.json#/$defs/messageReference" },
					"description": { "$ref": "common.schema.json#/$defs/messageReference" },
					"choices": {
						"type": "array",
						"minItems": 1,
						"maxItems": 500,
						"items": {
							"type": "object",
							"additionalProperties": false,
							"required": ["id", "label"],
							"properties": {
								"id": { "$ref": "common.schema.json#/$defs/localName" },
								"label": { "$ref": "common.schema.json#/$defs/messageReference" },
								"deprecated": { "type": "boolean" }
							}
						}
					}
				}
			}
		},
		"recipes": {
			"type": "array",
			"maxItems": 1e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"blockType",
					"label",
					"designValues"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/localName" },
					"blockType": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"label": { "$ref": "common.schema.json#/$defs/messageReference" },
					"designValues": {
						"type": "object",
						"maxProperties": 100,
						"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
						"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
					}
				}
			}
		},
		"renderers": {
			"type": "array",
			"minItems": 1,
			"maxItems": 100,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"version",
					"surfaces",
					"exactPreview"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
					"surfaces": {
						"type": "array",
						"minItems": 1,
						"maxItems": 20,
						"uniqueItems": true,
						"items": { "enum": [
							"web",
							"email",
							"document",
							"native",
							"preview"
						] }
					},
					"exactPreview": { "type": "boolean" }
				}
			}
		},
		"blockSupport": {
			"type": "array",
			"maxItems": 5e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"type",
					"versions",
					"renderer"
				],
				"properties": {
					"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"versions": { "$ref": "common.schema.json#/$defs/versionRange" },
					"renderer": { "$ref": "common.schema.json#/$defs/qualifiedName" }
				}
			}
		},
		"aliases": {
			"type": "array",
			"maxItems": 1e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"kind",
					"from",
					"to",
					"equivalentMeaning"
				],
				"properties": {
					"kind": { "enum": [
						"viewport",
						"design-control",
						"choice",
						"recipe"
					] },
					"from": { "$ref": "common.schema.json#/$defs/localName" },
					"to": { "$ref": "common.schema.json#/$defs/localName" },
					"equivalentMeaning": {
						"type": "boolean",
						"const": true
					}
				}
			}
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var unresolved_contribution_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/unresolved-contribution.schema.json",
	title: "Studio unresolved contribution",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"reference",
		"reason"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "unresolved-contribution" },
		"reference": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"contribution",
				"id",
				"version"
			],
			"properties": {
				"contribution": { "enum": [
					"block",
					"command",
					"design-vocabulary",
					"field-adapter",
					"inspector",
					"locale",
					"migration",
					"panel",
					"pattern",
					"renderer-capability",
					"transform"
				] },
				"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"version": { "$ref": "common.schema.json#/$defs/semanticVersion" }
			}
		},
		"reason": { "enum": [
			"incompatible",
			"not-installed",
			"owner-disabled",
			"owner-revoked"
		] },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"affectedNodes": {
			"type": "array",
			"maxItems": 1e4,
			"items": { "$ref": "common.schema.json#/$defs/stableId" }
		},
		"diagnostics": {
			"type": "array",
			"maxItems": 1e3,
			"items": { "$ref": "common.schema.json#/$defs/diagnostic" }
		}
	}
};
//#endregion
//#region node_modules/@kumwe/studio-protocol/dist/schemas.js
var authoringHttpSchema = authoring_http_schema_default;
var authoringHttpVectorSchema = authoring_http_vector_schema_default;
var authoringMessageCatalogSchema = authoring_message_catalog_schema_default;
var authoringSaveSchema = authoring_save_schema_default;
var authoringSessionSchema = authoring_session_schema_default;
var authoringTargetSchema = authoring_target_schema_default;
var blockDefinitionSchema = block_definition_schema_default;
var bindingProjectionVectorSchema = binding_projection_vector_schema_default;
var authoringWebVectorSchema = authoring_web_vector_schema_default;
var blueprintSchema = blueprint_schema_default;
var commandSchema = command_schema_default;
var commandVectorSchema = command_vector_schema_default;
var commonSchema = common_schema_default;
var contentModelSchema = content_model_schema_default;
var corpusManifestSchema = corpus_manifest_schema_default;
var canonicalVectorSchema = canonical_vector_schema_default;
var designVocabularySchema = design_vocabulary_schema_default;
var entrySchema = entry_schema_default;
var fieldAdapterSchema = field_adapter_schema_default;
var hostCapabilitiesSchema = host_capabilities_schema_default;
var hostErrorSchema = host_error_schema_default;
var hostOperationsSchema = host_operations_schema_default;
var hostRequestSchema = host_request_schema_default;
var hostResultSchema = host_result_schema_default;
var hostVectorSchema = host_vector_schema_default;
var hostSequenceVectorSchema = host_sequence_vector_schema_default;
var inspectorSchema = inspector_schema_default;
var mediaAssetSchema = media_asset_schema_default;
var mediaReferenceSchema = media_reference_schema_default;
var mediaUploadGrantSchema = media_upload_grant_schema_default;
var mediaUploadSessionSchema = media_upload_session_schema_default;
var mediaVectorSchema = media_vector_schema_default;
var migrationSchema = migration_schema_default;
var patternSchema = pattern_schema_default;
var pluginManifestSchema = plugin_manifest_schema_default;
var previewMessageSchema = preview_message_schema_default;
var previewVectorSchema = preview_vector_schema_default;
var rendererWebVectorSchema = renderer_web_vector_schema_default;
var provenanceSchema = provenance_schema_default;
var richTextProjectionSchema = rich_text_projection_schema_default;
var richTextSchema = rich_text_schema_default;
var reusableContentTypeSchema = reusable_content_type_schema_default;
var schemaProfileSchema = schema_profile_schema_default;
var schemaProfileVectorSchema = schema_profile_vector_schema_default;
var studioConfigurationSchema = studio_config_schema_default;
var studioBrowserAssetsSchema = studio_browser_assets_schema_default;
var studioDeploymentSchema = studio_deployment_schema_default;
var studioChartSchema = studio_chart_schema_default;
var studioDrawingSchema = studio_drawing_schema_default;
var studioMoneySchema = studio_money_schema_default;
var studioPresentationSchema = studio_presentation_schema_default;
Object.freeze([
	commonSchema,
	authoringHttpSchema,
	authoringHttpVectorSchema,
	authoringMessageCatalogSchema,
	authoringSaveSchema,
	authoringSessionSchema,
	authoringTargetSchema,
	authoringWebVectorSchema,
	blockDefinitionSchema,
	bindingProjectionVectorSchema,
	blueprintSchema,
	commandSchema,
	commandVectorSchema,
	canonicalVectorSchema,
	contentModelSchema,
	corpusManifestSchema,
	designVocabularySchema,
	entrySchema,
	fieldAdapterSchema,
	hostOperationsSchema,
	hostCapabilitiesSchema,
	hostErrorSchema,
	hostRequestSchema,
	hostResultSchema,
	hostVectorSchema,
	hostSequenceVectorSchema,
	inspectorSchema,
	mediaAssetSchema,
	mediaReferenceSchema,
	mediaUploadGrantSchema,
	mediaUploadSessionSchema,
	mediaVectorSchema,
	migrationSchema,
	patternSchema,
	pluginManifestSchema,
	previewMessageSchema,
	previewVectorSchema,
	rendererWebVectorSchema,
	provenanceSchema,
	richTextProjectionSchema,
	richTextSchema,
	reusableContentTypeSchema,
	schemaProfileSchema,
	schemaProfileVectorSchema,
	studioBrowserAssetsSchema,
	studioConfigurationSchema,
	studioDeploymentSchema,
	studioChartSchema,
	studioDrawingSchema,
	studioMoneySchema,
	studioPresentationSchema,
	studio_release_schema_default,
	studio_table_schema_default,
	theme_schema_default,
	unresolved_contribution_schema_default
]);
//#endregion
//#region node_modules/@kumwe/studio-core/dist/commands.js
var StudioCommandError = class extends Error {
	code;
	constructor(e, t) {
		super(t), this.name = `StudioCommandError`, this.code = e;
	}
};
function applyCommand(n, r) {
	if (r.artifactId !== n.id) throw new StudioCommandError(`node-not-found`, `Command targets ${r.artifactId}, not Blueprint ${n.id}.`);
	let o = cloneContractValue(n);
	if (r.type === `studio.command/batch`) for (let e of i$13(r.payload.operations)) applyOperation(o, e);
	else r.type === `studio.command/apply-pattern` ? _$11(o, r.payload) : r.type === `studio.command/reset-inherited-property` ? y$11(o, r.payload) : applyOperation(o, r);
	return o;
}
function i$13(e) {
	if (e.length === 0 || e.length > 100) throw new StudioCommandError(`invalid-batch`, `A batch must contain between 1 and 100 operations, not ${e.length}.`);
	for (let n of e) {
		let e = n.type;
		if (e === `studio.command/batch` || e === `studio.command/apply-pattern` || e === `studio.command/reset-inherited-property`) throw new StudioCommandError(`invalid-batch`, `A batch cannot contain a ${e.slice(e.indexOf(`/`) + 1)} operation.`);
	}
	return e;
}
function applyOperation(n, r) {
	switch (r.type) {
		case `studio.command/insert-node`:
		case `studio.command/restore-node`:
			h$11(n, r.payload.node), A$8(E$10(n, r.payload.destination), r.payload.destination.position, cloneContractValue(r.payload.node));
			break;
		case `studio.command/remove-node`: {
			let e = j$8(n.roots, r.payload.nodeId);
			if (e === void 0) throw M$6(r.payload.nodeId);
			e.collection.splice(e.index, 1), x$11(n, e);
			break;
		}
		case `studio.command/move-node`: {
			let e = j$8(n.roots, r.payload.nodeId);
			if (e === void 0) throw M$6(r.payload.nodeId);
			let i = r.payload.destination.parentNodeId;
			if (i === r.payload.nodeId || i !== void 0 && j$8([e.node], i) !== void 0) throw new StudioCommandError(`illegal-move`, `A node cannot be moved into itself.`);
			let [a] = e.collection.splice(e.index, 1);
			if (a === void 0) throw M$6(r.payload.nodeId);
			x$11(n, e), A$8(E$10(n, r.payload.destination), r.payload.destination.position, a);
			break;
		}
		case `studio.command/duplicate-node`: {
			let t = j$8(n.roots, r.payload.nodeId);
			if (t === void 0) throw M$6(r.payload.nodeId);
			let i = f$14(n, t.node, r.payload.idMap), a = g$11(cloneContractValue(t.node), i);
			r.payload.destination === void 0 ? A$8(t.collection, t.index + 1, a) : A$8(E$10(n, r.payload.destination), r.payload.destination.position, a);
			break;
		}
		case `studio.command/reorder-children`: {
			let e = C$11(n, r.payload.parentNodeId, r.payload.slot);
			if (!S$11(e.map((e) => e.id), r.payload.order)) throw new StudioCommandError(`invalid-order`, `The requested order is not a permutation of the current children.`);
			let i = new Map(e.map((e) => [e.id, e])), a = r.payload.order.map((e) => {
				let n = i.get(e);
				if (n === void 0) throw new StudioCommandError(`invalid-order`, `The requested order is not a permutation of the current children.`);
				return n;
			});
			e.splice(0, e.length, ...a);
			break;
		}
		case `studio.command/set-property`: {
			let t = j$8(n.roots, r.payload.nodeId);
			if (t === void 0) throw M$6(r.payload.nodeId);
			if (r.payload.viewport === void 0) O$9(t.node.properties, r.payload.property, cloneContractValue(r.payload.value));
			else {
				let n = t.node.responsive ??= {}, i = D$8(n, r.payload.property);
				i === void 0 && (i = {}, O$9(n, r.payload.property, i)), O$9(i, r.payload.viewport, cloneContractValue(r.payload.value));
			}
			break;
		}
		case `studio.command/unset-property`: {
			let e = j$8(n.roots, r.payload.nodeId);
			if (e === void 0) throw M$6(r.payload.nodeId);
			if (r.payload.viewport === void 0) {
				if (D$8(e.node.properties, r.payload.property) === void 0) throw N$6(r.payload.nodeId, r.payload.property);
				k$8(e.node.properties, r.payload.property);
			} else {
				let t = e.node.responsive, n = t === void 0 ? void 0 : D$8(t, r.payload.property);
				if (t === void 0 || n === void 0 || D$8(n, r.payload.viewport) === void 0) throw N$6(r.payload.nodeId, r.payload.property, r.payload.viewport);
				k$8(n, r.payload.viewport), Object.keys(n).length === 0 && k$8(t, r.payload.property), Object.keys(t).length === 0 && delete e.node.responsive;
			}
			break;
		}
		case `studio.command/set-size-role`: {
			let e = j$8(n.roots, r.payload.nodeId);
			if (e === void 0) throw M$6(r.payload.nodeId);
			if (r.payload.viewport === void 0) O$9(e.node.sizeRoles ??= {}, r.payload.axis, r.payload.role);
			else {
				let t = e.node.responsiveSizeRoles ??= {}, n = D$8(t, r.payload.axis);
				n === void 0 && (n = {}, O$9(t, r.payload.axis, n)), O$9(n, r.payload.viewport, r.payload.role);
			}
			break;
		}
		case `studio.command/unset-size-role`: {
			let e = j$8(n.roots, r.payload.nodeId);
			if (e === void 0) throw M$6(r.payload.nodeId);
			if (r.payload.viewport === void 0) {
				let t = e.node.sizeRoles;
				if (t === void 0 || D$8(t, r.payload.axis) === void 0) throw P$6(r.payload.nodeId, r.payload.axis);
				k$8(t, r.payload.axis), Object.keys(t).length === 0 && delete e.node.sizeRoles;
			} else {
				let t = e.node.responsiveSizeRoles, n = t === void 0 ? void 0 : D$8(t, r.payload.axis);
				if (t === void 0 || n === void 0 || D$8(n, r.payload.viewport) === void 0) throw P$6(r.payload.nodeId, r.payload.axis, r.payload.viewport);
				k$8(n, r.payload.viewport), Object.keys(n).length === 0 && k$8(t, r.payload.axis), Object.keys(t).length === 0 && delete e.node.responsiveSizeRoles;
			}
			break;
		}
		case `studio.command/set-binding`: {
			let t = j$8(n.roots, r.payload.nodeId);
			if (t === void 0) throw M$6(r.payload.nodeId);
			O$9(t.node.bindings, r.payload.port, cloneContractValue(r.payload.binding));
			break;
		}
		case `studio.command/remove-binding`: {
			let e = j$8(n.roots, r.payload.nodeId);
			if (e === void 0) throw M$6(r.payload.nodeId);
			if (D$8(e.node.bindings, r.payload.port) === void 0) throw F$6(r.payload.nodeId, r.payload.port);
			k$8(e.node.bindings, r.payload.port);
			break;
		}
		default: w$10(r);
	}
}
function f$14(e, n, r) {
	let i = m$13(n), a = /* @__PURE__ */ new Map();
	for (let [e, t] of Object.entries(r)) a.set(e, t);
	if (a.size !== i.size) throw p$13();
	let o = /* @__PURE__ */ new Set();
	for (let n of i) {
		let r = a.get(n);
		if (r === void 0) throw p$13();
		if (o.has(r)) throw new StudioCommandError(`invalid-id-map`, `The identifier map assigns ${r} more than once.`);
		if (o.add(r), j$8(e.roots, r) !== void 0) throw new StudioCommandError(`duplicate-node`, `Node identifier ${r} is already present.`);
	}
	return a;
}
function p$13() {
	return new StudioCommandError(`invalid-id-map`, `The identifier map must remap every node of the duplicated subtree exactly once.`);
}
function m$13(e) {
	let t = /* @__PURE__ */ new Set(), n = [e];
	for (; n.length > 0;) {
		let e = n.pop();
		if (e === void 0) break;
		t.add(e.id);
		for (let t of Object.values(e.slots)) n.push(...t);
	}
	return t;
}
function h$11(e, n) {
	for (let r of m$13(n)) if (j$8(e.roots, r) !== void 0) throw new StudioCommandError(`duplicate-node`, `Node identifier ${r} is already present.`);
}
function g$11(e, t) {
	let n = [e];
	for (; n.length > 0;) {
		let e = n.pop();
		if (e === void 0) break;
		let r = t.get(e.id);
		if (r === void 0) throw p$13();
		e.id = r;
		for (let t of Object.values(e.slots)) n.push(...t);
	}
	return e;
}
function _$11(n, r) {
	let i = /* @__PURE__ */ new Set();
	for (let e of r.nodes) for (let t of m$13(e)) i.add(t);
	let a = /* @__PURE__ */ new Map();
	for (let [e, t] of Object.entries(r.idMap)) a.set(e, t);
	if (a.size !== i.size) throw p$13();
	let o = /* @__PURE__ */ new Set();
	for (let e of i) {
		let r = a.get(e);
		if (r === void 0) throw p$13();
		if (o.has(r)) throw new StudioCommandError(`invalid-id-map`, `The identifier map assigns ${r} more than once.`);
		if (o.add(r), j$8(n.roots, r) !== void 0) throw new StudioCommandError(`duplicate-node`, `Node identifier ${r} is already present.`);
	}
	let s = E$10(n, r.destination);
	for (let [t, n] of r.nodes.entries()) {
		let i = g$11(cloneContractValue(n), a);
		O$9(i.extensions ??= {}, `studio.pattern/source`, {
			id: r.pattern.id,
			revision: r.pattern.revision,
			version: r.pattern.version
		}), A$8(s, r.destination.position + t, i);
	}
}
function v$11(e, n) {
	let r = j$8(e.roots, n.nodeId);
	if (r === void 0) throw M$6(n.nodeId);
	let i = r.node.responsive, a = i === void 0 ? void 0 : D$8(i, n.property);
	if (i === void 0 || a === void 0 || Object.keys(a).length === 0) throw new StudioCommandError(`property-not-found`, `Property ${n.property} has no responsive overrides on node ${n.nodeId}.`);
	return {
		node: r.node,
		responsive: i,
		values: a
	};
}
function y$11(e, t) {
	let { node: n, responsive: r } = v$11(e, t);
	k$8(r, t.property), Object.keys(r).length === 0 && delete n.responsive;
}
function x$11(e, t) {
	if (t.collection.length > 0 || t.parentNodeId === void 0 || t.slot === void 0) return;
	let n = j$8(e.roots, t.parentNodeId)?.node;
	n !== void 0 && D$8(n.slots, t.slot) === t.collection && k$8(n.slots, t.slot);
}
function S$11(e, t) {
	if (e.length !== t.length) return !1;
	let n = /* @__PURE__ */ new Map();
	for (let t of e) n.set(t, (n.get(t) ?? 0) + 1);
	for (let e of t) {
		let t = n.get(e);
		if (t === void 0 || t === 0) return !1;
		n.set(e, t - 1);
	}
	return !0;
}
function C$11(e, n, r) {
	if (n === void 0) return e.roots;
	if (r === void 0) throw new StudioCommandError(`parent-not-found`, `A parent destination requires a named slot.`);
	let i = j$8(e.roots, n)?.node;
	if (i === void 0) throw new StudioCommandError(`parent-not-found`, `Parent node ${n} was not found.`);
	let a = D$8(i.slots, r);
	if (a === void 0) throw new StudioCommandError(`invalid-order`, `Slot ${r} on node ${n} has no children to reorder.`);
	return a;
}
function w$10(e) {
	throw new StudioCommandError(`unsupported-command`, `Unsupported Blueprint command type: ${T$10(e)}.`);
}
function T$10(e) {
	return typeof e == `object` && e && `type` in e && typeof e.type == `string` && e.type.length <= 160 && /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\/[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/u.test(e.type) ? e.type : `unknown`;
}
function E$10(e, n) {
	if (n.parentNodeId === void 0) return e.roots;
	if (n.slot === void 0) throw new StudioCommandError(`parent-not-found`, `A parent destination requires a named slot.`);
	let r = j$8(e.roots, n.parentNodeId)?.node;
	if (r === void 0) throw new StudioCommandError(`parent-not-found`, `Parent node ${n.parentNodeId} was not found.`);
	let i = D$8(r.slots, n.slot);
	return i === void 0 && (i = [], O$9(r.slots, n.slot, i)), i;
}
function D$8(e, t) {
	return Object.hasOwn(e, t) ? e[t] : void 0;
}
function O$9(e, t, n) {
	Object.defineProperty(e, t, {
		configurable: !0,
		enumerable: !0,
		value: n,
		writable: !0
	});
}
function k$8(e, t) {
	Object.hasOwn(e, t) && delete e[t];
}
function A$8(e, n, r) {
	if (!Number.isInteger(n) || n < 0 || n > e.length) throw new StudioCommandError(`invalid-index`, `Insertion index ${n} is outside the slot.`);
	e.splice(n, 0, r);
}
function j$8(e, t, n, r) {
	for (let [i, a] of e.entries()) {
		if (a.id === t) {
			let t = {
				collection: e,
				index: i,
				node: a
			};
			return n !== void 0 && r !== void 0 && (t.parentNodeId = n, t.slot = r), t;
		}
		for (let [e, n] of Object.entries(a.slots)) {
			let r = j$8(n, t, a.id, e);
			if (r !== void 0) return r;
		}
	}
}
function M$6(e) {
	return new StudioCommandError(`node-not-found`, `Node ${e} was not found.`);
}
function N$6(e, n, r) {
	return new StudioCommandError(`property-not-found`, `Property ${r === void 0 ? n : `${n} for viewport ${r}`} is not set on node ${e}.`);
}
function P$6(e, n, r) {
	return new StudioCommandError(`property-not-found`, `No size role is set on node ${e} for ${r === void 0 ? `axis ${n}` : `axis ${n} for viewport ${r}`}.`);
}
function F$6(e, n) {
	return new StudioCommandError(`binding-not-found`, `Binding ${n} is not present on node ${e}.`);
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/entry-commands.js
function applyEntryCommand(n, i) {
	if (i.artifactId !== n.id) throw new StudioCommandError(`node-not-found`, `Command targets ${i.artifactId}, not entry ${n.id}.`);
	if (i.payload.locale !== void 0 && n.locale !== void 0 && i.payload.locale !== n.locale) throw new StudioCommandError(`locale-mismatch`, `Command targets locale ${i.payload.locale}, but the entry stores ${n.locale}.`);
	let a = cloneContractValue(n), o = i.payload.fieldPath, s = a.values;
	for (let [n, a] of o.entries()) {
		if (n === o.length - 1) {
			r$9(s, a, cloneContractValue(i.payload.value));
			break;
		}
		let c = Object.hasOwn(s, a) ? s[a] : void 0;
		if (typeof c != `object` || !c || Array.isArray(c)) throw new StudioCommandError(`property-not-found`, `Field path segment ${a} does not resolve to an object value.`);
		s = c;
	}
	return a;
}
function r$9(e, t, n) {
	Object.defineProperty(e, t, {
		configurable: !0,
		enumerable: !0,
		value: n,
		writable: !0
	});
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/negotiation.js
var t$5 = [`studio.port/artifact`];
function negotiateCapabilities(n, r = {}) {
	let i = r.supportedProtocolVersions ?? [STUDIO_WIRE_PROTOCOL_VERSION], a = r.requiredPorts ?? t$5, o = r.optionalPorts ?? [], s = [], c = n.ports.map((e) => e.id), l = new Set(c), u = i.find((e) => n.protocolVersions.includes(e));
	u === void 0 && s.push({
		code: `studio.host/no-common-protocol-version`,
		message: {
			defaultMessage: `Studio and the host share no wire protocol version.`,
			key: `studio.host/no-common-protocol-version`
		},
		severity: `blocking`
	});
	let d = a.filter((e) => !l.has(e));
	for (let e of d) s.push({
		code: `studio.host/missing-required-port`,
		message: {
			defaultMessage: `The host does not provide the required ${e} port.`,
			key: `studio.host/missing-required-port`
		},
		parameters: { port: e },
		severity: `blocking`
	});
	let f = o.filter((e) => !l.has(e));
	for (let e of f) s.push({
		code: `studio.host/missing-optional-port`,
		message: {
			defaultMessage: `The optional ${e} port is unavailable; its features are disabled.`,
			key: `studio.host/missing-optional-port`
		},
		parameters: { port: e },
		severity: `information`
	});
	let p = {
		availablePorts: c,
		diagnostics: s,
		missingOptionalPorts: f,
		missingRequiredPorts: d,
		sessionState: u !== void 0 && d.length === 0 ? `editable` : `read-only`
	};
	return u !== void 0 && (p.protocolVersion = u), p;
}
Object.freeze([
	`blueprint`,
	`content`,
	`hybrid`,
	`model`,
	`read-only`
]);
var r$8 = [
	`studio.command/apply-pattern`,
	`studio.command/batch`,
	`studio.command/duplicate-node`,
	`studio.command/insert-node`,
	`studio.command/move-node`,
	`studio.command/remove-binding`,
	`studio.command/remove-node`,
	`studio.command/reorder-children`,
	`studio.command/reset-inherited-property`,
	`studio.command/restore-node`,
	`studio.command/set-binding`,
	`studio.command/set-property`,
	`studio.command/set-size-role`,
	`studio.command/unset-property`,
	`studio.command/unset-size-role`
];
var i$12 = [
	`studio.command/duplicate-node`,
	`studio.command/insert-node`,
	`studio.command/move-node`,
	`studio.command/remove-node`,
	`studio.command/reorder-children`,
	`studio.command/restore-node`
];
var a$11 = Object.freeze({
	blueprint: m$12(r$8),
	content: m$12([`studio.command/set-field-value`]),
	hybrid: m$12([
		`studio.command/batch`,
		...i$12,
		`studio.command/set-field-value`
	]),
	model: m$12([`studio.command/add-model-field`]),
	"read-only": m$12([])
});
var o$8 = m$12(i$12);
function permittedCommandTypes(e) {
	return a$11[e];
}
function resolveSessionMode(e) {
	if (e.sessionState === `read-only`) return `read-only`;
	if (e.composite === `hybrid`) {
		if (e.mode === `model`) throw RangeError(`The hybrid composite is invalid with the model editing mode.`);
		return `hybrid`;
	}
	return e.mode;
}
function assertModePermitsCommandType(e, n) {
	if (!a$11[e].has(n)) throw new StudioCommandError(`mode-forbidden`, `Command type ${n} is not permitted in ${e} mode.`);
}
function assertHybridCommandInBounds(n, r) {
	switch (r.type) {
		case `studio.command/batch`:
			for (let i of r.payload.operations) {
				let r = i.type;
				if (r === `studio.command/batch` || r === `studio.command/apply-pattern` || r === `studio.command/reset-inherited-property`) return;
				if (!o$8.has(i.type)) throw new StudioCommandError(`mode-forbidden`, `Batch operation type ${i.type} is not permitted in hybrid mode.`);
				s$10(n, i);
				try {
					applyOperation(n, i);
				} catch {
					return;
				}
			}
			return;
		case `studio.command/apply-pattern`:
		case `studio.command/reset-inherited-property`: throw new StudioCommandError(`mode-forbidden`, `Command type ${r.type} is not permitted in hybrid mode.`);
		default: s$10(n, r);
	}
}
function s$10(e, n) {
	switch (n.type) {
		case `studio.command/insert-node`:
		case `studio.command/restore-node`:
			f$13(n.payload.node), l$13(e, n.payload.destination, n.payload.node);
			return;
		case `studio.command/remove-node`: {
			let t = c$8(e.roots, n.payload.nodeId);
			if (t === void 0) return;
			f$13(t.node), u$13(t.parent, t.slot);
			return;
		}
		case `studio.command/move-node`: {
			let t = c$8(e.roots, n.payload.nodeId);
			if (t === void 0) return;
			f$13(t.node), u$13(t.parent, t.slot), l$13(e, n.payload.destination, t.node);
			return;
		}
		case `studio.command/duplicate-node`: {
			let t = c$8(e.roots, n.payload.nodeId);
			if (t === void 0) return;
			f$13(t.node), n.payload.destination === void 0 ? (u$13(t.parent, t.slot), t.parent !== void 0 && d$12(t.parent, t.slot, t.node)) : l$13(e, n.payload.destination, t.node);
			return;
		}
		case `studio.command/reorder-children`:
			if (n.payload.parentNodeId === void 0) throw p$12();
			u$13(c$8(e.roots, n.payload.parentNodeId)?.node, n.payload.slot);
			return;
		default: throw new StudioCommandError(`mode-forbidden`, `Batch operation type ${n.type} is not permitted in hybrid mode.`);
	}
}
function c$8(e, t, n, r) {
	for (let i of e) {
		if (i.id === t) return n === void 0 ? { node: i } : r === void 0 ? {
			node: i,
			parent: n
		} : {
			node: i,
			parent: n,
			slot: r
		};
		for (let [e, n] of Object.entries(i.slots)) {
			let r = c$8(n, t, i, e);
			if (r !== void 0) return r;
		}
	}
}
function l$13(e, t, n) {
	if (t.parentNodeId === void 0) throw p$12();
	let r = c$8(e.roots, t.parentNodeId)?.node;
	r !== void 0 && (u$13(r, t.slot), d$12(r, t.slot, n));
}
function u$13(e, n) {
	if (e === void 0) throw p$12();
	if (e.authoring.mode !== `structural` && (n === void 0 || e.authoring.slots?.[n]?.composable !== !0)) throw new StudioCommandError(`mode-forbidden`, `Hybrid composition is bounded to structural slots; node ${e.id} declares neither structural authoring nor a composable marker for the affected slot.`);
}
function d$12(e, n, r) {
	let i = (n === void 0 ? void 0 : e.authoring.slots?.[n])?.allowedBlocks ?? e.authoring.allowedBlocks;
	if (i !== void 0 && !i.includes(r.type)) throw new StudioCommandError(`mode-forbidden`, `Block type ${r.type} is not an allowed block inside the composable region of node ${e.id}.`);
}
function f$13(e) {
	let n = [e];
	for (; n.length > 0;) {
		let e = n.pop();
		if (e === void 0) break;
		if (e.authoring.mode === `locked`) throw new StudioCommandError(`mode-forbidden`, `Node ${e.id} is locked and never changes through hybrid composition.`);
		for (let t of Object.values(e.slots)) n.push(...t);
	}
}
function p$12() {
	return new StudioCommandError(`mode-forbidden`, `Hybrid composition is bounded to structural slots; the document roots are out of bounds.`);
}
function m$12(e) {
	let t = new Set(e), n = () => {
		throw TypeError(`The permitted command-type table is immutable.`);
	};
	return Object.freeze(Object.assign(t, {
		add: n,
		clear: n,
		delete: n
	}));
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/profile-validator.js
var e$5 = /* @__PURE__ */ new Set([
	`array`,
	`boolean`,
	`integer`,
	`null`,
	`number`,
	`object`,
	`string`
]);
var t$4 = new Set(`$defs.$id.$ref.$schema.additionalProperties.allOf.anyOf.const.contains.default.dependentRequired.description.else.enum.examples.exclusiveMaximum.exclusiveMinimum.if.items.maxItems.maxContains.maxLength.maxProperties.maximum.minItems.minContains.minLength.minProperties.minimum.multipleOf.not.oneOf.pattern.prefixItems.properties.propertyNames.readOnly.required.then.title.type.uniqueItems.writeOnly`.split(`.`));
var CompiledSchemaValidator = class {
	errors = null;
	#program;
	constructor(e) {
		this.#program = e;
	}
	validate(e) {
		let t = [], n = u$12(this.#program.root, e, ``, t, this.#program, /* @__PURE__ */ new Set(), /* @__PURE__ */ new WeakMap()), r = x$10(t);
		if (n === r.length > 0) throw TypeError(`Schema validation verdict and diagnostics disagree.`);
		return this.errors = r.length > 0 ? r : null, n;
	}
};
function compileProfileSchema(e, t = {}) {
	if (!D$7(e)) throw TypeError(`Schema root must be a plain JSON Schema object.`);
	let r = [], a = /* @__PURE__ */ new Map(), o = (e, t) => {
		let n = e.$id;
		if (n !== void 0 && typeof n != `string`) throw TypeError(`Schema $id must be a string.`);
		if (t && n === void 0) throw TypeError(`Registry schema documents must declare a root $id.`);
		let i = {
			baseUri: n,
			root: e,
			schemaPointers: /* @__PURE__ */ new Set()
		};
		if (n !== void 0) {
			if (a.has(n)) throw TypeError(`Schema registry declares ${n} more than once.`);
			a.set(n, i);
		}
		return r.push(i), i;
	};
	o(e, !1);
	for (let e of t.schemas ?? []) {
		if (!D$7(e)) throw TypeError(`Registry schema documents must be plain JSON Schema objects.`);
		o(e, !0);
	}
	let s = /* @__PURE__ */ new WeakMap(), l = /* @__PURE__ */ new WeakMap(), u = [];
	for (let e of r) i$11(e, s, u);
	for (let e of u) l.set(e.node, c$7(e, a));
	return new CompiledSchemaValidator({
		patterns: s,
		references: l,
		root: e
	});
}
function i$11(e, n, r) {
	let i = /* @__PURE__ */ new WeakSet(), c = (c, f) => {
		let p = F$5(e, f);
		if (!D$7(c)) throw TypeError(`${p} must be a plain JSON Schema object.`);
		if (i.has(c)) throw TypeError(`${p} reuses or cycles a schema object.`);
		i.add(c), e.schemaPointers.add(f);
		for (let [i, p] of k$7(c)) {
			let m = F$5(e, M$5(f, i));
			if (!t$4.has(i)) throw TypeError(`${m} uses keyword ${JSON.stringify(i)}, which the Studio schema interpreter does not support.`);
			switch (i) {
				case `$id`:
					if (f !== ``) throw TypeError(`${m} may only appear at the document root.`);
					break;
				case `$schema`:
					if (p !== `https://json-schema.org/draft/2020-12/schema`) throw TypeError(`${m} must declare JSON Schema Draft 2020-12.`);
					break;
				case `$ref`:
					if (typeof p != `string` || T$9(p) > 500) throw TypeError(`${m} must be a string of at most 500 characters.`);
					r.push({
						document: e,
						node: c,
						pointer: f,
						reference: p
					});
					break;
				case `$defs`:
				case `properties`:
					u(p, M$5(f, i));
					break;
				case `additionalProperties`:
				case `contains`:
				case `else`:
				case `if`:
				case `items`:
				case `not`:
				case `propertyNames`:
				case `then`:
					l(p, M$5(f, i));
					break;
				case `allOf`:
				case `anyOf`:
				case `oneOf`:
				case `prefixItems`:
					d(p, M$5(f, i));
					break;
				case `type`:
					o$7(p, m);
					break;
				case `enum`:
					if (!O$8(p) || p.length === 0) throw TypeError(`${m} must be a dense, non-empty JSON array.`);
					for (let e = 0; e < p.length; e += 1) if (p.slice(0, e).some((t) => w$9(t, p[e]))) throw TypeError(`${m} must contain unique JSON values.`);
					break;
				case `examples`:
					if (!O$8(p)) throw TypeError(`${m} must be a dense JSON array.`);
					break;
				case `const`:
				case `default`: break;
				case `required`:
					s$9(p, m);
					break;
				case `dependentRequired`:
					if (!D$7(p)) throw TypeError(`${m} must be an object of property-name arrays.`);
					for (let [e, t] of k$7(p)) s$9(t, `${m}.${e}`);
					break;
				case `maxItems`:
				case `maxContains`:
				case `maxLength`:
				case `maxProperties`:
				case `minItems`:
				case `minContains`:
				case `minLength`:
				case `minProperties`:
					if (typeof p != `number` || !Number.isInteger(p) || p < 0) throw TypeError(`${m} must be a non-negative integer.`);
					break;
				case `exclusiveMaximum`:
				case `exclusiveMinimum`:
				case `maximum`:
				case `minimum`:
					if (typeof p != `number` || !Number.isFinite(p)) throw TypeError(`${m} must be a finite number.`);
					break;
				case `multipleOf`:
					if (typeof p != `number` || !Number.isFinite(p) || p <= 0) throw TypeError(`${m} must be a finite number greater than zero.`);
					break;
				case `pattern`:
					n.set(c, a$10(p, m));
					break;
				case `readOnly`:
				case `uniqueItems`:
				case `writeOnly`:
					if (typeof p != `boolean`) throw TypeError(`${m} must be a boolean.`);
					break;
				case `description`:
				case `title`:
					if (typeof p != `string`) throw TypeError(`${m} must be a string.`);
					break;
				default: throw TypeError(`${m} is not interpretable.`);
			}
		}
		if ((c.minContains !== void 0 || c.maxContains !== void 0) && c.contains === void 0) throw TypeError(`${p} declares contains bounds without contains.`);
		if (typeof c.minContains == `number` && typeof c.maxContains == `number` && c.minContains > c.maxContains) throw TypeError(`${p} declares minContains greater than maxContains.`);
	}, l = (t, n) => {
		if (typeof t == `boolean`) {
			e.schemaPointers.add(n);
			return;
		}
		c(t, n);
	}, u = (t, n) => {
		if (!D$7(t)) throw TypeError(`${F$5(e, n)} must be an object of schemas.`);
		for (let [e, r] of k$7(t)) l(r, M$5(n, e));
	}, d = (t, n) => {
		if (!O$8(t) || t.length === 0) throw TypeError(`${F$5(e, n)} must be a dense, non-empty array of schemas.`);
		for (let [e, r] of t.entries()) l(r, M$5(n, String(e)));
	};
	c(e.root, ``);
}
function a$10(e, t) {
	if (typeof e != `string` || T$9(e) > 500) throw TypeError(`${t} must be a lexical pattern of at most 500 characters.`);
	try {
		return new RegExp(e, `u`);
	} catch (e) {
		throw TypeError(`${t} is not a valid Unicode regular expression.`, { cause: e });
	}
}
function o$7(t, n) {
	if (typeof t == `string`) {
		if (!e$5.has(t)) throw TypeError(`${n} names an unknown JSON Schema type.`);
		return;
	}
	if (!O$8(t) || t.length === 0) throw TypeError(`${n} must be a type name or a dense, non-empty array of them.`);
	let r = /* @__PURE__ */ new Set();
	for (let i of t) {
		if (typeof i != `string` || !e$5.has(i) || r.has(i)) throw TypeError(`${n} must list unique, known JSON Schema type names.`);
		r.add(i);
	}
}
function s$9(e, t) {
	if (!O$8(e)) throw TypeError(`${t} must be a dense array of property names.`);
	let n = /* @__PURE__ */ new Set();
	for (let r of e) {
		if (typeof r != `string` || n.has(r)) throw TypeError(`${t} must list unique property-name strings.`);
		n.add(r);
	}
}
function c$7(e, t) {
	let n = `${F$5(e.document, e.pointer)}/$ref`, r = e.reference.indexOf(`#`), i = r === -1 ? e.reference : e.reference.slice(0, r), a = r === -1 ? `` : e.reference.slice(r + 1), o;
	if (i === ``) o = e.document;
	else {
		let r = l$12(e.document.baseUri, i, n), a = t.get(r);
		if (a === void 0) throw TypeError(`${n} references ${r}, which is not in the registry.`);
		o = a;
	}
	if (a !== `` && !a.startsWith(`/`)) throw TypeError(`${n} must use a JSON Pointer fragment.`);
	let s = a === `` ? [] : a.slice(1).split(`/`).map((e) => P$5(e, n)), c = s.map((e) => `/${N$5(e)}`).join(``);
	if (c !== `` && !o.schemaPointers.has(c)) throw TypeError(`${n} does not reference a schema position.`);
	let u = o.root;
	for (let e of s) if (Array.isArray(u)) {
		let t = Number(e);
		if (!Number.isInteger(t) || t < 0 || t >= u.length) throw TypeError(`${n} does not resolve to a schema.`);
		u = u[t];
	} else if (D$7(u) && Object.hasOwn(u, e)) u = u[e];
	else throw TypeError(`${n} does not resolve to a schema.`);
	if (typeof u == `boolean` || D$7(u)) return u;
	throw TypeError(`${n} does not resolve to a schema.`);
}
function l$12(e, t, n) {
	if (/^[A-Za-z][A-Za-z0-9+.-]*:/u.test(t)) return t;
	if (e === void 0) throw TypeError(`${n} uses a relative reference without a document base URI.`);
	if (t.startsWith(`/`) || t.split(`/`).some((e) => e === `..` || e === `.`)) throw TypeError(`${n} must stay within the schema registry root.`);
	return e.slice(0, e.lastIndexOf(`/`) + 1) + t;
}
function u$12(e, t, n, r, i, a, o) {
	if (typeof e == `boolean`) return e || r.push({
		instancePath: n,
		keyword: `false`,
		message: `boolean schema is false`
	}), e;
	let s = y$10(o, e, n, t);
	if (s !== void 0) {
		for (let e of s.diagnostics) r.push({ ...e });
		return s.valid;
	}
	if (a.has(e)) throw RangeError(`Schema evaluation cycled without consuming instance input.`);
	a.add(e);
	let c = r.length, l;
	try {
		l = d$11(e, t, n, r, i, a, o);
	} finally {
		a.delete(e);
	}
	let u = x$10(r.slice(c));
	if (l === u.length > 0) throw TypeError(`Subschema validation verdict and diagnostics disagree.`);
	return b$10(o, e, n, t, {
		diagnostics: u,
		valid: l
	}), l;
}
function d$11(e, t, n, r, i, a, o) {
	let s = !0, c = (e, t, i = n) => {
		s = !1, r.push({
			instancePath: i,
			keyword: e,
			message: t
		});
	};
	if (e.$ref !== void 0) {
		let c = i.references.get(e);
		if (c === void 0) throw TypeError(`Schema reference was not resolved at compile time.`);
		u$12(c, t, n, r, i, a, o) || (s = !1);
	}
	let l = e.type;
	return typeof l == `string` ? S$10(l, t) || c(`type`, `must be ${l}`) : Array.isArray(l) && (l.some((e) => typeof e == `string` && S$10(e, t)) || c(`type`, `must be ${l.join(`,`)}`)), e.enum !== void 0 && Array.isArray(e.enum) && (e.enum.some((e) => w$9(e, t)) || c(`enum`, `must be equal to one of the allowed values`)), Object.hasOwn(e, `const`) && !w$9(e.const, t) && c(`const`, `must be equal to constant`), f$12(e, t, n, r, i, a, o, c), typeof t == `string` ? p$11(e, t, c, i) : typeof t == `number` && Number.isFinite(t) ? m$11(e, t, c) : Array.isArray(t) ? _$10(e, t, n, r, i, o, c) || (s = !1) : E$9(t) && (v$10(e, t, n, r, i, o, c) || (s = !1)), s;
}
function f$12(e, t, n, r, i, a, o, s) {
	let c = (e) => u$12(e, t, n, [], i, a, o);
	if (Array.isArray(e.allOf)) for (let c of e.allOf) u$12(c, t, n, r, i, a, o) || s(`allOf`, `must match all schemas in allOf`);
	if (Array.isArray(e.anyOf) && (e.anyOf.some((e) => c(e)) || s(`anyOf`, `must match a schema in anyOf`)), Array.isArray(e.oneOf)) {
		let t = 0;
		for (let n of e.oneOf) if (c(n) && (t += 1) > 1) break;
		t !== 1 && s(`oneOf`, `must match exactly one schema in oneOf`);
	}
	if (e.not !== void 0 && c(e.not) && s(`not`, `must NOT be valid`), e.if !== void 0) {
		let l = c(e.if) ? e.then : e.else;
		l !== void 0 && !u$12(l, t, n, r, i, a, o) && s(`if`, `must match the conditional schema`);
	}
}
function p$11(e, t, n, r) {
	let i = e.minLength, a = e.maxLength;
	if (typeof i == `number` || typeof a == `number`) {
		let e = T$9(t);
		typeof i == `number` && e < i && n(`minLength`, `must NOT have fewer than ${i} characters`), typeof a == `number` && e > a && n(`maxLength`, `must NOT have more than ${a} characters`);
	}
	if (typeof e.pattern == `string`) {
		let i = r.patterns.get(e);
		if (i === void 0) throw TypeError(`Schema pattern was not compiled.`);
		i.test(t) || n(`pattern`, `must match pattern "${e.pattern}"`);
	}
}
function m$11(e, t, n) {
	typeof e.minimum == `number` && t < e.minimum && n(`minimum`, `must be >= ${e.minimum}`), typeof e.maximum == `number` && t > e.maximum && n(`maximum`, `must be <= ${e.maximum}`), typeof e.exclusiveMinimum == `number` && t <= e.exclusiveMinimum && n(`exclusiveMinimum`, `must be > ${e.exclusiveMinimum}`), typeof e.exclusiveMaximum == `number` && t >= e.exclusiveMaximum && n(`exclusiveMaximum`, `must be < ${e.exclusiveMaximum}`), typeof e.multipleOf == `number` && (h$10(t, e.multipleOf) || n(`multipleOf`, `must be multiple of ${e.multipleOf}`));
}
function h$10(e, t) {
	let n = g$10(e), r = g$10(t), i = n.exponent - r.exponent;
	return i >= 0 ? n.coefficient * 10n ** BigInt(i) % r.coefficient == 0n : n.coefficient % (r.coefficient * 10n ** BigInt(-i)) == 0n;
}
function g$10(e) {
	let t = JSON.stringify(Object.is(e, -0) ? 0 : e), n = /^(-?)(\d+)(?:\.(\d+))?(?:e([+-]?\d+))?$/u.exec(t);
	if (n === null) throw TypeError(`Canonical decimal conversion requires a finite number.`);
	let r = n[3] ?? ``, i = BigInt(`${n[1] ?? ``}${n[2]}${r}`), a = Number(n[4] ?? 0) - r.length;
	for (; i !== 0n && i % 10n == 0n;) i /= 10n, a += 1;
	return {
		coefficient: i,
		exponent: a
	};
}
function _$10(e, t, n, r, i, a, o) {
	let s = !0, c = (e, o) => {
		u$12(e, t[o], `${n}/${o}`, r, i, /* @__PURE__ */ new Set(), a) || (s = !1);
	}, l = Array.isArray(e.prefixItems) ? e.prefixItems : void 0, d = l?.length ?? 0;
	if (l !== void 0) for (let e = 0; e < Math.min(d, t.length); e += 1) c(l[e], e);
	let f = e.items;
	if (f !== void 0 && t.length > d) {
		if (f === !1) o(`items`, `must NOT have more than ${d} items`);
		else if (f !== !0) for (let e = d; e < t.length; e += 1) c(f, e);
	}
	if (typeof e.minItems == `number` && t.length < e.minItems && o(`minItems`, `must NOT have fewer than ${e.minItems} items`), typeof e.maxItems == `number` && t.length > e.maxItems && o(`maxItems`, `must NOT have more than ${e.maxItems} items`), e.uniqueItems === !0) {
		let e = C$10(t);
		e !== void 0 && o(`uniqueItems`, `must NOT have duplicate items (items ## ${e[0]} and ${e[1]} are identical)`);
	}
	if (e.contains !== void 0) {
		let r = typeof e.minContains == `number` ? e.minContains : 1, s = typeof e.maxContains == `number` ? e.maxContains : 1 / 0, c = 0;
		for (let r = 0; r < t.length; r += 1) u$12(e.contains, t[r], `${n}/${r}`, [], i, /* @__PURE__ */ new Set(), a) && (c += 1);
		(c < r || c > s) && o(`contains`, `must contain ${Number.isFinite(s) ? `between ${r} and ${s}` : `at least ${r}`} matching items`);
	}
	return s;
}
function v$10(e, t, n, r, i, a, o) {
	let s = !0, c = Object.keys(t).filter((e) => t[e] !== void 0).sort(j$7), l = (e) => Object.hasOwn(t, e) && t[e] !== void 0, d = D$7(e.properties) ? e.properties : void 0;
	if (d !== void 0) for (let [e, o] of k$7(d)) l(e) && !u$12(o, t[e], `${n}/${N$5(e)}`, r, i, /* @__PURE__ */ new Set(), a) && (s = !1);
	if (Array.isArray(e.required)) for (let t of A$7(e.required)) l(t) || o(`required`, `must have required property '${t}'`);
	let f = e.additionalProperties;
	if (f !== void 0) for (let e of c) d !== void 0 && Object.hasOwn(d, e) || (f === !1 ? o(`additionalProperties`, `must NOT have additional properties`) : f !== !0 && !u$12(f, t[e], `${n}/${N$5(e)}`, r, i, /* @__PURE__ */ new Set(), a) && (s = !1));
	let p = e.propertyNames;
	if (p !== void 0) for (let e of c) u$12(p, e, n, [], i, /* @__PURE__ */ new Set(), a) || o(`propertyNames`, `property name '${e}' is invalid`);
	let m = e.dependentRequired;
	if (D$7(m)) {
		for (let [e, t] of k$7(m)) if (!(!l(e) || !Array.isArray(t))) for (let n of A$7(t)) l(n) || o(`dependentRequired`, `must have property ${n} when property ${e} is present`);
	}
	return typeof e.minProperties == `number` && c.length < e.minProperties && o(`minProperties`, `must NOT have fewer than ${e.minProperties} properties`), typeof e.maxProperties == `number` && c.length > e.maxProperties && o(`maxProperties`, `must NOT have more than ${e.maxProperties} properties`), s;
}
function y$10(e, t, n, r) {
	return (e.get(t)?.get(n))?.get(r);
}
function b$10(e, t, n, r, i) {
	let a = e.get(t);
	a === void 0 && (a = /* @__PURE__ */ new Map(), e.set(t, a));
	let o = a.get(n);
	o === void 0 && (o = /* @__PURE__ */ new Map(), a.set(n, o)), o.set(r, i);
}
function x$10(e) {
	let t = /* @__PURE__ */ new Set(), n = [];
	for (let r of e) {
		let e = JSON.stringify([
			r.instancePath,
			r.keyword,
			r.message
		]);
		t.has(e) || (t.add(e), n.push({ ...r }));
	}
	return n;
}
function S$10(e, t) {
	switch (e) {
		case `array`: return Array.isArray(t);
		case `boolean`: return typeof t == `boolean`;
		case `integer`: return typeof t == `number` && Number.isFinite(t) && t % 1 == 0;
		case `null`: return t === null;
		case `number`: return typeof t == `number` && Number.isFinite(t);
		case `object`: return E$9(t);
		case `string`: return typeof t == `string`;
		default: return !1;
	}
}
function C$10(e) {
	for (let t = 1; t < e.length; t += 1) for (let n = 0; n < t; n += 1) if (w$9(e[t], e[n])) return [n, t];
}
function w$9(e, t) {
	if (e === t) return !0;
	if (Array.isArray(e) && Array.isArray(t)) {
		if (e.length !== t.length) return !1;
		for (let n = 0; n < e.length; n += 1) if (!w$9(e[n], t[n])) return !1;
		return !0;
	}
	if (typeof e == `object` && typeof t == `object` && e !== null && t !== null && !Array.isArray(e) && !Array.isArray(t)) {
		let n = Object.keys(e), r = t;
		if (n.length !== Object.keys(t).length) return !1;
		for (let i of n) if (!Object.hasOwn(t, i) || !w$9(e[i], r[i])) return !1;
		return !0;
	}
	return !1;
}
function T$9(e) {
	let t = 0;
	for (let n = 0; n < e.length; n += 1) {
		t += 1;
		let r = e.charCodeAt(n);
		r >= 55296 && r <= 56319 && n + 1 < e.length && (e.charCodeAt(n + 1) & 64512) == 56320 && (n += 1);
	}
	return t;
}
function E$9(e) {
	return typeof e == `object` && !!e && !Array.isArray(e);
}
function D$7(e) {
	return typeof e == `object` && !!e && !Array.isArray(e);
}
function O$8(e) {
	if (!Array.isArray(e)) return !1;
	let t = Object.keys(e);
	return t.length === e.length && t.every((e, t) => e === String(t));
}
function k$7(e) {
	return Object.entries(e).sort(([e], [t]) => j$7(e, t));
}
function A$7(e) {
	let t = [];
	for (let n of e) typeof n == `string` && t.push(n);
	return t.sort(j$7);
}
function j$7(e, t) {
	return e < t ? -1 : +(e > t);
}
function M$5(e, t) {
	return `${e}/${N$5(t)}`;
}
function N$5(e) {
	return e.replaceAll(`~`, `~0`).replaceAll(`/`, `~1`);
}
function P$5(e, t) {
	if (/(?:~[^01]|~$)/u.test(e)) throw TypeError(`${t} is not a valid JSON Pointer reference.`);
	return e.replaceAll(`~1`, `/`).replaceAll(`~0`, `~`);
}
function F$5(e, t) {
	return `${e.baseUri ?? `schema`}#${t}`;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/session-policy.js
var STUDIO_PROTOCOL_MAXIMUM_COMMAND_POLICY_LIMITS = Object.freeze({
	maxChildrenPerSlot: 1e4,
	maxCommandBatch: 1e4,
	maxDepth: 128,
	maxExtensionBytes: 10485760,
	maxNodes: 1e5,
	maxPropertyBytes: 10485760,
	maxRichTextBytes: 10485760,
	maxRichTextDepth: 128,
	maxSlotsPerNode: 100
});
var i$10 = Object.freeze({
	maxChildrenPerSlot: 0,
	maxCommandBatch: 1,
	maxDepth: 1,
	maxExtensionBytes: 0,
	maxNodes: 1,
	maxPropertyBytes: 0,
	maxRichTextBytes: 1,
	maxRichTextDepth: 1,
	maxSlotsPerNode: 0
});
function resolveStudioSessionPolicy(e = {}) {
	let t = e.limits ?? {}, n = {
		maxChildrenPerSlot: s$8(`maxChildrenPerSlot`, t.maxChildrenPerSlot),
		maxCommandBatch: s$8(`maxCommandBatch`, t.maxCommandBatch),
		maxDepth: s$8(`maxDepth`, t.maxDepth),
		maxExtensionBytes: s$8(`maxExtensionBytes`, t.maxExtensionBytes),
		maxNodes: s$8(`maxNodes`, t.maxNodes),
		maxPropertyBytes: s$8(`maxPropertyBytes`, t.maxPropertyBytes),
		maxRichTextBytes: s$8(`maxRichTextBytes`, t.maxRichTextBytes),
		maxRichTextDepth: s$8(`maxRichTextDepth`, t.maxRichTextDepth),
		maxSlotsPerNode: s$8(`maxSlotsPerNode`, t.maxSlotsPerNode)
	};
	return Object.freeze({
		limits: Object.freeze(n),
		permissions: new Set(e.permissions ?? [])
	});
}
function assertBlueprintCommandPolicy(e, t, n, r) {
	if (t.type === `studio.command/batch` && t.payload.operations.length > r.limits.maxCommandBatch) throw C$9(`maxCommandBatch`, t.payload.operations.length, r.limits.maxCommandBatch);
	c$6(e, t, r.permissions), assertBlueprintWithinSessionPolicy(n, r.limits);
}
function assertBlueprintWithinSessionPolicy(e, t) {
	let n = v$9(e.extensions);
	if (n > t.maxExtensionBytes) throw C$9(`maxExtensionBytes`, n, t.maxExtensionBytes);
	let r = 0, i = 0, a = e.roots.map((e) => ({
		depth: 1,
		node: e
	}));
	for (; a.length > 0;) {
		let e = a.pop();
		if (e === void 0) break;
		if (r += 1, r > t.maxNodes) throw C$9(`maxNodes`, r, t.maxNodes);
		if (e.depth > t.maxDepth) throw C$9(`maxDepth`, e.depth, t.maxDepth);
		let o = Object.entries(e.node.slots);
		if (o.length > t.maxSlotsPerNode) throw C$9(`maxSlotsPerNode`, o.length, t.maxSlotsPerNode);
		if (i += y$9(e.node.properties), i += y$9(e.node.responsive), i > t.maxPropertyBytes) throw C$9(`maxPropertyBytes`, i, t.maxPropertyBytes);
		if (n += v$9(e.node.extensions), n > t.maxExtensionBytes) throw C$9(`maxExtensionBytes`, n, t.maxExtensionBytes);
		for (let [, n] of o) {
			if (n.length > t.maxChildrenPerSlot) throw C$9(`maxChildrenPerSlot`, n.length, t.maxChildrenPerSlot);
			for (let t of n) a.push({
				depth: e.depth + 1,
				node: t
			});
		}
	}
	g$9(e, t);
}
function assertEntryWithinSessionPolicy(e, t) {
	let n = v$9(e.extensions);
	if (n > t.maxExtensionBytes) throw C$9(`maxExtensionBytes`, n, t.maxExtensionBytes);
	g$9(e, t);
}
function assertModelWithinSessionPolicy(e, t) {
	let n = v$9(e.extensions);
	for (let t of e.relationships) n += v$9(t.extensions);
	let r = [...e.fields];
	for (; r.length > 0;) {
		let e = r.pop();
		if (e === void 0) break;
		n += v$9(e.extensions), r.push(...e.fields ?? []);
	}
	if (n > t.maxExtensionBytes) throw C$9(`maxExtensionBytes`, n, t.maxExtensionBytes);
	g$9(e, t);
}
function s$8(e, t) {
	let n = STUDIO_PROTOCOL_MAXIMUM_COMMAND_POLICY_LIMITS[e], a = t ?? n;
	if (!Number.isSafeInteger(a) || a < i$10[e] || a > n) throw RangeError(`${e} must be an integer between ${String(i$10[e])} and ${String(n)}.`);
	return a;
}
function c$6(n, r, i) {
	if (r.type === `studio.command/batch`) {
		let a = cloneContractValue(n);
		for (let e of r.payload.operations) l$11(a, e, i), applyOperation(a, e);
		return;
	}
	if (r.type === `studio.command/apply-pattern`) {
		u$11(n, r.payload.destination.parentNodeId, i);
		for (let e of r.payload.nodes) f$11(e, i);
		return;
	}
	if (r.type === `studio.command/reset-inherited-property`) {
		d$10(n, r.payload.nodeId, i);
		return;
	}
	l$11(n, r, i);
}
function l$11(e, t, n) {
	switch (t.type) {
		case `studio.command/insert-node`:
		case `studio.command/restore-node`:
			u$11(e, t.payload.destination.parentNodeId, n), f$11(t.payload.node, n);
			return;
		case `studio.command/remove-node`: {
			let r = m$10(e, t.payload.nodeId);
			p$10(r.parent, n), f$11(r.node, n);
			return;
		}
		case `studio.command/move-node`: {
			let r = m$10(e, t.payload.nodeId);
			p$10(r.parent, n), f$11(r.node, n), u$11(e, t.payload.destination.parentNodeId, n);
			return;
		}
		case `studio.command/duplicate-node`: {
			let r = m$10(e, t.payload.nodeId);
			p$10(r.parent, n), f$11(r.node, n), u$11(e, t.payload.destination?.parentNodeId, n);
			return;
		}
		case `studio.command/reorder-children`:
			u$11(e, t.payload.parentNodeId, n);
			for (let r of t.payload.order) d$10(e, r, n);
			return;
		case `studio.command/remove-binding`:
		case `studio.command/set-binding`:
		case `studio.command/set-property`:
		case `studio.command/set-size-role`:
		case `studio.command/unset-property`:
		case `studio.command/unset-size-role`:
			d$10(e, t.payload.nodeId, n);
			return;
	}
}
function u$11(e, t, n) {
	t !== void 0 && d$10(e, t, n);
}
function d$10(e, t, n) {
	p$10(m$10(e, t).node, n);
}
function f$11(e, t) {
	let n = [e];
	for (; n.length > 0;) {
		let e = n.pop();
		if (e === void 0) break;
		p$10(e, t);
		for (let t of Object.values(e.slots)) n.push(...t);
	}
}
function p$10(e, t) {
	let r = e?.authoring.requiredPermission;
	if (r !== void 0 && !t.has(r)) throw new StudioCommandError(`permission-forbidden`, `Node ${String(e?.id)} requires the ${r} permission for this command.`);
}
function m$10(e, t) {
	let r = h$9(e.roots, t);
	if (r === void 0) throw new StudioCommandError(`node-not-found`, `Node ${t} does not exist.`);
	return r;
}
function h$9(e, t, n) {
	for (let r of e) {
		if (r.id === t) return n === void 0 ? { node: r } : {
			node: r,
			parent: n
		};
		for (let e of Object.values(r.slots)) {
			let n = h$9(e, t, r);
			if (n !== void 0) return n;
		}
	}
}
function g$9(e, t) {
	let n = [e];
	for (; n.length > 0;) {
		let e = n.pop();
		if (!(typeof e != `object` || !e)) {
			if (Array.isArray(e)) {
				n.push(...e);
				continue;
			}
			if (e.type === `doc` && Array.isArray(e.content)) {
				let n = b$9(e);
				if (n > t.maxRichTextBytes) throw C$9(`maxRichTextBytes`, n, t.maxRichTextBytes);
				let r = _$9(e);
				if (r > t.maxRichTextDepth) throw C$9(`maxRichTextDepth`, r, t.maxRichTextDepth);
				continue;
			}
			for (let t of Object.values(e)) n.push(t);
		}
	}
}
function _$9(e) {
	let t = 1, n = [{
		depth: 1,
		node: e
	}];
	for (; n.length > 0;) {
		let e = n.pop();
		if (e === void 0) break;
		t = Math.max(t, e.depth);
		let r = e.node.content;
		if (Array.isArray(r)) for (let t of r) S$9(t) && n.push({
			depth: e.depth + 1,
			node: t
		});
	}
	return t;
}
function v$9(e) {
	return y$9(e);
}
function y$9(e) {
	return e === void 0 || Object.keys(e).length === 0 ? 0 : Math.max(0, b$9(e) - 2);
}
function b$9(e) {
	let t;
	try {
		let n = JSON.stringify(e);
		if (n === void 0) throw TypeError(`The value is not JSON serializable.`);
		t = n;
	} catch {
		throw new StudioCommandError(`resource-limit`, `The command value cannot be measured within the finite JSON resource boundary.`);
	}
	return x$9(t);
}
function x$9(e) {
	let t = 0;
	for (let n = 0; n < e.length; n += 1) {
		let r = e.charCodeAt(n);
		if (r <= 127) t += 1;
		else if (r <= 2047) t += 2;
		else if (r >= 55296 && r <= 56319) {
			let r = e.charCodeAt(n + 1);
			r >= 56320 && r <= 57343 ? (t += 4, n += 1) : t += 3;
		} else t += 3;
	}
	return t;
}
function S$9(e) {
	return typeof e == `object` && !!e && !Array.isArray(e);
}
function C$9(e, t, r) {
	return new StudioCommandError(`resource-limit`, `${e} permits at most ${String(r)}, but the projected command requires ${String(t)}.`);
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/history.js
var StudioHistory = class {
	#maximumEntries;
	#policy;
	#current;
	#future = [];
	#past = [];
	#stateVersion = 0;
	constructor(e, t = 100, r) {
		if (!Number.isInteger(t) || t < 1) throw RangeError(`History maximum must be a positive integer.`);
		this.#current = cloneContractValue(e), this.#maximumEntries = t, this.#policy = r;
	}
	get canRedo() {
		return this.#future.length > 0;
	}
	get canUndo() {
		return this.#past.length > 0;
	}
	get current() {
		return cloneContractValue(this.#current);
	}
	get stateVersion() {
		return this.#stateVersion;
	}
	rebaseRevision(e) {
		let t = (t) => ({
			...t,
			revision: e
		});
		return this.#current = t(this.#current), this.#past = this.#past.map(t), this.#future = this.#future.map(t), this.current;
	}
	execute(n) {
		if (n.baseStateVersion !== this.#stateVersion) throw new StudioCommandError(`stale-state`, `Command state ${n.baseStateVersion} does not match ${this.#stateVersion}.`);
		let i = applyCommand(this.#current, n);
		return this.#policy !== void 0 && assertBlueprintCommandPolicy(this.#current, n, i, this.#policy), this.#past.push(this.#current), this.#past.length > this.#maximumEntries && this.#past.shift(), this.#current = i, this.#future = [], this.#stateVersion += 1, this.current;
	}
	redo() {
		let e = this.#future.pop();
		return e === void 0 ? this.current : (this.#past.push(this.#current), this.#current = e, this.#stateVersion += 1, this.current);
	}
	undo() {
		let e = this.#past.pop();
		return e === void 0 ? this.current : (this.#future.push(this.#current), this.#current = e, this.#stateVersion += 1, this.current);
	}
};
//#endregion
//#region node_modules/@kumwe/studio-core/dist/model-commands.js
function applyModelCommand(n, r) {
	if (r.artifactId !== n.id) throw new StudioCommandError(`node-not-found`, `Command targets ${r.artifactId}, not content model ${n.id}.`);
	if (n.status !== `draft`) throw new StudioCommandError(`artifact-not-draft`, `Content model ${n.id} is ${n.status}; fields are added through a new draft.`);
	if (n.fields.some((e) => e.id === r.payload.field.id)) throw new StudioCommandError(`duplicate-field`, `Field ${r.payload.field.id} already exists on ${n.id}.`);
	let i = r.payload.position ?? n.fields.length;
	if (!Number.isInteger(i) || i < 0 || i > n.fields.length) throw new StudioCommandError(`invalid-index`, `Field position ${i} is outside the model's field list.`);
	let a = cloneContractValue(n);
	return a.fields.splice(i, 0, cloneContractValue(r.payload.field)), a;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/session.js
var StudioSession = class {
	#history;
	#mode;
	#policy;
	#sessionGeneration;
	#savedRevision;
	#savedStateVersion = 0;
	#selection = [];
	constructor(e) {
		this.#policy = resolveStudioSessionPolicy({
			...e.limits === void 0 ? {} : { limits: e.limits },
			...e.permissions === void 0 ? {} : { permissions: e.permissions }
		}), this.#history = new StudioHistory(e.document, e.maximumHistoryEntries ?? 100, this.#policy), this.#mode = l$10(e), this.#sessionGeneration = e.sessionGeneration, this.#savedRevision = e.document.revision;
	}
	get canRedo() {
		return this.#history.canRedo;
	}
	get canUndo() {
		return this.#history.canUndo;
	}
	get dirty() {
		return this.#history.stateVersion !== this.#savedStateVersion;
	}
	get document() {
		return this.#history.current;
	}
	get mode() {
		return this.#mode;
	}
	get selection() {
		return [...this.#selection];
	}
	get sessionState() {
		return this.#mode === `read-only` ? `read-only` : `editable`;
	}
	get stateVersion() {
		return this.#history.stateVersion;
	}
	execute(t) {
		if (this.#assertWritable(), this.#assertLiveGeneration(t), assertModePermitsCommandType(this.#mode, t.type), this.#mode === `hybrid` && assertHybridCommandInBounds(this.#history.current, t), t.expectedRevision !== void 0 && t.expectedRevision !== this.#savedRevision) throw new StudioCommandError(`stale-state`, `Command expects revision ${t.expectedRevision}, but the session holds ${this.#savedRevision}.`);
		let n = this.#history.execute(t);
		return this.#pruneSelection(n), n;
	}
	executeEntryCommand(n, r) {
		if (this.#assertWritable(), this.#assertLiveGeneration(r), assertModePermitsCommandType(this.#mode, r.type), r.expectedRevision !== void 0 && r.expectedRevision !== n.revision) throw new StudioCommandError(`stale-state`, `Command expects revision ${r.expectedRevision}, but the entry holds ${n.revision}.`);
		let i = applyEntryCommand(n, r);
		return assertEntryWithinSessionPolicy(i, this.#policy.limits), i;
	}
	executeModelCommand(t, n) {
		if (this.#assertWritable(), this.#assertLiveGeneration(n), assertModePermitsCommandType(this.#mode, n.type), n.expectedRevision !== void 0 && n.expectedRevision !== t.revision) throw new StudioCommandError(`stale-state`, `Command expects revision ${n.expectedRevision}, but the model holds ${t.revision}.`);
		let i = applyModelCommand(t, n);
		return assertModelWithinSessionPolicy(i, this.#policy.limits), i;
	}
	markSaved(e, t = this.#history.stateVersion) {
		if (!Number.isSafeInteger(t) || t < 0 || t > this.#history.stateVersion) throw RangeError(`A saved snapshot version must be a known non-negative state version.`);
		this.#history.rebaseRevision(e), this.#savedRevision = e, this.#savedStateVersion = t;
	}
	get savedRevision() {
		return this.#savedRevision;
	}
	select(t) {
		let n = this.#history.current, r = [];
		for (let i of t) if (!r.includes(i)) {
			if (!u$10(n.roots, i)) throw new StudioCommandError(`node-not-found`, `Node ${i} cannot be selected because it is not in the document.`);
			r.push(i);
		}
		return this.#selection = r, this.selection;
	}
	clearSelection() {
		this.#selection = [];
	}
	undo() {
		let e = this.#history.undo();
		return this.#pruneSelection(e), e;
	}
	redo() {
		let e = this.#history.redo();
		return this.#pruneSelection(e), e;
	}
	#assertWritable() {
		if (this.#mode === `read-only`) throw new StudioCommandError(`read-only-session`, `A read-only session never applies a persistent command.`);
	}
	#assertLiveGeneration(t) {
		if (t.sessionGeneration !== this.#sessionGeneration) throw new StudioCommandError(`stale-generation`, `Command generation ${t.sessionGeneration} does not match the active session generation.`);
	}
	#pruneSelection(e) {
		this.#selection.length > 0 && (this.#selection = this.#selection.filter((t) => u$10(e.roots, t)));
	}
};
function l$10(e) {
	let { mode: t, sessionState: n } = e;
	if (t === void 0) {
		if (n === void 0) throw RangeError(`A session requires an explicit mode or session state.`);
		return n === `read-only` ? `read-only` : `blueprint`;
	}
	if (n !== void 0 && n === `read-only` != (t === `read-only`)) throw RangeError(`Session mode ${t} contradicts session state ${n}; mode read-only is the read-only state.`);
	return t;
}
function u$10(e, t) {
	for (let n of e) {
		if (n.id === t) return !0;
		for (let e of Object.values(n.slots)) if (u$10(e, t)) return !0;
	}
	return !1;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/host-session.js
var p$9 = `studio.port/artifact`;
var m$9 = `studio.port/model`;
var h$8 = `studio.port/recovery`;
var g$8 = `studio.port/resource`;
var _$8 = `studio.operation/artifact.load`;
var v$8 = `studio.operation/artifact.save`;
var y$8 = `studio.operation/model.get`;
var b$8 = `studio.operation/model.list`;
var x$8 = `studio.operation/recovery.store`;
var S$8 = `studio.operation/recovery.load`;
var C$8 = `studio.operation/recovery.discard`;
var w$8 = `studio.operation/resource.search`;
var T$8 = /^[A-Za-z0-9][A-Za-z0-9._:/-]*$/u;
var E$8 = /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\/[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/u;
var D$6 = /* @__PURE__ */ new Set([
	`__proto__`,
	`prototype`,
	`constructor`
]);
var STUDIO_RESOURCE_SEARCH_LIMITS = Object.freeze({
	maximumCursorLength: 500,
	maximumLimit: 100,
	maximumSearchLength: 500,
	minimumLimit: 1
});
var O$7 = compileProfileSchema(contentModelSchema, { schemas: [commonSchema] });
var k$6 = compileProfileSchema({
	$ref: `https://schemas.kumwe.org/studio/v1/common.schema.json#/$defs/artifactReference`,
	$schema: `https://json-schema.org/draft/2020-12/schema`
}, { schemas: [commonSchema] });
var StudioHostSessionError = class extends Error {
	code;
	diagnostics;
	constructor(e, t, n = []) {
		super(t), this.name = `StudioHostSessionError`, this.code = e, this.diagnostics = cloneContractValue(n);
	}
};
async function openStudioSession(e, t) {
	let n = cloneContractValue(t.configuration), r = N$4(n, t.optionalPorts), i = negotiateCapabilities(n.hostCapabilities, {
		optionalPorts: r,
		requiredPorts: [p$9],
		supportedProtocolVersions: [n.protocolVersion]
	});
	n.sessionState === `read-only` && (i.sessionState = `read-only`), M$4(n, i);
	let a = ee(e, n, i), o = te(e, n, i), u = ne(e, n, i);
	if (i.diagnostics.some((e) => e.severity === `blocking`)) throw new StudioHostSessionError(`configuration-blocked`, `The resolved Studio configuration cannot open a Blueprint host session.`, i.diagnostics);
	let m = n.artifacts.blueprint;
	if (m === void 0) throw new StudioHostSessionError(`configuration-blocked`, `A Blueprint host session requires an explicit locked Blueprint reference.`, i.diagnostics);
	let h = new SessionIdentifierAllocator(t.identifiers), g = createContext(n, h.requestId(_$8), { operationId: _$8 }), v = await ie(() => e.artifact.load(m, g));
	if (!F$4(v, m.id)) throw new StudioHostSessionError(`unexpected-artifact`, `The host returned an artifact outside the Blueprint session profile.`, [createDiagnostic(`studio.host/unexpected-artifact`, `The artifact port did not return the configured Blueprint.`, `blocking`, { artifactId: m.id })]);
	let y = P$4(v.value, v.revision);
	assertBlueprintWithinSessionPolicy(y, n.limits);
	let b = new StudioSession({
		document: y,
		limits: n.limits,
		maximumHistoryEntries: n.limits.maxHistoryEntries,
		mode: resolveSessionMode(n),
		permissions: n.permissions,
		sessionGeneration: n.sessionGeneration
	});
	return b.markSaved(y.revision), new A$6(e, n, h, i, a, o, u, b, y.revision);
}
var A$6 = class {
	diagnostics;
	negotiation;
	models;
	recovery;
	resources;
	session;
	#adapter;
	#configuration;
	#identifiers;
	#retryIntents = /* @__PURE__ */ new Map();
	#disposed = !1;
	#invalidationFailure;
	#lastScheduledSave;
	#revision;
	#saveTail = Promise.resolve();
	constructor(e, t, n, r, i, a, o, c, l) {
		this.#adapter = e, this.#configuration = t, this.#identifiers = n, this.negotiation = ae(r), this.diagnostics = cloneContractValue(r.diagnostics), this.session = c, this.#revision = l, this.recovery = i ? Object.freeze({
			discard: () => this.#discardRecovery(),
			load: () => this.#loadRecovery(),
			store: (e) => this.#storeRecovery(e)
		}) : void 0, this.models = a ? Object.freeze({
			get: (e) => this.#getModel(e),
			list: () => this.#listModels()
		}) : void 0, this.resources = o ? Object.freeze({ search: (e) => this.#searchResources(e) }) : void 0;
	}
	get disposed() {
		return this.#disposed;
	}
	get invalidated() {
		return this.#invalidationFailure !== void 0;
	}
	get revision() {
		return this.#revision;
	}
	dispose() {
		this.#disposed || (this.#disposed = !0, this.#retryIntents.clear(), this.#identifiers.dispose());
	}
	save() {
		try {
			if (this.#assertActive(), this.session.mode === `read-only`) throw new StudioHostSessionError(`read-only-session`, `A read-only Studio host session cannot save.`);
			let e = this.session.document, t = canonicalStringify(e), n = this.#lastScheduledSave;
			if (n?.snapshotFingerprint === t) return n.promise;
			if (!this.session.dirty) return Promise.resolve({
				revision: this.#revision,
				value: null
			});
			let r = this.session.stateVersion, i = this.#saveTail.then(() => this.#saveSnapshot(e, r));
			this.#saveTail = i.then(() => void 0, () => void 0), this.#lastScheduledSave = {
				promise: i,
				snapshotFingerprint: t
			};
			let a = () => {
				this.#lastScheduledSave?.promise === i && (this.#lastScheduledSave = void 0);
			};
			return i.then(a, a), i;
		} catch (e) {
			return Promise.reject(e instanceof Error ? e : Error(`The Studio host session failed with a non-Error rejection.`));
		}
	}
	async #discardRecovery() {
		this.#assertActive();
		let e = this.#adapter.recovery;
		if (e === void 0) throw adapterContractFailure(`studio.host/adapter-port-unavailable`, `The negotiated recovery adapter is unavailable.`);
		let t = mutationFingerprint(null, this.#configuration), n = this.#mutationKey(C$8, t), r = createContext(this.#configuration, this.#identifiers.requestId(C$8), {
			idempotencyKey: n,
			operationId: C$8
		}), i = await this.#invoke(() => e.discard(r));
		return this.#clearMutationKey(C$8, t), i;
	}
	async #loadRecovery() {
		this.#assertActive();
		let e = this.#adapter.recovery;
		if (e === void 0) throw adapterContractFailure(`studio.host/adapter-port-unavailable`, `The negotiated recovery adapter is unavailable.`);
		let t = createContext(this.#configuration, this.#identifiers.requestId(S$8), { operationId: S$8 }), n = await this.#invoke(() => e.load(t));
		return {
			...n.revision === void 0 ? {} : { revision: n.revision },
			value: n.value === null ? null : cloneContractValue(n.value)
		};
	}
	async #getModel(e) {
		if (this.#assertActive(), !Y$1(e)) throw new StudioHostSessionError(`invalid-model-reference`, `A model read requires a canonical artifact identifier and semantic version.`);
		let t = this.#adapter.model;
		if (t === void 0) throw adapterContractFailure(`studio.host/adapter-port-unavailable`, `The negotiated model adapter is unavailable.`);
		let n = cloneContractValue(e), r = createContext(this.#configuration, this.#identifiers.requestId(y$8), { operationId: y$8 }), i = await this.#invoke(() => t.get(n, r));
		if (!I$4(i, n)) throw adapterContractFailure(`studio.host/unexpected-model-result`, `The model port returned a document outside the requested model coordinate.`);
		return {
			...i.revision === void 0 ? {} : { revision: i.revision },
			value: cloneContractValue(i.value)
		};
	}
	async #listModels() {
		this.#assertActive();
		let e = this.#adapter.model;
		if (e === void 0) throw adapterContractFailure(`studio.host/adapter-port-unavailable`, `The negotiated model adapter is unavailable.`);
		let t = createContext(this.#configuration, this.#identifiers.requestId(b$8), { operationId: b$8 }), n = await this.#invoke(() => e.list(t));
		if (!L$4(n)) throw adapterContractFailure(`studio.host/unexpected-model-result`, `The model port returned a malformed or duplicate model collection.`);
		return {
			...n.revision === void 0 ? {} : { revision: n.revision },
			value: cloneContractValue(n.value).sort(re)
		};
	}
	async #saveSnapshot(e, t) {
		this.#assertActive();
		let n = this.#revision, r = {
			...e,
			revision: n
		}, i = mutationFingerprint(r, this.#configuration, n), a = this.#mutationKey(v$8, i), o = createContext(this.#configuration, this.#identifiers.requestId(v$8), {
			expectedRevision: n,
			idempotencyKey: a,
			operationId: v$8
		}), s = await this.#invoke(() => this.#adapter.artifact.save(r, o));
		if (s.value !== null || !$(s.revision)) throw adapterContractFailure(`studio.host/missing-accepted-revision`, `The artifact save did not return its accepted revision.`);
		return this.#revision = s.revision, this.session.markSaved(s.revision, t), this.#clearMutationKey(v$8, i), {
			revision: s.revision,
			value: null
		};
	}
	async #searchResources(e) {
		if (this.#assertActive(), !R$4(e)) throw new StudioHostSessionError(`invalid-resource-query`, `A resource search requires a canonical resource type, bounded limit, cursor, and search text.`);
		let t = this.#adapter.resource;
		if (t === void 0) throw adapterContractFailure(`studio.host/adapter-port-unavailable`, `The negotiated resource adapter is unavailable.`);
		let n = cloneContractValue(e), r = createContext(this.#configuration, this.#identifiers.requestId(w$8), { operationId: w$8 }), i = await this.#invoke(() => t.search(n, r));
		if (!z$3(i, n)) throw adapterContractFailure(`studio.host/unexpected-resource-result`, `The resource port returned a malformed, mismatched, duplicate, or oversized search page.`);
		return {
			...i.revision === void 0 ? {} : { revision: i.revision },
			value: cloneContractValue(i.value)
		};
	}
	async #storeRecovery(e) {
		this.#assertActive();
		let t = this.#adapter.recovery;
		if (t === void 0) throw adapterContractFailure(`studio.host/adapter-port-unavailable`, `The negotiated recovery adapter is unavailable.`);
		let n = cloneContractValue(e), r = mutationFingerprint(n, this.#configuration), i = this.#mutationKey(x$8, r), a = createContext(this.#configuration, this.#identifiers.requestId(x$8), {
			idempotencyKey: i,
			operationId: x$8
		}), o = await this.#invoke(() => t.store(n, a));
		return this.#clearMutationKey(x$8, r), o;
	}
	#assertActive() {
		if (this.#invalidationFailure !== void 0) throw this.#invalidationFailure;
		if (this.#disposed) throw new StudioHostSessionError(`disposed`, `The Studio host session is disposed.`);
	}
	#clearMutationKey(e, t) {
		this.#retryIntents.get(e)?.fingerprint === t && this.#retryIntents.delete(e);
	}
	async #invoke(e) {
		this.#assertActive();
		try {
			return await e();
		} catch (e) {
			let t = normalizeHostRejection(e);
			throw isStaleGenerationFailure(t) && (this.#invalidationFailure = t), t;
		}
	}
	#mutationKey(e, t) {
		let n = this.#retryIntents.get(e);
		if (n?.fingerprint === t) return n.idempotencyKey;
		let r = this.#identifiers.idempotencyKey(e, t);
		return this.#retryIntents.set(e, {
			fingerprint: t,
			idempotencyKey: r
		}), r;
	}
};
var SessionIdentifierAllocator = class {
	#factories;
	#idempotencyIntents = /* @__PURE__ */ new Map();
	#requestIds = /* @__PURE__ */ new Set();
	constructor(e) {
		this.#factories = e;
	}
	dispose() {
		this.#idempotencyIntents.clear(), this.#requestIds.clear();
	}
	idempotencyKey(e, t) {
		let n = j$6((e) => this.#factories.idempotencyKey(e), e, `idempotency`), r = `${e}\u0000${n}`, i = this.#idempotencyIntents.get(r);
		if (i !== void 0 && i !== t) throw new StudioHostSessionError(`invalid-identifier`, `The idempotency-key factory reused a key for another mutation intent.`);
		return this.#idempotencyIntents.set(r, t), n;
	}
	requestId(e) {
		let t = j$6((e) => this.#factories.requestId(e), e, `request`);
		if (this.#requestIds.has(t)) throw new StudioHostSessionError(`invalid-identifier`, `The request-ID factory returned an identifier already used by this session.`);
		return this.#requestIds.add(t), t;
	}
};
function j$6(e, t, n) {
	let r;
	try {
		r = e(t);
	} catch {
		throw new StudioHostSessionError(`invalid-identifier`, `The ${n}-ID factory failed to allocate an identifier.`);
	}
	if (!Q(r)) throw new StudioHostSessionError(`invalid-identifier`, `The ${n}-ID factory returned a non-canonical stable identifier.`);
	return r;
}
function ee(e, t, n) {
	let r = t.hostCapabilities.ports.find((e) => e.id === p$9);
	if (r !== void 0) {
		let e = [_$8];
		t.sessionState === `editable` && e.push(v$8);
		for (let t of e) r.operations.includes(t) || n.diagnostics.push(createDiagnostic(`studio.host/missing-required-operation`, `The host does not advertise the required ${t} operation.`, `blocking`, { operationId: t }));
	}
	if (!t.features.offlineRecovery) return !1;
	let i = t.hostCapabilities.ports.find((e) => e.id === h$8);
	if (i === void 0) return !1;
	let a = !0;
	for (let e of [
		x$8,
		S$8,
		C$8
	]) i.operations.includes(e) || (a = !1, n.diagnostics.push(createDiagnostic(`studio.host/missing-optional-operation`, `The optional recovery port omits ${e}; recovery is disabled.`, `information`, { operationId: e })));
	return e.recovery === void 0 && (a = !1, n.diagnostics.push(createDiagnostic(`studio.host/adapter-port-unavailable`, `The capability document advertises recovery but the adapter does not implement it.`, `information`, { port: h$8 }))), a;
}
function te(e, t, n) {
	let r = t.hostCapabilities.ports.find((e) => e.id === m$9);
	if (r === void 0) return !1;
	let i = !0;
	for (let e of [b$8, y$8]) r.operations.includes(e) || (i = !1, n.diagnostics.push(createDiagnostic(`studio.host/missing-optional-operation`, `The model port omits ${e}; model binding is disabled.`, `information`, { operationId: e })));
	return e.model === void 0 && (i = !1, n.diagnostics.push(createDiagnostic(`studio.host/adapter-port-unavailable`, `The capability document advertises model reads but the adapter does not implement them.`, `information`, { port: m$9 }))), i;
}
function ne(e, t, n) {
	let r = t.hostCapabilities.ports.find((e) => e.id === g$8);
	if (r === void 0) return !1;
	let i = !0;
	return r.operations.includes(w$8) || (i = !1, n.diagnostics.push(createDiagnostic(`studio.host/missing-optional-operation`, `The resource port omits ${w$8}; resource discovery is disabled.`, `information`, { operationId: w$8 }))), e.resource === void 0 && (i = !1, n.diagnostics.push(createDiagnostic(`studio.host/adapter-port-unavailable`, `The capability document advertises resource discovery but the adapter does not implement it.`, `information`, { port: g$8 }))), i;
}
function M$4(e, t) {
	e.artifacts.blueprint === void 0 && t.diagnostics.push(createDiagnostic(`studio.host/missing-blueprint-artifact`, `A Blueprint session requires a locked Blueprint artifact reference.`, `blocking`)), (e.mode !== `blueprint` || e.composite !== `single`) && t.diagnostics.push(createDiagnostic(`studio.host/unsupported-session-profile`, `This host-session profile opens only single Blueprint configurations.`, `blocking`, {
		composite: e.composite,
		mode: e.mode
	}));
}
function N$4(e, t) {
	let n = new Set(t ?? []);
	return n.delete(p$9), e.features.offlineRecovery && n.add(h$8), e.hostCapabilities.ports.some((e) => e.id === m$9) && n.add(m$9), e.hostCapabilities.ports.some((e) => e.id === g$8) && n.add(g$8), [...n];
}
function createContext(e, t, n) {
	return {
		...n.expectedRevision === void 0 ? {} : { expectedRevision: n.expectedRevision },
		...n.idempotencyKey === void 0 ? {} : { idempotencyKey: n.idempotencyKey },
		locale: e.locale.resolved,
		operationId: n.operationId,
		protocolVersion: e.protocolVersion,
		requestId: t,
		resourceContextKey: e.resourceContext.key,
		sessionGeneration: e.sessionGeneration
	};
}
function createDiagnostic(e, t, n, r) {
	return {
		code: e,
		message: {
			defaultMessage: t,
			key: e
		},
		...r === void 0 ? {} : { parameters: r },
		severity: n
	};
}
function P$4(e, t) {
	let n = t ?? e.revision;
	if (!$(n)) throw new StudioHostSessionError(`unexpected-artifact`, `The loaded Blueprint does not carry a valid accepted revision.`, [createDiagnostic(`studio.host/missing-accepted-revision`, `The loaded Blueprint does not carry a valid accepted revision.`, `blocking`)]);
	return cloneContractValue({
		...e,
		revision: n
	});
}
function F$4(e, t) {
	if (typeof e != `object` || !e || Array.isArray(e) || !(`value` in e)) return !1;
	let n = e.value;
	return typeof n == `object` && !!n && !Array.isArray(n) && `kind` in n && n.kind === `blueprint` && `id` in n && n.id === t;
}
function I$4(e, t) {
	if (!q$1(e) || !J$1(e.value)) return !1;
	let n = X$1(t);
	return e.value.id === t.id && e.value.version === t.version && (n === void 0 || e.value.revision === n) && (e.revision === void 0 || e.revision === e.value.revision);
}
function L$4(e) {
	if (!q$1(e) || !Array.isArray(e.value)) return !1;
	let t = /* @__PURE__ */ new Set();
	for (let n of e.value) {
		if (!J$1(n)) return !1;
		let e = `${n.id}\u0000${n.version}\u0000${n.revision}`;
		if (t.has(e)) return !1;
		t.add(e);
	}
	return !0;
}
function R$4(e) {
	return !G$3(e) || !K$1(e, [`limit`, `resourceType`], [`cursor`, `search`]) ? !1 : typeof e.limit == `number` && Number.isSafeInteger(e.limit) && e.limit >= STUDIO_RESOURCE_SEARCH_LIMITS.minimumLimit && e.limit <= STUDIO_RESOURCE_SEARCH_LIMITS.maximumLimit && U$3(e.resourceType) && W$3(e.cursor, STUDIO_RESOURCE_SEARCH_LIMITS.maximumCursorLength, !1) && W$3(e.search, STUDIO_RESOURCE_SEARCH_LIMITS.maximumSearchLength, !0);
}
function z$3(e, t) {
	return !(!q$1(e) || !B$4(e.value, t));
}
function B$4(e, t) {
	if (!G$3(e) || !K$1(e, [`items`], [`nextCursor`]) || !Array.isArray(e.items) || e.items.length > t.limit || !W$3(e.nextCursor, STUDIO_RESOURCE_SEARCH_LIMITS.maximumCursorLength, !1)) return !1;
	let n = /* @__PURE__ */ new Set();
	for (let r of e.items) {
		if (!V$3(r, t.resourceType) || n.has(r.id)) return !1;
		n.add(r.id);
	}
	return !0;
}
function V$3(e, t) {
	return G$3(e) && K$1(e, [
		`id`,
		`label`,
		`resourceType`
	]) && Q(e.id) && e.resourceType === t && H$3(e.label);
}
function H$3(e) {
	return G$3(e) && K$1(e, [`key`], [`defaultMessage`]) && U$3(e.key) && (e.defaultMessage === void 0 || typeof e.defaultMessage == `string` && e.defaultMessage.length >= 1 && e.defaultMessage.length <= 500);
}
function U$3(e) {
	return typeof e == `string` && e.length <= 160 && E$8.test(e);
}
function W$3(e, t, n) {
	return e === void 0 || typeof e == `string` && e.length <= t && (n || e.length >= 1);
}
function G$3(e) {
	if (typeof e != `object` || !e || Array.isArray(e)) return !1;
	let t = Object.getPrototypeOf(e);
	return t === Object.prototype || t === null;
}
function K$1(e, t, n = []) {
	let r = /* @__PURE__ */ new Set([...t, ...n]);
	return t.every((t) => Object.hasOwn(e, t)) && Object.keys(e).every((e) => r.has(e));
}
function q$1(e) {
	return typeof e != `object` || !e || Array.isArray(e) || !(`value` in e) ? !1 : !(`revision` in e) || $(e.revision);
}
function J$1(e) {
	return O$7.validate(e);
}
function Y$1(e) {
	return k$6.validate(e);
}
function X$1(e) {
	let t = e;
	return $(t.revision) ? t.revision : void 0;
}
function re(e, t) {
	return Z$1(e.id, t.id) || Z$1(e.version, t.version) || Z$1(e.revision, t.revision);
}
function Z$1(e, t) {
	return e < t ? -1 : +(e > t);
}
function mutationFingerprint(e, t, n) {
	return canonicalStringify({
		argument: e,
		context: {
			...n === void 0 ? {} : { expectedRevision: n },
			locale: t.locale.resolved,
			protocolVersion: t.protocolVersion
		}
	});
}
async function ie(e) {
	try {
		return await e();
	} catch (e) {
		throw normalizeHostRejection(e);
	}
}
function normalizeHostRejection(e) {
	return isHostPortFailure(e) ? e : adapterContractFailure(`studio.host/invalid-failure-wrapper`, `The host adapter rejected without a canonical HostPortFailure.`);
}
function adapterContractFailure(n, r) {
	return new HostPortFailure({
		category: `internal`,
		contractVersion: STUDIO_CONTRACT_VERSION,
		diagnostics: [createDiagnostic(n, r, `error`)],
		kind: `host-error`,
		message: {
			defaultMessage: r,
			key: n
		},
		retryable: !1
	});
}
function isStaleGenerationFailure(e) {
	return e.error.category === `invalid-request` && (e.error.diagnostics?.some((e) => e.code === STUDIO_STALE_SESSION_GENERATION_DIAGNOSTIC_CODE) ?? !1);
}
function ae(e) {
	return {
		availablePorts: [...e.availablePorts],
		diagnostics: cloneContractValue(e.diagnostics),
		missingOptionalPorts: [...e.missingOptionalPorts],
		missingRequiredPorts: [...e.missingRequiredPorts],
		...e.protocolVersion === void 0 ? {} : { protocolVersion: e.protocolVersion },
		sessionState: e.sessionState
	};
}
function Q(e) {
	return typeof e == `string` && e.length >= 1 && e.length <= 240 && !D$6.has(e) && T$8.test(e);
}
function $(e) {
	return typeof e == `string` && e.length >= 1 && e.length <= 200;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/schema-profile.js
var n$5 = 1e4;
var r$7 = 1024;
var i$9 = 1e4;
var a$9 = 1e3;
var o$6 = 262144;
var s$7 = 1024;
var c$5 = 1e3;
Object.freeze({
	maxAlternatives: 64,
	maxDescriptionLength: n$5,
	maxEnumMembers: r$7,
	maxExamples: 100,
	maxJsonDepth: 64,
	maxJsonItems: i$9,
	maxJsonProperties: a$9,
	maxObjectKeyLength: 200,
	maxPropertyNames: 512,
	maxReferenceLength: 500,
	maxReferences: 128,
	maxSchemaBytes: o$6,
	maxSchemaDepth: 32,
	maxSchemaMapProperties: 512,
	maxSchemaNodes: s$7,
	maxTitleLength: c$5
});
Object.freeze([
	`invalid-root`,
	`unsupported-keyword`,
	`invalid-keyword-value`,
	`unsafe-member`,
	`limit-exceeded`,
	`invalid-reference`,
	`recursive-schema`
]);
var StudioSchemaProfileError = class extends TypeError {
	code;
	schemaPath;
	constructor(e, t, n, r) {
		super(n, r), this.name = `StudioSchemaProfileError`, this.code = e, this.schemaPath = t;
	}
};
var d$9 = new Set(`$defs.$ref.$schema.additionalProperties.allOf.anyOf.const.default.dependentRequired.description.else.enum.examples.exclusiveMaximum.exclusiveMinimum.if.items.maxItems.maxLength.maxProperties.maximum.minItems.minLength.minProperties.minimum.multipleOf.not.oneOf.prefixItems.properties.propertyNames.readOnly.required.then.title.type.uniqueItems.writeOnly`.split(`.`));
var f$10 = /* @__PURE__ */ new Set([
	`array`,
	`boolean`,
	`integer`,
	`null`,
	`number`,
	`object`,
	`string`
]);
var p$8 = class extends RangeError {};
var m$8 = class extends TypeError {};
function compileStudioPropertySchema(e) {
	Z(e) || z$2(`invalid-root`, ``, `Studio property schema root must be a JSON Schema object.`);
	try {
		G$2(e);
	} catch (e) {
		e instanceof p$8 && z$2(`limit-exceeded`, ``, `Studio property schema exceeds ${o$6} canonical UTF-8 bytes.`), e instanceof m$8 || z$2(`invalid-root`, ``, `Studio property schema must be a bounded canonical JSON document.`, e);
	}
	let n = {
		references: 0,
		schemaNodes: 0,
		seen: /* @__PURE__ */ new WeakSet()
	}, r = [];
	j$5(() => h$7(e, ``, 1, n), r), j$5(() => I$3(e), r), j$5(() => A$5(e), r);
	let i = M$3(e, r);
	if (i !== void 0) throw i;
	try {
		return compileProfileSchema(e);
	} catch (e) {
		z$2(`invalid-keyword-value`, ``, `Studio property schema does not compile under the strict profile.`, e);
	}
}
function assertStudioPropertySchema(e) {
	compileStudioPropertySchema(e);
}
function h$7(e, t, r, i) {
	Z(e) || z$2(`invalid-keyword-value`, t, `${V$2(t)} must be a JSON Schema object.`), X(e, t, i), y$7(t, r, i);
	for (let [a, o] of q(e)) {
		let e = B$3(t, a);
		switch (F$3(a, t), d$9.has(a) || z$2(`unsupported-keyword`, e, `${V$2(e)} uses keyword ${JSON.stringify(a)}, which is not allowed by the Studio Schema Profile.`), a) {
			case `$defs`:
			case `properties`:
				g$7(o, e, r + 1, i);
				break;
			case `additionalProperties`:
			case `else`:
			case `if`:
			case `items`:
			case `not`:
			case `propertyNames`:
			case `then`:
				v$7(o, e, r + 1, i);
				break;
			case `allOf`:
			case `anyOf`:
			case `oneOf`:
			case `prefixItems`:
				_$7(o, e, r + 1, i);
				break;
			case `$ref`:
				b$7(o, e, i);
				break;
			case `$schema`:
				o !== `https://json-schema.org/draft/2020-12/schema` && z$2(`invalid-keyword-value`, e, `${V$2(e)} must declare JSON Schema Draft 2020-12.`);
				break;
			case `enum`:
				x$7(o, e, 1, i);
				break;
			case `examples`:
				S$7(o, e, 1, i);
				break;
			case `dependentRequired`:
				C$7(o, e, i);
				break;
			case `required`:
				w$7(o, e, 512, i);
				break;
			case `type`:
				T$7(o, e, i);
				break;
			case `description`:
				E$7(o, e, n$5);
				break;
			case `title`:
				E$7(o, e, c$5);
				break;
			case `maxItems`:
			case `maxLength`:
			case `maxProperties`:
			case `minItems`:
			case `minLength`:
			case `minProperties`:
				D$5(o, e);
				break;
			case `exclusiveMaximum`:
			case `exclusiveMinimum`:
			case `maximum`:
			case `minimum`:
				O$6(o, e);
				break;
			case `multipleOf`:
				O$6(o, e), o <= 0 && z$2(`invalid-keyword-value`, e, `${V$2(e)} must be greater than zero.`);
				break;
			case `readOnly`:
			case `uniqueItems`:
			case `writeOnly`:
				typeof o != `boolean` && z$2(`invalid-keyword-value`, e, `${V$2(e)} must be a boolean.`);
				break;
			case `const`:
			case `default`: k$5(o, e, 1, i);
		}
	}
}
function g$7(e, t, n, r) {
	Z(e) || z$2(`invalid-keyword-value`, t, `${V$2(t)} must be an object of schemas.`), X(e, t, r);
	let i = Object.keys(e);
	i.length > 512 && z$2(`limit-exceeded`, t, `${V$2(t)} exceeds 512 schema entries.`);
	for (let a of i.sort(Y)) F$3(a, t), h$7(e[a], B$3(t, a), n, r);
}
function _$7(e, t, n, r) {
	(!Array.isArray(e) || !J(e)) && z$2(`invalid-keyword-value`, t, `${V$2(t)} must be a dense JSON array of schemas.`), e.length === 0 && z$2(`invalid-keyword-value`, t, `${V$2(t)} must contain at least one schema.`), e.length > 64 && z$2(`limit-exceeded`, t, `${V$2(t)} must contain at most 64 schemas.`), X(e, t, r);
	for (let [i, a] of e.entries()) v$7(a, B$3(t, String(i)), n, r);
}
function v$7(e, t, n, r) {
	if (typeof e == `boolean`) {
		y$7(t, n, r);
		return;
	}
	h$7(e, t, n, r);
}
function y$7(e, t, n) {
	t > 32 && z$2(`limit-exceeded`, e, `${V$2(e)} exceeds the Studio Schema Profile depth limit.`), n.schemaNodes += 1, n.schemaNodes > s$7 && z$2(`limit-exceeded`, e, `Studio property schema exceeds ${s$7} schema nodes.`);
}
function b$7(e, t, n) {
	U$2(e) || z$2(`invalid-reference`, t, `${V$2(t)} must be a bounded local JSON Pointer reference.`), n.references += 1, n.references > 128 && z$2(`limit-exceeded`, t, `Studio property schema exceeds 128 references.`);
}
function x$7(t, n, i, a) {
	(!Array.isArray(t) || !J(t)) && z$2(`invalid-keyword-value`, n, `${V$2(n)} must be a dense JSON array.`), t.length === 0 && z$2(`invalid-keyword-value`, n, `${V$2(n)} must contain at least one value.`), t.length > r$7 && z$2(`limit-exceeded`, n, `${V$2(n)} exceeds ${r$7} members.`), X(t, n, a);
	let o = /* @__PURE__ */ new Set();
	for (let [r, s] of t.entries()) {
		k$5(s, B$3(n, String(r)), i, a);
		let t = canonicalStringify(s, { maximumDepth: 65 });
		o.has(t) && z$2(`invalid-keyword-value`, B$3(n, String(r)), `${V$2(n)} must contain unique JSON values.`), o.add(t);
	}
}
function S$7(e, t, n, r) {
	(!Array.isArray(e) || !J(e)) && z$2(`invalid-keyword-value`, t, `${V$2(t)} must be a dense JSON array.`), e.length > 100 && z$2(`limit-exceeded`, t, `${V$2(t)} exceeds 100 examples.`), X(e, t, r);
	for (let [i, a] of e.entries()) k$5(a, B$3(t, String(i)), n, r);
}
function C$7(e, t, n) {
	Z(e) || z$2(`invalid-keyword-value`, t, `${V$2(t)} must be an object of property-name arrays.`), X(e, t, n);
	let r = Object.keys(e);
	r.length > 512 && z$2(`limit-exceeded`, t, `${V$2(t)} exceeds 512 dependency entries.`);
	for (let i of r.sort(Y)) F$3(i, t), w$7(e[i], B$3(t, i), 512, n);
}
function w$7(e, t, n, r) {
	(!Array.isArray(e) || !J(e)) && z$2(`invalid-keyword-value`, t, `${V$2(t)} must be a dense array of property names.`), e.length > n && z$2(`limit-exceeded`, t, `${V$2(t)} exceeds ${n} property names.`), X(e, t, r);
	let i = /* @__PURE__ */ new Set();
	for (let [n, r] of e.entries()) typeof r != `string` && z$2(`invalid-keyword-value`, B$3(t, String(n)), `${V$2(t)} must contain only property-name strings.`), F$3(r, t, B$3(t, String(n))), i.has(r) && z$2(`invalid-keyword-value`, B$3(t, String(n)), `${V$2(t)} must list unique property names.`), i.add(r);
}
function T$7(e, t, n) {
	if (typeof e == `string`) {
		f$10.has(e) || z$2(`invalid-keyword-value`, t, `${V$2(t)} names an unknown JSON Schema type.`);
		return;
	}
	(!Array.isArray(e) || !J(e) || e.length === 0 || e.length > 7) && z$2(`invalid-keyword-value`, t, `${V$2(t)} must be a type name or a non-empty array of at most seven names.`), X(e, t, n);
	let r = /* @__PURE__ */ new Set();
	for (let [n, i] of e.entries()) (typeof i != `string` || !f$10.has(i) || r.has(i)) && z$2(`invalid-keyword-value`, B$3(t, String(n)), `${V$2(t)} must list unique, known JSON Schema type names.`), r.add(i);
}
function E$7(e, t, n) {
	typeof e != `string` && z$2(`invalid-keyword-value`, t, `${V$2(t)} must be a string.`), W$2(e) > n && z$2(`limit-exceeded`, t, `${V$2(t)} exceeds ${n} characters.`);
}
function D$5(e, t) {
	(typeof e != `number` || !Number.isInteger(e) || e < 0) && z$2(`invalid-keyword-value`, t, `${V$2(t)} must be a non-negative integer.`);
}
function O$6(e, t) {
	(typeof e != `number` || !Number.isFinite(e)) && z$2(`invalid-keyword-value`, t, `${V$2(t)} must be a finite number.`);
}
function k$5(e, t, n, r) {
	if (!(e === null || typeof e == `boolean` || typeof e == `string` || typeof e == `number` && Number.isFinite(e))) {
		if (n > 64 && z$2(`limit-exceeded`, t, `${V$2(t)} exceeds the Studio Schema Profile JSON depth limit.`), Array.isArray(e)) {
			J(e) || z$2(`invalid-keyword-value`, t, `${V$2(t)} must be a dense JSON array.`), X(e, t, r), e.length > i$9 && z$2(`limit-exceeded`, t, `${V$2(t)} exceeds ${i$9} JSON items.`);
			for (let [i, a] of e.entries()) k$5(a, B$3(t, String(i)), n + 1, r);
			return;
		}
		if (Z(e)) {
			X(e, t, r);
			let i = Object.keys(e);
			i.length > a$9 && z$2(`limit-exceeded`, t, `${V$2(t)} exceeds ${a$9} JSON properties.`);
			for (let a of i.sort(Y)) F$3(a, t), k$5(e[a], B$3(t, a), n + 1, r);
			return;
		}
		z$2(`invalid-keyword-value`, t, `${V$2(t)} is not JSON-compatible.`);
	}
}
function A$5(e) {
	e.additionalProperties !== !1 && z$2(`invalid-root`, `/additionalProperties`, `Studio property schema root must declare additionalProperties: false.`), e.type !== `object` && z$2(`invalid-root`, `/type`, `Studio property schema root must declare exactly type "object".`);
}
function j$5(e, t) {
	try {
		e();
	} catch (e) {
		if (e instanceof StudioSchemaProfileError) {
			t.push(e);
			return;
		}
		throw e;
	}
}
function M$3(e, t) {
	let n;
	for (let r of t) (n === void 0 || N$3(e, r.schemaPath, n.schemaPath) < 0) && (n = r);
	return n;
}
function N$3(e, t, n) {
	let r = P$3(t), i = P$3(n), a = e, o = Math.min(r.length, i.length);
	for (let e = 0; e < o; e += 1) {
		let t = r[e], n = i[e];
		if (t === void 0 || n === void 0) break;
		if (t !== n) {
			if (Array.isArray(a)) {
				let e = Number(t), r = Number(n);
				if (Number.isSafeInteger(e) && Number.isSafeInteger(r)) return e - r;
			}
			return Y(t, n);
		}
		a = (Z(a) || Array.isArray(a)) && Object.hasOwn(a, t) ? a[t] : void 0;
	}
	return r.length - i.length;
}
function P$3(e) {
	return e === `` ? [] : e.slice(1).split(`/`).map((e) => e.replaceAll(`~1`, `/`).replaceAll(`~0`, `~`));
}
function F$3(e, t, n = B$3(t, e)) {
	W$2(e) > 200 && z$2(`limit-exceeded`, n, `${V$2(t)} contains an object member name longer than 200 characters.`), (e.length === 0 || e === `__proto__` || e === `constructor` || e === `prototype` || H$2(e)) && z$2(`unsafe-member`, n, `${V$2(t)} contains forbidden object member name ${JSON.stringify(e)}.`);
}
function I$3(e) {
	let t = [], n = /* @__PURE__ */ new Map(), r = [], i = [], a = [], o = /* @__PURE__ */ new WeakSet(), s = 0, c = (e, t) => ({
		parent: e,
		token: t
	}), l = (e) => {
		let t = [], n = e;
		for (; n !== void 0;) t.push(n.token), n = n.parent;
		let r = ``;
		for (let e = t.length - 1; e >= 0; --e) {
			let n = t[e];
			n !== void 0 && (r = B$3(r, n));
		}
		return r;
	}, u = (e) => {
		let t = n.get(e);
		if (t !== void 0) return t;
		let a = r.length;
		return n.set(e, a), r.push([]), i.push([]), a;
	}, d = (e, t) => {
		r[e]?.push(t), i[t]?.push(e);
	};
	u(e);
	let f = [{
		depth: 1,
		diagnosticsEligible: !0,
		node: e,
		path: void 0
	}];
	for (; f.length > 0;) {
		let n = f.pop();
		if (n === void 0 || o.has(n.node)) continue;
		o.add(n.node);
		let r = u(n.node), i = [], p = (e, t, a = n.diagnosticsEligible) => {
			if (!Z(e)) return;
			let o = u(e);
			d(r, o);
			let s = n.depth + 1;
			i.push({
				depth: s,
				diagnosticsEligible: a && s <= 32,
				node: e,
				path: t
			});
		};
		for (let [i, o] of q(n.node)) {
			let f = c(n.path, i);
			switch (i) {
				case `$defs`:
				case `properties`:
					if (Z(o)) {
						let e = Object.keys(o), t = e.length <= 512;
						t && e.sort(Y);
						for (let r of e) p(o[r], c(f, r), n.diagnosticsEligible && t);
					}
					break;
				case `$ref`:
					if (U$2(o)) {
						let i = n.diagnosticsEligible && (s += 1) <= 128, c = i ? l(f) : ``;
						try {
							let n = R$3(e, o, c);
							if (!n.schemaPosition) i && t.push(new StudioSchemaProfileError(`invalid-reference`, c, `Local schema reference ${o} does not resolve to a schema position.`));
							else if (Z(n.value)) {
								let e = u(n.value);
								d(r, e), i && a.push({
									path: c,
									source: r,
									target: e
								});
							}
						} catch (e) {
							if (e instanceof StudioSchemaProfileError) i && t.push(e);
							else throw e;
						}
					}
					break;
				case `additionalProperties`:
				case `else`:
				case `if`:
				case `items`:
				case `not`:
				case `propertyNames`:
				case `then`:
					p(o, f);
					break;
				case `allOf`:
				case `anyOf`:
				case `oneOf`:
				case `prefixItems`: if (Array.isArray(o)) {
					let e = o.length > 0 && o.length <= 64 && J(o);
					for (let t = 0; t < o.length; t += 1) Object.hasOwn(o, t) && p(o[t], c(f, String(t)), n.diagnosticsEligible && e);
				}
			}
		}
		for (let e = i.length - 1; e >= 0; --e) {
			let t = i[e];
			t !== void 0 && f.push(t);
		}
	}
	let p = L$3(r, i);
	for (let e of a) p[e.source] === p[e.target] && t.push(new StudioSchemaProfileError(`recursive-schema`, e.path, `Recursive contributed schemas are not admitted by the alpha profile.`));
	let m = M$3(e, t);
	if (m !== void 0) throw m;
}
function L$3(e, t) {
	let n = new Uint8Array(e.length), r = [];
	for (let t = 0; t < e.length; t += 1) {
		if (n[t] !== 0) continue;
		n[t] = 1;
		let i = [{
			edge: 0,
			node: t
		}];
		for (; i.length > 0;) {
			let t = i[i.length - 1];
			if (t === void 0) break;
			let a = (e[t.node] ?? [])[t.edge];
			a === void 0 ? (r.push(t.node), i.pop()) : (t.edge += 1, n[a] === 0 && (n[a] = 1, i.push({
				edge: 0,
				node: a
			})));
		}
	}
	let i = new Int32Array(e.length);
	i.fill(-1);
	let a = 0;
	for (let e = r.length - 1; e >= 0; --e) {
		let n = r[e];
		if (n === void 0 || i[n] !== -1) continue;
		i[n] = a;
		let o = [n];
		for (; o.length > 0;) {
			let e = o.pop();
			if (e !== void 0) for (let n of t[e] ?? []) i[n] === -1 && (i[n] = a, o.push(n));
		}
		a += 1;
	}
	return i;
}
function R$3(e, t, n) {
	if (t === `#`) return {
		schemaPosition: !0,
		value: e
	};
	let r = e, i = `schema`;
	for (let e of t.slice(2).split(`/`)) {
		let a = e.replaceAll(`~1`, `/`).replaceAll(`~0`, `~`), o = `other`;
		if (i === `schema` && Z(r)) switch (a) {
			case `$defs`:
			case `properties`:
				o = `schema-map`;
				break;
			case `additionalProperties`:
			case `else`:
			case `if`:
			case `items`:
			case `not`:
			case `propertyNames`:
			case `then`:
				o = `schema`;
				break;
			case `allOf`:
			case `anyOf`:
			case `oneOf`:
			case `prefixItems`: o = `schema-array`;
		}
		else (i === `schema-map` && Z(r) || i === `schema-array` && Array.isArray(r)) && (o = `schema`);
		!Z(r) && !Array.isArray(r) && z$2(`invalid-reference`, n, `Local schema reference ${t} does not resolve to a schema.`), Object.hasOwn(r, a) || z$2(`invalid-reference`, n, `Local schema reference ${t} does not resolve to a schema.`), r = r[a], i = o;
	}
	return typeof r != `boolean` && !Z(r) && z$2(`invalid-reference`, n, `Local schema reference ${t} does not resolve to a schema.`), {
		schemaPosition: i === `schema`,
		value: r
	};
}
function z$2(e, t, n, r) {
	throw new StudioSchemaProfileError(e, t, n, r === void 0 ? void 0 : { cause: r });
}
function B$3(e, t) {
	return `${e}/${t.replaceAll(`~`, `~0`).replaceAll(`/`, `~1`)}`;
}
function V$2(e) {
	return e === `` ? `schema root` : e;
}
function H$2(e) {
	for (let t = 0; t < e.length; t += 1) {
		let n = e.charCodeAt(t);
		if (n <= 31 || n === 127) return !0;
	}
	return !1;
}
function U$2(e) {
	return typeof e == `string` && W$2(e) <= 500 && !H$2(e) && /^#(?:\/(?:[A-Za-z0-9._!$&'()*+,;=:@-]|~[01])*)*$/u.test(e);
}
function W$2(e) {
	let t = 0;
	for (let n = 0; n < e.length; n += 1) {
		t += 1;
		let r = e.charCodeAt(n);
		r >= 55296 && r <= 56319 && n + 1 < e.length && (e.charCodeAt(n + 1) & 64512) == 56320 && (n += 1);
	}
	return t;
}
function G$2(e) {
	let t = [e], n = /* @__PURE__ */ new WeakSet(), r = 0, i = (e) => {
		if (r += e, r > o$6) throw new p$8();
	};
	for (; t.length > 0;) {
		let e = t.pop();
		if (e === null) {
			i(4);
			continue;
		}
		switch (typeof e) {
			case `boolean`:
				i(e ? 4 : 5);
				continue;
			case `number`:
				if (!Number.isFinite(e)) throw new m$8();
				i(JSON.stringify(Object.is(e, -0) ? 0 : e).length);
				continue;
			case `string`:
				K(e, i);
				continue;
			case `object`: break;
			default: throw new m$8();
		}
		if (n.has(e)) throw new m$8();
		if (n.add(e), Array.isArray(e)) {
			let n = e;
			if (i(2 + Math.max(0, n.length - 1)), !J(n)) throw new m$8();
			for (let e = n.length - 1; e >= 0; --e) {
				let r = n[e];
				if (r === void 0) throw new m$8();
				t.push(r);
			}
			continue;
		}
		if (!Z(e)) throw new m$8();
		let r = Object.keys(e);
		i(2 + Math.max(0, r.length - 1));
		for (let n = r.length - 1; n >= 0; --n) {
			let a = r[n];
			if (a === void 0) continue;
			let o = e[a];
			if (o === void 0) throw new m$8();
			K(a, i), i(1), t.push(o);
		}
	}
}
function K(e, t) {
	t(2);
	for (let n = 0; n < e.length; n += 1) {
		let r = e.charCodeAt(n);
		if (r === 34 || r === 92 || r === 8 || r === 9 || r === 10 || r === 12 || r === 13) t(2);
		else if (r <= 31) t(6);
		else if (r <= 127) t(1);
		else if (r <= 2047) t(2);
		else if (r >= 55296 && r <= 56319) {
			let r = e.charCodeAt(n + 1);
			n + 1 < e.length && (r & 64512) == 56320 ? (t(4), n += 1) : t(6);
		} else t(r >= 56320 && r <= 57343 ? 6 : 3);
	}
}
function q(e) {
	let t = Object.keys(e);
	if (t.length <= d$9.size) return t.sort(Y).map((t) => [t, e[t]]);
	let n = [], r;
	for (let e of t) d$9.has(e) ? n.push(e) : (r === void 0 || Y(e, r) < 0) && (r = e);
	return r !== void 0 && n.push(r), n.sort(Y).map((t) => [t, e[t]]);
}
function J(e) {
	let t = Object.keys(e);
	return t.length === e.length && t.every((e, t) => e === String(t));
}
function Y(e, t) {
	return e < t ? -1 : +(e > t);
}
function X(e, t, n) {
	n.seen.has(e) && z$2(`invalid-root`, t, `${V$2(t)} reuses or cycles a JSON object.`), n.seen.add(e);
}
function Z(e) {
	if (typeof e != `object` || !e || Array.isArray(e)) return !1;
	let t = Object.getPrototypeOf(e);
	return t === Object.prototype || t === null;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/registry.js
var BlockRegistry = class {
	#definitions = /* @__PURE__ */ new Map();
	constructor(e = []) {
		for (let t of e) this.register(t);
	}
	register(n, i = {}) {
		if (assertStudioPropertySchema(n.propertySchema), i.verifiedIntegrity !== void 0 && !r$6(i.verifiedIntegrity)) throw TypeError(`Host-verified block integrity must be a canonical SRI sha256/384/512 value.`);
		let a = this.#definitions.get(n.type);
		if (a === void 0 && (a = /* @__PURE__ */ new Map(), this.#definitions.set(n.type, a)), a.has(n.version)) throw Error(`Block ${n.type}@${n.version} is already registered.`);
		let o = { definition: cloneContractValue(n) };
		i.verifiedIntegrity !== void 0 && (o.verifiedIntegrity = i.verifiedIntegrity), a.set(n.version, o);
	}
	resolve(e, t) {
		return this.resolveRegistration(e, t)?.definition;
	}
	resolveRegistration(t, n) {
		let r = this.#definitions.get(t)?.get(n);
		if (r === void 0) return;
		let i = { definition: cloneContractValue(r.definition) };
		return r.verifiedIntegrity !== void 0 && (i.verifiedIntegrity = r.verifiedIntegrity), i;
	}
	definitions() {
		return [...this.#definitions.values()].flatMap((e) => [...e.values()]).map((t) => cloneContractValue(t.definition));
	}
};
function r$6(e) {
	return /^(?:sha256-[A-Za-z0-9+/]{42}[AEIMQUYcgkosw048]=|sha384-[A-Za-z0-9+/]{64}|sha512-[A-Za-z0-9+/]{85}[AQgw]==)(?![\s\S])/u.test(e);
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/semver.js
var e$4 = /^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-((?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/u;
function parseSemanticVersion(t) {
	let n = t.length <= 100 ? e$4.exec(t) : null;
	if (n === null) throw TypeError(`${t} is not a canonical semantic version.`);
	return {
		major: Number(n[1]),
		minor: Number(n[2]),
		patch: Number(n[3]),
		prerelease: n[4] === void 0 ? [] : n[4].split(`.`).map((e) => /^(0|[1-9][0-9]*)$/u.test(e) ? Number(e) : e)
	};
}
function compareSemanticVersions(e, n) {
	let r = parseSemanticVersion(e), i = parseSemanticVersion(n);
	for (let e of [
		`major`,
		`minor`,
		`patch`
	]) if (r[e] !== i[e]) return r[e] < i[e] ? -1 : 1;
	if (r.prerelease.length === 0 && i.prerelease.length === 0) return 0;
	if (r.prerelease.length === 0) return 1;
	if (i.prerelease.length === 0) return -1;
	let a = Math.max(r.prerelease.length, i.prerelease.length);
	for (let e = 0; e < a; e += 1) {
		let t = r.prerelease[e], n = i.prerelease[e];
		if (t === void 0) return -1;
		if (n === void 0) return 1;
		if (t !== n) return typeof t == `number` && typeof n == `number` ? t < n ? -1 : 1 : typeof t == `number` ? -1 : typeof n == `number` ? 1 : t < n ? -1 : 1;
	}
	return 0;
}
function normalizeVersionRange(e) {
	let n = e.trim();
	if (n.length === 0 || n.length > 120 || n.includes(`||`)) throw TypeError(`${e} is not a supported version range.`);
	return n.split(/\s+/u).map((e) => {
		if (e.startsWith(`^`)) {
			let n = e.slice(1), r = parseSemanticVersion(n);
			return `>=${n} <${r.major > 0 ? `${r.major + 1}.0.0-0` : r.minor > 0 ? `0.${r.minor + 1}.0-0` : `0.0.${r.patch + 1}-0`}`;
		}
		if (e.startsWith(`~`)) {
			let n = e.slice(1), r = parseSemanticVersion(n);
			return `>=${n} <${r.major}.${r.minor + 1}.0-0`;
		}
		return a$8(e), e;
	}).join(` `);
}
function satisfiesVersionRange(e, i) {
	return parseSemanticVersion(e), normalizeVersionRange(i).split(/\s+/u).map(a$8).every((t) => {
		let r = compareSemanticVersions(e, t.version);
		switch (t.operator) {
			case `<`: return r < 0;
			case `<=`: return r <= 0;
			case `=`: return r === 0;
			case `>`: return r > 0;
			default: return r >= 0;
		}
	});
}
function a$8(e) {
	let n = /^(>=|<=|>|<|=)?([^<>=].*)$/u.exec(e);
	if (n?.[2] === void 0) throw TypeError(`${e} is not a supported version comparator.`);
	return parseSemanticVersion(n[2]), {
		operator: n[1] ?? `=`,
		version: n[2]
	};
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/contributions.js
var StudioContributionError = class extends Error {
	diagnostics;
	constructor(e, t) {
		super(e), this.name = `StudioContributionError`, this.diagnostics = t;
	}
};
var _$6 = {
	"authoring-target": compileProfileSchema(authoringTargetSchema, { schemas: [commonSchema] }),
	block: compileProfileSchema(blockDefinitionSchema, { schemas: [commonSchema] }),
	"design-vocabulary": compileProfileSchema(designVocabularySchema, { schemas: [commonSchema] }),
	"field-adapter": compileProfileSchema(fieldAdapterSchema, { schemas: [commonSchema] }),
	inspector: compileProfileSchema(inspectorSchema, { schemas: [commonSchema] }),
	migration: compileProfileSchema(migrationSchema, { schemas: [commonSchema] }),
	pattern: compileProfileSchema(patternSchema, { schemas: [commonSchema, blueprintSchema] })
};
var RegistryGeneration = class {
	#contributions;
	#generation;
	#owners;
	#registry;
	constructor(e, t, n, r = []) {
		this.#contributions = new Map(r.map((e) => [x$6(e.kind, e.id, e.version), cloneContractValue(e.payload)])), this.#generation = e, this.#owners = t, this.#registry = n;
	}
	get generation() {
		return this.#generation;
	}
	get registry() {
		return this.#registry;
	}
	blocks() {
		return this.#registry.definitions();
	}
	owners() {
		return this.#owners.map((e) => cloneContractValue(e));
	}
	resolveBlock(e, t) {
		return this.#registry.resolve(e, t);
	}
	resolveContribution(e, t, n) {
		if (e === `block`) return this.resolveBlock(t, n);
		let r = this.#contributions.get(x$6(e, t, n));
		return r === void 0 ? void 0 : cloneContractValue(r);
	}
	contributions(e) {
		if (e === `block`) return this.blocks();
		let t = `${e}\u0000`;
		return [...this.#contributions.entries()].filter(([e]) => e.startsWith(t)).sort(([e], [t]) => e.localeCompare(t)).map(([, e]) => cloneContractValue(e));
	}
	authoringTargets() {
		return [...this.#contributions.entries()].filter(([e]) => e.startsWith(`authoring-target\0`)).sort(([e], [t]) => e.localeCompare(t)).map(([, e]) => cloneContractValue(e));
	}
	resolveAuthoringTarget(e, t) {
		let n = this.authoringTargets().find((t) => t.id === e.targetId);
		if (n === void 0 || !T$6(n, e, t)) return;
		let r = [];
		for (let e of n.contributionDependencies) {
			let t = this.#resolveDependency(e);
			if (t === void 0) {
				if (e.required) return;
				continue;
			}
			r.push(t);
		}
		return {
			contributions: cloneContractValue(r),
			target: cloneContractValue(n)
		};
	}
	#resolveDependency(e) {
		let t = C$6(e.kind);
		return w$6(this.#contributions, t, e.id).filter((t) => satisfiesVersionRange(t.version, e.versions)).sort((e, t) => compareSemanticVersions(t.version, e.version))[0]?.payload;
	}
};
var v$6 = class extends BlockRegistry {
	#sealed = !1;
	seal() {
		this.#sealed = !0;
	}
	register(...e) {
		if (this.#sealed) throw Error(`A published registry generation is immutable.`);
		super.register(...e);
	}
};
var ContributionRuntime = class {
	#extensions = /* @__PURE__ */ new Map();
	#current;
	constructor(e) {
		this.#current = this.#publish(e.generation);
	}
	get current() {
		return this.#current;
	}
	activate(e, t, n) {
		let r = y$6(t), i = this.#collectActivationDiagnostics(e, r);
		if (i.length > 0) throw this.#extensions.has(e.id) || this.#extensions.set(e.id, {
			contributions: y$6({ blocks: [] }),
			diagnostics: i,
			owner: cloneContractValue(e),
			state: `rejected`
		}), new StudioContributionError(`Activation of ${e.id} was rejected: ${i.map((e) => e.code).join(`, `)}.`, i);
		return this.#extensions.set(e.id, {
			contributions: r,
			diagnostics: [],
			owner: cloneContractValue(e),
			state: `active`
		}), this.#current = this.#publish(n.generation), this.#current;
	}
	disable(e, t) {
		let n = this.#requireExtension(e);
		if (n.state !== `active` && n.state !== `disabled`) throw new StudioContributionError(`Extension ${e} cannot be disabled from lifecycle state ${n.state}.`, n.diagnostics);
		return n.state = `disabled`, this.#current = this.#publish(t.generation), this.#current;
	}
	reactivate(e, t) {
		let n = this.#requireExtension(e);
		if (n.state !== `disabled`) throw new StudioContributionError(`Extension ${e} cannot be reactivated from lifecycle state ${n.state}; a fresh verified activation is required.`, n.diagnostics);
		return n.state = `active`, this.#current = this.#publish(t.generation), this.#current;
	}
	revokeTrust(e, t) {
		let n = this.#requireExtension(e);
		return n.state = `trust-revoked`, this.#current = this.#publish(t.generation), this.#current;
	}
	uninstall(e, t) {
		let n = this.#requireExtension(e);
		return n.state = `uninstalled-data-preserved`, this.#current = this.#publish(t.generation), this.#current;
	}
	assertCurrent(e) {
		if (this.#current.generation !== e) throw new StudioCommandError(`stale-generation`, `Registry generation ${e} is stale; the active generation is ${this.#current.generation}.`);
		return this.#current;
	}
	inventory() {
		return [...this.#extensions.values()].map((e) => ({
			diagnostics: cloneContractValue(e.diagnostics),
			owner: cloneContractValue(e.owner),
			state: e.state
		})).sort((e, t) => e.owner.id < t.owner.id ? -1 : 1);
	}
	unresolvedNodes(e) {
		let t = [], n = [...e.roots].reverse();
		for (; n.length > 0;) {
			let e = n.pop();
			if (e === void 0) break;
			if (this.#current.resolveBlock(e.type, e.version) === void 0) {
				let { owner: n, reason: r } = this.#unresolvedReason(e.type, e.version), i = {
					nodeId: e.id,
					reason: r,
					type: e.type,
					version: e.version
				};
				n !== void 0 && (i.owner = cloneContractValue(n)), t.push(i);
			}
			for (let t of Object.values(e.slots)) n.push(...[...t].reverse());
		}
		return t;
	}
	unresolvedContributions(e) {
		let t = /* @__PURE__ */ new Map();
		for (let n of this.unresolvedNodes(e)) {
			let e = `${n.type}@${n.version}`, r = t.get(e);
			r === void 0 && (r = {
				affectedNodes: [],
				contractVersion: STUDIO_CONTRACT_VERSION,
				diagnostics: [{
					code: `studio.validation/block-unavailable`,
					message: {
						defaultMessage: `The ${n.type} block is currently unavailable; its content is preserved.`,
						key: `studio.validation/block-unavailable`
					},
					severity: `warning`
				}],
				kind: `unresolved-contribution`,
				reason: n.reason,
				reference: {
					contribution: `block`,
					id: n.type,
					version: n.version
				}
			}, n.owner !== void 0 && (r.owner = n.owner), t.set(e, r)), r.affectedNodes?.push(n.nodeId);
		}
		return [...t.values()];
	}
	unresolvedReference(e) {
		if (!S$6(e.contribution)) return { reason: `not-installed` };
		if (this.#current.resolveContribution(e.contribution, e.id, e.version) === void 0) return this.#unresolvedContributionReason(e.contribution, e.id, e.version);
	}
	#unresolvedReason(e, t) {
		return this.#unresolvedContributionReason(`block`, e, t);
	}
	#unresolvedContributionReason(e, t, n) {
		for (let r of this.#extensions.values()) {
			let i = b$6(r.contributions).filter((n) => n.kind === e && n.id === t).map((e) => e.version);
			if (i.length !== 0) return i.includes(n) ? r.state === `trust-revoked` ? {
				owner: r.owner,
				reason: `owner-revoked`
			} : {
				owner: r.owner,
				reason: `owner-disabled`
			} : {
				owner: r.owner,
				reason: `incompatible`
			};
		}
		return { reason: `not-installed` };
	}
	#collectActivationDiagnostics(e, t) {
		let n = [], r = /* @__PURE__ */ new Set();
		for (let i of b$6(t)) {
			if (i.owner.id !== e.id || i.owner.version !== e.version) {
				n.push(E$6(`studio.contribution/owner-mismatch`, `${i.kind} ${i.id} declares owner ${i.owner.id}@${i.owner.version}.`));
				continue;
			}
			let t = x$6(i.kind, i.id, i.version);
			if (r.has(t)) {
				n.push(E$6(`studio.contribution/duplicate-contribution`, `${i.kind} ${i.id}@${i.version} is contributed twice by ${e.id}.`));
				continue;
			}
			r.add(t);
			let a = this.#ownerOfContribution(i.kind, i.id, e.id);
			a !== void 0 && n.push(E$6(`studio.contribution/cross-owner-collision`, `${i.kind} ${i.id} is owned by ${a}.`));
			let o = _$6[i.kind];
			if (!o.validate(i.payload)) {
				let e = o.errors?.[0];
				n.push(E$6(`studio.contribution/invalid-definition`, `${i.kind} ${i.id}@${i.version} ${e?.instancePath ?? `document`} ${e?.message ?? `violates its canonical schema`}.`));
			}
		}
		if (n.length === 0) try {
			let n = new v$6();
			for (let t of this.#extensions.values()) if (t.state === `active` && t.owner.id !== e.id) for (let e of t.contributions.blocks) n.register(cloneContractValue(e));
			for (let e of t.blocks) n.register(cloneContractValue(e));
			for (let e of t.fieldAdapters) e.optionSchema !== void 0 && assertStudioPropertySchema(e.optionSchema);
		} catch (e) {
			n.push(E$6(`studio.contribution/invalid-definition`, e instanceof Error ? e.message : `A contributed definition is invalid.`));
		}
		return n;
	}
	#ownerOfContribution(e, t, n) {
		for (let r of this.#extensions.values()) if (r.owner.id !== n && r.state !== `purged` && b$6(r.contributions).some((n) => n.kind === e && n.id === t)) return r.owner.id;
	}
	#publish(e) {
		let t = new v$6(), n = [], r = [];
		for (let e of this.#extensions.values()) if (e.state === `active`) {
			n.push(cloneContractValue(e.owner));
			for (let n of e.contributions.blocks) t.register(cloneContractValue(n));
			r.push(...b$6(e.contributions));
		}
		return t.seal(), new RegistryGeneration(e, n, t, r);
	}
	#requireExtension(e) {
		let t = this.#extensions.get(e);
		if (t === void 0) throw new StudioContributionError(`Extension ${e} is not known to the contribution runtime.`, []);
		return t;
	}
};
function y$6(e) {
	return {
		authoringTargets: cloneContractValue(e.authoringTargets ?? []),
		blocks: cloneContractValue(e.blocks),
		designVocabularies: cloneContractValue(e.designVocabularies ?? []),
		fieldAdapters: cloneContractValue(e.fieldAdapters ?? []),
		inspectors: cloneContractValue(e.inspectors ?? []),
		migrations: cloneContractValue(e.migrations ?? []),
		patterns: cloneContractValue(e.patterns ?? [])
	};
}
function b$6(e) {
	return [
		...e.authoringTargets.map((e) => ({
			id: e.id,
			kind: `authoring-target`,
			owner: e.owner,
			payload: e,
			version: e.owner.version
		})),
		...e.blocks.map((e) => ({
			id: e.type,
			kind: `block`,
			owner: e.owner,
			payload: e,
			version: e.version
		})),
		...e.designVocabularies.map((e) => ({
			id: e.id,
			kind: `design-vocabulary`,
			owner: e.owner,
			payload: e,
			version: e.version
		})),
		...e.fieldAdapters.map((e) => ({
			id: e.id,
			kind: `field-adapter`,
			owner: e.owner,
			payload: e,
			version: e.version
		})),
		...e.inspectors.map((e) => ({
			id: e.id,
			kind: `inspector`,
			owner: e.owner,
			payload: e,
			version: e.version
		})),
		...e.migrations.map((e) => ({
			id: e.id,
			kind: `migration`,
			owner: e.owner,
			payload: e,
			version: e.version
		})),
		...e.patterns.map((e) => ({
			id: e.id,
			kind: `pattern`,
			owner: e.owner,
			payload: e,
			version: e.version
		}))
	];
}
function x$6(e, t, n) {
	return `${e}\u0000${t}\u0000${n}`;
}
function S$6(e) {
	return e === `authoring-target` || e === `block` || e === `design-vocabulary` || e === `field-adapter` || e === `inspector` || e === `migration` || e === `pattern`;
}
function C$6(e) {
	return e === `block-definition` ? `block` : e;
}
function w$6(e, t, n) {
	let r = `${t}\u0000${n}\u0000`;
	return [...e.entries()].filter(([e]) => e.startsWith(r)).map(([e, t]) => ({
		payload: cloneContractValue(t),
		version: e.slice(r.length)
	}));
}
function T$6(e, t, n) {
	return e.surface !== t.resourceContext.surface || t.resourceContext.resource === void 0 || !e.resourceTypes.includes(t.resourceContext.resource.type) || !e.eligibility.includes(t.intent) || t.requestedPresentation !== void 0 && !e.presentationStates.includes(t.requestedPresentation) || n.mode !== void 0 && !e.modes.includes(n.mode) ? !1 : e.requiredCapabilities.every((e) => n.capabilities.some((t) => t.id === e.id && satisfiesVersionRange(t.version, e.versions)));
}
function E$6(e, t) {
	return {
		code: e,
		message: {
			defaultMessage: t,
			key: `studio.contribution/activation`
		},
		severity: `blocking`
	};
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/layout.js
var CORE_LAYOUT_BLOCK_TYPES = Object.freeze({
	columns: `studio.core/columns`,
	grid: `studio.core/grid`,
	section: `studio.core/section`,
	stack: `studio.core/stack`
});
var CORE_LAYOUT_THEME_CONTROLS = Object.freeze({
	alignment: `layout-alignment`,
	collapse: `layout-collapse`,
	direction: `layout-direction`,
	spacing: `layout-spacing`,
	visibility: `layout-visibility`
});
var a$7 = Object.freeze([{
	capability: `studio.renderer/layout`,
	surface: `preview`,
	versions: `^1.0.0`
}, {
	capability: `studio.renderer/layout`,
	surface: `web`,
	versions: `^1.0.0`
}]);
var o$5 = [
	`center`,
	`end`,
	`start`,
	`stretch`
];
var s$6 = [
	`preserve`,
	`stack`,
	`wrap`
];
var c$4 = [`block`, `inline`];
var l$9 = [
	`comfortable`,
	`compact`,
	`none`,
	`spacious`
];
var u$9 = [`hidden`, `visible`];
function isCoreLayoutBlockType(e) {
	return Object.values(CORE_LAYOUT_BLOCK_TYPES).includes(e);
}
function createCoreLayoutBlockDefinitions(e = {}) {
	let r = g$6([...Object.values(CORE_LAYOUT_BLOCK_TYPES), ...e.acceptedChildTypes ?? []]), i = cloneContractValue(e.rendererRequirements ?? a$7);
	if (i.length === 0) throw RangeError(`Core layout blocks require at least one trusted renderer capability.`);
	return [
		f$9(`section`, r, i),
		f$9(`stack`, r, i),
		f$9(`grid`, r, i),
		f$9(`columns`, r, i)
	];
}
function coreLayoutInitialProperties(e) {
	switch (e) {
		case CORE_LAYOUT_BLOCK_TYPES.section: return {};
		case CORE_LAYOUT_BLOCK_TYPES.stack: return { direction: `block` };
		case CORE_LAYOUT_BLOCK_TYPES.grid:
		case CORE_LAYOUT_BLOCK_TYPES.columns: return {
			collapse: `stack`,
			columns: 1
		};
	}
}
function f$9(i, a, o) {
	let s = CORE_LAYOUT_BLOCK_TYPES[i], c = `${i.charAt(0).toUpperCase()}${i.slice(1)}`, l = [
		CORE_LAYOUT_THEME_CONTROLS.alignment,
		CORE_LAYOUT_THEME_CONTROLS.spacing,
		CORE_LAYOUT_THEME_CONTROLS.visibility
	];
	return i === `stack` && l.push(CORE_LAYOUT_THEME_CONTROLS.direction), (i === `grid` || i === `columns`) && l.push(CORE_LAYOUT_THEME_CONTROLS.collapse), {
		accessibility: {
			accessibleName: i === `section` ? `derived` : `not-applicable`,
			category: i === `section` ? `landmark` : `structural`,
			keyboard: {
				defaultMessage: `Use the outline commands to insert, move, and reorder layout children.`,
				key: `studio.blocks/layout-keyboard`
			},
			outputChecks: [`studio.check/reading-order`, `studio.check/reflow`],
			reducedMotion: `not-applicable`
		},
		category: `studio.category/layout`,
		contractVersion: STUDIO_CONTRACT_VERSION,
		editingModes: [`blueprint`, `content`],
		icon: {
			kind: `symbol`,
			value: i
		},
		kind: `block-definition`,
		label: {
			defaultMessage: c,
			key: `studio.blocks/${i}`
		},
		owner: {
			id: `studio.core/blocks`,
			version: `1.0.0`
		},
		ports: [],
		propertyControls: l.map((e) => ({
			control: `studio.control/${e}`,
			property: h$6(e)
		})),
		propertySchema: m$7(i),
		rendererRequirements: cloneContractValue([...o]),
		revision: `layout-${i}-r1`,
		slots: [p$7(i, a)],
		themeControls: l,
		type: s,
		version: `1.0.0`
	};
}
function p$7(e, n) {
	let r = e === `section` ? `content` : `items`;
	return {
		accepts: { types: cloneContractValue(n) },
		id: r,
		label: {
			defaultMessage: e === `section` ? `Content` : `Items`,
			key: e === `section` ? `studio.blocks/section-content` : `studio.blocks/layout-items`
		},
		maximum: 100,
		minimum: 0,
		ordered: !0
	};
}
function m$7(e) {
	let t = {
		alignment: { enum: [...o$5] },
		spacing: { enum: [...l$9] },
		visibility: { enum: [...u$9] }
	};
	return e === `stack` && (t.direction = { enum: [...c$4] }), (e === `grid` || e === `columns`) && (t.collapse = { enum: [...s$6] }, t.columns = {
		maximum: 12,
		minimum: 1,
		type: `integer`
	}), {
		additionalProperties: !1,
		properties: t,
		type: `object`
	};
}
function h$6(e) {
	switch (e) {
		case CORE_LAYOUT_THEME_CONTROLS.alignment: return `alignment`;
		case CORE_LAYOUT_THEME_CONTROLS.collapse: return `collapse`;
		case CORE_LAYOUT_THEME_CONTROLS.direction: return `direction`;
		case CORE_LAYOUT_THEME_CONTROLS.spacing: return `spacing`;
		case CORE_LAYOUT_THEME_CONTROLS.visibility: return `visibility`;
		default: throw RangeError(`Unknown core layout control ${e}.`);
	}
}
function g$6(e) {
	let t = [...new Set(e)];
	if (t.sort((e, t) => e < t ? -1 : +(e > t)), t.length === 0) throw RangeError(`A core layout slot requires at least one accepted block type.`);
	return t;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/production.js
var CORE_PRODUCTION_BLOCK_TYPES = Object.freeze({
	...CORE_LAYOUT_BLOCK_TYPES,
	accordion: `studio.core/accordion`,
	accordionItem: `studio.core/accordion-item`,
	article: `studio.core/article`,
	attachment: `studio.core/attachment`,
	audio: `studio.core/audio`,
	badge: `studio.core/badge`,
	callToAction: `studio.core/call-to-action`,
	callout: `studio.core/callout`,
	card: `studio.core/card`,
	chart: `studio.core/chart`,
	code: `studio.core/code`,
	contentCollection: `studio.core/content-collection`,
	contentReference: `studio.core/content-reference`,
	countdown: `studio.core/countdown`,
	cover: `studio.core/cover`,
	descriptionItem: `studio.core/description-item`,
	descriptionList: `studio.core/description-list`,
	diagram: `studio.core/diagram`,
	dialog: `studio.core/dialog`,
	divider: `studio.core/divider`,
	drawing: `studio.core/drawing`,
	embed: `studio.core/embed`,
	gallery: `studio.core/gallery`,
	heading: `studio.core/heading`,
	icon: `studio.core/icon`,
	image: `studio.core/image`,
	label: `studio.core/label`,
	math: `studio.core/math`,
	money: `studio.core/money`,
	navigation: `studio.core/navigation`,
	navigationItem: `studio.core/navigation-item`,
	notice: `studio.core/notice`,
	popover: `studio.core/popover`,
	progress: `studio.core/progress`,
	richText: `studio.core/rich-text`,
	search: `studio.core/search`,
	spinner: `studio.core/spinner`,
	tab: `studio.core/tab`,
	table: `studio.core/table`,
	tabs: `studio.core/tabs`,
	video: `studio.core/video`
});
var CORE_PRODUCTION_CONTROL_IDS = Object.freeze({
	chart: `studio.control/chart`,
	drawing: `studio.control/drawing`,
	mediaCollection: `studio.control/media-collection`,
	mediaReference: `studio.control/media-reference`,
	money: `studio.control/money`,
	presentation: `studio.control/presentation`,
	richText: `studio.control/rich-text`,
	scopedCss: `studio.control/scoped-css`,
	source: `studio.control/source`,
	table: `studio.control/table`
});
Object.freeze([
	`studio.pattern/article`,
	`studio.pattern/collection-index`,
	`studio.pattern/document-header`,
	`studio.pattern/faq`,
	`studio.pattern/feature-grid`,
	`studio.pattern/hero`,
	`studio.pattern/media-gallery`,
	`studio.pattern/pricing`,
	`studio.pattern/product`,
	`studio.pattern/tabbed-content`
]);
var l$8 = `1.0.0`;
var u$8 = Object.freeze({
	id: `studio.core/blocks`,
	version: l$8
});
var d$8 = Object.freeze([{
	capability: `studio.renderer/semantic-web`,
	surface: `preview`,
	versions: `^1.0.0`
}, {
	capability: `studio.renderer/semantic-web`,
	surface: `web`,
	versions: `^1.0.0`
}]);
var f$8 = Object.freeze(Object.values(CORE_PRODUCTION_BLOCK_TYPES));
var p$6 = Object.freeze(f$8.filter((e) => e !== CORE_PRODUCTION_BLOCK_TYPES.accordionItem && e !== CORE_PRODUCTION_BLOCK_TYPES.descriptionItem && e !== CORE_PRODUCTION_BLOCK_TYPES.navigationItem && e !== CORE_PRODUCTION_BLOCK_TYPES.tab));
var m$6 = (e, t = !1) => ({
	authoring: { control: `studio.control/single-line-text` },
	id: e,
	label: L$2(`port-${e}`, R$2(e)),
	multiple: !1,
	required: t,
	valueType: `text`
});
var h$5 = (e, t = !1) => ({
	authoring: { control: `studio.control/integer` },
	id: e,
	label: L$2(`port-${e}`, R$2(e)),
	multiple: !1,
	required: t,
	valueType: `integer`
});
var g$5 = (e = `content`) => ({
	authoring: {
		control: CORE_PRODUCTION_CONTROL_IDS.richText,
		profile: `studio.rich-text/marketing`
	},
	id: e,
	label: L$2(`port-${e}`, R$2(e)),
	multiple: !1,
	required: !1,
	valueType: `rich-text`
});
var _$5 = (e, t = !1) => ({
	authoring: { control: t ? CORE_PRODUCTION_CONTROL_IDS.mediaCollection : CORE_PRODUCTION_CONTROL_IDS.mediaReference },
	id: e,
	label: L$2(`port-${e}`, R$2(e)),
	multiple: t,
	required: !1,
	valueType: `media`
});
var v$5 = (e) => ({
	authoring: {
		control: CORE_PRODUCTION_CONTROL_IDS.source,
		profile: e
	},
	id: `source`,
	label: L$2(`port-source`, `Source`),
	multiple: !1,
	required: !0,
	valueType: `text`
});
var y$5 = (e, t) => ({
	authoring: { readOnly: !0 },
	id: e,
	label: L$2(`port-${e}`, R$2(e)),
	multiple: t,
	required: !0,
	valueType: `resource`
});
var b$5 = (e = 2e4) => ({
	maxLength: e,
	type: `string`
});
var x$5 = () => ({ type: `boolean` });
var S$5 = (...e) => ({ enum: e });
var C$5 = (e, t) => ({
	maximum: t,
	minimum: e,
	type: `integer`
});
var w$5 = O$5();
var T$5 = Object.freeze({
	accordion: {
		accessibility: `composite`,
		controls: { "allow-multiple": `studio.control/switch` },
		defaults: { "allow-multiple": !1 },
		properties: { "allow-multiple": x$5() },
		slots: [{
			accepts: [CORE_PRODUCTION_BLOCK_TYPES.accordionItem],
			id: `items`,
			maximum: 50
		}]
	},
	accordionItem: {
		accessibility: `composite`,
		controls: { expanded: `studio.control/switch` },
		defaults: { expanded: !1 },
		ports: [m$6(`title`, !0)],
		properties: { expanded: x$5() },
		slots: [{
			accepts: p$6,
			id: `content`,
			maximum: 100
		}]
	},
	article: {
		accessibility: `landmark`,
		defaults: {},
		ports: [m$6(`title`)],
		slots: [{
			accepts: p$6,
			id: `content`,
			maximum: 200
		}]
	},
	attachment: {
		accessibility: `media`,
		controls: { download: `studio.control/switch` },
		defaults: { download: !0 },
		ports: [_$5(`asset`), m$6(`label`)],
		properties: { download: x$5() }
	},
	audio: {
		accessibility: `media`,
		controls: {
			autoplay: `studio.control/switch`,
			controls: `studio.control/switch`
		},
		defaults: {
			autoplay: !1,
			controls: !0
		},
		ports: [_$5(`asset`), m$6(`transcript`)],
		properties: {
			autoplay: x$5(),
			controls: x$5()
		}
	},
	badge: {
		accessibility: `text`,
		controls: {
			appearance: `studio.control/select`,
			tone: `studio.control/select`
		},
		defaults: {
			appearance: `solid`,
			tone: `neutral`
		},
		ports: [m$6(`label`, !0)],
		properties: {
			appearance: S$5(`outline`, `soft`, `solid`),
			tone: S$5(`error`, `information`, `neutral`, `success`, `warning`)
		}
	},
	callToAction: {
		accessibility: `interactive`,
		controls: {
			appearance: `studio.control/select`,
			href: `studio.control/single-line-text`
		},
		defaults: {
			appearance: `primary`,
			href: ``
		},
		ports: [m$6(`label`, !0)],
		properties: {
			appearance: S$5(`primary`, `secondary`, `link`),
			href: b$5(2048)
		}
	},
	callout: {
		accessibility: `composite`,
		controls: { tone: `studio.control/select` },
		defaults: { tone: `information` },
		ports: [m$6(`title`), g$5()],
		properties: { tone: S$5(`information`, `success`, `warning`, `danger`) }
	},
	card: {
		accessibility: `composite`,
		controls: { appearance: `studio.control/select` },
		defaults: { appearance: `plain` },
		ports: [
			_$5(`media`),
			m$6(`title`),
			g$5(`summary`)
		],
		properties: { appearance: S$5(`plain`, `bordered`, `elevated`) },
		slots: [{
			accepts: p$6,
			id: `actions`,
			maximum: 5
		}]
	},
	chart: {
		accessibility: `data-display`,
		defaults: {},
		ports: [{
			authoring: {
				control: CORE_PRODUCTION_CONTROL_IDS.chart,
				profile: `studio.chart/canonical`
			},
			id: `chart`,
			label: L$2(`port-chart`, `Chart`),
			multiple: !1,
			required: !0,
			valueType: `studio.value/chart`
		}]
	},
	code: {
		accessibility: `text`,
		controls: {
			language: `studio.control/single-line-text`,
			"show-line-numbers": `studio.control/switch`
		},
		defaults: {
			language: `text`,
			"show-line-numbers": !1
		},
		ports: [v$5(`studio.source/code`)],
		properties: {
			language: b$5(100),
			"show-line-numbers": x$5()
		}
	},
	contentCollection: {
		accessibility: `data-display`,
		controls: {
			limit: `studio.control/integer`,
			presentation: `studio.control/select`
		},
		defaults: {
			limit: 12,
			presentation: `cards`
		},
		ports: [y$5(`items`, !0)],
		properties: {
			limit: C$5(1, 100),
			presentation: S$5(`cards`, `grid`, `list`, `slideshow`)
		}
	},
	contentReference: {
		accessibility: `data-display`,
		controls: { presentation: `studio.control/select` },
		defaults: { presentation: `summary` },
		ports: [y$5(`item`, !1)],
		properties: { presentation: S$5(`full`, `summary`, `title`) }
	},
	countdown: {
		accessibility: `data-display`,
		controls: {
			display: `studio.control/select`,
			"expired-behavior": `studio.control/select`
		},
		defaults: {
			display: `detailed`,
			"expired-behavior": `zero`
		},
		ports: [m$6(`target`, !0), m$6(`completion-message`)],
		properties: {
			display: S$5(`compact`, `detailed`),
			"expired-behavior": S$5(`hide`, `message`, `zero`)
		}
	},
	cover: {
		accessibility: `composite`,
		controls: {
			alignment: `studio.control/select`,
			overlay: `studio.control/select`
		},
		defaults: {
			alignment: `center`,
			overlay: `medium`
		},
		ports: [_$5(`background`)],
		properties: {
			alignment: S$5(`center`, `end`, `start`),
			overlay: S$5(`light`, `medium`, `none`, `strong`)
		},
		slots: [{
			accepts: p$6,
			id: `content`,
			maximum: 100
		}]
	},
	descriptionItem: {
		accessibility: `text`,
		defaults: {},
		ports: [m$6(`term`, !0), g$5(`description`)]
	},
	descriptionList: {
		accessibility: `data-display`,
		defaults: {},
		ports: [m$6(`title`)],
		slots: [{
			accepts: [CORE_PRODUCTION_BLOCK_TYPES.descriptionItem],
			id: `items`,
			maximum: 100
		}]
	},
	diagram: {
		accessibility: `data-display`,
		controls: { theme: `studio.control/select` },
		defaults: { theme: `neutral` },
		ports: [v$5(`studio.source/mermaid`)],
		properties: { theme: S$5(`dark`, `forest`, `neutral`) }
	},
	dialog: {
		accessibility: `interactive`,
		controls: {
			modal: `studio.control/switch`,
			presentation: `studio.control/select`
		},
		defaults: {
			modal: !0,
			presentation: `modal`
		},
		ports: [m$6(`trigger-label`, !0), m$6(`title`, !0)],
		properties: {
			modal: x$5(),
			presentation: S$5(`modal`, `offcanvas`, `overlay`)
		},
		slots: [{
			accepts: p$6,
			id: `content`,
			maximum: 100
		}]
	},
	divider: {
		accessibility: `structural`,
		controls: { style: `studio.control/select` },
		defaults: { style: `solid` },
		ports: [m$6(`label`)],
		properties: { style: S$5(`dashed`, `dotted`, `solid`) }
	},
	drawing: {
		accessibility: `media`,
		defaults: {},
		ports: [{
			authoring: {
				control: CORE_PRODUCTION_CONTROL_IDS.drawing,
				profile: `studio.drawing/canonical`
			},
			id: `drawing`,
			label: L$2(`port-drawing`, `Drawing`),
			multiple: !1,
			required: !0,
			valueType: `studio.value/drawing`
		}]
	},
	embed: {
		accessibility: `media`,
		controls: { "aspect-ratio": `studio.control/select` },
		defaults: { "aspect-ratio": `16:9` },
		ports: [y$5(`resource`, !1)],
		properties: { "aspect-ratio": S$5(`1:1`, `4:3`, `16:9`, `21:9`) }
	},
	gallery: {
		accessibility: `composite`,
		controls: {
			autoplay: `studio.control/switch`,
			columns: `studio.control/integer`,
			lightbox: `studio.control/switch`,
			presentation: `studio.control/select`
		},
		defaults: {
			autoplay: !1,
			columns: 4,
			lightbox: !1,
			presentation: `grid`
		},
		ports: [_$5(`items`, !0)],
		properties: {
			autoplay: x$5(),
			columns: C$5(1, 12),
			lightbox: x$5(),
			presentation: S$5(`grid`, `slideshow`)
		}
	},
	heading: {
		accessibility: `text`,
		controls: { level: `studio.control/select` },
		defaults: { level: 2 },
		ports: [m$6(`text`, !0)],
		properties: { level: C$5(1, 6) }
	},
	icon: {
		accessibility: `media`,
		controls: {
			decorative: `studio.control/switch`,
			name: `studio.control/single-line-text`
		},
		defaults: {
			decorative: !0,
			name: `symbol`
		},
		ports: [m$6(`alternative-text`)],
		properties: {
			decorative: x$5(),
			name: b$5(200)
		}
	},
	image: {
		accessibility: `media`,
		controls: {
			fit: `studio.control/select`,
			loading: `studio.control/select`
		},
		defaults: {
			fit: `cover`,
			loading: `lazy`
		},
		ports: [_$5(`asset`)],
		properties: {
			fit: S$5(`contain`, `cover`, `fill`, `scale-down`),
			loading: S$5(`eager`, `lazy`)
		}
	},
	label: {
		accessibility: `text`,
		controls: { tone: `studio.control/select` },
		defaults: { tone: `neutral` },
		ports: [m$6(`text`, !0)],
		properties: { tone: S$5(`error`, `information`, `neutral`, `success`, `warning`) }
	},
	math: {
		accessibility: `text`,
		controls: { "display-mode": `studio.control/switch` },
		defaults: { "display-mode": !0 },
		ports: [v$5(`studio.source/latex`)],
		properties: { "display-mode": x$5() }
	},
	money: {
		accessibility: `data-display`,
		defaults: {},
		ports: [{
			authoring: {
				control: CORE_PRODUCTION_CONTROL_IDS.money,
				profile: `studio.money/canonical`
			},
			id: `amount`,
			label: L$2(`port-amount`, `Amount`),
			multiple: !1,
			required: !0,
			valueType: `money`
		}]
	},
	navigation: {
		accessibility: `landmark`,
		controls: { presentation: `studio.control/select` },
		defaults: { presentation: `nav` },
		ports: [m$6(`label`)],
		properties: { presentation: S$5(`breadcrumbs`, `dotnav`, `dropnav`, `navbar`, `nav`, `pagination`, `subnav`, `thumbnav`) },
		slots: [{
			accepts: [CORE_PRODUCTION_BLOCK_TYPES.navigationItem],
			id: `items`,
			maximum: 100
		}]
	},
	navigationItem: {
		accessibility: `interactive`,
		controls: {
			current: `studio.control/switch`,
			href: `studio.control/single-line-text`
		},
		defaults: {
			current: !1,
			href: ``
		},
		ports: [m$6(`label`, !0)],
		properties: {
			current: x$5(),
			href: b$5(2048)
		},
		slots: [{
			accepts: [CORE_PRODUCTION_BLOCK_TYPES.navigationItem],
			id: `children`,
			maximum: 50
		}]
	},
	notice: {
		accessibility: `composite`,
		controls: {
			dismissible: `studio.control/switch`,
			tone: `studio.control/select`
		},
		defaults: {
			dismissible: !1,
			tone: `information`
		},
		ports: [m$6(`title`), g$5()],
		properties: {
			dismissible: x$5(),
			tone: S$5(`comment`, `error`, `information`, `success`, `warning`)
		}
	},
	popover: {
		accessibility: `interactive`,
		controls: {
			"dismiss-on-blur": `studio.control/switch`,
			placement: `studio.control/select`,
			presentation: `studio.control/select`
		},
		defaults: {
			"dismiss-on-blur": !0,
			placement: `auto`,
			presentation: `popover`
		},
		ports: [m$6(`trigger-label`, !0), m$6(`title`)],
		properties: {
			"dismiss-on-blur": x$5(),
			placement: S$5(`auto`, `bottom`, `left`, `right`, `top`),
			presentation: S$5(`dropbar`, `dropdown`, `popover`, `tooltip`)
		},
		slots: [{
			accepts: p$6,
			id: `content`,
			maximum: 100
		}]
	},
	progress: {
		accessibility: `data-display`,
		controls: { maximum: `studio.control/integer` },
		defaults: { maximum: 100 },
		ports: [m$6(`label`), h$5(`value`, !0)],
		properties: { maximum: C$5(1, 1e6) }
	},
	richText: {
		accessibility: `text`,
		defaults: {},
		ports: [g$5()]
	},
	search: {
		accessibility: `interactive`,
		controls: {
			action: `studio.control/single-line-text`,
			"query-parameter": `studio.control/single-line-text`
		},
		defaults: {
			action: ``,
			"query-parameter": `q`
		},
		ports: [m$6(`label`), m$6(`placeholder`)],
		properties: {
			action: b$5(2048),
			"query-parameter": b$5(100)
		}
	},
	spinner: {
		accessibility: `data-display`,
		controls: {
			active: `studio.control/switch`,
			size: `studio.control/select`
		},
		defaults: {
			active: !0,
			size: `medium`
		},
		ports: [m$6(`label`)],
		properties: {
			active: x$5(),
			size: S$5(`large`, `medium`, `small`)
		}
	},
	tab: {
		accessibility: `composite`,
		defaults: {},
		ports: [m$6(`title`, !0)],
		slots: [{
			accepts: p$6,
			id: `content`,
			maximum: 100
		}]
	},
	tabs: {
		accessibility: `composite`,
		controls: { activation: `studio.control/select` },
		defaults: { activation: `automatic` },
		properties: { activation: S$5(`automatic`, `manual`) },
		slots: [{
			accepts: [CORE_PRODUCTION_BLOCK_TYPES.tab],
			id: `items`,
			maximum: 30
		}]
	},
	table: {
		accessibility: `data-display`,
		defaults: {},
		ports: [{
			authoring: {
				control: CORE_PRODUCTION_CONTROL_IDS.table,
				profile: `studio.table/canonical`
			},
			id: `table`,
			label: L$2(`port-table`, `Table`),
			multiple: !1,
			required: !0,
			valueType: `studio.value/table`
		}]
	},
	video: {
		accessibility: `media`,
		controls: {
			autoplay: `studio.control/switch`,
			controls: `studio.control/switch`,
			muted: `studio.control/switch`
		},
		defaults: {
			autoplay: !1,
			controls: !0,
			muted: !1
		},
		ports: [
			_$5(`asset`),
			_$5(`poster`),
			m$6(`captions`)
		],
		properties: {
			autoplay: x$5(),
			controls: x$5(),
			muted: x$5()
		}
	}
});
function createCoreProductionBlockDefinitions() {
	let e = createCoreLayoutBlockDefinitions({
		acceptedChildTypes: p$6,
		rendererRequirements: d$8
	}).map(D$4), t = Object.keys(T$5).map((e) => E$5(e, T$5[e]));
	return [...e, ...t];
}
function coreProductionInitialProperties(e) {
	if (isCoreLayoutBlockType(e)) return coreLayoutInitialProperties(e);
	return cloneContractValue(T$5[I$2(e)].defaults);
}
function isCoreProductionBlockType(e) {
	return f$8.includes(e);
}
function createCoreProductionPatterns() {
	return [
		A$4(`article`, [j$4(`article`, `stack`, {}, [j$4(`article-title`, `heading`, { text: `Article title` }), j$4(`article-body`, `richText`, { content: F$2(`Start writing…`) })])]),
		A$4(`collection-index`, [j$4(`collection-index`, `section`, {}, [j$4(`collection-heading`, `heading`, { text: `Latest content` }), j$4(`collection`, `contentCollection`, {}, void 0, { items: N$2(`studio.query/content`) })])]),
		A$4(`document-header`, [j$4(`document-header`, `columns`, {}, [j$4(`document-logo`, `image`), j$4(`document-title`, `heading`, { text: `Document title` })])]),
		A$4(`faq`, [j$4(`faq`, `accordion`, {}, [j$4(`faq-item`, `accordionItem`, { title: `Question` }, [j$4(`faq-answer`, `richText`, { content: F$2(`Answer`) })])])]),
		A$4(`feature-grid`, [j$4(`features`, `grid`, {}, [
			j$4(`feature-one`, `card`, { title: `Feature one` }),
			j$4(`feature-two`, `card`, { title: `Feature two` }),
			j$4(`feature-three`, `card`, { title: `Feature three` })
		])]),
		A$4(`hero`, [j$4(`hero`, `section`, {}, [j$4(`hero-stack`, `stack`, {}, [
			j$4(`hero-title`, `heading`, { text: `Build something meaningful` }),
			j$4(`hero-copy`, `richText`, { content: F$2(`A portable Studio page.`) }),
			j$4(`hero-action`, `callToAction`, { label: `Get started` })
		])])]),
		A$4(`media-gallery`, [j$4(`media-gallery`, `gallery`)]),
		A$4(`pricing`, [j$4(`pricing`, `card`, { title: `Plan` }, [j$4(`price`, `money`, { amount: {
			amount: `0.00`,
			currency: `USD`
		} }), j$4(`price-action`, `callToAction`, { label: `Choose plan` })])]),
		A$4(`product`, [j$4(`product`, `columns`, {}, [j$4(`product-media`, `gallery`), j$4(`product-copy`, `stack`, {}, [
			j$4(`product-title`, `heading`, { text: `Product` }),
			j$4(`product-description`, `richText`, { content: F$2(`Product description`) }),
			j$4(`product-price`, `money`, {}, void 0, { amount: P$2(`catalog/product-price`, `studio.resource/money`) })
		])])]),
		A$4(`tabbed-content`, [j$4(`tabbed-content`, `tabs`, {}, [j$4(`tab-one`, `tab`, { title: `First` }, [j$4(`tab-one-copy`, `richText`, { content: F$2(`First panel`) })]), j$4(`tab-two`, `tab`, { title: `Second` }, [j$4(`tab-two-copy`, `richText`, { content: F$2(`Second panel`) })])])])
	];
}
function E$5(t, r) {
	let i = CORE_PRODUCTION_BLOCK_TYPES[t];
	return {
		accessibility: {
			accessibleName: r.accessibility === `decorative` || r.accessibility === `structural` ? `not-applicable` : `derived`,
			category: r.accessibility,
			keyboard: L$2(`block-keyboard`, `Use Studio controls to edit and reorder this block.`),
			outputChecks: [`studio.check/accessible-name`, `studio.check/reflow`],
			reducedMotion: [
				`audio`,
				`gallery`,
				`video`
			].includes(t) ? `disable-motion` : `not-applicable`
		},
		category: `studio.category/${B$2(r.accessibility)}`,
		contractVersion: STUDIO_CONTRACT_VERSION,
		editingModes: [`blueprint`, `content`],
		icon: {
			kind: `symbol`,
			value: z$1(t)
		},
		kind: `block-definition`,
		label: L$2(`block-${z$1(t)}`, R$2(t)),
		owner: u$8,
		ports: cloneContractValue([...r.ports ?? []]),
		propertyControls: [...Object.entries(r.controls ?? {}).map(([e, t]) => ({
			control: t,
			property: e
		})), {
			control: CORE_PRODUCTION_CONTROL_IDS.presentation,
			property: `design`
		}],
		propertySchema: {
			additionalProperties: !1,
			properties: cloneContractValue({
				...r.properties ?? {},
				design: w$5
			}),
			...r.required === void 0 ? {} : { required: [...r.required] },
			type: `object`
		},
		rendererRequirements: cloneContractValue([...d$8]),
		revision: `production-${z$1(t)}-r1`,
		slots: (r.slots ?? []).map((e) => ({
			accepts: { types: cloneContractValue([...e.accepts]) },
			id: e.id,
			label: L$2(`slot-${e.id}`, R$2(e.id)),
			maximum: e.maximum ?? 100,
			minimum: e.minimum ?? 0,
			ordered: !0
		})),
		themeControls: [],
		type: i,
		version: l$8
	};
}
function D$4(e) {
	let t = e.propertySchema.properties;
	if (!k$4(t)) throw TypeError(`${e.type} property schema must declare an object property map.`);
	return {
		...e,
		propertyControls: [...e.propertyControls ?? [], {
			control: CORE_PRODUCTION_CONTROL_IDS.presentation,
			property: `design`
		}],
		propertySchema: {
			...e.propertySchema,
			properties: cloneContractValue({
				...t,
				design: w$5
			})
		}
	};
}
function O$5() {
	let e = cloneContractValue(studioPresentationSchema);
	return delete e.$id, delete e.$schema, delete e.title, e;
}
function k$4(e) {
	return typeof e == `object` && !!e && !Array.isArray(e);
}
function A$4(t, n) {
	let r = new Map(createCoreProductionBlockDefinitions().map((e) => [e.type, e])), i = /* @__PURE__ */ new Set(), a = (e) => {
		i.add(e.type), Object.values(e.slots).flat().forEach(a);
	};
	return n.forEach(a), {
		blockDependencies: [...i].sort().map((e) => {
			let n = r.get(e);
			if (n === void 0) throw Error(`Pattern ${t} uses unknown block ${e}.`);
			return {
				revision: n.revision,
				type: e,
				version: n.version
			};
		}),
		contractVersion: STUDIO_CONTRACT_VERSION,
		id: `studio.pattern/${t}`,
		kind: `pattern`,
		label: L$2(`pattern-${t}`, R$2(t)),
		owner: u$8,
		revision: `production-pattern-${t}-r1`,
		roots: n,
		version: l$8
	};
}
function j$4(e, t, n = {}, r, i = {}) {
	let a = CORE_PRODUCTION_BLOCK_TYPES[t], s = t === `section` || t === `accordionItem` || t === `dialog` || t === `popover` || t === `tab` ? `content` : t === `card` ? `actions` : `items`, c = {};
	for (let [e, t] of Object.entries(n)) c[e] = M$2({
		kind: `static-value`,
		value: t
	});
	for (let [e, t] of Object.entries(i)) c[e] = M$2(t);
	let u = {
		authoring: { mode: isCoreLayoutBlockType(a) || r !== void 0 ? `structural` : `content` },
		bindings: c,
		id: e,
		properties: coreProductionInitialProperties(a),
		slots: r === void 0 ? {} : { [s]: r },
		type: a,
		version: l$8
	};
	return t === `grid` && (u.responsive = { columns: {
		expanded: 4,
		medium: 2
	} }), u;
}
function M$2(e) {
	return {
		onError: `error`,
		onNull: `empty`,
		source: e,
		transforms: []
	};
}
function N$2(e) {
	return {
		kind: `query-reference`,
		parameters: {},
		query: e,
		version: l$8
	};
}
function P$2(e, t) {
	return {
		id: e,
		kind: `resource-reference`,
		resourceType: t
	};
}
function F$2(e) {
	return {
		content: [{
			content: [{
				text: e,
				type: `text`
			}],
			type: `paragraph`
		}],
		type: `doc`
	};
}
function I$2(e) {
	let t = Object.entries(CORE_PRODUCTION_BLOCK_TYPES).find(([, t]) => t === e);
	if (t === void 0 || isCoreLayoutBlockType(e)) throw TypeError(`Unsupported production block ${e}.`);
	return t[0];
}
function L$2(e, t) {
	return {
		defaultMessage: t,
		key: `studio.blocks/${e}`
	};
}
function R$2(e) {
	return e.replace(/([a-z])([A-Z])/gu, `$1 $2`).replaceAll(`-`, ` `).replace(/^./u, (e) => e.toUpperCase());
}
function z$1(e) {
	return e.replace(/([a-z])([A-Z])/gu, `$1-$2`).toLowerCase();
}
function B$2(e) {
	return e === `structural` || e === `landmark` ? `layout` : e;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/production-values.js
var e$3 = /* @__PURE__ */ new Set([
	`bar`,
	`doughnut`,
	`line`,
	`pie`
]);
var t$3 = /^-?(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,6})?$/u;
var n$4 = /^[A-Z]{3}$/u;
var r$5 = /^(?:#[0-9A-Fa-f]{6}|[a-z][a-z0-9-]{0,62}\/[a-z][a-z0-9-]{0,62})$/u;
function parseStudioChartSpec(t) {
	let n = l$7(t, [
		`datasets`,
		`labels`,
		`title`,
		`type`
	], `Chart`);
	if (typeof n.type != `string` || !e$3.has(n.type)) throw TypeError(`Chart type must be bar, doughnut, line, or pie.`);
	let r = u$7(n.labels, 200, 500, `Chart labels`);
	if (!Array.isArray(n.datasets) || n.datasets.length < 1 || n.datasets.length > 20) throw RangeError(`Chart datasets must contain between 1 and 20 datasets.`);
	let i = {
		datasets: n.datasets.map((e, t) => {
			let n = l$7(e, [`label`, `values`], `Chart dataset ${t}`);
			if (typeof n.label != `string` || n.label.length > 500) throw TypeError(`Chart dataset ${t} label must be a bounded string.`);
			if (!Array.isArray(n.values) || n.values.length > 200) throw RangeError(`Chart dataset ${t} values exceed the 200-value limit.`);
			let i = n.values.map((e) => {
				if (typeof e != `number` || !Number.isFinite(e) || Math.abs(e) > 0x38d7ea4c68000) throw TypeError(`Chart dataset ${t} contains an invalid finite number.`);
				return e;
			});
			if (i.length !== r.length) throw RangeError(`Chart dataset ${t} must have one value per label.`);
			return {
				label: n.label,
				values: i
			};
		}),
		labels: r,
		type: n.type
	};
	if (n.title !== void 0) {
		if (typeof n.title != `string` || n.title.length > 500) throw TypeError(`Chart title must be a bounded string.`);
		i.title = n.title;
	}
	return i;
}
function parseStudioDrawingDocument(e) {
	let t = l$7(e, [
		`alt`,
		`height`,
		`strokes`,
		`width`
	], `Drawing`), n = d$7(t.width, 1, 4096, `Drawing width`), i = d$7(t.height, 1, 4096, `Drawing height`);
	if (typeof t.alt != `string` || t.alt.length < 1 || t.alt.length > 5e3) throw TypeError(`Drawing alternative text must contain between 1 and 5000 characters.`);
	if (!Array.isArray(t.strokes) || t.strokes.length > 5e3) throw RangeError(`Drawing strokes exceed the 5000-stroke limit.`);
	let a = t.strokes.map((e, t) => {
		let a = l$7(e, [
			`color`,
			`points`,
			`width`
		], `Drawing stroke ${t}`);
		if (typeof a.color != `string` || !r$5.test(a.color)) throw TypeError(`Drawing stroke ${t} uses an invalid color token.`);
		if (typeof a.width != `number` || !Number.isFinite(a.width) || a.width < .25 || a.width > 64) throw RangeError(`Drawing stroke ${t} width is outside 0.25 through 64.`);
		if (!Array.isArray(a.points) || a.points.length < 1 || a.points.length > 1e4) throw RangeError(`Drawing stroke ${t} must contain 1 through 10000 points.`);
		let o = a.points.map((e, t) => {
			let r = l$7(e, [`x`, `y`], `Drawing point ${t}`);
			return {
				x: f$7(r.x, n, `Drawing point ${t} x`),
				y: f$7(r.y, i, `Drawing point ${t} y`)
			};
		});
		return {
			color: a.color,
			points: o,
			width: a.width
		};
	});
	return {
		alt: t.alt,
		height: i,
		strokes: a,
		width: n
	};
}
function parseStudioMoneyValue(e) {
	let r = l$7(e, [`amount`, `currency`], `Money`);
	if (typeof r.amount != `string` || !t$3.test(r.amount)) throw TypeError(`Money amount must be a canonical decimal string with at most six places.`);
	if (typeof r.currency != `string` || !n$4.test(r.currency)) throw TypeError(`Money currency must be an uppercase ISO-style three-letter code.`);
	return {
		amount: r.amount,
		currency: r.currency
	};
}
function parseStudioTableDocument(e) {
	let t = l$7(e, [
		`caption`,
		`columns`,
		`rows`
	], `Table`), n = u$7(t.columns, 50, 500, `Table columns`);
	if (n.length === 0) throw RangeError(`Table must declare at least one column.`);
	if (!Array.isArray(t.rows) || t.rows.length > 1e3) throw RangeError(`Table rows exceed the 1000-row limit.`);
	let r = t.rows.map((e, t) => {
		let r = u$7(e, 50, 5e3, `Table row ${t}`);
		if (r.length !== n.length) throw RangeError(`Table row ${t} must contain one cell per column.`);
		return r;
	}), i;
	if (t.caption !== void 0) {
		if (typeof t.caption != `string` || t.caption.length > 500) throw TypeError(`Table caption must be a bounded string.`);
		i = t.caption;
	}
	return {
		...i === void 0 ? {} : { caption: i },
		columns: n,
		rows: r
	};
}
function l$7(e, t, n) {
	if (typeof e != `object` || !e || Array.isArray(e) || Object.getPrototypeOf(e) !== Object.prototype) throw TypeError(`${n} must be a plain JSON object.`);
	let r = e, i = new Set(t), a = Object.keys(r).find((e) => !i.has(e));
	if (a !== void 0) throw TypeError(`${n} contains unknown member ${a}.`);
	return r;
}
function u$7(e, t, n, r) {
	if (!Array.isArray(e) || e.length > t) throw RangeError(`${r} exceed their item limit.`);
	return e.map((e) => {
		if (typeof e != `string` || e.length > n) throw TypeError(`${r} must be bounded strings.`);
		return e;
	});
}
function d$7(e, t, n, r) {
	if (typeof e != `number` || !Number.isInteger(e) || e < t || e > n) throw RangeError(`${r} must be an integer from ${t} through ${n}.`);
	return e;
}
function f$7(e, t, n) {
	if (typeof e != `number` || !Number.isFinite(e) || e < 0 || e > t) throw RangeError(`${n} must be a finite coordinate inside the drawing bounds.`);
	return e;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/recipes.js
var RECIPE_MARKER_PROPERTY = `studio.recipe`;
function recipeSelectionOperations(t, n, r) {
	let i = n.recipes.find((e) => e.id === r);
	if (i === void 0) throw Error(`Theme ${n.id} does not declare a recipe ${r}.`);
	if (i.blockType !== t.type) throw Error(`Recipe ${r} targets ${i.blockType} blocks, not ${t.type} node ${t.id}.`);
	let a = Object.entries(i.designValues).sort(([e], [t]) => e < t ? -1 : 1).map(([n, r]) => ({
		payload: {
			nodeId: t.id,
			property: n,
			value: cloneContractValue(r)
		},
		type: `studio.command/set-property`
	}));
	return a.push({
		payload: {
			nodeId: t.id,
			property: `studio.recipe`,
			value: r
		},
		type: `studio.command/set-property`
	}), a;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/validation.js
var r$4 = compileProfileSchema(blueprintSchema, { schemas: [commonSchema] });
var i$8 = /* @__PURE__ */ new WeakMap();
var a$6 = 16777216;
var o$4 = 1e6;
var s$5 = 1e4;
function validateBlueprint(e, t, n = {}) {
	let i = [], a = h$4(n.maximumDepth, 32, `maximumDepth`), o = h$4(n.maximumNodes, 5e3, `maximumNodes`), s = l$6(e, a, o);
	if (s.length > 0) return {
		diagnostics: s,
		valid: !1
	};
	let c = u$6(e);
	if (c !== void 0) return {
		diagnostics: [c],
		valid: !1
	};
	if (!r$4.validate(e)) return i.push(..._$4(r$4.errors)), {
		diagnostics: i,
		valid: !1
	};
	let d = e, f = /* @__PURE__ */ new Set(), p = S$4(d.dependencyLock.blocks, i), m = 0, g = d.roots.map((e) => ({
		depth: 1,
		node: e
	})).reverse();
	for (; g.length > 0;) {
		let e = g.pop();
		if (e === void 0) break;
		let { depth: r, node: s } = e;
		if (m += 1, m > o) break;
		if (r > a) {
			i.push(D$3(`maximum-depth`, `Node depth exceeds the configured limit of ${a}.`, s.id));
			continue;
		}
		f.has(s.id) && i.push(D$3(`duplicate-node-id`, `Node identifier ${s.id} is not unique.`, s.id)), f.add(s.id);
		let c = t.resolveRegistration(s.type, s.version);
		c === void 0 ? i.push(D$3(`block-unavailable`, `Block ${s.type}@${s.version} is not registered.`, s.id)) : (C$4(s, c.definition, c.verifiedIntegrity, p.get(w$4(s.type, s.version)), i), v$4(s, c.definition, t, i), T$4(s, c.definition, i)), E$4(s, n.fieldPaths, i);
		let l = Object.values(s.slots);
		for (let e = l.length - 1; e >= 0; --e) {
			let t = l[e];
			if (t !== void 0) for (let e = t.length - 1; e >= 0; --e) {
				let n = t[e];
				n !== void 0 && g.push({
					depth: r + 1,
					node: n
				});
			}
		}
	}
	return m > o && i.push(D$3(`maximum-nodes`, `Blueprint contains more than the configured limit of ${o} nodes.`)), {
		diagnostics: i,
		valid: i.every((e) => e.severity !== `blocking` && e.severity !== `error`)
	};
}
function l$6(e, t, n) {
	if (!g$4(e) || !Array.isArray(e.roots)) return [];
	let r = e.roots;
	if (r.length > n) return [D$3(`maximum-nodes`, `Blueprint contains more than the configured limit of ${n} nodes.`)];
	let i = /* @__PURE__ */ new WeakSet(), a = r.length, o = r.map((e) => ({
		depth: 1,
		value: e
	})).reverse();
	for (; o.length > 0;) {
		let e = o.pop();
		if (!(e === void 0 || !g$4(e.value))) {
			if (i.has(e.value)) return [D$3(`cyclic-blueprint`, `Blueprint nodes must form an acyclic JSON tree.`)];
			if (i.add(e.value), e.depth > t) return [D$3(`maximum-depth`, `Node depth exceeds the configured limit of ${t}.`, typeof e.value.id == `string` ? e.value.id : void 0)];
			if (g$4(e.value.slots)) {
				for (let t of Object.values(e.value.slots)) if (Array.isArray(t)) {
					if (a += t.length, a > n) return [D$3(`maximum-nodes`, `Blueprint contains more than the configured limit of ${n} nodes.`)];
					for (let n = t.length - 1; n >= 0; --n) o.push({
						depth: e.depth + 1,
						value: t[n]
					});
				}
			}
		}
	}
	return [];
}
function u$6(e) {
	let t = /* @__PURE__ */ new WeakSet(), n = [{
		depth: 0,
		value: e
	}], r = 0, i = 0;
	for (; n.length > 0;) {
		let e = n.pop();
		if (e === void 0) break;
		if (i += 1, i > o$4) return D$3(`maximum-json-values`, `Blueprint exceeds the fixed alpha limit of ${o$4} JSON values.`);
		if (e.depth > 64) return D$3(`maximum-value-depth`, `Blueprint JSON value depth exceeds the fixed alpha limit of 64.`);
		let { value: c } = e;
		if (c === null) r += 4;
		else if (typeof c == `boolean`) r += c ? 4 : 5;
		else if (typeof c == `number` && Number.isFinite(c)) r += String(c).length;
		else if (typeof c == `string`) r += m$5(c);
		else if (Array.isArray(c)) {
			if (!d$6(c)) return D$3(`non-json-value`, `Blueprint arrays must be dense JSON arrays.`);
			if (c.length > s$5) return D$3(`maximum-array-items`, `Blueprint arrays cannot exceed ${s$5} items.`);
			if (t.has(c)) return D$3(`cyclic-blueprint`, `Blueprint must be an acyclic JSON document.`);
			t.add(c), r += c.length + 2;
			for (let t = c.length - 1; t >= 0; --t) n.push({
				depth: e.depth + 1,
				value: c[t]
			});
		} else if (f$6(c)) {
			if (t.has(c)) return D$3(`cyclic-blueprint`, `Blueprint must be an acyclic JSON document.`);
			t.add(c);
			let i = Object.entries(c);
			if (i.length > s$5) return D$3(`maximum-object-properties`, `Blueprint objects cannot exceed ${s$5} properties.`);
			r += i.length + 2;
			for (let t = i.length - 1; t >= 0; --t) {
				let a = i[t];
				if (a === void 0) continue;
				let [o, s] = a;
				if (!p$5(o)) return D$3(`unsafe-json-member`, `Blueprint contains an unsafe JSON object member name.`);
				r += m$5(o) + 1, n.push({
					depth: e.depth + 1,
					value: s
				});
			}
		} else return D$3(`non-json-value`, `Blueprint must contain only JSON-compatible values.`);
		if (r > a$6) return D$3(`maximum-json-bytes`, `Blueprint exceeds the fixed alpha limit of ${a$6} encoded bytes.`);
	}
}
function d$6(e) {
	if (Object.getPrototypeOf(e) !== Array.prototype || Object.getOwnPropertySymbols(e).length) return !1;
	let t = Object.getOwnPropertyNames(e);
	return t.length === e.length + 1 && t[e.length] === `length` && t.slice(0, -1).every((e, t) => e === String(t));
}
function f$6(e) {
	if (!g$4(e) || Object.getOwnPropertySymbols(e).length > 0) return !1;
	let t = Object.getPrototypeOf(e);
	return t !== Object.prototype && t !== null ? !1 : Object.getOwnPropertyNames(e).length === Object.keys(e).length;
}
function p$5(e) {
	if (e.length === 0 || e.length > 200 || e === `__proto__` || e === `prototype` || e === `constructor`) return !1;
	for (let t = 0; t < e.length; t += 1) {
		let n = e.charCodeAt(t);
		if (n <= 31 || n === 127) return !1;
	}
	return !0;
}
function m$5(e) {
	let t = 2;
	for (let n = 0; n < e.length; n += 1) {
		let r = e.charCodeAt(n);
		if (r <= 31) t += 6;
		else if (r === 34 || r === 92) t += 2;
		else if (r <= 127) t += 1;
		else if (r <= 2047) t += 2;
		else if (r >= 55296 && r <= 56319) {
			let r = e.charCodeAt(n + 1);
			r >= 56320 && r <= 57343 ? (t += 4, n += 1) : t += 3;
		} else t += 3;
	}
	return t;
}
function h$4(e, t, n) {
	let r = e ?? t;
	if (!Number.isInteger(r) || r < 1) throw RangeError(`${n} must be a positive integer.`);
	return r;
}
function g$4(e) {
	return typeof e == `object` && !!e && !Array.isArray(e);
}
function _$4(e) {
	return (e ?? []).map((e) => D$3(`schema-${e.keyword}`, e.message, void 0, e.instancePath));
}
function v$4(e, t, r, a) {
	let o = `${t.type}@${t.version}`, s = i$8.get(r);
	s === void 0 && (s = /* @__PURE__ */ new Map(), i$8.set(r, s));
	let c = s.get(o);
	if (c === void 0) {
		let e = compileProfileSchema(t.propertySchema);
		s.set(o, e), c = e;
	}
	y$4(e, c, e.properties, void 0, a);
	let l = /* @__PURE__ */ new Map();
	for (let t of Object.keys(e.responsive ?? {}).sort(O$4)) {
		let n = e.responsive?.[t];
		if (n !== void 0) for (let r of Object.keys(n).sort(O$4)) {
			let i = n[r];
			if (i === void 0) continue;
			let a = l.get(r) ?? { ...e.properties };
			a[t] = i, l.set(r, a);
		}
	}
	for (let t of [...l.keys()].sort(O$4)) {
		let n = l.get(t);
		n !== void 0 && y$4(e, c, n, t, a);
	}
}
function y$4(e, t, n, r, i) {
	t.validate(n) || i.push(..._$4(t.errors).map((t) => ({
		...t,
		code: `studio.validation/block-properties-${t.code.split(`/`).at(-1) ?? `invalid`}`,
		location: {
			...t.location,
			nodeId: e.id,
			...r === void 0 ? {} : { jsonPointer: b$4(t.location?.jsonPointer, r) }
		}
	})));
}
function b$4(e, t) {
	let n = e?.split(`/`).slice(1) ?? [], r = n.shift();
	return r === void 0 ? `/responsive/${x$4(t)}` : [
		``,
		`responsive`,
		r,
		x$4(t),
		...n
	].join(`/`);
}
function x$4(e) {
	return e.replaceAll(`~`, `~0`).replaceAll(`/`, `~1`);
}
function S$4(e, t) {
	let n = /* @__PURE__ */ new Map();
	for (let r of e) {
		let e = w$4(r.type, r.version);
		n.has(e) ? t.push(D$3(`block-lock-duplicate`, `Blueprint dependency lock repeats block ${r.type}@${r.version}.`)) : n.set(e, r);
	}
	return n;
}
function C$4(e, t, n, r, i) {
	if (r === void 0) {
		i.push(D$3(`block-lock-missing`, `Block ${e.type}@${e.version} is absent from the Blueprint dependency lock.`, e.id));
		return;
	}
	r.revision !== t.revision && i.push(D$3(`block-lock-revision-mismatch`, `Block ${e.type}@${e.version} resolves to revision ${t.revision}, not locked revision ${r.revision}.`, e.id)), r.integrity !== void 0 && n === void 0 ? i.push(D$3(`block-lock-integrity-unverified`, `Block ${e.type}@${e.version} has a locked integrity value that the registry cannot verify.`, e.id)) : r.integrity !== void 0 && r.integrity !== n && i.push(D$3(`block-lock-integrity-mismatch`, `Block ${e.type}@${e.version} does not match its locked integrity value.`, e.id));
}
function w$4(e, t) {
	return `${e}@${t}`;
}
function T$4(e, t, n) {
	let r = new Map(t.slots.map((e) => [e.id, e]));
	for (let [t, i] of Object.entries(e.slots)) {
		let a = r.get(t);
		if (a === void 0) {
			n.push(D$3(`slot-unknown`, `Slot ${t} is not declared by ${e.type}.`, e.id));
			continue;
		}
		i.length > a.maximum && n.push(D$3(`slot-maximum`, `Slot ${t} accepts at most ${a.maximum} children.`, e.id));
		for (let e of i) a.accepts.types !== void 0 && !a.accepts.types.includes(e.type) && n.push(D$3(`slot-rejects-type`, `Slot ${t} does not accept ${e.type}.`, e.id));
	}
	for (let t of r.values()) (Object.hasOwn(e.slots, t.id) ? e.slots[t.id]?.length ?? 0 : 0) < t.minimum && n.push(D$3(`slot-minimum`, `Slot ${t.id} requires at least ${t.minimum} children.`, e.id));
}
function E$4(e, t, n) {
	if (t !== void 0) for (let r of Object.values(e.bindings)) {
		if (r.source.kind !== `entry-field`) continue;
		let i = r.source.fieldPath.join(`.`);
		t.has(i) || n.push(D$3(`field-unavailable`, `Field ${i} is not available to this Studio configuration.`, e.id));
	}
}
function D$3(e, t, n, r) {
	let i = {
		code: `studio.validation/${e}`,
		message: {
			defaultMessage: t,
			key: `studio.validation/${e}`
		},
		severity: `error`
	};
	return (n !== void 0 || r !== void 0) && (i.location = {}, n !== void 0 && (i.location.nodeId = n), r !== void 0 && (i.location.jsonPointer = r)), i;
}
function O$4(e, t) {
	return e < t ? -1 : +(e > t);
}
var en_default = {
	$schema: "https://schemas.kumwe.org/studio/v1/authoring-message-catalog.schema.json",
	kind: "authoring-message-catalog",
	contractVersion: "0.1-draft",
	catalogVersion: "1.7.0",
	locale: "en",
	messages: /* @__PURE__ */ JSON.parse("{\"studio.contextual/add-field\":{\"defaultMessage\":\"Add field\",\"parameters\":[]},\"studio.contextual/add-typed-field\":{\"defaultMessage\":\"Add typed field\",\"parameters\":[]},\"studio.contextual/all-saved\":{\"defaultMessage\":\"All local changes saved\",\"parameters\":[]},\"studio.contextual/announce-field-added\":{\"defaultMessage\":\"Field {label} added.\",\"parameters\":[\"label\"]},\"studio.contextual/announce-mode\":{\"defaultMessage\":\"{mode} mode selected.\",\"parameters\":[\"mode\"]},\"studio.contextual/announce-presentation\":{\"defaultMessage\":\"{presentation} presentation selected.\",\"parameters\":[\"presentation\"]},\"studio.contextual/announce-save-requested\":{\"defaultMessage\":\"{outcome} requested. The host must confirm and accept it.\",\"parameters\":[\"outcome\"]},\"studio.contextual/authoring-mode\":{\"defaultMessage\":\"Authoring mode\",\"parameters\":[]},\"studio.contextual/bind-in-blueprint\":{\"defaultMessage\":\"Bind in Blueprint\",\"parameters\":[]},\"studio.contextual/binding-heading\":{\"defaultMessage\":\"Field binding\",\"parameters\":[]},\"studio.contextual/binding-help\":{\"defaultMessage\":\"Choose Blueprint mode, select a block, and bind one of these exact Model fields to a compatible block port. Studio keeps the Model definition, Blueprint binding, and Entry value separate while they remain in this resource session.\",\"parameters\":[]},\"studio.contextual/blueprint-coordinate-mismatch\":{\"defaultMessage\":\"The session coordinates do not identify the supplied exact Blueprint revision.\",\"parameters\":[]},\"studio.contextual/blueprint-model-mismatch\":{\"defaultMessage\":\"The Blueprint does not lock the supplied exact Model revision.\",\"parameters\":[]},\"studio.contextual/cardinality\":{\"defaultMessage\":\"Cardinality\",\"parameters\":[]},\"studio.contextual/cardinality-many\":{\"defaultMessage\":\"Multiple values\",\"parameters\":[]},\"studio.contextual/cardinality-one\":{\"defaultMessage\":\"One value\",\"parameters\":[]},\"studio.contextual/choose-start\":{\"defaultMessage\":\"Choose how to start\",\"parameters\":[]},\"studio.contextual/choose-value\":{\"defaultMessage\":\"Choose a value\",\"parameters\":[]},\"studio.contextual/collection-item-type\":{\"defaultMessage\":\"Collection item type (used only for a collection field)\",\"parameters\":[]},\"studio.contextual/configuration-generation-mismatch\":{\"defaultMessage\":\"The Studio configuration and contextual session generations do not match.\",\"parameters\":[]},\"studio.contextual/control-unavailable\":{\"defaultMessage\":\"This field control is unavailable.\",\"parameters\":[]},\"studio.contextual/diagnostic-count\":{\"defaultMessage\":\"{count} diagnostics\",\"parameters\":[\"count\"]},\"studio.contextual/diagnostic-count-one\":{\"defaultMessage\":\"1 diagnostic\",\"parameters\":[]},\"studio.contextual/dirty-artifacts\":{\"defaultMessage\":\"Model {model} · Blueprint {blueprint} · Content {entry}\",\"parameters\":[\"blueprint\",\"entry\",\"model\"]},\"studio.contextual/entry-coordinate-mismatch\":{\"defaultMessage\":\"The session coordinates do not identify the supplied exact Entry revision.\",\"parameters\":[]},\"studio.contextual/entry-model-mismatch\":{\"defaultMessage\":\"The Entry does not lock the supplied exact Model revision.\",\"parameters\":[]},\"studio.contextual/entry-resource-mismatch\":{\"defaultMessage\":\"The existing-item target does not identify the supplied Entry.\",\"parameters\":[]},\"studio.contextual/enum-values\":{\"defaultMessage\":\"Enum choices (one identifier per line; used only for an enum field)\",\"parameters\":[]},\"studio.contextual/field-identifier\":{\"defaultMessage\":\"Field identifier\",\"parameters\":[]},\"studio.contextual/field-kind-boolean\":{\"defaultMessage\":\"Boolean\",\"parameters\":[]},\"studio.contextual/field-kind-collection\":{\"defaultMessage\":\"Collection\",\"parameters\":[]},\"studio.contextual/field-kind-date\":{\"defaultMessage\":\"Date\",\"parameters\":[]},\"studio.contextual/field-kind-date-time\":{\"defaultMessage\":\"Date and time\",\"parameters\":[]},\"studio.contextual/field-kind-decimal\":{\"defaultMessage\":\"Decimal\",\"parameters\":[]},\"studio.contextual/field-kind-enum\":{\"defaultMessage\":\"Choice\",\"parameters\":[]},\"studio.contextual/field-kind-integer\":{\"defaultMessage\":\"Integer\",\"parameters\":[]},\"studio.contextual/field-kind-media\":{\"defaultMessage\":\"Media\",\"parameters\":[]},\"studio.contextual/field-kind-money\":{\"defaultMessage\":\"Money\",\"parameters\":[]},\"studio.contextual/field-kind-object\":{\"defaultMessage\":\"Object\",\"parameters\":[]},\"studio.contextual/field-kind-resource\":{\"defaultMessage\":\"Resource\",\"parameters\":[]},\"studio.contextual/field-kind-rich-text\":{\"defaultMessage\":\"Rich text\",\"parameters\":[]},\"studio.contextual/field-kind-string\":{\"defaultMessage\":\"Text\",\"parameters\":[]},\"studio.contextual/field-label\":{\"defaultMessage\":\"Label\",\"parameters\":[]},\"studio.contextual/field-optional\":{\"defaultMessage\":\"optional\",\"parameters\":[]},\"studio.contextual/field-required\":{\"defaultMessage\":\"required\",\"parameters\":[]},\"studio.contextual/field-summary\":{\"defaultMessage\":\"{path} · {kind} · {cardinality} · {requirement}\",\"parameters\":[\"cardinality\",\"kind\",\"path\",\"requirement\"]},\"studio.contextual/field-type\":{\"defaultMessage\":\"Field type\",\"parameters\":[]},\"studio.contextual/json-array\":{\"defaultMessage\":\"JSON array\",\"parameters\":[]},\"studio.contextual/json-value\":{\"defaultMessage\":\"Canonical JSON value\",\"parameters\":[]},\"studio.contextual/load-more-types\":{\"defaultMessage\":\"Load more types\",\"parameters\":[]},\"studio.contextual/localized\":{\"defaultMessage\":\"Localized\",\"parameters\":[]},\"studio.contextual/mode-blueprint\":{\"defaultMessage\":\"Blueprint\",\"parameters\":[]},\"studio.contextual/mode-content\":{\"defaultMessage\":\"Content\",\"parameters\":[]},\"studio.contextual/mode-model\":{\"defaultMessage\":\"Model\",\"parameters\":[]},\"studio.contextual/model-coordinate-mismatch\":{\"defaultMessage\":\"The session coordinates do not identify the supplied exact Model revision.\",\"parameters\":[]},\"studio.contextual/model-fields\":{\"defaultMessage\":\"Model fields\",\"parameters\":[]},\"studio.contextual/model-status\":{\"defaultMessage\":\"This Model is {status}. The host must open a draft successor before fields can change.\",\"parameters\":[\"status\"]},\"studio.contextual/no-authorable-fields\":{\"defaultMessage\":\"This Model exposes no authorable fields.\",\"parameters\":[]},\"studio.contextual/no-canonical-value\":{\"defaultMessage\":\"No canonical value is available for this field yet.\",\"parameters\":[]},\"studio.contextual/no-fields\":{\"defaultMessage\":\"No fields have been defined.\",\"parameters\":[]},\"studio.contextual/presentation-fullscreen\":{\"defaultMessage\":\"Fullscreen\",\"parameters\":[]},\"studio.contextual/presentation-inline\":{\"defaultMessage\":\"Inline\",\"parameters\":[]},\"studio.contextual/presentation-maximized\":{\"defaultMessage\":\"Maximized\",\"parameters\":[]},\"studio.contextual/presentation-minimized\":{\"defaultMessage\":\"Minimized\",\"parameters\":[]},\"studio.contextual/presentation-not-authorized\":{\"defaultMessage\":\"The current presentation is not authorized by this contextual session.\",\"parameters\":[]},\"studio.contextual/required\":{\"defaultMessage\":\"Required\",\"parameters\":[]},\"studio.contextual/return\":{\"defaultMessage\":\"Return to {destination}\",\"parameters\":[\"destination\"]},\"studio.contextual/return-destination\":{\"defaultMessage\":\"the host\",\"parameters\":[]},\"studio.contextual/save-as-new-type\":{\"defaultMessage\":\"Save as new type\",\"parameters\":[]},\"studio.contextual/save-item\":{\"defaultMessage\":\"Save item\",\"parameters\":[]},\"studio.contextual/save-new-type-version\":{\"defaultMessage\":\"Save new type version\",\"parameters\":[]},\"studio.contextual/save-outcome\":{\"defaultMessage\":\"Save outcome\",\"parameters\":[]},\"studio.contextual/save-plan-help\":{\"defaultMessage\":\"The authoritative host will plan and show affected artifacts and consequences before confirmation.\",\"parameters\":[]},\"studio.contextual/search\":{\"defaultMessage\":\"Search\",\"parameters\":[]},\"studio.contextual/session-generation-missing\":{\"defaultMessage\":\"The contextual session has no authoritative generation.\",\"parameters\":[]},\"studio.contextual/start\":{\"defaultMessage\":\"Start Studio\",\"parameters\":[]},\"studio.contextual/start-blank\":{\"defaultMessage\":\"Blank start\",\"parameters\":[]},\"studio.contextual/start-blank-help\":{\"defaultMessage\":\"Create a new layout and content structure for this resource.\",\"parameters\":[]},\"studio.contextual/start-existing\":{\"defaultMessage\":\"Existing item\",\"parameters\":[]},\"studio.contextual/start-from-type\":{\"defaultMessage\":\"Reusable type · {type}\",\"parameters\":[\"type\"]},\"studio.contextual/start-source\":{\"defaultMessage\":\"Starting point\",\"parameters\":[]},\"studio.contextual/starting\":{\"defaultMessage\":\"Starting…\",\"parameters\":[]},\"studio.contextual/state-changed\":{\"defaultMessage\":\"changed\",\"parameters\":[]},\"studio.contextual/state-unchanged\":{\"defaultMessage\":\"unchanged\",\"parameters\":[]},\"studio.contextual/type-blueprint-mismatch\":{\"defaultMessage\":\"The reusable type does not identify the supplied exact Blueprint revision.\",\"parameters\":[]},\"studio.contextual/type-coordinate-mismatch\":{\"defaultMessage\":\"The session coordinates do not identify the supplied exact reusable type version.\",\"parameters\":[]},\"studio.contextual/type-model-mismatch\":{\"defaultMessage\":\"The reusable type does not identify the supplied exact Model revision.\",\"parameters\":[]},\"studio.contextual/type-search\":{\"defaultMessage\":\"Find a reusable type\",\"parameters\":[]},\"studio.contextual/types-empty\":{\"defaultMessage\":\"No authorized reusable types match this search.\",\"parameters\":[]},\"studio.contextual/types-loading\":{\"defaultMessage\":\"Loading authorized reusable types…\",\"parameters\":[]},\"studio.contextual/unavailable\":{\"defaultMessage\":\"Load one authorized content resource to open Studio.\",\"parameters\":[]},\"studio.contextual/unsaved\":{\"defaultMessage\":\"Unsaved changes\",\"parameters\":[]},\"studio.contextual/value-coordinate\":{\"defaultMessage\":\"Values belong to {entry}; they are not part of the reusable type.\",\"parameters\":[\"entry\"]},\"studio.contextual/values-heading\":{\"defaultMessage\":\"Content values\",\"parameters\":[]},\"studio.contextual/workspace-size\":{\"defaultMessage\":\"Workspace size\",\"parameters\":[]},\"studio.shell/announce-binding-removed\":{\"defaultMessage\":\"Removed the {port} binding\",\"parameters\":[\"port\"]},\"studio.shell/announce-binding-set\":{\"defaultMessage\":\"Set the {port} binding\",\"parameters\":[\"port\"]},\"studio.shell/announce-canvas-mode\":{\"defaultMessage\":\"Canvas mode: {state}\",\"parameters\":[\"state\"]},\"studio.shell/announce-command-failed\":{\"defaultMessage\":\"Command failed: {message}\",\"parameters\":[\"message\"]},\"studio.shell/announce-conflict\":{\"defaultMessage\":\"The change was rejected: {message} The document is unchanged; refresh the session or undo before retrying.\",\"parameters\":[\"message\"]},\"studio.shell/announce-deleted\":{\"defaultMessage\":\"Deleted {label} block\",\"parameters\":[\"label\"]},\"studio.shell/announce-drag-cancelled\":{\"defaultMessage\":\"Reorder cancelled. {label} kept its position.\",\"parameters\":[\"label\"]},\"studio.shell/announce-dropped\":{\"defaultMessage\":\"Moved {label} to position {position} of {count}\",\"parameters\":[\"count\",\"label\",\"position\"]},\"studio.shell/announce-duplicated\":{\"defaultMessage\":\"Duplicated {label}\",\"parameters\":[\"label\"]},\"studio.shell/announce-edit-cancelled\":{\"defaultMessage\":\"Edit cancelled. {property} kept its value.\",\"parameters\":[\"property\"]},\"studio.shell/announce-field-bound\":{\"defaultMessage\":\"Bound {port} to the {field} model field\",\"parameters\":[\"field\",\"port\"]},\"studio.shell/announce-inheritance-reset\":{\"defaultMessage\":\"Reset every responsive override for {property}; all viewports now inherit the base value\",\"parameters\":[\"property\"]},\"studio.shell/announce-inserted\":{\"defaultMessage\":\"Inserted {label}\",\"parameters\":[\"label\"]},\"studio.shell/announce-invalid-value\":{\"defaultMessage\":\"The {label} value is not valid JSON. Nothing was changed.\",\"parameters\":[\"label\"]},\"studio.shell/announce-moved-down\":{\"defaultMessage\":\"Moved {label} down\",\"parameters\":[\"label\"]},\"studio.shell/announce-moved-to\":{\"defaultMessage\":\"Moved {label} to {destination}\",\"parameters\":[\"destination\",\"label\"]},\"studio.shell/announce-moved-up\":{\"defaultMessage\":\"Moved {label} up\",\"parameters\":[\"label\"]},\"studio.shell/announce-name-required\":{\"defaultMessage\":\"Enter a name before applying the change.\",\"parameters\":[]},\"studio.shell/announce-override-removed\":{\"defaultMessage\":\"Removed the {property} override for the {viewport} viewport\",\"parameters\":[\"property\",\"viewport\"]},\"studio.shell/announce-override-set\":{\"defaultMessage\":\"Set the {property} override for the {viewport} viewport\",\"parameters\":[\"property\",\"viewport\"]},\"studio.shell/announce-pattern-applied\":{\"defaultMessage\":\"Applied the {pattern} pattern\",\"parameters\":[\"pattern\"]},\"studio.shell/announce-preview-reloaded\":{\"defaultMessage\":\"The preview reloaded ({reason}). The document is unchanged.\",\"parameters\":[\"reason\"]},\"studio.shell/announce-preview-torn-down\":{\"defaultMessage\":\"The preview closed ({reason}). The document is unchanged.\",\"parameters\":[\"reason\"]},\"studio.shell/announce-property-set\":{\"defaultMessage\":\"Set {property}\",\"parameters\":[\"property\"]},\"studio.shell/announce-property-unset\":{\"defaultMessage\":\"Unset {property}\",\"parameters\":[\"property\"]},\"studio.shell/announce-recipe-applied\":{\"defaultMessage\":\"Applied the {recipe} recipe\",\"parameters\":[\"recipe\"]},\"studio.shell/announce-redid\":{\"defaultMessage\":\"Redid change\",\"parameters\":[]},\"studio.shell/announce-restored\":{\"defaultMessage\":\"Restored {label} block\",\"parameters\":[\"label\"]},\"studio.shell/announce-selection-cleared\":{\"defaultMessage\":\"Selection cleared\",\"parameters\":[]},\"studio.shell/announce-size-role-invalid\":{\"defaultMessage\":\"The {axis} role must be a lower-case identifier such as half or full-width. Nothing was changed.\",\"parameters\":[\"axis\"]},\"studio.shell/announce-size-role-removed\":{\"defaultMessage\":\"Removed the {axis} role\",\"parameters\":[\"axis\"]},\"studio.shell/announce-size-role-removed-viewport\":{\"defaultMessage\":\"Removed the {axis} role for the {viewport} viewport\",\"parameters\":[\"axis\",\"viewport\"]},\"studio.shell/announce-size-role-set\":{\"defaultMessage\":\"Set the {axis} role to {role}\",\"parameters\":[\"axis\",\"role\"]},\"studio.shell/announce-size-role-set-viewport\":{\"defaultMessage\":\"Set the {axis} role to {role} for the {viewport} viewport\",\"parameters\":[\"axis\",\"role\",\"viewport\"]},\"studio.shell/announce-undid\":{\"defaultMessage\":\"Undid change\",\"parameters\":[]},\"studio.shell/announce-viewport-changed\":{\"defaultMessage\":\"Previewing the {label} viewport\",\"parameters\":[\"label\"]},\"studio.shell/block-actions\":{\"defaultMessage\":\"Block actions\",\"parameters\":[]},\"studio.shell/breadcrumb-label\":{\"defaultMessage\":\"Selection path\",\"parameters\":[]},\"studio.shell/canvas-edit-toggle\":{\"defaultMessage\":\"Select and move rendered blocks\",\"parameters\":[]},\"studio.shell/canvas-empty\":{\"defaultMessage\":\"Choose a block to begin composing.\",\"parameters\":[]},\"studio.shell/canvas-label\":{\"defaultMessage\":\"Blueprint structure\",\"parameters\":[]},\"studio.shell/canvas-mode-editing\":{\"defaultMessage\":\"selecting and moving blocks\",\"parameters\":[]},\"studio.shell/canvas-mode-interacting\":{\"defaultMessage\":\"interacting with the rendered preview\",\"parameters\":[]},\"studio.shell/command-apply-pattern\":{\"defaultMessage\":\"Apply pattern {pattern}\",\"parameters\":[\"pattern\"]},\"studio.shell/command-clear-selection\":{\"defaultMessage\":\"Clear selection\",\"parameters\":[]},\"studio.shell/command-insert\":{\"defaultMessage\":\"Insert {label}\",\"parameters\":[\"label\"]},\"studio.shell/command-move-to\":{\"defaultMessage\":\"Move to {destination}\",\"parameters\":[\"destination\"]},\"studio.shell/command-palette-empty\":{\"defaultMessage\":\"No commands match the filter.\",\"parameters\":[]},\"studio.shell/command-palette-hint\":{\"defaultMessage\":\"Type to filter commands. Arrow Down moves into the results, Arrow Up returns to the filter, Enter runs a command, Escape closes.\",\"parameters\":[]},\"studio.shell/command-palette-input-label\":{\"defaultMessage\":\"Filter commands\",\"parameters\":[]},\"studio.shell/command-palette-label\":{\"defaultMessage\":\"Command palette\",\"parameters\":[]},\"studio.shell/command-palette-results-label\":{\"defaultMessage\":\"Matching commands\",\"parameters\":[]},\"studio.shell/command-palette-toggle\":{\"defaultMessage\":\"Commands\",\"parameters\":[]},\"studio.shell/delete\":{\"defaultMessage\":\"Delete\",\"parameters\":[]},\"studio.shell/diagnostics-empty\":{\"defaultMessage\":\"No issues\",\"parameters\":[]},\"studio.shell/diagnostics-heading\":{\"defaultMessage\":\"Diagnostics\",\"parameters\":[]},\"studio.shell/document-roots\":{\"defaultMessage\":\"document roots\",\"parameters\":[]},\"studio.shell/drag-drop-position\":{\"defaultMessage\":\"Moving {label} to position {position} of {count}\",\"parameters\":[\"count\",\"label\",\"position\"]},\"studio.shell/duplicate\":{\"defaultMessage\":\"Duplicate\",\"parameters\":[]},\"studio.shell/history-label\":{\"defaultMessage\":\"History\",\"parameters\":[]},\"studio.shell/inspector-add-override\":{\"defaultMessage\":\"Add override\",\"parameters\":[]},\"studio.shell/inspector-add-override-name-label\":{\"defaultMessage\":\"Override property name\",\"parameters\":[]},\"studio.shell/inspector-add-override-value-label\":{\"defaultMessage\":\"Override value as JSON\",\"parameters\":[]},\"studio.shell/inspector-add-property\":{\"defaultMessage\":\"Add property\",\"parameters\":[]},\"studio.shell/inspector-add-property-name-label\":{\"defaultMessage\":\"New property name\",\"parameters\":[]},\"studio.shell/inspector-add-property-value-label\":{\"defaultMessage\":\"New property value as JSON\",\"parameters\":[]},\"studio.shell/inspector-binding-accepts\":{\"defaultMessage\":\"Accepts {cardinality} {value-type} value\",\"parameters\":[\"cardinality\",\"value-type\"]},\"studio.shell/inspector-binding-control-label\":{\"defaultMessage\":\"Declared {control} control for {field}\",\"parameters\":[\"control\",\"field\"]},\"studio.shell/inspector-binding-control-preview\":{\"defaultMessage\":\"Control preview\",\"parameters\":[]},\"studio.shell/inspector-binding-control-unavailable\":{\"defaultMessage\":\"The declared {control} control requires a host field-adapter contribution.\",\"parameters\":[\"control\"]},\"studio.shell/inspector-binding-control-undeclared\":{\"defaultMessage\":\"This field declares no authoring control.\",\"parameters\":[]},\"studio.shell/inspector-binding-field-placeholder\":{\"defaultMessage\":\"Choose a model field\",\"parameters\":[]},\"studio.shell/inspector-binding-invalid\":{\"defaultMessage\":\"This binding no longer resolves and requires migration.\",\"parameters\":[]},\"studio.shell/inspector-binding-model\":{\"defaultMessage\":\"Fields from locked model {model}\",\"parameters\":[\"model\"]},\"studio.shell/inspector-binding-model-mismatch\":{\"defaultMessage\":\"The projected model does not match the Blueprint lock. Binding choices are disabled; see diagnostics.\",\"parameters\":[]},\"studio.shell/inspector-binding-model-unavailable\":{\"defaultMessage\":\"The session advertises model reads, but no active model projection is loaded. Binding choices are disabled.\",\"parameters\":[]},\"studio.shell/inspector-binding-no-compatible-fields\":{\"defaultMessage\":\"No compatible model fields\",\"parameters\":[]},\"studio.shell/inspector-binding-non-field-source\":{\"defaultMessage\":\"This port uses a non-field source. Choosing a model field replaces that source explicitly.\",\"parameters\":[]},\"studio.shell/inspector-binding-port-label\":{\"defaultMessage\":\"Binding port name\",\"parameters\":[]},\"studio.shell/inspector-binding-required\":{\"defaultMessage\":\" (required)\",\"parameters\":[]},\"studio.shell/inspector-binding-value-label\":{\"defaultMessage\":\"Binding value as JSON\",\"parameters\":[]},\"studio.shell/inspector-bindings-empty\":{\"defaultMessage\":\"No bindings\",\"parameters\":[]},\"studio.shell/inspector-bindings-heading\":{\"defaultMessage\":\"Bindings\",\"parameters\":[]},\"studio.shell/inspector-design-heading\":{\"defaultMessage\":\"Design\",\"parameters\":[]},\"studio.shell/inspector-design-placeholder\":{\"defaultMessage\":\"Choose a token\",\"parameters\":[]},\"studio.shell/inspector-design-unset\":{\"defaultMessage\":\"Remove\",\"parameters\":[]},\"studio.shell/inspector-empty\":{\"defaultMessage\":\"Select a block to inspect its contract.\",\"parameters\":[]},\"studio.shell/inspector-heading\":{\"defaultMessage\":\"Inspector\",\"parameters\":[]},\"studio.shell/inspector-hint\":{\"defaultMessage\":\"Inputs hold JSON values. Enter applies the edit, Escape reverts it.\",\"parameters\":[]},\"studio.shell/inspector-identifier\":{\"defaultMessage\":\"Identifier\",\"parameters\":[]},\"studio.shell/inspector-layout-axis-block\":{\"defaultMessage\":\"Block size\",\"parameters\":[]},\"studio.shell/inspector-layout-axis-inline\":{\"defaultMessage\":\"Inline size\",\"parameters\":[]},\"studio.shell/inspector-layout-base-none\":{\"defaultMessage\":\"Base: none\",\"parameters\":[]},\"studio.shell/inspector-layout-base-role\":{\"defaultMessage\":\"Base: {role}\",\"parameters\":[\"role\"]},\"studio.shell/inspector-layout-fallback-hint\":{\"defaultMessage\":\"No theme size-role vocabulary is available. Enter a lower-case role identifier; Enter applies it, Escape cancels.\",\"parameters\":[]},\"studio.shell/inspector-layout-heading\":{\"defaultMessage\":\"Layout\",\"parameters\":[]},\"studio.shell/inspector-layout-no-roles\":{\"defaultMessage\":\"The active theme declares no size roles, so none can be assigned.\",\"parameters\":[]},\"studio.shell/inspector-layout-role-label-base\":{\"defaultMessage\":\"{axis} role (base)\",\"parameters\":[\"axis\"]},\"studio.shell/inspector-layout-role-label-viewport\":{\"defaultMessage\":\"{axis} role override for the {viewport} viewport\",\"parameters\":[\"axis\",\"viewport\"]},\"studio.shell/inspector-layout-role-placeholder\":{\"defaultMessage\":\"Choose a role\",\"parameters\":[]},\"studio.shell/inspector-layout-unset\":{\"defaultMessage\":\"Remove\",\"parameters\":[]},\"studio.shell/inspector-layout-unset-label-base\":{\"defaultMessage\":\"Remove the {axis} base role\",\"parameters\":[\"axis\"]},\"studio.shell/inspector-layout-unset-label-viewport\":{\"defaultMessage\":\"Remove the {axis} role override for the {viewport} viewport\",\"parameters\":[\"axis\",\"viewport\"]},\"studio.shell/inspector-override-value-label\":{\"defaultMessage\":\"Override of {property} for the {viewport} viewport as JSON\",\"parameters\":[\"property\",\"viewport\"]},\"studio.shell/inspector-overrides-empty\":{\"defaultMessage\":\"No overrides for the {viewport} viewport\",\"parameters\":[\"viewport\"]},\"studio.shell/inspector-overrides-heading\":{\"defaultMessage\":\"Overrides for the {viewport} viewport\",\"parameters\":[\"viewport\"]},\"studio.shell/inspector-properties\":{\"defaultMessage\":\"Properties\",\"parameters\":[]},\"studio.shell/inspector-properties-empty\":{\"defaultMessage\":\"No properties\",\"parameters\":[]},\"studio.shell/inspector-property-value-label\":{\"defaultMessage\":\"Value of {property} as JSON\",\"parameters\":[\"property\"]},\"studio.shell/inspector-provenance-base\":{\"defaultMessage\":\"Base value\",\"parameters\":[]},\"studio.shell/inspector-provenance-inherited\":{\"defaultMessage\":\"Inherited from base: {value}\",\"parameters\":[\"value\"]},\"studio.shell/inspector-provenance-inherited-none\":{\"defaultMessage\":\"Inherited from base: none\",\"parameters\":[]},\"studio.shell/inspector-provenance-overridden\":{\"defaultMessage\":\"Overridden for the {viewport} viewport: {value}\",\"parameters\":[\"value\",\"viewport\"]},\"studio.shell/inspector-read-only\":{\"defaultMessage\":\"Editing is disabled because this session is read-only.\",\"parameters\":[]},\"studio.shell/inspector-recipe-label\":{\"defaultMessage\":\"Recipe\",\"parameters\":[]},\"studio.shell/inspector-recipe-placeholder\":{\"defaultMessage\":\"Choose a recipe\",\"parameters\":[]},\"studio.shell/inspector-recipes-heading\":{\"defaultMessage\":\"Recipes\",\"parameters\":[]},\"studio.shell/inspector-remove-binding\":{\"defaultMessage\":\"Remove\",\"parameters\":[]},\"studio.shell/inspector-remove-binding-label\":{\"defaultMessage\":\"Remove the {port} binding\",\"parameters\":[\"port\"]},\"studio.shell/inspector-remove-override\":{\"defaultMessage\":\"Remove\",\"parameters\":[]},\"studio.shell/inspector-remove-override-label\":{\"defaultMessage\":\"Remove the {property} override for the {viewport} viewport\",\"parameters\":[\"property\",\"viewport\"]},\"studio.shell/inspector-reset-inheritance\":{\"defaultMessage\":\"Reset all viewport overrides\",\"parameters\":[]},\"studio.shell/inspector-set-binding\":{\"defaultMessage\":\"Set binding\",\"parameters\":[]},\"studio.shell/inspector-type\":{\"defaultMessage\":\"Type\",\"parameters\":[]},\"studio.shell/inspector-unset\":{\"defaultMessage\":\"Unset\",\"parameters\":[]},\"studio.shell/inspector-unset-label\":{\"defaultMessage\":\"Unset {property}\",\"parameters\":[\"property\"]},\"studio.shell/move-destination-label\":{\"defaultMessage\":\"Move block to another position or slot\",\"parameters\":[]},\"studio.shell/move-destination-option\":{\"defaultMessage\":\"{collection}, position {position} of {count}\",\"parameters\":[\"collection\",\"count\",\"position\"]},\"studio.shell/move-destination-placeholder\":{\"defaultMessage\":\"Choose a destination\",\"parameters\":[]},\"studio.shell/move-down\":{\"defaultMessage\":\"Move down\",\"parameters\":[]},\"studio.shell/move-slot-collection\":{\"defaultMessage\":\"{parent}: {slot} slot\",\"parameters\":[\"parent\",\"slot\"]},\"studio.shell/move-up\":{\"defaultMessage\":\"Move up\",\"parameters\":[]},\"studio.shell/outline-empty\":{\"defaultMessage\":\"The outline lists blocks once the document has content.\",\"parameters\":[]},\"studio.shell/outline-heading\":{\"defaultMessage\":\"Outline\",\"parameters\":[]},\"studio.shell/outline-hint\":{\"defaultMessage\":\"Arrow keys move focus. Alt+Arrow moves the block. Delete removes it. Ctrl+D or Cmd+D duplicates it.\",\"parameters\":[]},\"studio.shell/outline-slot\":{\"defaultMessage\":\"Slot: {slot}\",\"parameters\":[\"slot\"]},\"studio.shell/palette-heading\":{\"defaultMessage\":\"Blocks\",\"parameters\":[]},\"studio.shell/palette-label\":{\"defaultMessage\":\"Block palette\",\"parameters\":[]},\"studio.shell/patterns-heading\":{\"defaultMessage\":\"Patterns\",\"parameters\":[]},\"studio.shell/preview-closed\":{\"defaultMessage\":\"Preview is disconnected. Editing remains available.\",\"parameters\":[]},\"studio.shell/preview-connecting\":{\"defaultMessage\":\"Preview is connecting.\",\"parameters\":[]},\"studio.shell/preview-current\":{\"defaultMessage\":\"Preview is current.\",\"parameters\":[]},\"studio.shell/preview-heading\":{\"defaultMessage\":\"Preview\",\"parameters\":[]},\"studio.shell/preview-label\":{\"defaultMessage\":\"Rendered preview\",\"parameters\":[]},\"studio.shell/preview-rendering\":{\"defaultMessage\":\"Preview is updating.\",\"parameters\":[]},\"studio.shell/preview-stale\":{\"defaultMessage\":\"Preview is stale. Editing remains available.\",\"parameters\":[]},\"studio.shell/preview-unavailable\":{\"defaultMessage\":\"Preview is unavailable for this session. Editing remains available.\",\"parameters\":[]},\"studio.shell/redo\":{\"defaultMessage\":\"Redo\",\"parameters\":[]},\"studio.shell/restore-last-deleted\":{\"defaultMessage\":\"Restore last deleted block\",\"parameters\":[]},\"studio.shell/save-state-saved\":{\"defaultMessage\":\"Saved\",\"parameters\":[]},\"studio.shell/save-state-unsaved\":{\"defaultMessage\":\"Unsaved changes\",\"parameters\":[]},\"studio.shell/severity-blocking\":{\"defaultMessage\":\"Blocking\",\"parameters\":[]},\"studio.shell/severity-error\":{\"defaultMessage\":\"Error\",\"parameters\":[]},\"studio.shell/severity-information\":{\"defaultMessage\":\"Information\",\"parameters\":[]},\"studio.shell/severity-warning\":{\"defaultMessage\":\"Warning\",\"parameters\":[]},\"studio.shell/status-label\":{\"defaultMessage\":\"Status\",\"parameters\":[]},\"studio.shell/undo\":{\"defaultMessage\":\"Undo\",\"parameters\":[]},\"studio.shell/unresolved-block\":{\"defaultMessage\":\"(unresolved)\",\"parameters\":[]},\"studio.shell/viewport-label\":{\"defaultMessage\":\"Preview width\",\"parameters\":[]},\"studio.shell/visual-drop-target\":{\"defaultMessage\":\"Moving {label} to {destination}\",\"parameters\":[\"destination\",\"label\"]},\"studio.standalone/change-local\":{\"defaultMessage\":\"{artifact} changed in this browser session only.\",\"parameters\":[\"artifact\"]},\"studio.standalone/current-in-memory-draft\":{\"defaultMessage\":\"Current in-memory draft\",\"parameters\":[]},\"studio.standalone/download-project\":{\"defaultMessage\":\"Download project JSON\",\"parameters\":[]},\"studio.standalone/download-save-intent\":{\"defaultMessage\":\"Download save-intent JSON\",\"parameters\":[]},\"studio.standalone/heading\":{\"defaultMessage\":\"Local Studio workspace\",\"parameters\":[]},\"studio.standalone/import-failed\":{\"defaultMessage\":\"Project import failed: {message}\",\"parameters\":[\"message\"]},\"studio.standalone/import-project\":{\"defaultMessage\":\"Import project JSON\",\"parameters\":[]},\"studio.standalone/imported\":{\"defaultMessage\":\"Project imported into this browser session.\",\"parameters\":[]},\"studio.standalone/json-actions\":{\"defaultMessage\":\"Local project import and download actions\",\"parameters\":[]},\"studio.standalone/no-in-memory-edits\":{\"defaultMessage\":\"No in-memory edits\",\"parameters\":[]},\"studio.standalone/no-persistence\":{\"defaultMessage\":\"Nothing is sent to or saved by a server. Changes live only in this page and are lost when it closes or reloads unless you download project JSON.\",\"parameters\":[]},\"studio.standalone/project-downloaded\":{\"defaultMessage\":\"The complete Studio project JSON was downloaded.\",\"parameters\":[]},\"studio.standalone/save-button-announcement\":{\"defaultMessage\":\"{outcome} intent downloaded. No save occurred.\",\"parameters\":[\"outcome\"]},\"studio.standalone/save-button-help\":{\"defaultMessage\":\"Downloads the selected host save-intent JSON. Nothing is sent or saved.\",\"parameters\":[]},\"studio.standalone/save-intent-downloaded\":{\"defaultMessage\":\"The {outcome} host save-intent JSON was downloaded. No save occurred.\",\"parameters\":[\"outcome\"]},\"studio.standalone/save-intent-outcome\":{\"defaultMessage\":\"Save-intent outcome\",\"parameters\":[]}}")
};
//#endregion
//#region node_modules/@kumwe/studio/dist/messages.js
var studioMessages = en_default.messages;
function messageText(t, r, i) {
	let a = (r?.[t] ?? studioMessages[t]).defaultMessage;
	if (i === void 0) return a;
	for (let n of en_default.messages[t].parameters) {
		let e = i[n];
		e !== void 0 && (a = a.replaceAll(`{${n}}`, e));
	}
	return a;
}
//#endregion
//#region node_modules/@kumwe/studio/dist/outline.js
function findOutlineLocation(e, t) {
	return i$7(e, t, void 0, void 0);
}
function findAncestry(e, n) {
	for (let r of e) {
		if (r.id === n) return [r];
		for (let e of Object.values(r.slots)) {
			let i = findAncestry(e, n);
			if (i.length > 0) return [r, ...i];
		}
	}
	return [];
}
function collectDocumentIds(e) {
	let t = /* @__PURE__ */ new Set(), n = [...e];
	for (; n.length > 0;) {
		let e = n.pop();
		if (e === void 0) break;
		t.add(e.id);
		for (let t of Object.values(e.slots)) n.push(...t);
	}
	return t;
}
function allocateDuplicateIdMap(e, t) {
	let r = collectDocumentIds(e), i = {}, a = [t];
	for (; a.length > 0;) {
		let e = a.shift();
		if (e === void 0) break;
		let t = 1, n = `${e.id}-copy-${t}`;
		for (; r.has(n);) t += 1, n = `${e.id}-copy-${t}`;
		r.add(n), Object.defineProperty(i, e.id, {
			configurable: !0,
			enumerable: !0,
			value: n,
			writable: !0
		});
		for (let t of Object.values(e.slots)) a.push(...t);
	}
	return i;
}
function i$7(e, t, n, r) {
	for (let [a, o] of e.entries()) {
		if (o.id === t) {
			let t = {
				collection: e,
				index: a,
				node: o
			};
			return n !== void 0 && r !== void 0 && (t.parentNodeId = n, t.slot = r), t;
		}
		for (let [e, n] of Object.entries(o.slots)) {
			let r = i$7(n, t, o.id, e);
			if (r !== void 0) return r;
		}
	}
}
//#endregion
//#region node_modules/@kumwe/studio-preview/dist/preview-client.js
var PreviewChannelError = class extends Error {
	code;
	retryable;
	constructor(e, t, n = !1) {
		super(t), this.name = `PreviewChannelError`, this.code = e, this.retryable = n;
	}
};
function i$6(e) {
	try {
		return structuredClone(e);
	} catch {
		throw new PreviewChannelError(`studio.preview/invalid-outbound-message`, `Refused an invalid outbound preview message.`);
	}
}
var PreviewClient = class {
	#activationListeners = /* @__PURE__ */ new Set();
	#channelId;
	#listener;
	#listeners = /* @__PURE__ */ new Set();
	#markerInventory = /* @__PURE__ */ new Set();
	#pending = /* @__PURE__ */ new Map();
	#pendingMeasures = /* @__PURE__ */ new Map();
	#pendingReady = /* @__PURE__ */ new Set();
	#sessionGeneration;
	#source;
	#target;
	#targetOrigin;
	#timeoutMilliseconds;
	#usedRequestIds = /* @__PURE__ */ new Set();
	#disposed = !1;
	#lastInboundSequence = -1;
	#latestRenderRequestId;
	#latestRenderedDigest;
	#readyPayload;
	#sequence = 0;
	constructor(e) {
		this.#targetOrigin = normalizeOrigin(e.targetOrigin), this.#channelId = e.channelId, this.#sessionGeneration = e.sessionGeneration, this.#source = e.source, this.#target = e.target, this.#timeoutMilliseconds = e.timeoutMilliseconds ?? 1e4, this.#listener = (e) => {
			this.#receive(e);
		}, this.#source.addEventListener(`message`, this.#listener);
	}
	dispose() {
		if (!this.#disposed) {
			this.#disposed = !0, this.#source.removeEventListener(`message`, this.#listener);
			for (let e of this.#pending.values()) clearTimeout(e.timeout), e.cleanup(), e.reject(Error(`Preview client was disposed.`));
			this.#pending.clear();
			for (let e of this.#pendingMeasures.values()) clearTimeout(e.timeout), e.cleanup(), e.reject(Error(`Preview client was disposed.`));
			this.#pendingMeasures.clear();
			for (let e of this.#pendingReady) clearTimeout(e.timeout), e.cleanup(), e.reject(Error(`Preview client was disposed.`));
			this.#pendingReady.clear(), this.#activationListeners.clear(), this.#listeners.clear(), this.#latestRenderRequestId = void 0, this.#latestRenderedDigest = void 0, this.#markerInventory.clear();
		}
	}
	onMessage(e) {
		return this.#listeners.add(e), () => {
			this.#listeners.delete(e);
		};
	}
	ready(e = {}) {
		return this.#disposed ? Promise.reject(Error(`Preview client was disposed.`)) : e.signal?.aborted === !0 ? Promise.reject(Error(`Preview ready wait was aborted.`, { cause: e.signal.reason })) : this.#readyPayload === void 0 ? new Promise((t, n) => {
			let r = () => {
				this.#pendingReady.delete(i) && (clearTimeout(i.timeout), i.cleanup(), i.reject(Error(`Preview ready wait was aborted.`)));
			}, i = {
				cleanup: () => {
					e.signal?.removeEventListener(`abort`, r);
				},
				reject: n,
				resolve: t,
				timeout: setTimeout(() => {
					this.#pendingReady.delete(i) && (i.cleanup(), i.reject(Error(`Preview ready wait timed out.`)));
				}, this.#timeoutMilliseconds)
			};
			this.#pendingReady.add(i), e.signal?.addEventListener(`abort`, r, { once: !0 });
		}) : Promise.resolve(this.#readyPayload);
	}
	render(e, t = {}) {
		if (this.#disposed) return Promise.reject(Error(`Preview client was disposed.`));
		if (t.signal?.aborted === !0) return Promise.reject(Error(`Preview render was aborted.`, { cause: t.signal.reason }));
		let a;
		try {
			a = i$6(e);
		} catch (e) {
			return Promise.reject(e instanceof Error ? e : new PreviewChannelError(`studio.preview/invalid-outbound-message`, `Refused an invalid outbound preview message.`));
		}
		let o = {
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: `preview-message`,
			payload: a,
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: `studio.preview/render`
		};
		try {
			this.#assertOutbound(o);
		} catch (e) {
			return Promise.reject(e instanceof Error ? e : new PreviewChannelError(`studio.preview/invalid-outbound-message`, `Refused an invalid outbound preview message.`));
		}
		if (this.#usedRequestIds.has(a.requestId)) return Promise.reject(new PreviewChannelError(`studio.preview/request-id-reused`, `Preview request ${a.requestId} was already used in this session.`));
		for (let [e, t] of this.#pending) clearTimeout(t.timeout), t.cleanup(), t.reject(Error(`Preview render ${e} was superseded by ${a.requestId}.`));
		return this.#pending.clear(), this.#rejectPendingMeasures(Error(`Preview measurements were superseded by render ${a.requestId}.`)), this.#usedRequestIds.add(a.requestId), this.#latestRenderRequestId = a.requestId, this.#latestRenderedDigest = void 0, this.#markerInventory.clear(), new Promise((e, n) => {
			let r = () => {
				let e = this.#pending.get(a.requestId);
				e !== void 0 && (clearTimeout(e.timeout), this.#pending.delete(a.requestId), e.cleanup(), this.#latestRenderRequestId === a.requestId && (this.#latestRenderRequestId = void 0), e.reject(Error(`Preview render was aborted.`)), this.#revokeRemoteRender(a.draftDigest, `studio.preview/client-aborted`));
			}, i = () => {
				t.signal?.removeEventListener(`abort`, r);
			}, s = setTimeout(() => {
				let e = this.#pending.get(a.requestId);
				e !== void 0 && (this.#pending.delete(a.requestId), e.cleanup(), this.#latestRenderRequestId === a.requestId && (this.#latestRenderRequestId = void 0), e.reject(Error(`Preview render ${a.requestId} timed out.`)), this.#revokeRemoteRender(a.draftDigest, `studio.preview/client-timeout`));
			}, this.#timeoutMilliseconds);
			this.#pending.set(a.requestId, {
				cleanup: i,
				payload: a,
				reject: n,
				resolve: e,
				timeout: s
			}), t.signal?.addEventListener(`abort`, r, { once: !0 });
			try {
				this.#post(o);
			} catch (e) {
				clearTimeout(s), i(), this.#pending.delete(a.requestId), this.#latestRenderRequestId === a.requestId && (this.#latestRenderRequestId = void 0), n(e instanceof Error ? e : Error(`Preview transport failed.`));
			}
		});
	}
	measure(t, a = {}) {
		if (this.#disposed) return Promise.reject(Error(`Preview client was disposed.`));
		if (a.signal?.aborted === !0) return Promise.reject(Error(`Preview measure was aborted.`, { cause: a.signal.reason }));
		let o;
		try {
			o = i$6(t);
		} catch (e) {
			return Promise.reject(e instanceof Error ? e : new PreviewChannelError(`studio.preview/invalid-outbound-message`, `Refused an invalid outbound preview message.`));
		}
		if (this.#latestRenderedDigest === void 0) return Promise.reject(Error(`Preview measure requires a completed render.`));
		let s = {
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: `preview-message`,
			payload: o,
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: `studio.preview/measure`
		};
		try {
			this.#assertOutbound(s);
		} catch (e) {
			return Promise.reject(e instanceof Error ? e : new PreviewChannelError(`studio.preview/invalid-outbound-message`, `Refused an invalid outbound preview message.`));
		}
		if (this.#usedRequestIds.has(o.requestId)) return Promise.reject(new PreviewChannelError(`studio.preview/request-id-reused`, `Preview request ${o.requestId} was already used in this session.`));
		if (o.markers.some((t) => !this.#markerInventory.has(t) || !isPreviewMarker(t, this.#latestRenderedDigest))) return Promise.reject(new PreviewChannelError(`studio.preview/measure-stale-marker`, `Preview measurement markers must belong to the current render inventory.`, !0));
		for (let [e, t] of this.#pendingMeasures) clearTimeout(t.timeout), t.cleanup(), t.reject(Error(`Preview measure ${e} was superseded by ${o.requestId}.`));
		return this.#pendingMeasures.clear(), this.#usedRequestIds.add(o.requestId), new Promise((e, t) => {
			let n = () => {
				let e = this.#pendingMeasures.get(o.requestId);
				e !== void 0 && (clearTimeout(e.timeout), this.#pendingMeasures.delete(o.requestId), e.cleanup(), e.reject(Error(`Preview measure was aborted.`)));
			}, r = () => {
				a.signal?.removeEventListener(`abort`, n);
			}, i = setTimeout(() => {
				let e = this.#pendingMeasures.get(o.requestId);
				e !== void 0 && (this.#pendingMeasures.delete(o.requestId), e.cleanup(), e.reject(Error(`Preview measure ${o.requestId} timed out.`)));
			}, this.#timeoutMilliseconds);
			this.#pendingMeasures.set(o.requestId, {
				cleanup: r,
				payload: o,
				reject: t,
				resolve: e,
				timeout: i
			}), a.signal?.addEventListener(`abort`, n, { once: !0 });
			try {
				this.#post(s);
			} catch (e) {
				clearTimeout(i), r(), this.#pendingMeasures.delete(o.requestId), t(e instanceof Error ? e : Error(`Preview transport failed.`));
			}
		});
	}
	setViewport(e) {
		if (this.#assertActive(), e.viewport !== void 0 == (e.width !== void 0 || e.height !== void 0)) throw RangeError(`A viewport message carries either a semantic role or explicit dimensions, never both.`);
		let t = {
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: `preview-message`,
			payload: e,
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: `studio.preview/viewport`
		};
		this.#assertOutbound(t), this.#rejectPendingMeasures(new PreviewChannelError(`studio.preview/measure-viewport-changed`, `Preview measurement was invalidated by a viewport change.`, !0)), this.#post(t);
	}
	disposeDraft(e) {
		this.#assertActive();
		let t = {
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: `preview-message`,
			payload: e,
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: `studio.preview/dispose`
		};
		this.#assertOutbound(t);
		for (let [t, n] of this.#pending) (e.draftDigest === void 0 || n.payload.draftDigest === e.draftDigest) && (clearTimeout(n.timeout), n.cleanup(), n.reject(new PreviewChannelError(`studio.preview/render-disposed`, `Preview render ${t} was disposed before completion.`)), this.#pending.delete(t), this.#latestRenderRequestId === t && (this.#latestRenderRequestId = void 0));
		(e.draftDigest === void 0 || e.draftDigest === this.#latestRenderedDigest) && (this.#latestRenderedDigest = void 0, this.#markerInventory.clear(), this.#rejectPendingMeasures(new PreviewChannelError(`studio.preview/measure-disposed`, `Preview measurement was disposed with its render.`))), this.#post(t);
	}
	onActivated(e) {
		return this.#activationListeners.add(e), () => {
			this.#activationListeners.delete(e);
		};
	}
	select(e) {
		this.#assertActive(), this.#post({
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: `preview-message`,
			payload: e,
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: `studio.preview/select`
		});
	}
	#assertActive() {
		if (this.#disposed) throw Error(`Preview client was disposed.`);
	}
	#assertOutbound(e) {
		if (!isPreviewMessage(e)) throw new PreviewChannelError(`studio.preview/invalid-outbound-message`, `Refused an invalid outbound preview message.`);
	}
	#post(e) {
		this.#assertOutbound(e), this.#sequence += 1, this.#target.postMessage(e, this.#targetOrigin);
	}
	#rejectPendingMeasures(e) {
		for (let t of this.#pendingMeasures.values()) clearTimeout(t.timeout), t.cleanup(), t.reject(e);
		this.#pendingMeasures.clear();
	}
	#revokeRemoteRender(e, t) {
		if (!this.#disposed) try {
			this.#post({
				channelId: this.#channelId,
				contractVersion: STUDIO_CONTRACT_VERSION,
				kind: `preview-message`,
				payload: {
					draftDigest: e,
					reason: t
				},
				sequence: this.#sequence,
				sessionGeneration: this.#sessionGeneration,
				type: `studio.preview/dispose`
			});
		} catch {}
	}
	#receive(n) {
		if (n.origin !== this.#targetOrigin || n.source !== this.#target || !isPreviewMessage(n.data) || n.data.channelId !== this.#channelId || n.data.sessionGeneration !== this.#sessionGeneration || n.data.sequence <= this.#lastInboundSequence) return;
		if (this.#lastInboundSequence = n.data.sequence, n.data.type === `studio.preview/activated`) {
			if (!this.#markerInventory.has(n.data.payload.marker) || !isPreviewMarker(n.data.payload.marker, this.#latestRenderedDigest)) return;
			for (let e of this.#activationListeners) e(n.data.payload);
			return;
		}
		if (n.data.type === `studio.preview/rendered`) {
			if (n.data.payload.requestId !== this.#latestRenderRequestId) return;
			let e = this.#pending.get(n.data.payload.requestId);
			if (e !== void 0 && n.data.payload.draftDigest !== e.payload.draftDigest) {
				clearTimeout(e.timeout), e.cleanup(), this.#pending.delete(n.data.payload.requestId), this.#latestRenderRequestId = void 0, e.reject(new PreviewChannelError(`studio.preview/render-correlation-mismatch`, `Preview response digest did not match its render request.`));
				return;
			}
			if (e !== void 0) {
				clearTimeout(e.timeout), e.cleanup(), this.#pending.delete(n.data.payload.requestId), this.#latestRenderRequestId = void 0, this.#latestRenderedDigest = n.data.payload.draftDigest, this.#markerInventory.clear();
				for (let e of n.data.payload.markers) this.#markerInventory.add(e);
				e.resolve(n.data.payload);
			}
		} else if (n.data.type === `studio.preview/measurements`) {
			let e = this.#pendingMeasures.get(n.data.payload.requestId);
			if (e !== void 0) {
				let t = [...Object.keys(n.data.payload.measurements), ...n.data.payload.unknown];
				if (t.length !== e.payload.markers.length || e.payload.markers.some((e) => !t.includes(e))) {
					clearTimeout(e.timeout), e.cleanup(), this.#pendingMeasures.delete(n.data.payload.requestId), e.reject(new PreviewChannelError(`studio.preview/invalid-measurements`, `Preview measurements did not exactly partition the requested marker inventory.`));
					return;
				}
				clearTimeout(e.timeout), e.cleanup(), this.#pendingMeasures.delete(n.data.payload.requestId), n.data.payload.draftDigest === this.#latestRenderedDigest ? e.resolve({
					geometry: n.data.payload,
					status: `measured`
				}) : e.resolve({
					measuredDigest: n.data.payload.draftDigest,
					status: `stale`
				});
			}
		} else if (n.data.type === `studio.preview/error`) {
			let e = n.data.payload.message.defaultMessage ?? n.data.payload.message.key, t = n.data.payload.correlationId;
			if (t !== void 0) {
				let i = this.#pending.get(t), a = this.#pendingMeasures.get(t);
				if (i === void 0 && a === void 0) return;
				i !== void 0 && (clearTimeout(i.timeout), i.cleanup(), i.reject(new PreviewChannelError(n.data.payload.code, e, n.data.payload.retryable)), this.#pending.delete(t), this.#latestRenderRequestId === t && (this.#latestRenderRequestId = void 0, this.#markerInventory.clear())), a !== void 0 && (clearTimeout(a.timeout), a.cleanup(), a.reject(new PreviewChannelError(n.data.payload.code, e, n.data.payload.retryable)), this.#pendingMeasures.delete(t));
			} else {
				for (let t of this.#pending.values()) clearTimeout(t.timeout), t.cleanup(), t.reject(Error(e));
				this.#pending.clear();
				for (let t of this.#pendingMeasures.values()) clearTimeout(t.timeout), t.cleanup(), t.reject(Error(e));
				this.#pendingMeasures.clear(), this.#latestRenderRequestId = void 0, this.#latestRenderedDigest = void 0, this.#markerInventory.clear();
			}
		} else if (n.data.type === `studio.preview/ready`) {
			this.#readyPayload = n.data.payload;
			let e = [...this.#pendingReady];
			this.#pendingReady.clear();
			for (let t of e) clearTimeout(t.timeout), t.cleanup(), t.resolve(n.data.payload);
		} else if (n.data.type === `studio.preview/reload` || n.data.type === `studio.preview/teardown`) {
			let e = n.data.type === `studio.preview/reload` ? `Preview renderer reloaded before responding.` : `Preview channel was torn down.`;
			for (let t of this.#pending.values()) clearTimeout(t.timeout), t.cleanup(), t.reject(Error(e));
			this.#pending.clear();
			for (let t of this.#pendingMeasures.values()) clearTimeout(t.timeout), t.cleanup(), t.reject(Error(e));
			this.#pendingMeasures.clear(), this.#latestRenderRequestId = void 0, this.#latestRenderedDigest = void 0, this.#markerInventory.clear(), this.#readyPayload = void 0;
		}
		let i = n.data.type === `studio.preview/teardown`;
		for (let e of this.#listeners) e(n.data);
		i && this.dispose();
	}
	teardown(e) {
		this.#assertActive(), this.#post({
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: `preview-message`,
			payload: { reason: e },
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: `studio.preview/teardown`
		}), this.dispose();
	}
};
function normalizeOrigin(e) {
	if (e === `*`) throw TypeError(`Preview target origin must be exact; wildcard origins are forbidden.`);
	let t = new URL(e);
	if (t.origin === `null`) throw TypeError(`Preview target origin must use a network origin.`);
	return t.origin;
}
//#endregion
//#region node_modules/@kumwe/studio-preview/dist/preview-host.js
var PreviewHost = class {
	#channelId;
	#listener;
	#measureCallback;
	#renderCallback;
	#renderer;
	#viewportListeners = /* @__PURE__ */ new Set();
	#disposeListeners = /* @__PURE__ */ new Set();
	#selectListeners = /* @__PURE__ */ new Set();
	#markerInventory = /* @__PURE__ */ new Set();
	#sessionGeneration;
	#source;
	#target;
	#targetOrigin;
	#usedRequestIds = /* @__PURE__ */ new Set();
	#viewports;
	#activeMeasure;
	#activeRender;
	#disposed = !1;
	#lastInboundSequence = -1;
	#measureGeneration = 0;
	#measuredRenderDigest;
	#renderGeneration = 0;
	#sequence = 0;
	constructor(e) {
		this.#targetOrigin = normalizeOrigin(e.targetOrigin), this.#channelId = e.channelId, this.#sessionGeneration = e.sessionGeneration, this.#source = e.source, this.#target = e.target, this.#measureCallback = e.measure, this.#renderCallback = e.render, this.#renderer = e.renderer, this.#viewports = [...e.viewports], this.#listener = (e) => {
			this.#receive(e);
		}, this.#source.addEventListener(`message`, this.#listener);
	}
	announce() {
		this.#assertActive(), this.#post({
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: `preview-message`,
			payload: {
				protocolVersion: STUDIO_WIRE_PROTOCOL_VERSION,
				renderer: this.#renderer,
				viewports: [...this.#viewports]
			},
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: `studio.preview/ready`
		});
	}
	dispose() {
		this.#disposed || (this.#disposed = !0, this.#source.removeEventListener(`message`, this.#listener), this.#selectListeners.clear(), this.#viewportListeners.clear(), this.#disposeListeners.clear(), this.#invalidateMeasure(`Preview host was disposed.`), this.#invalidateRender(`Preview host was disposed.`), this.#measuredRenderDigest = void 0, this.#markerInventory.clear());
	}
	announceActivation(t) {
		if (this.#assertActive(), !this.#markerInventory.has(t.marker) || !isPreviewMarker(t.marker, this.#measuredRenderDigest)) throw RangeError(`Preview activation marker is not in the current render inventory.`);
		this.#post({
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: `preview-message`,
			payload: t,
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: `studio.preview/activated`
		});
	}
	onViewport(e) {
		return this.#viewportListeners.add(e), () => {
			this.#viewportListeners.delete(e);
		};
	}
	onDispose(e) {
		return this.#disposeListeners.add(e), () => {
			this.#disposeListeners.delete(e);
		};
	}
	onSelect(e) {
		return this.#selectListeners.add(e), () => {
			this.#selectListeners.delete(e);
		};
	}
	reload(e) {
		this.#assertActive(), this.#invalidateMeasure(`Preview renderer reloaded.`), this.#invalidateRender(`Preview renderer reloaded.`), this.#measuredRenderDigest = void 0, this.#markerInventory.clear(), this.#post({
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: `preview-message`,
			payload: { reason: e },
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: `studio.preview/reload`
		}), this.announce();
	}
	teardown(e) {
		this.#assertActive(), this.#post({
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: `preview-message`,
			payload: { reason: e },
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: `studio.preview/teardown`
		}), this.dispose();
	}
	#assertActive() {
		if (this.#disposed) throw Error(`Preview host was disposed.`);
	}
	#handleMeasure(t) {
		let n = t.requestId;
		if (this.#usedRequestIds.has(n)) {
			this.#postError(n, {
				code: `studio.preview/request-id-reused`,
				defaultMessage: `The preview request identifier was already used in this session.`,
				retryable: !1
			});
			return;
		}
		if (this.#usedRequestIds.add(n), this.#measuredRenderDigest === void 0) {
			this.#postError(n, {
				code: `studio.preview/measure-unavailable`,
				defaultMessage: `Preview measurement is unavailable.`,
				retryable: !0
			});
			return;
		}
		let r = this.#measuredRenderDigest;
		if (t.markers.some((t) => !this.#markerInventory.has(t) || !isPreviewMarker(t, r))) {
			this.#postError(n, {
				code: `studio.preview/measure-stale-marker`,
				defaultMessage: `Preview measurement markers are not in the current render inventory.`,
				retryable: !0
			});
			return;
		}
		if (this.#measureCallback === void 0) {
			this.#postError(n, {
				code: `studio.preview/measure-unavailable`,
				defaultMessage: `Preview measurement is unavailable.`,
				retryable: !1
			});
			return;
		}
		this.#invalidateMeasure(`Preview measurement was superseded.`);
		let i = {
			controller: new AbortController(),
			draftDigest: r,
			generation: this.#measureGeneration,
			requestId: n
		};
		this.#activeMeasure = i;
		let a = [...t.markers], o;
		try {
			o = this.#measureCallback(a, i.controller.signal);
		} catch {
			this.#settleMeasureFailure(i, s$4());
			return;
		}
		Promise.resolve(o).then((e) => {
			try {
				this.#settleMeasured(i, a, e);
			} catch {
				this.#settleMeasureFailure(i, s$4());
			}
		}, () => {
			this.#settleMeasureFailure(i, s$4());
		});
	}
	#handleRender(e) {
		if (this.#usedRequestIds.has(e.requestId)) {
			this.#postError(e.requestId, {
				code: `studio.preview/request-id-reused`,
				defaultMessage: `The preview request identifier was already used in this session.`,
				retryable: !1
			});
			return;
		}
		this.#usedRequestIds.add(e.requestId), this.#invalidateMeasure(`Preview measurement was superseded by a render.`), this.#invalidateRender(`Preview render was superseded.`), this.#measuredRenderDigest = void 0, this.#markerInventory.clear();
		let t = {
			controller: new AbortController(),
			draftDigest: e.draftDigest,
			generation: this.#renderGeneration,
			requestId: e.requestId
		};
		this.#activeRender = t;
		let n;
		try {
			n = this.#renderCallback(e, t.controller.signal);
		} catch {
			this.#settleFailure(t);
			return;
		}
		Promise.resolve(n).then((e) => {
			try {
				this.#settleRendered(t, e);
			} catch {
				this.#settleFailure(t);
			}
		}, () => {
			this.#settleFailure(t);
		});
	}
	#post(e) {
		if (!isPreviewMessage(e)) throw TypeError(`Refused an invalid outbound preview message.`);
		this.#sequence += 1, this.#target.postMessage(e, this.#targetOrigin);
	}
	#receive(e) {
		if (!(e.origin !== this.#targetOrigin || e.source !== this.#target || !isPreviewMessage(e.data) || e.data.channelId !== this.#channelId || e.data.sessionGeneration !== this.#sessionGeneration || e.data.sequence <= this.#lastInboundSequence)) {
			if (this.#lastInboundSequence = e.data.sequence, e.data.type === `studio.preview/render`) this.#handleRender(e.data.payload);
			else if (e.data.type === `studio.preview/measure`) this.#handleMeasure(e.data.payload);
			else if (e.data.type === `studio.preview/select`) for (let t of this.#selectListeners) t(e.data.payload);
			else if (e.data.type === `studio.preview/viewport`) {
				this.#invalidateMeasure(`Preview viewport changed.`);
				for (let t of this.#viewportListeners) t(e.data.payload);
			} else if (e.data.type === `studio.preview/dispose`) {
				(e.data.payload.draftDigest === void 0 || e.data.payload.draftDigest === this.#activeRender?.draftDigest) && this.#invalidateRender(`Preview render was disposed.`), (e.data.payload.draftDigest === void 0 || e.data.payload.draftDigest === this.#measuredRenderDigest) && (this.#invalidateMeasure(`Preview measurement was disposed.`), this.#measuredRenderDigest = void 0, this.#markerInventory.clear());
				for (let t of this.#disposeListeners) t(e.data.payload);
			} else e.data.type === `studio.preview/teardown` && this.dispose();
		}
	}
	#settleMeasureFailure(e, t) {
		this.#isActiveMeasure(e) && (this.#activeMeasure = void 0, this.#postError(e.requestId, t));
	}
	#postError(e, t) {
		this.#disposed || this.#post({
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: `preview-message`,
			payload: {
				code: t.code,
				correlationId: e,
				message: {
					defaultMessage: t.defaultMessage,
					key: t.code
				},
				retryable: t.retryable
			},
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: `studio.preview/error`
		});
	}
	#settleMeasured(e, n, i) {
		if (!this.#isActiveMeasure(e)) return;
		let a = {}, o = [];
		for (let e of n) {
			let t = Object.hasOwn(i.rects, e) ? i.rects[e] : void 0;
			t === void 0 || t.length === 0 ? o.push(e) : a[e] = t.map((e) => ({
				height: e.height,
				width: e.width,
				x: e.x,
				y: e.y
			}));
		}
		let c = {
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: `preview-message`,
			payload: {
				draftDigest: e.draftDigest,
				measurements: a,
				requestId: e.requestId,
				unknown: o,
				viewport: {
					devicePixelRatio: i.viewport.devicePixelRatio,
					height: i.viewport.height,
					scrollX: i.viewport.scrollX,
					scrollY: i.viewport.scrollY,
					width: i.viewport.width
				}
			},
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: `studio.preview/measurements`
		};
		if (!isPreviewMessage(c)) {
			this.#settleMeasureFailure(e, s$4());
			return;
		}
		this.#activeMeasure = void 0, this.#post(c);
	}
	#settleFailure(e) {
		this.#isActiveRender(e) && (this.#activeRender = void 0, this.#postError(e.requestId, {
			code: `studio.preview/render-failed`,
			defaultMessage: `Preview rendering failed.`,
			retryable: !0
		}));
	}
	#settleRendered(e, t) {
		if (this.#isActiveRender(e)) {
			if (!isPreviewRenderedPayload(t) || t.draftDigest !== e.draftDigest || t.requestId !== e.requestId) {
				this.#settleFailure(e);
				return;
			}
			this.#activeRender = void 0, this.#measuredRenderDigest = e.draftDigest, this.#markerInventory.clear();
			for (let e of t.markers) this.#markerInventory.add(e);
			this.#post({
				channelId: this.#channelId,
				contractVersion: STUDIO_CONTRACT_VERSION,
				kind: `preview-message`,
				payload: {
					diagnostics: t.diagnostics,
					draftDigest: e.draftDigest,
					markers: t.markers,
					markerMap: t.markerMap,
					requestId: e.requestId
				},
				sequence: this.#sequence,
				sessionGeneration: this.#sessionGeneration,
				type: `studio.preview/rendered`
			});
		}
	}
	#invalidateMeasure(e) {
		this.#measureGeneration += 1, this.#activeMeasure?.controller.abort(e), this.#activeMeasure = void 0;
	}
	#invalidateRender(e) {
		this.#renderGeneration += 1, this.#activeRender?.controller.abort(e), this.#activeRender = void 0;
	}
	#isActiveMeasure(e) {
		return !this.#disposed && this.#activeMeasure === e && e.generation === this.#measureGeneration && e.draftDigest === this.#measuredRenderDigest;
	}
	#isActiveRender(e) {
		return !this.#disposed && this.#activeRender === e && e.generation === this.#renderGeneration;
	}
};
function s$4() {
	return {
		code: `studio.preview/measure-failed`,
		defaultMessage: `Preview measurement failed.`,
		retryable: !0
	};
}
//#endregion
//#region node_modules/@kumwe/studio-preview/dist/preview-identity.js
function canonicalPreviewDraftBytes(t, n = {}) {
	return canonicalUtf8Bytes(t, n);
}
async function computePreviewDraftDigest(e, t = {}) {
	let n = t.subtle ?? globalThis.crypto.subtle, i = t.maximumDepth === void 0 ? {} : { maximumDepth: t.maximumDepth }, a = Uint8Array.from(canonicalPreviewDraftBytes(e, i)), o = await n.digest(`SHA-256`, a);
	return [...new Uint8Array(o)].map((e) => e.toString(16).padStart(2, `0`)).join(``);
}
//#endregion
//#region node_modules/@kumwe/studio/dist/preview-surface.js
var StudioPreviewSurface = class {
	#binding;
	#callbacks;
	#controllers = /* @__PURE__ */ new Set();
	#unsubscribeActivated;
	#unsubscribeMessages;
	#accepted = !1;
	#acceptedDigest;
	#closed = !1;
	#generation = 0;
	#lastViewport;
	#latestIntent;
	#markerMap = {};
	#measurementController;
	#measurementGeneration = 0;
	#pendingIntent;
	#rendered;
	#measureSerial = 0;
	#renderSerial = 0;
	#scheduled = !1;
	#selectedNodeId;
	#state = `connecting`;
	constructor(e, t) {
		this.#binding = e, this.#callbacks = t, this.#unsubscribeMessages = e.client.onMessage((e) => {
			this.#receive(e);
		}), this.#unsubscribeActivated = e.client.onActivated((e) => {
			let t = this.#markerMap[e.marker];
			t !== void 0 && (this.#selectedNodeId = t, this.#callbacks.onActivated(t));
		}), t.onState(this.#state);
	}
	get state() {
		return this.#state;
	}
	update(e, t) {
		if (this.#closed) return;
		let n;
		try {
			n = structuredClone(e);
		} catch {
			this.#setState(`stale`);
			return;
		}
		this.#generation += 1;
		let r = {
			draft: n,
			generation: this.#generation,
			viewport: t
		};
		if (this.#latestIntent = r, this.#pendingIntent = r, this.#acceptedDigest !== void 0) try {
			this.#binding.client.disposeDraft({
				draftDigest: this.#acceptedDigest,
				reason: `studio.preview/draft-superseded`
			});
		} catch {}
		this.#accepted = !1, this.#acceptedDigest = void 0, this.#markerMap = {}, this.#rendered = void 0, this.#clearGeometry();
		for (let e of this.#controllers) e.abort(`Preview render was superseded by newer authoring state.`);
		this.#schedule();
	}
	selectNode(e) {
		if (this.#selectedNodeId = e, !(e === void 0 || !Object.values(this.#markerMap).includes(e))) try {
			this.#binding.client.select({
				nodeId: e,
				reveal: !0
			});
		} catch {
			this.#setState(`stale`);
		}
	}
	refreshGeometry() {
		let e = this.#rendered;
		e !== void 0 && this.#accepted && this.#measure(e);
	}
	teardown(e) {
		if (!this.#closed) {
			try {
				this.#binding.client.teardown(e);
			} catch {}
			this.#close();
		}
	}
	#close() {
		if (!this.#closed) {
			this.#closed = !0, this.#generation += 1, this.#pendingIntent = void 0, this.#accepted = !1, this.#acceptedDigest = void 0, this.#markerMap = {}, this.#rendered = void 0, this.#clearGeometry();
			for (let e of this.#controllers) e.abort(`Preview channel closed.`);
			this.#controllers.clear(), this.#unsubscribeActivated(), this.#unsubscribeMessages(), this.#setState(`closed`);
		}
	}
	async #perform(t) {
		let n = new AbortController();
		this.#controllers.add(n);
		try {
			this.#setState(`connecting`);
			let r = await this.#binding.client.ready({ signal: n.signal });
			if (!this.#isCurrent(t, n)) return;
			let i = this.#resolveViewport(t, r);
			if (i === void 0) {
				this.#setState(`stale`);
				return;
			}
			let a = await computePreviewDraftDigest(t.draft);
			if (!this.#isCurrent(t, n)) return;
			let o = await this.#binding.stage(t.draft, { signal: n.signal });
			if (!this.#isCurrent(t, n)) return;
			if (o.artifactId !== t.draft.id || o.draftRevision !== t.draft.revision || o.draftDigest !== a) throw TypeError(`The host staged a different preview draft identity.`);
			this.#lastViewport !== i && (this.#binding.client.setViewport({ viewport: i }), this.#lastViewport = i), this.#renderSerial += 1, this.#setState(`rendering`);
			let s = await this.#binding.client.render({
				artifactId: o.artifactId,
				draftDigest: o.draftDigest,
				draftRevision: o.draftRevision,
				requestId: `renders/studio-shell-${this.#renderSerial}`,
				viewport: i
			}, { signal: n.signal });
			if (!this.#isCurrent(t, n)) return;
			this.#accept(s);
		} catch {
			this.#isCurrent(t, n) && (this.#accepted = !1, this.#acceptedDigest = void 0, this.#markerMap = {}, this.#rendered = void 0, this.#clearGeometry(), this.#setState(`stale`));
		} finally {
			this.#controllers.delete(n);
		}
	}
	#accept(e) {
		this.#markerMap = structuredClone(e.markerMap), this.#accepted = !0, this.#acceptedDigest = e.draftDigest, this.#rendered = structuredClone(e), this.#setState(`current`), this.selectNode(this.#selectedNodeId), this.#measure(e);
	}
	async #measure(e) {
		let t = Object.entries(e.markerMap);
		if (t.length === 0) {
			this.#clearGeometry();
			return;
		}
		this.#measurementController?.abort(`Preview geometry was superseded by a newer measurement.`), this.#measurementGeneration += 1;
		let n = this.#measurementGeneration, r = new AbortController();
		this.#measurementController = r, this.#controllers.add(r);
		let i = {}, a = [], o;
		try {
			for (let s = 0; s < t.length; s += 1e3) {
				let c = t.slice(s, s + 1e3);
				this.#measureSerial += 1;
				let l = await this.#binding.client.measure({
					markers: c.map(([e]) => e),
					requestId: `measurements/studio-shell-${this.#measureSerial}`
				}, { signal: r.signal });
				if (!this.#isAcceptedGeometry(e, r, n) || l.status !== `measured`) return;
				o ??= structuredClone(l.geometry.viewport);
				for (let [t, n] of Object.entries(l.geometry.measurements)) {
					let r = e.markerMap[t];
					r !== void 0 && (i[r] = structuredClone(n));
				}
				for (let t of l.geometry.unknown) {
					let n = e.markerMap[t];
					n !== void 0 && a.push(n);
				}
			}
			o !== void 0 && this.#isAcceptedGeometry(e, r, n) && this.#callbacks.onGeometry?.({
				draftDigest: e.draftDigest,
				measurements: i,
				unknownNodeIds: a,
				viewport: o
			});
		} catch {
			this.#isAcceptedGeometry(e, r, n) && this.#callbacks.onGeometry?.(void 0);
		} finally {
			this.#controllers.delete(r), this.#measurementController === r && (this.#measurementController = void 0);
		}
	}
	#isAcceptedGeometry(e, t, n) {
		return !this.#closed && !t.signal.aborted && n === this.#measurementGeneration && this.#accepted && this.#acceptedDigest === e.draftDigest && this.#rendered?.requestId === e.requestId;
	}
	#clearGeometry() {
		this.#measurementGeneration += 1, this.#measurementController?.abort(`Preview geometry authority was revoked.`), this.#measurementController = void 0, this.#callbacks.onGeometry?.(void 0);
	}
	#isCurrent(e, t) {
		return !this.#closed && !t.signal.aborted && e.generation === this.#generation && this.#latestIntent?.generation === e.generation;
	}
	#receive(e) {
		if (this.#callbacks.onMessage(e), e.type === `studio.preview/reload`) {
			this.#accepted = !1, this.#acceptedDigest = void 0, this.#lastViewport = void 0, this.#markerMap = {}, this.#rendered = void 0, this.#clearGeometry(), this.#setState(`stale`);
			let e = this.#latestIntent;
			e !== void 0 && this.update(e.draft, e.viewport);
		} else if (e.type === `studio.preview/ready`) {
			if (!this.#accepted && this.#controllers.size === 0 && this.#pendingIntent === void 0) {
				let e = this.#latestIntent;
				e !== void 0 && this.update(e.draft, e.viewport);
			}
		} else e.type === `studio.preview/teardown` ? this.#close() : e.type === `studio.preview/error` && e.payload.correlationId === void 0 && (this.#accepted = !1, this.#acceptedDigest = void 0, this.#markerMap = {}, this.#rendered = void 0, this.#clearGeometry(), this.#setState(`stale`));
	}
	#resolveViewport(e, t) {
		let n = e.viewport ?? t.viewports[0];
		return n !== void 0 && t.viewports.includes(n) ? n : void 0;
	}
	#schedule() {
		this.#scheduled || (this.#scheduled = !0, queueMicrotask(() => {
			this.#scheduled = !1;
			let e = this.#pendingIntent;
			this.#pendingIntent = void 0, e !== void 0 && !this.#closed && this.#perform(e);
		}));
	}
	#setState(e) {
		e !== this.#state && (this.#state = e, this.#callbacks.onState(e));
	}
};
//#endregion
//#region node_modules/@kumwe/studio-rich-text/dist/profiles.js
var e$2 = Object.freeze([
	`blockquote`,
	`bulletList`,
	`callout`,
	`checklist`,
	`checklistItem`,
	`codeBlock`,
	`doc`,
	`hardBreak`,
	`heading`,
	`horizontalRule`,
	`listItem`,
	`orderedList`,
	`paragraph`,
	`table`,
	`tableCell`,
	`tableRow`,
	`text`
]);
var t$2 = Object.freeze([
	`bold`,
	`code`,
	`highlight`,
	`italic`,
	`strike`
]);
var n$3 = Object.freeze({
	maximumDepth: 8,
	maximumItemsPerArray: 256,
	maximumPropertiesPerObject: 64,
	maximumStringLength: 4096,
	maximumTotalBytes: 65536
});
function r$3(r, i) {
	return Object.freeze({
		allowedAttributes: Object.freeze({
			callout: Object.freeze([`tone`]),
			checklistItem: Object.freeze([`checked`, `level`]),
			codeBlock: Object.freeze([`language`]),
			heading: Object.freeze([`level`]),
			"mark:highlight": Object.freeze([`tone`]),
			orderedList: Object.freeze([`start`]),
			table: Object.freeze([`header`])
		}),
		allowedMarks: t$2,
		allowedNodes: e$2,
		attributeLimits: n$3,
		headingLevels: Object.freeze([
			2,
			3,
			4
		]),
		maximumDepth: 32,
		maximumDocumentBytes: 1048576,
		maximumMarks: 2e4,
		maximumMarksPerNode: t$2.length,
		maximumNodes: i,
		maximumTextLength: r
	});
}
var PORTABLE_RICH_TEXT_PROFILE = r$3(25e4, 5e3);
var MARKETING_RICH_TEXT_PROFILE = r$3(1e5, 2e3);
var DOCUMENTATION_RICH_TEXT_PROFILE = r$3(5e5, 1e4);
var a$5 = Object.freeze({
	"studio.rich-text/documentation": DOCUMENTATION_RICH_TEXT_PROFILE,
	"studio.rich-text/marketing": MARKETING_RICH_TEXT_PROFILE,
	"studio.rich-text/portable": PORTABLE_RICH_TEXT_PROFILE
});
function resolveRichTextProfile(e = `studio.rich-text/portable`) {
	let t = a$5[e];
	if (t === void 0) throw TypeError(`Unknown Studio rich-text profile "${e}".`);
	return t;
}
function resolveContainerRichTextProfile(e) {
	switch (e) {
		case `studio.core/accordion-item`:
		case `studio.core/dialog`:
		case `studio.core/notice`:
		case `studio.core/popover`:
		case `studio.core/tab`: return `studio.rich-text/marketing`;
		default: throw TypeError(`Unknown Studio rich-text container "${String(e)}".`);
	}
}
//#endregion
//#region node_modules/@kumwe/studio-rich-text/dist/first-party-tools.js
var STUDIO_EDITOR_JS_TOOL_NAMES = Object.freeze([
	`callout`,
	`checklist`,
	`code`,
	`delimiter`,
	`header`,
	`list`,
	`paragraph`,
	`quote`,
	`table`
]);
function studioEditorJsTools() {
	return Object.freeze({
		callout: StudioCalloutTool,
		checklist: StudioChecklistTool,
		code: StudioCodeTool,
		delimiter: StudioDelimiterTool,
		header: StudioHeaderTool,
		list: StudioListTool,
		paragraph: StudioParagraphTool,
		quote: StudioQuoteTool,
		table: StudioTableTool
	});
}
function toStudioEditorJsBlocks(e) {
	return e.content.map((e) => ({
		data: { node: structuredClone(e) },
		type: i$5(e)
	}));
}
function fromStudioEditorJsBlocks(t) {
	if (!A$3(t) || !Array.isArray(t.blocks)) throw TypeError(`Editor surface returned an invalid block collection.`);
	let n = t.blocks.map((t, n) => {
		if (!A$3(t) || !STUDIO_EDITOR_JS_TOOL_NAMES.includes(t.type) || !A$3(t.data) || !A$3(t.data.node)) throw TypeError(`Editor block ${n} is not a Studio first-party block.`);
		let r = structuredClone(t.data.node);
		if (i$5(r) !== t.type) throw TypeError(`Editor block ${n} has a mismatched Studio node type.`);
		return r;
	});
	return {
		content: n.length > 0 ? n : [{ type: `paragraph` }],
		type: `doc`
	};
}
function i$5(e) {
	switch (e.type) {
		case `heading`: return `header`;
		case `blockquote`: return `quote`;
		case `horizontalRule`: return `delimiter`;
		case `bulletList`:
		case `orderedList`: return `list`;
		case `checklist`: return `checklist`;
		case `table`: return `table`;
		case `callout`: return `callout`;
		case `codeBlock`: return `code`;
		case `paragraph`: return `paragraph`;
		default: throw TypeError(`Node type "${e.type}" has no first-party Editor.js tool.`);
	}
}
var a$4 = class {
	static isReadOnlySupported = !0;
	node;
	readOnly;
	field;
	constructor(e, t) {
		this.node = structuredClone(e.data?.node ?? t), this.readOnly = e.readOnly === !0;
	}
	renderInline(e, t) {
		let n = document.createElement(`div`);
		n.className = `studio-rich-text-field`, n.contentEditable = this.readOnly ? `false` : `true`, n.setAttribute(`aria-label`, e), n.setAttribute(`role`, `textbox`), n.setAttribute(`aria-multiline`, `true`), n.spellcheck = !0;
		for (let e of t) c$3(n, e);
		return n.addEventListener(`paste`, m$4), this.field = n, n;
	}
	saveInline(e) {
		return this.field === void 0 ? structuredClone([...e]) : d$5(e, u$5(this.field));
	}
};
var StudioParagraphTool = class extends a$4 {
	static isReadOnlySupported = !0;
	static toolbox = {
		icon: `¶`,
		title: `Paragraph`
	};
	constructor(e) {
		super(e, { type: `paragraph` });
	}
	render() {
		return this.renderInline(`Paragraph`, this.node.content ?? []);
	}
	save() {
		let e = structuredClone(this.node), t = this.saveInline(e.content ?? []);
		return w$3(e.content ?? [], t) || (e.content = t), { node: e };
	}
};
var StudioHeaderTool = class extends a$4 {
	static isReadOnlySupported = !0;
	static toolbox = {
		icon: `H`,
		title: `Heading`
	};
	#level;
	constructor(e) {
		super(e, {
			attrs: { level: 2 },
			type: `heading`
		});
	}
	render() {
		let e = T$3(`Heading`), t = document.createElement(`select`), n = this.node.attrs?.level === 3 || this.node.attrs?.level === 4 ? this.node.attrs.level : 2;
		t.setAttribute(`aria-label`, `Heading level`), t.disabled = this.readOnly;
		for (let e of [
			2,
			3,
			4
		]) {
			let r = document.createElement(`option`);
			r.value = String(e), r.textContent = `Heading ${e}`, r.selected = n === e, t.append(r);
		}
		return t.value = String(n), this.#level = t, e.append(t, this.renderInline(`Heading text`, this.node.content ?? [])), e;
	}
	save() {
		let e = structuredClone(this.node), t = Number(this.#level?.value ?? this.node.attrs?.level ?? 2);
		t !== Number(this.node.attrs?.level ?? 2) && (e.attrs = { level: t });
		let n = this.saveInline(e.content ?? []);
		return w$3(e.content ?? [], n) || (e.content = n), { node: e };
	}
};
var StudioQuoteTool = class extends a$4 {
	static isReadOnlySupported = !0;
	static toolbox = {
		icon: `“`,
		title: `Quote`
	};
	constructor(e) {
		super(e, {
			content: [{ type: `paragraph` }],
			type: `blockquote`
		});
	}
	render() {
		return this.renderInline(`Quotation`, x$3(this.node.content ?? []));
	}
	save() {
		let e = structuredClone(this.node), t = x$3(e.content ?? []);
		return e.content = S$3(e.content ?? [], this.saveInline(t)), { node: e };
	}
};
var StudioDelimiterTool = class {
	static isReadOnlySupported = !0;
	static toolbox = {
		icon: `—`,
		title: `Separator`
	};
	render() {
		let e = document.createElement(`hr`);
		return e.setAttribute(`aria-label`, `Separator`), e;
	}
	save() {
		return { node: { type: `horizontalRule` } };
	}
};
var StudioCalloutTool = class extends a$4 {
	static isReadOnlySupported = !0;
	static toolbox = {
		icon: `!`,
		title: `Callout`
	};
	#tone;
	constructor(e) {
		super(e, {
			attrs: { tone: `info` },
			content: [{ type: `paragraph` }],
			type: `callout`
		});
	}
	render() {
		let e = T$3(`Callout`);
		return this.#tone = D$2(`Callout tone`, [
			`info`,
			`success`,
			`warning`,
			`danger`
		], j$3(this.node.attrs?.tone, `info`), this.readOnly), e.append(this.#tone, this.renderInline(`Callout text`, x$3(this.node.content ?? []))), e;
	}
	save() {
		let e = structuredClone(this.node);
		e.attrs = { tone: this.#tone?.value ?? `info` };
		let t = x$3(e.content ?? []);
		return e.content = S$3(e.content ?? [], this.saveInline(t)), { node: e };
	}
};
var StudioCodeTool = class {
	static isReadOnlySupported = !0;
	static toolbox = {
		icon: `</>`,
		title: `Code`
	};
	#node;
	#readOnly;
	#language;
	#source;
	constructor(e) {
		this.#node = structuredClone(e.data?.node ?? {
			attrs: { language: `text` },
			text: ``,
			type: `codeBlock`
		}), this.#readOnly = e.readOnly === !0;
	}
	render() {
		let e = T$3(`Code sample`);
		return this.#language = E$3(`Code language`, j$3(this.#node.attrs?.language, `text`), this.#readOnly), this.#language.pattern = `[A-Za-z0-9][A-Za-z0-9+_.#-]{0,63}`, this.#language.maxLength = 64, this.#source = document.createElement(`textarea`), this.#source.setAttribute(`aria-label`, `Inert code source`), this.#source.disabled = this.#readOnly, this.#source.rows = 8, this.#source.value = this.#node.text ?? ``, e.append(this.#language, this.#source), e;
	}
	save() {
		let e = this.#language?.value.trim() ?? `text`;
		return { node: {
			attrs: { language: /^[A-Za-z0-9][A-Za-z0-9+_.#-]{0,63}$/u.test(e) ? e : `text` },
			text: this.#source?.value ?? ``,
			type: `codeBlock`
		} };
	}
};
var StudioListTool = class {
	static isReadOnlySupported = !0;
	static toolbox = {
		icon: `•`,
		title: `List`
	};
	#readOnly;
	#node;
	#rows;
	#root;
	constructor(e) {
		let t = structuredClone(e.data?.node ?? {
			content: [{
				content: [{ type: `paragraph` }],
				type: `listItem`
			}],
			type: `bulletList`
		});
		this.#node = t, this.#readOnly = e.readOnly === !0, this.#rows = h$3(t);
	}
	render() {
		return this.#root = T$3(`List`), this.#renderRows(), this.#root;
	}
	save() {
		return this.#syncRows(), { node: structuredClone(this.#node) };
	}
	#renderRows() {
		let e = this.#root;
		if (e === void 0) return;
		e.replaceChildren();
		let t = D$2(`List style`, [`bullet`, `ordered`], this.#node.type === `orderedList` ? `ordered` : `bullet`, this.#readOnly);
		if (t.addEventListener(`change`, () => {
			this.#syncRows();
			let e = t.value === `ordered`, n = g$3(this.#node);
			this.#node.type = e ? `orderedList` : `bulletList`, e && n !== 1 ? this.#node.attrs = { start: n } : delete this.#node.attrs, this.#renderRows();
		}), e.append(t), this.#node.type === `orderedList`) {
			let t = E$3(`Ordered list start`, String(g$3(this.#node)), this.#readOnly);
			t.type = `number`, t.min = `1`, t.max = `1000000`, t.addEventListener(`change`, () => {
				let e = Math.max(1, Math.min(1e6, Number(t.value) || 1));
				e !== g$3(this.#node) && (e === 1 ? delete this.#node.attrs : this.#node.attrs = { start: e });
			}), e.append(t);
		}
		this.#rows = h$3(this.#node);
		let n = document.createElement(`ol`);
		n.setAttribute(`aria-label`, `List items`);
		for (let [e, t] of this.#rows.entries()) {
			let r = document.createElement(`li`);
			r.dataset.index = String(e), r.dataset.studioDepth = String(t.depth), r.setAttribute(`aria-level`, String(t.depth + 1));
			let i = C$3(`List item ${e + 1}`, t.editableBlock.content ?? [], this.#readOnly);
			i.dataset.listText = String(e), r.append(i), this.#readOnly || r.append(O$3(`Move item up`, () => this.#move(e, -1), !_$3(t, -1)), O$3(`Move item down`, () => this.#move(e, 1), !_$3(t, 1)), O$3(`Indent item`, () => this.#indent(e), !v$3(t)), O$3(`Outdent item`, () => this.#outdent(e), t.ownerItem === void 0), O$3(`Remove item`, () => this.#remove(e), !y$3(t, this.#node))), n.append(r);
		}
		e.append(n), this.#readOnly || e.append(O$3(`Add list item`, () => this.#add()));
	}
	#syncRows() {
		for (let e of this.#root?.querySelectorAll(`[data-list-text]`) ?? []) {
			let t = Number(e.dataset.listText), n = this.#rows[t];
			if (n === void 0) continue;
			let r = d$5(n.editableBlock.content ?? [], u$5(e));
			n.syntheticEditable ? r.length > 0 && (n.editableBlock.content = r, n.item.content = [n.editableBlock, ...n.item.content ?? []], n.syntheticEditable = !1) : w$3(n.editableBlock.content ?? [], r) || (n.editableBlock.content = r);
		}
	}
	#add() {
		this.#syncRows(), this.#rows.length < 500 && (this.#node.content = [...this.#node.content ?? [], {
			content: [{ type: `paragraph` }],
			type: `listItem`
		}]), this.#renderRows();
	}
	#indent(e) {
		this.#syncRows();
		let t = this.#rows[e];
		if (t === void 0 || !v$3(t)) return;
		let n = t.parentList.content ?? [], r = n.indexOf(t.item), i = n[r - 1];
		if (i === void 0) return;
		n.splice(r, 1);
		let a = i.content?.at(-1), o = a?.type === t.parentList.type ? a : {
			...t.parentList.type === `orderedList` && t.parentList.attrs !== void 0 ? { attrs: structuredClone(t.parentList.attrs) } : {},
			content: [],
			type: t.parentList.type
		};
		o !== a && (i.content = [...i.content ?? [], o]), o.content = [...o.content ?? [], t.item], this.#renderRows();
	}
	#outdent(e) {
		this.#syncRows();
		let t = this.#rows[e];
		if (t?.ownerItem === void 0 || t.parentListParent === void 0) return;
		let n = t.parentList.content ?? [], r = n.indexOf(t.item);
		if (r < 0) return;
		let i = n.splice(r + 1);
		n.splice(r, 1), i.length > 0 && (t.item.content = [...t.item.content ?? [], {
			...t.parentList.type === `orderedList` && t.parentList.attrs !== void 0 ? { attrs: structuredClone(t.parentList.attrs) } : {},
			content: i,
			type: t.parentList.type
		}]), n.length === 0 && b$3(t.ownerItem, t.parentList);
		let a = t.parentListParent.content ?? [], o = a.indexOf(t.ownerItem);
		o < 0 || (a.splice(o + 1, 0, t.item), this.#renderRows());
	}
	#move(e, t) {
		this.#syncRows();
		let n = this.#rows[e];
		if (n === void 0 || !_$3(n, t)) return;
		let r = n.parentList.content ?? [], i = r.indexOf(n.item), [a] = r.splice(i, 1);
		a !== void 0 && r.splice(i + t, 0, a), this.#renderRows();
	}
	#remove(e) {
		this.#syncRows();
		let t = this.#rows[e];
		if (t === void 0 || !y$3(t, this.#node)) return;
		let n = t.parentList.content ?? [], r = n.indexOf(t.item);
		r < 0 || (n.splice(r, 1), n.length === 0 && t.ownerItem !== void 0 && b$3(t.ownerItem, t.parentList), this.#renderRows());
	}
};
var StudioChecklistTool = class {
	static isReadOnlySupported = !0;
	static toolbox = {
		icon: `☑`,
		title: `Checklist`
	};
	#readOnly;
	#initialRows;
	#node;
	#root;
	#rows;
	constructor(e) {
		this.#readOnly = e.readOnly === !0, this.#node = structuredClone(e.data?.node ?? {
			content: [{
				attrs: {
					checked: !1,
					level: 0
				},
				type: `checklistItem`
			}],
			type: `checklist`
		});
		let t = this.#node.content ?? [];
		this.#rows = t.length > 0 ? t.map((e) => ({
			checked: e.attrs?.checked === !0,
			content: structuredClone(e.content ?? []),
			contentPresent: e.content !== void 0,
			depth: Number(e.attrs?.level ?? 0)
		})) : [{
			checked: !1,
			content: [],
			contentPresent: !1,
			depth: 0
		}], this.#initialRows = structuredClone(this.#rows);
	}
	render() {
		return this.#root = T$3(`Checklist`), this.#renderRows(), this.#root;
	}
	save() {
		return this.#syncRows(), w$3(this.#rows, this.#initialRows) ? { node: structuredClone(this.#node) } : { node: {
			content: this.#rows.map((e) => ({
				attrs: {
					checked: e.checked,
					level: e.depth
				},
				...e.contentPresent || e.content.length > 0 ? { content: structuredClone(e.content) } : {},
				type: `checklistItem`
			})),
			type: `checklist`
		} };
	}
	#renderRows() {
		let e = this.#root;
		if (e !== void 0) {
			e.replaceChildren();
			for (let [t, n] of this.#rows.entries()) {
				let r = T$3(`Checklist item ${t + 1}`);
				r.dataset.studioDepth = String(n.depth), r.setAttribute(`aria-level`, String(n.depth + 1));
				let i = document.createElement(`input`);
				i.type = `checkbox`, i.checked = n.checked, i.disabled = this.#readOnly, i.dataset.checkState = String(t), i.setAttribute(`aria-label`, `Checklist item ${t + 1} complete`);
				let a = C$3(`Checklist item ${t + 1}`, n.content, this.#readOnly);
				a.dataset.checkText = String(t), a.addEventListener(`input`, () => {
					n.contentPresent = !0;
				}), r.append(i, a), this.#readOnly || r.append(O$3(`Move item up`, () => this.#move(t, -1), t === 0), O$3(`Move item down`, () => this.#move(t, 1), t === this.#rows.length - 1), O$3(`Indent item`, () => this.#indent(t, 1), n.depth >= 4 || t === 0), O$3(`Outdent item`, () => this.#indent(t, -1), n.depth === 0), O$3(`Remove item`, () => this.#remove(t), this.#rows.length === 1)), e.append(r);
			}
			this.#readOnly || e.append(O$3(`Add checklist item`, () => this.#add()));
		}
	}
	#syncRows() {
		for (let e of this.#root?.querySelectorAll(`[data-check-text]`) ?? []) {
			let t = this.#rows[Number(e.dataset.checkText)];
			t !== void 0 && (t.content = d$5(t.content, u$5(e)));
		}
		for (let e of this.#root?.querySelectorAll(`[data-check-state]`) ?? []) {
			let t = this.#rows[Number(e.dataset.checkState)];
			t !== void 0 && (t.checked = e.checked);
		}
	}
	#add() {
		this.#syncRows(), this.#rows.length < 500 && this.#rows.push({
			checked: !1,
			content: [],
			contentPresent: !1,
			depth: 0
		}), this.#renderRows();
	}
	#indent(e, t) {
		this.#syncRows();
		let n = this.#rows[e];
		n !== void 0 && (n.depth = Math.max(0, Math.min(4, n.depth + t))), this.#renderRows();
	}
	#move(e, t) {
		this.#syncRows();
		let n = e + t;
		if (n >= 0 && n < this.#rows.length) {
			let [t] = this.#rows.splice(e, 1);
			t !== void 0 && this.#rows.splice(n, 0, t);
		}
		this.#renderRows();
	}
	#remove(e) {
		this.#syncRows(), this.#rows.length > 1 && this.#rows.splice(e, 1), this.#renderRows();
	}
};
var StudioTableTool = class {
	static isReadOnlySupported = !0;
	static toolbox = {
		icon: `▦`,
		title: `Table`
	};
	#readOnly;
	#initialCells;
	#initialHeader;
	#node;
	#cells;
	#header;
	#root;
	constructor(e) {
		this.#readOnly = e.readOnly === !0, this.#node = structuredClone(e.data?.node ?? {
			attrs: { header: !1 },
			content: [{
				content: [{ type: `tableCell` }, { type: `tableCell` }],
				type: `tableRow`
			}, {
				content: [{ type: `tableCell` }, { type: `tableCell` }],
				type: `tableRow`
			}],
			type: `table`
		}), this.#header = this.#node.attrs?.header === !0, this.#cells = (this.#node.content ?? []).map((e) => (e.content ?? []).map((e) => ({
			content: structuredClone(e.content ?? []),
			contentPresent: e.content !== void 0
		}))), this.#initialHeader = this.#header, this.#initialCells = structuredClone(this.#cells);
	}
	render() {
		return this.#root = T$3(`Table`), this.#renderTable(), this.#root;
	}
	save() {
		return this.#syncCells(), this.#header === this.#initialHeader && w$3(this.#cells, this.#initialCells) ? { node: structuredClone(this.#node) } : { node: {
			attrs: { header: this.#header },
			content: this.#cells.map((e) => ({
				content: e.map((e) => ({
					...e.contentPresent || e.content.length > 0 ? { content: structuredClone(e.content) } : {},
					type: `tableCell`
				})),
				type: `tableRow`
			})),
			type: `table`
		} };
	}
	#renderTable() {
		let e = this.#root;
		if (e === void 0) return;
		e.replaceChildren();
		let t = document.createElement(`input`);
		t.type = `checkbox`, t.checked = this.#header, t.disabled = this.#readOnly, t.setAttribute(`aria-label`, `Use first row as table header`), t.addEventListener(`change`, () => {
			this.#header = t.checked;
		}), e.append(t);
		let n = document.createElement(`table`);
		n.setAttribute(`aria-label`, `Table data`);
		for (let [e, t] of this.#cells.entries()) {
			let r = document.createElement(`tr`);
			for (let [n, i] of t.entries()) {
				let t = document.createElement(e === 0 && this.#header ? `th` : `td`), a = C$3(`Row ${e + 1}, column ${n + 1}`, i.content, this.#readOnly);
				a.dataset.tableCell = `${e}:${n}`, a.addEventListener(`input`, () => {
					i.contentPresent = !0;
				}), t.append(a), r.append(t);
			}
			n.append(r);
		}
		e.append(n), this.#readOnly || e.append(O$3(`Add table row`, () => this.#resize(1, 0), this.#cells.length >= 200), O$3(`Remove table row`, () => this.#resize(-1, 0), this.#cells.length <= 1), O$3(`Add table column`, () => this.#resize(0, 1), (this.#cells[0]?.length ?? 0) >= 50), O$3(`Remove table column`, () => this.#resize(0, -1), (this.#cells[0]?.length ?? 0) <= 1));
	}
	#resize(e, t) {
		if (this.#syncCells(), e > 0 && this.#cells.length < 200 && this.#cells.push(Array.from({ length: this.#cells[0]?.length ?? 1 }, () => ({
			content: [],
			contentPresent: !1
		}))), e < 0 && this.#cells.length > 1 && this.#cells.pop(), t > 0 && (this.#cells[0]?.length ?? 0) < 50) for (let e of this.#cells) e.push({
			content: [],
			contentPresent: !1
		});
		if (t < 0 && (this.#cells[0]?.length ?? 0) > 1) for (let e of this.#cells) e.pop();
		this.#renderTable();
	}
	#syncCells() {
		for (let e of this.#root?.querySelectorAll(`[data-table-cell]`) ?? []) {
			let [t, n] = (e.dataset.tableCell ?? ``).split(`:`).map(Number), r = t === void 0 ? void 0 : this.#cells[t], i = n === void 0 ? void 0 : r?.[n];
			i !== void 0 && (i.content = d$5(i.content, u$5(e)));
		}
	}
};
var StudioMarkerTool = class {
	static isInline = !0;
	static sanitize = { mark: { "data-studio-tone": !0 } };
	#button;
	#tone = `accent`;
	checkState(e) {
		let t = k$3(e.anchorNode) !== void 0;
		return this.#button?.setAttribute(`aria-pressed`, String(t)), t;
	}
	render() {
		let e = document.createElement(`button`);
		return e.type = `button`, e.textContent = `Highlight`, e.setAttribute(`aria-label`, `Toggle semantic highlight`), e.setAttribute(`aria-pressed`, `false`), this.#button = e, e;
	}
	renderActions() {
		let e = D$2(`Highlight tone`, [
			`accent`,
			`info`,
			`success`,
			`warning`,
			`danger`
		], this.#tone, !1);
		return e.addEventListener(`change`, () => {
			this.#tone = e.value;
		}), e;
	}
	surround(e) {
		let t = k$3(e.commonAncestorContainer);
		if (t !== void 0) {
			let e = t.parentNode;
			for (; t.firstChild !== null;) e?.insertBefore(t.firstChild, t);
			t.remove();
			return;
		}
		if (e.collapsed) return;
		let n = document.createElement(`mark`);
		n.dataset.studioTone = this.#tone, n.append(e.extractContents()), e.insertNode(n);
	}
};
function c$3(e, t) {
	if (t.type === `hardBreak`) {
		e.appendChild(document.createElement(`br`));
		return;
	}
	if (t.type !== `text` || (t.text ?? ``).length === 0) return;
	let n = document.createTextNode(t.text ?? ``);
	for (let e of [...t.marks ?? []].reverse()) {
		let t = document.createElement(l$5(e));
		e.type === `highlight` && (t.dataset.studioTone = j$3(e.attrs?.tone, `accent`)), t.append(n), n = t;
	}
	e.appendChild(n);
}
function l$5(e) {
	return e.type === `bold` ? `strong` : e.type === `italic` ? `em` : e.type === `strike` ? `s` : e.type === `code` ? `code` : `mark`;
}
function u$5(e) {
	let t = [], n = (e, r) => {
		if (e.nodeType === Node.TEXT_NODE) {
			let n = e.nodeValue ?? ``;
			n.length > 0 && t.push({
				...r.length > 0 ? { marks: r } : {},
				text: n,
				type: `text`
			});
			return;
		}
		if (!(e instanceof Element)) return;
		if (e.localName === `br`) {
			t.push({ type: `hardBreak` });
			return;
		}
		let i = [...r], a = p$4(e);
		a !== void 0 && !i.some((e) => e.type === a.type) && (a.type === `code` ? i.splice(0, i.length, a) : i.some((e) => e.type === `code`) || i.push(a));
		for (let t of e.childNodes) n(t, i);
	};
	for (let t of e.childNodes) n(t, []);
	return t;
}
function d$5(e, t) {
	return w$3(f$5(e), f$5(t)) ? structuredClone([...e]) : t;
}
function f$5(e) {
	let t = [];
	for (let n of e) {
		if (n.type === `hardBreak`) {
			t.push({ kind: `hard-break` });
			continue;
		}
		if (n.type !== `text`) continue;
		let e = (n.marks ?? []).map((e) => {
			if (e.type !== `highlight`) return e.type;
			let t = e.attrs?.tone;
			return `${e.type}:${typeof t == `string` ? t : ``}`;
		}).sort(), r = t.at(-1);
		r?.kind === `text` && w$3(r.marks, e) ? r.text += n.text ?? `` : t.push({
			kind: `text`,
			marks: e,
			text: n.text ?? ``
		});
	}
	return t;
}
function p$4(e) {
	if (e.localName === `strong` || e.localName === `b`) return { type: `bold` };
	if (e.localName === `em` || e.localName === `i`) return { type: `italic` };
	if (e.localName === `s` || e.localName === `del`) return { type: `strike` };
	if (e.localName === `code`) return { type: `code` };
	if (e.localName === `mark`) {
		let t = e.getAttribute(`data-studio-tone`);
		return {
			attrs: { tone: [
				`accent`,
				`danger`,
				`info`,
				`success`,
				`warning`
			].includes(t ?? ``) ? t ?? `accent` : `accent` },
			type: `highlight`
		};
	}
}
function m$4(e) {
	e.preventDefault();
	let t = e.clipboardData?.getData(`text/plain`) ?? ``, n = globalThis.getSelection();
	if (n === null || n.rangeCount === 0) return;
	let r = n.getRangeAt(0);
	r.deleteContents(), r.insertNode(document.createTextNode(t.slice(0, 25e4))), r.collapse(!1);
}
function h$3(e, t = 0, n, r) {
	let i = [];
	for (let a of e.content ?? []) {
		let o = (a.content ?? []).find((e) => e.type === `paragraph` || e.type === `heading`), s = o ?? { type: `paragraph` };
		i.push({
			depth: t,
			editableBlock: s,
			item: a,
			...n === void 0 ? {} : { ownerItem: n },
			parentList: e,
			...r === void 0 ? {} : { parentListParent: r },
			syntheticEditable: o === void 0
		});
		for (let n of a.content ?? []) (n.type === `bulletList` || n.type === `orderedList`) && i.push(...h$3(n, t + 1, a, e));
	}
	return i;
}
function g$3(e) {
	let t = Number(e.attrs?.start ?? 1);
	return Number.isSafeInteger(t) && t >= 1 && t <= 1e6 ? t : 1;
}
function _$3(e, t) {
	let n = e.parentList.content ?? [], r = n.indexOf(e.item);
	return r >= 0 && r + t >= 0 && r + t < n.length;
}
function v$3(e) {
	return e.depth >= 4 ? !1 : (e.parentList.content ?? []).indexOf(e.item) > 0;
}
function y$3(e, t) {
	return e.parentList !== t || (t.content?.length ?? 0) > 1;
}
function b$3(e, t) {
	e.content = (e.content ?? []).filter((e) => e !== t);
}
function x$3(e) {
	return e.find((e) => e.type === `paragraph` || e.type === `heading`)?.content ?? [];
}
function S$3(e, t) {
	let n = structuredClone([...e]), r = n.findIndex((e) => e.type === `paragraph` || e.type === `heading`);
	if (r < 0) return t.length > 0 && n.unshift({
		content: structuredClone([...t]),
		type: `paragraph`
	}), n;
	let i = n[r];
	return i !== void 0 && !w$3(i.content ?? [], t) && (i.content = structuredClone([...t])), n;
}
function C$3(e, t, n) {
	let r = document.createElement(`div`);
	r.className = `studio-rich-text-field`, r.contentEditable = n ? `false` : `true`, r.setAttribute(`aria-label`, e), r.setAttribute(`aria-multiline`, `true`), r.setAttribute(`role`, `textbox`), r.spellcheck = !0;
	for (let e of t) c$3(r, e);
	return r.addEventListener(`paste`, m$4), r;
}
function w$3(e, t) {
	if (Object.is(e, t)) return !0;
	if (Array.isArray(e) || Array.isArray(t)) return Array.isArray(e) && Array.isArray(t) && e.length === t.length && e.every((e, n) => w$3(e, t[n]));
	if (!A$3(e) || !A$3(t)) return !1;
	let n = Object.keys(e).sort(), r = Object.keys(t).sort();
	return n.length === r.length && n.every((n, i) => n === r[i] && w$3(e[n], t[n]));
}
function T$3(e) {
	let t = document.createElement(`div`);
	return t.setAttribute(`aria-label`, e), t.setAttribute(`role`, `group`), t;
}
function E$3(e, t, n) {
	let r = document.createElement(`input`);
	return r.type = `text`, r.setAttribute(`aria-label`, e), r.disabled = n, r.value = t, r;
}
function D$2(e, t, n, r) {
	let i = document.createElement(`select`);
	i.setAttribute(`aria-label`, e), i.disabled = r;
	for (let e of t) {
		let t = document.createElement(`option`);
		t.value = e, t.textContent = e, t.selected = e === n, i.append(t);
	}
	return i.value = n, i;
}
function O$3(e, t, n = !1) {
	let r = document.createElement(`button`);
	return r.type = `button`, r.textContent = e, r.setAttribute(`aria-label`, e), r.disabled = n, r.addEventListener(`click`, t), r;
}
function k$3(e) {
	let t = e instanceof HTMLElement ? e : e?.parentElement;
	for (; t != null;) {
		if (t.localName === `mark`) return t;
		t = t.parentElement ?? void 0;
	}
}
function A$3(e) {
	return typeof e == `object` && !!e && !Array.isArray(e);
}
function j$3(e, t) {
	return typeof e == `string` ? e : t;
}
//#endregion
//#region node_modules/@kumwe/studio-rich-text/dist/studio-rich-text-editor.js
var StudioRichTextEditorFactory = class {
	#surfaceAdapter;
	constructor(e = new u$4()) {
		this.#surfaceAdapter = e;
	}
	async create(r) {
		let a = resolveRichTextProfile(r.profile ?? (r.containerType === void 0 ? `studio.rich-text/portable` : resolveContainerRichTextProfile(r.containerType))), o = parseRichTextDocument(r.value, a), s = r.readOnly === !0 || r.binding !== void 0 && r.binding.source.kind !== `static-value`, c = {}, u = Promise.resolve(), d = async () => {
			if (c.surface === void 0) return o;
			return o = parseRichTextDocument(await c.surface.read(), a), o;
		};
		return c.surface = await this.#surfaceAdapter.mount({
			holder: r.holder,
			initialValue: o,
			onChange: () => {
				u = u.then(async () => {
					try {
						let e = await d();
						r.onChange?.({
							diagnostics: [],
							valid: !0,
							value: e
						});
					} catch {
						r.onChange?.({
							diagnostics: [l$4()],
							valid: !1,
							value: o
						});
					}
				});
			},
			...r.placeholder === void 0 ? {} : { placeholder: r.placeholder },
			readOnly: s
		}), {
			destroy: () => c.surface?.destroy(),
			focus: () => c.surface?.focus(),
			readOnly: s,
			replace: async (t) => {
				let n = parseRichTextDocument(t, a);
				await c.surface?.replace(n), o = n;
			},
			save: async () => {
				await u;
				try {
					return await d();
				} catch {
					return o;
				}
			}
		};
	}
};
function l$4() {
	return {
		code: `studio.rich-text/invalid-editor-state`,
		message: {
			defaultMessage: `The latest edit is not valid for this rich-text profile.`,
			key: `studio.rich-text/invalid-editor-state`
		},
		severity: `error`
	};
}
var u$4 = class {
	async mount(e) {
		let t = (await __vitePreload(async () => {
			const { default: __vite_default__ } = await import("./editorjs-BQPU4-8b.js");
			return { default: __vite_default__ };
		}, [])).default, n = new t({
			data: d$4(e.initialValue),
			holder: e.holder,
			inlineToolbar: [
				`bold`,
				`italic`,
				`marker`
			],
			minHeight: 0,
			onChange: e.onChange,
			placeholder: e.placeholder ?? ``,
			readOnly: e.readOnly,
			tools: {
				...studioEditorJsTools(),
				marker: StudioMarkerTool
			}
		});
		return await n.isReady, {
			destroy: () => n.destroy(),
			focus: () => {
				n.caret?.focus(!0);
			},
			read: async () => f$4(await n.save()),
			replace: async (e) => n.render(d$4(e))
		};
	}
};
function d$4(e) {
	return {
		blocks: toStudioEditorJsBlocks(e),
		version: `2.31.6`
	};
}
function f$4(e) {
	return fromStudioEditorJsBlocks(e);
}
//#endregion
//#region node_modules/@kumwe/studio-rich-text/dist/strict-csp-surface.js
var StudioStrictCspRichTextSurfaceAdapter = class {
	mount(e) {
		return Promise.resolve(new i$4(e));
	}
};
var i$4 = class {
	#blocks = document.createElement(`div`);
	#options;
	#root = document.createElement(`section`);
	#mounted = [];
	constructor(e) {
		this.#options = e, this.#root.className = `studio-rich-text-strict-surface`, this.#root.dataset.studioRichTextSurface = `strict-csp`, this.#root.setAttribute(`aria-label`, e.readOnly ? `Rich text preview` : `Rich text editor`), this.#root.setAttribute(`role`, `region`), this.#blocks.className = `studio-rich-text-strict-blocks`, this.#blocks.addEventListener(`change`, this.#notifyChange), this.#blocks.addEventListener(`input`, this.#notifyChange), this.#render(e.initialValue), e.holder.replaceChildren(this.#root);
	}
	destroy() {
		this.#blocks.removeEventListener(`change`, this.#notifyChange), this.#blocks.removeEventListener(`input`, this.#notifyChange), this.#mounted = [], this.#root.remove();
	}
	focus() {
		let e = this.#root.querySelector(`[contenteditable="true"], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), button:not(:disabled)`);
		if (e !== null) {
			e.focus();
			return;
		}
		this.#root.tabIndex = -1, this.#root.focus();
	}
	read() {
		return Promise.resolve(structuredClone(this.#snapshot()));
	}
	replace(e) {
		return this.#render(e), Promise.resolve();
	}
	#notifyChange = () => {
		this.#options.onChange();
	};
	#add(e) {
		let t = this.#snapshot();
		t.content.push(p$3(e)), this.#render(t), this.#options.onChange();
	}
	#move(e, t) {
		let n = this.#snapshot(), r = e + t;
		if (r < 0 || r >= n.content.length) return;
		let [i] = n.content.splice(e, 1);
		i !== void 0 && (n.content.splice(r, 0, i), this.#render(n), this.#options.onChange(), this.#focusBlock(r));
	}
	#remove(e) {
		let t = this.#snapshot();
		t.content.splice(e, 1), t.content.length === 0 && t.content.push(p$3(`paragraph`)), this.#render(t), this.#options.onChange(), this.#focusBlock(Math.min(e, t.content.length - 1));
	}
	#focusBlock(e) {
		this.#blocks.querySelector(`[data-studio-rich-text-index="${String(e)}"]`)?.querySelector(`[contenteditable="true"], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), button:not(:disabled)`)?.focus();
	}
	#render(e) {
		let r = studioEditorJsTools(), i = toStudioEditorJsBlocks(e);
		this.#mounted = i.map((e) => {
			let t = r[e.type];
			return {
				tool: new t({
					data: e.data,
					readOnly: this.#options.readOnly
				}),
				type: e.type
			};
		}), this.#root.replaceChildren(), this.#options.readOnly || this.#root.append(this.#createToolbar()), this.#blocks.replaceChildren(...this.#mounted.map((e, t) => this.#renderBlock(e, t))), this.#root.append(this.#blocks);
	}
	#renderBlock(e, n) {
		let r = document.createElement(`section`), i = studioEditorJsTools()[e.type].toolbox.title;
		if (r.className = `studio-rich-text-strict-block`, r.dataset.studioRichTextIndex = String(n), r.setAttribute(`aria-label`, `${i} block ${String(n + 1)}`), r.setAttribute(`role`, `group`), !this.#options.readOnly) {
			let e = document.createElement(`div`);
			e.className = `studio-rich-text-strict-block-controls`, e.setAttribute(`aria-label`, `${i} block actions`), e.setAttribute(`role`, `toolbar`), e.append(a$3(`Move block up`, () => this.#move(n, -1), n === 0), a$3(`Move block down`, () => this.#move(n, 1), n === this.#mounted.length - 1), a$3(`Remove block`, () => this.#remove(n))), r.append(e);
		}
		return r.append(e.tool.render()), r;
	}
	#createToolbar() {
		let n = studioEditorJsTools(), r = document.createElement(`div`), i = document.createElement(`select`);
		i.setAttribute(`aria-label`, `Rich text block type`);
		for (let t of STUDIO_EDITOR_JS_TOOL_NAMES) {
			let e = document.createElement(`option`);
			e.textContent = n[t].toolbox.title, e.value = t, i.append(e);
		}
		r.className = `studio-rich-text-strict-toolbar`, r.setAttribute(`aria-label`, `Rich text tools`), r.setAttribute(`role`, `toolbar`), r.append(i, a$3(`Add rich text block`, () => {
			d$3(i.value) && this.#add(i.value);
		}));
		let c = s$3();
		return r.append(o$3(`Bold selected text`, () => this.#formatInline(`bold`)), o$3(`Italicize selected text`, () => this.#formatInline(`italic`)), o$3(`Strike selected text`, () => this.#formatInline(`strike`)), o$3(`Format selected text as code`, () => this.#formatInline(`code`)), c, o$3(`Highlight selected text`, () => this.#formatInline(`highlight`, f$3(c.value))), o$3(`Insert line break`, () => this.#formatInline(`hard-break`))), r;
	}
	#formatInline(e, t = `accent`) {
		let n = globalThis.getSelection();
		if (n === null || n.rangeCount === 0) return;
		let r = n.getRangeAt(0), i = c$2(r.startContainer, this.#root), a = c$2(r.endContainer, this.#root);
		if (i === void 0 || i !== a) return;
		if (e === `hard-break`) {
			r.deleteContents();
			let e = document.createElement(`br`);
			r.insertNode(e), r.setStartAfter(e), r.collapse(!0), n.removeAllRanges(), n.addRange(r), this.#options.onChange();
			return;
		}
		if (r.collapsed) return;
		let o = u$3(e), s = l$3(r.commonAncestorContainer, i, o);
		if (s !== void 0) {
			let e = s.parentNode;
			for (; s.firstChild !== null;) e?.insertBefore(s.firstChild, s);
			s.remove(), this.#options.onChange();
			return;
		}
		let d = document.createElement(o);
		e === `highlight` && (d.dataset.studioTone = t), d.append(r.extractContents()), r.insertNode(d), r.selectNodeContents(d), n.removeAllRanges(), n.addRange(r), this.#options.onChange();
	}
	#snapshot() {
		return {
			content: this.#mounted.map((e) => e.tool.save().node),
			type: `doc`
		};
	}
};
function a$3(e, t, n = !1) {
	let r = document.createElement(`button`);
	return r.disabled = n, r.textContent = e, r.type = `button`, r.setAttribute(`aria-label`, e), r.addEventListener(`click`, t), r;
}
function o$3(e, t) {
	let n = a$3(e, t);
	return n.addEventListener(`mousedown`, (e) => e.preventDefault()), n;
}
function s$3() {
	let e = document.createElement(`select`);
	e.setAttribute(`aria-label`, `Highlight tone`);
	for (let t of [
		`accent`,
		`info`,
		`success`,
		`warning`,
		`danger`
	]) {
		let n = document.createElement(`option`);
		n.textContent = t, n.value = t, e.append(n);
	}
	return e;
}
function c$2(e, t) {
	let n = e instanceof HTMLElement ? e : e.parentElement;
	for (; n !== null;) {
		if (n.getAttribute(`contenteditable`) === `true`) return n;
		if (n === t) return;
		n = n.parentElement;
	}
}
function l$3(e, t, n) {
	let r = e instanceof HTMLElement ? e : e.parentElement;
	for (; r !== null && r !== t;) {
		if (r.localName === n) return r;
		r = r.parentElement;
	}
}
function u$3(e) {
	return e === `bold` ? `strong` : e === `italic` ? `em` : e === `strike` ? `s` : e === `code` ? `code` : `mark`;
}
function d$3(t) {
	return STUDIO_EDITOR_JS_TOOL_NAMES.some((e) => e === t);
}
function f$3(e) {
	return [
		`accent`,
		`danger`,
		`info`,
		`success`,
		`warning`
	].includes(e) ? e : `accent`;
}
function p$3(e) {
	switch (e) {
		case `callout`: return {
			attrs: { tone: `info` },
			content: [{ type: `paragraph` }],
			type: `callout`
		};
		case `checklist`: return {
			content: [{
				attrs: {
					checked: !1,
					level: 0
				},
				type: `checklistItem`
			}],
			type: `checklist`
		};
		case `code`: return {
			attrs: { language: `text` },
			type: `codeBlock`
		};
		case `delimiter`: return { type: `horizontalRule` };
		case `header`: return {
			attrs: { level: 2 },
			type: `heading`
		};
		case `list`: return {
			content: [{
				content: [{ type: `paragraph` }],
				type: `listItem`
			}],
			type: `bulletList`
		};
		case `paragraph`: return { type: `paragraph` };
		case `quote`: return {
			content: [{ type: `paragraph` }],
			type: `blockquote`
		};
		case `table`: return {
			attrs: { header: !1 },
			content: [{
				content: [{ type: `tableCell` }, { type: `tableCell` }],
				type: `tableRow`
			}, {
				content: [{ type: `tableCell` }, { type: `tableCell` }],
				type: `tableRow`
			}],
			type: `table`
		};
	}
}
//#endregion
//#region node_modules/@kumwe/studio-rich-text/dist/index.js
var e$1 = Object.freeze([
	`bold`,
	`code`,
	`highlight`,
	`italic`,
	`strike`
]);
var t$1 = Object.freeze([
	`blockquote`,
	`bulletList`,
	`callout`,
	`checklist`,
	`checklistItem`,
	`codeBlock`,
	`doc`,
	`hardBreak`,
	`heading`,
	`horizontalRule`,
	`listItem`,
	`orderedList`,
	`paragraph`,
	`table`,
	`tableCell`,
	`tableRow`,
	`text`
]);
var n$2 = Object.freeze([
	2,
	3,
	4
]);
var r$2 = 1048576;
var i$3 = 2e4;
var a$2 = e$1.length;
var o$2 = Object.freeze({
	maximumDepth: 128,
	maximumDocumentBytes: 10485760,
	maximumMarks: 4e5,
	maximumMarksPerNode: e$1.length,
	maximumNodes: 1e5,
	maximumTextLength: 10485760
});
var s$2 = Object.freeze({
	maximumDepth: 32,
	maximumItemsPerArray: 1e4,
	maximumPropertiesPerObject: 1e3,
	maximumStringLength: 1048576,
	maximumTotalBytes: o$2.maximumDocumentBytes
});
var DEFAULT_RICH_TEXT_ATTRIBUTE_LIMITS = Object.freeze({
	maximumDepth: 8,
	maximumItemsPerArray: 256,
	maximumPropertiesPerObject: 64,
	maximumStringLength: 4096,
	maximumTotalBytes: 65536
});
var DEFAULT_RICH_TEXT_PROFILE = Object.freeze({
	allowedAttributes: Object.freeze({
		callout: Object.freeze([`tone`]),
		checklistItem: Object.freeze([`checked`, `level`]),
		codeBlock: Object.freeze([`language`]),
		heading: Object.freeze([`level`]),
		"mark:highlight": Object.freeze([`tone`]),
		orderedList: Object.freeze([`start`]),
		table: Object.freeze([`header`])
	}),
	allowedMarks: e$1,
	allowedNodes: t$1,
	attributeLimits: DEFAULT_RICH_TEXT_ATTRIBUTE_LIMITS,
	headingLevels: n$2,
	maximumDepth: 32,
	maximumDocumentBytes: r$2,
	maximumMarks: i$3,
	maximumMarksPerNode: a$2,
	maximumNodes: 5e3,
	maximumTextLength: 25e4
});
function parseRichTextDocument(e, t = DEFAULT_RICH_TEXT_PROFILE) {
	V$1(t);
	let n = m$3(e, `$`, 1, t, G$1(t), {
		attributeBytes: 0,
		markCount: 0,
		nodeCount: 0,
		textLength: 0
	});
	if (n.type !== `doc`) throw TypeError(`Rich-text document root must have type "doc".`);
	let r = {
		...n,
		content: n.content ?? [],
		type: `doc`
	};
	if (R$1(JSON.stringify(r)) > W$1(t)) throw RangeError(`Rich-text document exceeds its total-byte limit.`);
	return r;
}
function m$3(e, t, n, r, o, s) {
	if (!B$1(e) || (I$1(e, t, [
		`attrs`,
		`content`,
		`marks`,
		`text`,
		`type`
	]), typeof e.type != `string` || e.type.length === 0)) throw TypeError(`${t} must be a rich-text node with a non-empty type.`);
	if (!r.allowedNodes.includes(e.type)) throw TypeError(`${t} uses disallowed node type "${e.type}".`);
	if (n > r.maximumDepth) throw RangeError(`${t} exceeds the rich-text depth limit.`);
	if (s.nodeCount += 1, s.nodeCount > r.maximumNodes) throw RangeError(`Rich-text document exceeds its node limit.`);
	let c = { type: e.type };
	if (e.text !== void 0) {
		if (typeof e.text != `string`) throw TypeError(`${t}.text must be a string.`);
		if (c.text = e.text, s.textLength += e.text.length, s.textLength > r.maximumTextLength) throw RangeError(`Rich-text document exceeds its text-length limit.`);
	}
	if (e.attrs !== void 0 && (c.attrs = O$2(e.attrs, `${t}.attrs`, e.type, r, o, s)), e.content !== void 0) {
		if (!Array.isArray(e.content)) throw TypeError(`${t}.content must be an array.`);
		F$1(e.content, `${t}.content`), c.content = e.content.map((e, i) => m$3(e, `${t}.content[${i}]`, n + 1, r, o, s));
	}
	if (e.marks !== void 0) {
		if (!Array.isArray(e.marks)) throw TypeError(`${t}.marks must be an array.`);
		F$1(e.marks, `${t}.marks`);
		let n = r.maximumMarksPerNode ?? a$2, l = r.maximumMarks ?? i$3;
		if (e.marks.length > n) throw RangeError(`${t}.marks exceeds the per-node mark limit.`);
		if (s.markCount + e.marks.length > l) throw RangeError(`Rich-text document exceeds its aggregate mark limit.`);
		s.markCount += e.marks.length, c.marks = e.marks.map((e, n) => h$2(e, `${t}.marks[${n}]`, r, o, s)), _$2(c.marks, `${t}.marks`);
	}
	return g$2(c, t, r), c;
}
function h$2(e, t, n, r, i) {
	if (!B$1(e) || (I$1(e, t, [`attrs`, `type`]), typeof e.type != `string` || e.type.length === 0)) throw TypeError(`${t} must be a mark with a non-empty type.`);
	if (!n.allowedMarks.includes(e.type)) throw TypeError(`${t} uses disallowed mark type "${e.type}".`);
	let a = { type: e.type };
	if (e.attrs !== void 0 && (a.attrs = O$2(e.attrs, `${t}.attrs`, `mark:${e.type}`, n, r, i)), a.type === `highlight`) {
		let e = a.attrs?.tone;
		if (typeof e != `string` || ![
			`accent`,
			`danger`,
			`info`,
			`success`,
			`warning`
		].includes(e)) throw TypeError(`${t}.attrs.tone must be a configured highlight tone.`);
	} else if (a.attrs !== void 0) throw TypeError(`${t} cannot carry attributes in the portable rich-text grammar.`);
	return a;
}
function g$2(e, t, r) {
	switch (e.type) {
		case `doc`:
			if (T$2(e, t, [
				`attrs`,
				`marks`,
				`text`
			]), e.content === void 0 || e.content.length === 0) throw TypeError(`${t}.content must contain at least one block node.`);
			E$2(e.content, t, v$2);
			break;
		case `text`:
			if (T$2(e, t, [`attrs`, `content`]), e.text === void 0) throw TypeError(`${t}.text is required for a text node.`);
			if (e.text.length === 0) throw TypeError(`${t}.text cannot be empty.`);
			break;
		case `paragraph`:
			T$2(e, t, [
				`attrs`,
				`marks`,
				`text`
			]), E$2(e.content ?? [], t, y$2);
			break;
		case `heading`: {
			T$2(e, t, [`marks`, `text`]), E$2(e.content ?? [], t, y$2);
			let i = e.attrs?.level, a = r.headingLevels ?? n$2;
			if (typeof i != `number` || !Number.isInteger(i) || !a.includes(i)) throw TypeError(`${t}.attrs.level must be a configured heading level.`);
			break;
		}
		case `orderedList`: {
			T$2(e, t, [`marks`, `text`]), D$1(e.content, t, b$2);
			let n = e.attrs?.start;
			if (n !== void 0 && (!Number.isSafeInteger(n) || Number(n) < 1)) throw TypeError(`${t}.attrs.start must be a positive integer.`);
			break;
		}
		case `bulletList`:
			T$2(e, t, [
				`attrs`,
				`marks`,
				`text`
			]), D$1(e.content, t, b$2);
			break;
		case `listItem`:
			if (T$2(e, t, [
				`attrs`,
				`marks`,
				`text`
			]), D$1(e.content, t, v$2), e.content?.[0]?.type !== `paragraph`) throw TypeError(`${t}.content must begin with a paragraph node.`);
			break;
		case `blockquote`:
			T$2(e, t, [
				`attrs`,
				`marks`,
				`text`
			]), D$1(e.content, t, v$2);
			break;
		case `callout`:
			if (T$2(e, t, [`marks`, `text`]), D$1(e.content, t, v$2), typeof e.attrs?.tone != `string` || ![
				`danger`,
				`info`,
				`success`,
				`warning`
			].includes(e.attrs.tone)) throw TypeError(`${t}.attrs.tone must be a configured callout tone.`);
			break;
		case `checklist`:
			T$2(e, t, [
				`attrs`,
				`marks`,
				`text`
			]), D$1(e.content, t, x$2);
			break;
		case `checklistItem`:
			if (T$2(e, t, [`marks`, `text`]), E$2(e.content ?? [], t, y$2), typeof e.attrs?.checked != `boolean`) throw TypeError(`${t}.attrs.checked must be a boolean.`);
			if (!Number.isSafeInteger(e.attrs.level) || Number(e.attrs.level) < 0 || Number(e.attrs.level) > 4) throw TypeError(`${t}.attrs.level must be an integer from zero through four.`);
			break;
		case `table`:
			if (T$2(e, t, [`marks`, `text`]), D$1(e.content, t, S$2), typeof e.attrs?.header != `boolean`) throw TypeError(`${t}.attrs.header must be a boolean.`);
			w$2(e.content, t);
			break;
		case `tableRow`:
			T$2(e, t, [
				`attrs`,
				`marks`,
				`text`
			]), D$1(e.content, t, C$2);
			break;
		case `tableCell`:
			T$2(e, t, [
				`attrs`,
				`marks`,
				`text`
			]), E$2(e.content ?? [], t, y$2);
			break;
		case `codeBlock`:
			if (T$2(e, t, [`content`, `marks`]), e.text === void 0) throw TypeError(`${t}.text is required for a code block.`);
			if (typeof e.attrs?.language != `string` || !/^[A-Za-z0-9][A-Za-z0-9+_.#-]{0,63}$/u.test(e.attrs.language)) throw TypeError(`${t}.attrs.language must be a bounded language identifier.`);
			break;
		case `hardBreak`:
		case `horizontalRule`:
			T$2(e, t, [
				`attrs`,
				`content`,
				`marks`,
				`text`
			]);
			break;
		default: throw TypeError(`${t} uses a node without a portable grammar.`);
	}
}
function _$2(e, t) {
	let n = /* @__PURE__ */ new Set();
	for (let r of e) {
		if (n.has(r.type)) throw TypeError(`${t} cannot contain duplicate ${r.type} marks.`);
		n.add(r.type);
	}
	if (n.has(`code`) && n.size > 1) throw TypeError(`${t} cannot combine code with another mark.`);
}
var v$2 = /* @__PURE__ */ new Set([
	`blockquote`,
	`bulletList`,
	`callout`,
	`checklist`,
	`codeBlock`,
	`heading`,
	`horizontalRule`,
	`orderedList`,
	`paragraph`,
	`table`
]);
var y$2 = /* @__PURE__ */ new Set([`hardBreak`, `text`]);
var b$2 = /* @__PURE__ */ new Set([`listItem`]);
var x$2 = /* @__PURE__ */ new Set([`checklistItem`]);
var S$2 = /* @__PURE__ */ new Set([`tableRow`]);
var C$2 = /* @__PURE__ */ new Set([`tableCell`]);
function w$2(e, t) {
	let n = e?.[0]?.content?.length ?? 0, r = e?.findIndex((e) => e.content?.length !== n) ?? -1;
	if (n < 1 || r >= 0) throw TypeError(`${t}.content must be a non-empty rectangular table.`);
}
function T$2(e, t, n) {
	let r = n.find((t) => e[t] !== void 0);
	if (r !== void 0) throw TypeError(`${t}.${r} is not valid on a ${e.type} node.`);
}
function E$2(e, t, n) {
	let r = e.findIndex((e) => !n.has(e.type));
	if (r >= 0) throw TypeError(`${t}.content[${r}] is not valid inside this node.`);
}
function D$1(e, t, n) {
	if (e === void 0 || e.length === 0) throw TypeError(`${t}.content must contain at least one child node.`);
	E$2(e, t, n);
}
function O$2(e, t, n, r, i, a) {
	let o = k$2(e, t, 1, i, a), s = r.allowedAttributes[n] ?? [];
	for (let e of Object.keys(o)) if (!s.includes(e)) throw TypeError(`${t}.${e} is not allowed for ${n}.`);
	return o;
}
function k$2(e, t, n, r, i) {
	if (!B$1(e)) throw TypeError(`${t} must be an object.`);
	M$1(n, t, r);
	let a = Object.entries(e);
	if (a.length > r.maximumPropertiesPerObject) throw RangeError(`${t} exceeds the attribute property limit.`);
	j$2(i, r, 2);
	let o = {};
	for (let [e, [s, c]] of a.entries()) N$1(s, t, r), j$2(i, r, (e === 0 ? 0 : 1) + L$1(s) + 1), o[s] = A$2(c, `${t}.${s}`, n + 1, r, i);
	return o;
}
function A$2(e, t, n, r, i) {
	if (typeof e == `string`) {
		if (e.length > r.maximumStringLength) throw RangeError(`${t} exceeds the attribute string limit.`);
		return j$2(i, r, L$1(e)), e;
	}
	if (e === null || typeof e == `boolean` || typeof e == `number` && Number.isFinite(e)) return j$2(i, r, L$1(e)), e;
	if (Array.isArray(e)) return M$1(n, t, r), P$1(e, t, r), j$2(i, r, 2), e.map((e, a) => (a > 0 && j$2(i, r, 1), A$2(e, `${t}[${a}]`, n + 1, r, i)));
	if (B$1(e)) return k$2(e, t, n, r, i);
	throw TypeError(`${t} is not JSON-compatible.`);
}
function j$2(e, t, n) {
	if (e.attributeBytes += n, e.attributeBytes > t.maximumTotalBytes) throw RangeError(`Rich-text attributes exceed the total-byte limit.`);
}
function M$1(e, t, n) {
	if (e > n.maximumDepth) throw RangeError(`${t} exceeds the attribute depth limit.`);
}
function N$1(e, t, n) {
	if (e === `__proto__` || e === `constructor` || e === `prototype`) throw TypeError(`${t}.${e} is a forbidden object key.`);
	if (e.length > n.maximumStringLength) throw RangeError(`${t} contains an attribute key that exceeds the string limit.`);
}
function P$1(e, t, n) {
	if (e.length > n.maximumItemsPerArray) throw RangeError(`${t} exceeds the attribute item limit.`);
	let r = Object.keys(e);
	for (let e of r) if (e === `__proto__` || e === `constructor` || e === `prototype`) throw TypeError(`${t}.${e} is a forbidden object key.`);
	if (r.length !== e.length || r.some((e, t) => e !== String(t))) throw TypeError(`${t} must be a dense JSON array without extra properties.`);
}
function F$1(e, t) {
	if (Object.getPrototypeOf(e) !== Array.prototype || Object.getOwnPropertySymbols(e).length) throw TypeError(`${t} must be a dense JSON array without extra properties.`);
	let n = Object.getOwnPropertyNames(e);
	if (n.length !== e.length + 1 || n[e.length] !== `length` || n.slice(0, -1).some((e, t) => e !== String(t))) throw TypeError(`${t} must be a dense JSON array without extra properties.`);
}
function I$1(e, t, n) {
	let r = new Set(n), i = Object.keys(e).find((e) => !r.has(e));
	if (i !== void 0) throw TypeError(`${t}.${i} is not a recognized rich-text key.`);
}
function L$1(e) {
	let t = JSON.stringify(e);
	if (t === void 0) throw TypeError(`Attribute value is not JSON-compatible.`);
	return R$1(t);
}
function R$1(e) {
	let t = 0;
	for (let n = 0; n < e.length; n += 1) {
		let r = e.charCodeAt(n);
		if (r <= 127) t += 1;
		else if (r <= 2047) t += 2;
		else if (r >= 55296 && r <= 56319) {
			let r = e.charCodeAt(n + 1);
			r >= 56320 && r <= 57343 ? (t += 4, n += 1) : t += 3;
		} else t += 3;
	}
	return t;
}
function B$1(e) {
	if (typeof e != `object` || !e || Array.isArray(e)) return !1;
	let t = Object.getPrototypeOf(e);
	return t === Object.prototype || t === null;
}
function V$1(r) {
	for (let [e, t] of [
		[`maximumDepth`, r.maximumDepth],
		[`maximumNodes`, r.maximumNodes],
		[`maximumTextLength`, r.maximumTextLength]
	]) H$1(e, t, o$2[e]);
	for (let [e, t] of [
		[`maximumDocumentBytes`, W$1(r)],
		[`maximumMarks`, r.maximumMarks ?? i$3],
		[`maximumMarksPerNode`, r.maximumMarksPerNode ?? a$2]
	]) H$1(e, t, o$2[e]);
	if ((r.maximumMarksPerNode ?? a$2) > (r.maximumMarks ?? i$3)) throw RangeError(`maximumMarksPerNode cannot exceed maximumMarks.`);
	if (!r.allowedNodes.includes(`doc`) || !r.allowedNodes.includes(`text`)) throw TypeError(`Rich-text profile must allow doc and text nodes.`);
	if (new Set(r.allowedNodes).size !== r.allowedNodes.length) throw TypeError(`Rich-text profile node names must be unique.`);
	if (new Set(r.allowedMarks).size !== r.allowedMarks.length) throw TypeError(`Rich-text profile mark names must be unique.`);
	let s = r.allowedNodes.find((e) => !t$1.includes(e));
	if (s !== void 0) throw TypeError(`Rich-text profile node "${s}" has no portable grammar.`);
	let c = r.allowedMarks.find((t) => !e$1.includes(t));
	if (c !== void 0) throw TypeError(`Rich-text profile mark "${c}" has no portable grammar.`);
	U$1(r.headingLevels ?? n$2), G$1(r);
}
function H$1(e, t, n) {
	if (!Number.isInteger(t) || t < 1) throw RangeError(`${e} must be a positive integer.`);
	if (t > n) throw RangeError(`${e} exceeds the immutable safety ceiling of ${n}.`);
}
function U$1(e) {
	if (e.length === 0 || new Set(e).size !== e.length || e.some((e) => !Number.isInteger(e) || e < 1 || e > 6)) throw RangeError(`headingLevels must contain unique integer levels from 1 through 6.`);
}
function W$1(e) {
	return e.maximumDocumentBytes ?? r$2;
}
function G$1(e) {
	let t = {
		...DEFAULT_RICH_TEXT_ATTRIBUTE_LIMITS,
		...e.attributeLimits
	};
	for (let [e, n] of Object.entries(t)) H$1(e, n, s$2[e]);
	return t;
}
//#endregion
//#region node_modules/@kumwe/studio-renderer-web/dist/scoped-css.js
var e = Object.freeze({
	action: `[data-studio-part="action"]`,
	content: `[data-studio-part="content"]`,
	heading: `[data-studio-part="heading"]`,
	media: `[data-studio-part="media"]`,
	self: ``
});
var t = /* @__PURE__ */ new Set([
	`background-color`,
	`border-color`,
	`border-radius`,
	`border-style`,
	`border-width`,
	`color`,
	`font-family`,
	`font-size`,
	`font-style`,
	`font-weight`,
	`gap`,
	`letter-spacing`,
	`line-height`,
	`margin-block`,
	`margin-inline`,
	`max-inline-size`,
	`min-block-size`,
	`opacity`,
	`padding-block`,
	`padding-inline`,
	`text-align`,
	`text-decoration`,
	`text-transform`
]);
var n$1 = /^(?:#[0-9A-Fa-f]{3,8}|-?[0-9]+(?:\.[0-9]+)?(?:ch|em|rem|%|px)?|[a-z][a-z0-9 -]{0,126}|var\(--studio-[a-z0-9-]{1,100}\))$/u;
function compileStudioScopedStyleSheet(r, i) {
	if (!/^[A-Za-z][A-Za-z0-9_-]{0,511}$/u.test(r)) throw TypeError(`Scoped CSS scope must be a bounded CSS-safe identifier.`);
	if (i.rules.length > 100) throw RangeError(`Scoped stylesheet exceeds 100 rules.`);
	let a = `[data-studio-scope=${r}]`;
	return i.rules.map((r) => {
		if (!Object.hasOwn(e, r.target)) throw TypeError(`Scoped CSS target ${r.target} is not allowed.`);
		let i = Object.entries(r.declarations);
		if (i.length > 50) throw RangeError(`Scoped style rule exceeds 50 declarations.`);
		let o = i.sort(([e], [t]) => e.localeCompare(t)).map(([e, r]) => {
			if (!t.has(e)) throw TypeError(`Scoped CSS property ${e} is not allowed.`);
			if (r.length > 256 || !n$1.test(r) || /(?:url|expression|javascript|@|[;{}])/iu.test(r)) throw TypeError(`Scoped CSS value for ${e} is not allowed.`);
			return `${e}:${r}`;
		}).join(`;`);
		return `${a}${e[r.target]}{${o}}`;
	}).join(``);
}
//#endregion
//#region node_modules/@kumwe/studio-media/dist/media-library.js
var MEDIA_PROVIDER_FAILURE = Object.freeze({
	defaultMessage: `The media library could not be loaded.`,
	key: `studio.media/provider-failed`
});
var MediaLibrary = class {
	#listeners = /* @__PURE__ */ new Set();
	#provider;
	#abortController;
	#state = {
		assets: [],
		status: `idle`
	};
	constructor(e) {
		this.#provider = e;
	}
	get state() {
		return structuredClone(this.#state);
	}
	dispose() {
		this.#abortController?.abort(), this.#listeners.clear();
	}
	async loadNext() {
		return this.#state.query === void 0 || this.#state.nextCursor === void 0 ? this.state : this.#load({
			...this.#state.query,
			cursor: this.#state.nextCursor
		}, [...this.#state.assets]);
	}
	async search(e) {
		let t = { limit: Math.max(1, Math.min(100, Math.trunc(e.limit))) };
		return e.mediaTypes !== void 0 && (t.mediaTypes = [...e.mediaTypes]), e.search !== void 0 && (t.search = e.search), this.#load(t, []);
	}
	subscribe(e) {
		return this.#listeners.add(e), e(this.state), () => {
			this.#listeners.delete(e);
		};
	}
	async #load(t, n) {
		this.#abortController?.abort();
		let r = new AbortController();
		this.#abortController = r, this.#setState({
			assets: n,
			query: t,
			status: `loading`
		});
		try {
			let e = await this.#provider.list(t, r.signal);
			if (r.signal.aborted) return this.state;
			let i = {
				assets: [...n, ...e.assets],
				query: t,
				status: `ready`
			};
			e.nextCursor !== void 0 && (i.nextCursor = e.nextCursor), this.#setState(i);
		} catch {
			r.signal.aborted || this.#setState({
				assets: n,
				error: { ...MEDIA_PROVIDER_FAILURE },
				query: t,
				status: `error`
			});
		}
		return this.state;
	}
	#setState(e) {
		this.#state = e;
		for (let e of this.#listeners) e(this.state);
	}
};
//#endregion
//#region node_modules/@kumwe/studio-media/dist/upload-controller.js
var MEDIA_UPLOAD_FAILURE = Object.freeze({
	defaultMessage: `The upload could not be completed.`,
	key: `studio.media/upload-failed`
});
var MEDIA_UPLOAD_TOO_LARGE = Object.freeze({
	defaultMessage: `The file is larger than the host allows for this upload.`,
	key: `studio.media/upload-too-large`
});
var r$1 = /* @__PURE__ */ new Set([
	`authorized`,
	`requested`,
	`transferring`,
	`verifying`
]);
var MediaUploadController = class {
	#listeners = /* @__PURE__ */ new Set();
	#sessionId;
	#transport;
	#abortController;
	#file;
	#session;
	constructor(e, t) {
		this.#transport = e, this.#sessionId = t?.sessionId ?? (() => crypto.randomUUID());
	}
	get session() {
		if (this.#session === void 0) throw Error(`No upload session has been started.`);
		return structuredClone(this.#session);
	}
	cancel() {
		let e = this.#session;
		e === void 0 || !r$1.has(e.state) || (this.#abortController?.abort(), this.#setSession({
			...structuredClone(e),
			state: `cancelled`
		}), this.#transport.abort(e.id).catch(() => void 0));
	}
	async retry() {
		let e = this.#session, t = this.#file;
		if (e?.state !== `failed` || t === void 0) throw Error(`Only a failed upload session can be retried.`);
		return this.#run(t, structuredClone(e.request));
	}
	subscribe(e) {
		return this.#listeners.add(e), this.#session !== void 0 && e(this.session), () => {
			this.#listeners.delete(e);
		};
	}
	async upload(e, t) {
		if (this.#session !== void 0 && r$1.has(this.#session.state)) throw Error(`An upload session is already in progress.`);
		if (e.size < 1) throw Error(`Cannot upload an empty file.`);
		let n = {
			byteSize: e.size,
			filename: t.filename,
			mediaType: t.mediaType,
			purpose: t.purpose
		};
		return t.checksum !== void 0 && (n.checksum = t.checksum), this.#file = e, this.#run(e, n);
	}
	#fail() {
		let e = this.#session;
		if (e === void 0) return;
		let n = {
			contractVersion: e.contractVersion,
			failure: {
				code: `studio.media/upload-failed`,
				message: { ...MEDIA_UPLOAD_FAILURE },
				severity: `error`
			},
			id: e.id,
			kind: `media-upload-session`,
			progress: { ...e.progress },
			request: { ...e.request },
			state: `failed`
		};
		e.plan !== void 0 && (n.plan = { ...e.plan }), this.#setSession(n);
	}
	async #run(t, r) {
		let i = new AbortController();
		this.#abortController = i;
		let a = r.byteSize, o = {
			contractVersion: STUDIO_CONTRACT_VERSION,
			id: this.#sessionId(r),
			kind: `media-upload-session`,
			request: r
		};
		this.#setSession({
			...o,
			progress: {
				totalBytes: a,
				transferredBytes: 0
			},
			state: `requested`
		});
		try {
			let e = await this.#transport.authorize(r, i.signal);
			if (i.signal.aborted) return this.session;
			if (a > e.maximumBytes) return this.#setSession({
				...o,
				failure: {
					code: `studio.media/upload-too-large`,
					message: { ...MEDIA_UPLOAD_TOO_LARGE },
					parameters: {
						byteSize: a,
						maximumBytes: e.maximumBytes
					},
					severity: `error`
				},
				plan: e,
				progress: {
					totalBytes: a,
					transferredBytes: 0
				},
				state: `failed`
			}), this.session;
			this.#setSession({
				...o,
				plan: e,
				progress: {
					totalBytes: a,
					transferredBytes: 0
				},
				state: `authorized`
			}), this.#setSession({
				...o,
				plan: e,
				progress: {
					totalBytes: a,
					transferredBytes: 0
				},
				state: `transferring`
			});
			let s = Math.max(1, e.chunkBytes ?? a), c = 0;
			for (; c < a;) {
				let n = t.slice(c, Math.min(c + s, a));
				if (await this.#transport.transfer({
					data: n,
					offset: c,
					sessionId: o.id
				}, i.signal), i.signal.aborted) return this.session;
				c = Math.min(c + n.size, a), this.#setSession({
					...o,
					plan: e,
					progress: {
						totalBytes: a,
						transferredBytes: c
					},
					state: `transferring`
				});
			}
			this.#setSession({
				...o,
				plan: e,
				progress: {
					totalBytes: a,
					transferredBytes: a
				},
				state: `verifying`
			});
			let l = await this.#transport.finalize(o.id, i.signal);
			if (i.signal.aborted) return this.session;
			this.#setSession({
				...o,
				asset: l,
				plan: e,
				progress: {
					totalBytes: a,
					transferredBytes: a
				},
				state: `complete`
			});
		} catch {
			i.signal.aborted || this.#fail();
		}
		return this.session;
	}
	#setSession(e) {
		this.#session = e;
		for (let e of this.#listeners) e(this.session);
	}
};
//#endregion
//#region node_modules/@kumwe/studio-media/dist/validate-media-reference.js
function validateMediaReference(e) {
	let t = [], n = e.cropIntent;
	return n?.mode === `rectangle` && (n.x + n.width > 1 || n.y + n.height > 1) && t.push({
		code: `studio.media/crop-out-of-bounds`,
		location: { artifactId: e.assetId },
		message: {
			defaultMessage: `The crop rectangle extends beyond the source media bounds.`,
			key: `studio.media/crop-out-of-bounds`
		},
		severity: `error`
	}), t;
}
//#endregion
//#region node_modules/@kumwe/studio-media/dist/media-field.js
var StudioMediaFieldController = class {
	#library;
	#listeners = /* @__PURE__ */ new Set();
	#mediaTypes;
	#onChange;
	#provider;
	#readOnly;
	#upload;
	#usage;
	#asset;
	#libraryState = {
		assets: [],
		status: `idle`
	};
	#status;
	#uploadState;
	#value;
	constructor(e) {
		this.#provider = e.provider, this.#usage = e.usage, this.#mediaTypes = e.mediaTypes === void 0 ? void 0 : [...e.mediaTypes], this.#onChange = e.onChange, this.#readOnly = e.readOnly === !0 || e.binding !== void 0 && e.binding.source.kind !== `static-value`, this.#value = e.value === void 0 ? void 0 : structuredClone(e.value), this.#upload = e.uploadTransport === void 0 ? void 0 : new MediaUploadController(e.uploadTransport), this.#status = this.#value === void 0 ? `empty` : `browsing`, this.#library = new MediaLibrary(e.provider), this.#library.subscribe((e) => {
			this.#libraryState = e, e.status === `error` && (this.#status = `error`), this.#emit(!1);
		}), this.#upload !== void 0 && this.#upload.subscribe((e) => {
			this.#uploadState = e, this.#status = e.state === `failed` ? `error` : e.state === `complete` ? `browsing` : `uploading`, this.#emit(!1);
		});
	}
	get state() {
		let e = {
			diagnostics: this.#value === void 0 ? [] : validateMediaReference(this.#value),
			library: structuredClone(this.#libraryState),
			readOnly: this.#readOnly,
			status: this.#status
		};
		return this.#asset !== void 0 && (e.asset = structuredClone(this.#asset)), this.#uploadState !== void 0 && (e.upload = structuredClone(this.#uploadState)), this.#value !== void 0 && (e.value = structuredClone(this.#value)), e;
	}
	cancelUpload() {
		this.#assertMutable(), this.#upload?.cancel();
	}
	clear() {
		this.#assertMutable(), this.#asset = void 0, this.#value = void 0, this.#status = `empty`, this.#emit(!0);
	}
	dispose() {
		this.#library.dispose(), this.#listeners.clear();
	}
	async drop(e) {
		return this.#uploadFiles([...e]);
	}
	async loadNext() {
		return this.#status = `browsing`, await this.#library.loadNext(), this.state;
	}
	async open() {
		return this.search(``);
	}
	async paste(e) {
		let t = [...e].filter((e) => e.kind === `file`).map((e) => e.getAsFile()).filter((e) => e !== null);
		return this.#uploadFiles(t);
	}
	async resolve() {
		if (this.#value === void 0) return this.state;
		this.#status = `browsing`, this.#emit(!1);
		try {
			let e = await this.#provider.get(this.#value.assetId);
			e === null ? (this.#asset = void 0, this.#status = `orphaned`) : (this.#asset = e, this.#status = e.state === `rejected` || e.state === `quarantined` ? `error` : `ready`);
		} catch {
			this.#status = `error`;
		}
		return this.#emit(!1), this.state;
	}
	async retryUpload() {
		if (this.#assertMutable(), this.#upload === void 0) throw Error(`This media field has no upload transport.`);
		let e = await this.#upload.retry();
		return await this.#acceptCompletedUpload(e), this.state;
	}
	async search(e) {
		return this.#status = `browsing`, await this.#library.search({
			limit: 40,
			...this.#mediaTypes === void 0 ? {} : { mediaTypes: this.#mediaTypes },
			...e.trim().length === 0 ? {} : { search: e.trim().slice(0, 500) }
		}), this.state;
	}
	select(t) {
		this.#assertMutable(), this.#asset = structuredClone(t);
		let n = t.metadata.altText?.trim();
		this.#value = {
			accessibility: {
				altText: n === void 0 || n.length === 0 ? t.filename : n,
				...t.metadata.caption === void 0 ? {} : { caption: t.metadata.caption },
				mode: `informative`
			},
			assetId: t.id,
			assetRevision: t.revision,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: `media-reference`,
			usage: this.#usage
		}, this.#status = t.state === `ready` ? `ready` : t.state === `processing` ? `uploading` : `error`, this.#emit(!0);
	}
	setAltText(e) {
		this.#assertMutable();
		let t = this.#requireValue(), n = t.accessibility.mode === `informative` ? t.accessibility.caption : void 0;
		t.accessibility = {
			altText: e.trim().slice(0, 5e3),
			...n === void 0 ? {} : { caption: n },
			mode: `informative`
		}, this.#emit(!0);
	}
	setCaption(e) {
		this.#assertMutable();
		let t = this.#requireValue();
		t.accessibility.mode === `informative` && (t.accessibility = {
			...t.accessibility,
			...e === void 0 || e.length === 0 ? {} : { caption: e.slice(0, 2e4) }
		}, this.#emit(!0));
	}
	setDecorative(e) {
		this.#assertMutable();
		let t = this.#requireValue();
		if (e) t.accessibility = { mode: `decorative` };
		else {
			let e = this.#asset?.metadata.altText?.trim() ?? ``, n = this.#asset?.filename ?? ``;
			t.accessibility = {
				altText: e.length > 0 ? e : n.length > 0 ? n : `Media`,
				mode: `informative`
			};
		}
		this.#emit(!0);
	}
	setFocalPoint(e) {
		this.#assertMutable();
		let t = this.#requireValue();
		e === void 0 ? delete t.focalPoint : t.focalPoint = {
			x: i$2(e.x),
			y: i$2(e.y)
		}, this.#emit(!0);
	}
	setRenditionIntent(e) {
		this.#assertMutable();
		let t = this.#requireValue();
		e === void 0 ? delete t.renditionIntent : t.renditionIntent = structuredClone(e), this.#emit(!0);
	}
	subscribe(e) {
		return this.#listeners.add(e), e(this.state), () => {
			this.#listeners.delete(e);
		};
	}
	async upload(e) {
		return this.#uploadFiles([e]);
	}
	#assertMutable() {
		if (this.#readOnly) throw Error(`Dynamic and read-only media fields cannot be mutated.`);
	}
	async #acceptCompletedUpload(t) {
		if (t.state !== `complete` || t.asset === void 0) return;
		let n = await this.#provider.get(t.asset.id);
		if (n === null) {
			this.#value = {
				accessibility: {
					altText: t.request.filename,
					mode: `informative`
				},
				assetId: t.asset.id,
				assetRevision: t.asset.revision,
				contractVersion: STUDIO_CONTRACT_VERSION,
				kind: `media-reference`,
				usage: this.#usage
			}, this.#asset = void 0, this.#status = `orphaned`, this.#emit(!0);
			return;
		}
		this.select(n);
	}
	#emit(e) {
		let t = this.state;
		e && this.#onChange?.(t);
		for (let e of this.#listeners) e(t);
	}
	#requireValue() {
		if (this.#value === void 0) throw Error(`Select media before editing its usage.`);
		return this.#value;
	}
	async #uploadFiles(e) {
		this.#assertMutable();
		let t = e[0];
		if (t === void 0) return this.state;
		if (this.#mediaTypes !== void 0 && !this.#mediaTypes.includes(t.type)) throw TypeError(`Media type "${t.type}" is not accepted by this field.`);
		if (this.#status = `uploading`, this.#emit(!1), this.#upload === void 0) {
			let e = await this.#provider.upload({
				alt: t.name,
				file: t
			});
			return this.select(e), this.state;
		}
		let n = await this.#upload.upload(t, {
			filename: t.name,
			mediaType: t.type || `application/octet-stream`,
			purpose: this.#usage
		});
		return await this.#acceptCompletedUpload(n), this.state;
	}
};
function i$2(e) {
	if (!Number.isFinite(e)) throw TypeError(`Focal coordinates must be finite numbers.`);
	return Math.max(0, Math.min(1, e));
}
//#endregion
//#region node_modules/@kumwe/studio/dist/media-authoring-control.js
function mountStudioMediaReferenceControl(e, t) {
	return new i$1(e, t);
}
function mountStudioMediaCollectionControl(e, t) {
	return new a$1(e, t);
}
var i$1 = class {
	#controller;
	#holder;
	#onChange;
	#unsubscribe;
	#uploadsEnabled;
	readOnly;
	#error;
	#lastValid;
	constructor(t, n) {
		this.#holder = x$1(t.holder, `Media picker`), this.#onChange = t.onChange, this.#uploadsEnabled = n.uploadsEnabled !== !1, this.#lastValid = o$1(t.value);
		let r = n.uploadTransportFactory?.() ?? n.uploadTransport;
		this.#controller = new StudioMediaFieldController({
			...t.binding === void 0 ? {} : { binding: t.binding },
			...t.mediaTypes === void 0 ? {} : { mediaTypes: [...t.mediaTypes] },
			onChange: (e) => this.#acceptChange(e),
			provider: n.provider,
			...t.readOnly === void 0 ? {} : { readOnly: t.readOnly },
			...r === void 0 ? {} : { uploadTransport: r },
			usage: t.usage ?? this.#lastValid?.usage ?? `studio.media/content`,
			...this.#lastValid === void 0 ? {} : { value: this.#lastValid }
		}), this.readOnly = this.#controller.state.readOnly, this.#holder.addEventListener(`dragover`, (e) => {
			!this.readOnly && this.#uploadsEnabled && e.preventDefault();
		}), this.#holder.addEventListener(`drop`, (e) => {
			this.readOnly || !this.#uploadsEnabled || e.dataTransfer === null || (e.preventDefault(), this.#run(() => this.#controller.drop(e.dataTransfer?.files ?? [])));
		}), this.#holder.addEventListener(`paste`, (e) => {
			this.readOnly || !this.#uploadsEnabled || e.clipboardData === null || this.#run(() => this.#controller.paste(e.clipboardData?.items ?? []));
		}), this.#unsubscribe = this.#controller.subscribe(() => this.#render()), this.#lastValid === void 0 ? this.#run(() => this.#controller.open()) : this.#run(() => this.#controller.resolve());
	}
	destroy() {
		this.#unsubscribe(), this.#controller.dispose(), this.#holder.remove();
	}
	focus() {
		this.#holder.querySelector(`[aria-label="Search media"]`)?.focus();
	}
	value() {
		return this.#lastValid === void 0 ? void 0 : structuredClone(this.#lastValid);
	}
	#acceptChange(e) {
		let t = !e.diagnostics.some((e) => e.severity === `error`);
		if (t && e.value !== void 0) try {
			this.#lastValid = c$1(e.value);
		} catch {
			t = !1;
		}
		else t && (this.#lastValid = void 0);
		this.#onChange?.({
			valid: t,
			value: this.value()
		});
	}
	#render() {
		let e = this.#controller.state, t = document.createElement(`section`);
		t.setAttribute(`aria-label`, `Media library`);
		let n = S$1(`Search media`, ``, this.readOnly), r = T$1(`Search media library`, () => {
			this.#run(() => this.#controller.search(n.value));
		});
		r.disabled = this.readOnly, t.append(n, r);
		for (let n of e.library.assets) t.append(T$1(`Select ${E$1(n)}`, () => this.#controller.select(n), this.readOnly));
		e.library.nextCursor !== void 0 && t.append(T$1(`Load more media`, () => {
			this.#run(() => this.#controller.loadNext());
		}, this.readOnly));
		let i = document.createElement(`input`);
		i.type = `file`, i.setAttribute(`aria-label`, `Upload media`), i.disabled = this.readOnly || !this.#uploadsEnabled, e.library.query?.mediaTypes !== void 0 && (i.accept = e.library.query.mediaTypes.join(`,`)), i.addEventListener(`change`, () => {
			if (!this.#uploadsEnabled) return;
			let e = i.files?.[0];
			e !== void 0 && this.#run(() => this.#controller.upload(e));
		}), t.append(i);
		let a = document.createElement(`p`);
		a.setAttribute(`role`, e.status === `error` ? `alert` : `status`), a.setAttribute(`aria-live`, `polite`), a.textContent = this.#statusText(e), t.append(a), e.upload?.state === `failed` && t.append(T$1(`Retry media upload`, () => {
			this.#run(() => this.#controller.retryUpload());
		}, this.readOnly)), e.upload !== void 0 && ![
			`cancelled`,
			`complete`,
			`failed`
		].includes(e.upload.state) && t.append(T$1(`Cancel media upload`, () => this.#controller.cancelUpload()));
		let o = document.createElement(`section`);
		o.setAttribute(`aria-label`, `Selected media usage`), e.value === void 0 ? o.append(document.createTextNode(`No media selected.`)) : this.#renderUsage(o, e), this.#holder.replaceChildren(t, o);
	}
	#renderUsage(e, t) {
		let n = t.value;
		if (n === void 0) return;
		let r = document.createElement(`p`);
		r.textContent = t.status === `orphaned` ? `Missing media ${n.assetId}. Select a replacement.` : `Selected media ${t.asset?.filename ?? n.assetId}.`, e.append(r);
		let i = document.createElement(`input`);
		i.type = `checkbox`, i.checked = n.accessibility.mode === `decorative`, i.disabled = this.readOnly, i.setAttribute(`aria-label`, `Media is decorative`), i.addEventListener(`change`, () => this.#controller.setDecorative(i.checked)), e.append(i);
		let a = n.accessibility, o = a.mode === `informative`, s = S$1(`Media alternative text`, a.mode === `informative` ? a.altText : ``, this.readOnly || !o);
		s.maxLength = 5e3, s.addEventListener(`input`, () => this.#controller.setAltText(s.value));
		let c = S$1(`Media caption`, a.mode === `informative` ? a.caption ?? `` : ``, this.readOnly || !o);
		c.maxLength = 2e4, c.addEventListener(`input`, () => this.#controller.setCaption(c.value)), e.append(s, c);
		let l = C$1(`Media focal point x`, n.focalPoint?.x ?? .5, this.readOnly), u = C$1(`Media focal point y`, n.focalPoint?.y ?? .5, this.readOnly), d = () => {
			this.#controller.setFocalPoint({
				x: l.valueAsNumber,
				y: u.valueAsNumber
			});
		};
		l.addEventListener(`change`, d), u.addEventListener(`change`, d), e.append(l, u);
		let f = S$1(`Media rendition role`, n.renditionIntent?.role ?? `content`, this.readOnly);
		f.maxLength = 64;
		let p = w$1(`Media rendition fit`, [
			`contain`,
			`cover`,
			`fill`,
			`scale-down`
		], n.renditionIntent?.fit ?? `cover`, this.readOnly), m = () => {
			this.#controller.setRenditionIntent({
				fit: p.value,
				role: f.value.trim() || `content`
			});
		};
		f.addEventListener(`change`, m), p.addEventListener(`change`, m), e.append(f, p), e.append(T$1(`Replace media`, () => this.focus(), this.readOnly), T$1(`Clear media`, () => this.#controller.clear(), this.readOnly));
	}
	async #run(e) {
		try {
			this.#error = void 0, await e();
		} catch (e) {
			this.#error = e instanceof Error ? e.message : `Media operation failed.`, this.#render();
		}
	}
	#statusText(e) {
		if (this.#error !== void 0) return this.#error;
		if (e.upload !== void 0 && e.status === `uploading`) {
			let { totalBytes: t, transferredBytes: n } = e.upload.progress;
			return `Uploading media: ${n} of ${t} bytes.`;
		}
		switch (e.status) {
			case `browsing`: return `Browse, search, paste, drop, or upload media.`;
			case `empty`: return `No media selected.`;
			case `error`: return `The media operation needs attention.`;
			case `orphaned`: return `The stored media reference is missing. Select a replacement.`;
			case `ready`: return `Media is ready.`;
			case `uploading`: return `Media is processing.`;
		}
	}
};
var a$1 = class {
	#holder;
	#list;
	#onChange;
	#picker;
	readOnly;
	#lastValid;
	constructor(e, t) {
		this.#holder = x$1(e.holder, `Media collection editor`), this.#list = document.createElement(`ol`), this.#list.setAttribute(`aria-label`, `Selected media order`), this.#onChange = e.onChange, this.#lastValid = s$1(e.value), this.readOnly = b$1(e);
		let n = document.createElement(`div`);
		this.#picker = new i$1({
			...e.binding === void 0 ? {} : { binding: e.binding },
			holder: n,
			...e.mediaTypes === void 0 ? {} : { mediaTypes: e.mediaTypes },
			onChange: (e) => {
				e.valid && e.value !== void 0 && this.#append(c$1(e.value));
			},
			readOnly: this.readOnly,
			usage: e.usage ?? `studio.media/collection`,
			value: void 0
		}, t), this.#holder.append(n, this.#list), this.#render();
	}
	destroy() {
		this.#picker.destroy(), this.#holder.remove();
	}
	focus() {
		this.#picker.focus();
	}
	value() {
		return structuredClone(this.#lastValid);
	}
	#append(e) {
		this.readOnly || this.#lastValid.length >= 100 || this.#lastValid.some((t) => t.assetId === e.assetId) || this.#commit([...this.#lastValid, c$1(e)]);
	}
	#commit(e) {
		try {
			this.#lastValid = s$1(e), this.#onChange?.({
				valid: !0,
				value: this.value()
			}), this.#render();
		} catch {
			this.#onChange?.({
				valid: !1,
				value: this.value()
			});
		}
	}
	#move(e, t) {
		let n = e + t;
		if (this.readOnly || n < 0 || n >= this.#lastValid.length) return;
		let r = [...this.#lastValid], i = r[e], a = r[n];
		i !== void 0 && a !== void 0 && (r[e] = a, r[n] = i, this.#commit(r));
	}
	#render() {
		this.#list.replaceChildren();
		for (let [e, t] of this.#lastValid.entries()) {
			let n = document.createElement(`li`), r = document.createElement(`p`);
			r.textContent = `${e + 1}. ${t.assetId}`, n.append(r);
			let i = document.createElement(`input`);
			i.type = `checkbox`, i.checked = t.accessibility.mode === `decorative`, i.disabled = this.readOnly, i.setAttribute(`aria-label`, `Media ${e + 1} is decorative`), i.addEventListener(`change`, () => {
				let t = structuredClone(this.#lastValid), n = t[e];
				n !== void 0 && (n.accessibility = i.checked ? { mode: `decorative` } : {
					altText: n.assetId,
					mode: `informative`
				}, this.#commit(t));
			}), n.append(i);
			let a = S$1(`Media ${e + 1} alternative text`, t.accessibility.mode === `informative` ? t.accessibility.altText : ``, this.readOnly || t.accessibility.mode !== `informative`);
			a.addEventListener(`change`, () => {
				let t = structuredClone(this.#lastValid), n = t[e];
				n?.accessibility.mode === `informative` && (n.accessibility.altText = a.value, this.#commit(t));
			}), n.append(a), n.append(T$1(`Move media ${e + 1} up`, () => this.#move(e, -1), this.readOnly || e === 0), T$1(`Move media ${e + 1} down`, () => this.#move(e, 1), this.readOnly || e === this.#lastValid.length - 1), T$1(`Remove media ${e + 1}`, () => this.#commit(this.#lastValid.filter((t, n) => n !== e)), this.readOnly)), this.#list.append(n);
		}
	}
};
function o$1(e) {
	if (e != null) return c$1(e);
}
function s$1(e) {
	if (!Array.isArray(e) || e.length > 100) throw RangeError(`Media collection must contain at most 100 references.`);
	return e.map(c$1);
}
function c$1(e) {
	let n = m$2(e, [
		`accessibility`,
		`assetId`,
		`assetRevision`,
		`contractVersion`,
		`cropIntent`,
		`focalPoint`,
		`kind`,
		`renditionIntent`,
		`usage`
	], `Media reference`);
	if (n.contractVersion !== `0.1-draft` || n.kind !== `media-reference`) throw TypeError(`Media reference has an unsupported contract or kind.`);
	let r = g$1(n.assetId, /^[A-Za-z0-9][A-Za-z0-9._:/-]*$/u, 240, `asset id`), i = g$1(n.usage, /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\/[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/u, 160, `usage`), a = {
		accessibility: l$2(n.accessibility),
		assetId: r,
		contractVersion: `0.1-draft`,
		kind: `media-reference`,
		usage: i
	};
	if (n.assetRevision !== void 0 && (a.assetRevision = g$1(n.assetRevision, /^.{1,200}$/u, 200, `revision`)), n.focalPoint !== void 0 && (a.focalPoint = u$2(n.focalPoint)), n.cropIntent !== void 0 && (a.cropIntent = d$2(n.cropIntent)), n.renditionIntent !== void 0 && (a.renditionIntent = f$2(n.renditionIntent)), validateMediaReference(a).some((e) => e.severity === `error`)) throw TypeError(`Media value must be a canonical Studio media reference.`);
	return a;
}
function l$2(e) {
	let t = m$2(e, [
		`altFieldPath`,
		`altText`,
		`caption`,
		`captionFieldPath`,
		`mode`
	], `Media accessibility`);
	switch (t.mode) {
		case `decorative`: return h$1(t, [`mode`], `Decorative media accessibility`), { mode: `decorative` };
		case `informative`: {
			h$1(t, [
				`altText`,
				`caption`,
				`mode`
			], `Informative media accessibility`);
			let e = _$1(t.altText, 1, 5e3, `Media alternative text`), n = t.caption === void 0 ? void 0 : _$1(t.caption, 0, 2e4, `Media caption`);
			return {
				altText: e,
				...n === void 0 ? {} : { caption: n },
				mode: `informative`
			};
		}
		case `bound`: {
			h$1(t, [
				`altFieldPath`,
				`captionFieldPath`,
				`mode`
			], `Bound media accessibility`);
			let e = p$2(t.altFieldPath, `Alternative-text field path`), n = t.captionFieldPath === void 0 ? void 0 : p$2(t.captionFieldPath, `Caption field path`);
			return {
				altFieldPath: e,
				...n === void 0 ? {} : { captionFieldPath: n },
				mode: `bound`
			};
		}
		default: throw TypeError(`Media accessibility mode is invalid.`);
	}
}
function u$2(e) {
	let t = m$2(e, [`x`, `y`], `Media focal point`);
	return {
		x: y$1(t.x, `Focal x`),
		y: y$1(t.y, `Focal y`)
	};
}
function d$2(e) {
	let t = m$2(e, [
		`height`,
		`mode`,
		`width`,
		`x`,
		`y`
	], `Media crop intent`);
	if (t.mode === `aspect-ratio`) return h$1(t, [
		`height`,
		`mode`,
		`width`
	], `Aspect-ratio crop`), {
		height: v$1(t.height, 1, 1e4, `Crop height`),
		mode: `aspect-ratio`,
		width: v$1(t.width, 1, 1e4, `Crop width`)
	};
	if (t.mode === `rectangle`) {
		h$1(t, [
			`height`,
			`mode`,
			`width`,
			`x`,
			`y`
		], `Rectangle crop`);
		let e = y$1(t.width, `Crop width`), n = y$1(t.height, `Crop height`);
		if (e === 0 || n === 0) throw RangeError(`Crop dimensions must be positive.`);
		return {
			height: n,
			mode: `rectangle`,
			width: e,
			x: y$1(t.x, `Crop x`),
			y: y$1(t.y, `Crop y`)
		};
	}
	throw TypeError(`Media crop mode is invalid.`);
}
function f$2(e) {
	let t = m$2(e, [
		`fit`,
		`preferredMediaTypes`,
		`role`
	], `Media rendition intent`), n = g$1(t.role, /^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/u, 100, `rendition role`), r = t.fit;
	if (r !== void 0 && r !== `contain` && r !== `cover` && r !== `fill` && r !== `scale-down`) throw TypeError(`Media rendition fit is invalid.`);
	let i;
	if (t.preferredMediaTypes !== void 0) {
		if (!Array.isArray(t.preferredMediaTypes) || t.preferredMediaTypes.length > 10) throw RangeError(`Preferred media types exceed their item limit.`);
		if (i = t.preferredMediaTypes.map((e) => g$1(e, /^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/u, 200, `preferred media type`)), new Set(i).size !== i.length) throw TypeError(`Preferred media types must be unique.`);
	}
	return {
		...r === void 0 ? {} : { fit: r },
		...i === void 0 ? {} : { preferredMediaTypes: i },
		role: n
	};
}
function p$2(e, t) {
	if (!Array.isArray(e) || e.length < 1 || e.length > 32) throw RangeError(`${t} must have 1 through 32 segments.`);
	return e.map((e) => g$1(e, /^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/u, 100, `${t} segment`));
}
function m$2(e, t, n) {
	if (typeof e != `object` || !e || Array.isArray(e) || Object.getPrototypeOf(e) !== Object.prototype) throw TypeError(`${n} must be a plain JSON object.`);
	let r = e;
	return h$1(r, t, n), r;
}
function h$1(e, t, n) {
	let r = new Set(t), i = Object.keys(e).find((e) => !r.has(e));
	if (i !== void 0) throw TypeError(`${n} contains unknown member ${i}.`);
}
function g$1(e, t, n, r) {
	if (typeof e != `string` || e.length > n || [
		`__proto__`,
		`constructor`,
		`prototype`
	].includes(e) || !t.test(e)) throw TypeError(`Media ${r} is invalid.`);
	return e;
}
function _$1(e, t, n, r) {
	if (typeof e != `string` || e.length < t || e.length > n) throw RangeError(`${r} must contain ${t} through ${n} characters.`);
	return e;
}
function v$1(e, t, n, r) {
	if (typeof e != `number` || !Number.isInteger(e) || e < t || e > n) throw RangeError(`${r} must be an integer from ${t} through ${n}.`);
	return e;
}
function y$1(e, t) {
	if (typeof e != `number` || !Number.isFinite(e) || e < 0 || e > 1) throw RangeError(`${t} must be a finite number from 0 through 1.`);
	return e;
}
function b$1(e) {
	return e.readOnly === !0 || e.binding !== void 0 && e.binding.source.kind !== `static-value`;
}
function x$1(e, t) {
	let n = document.createElement(`section`);
	return n.className = `studio-authoring-control studio-media-control`, n.setAttribute(`aria-label`, t), e.append(n), n;
}
function S$1(e, t, n) {
	let r = document.createElement(`input`);
	return r.type = `text`, r.value = t, r.disabled = n, r.setAttribute(`aria-label`, e), r;
}
function C$1(e, t, n) {
	let r = document.createElement(`input`);
	return r.type = `number`, r.min = `0`, r.max = `1`, r.step = `0.01`, r.value = String(t), r.disabled = n, r.setAttribute(`aria-label`, e), r;
}
function w$1(e, t, n, r) {
	let i = document.createElement(`select`);
	i.disabled = r, i.setAttribute(`aria-label`, e);
	for (let e of t) {
		let t = document.createElement(`option`);
		t.value = e, t.textContent = e, t.selected = e === n, i.append(t);
	}
	return i;
}
function T$1(e, t, n = !1) {
	let r = document.createElement(`button`);
	return r.type = `button`, r.textContent = e, r.disabled = n, r.setAttribute(`aria-label`, e), r.addEventListener(`click`, t), r;
}
function E$1(e) {
	return `${e.filename} (${e.mediaKind})`;
}
//#endregion
//#region node_modules/@kumwe/studio/dist/authoring-controls.js
var STUDIO_AUTHORING_CONTROL_IDS = Object.freeze({
	chart: `studio.control/chart`,
	drawing: `studio.control/drawing`,
	mediaCollection: `studio.control/media-collection`,
	mediaReference: `studio.control/media-reference`,
	money: `studio.control/money`,
	richText: `studio.control/rich-text`,
	scopedCss: `studio.control/scoped-css`,
	source: `studio.control/source`,
	table: `studio.control/table`
});
var StudioAuthoringControlRegistry = class StudioAuthoringControlRegistry {
	#codeField;
	#extensionControls;
	#media;
	#richTextFactory;
	#sourcePreview;
	constructor(e = {}) {
		this.#codeField = e.codeField ?? new u$1(), this.#media = e.media, this.#richTextFactory = e.richTextFactory ?? new StudioRichTextEditorFactory(e.strictContentSecurityPolicy === !0 ? new StudioStrictCspRichTextSurfaceAdapter() : void 0), this.#sourcePreview = e.sourcePreview;
		let t = /* @__PURE__ */ new Map();
		for (let n of e.extensionControls ?? []) {
			if (l$1(n.control)) throw TypeError(`Extension control ${n.control} cannot replace a first-party Studio control.`);
			if (t.has(n.control)) throw TypeError(`Extension control ${n.control} is registered more than once.`);
			t.set(n.control, n);
		}
		this.#extensionControls = t;
	}
	supports(e) {
		return l$1(e) || this.#extensionControls.has(e);
	}
	forAdmittedExtensionControls(e) {
		let t = new Set(e);
		return new StudioAuthoringControlRegistry({
			codeField: this.#codeField,
			extensionControls: [...this.#extensionControls.values()].filter((e) => t.has(e.control)),
			...this.#media === void 0 ? {} : { media: this.#media },
			richTextFactory: this.#richTextFactory,
			...this.#sourcePreview === void 0 ? {} : { sourcePreview: this.#sourcePreview }
		});
	}
	withMediaServices(e) {
		return new StudioAuthoringControlRegistry({
			codeField: this.#codeField,
			extensionControls: [...this.#extensionControls.values()],
			media: e,
			richTextFactory: this.#richTextFactory,
			...this.#sourcePreview === void 0 ? {} : { sourcePreview: this.#sourcePreview }
		});
	}
	async mount(e, t) {
		let n = this.#extensionControls.get(e);
		if (n !== void 0) return n.mount(t);
		switch (e) {
			case `studio.control/rich-text`: return this.#mountRichText(t);
			case `studio.control/source`: return new d$1(t, this.#codeField, this.#sourcePreview);
			case `studio.control/chart`: return new f$1(t);
			case `studio.control/drawing`: return new m$1(t);
			case `studio.control/media-reference`: return mountStudioMediaReferenceControl(t, this.#requireMedia());
			case `studio.control/media-collection`: return mountStudioMediaCollectionControl(t, this.#requireMedia());
			case `studio.control/money`: return new g(t);
			case `studio.control/scoped-css`: return new _(t);
			case `studio.control/table`: return new h(t);
			default: throw Error(`Unknown Studio authoring control ${e}.`);
		}
	}
	async #mountRichText(e) {
		if (!j$1(e.value) || e.value.type !== `doc`) throw TypeError(`Rich-text control requires a canonical Studio document.`);
		let t = C(e.profile), n = structuredClone(e.value), r = await this.#richTextFactory.create({
			...e.binding === void 0 ? {} : { binding: e.binding },
			holder: e.holder,
			onChange: (t) => {
				n = t.value, e.onChange?.({
					valid: t.valid,
					value: t.value
				});
			},
			...t === void 0 ? {} : { profile: t },
			readOnly: S(e),
			value: e.value
		});
		return n = await r.save(), {
			destroy: () => r.destroy(),
			focus: () => r.focus(),
			readOnly: r.readOnly,
			value: () => structuredClone(n)
		};
	}
	#requireMedia() {
		if (this.#media === void 0) throw Error(`Studio media controls require host-injected media services.`);
		return this.#media;
	}
};
function l$1(e) {
	return Object.values(STUDIO_AUTHORING_CONTROL_IDS).some((t) => t === e);
}
var u$1 = class {
	mount(e) {
		let t = document.createElement(`textarea`);
		return t.className = `studio-source-editor`, t.setAttribute(`aria-label`, `${e.language} source`), t.disabled = e.readOnly, t.rows = 12, t.spellcheck = !1, t.value = e.source, t.addEventListener(`input`, () => e.onChange(t.value)), e.holder.append(t), {
			destroy: () => t.remove(),
			focus: () => t.focus(),
			source: () => t.value
		};
	}
};
var d$1 = class {
	#code;
	#language;
	#onChange;
	#preview;
	#previewRegion;
	readOnly;
	#abort;
	#lastValid;
	constructor(e, t, n) {
		this.readOnly = S(e), this.#onChange = e.onChange, this.#preview = n, this.#lastValid = y(e.value), this.#language = b(e.profile);
		let r = w(e.holder, `Source editor`), i = document.createElement(`div`), a = k$1(`Preview source`, () => void this.#renderPreview());
		a.disabled = n === void 0, this.#previewRegion = document.createElement(`div`), this.#previewRegion.setAttribute(`aria-live`, `polite`), this.#previewRegion.setAttribute(`aria-label`, `Trusted source preview`), r.append(i, a, this.#previewRegion), this.#code = t.mount({
			holder: i,
			language: this.#language,
			onChange: (e) => this.#change(e),
			readOnly: this.readOnly,
			source: this.#lastValid
		});
	}
	destroy() {
		this.#abort?.abort(), this.#code.destroy();
	}
	focus() {
		this.#code.focus();
	}
	value() {
		return this.#lastValid;
	}
	#change(e) {
		if (!this.readOnly) try {
			this.#lastValid = y(e), this.#onChange?.({
				valid: !0,
				value: this.value()
			});
		} catch {
			this.#onChange?.({
				valid: !1,
				value: this.value()
			});
		}
	}
	async #renderPreview() {
		if (this.#preview === void 0) return;
		this.#abort?.abort();
		let e = new AbortController();
		this.#abort = e, this.#previewRegion.replaceChildren(document.createTextNode(`Rendering preview…`));
		try {
			let t = await this.#preview.render({
				language: this.#language,
				source: this.value()
			}, e.signal);
			e.signal.aborted || this.#previewRegion.replaceChildren(t);
		} catch {
			e.signal.aborted || this.#previewRegion.replaceChildren(document.createTextNode(`Preview is unavailable.`));
		}
	}
};
var f$1 = class {
	#holder;
	#onChange;
	readOnly;
	#lastValid;
	#working;
	constructor(t) {
		this.readOnly = S(t), this.#holder = w(t.holder, `Chart editor`), this.#onChange = t.onChange, this.#lastValid = parseStudioChartSpec(t.value), this.#working = structuredClone(this.#lastValid), this.#render();
	}
	destroy() {
		this.#holder.remove();
	}
	focus() {
		this.#holder.querySelector(`input,select,button`)?.focus();
	}
	value() {
		return structuredClone(this.#lastValid);
	}
	#commit() {
		if (!this.readOnly) try {
			this.#lastValid = parseStudioChartSpec(this.#working), this.#onChange?.({
				valid: !0,
				value: this.value()
			});
		} catch {
			this.#onChange?.({
				valid: !1,
				value: this.value()
			});
		}
	}
	#render() {
		this.#holder.replaceChildren();
		let e = D(`Chart type`, [
			`bar`,
			`line`,
			`pie`,
			`doughnut`
		], this.#working.type, this.readOnly);
		e.addEventListener(`change`, () => {
			this.#working.type = e.value, this.#commit();
		});
		let t = T(`Chart title`, this.#working.title ?? ``, this.readOnly);
		t.maxLength = 500, t.addEventListener(`input`, () => {
			let e = t.value;
			e.length === 0 ? delete this.#working.title : this.#working.title = e, this.#commit();
		}), this.#holder.append(e, t);
		let n = document.createElement(`table`);
		n.setAttribute(`aria-label`, `Chart data`);
		let r = document.createElement(`tr`);
		r.append(O$1(`Label`));
		for (let [e, t] of this.#working.datasets.entries()) {
			let n = O$1(`Dataset ${e + 1}`), i = T(`Dataset ${e + 1} label`, t.label, this.readOnly);
			i.addEventListener(`input`, () => {
				t.label = i.value.slice(0, 500), this.#commit();
			}), n.replaceChildren(i), r.append(n);
		}
		n.append(r);
		for (let [e, t] of this.#working.labels.entries()) {
			let r = document.createElement(`tr`), i = document.createElement(`td`), a = T(`Chart label ${e + 1}`, t, this.readOnly);
			a.addEventListener(`input`, () => {
				this.#working.labels[e] = a.value.slice(0, 500), this.#commit();
			}), i.append(a), r.append(i);
			for (let [t, n] of this.#working.datasets.entries()) {
				let i = document.createElement(`td`), a = T(`Value for label ${e + 1}, dataset ${t + 1}`, String(n.values[e] ?? 0), this.readOnly);
				a.inputMode = `decimal`, a.addEventListener(`input`, () => {
					let t = Number(a.value);
					if (!Number.isFinite(t)) {
						this.#onChange?.({
							valid: !1,
							value: this.value()
						});
						return;
					}
					n.values[e] = t, this.#commit();
				}), i.append(a), r.append(i);
			}
			n.append(r);
		}
		this.#holder.append(n), this.readOnly || this.#holder.append(k$1(`Add chart row`, () => this.#addRow(), this.#working.labels.length >= 200), k$1(`Remove chart row`, () => this.#removeRow(), this.#working.labels.length <= 1), k$1(`Add chart dataset`, () => this.#addDataset(), this.#working.datasets.length >= 20), k$1(`Remove chart dataset`, () => this.#removeDataset(), this.#working.datasets.length <= 1));
	}
	#addRow() {
		if (!(this.#working.labels.length >= 200)) {
			this.#working.labels.push(`Label ${this.#working.labels.length + 1}`);
			for (let e of this.#working.datasets) e.values.push(0);
			this.#commit(), this.#render();
		}
	}
	#removeRow() {
		if (!(this.#working.labels.length <= 1)) {
			this.#working.labels.pop();
			for (let e of this.#working.datasets) e.values.pop();
			this.#commit(), this.#render();
		}
	}
	#addDataset() {
		this.#working.datasets.length >= 20 || (this.#working.datasets.push({
			label: `Dataset ${this.#working.datasets.length + 1}`,
			values: this.#working.labels.map(() => 0)
		}), this.#commit(), this.#render());
	}
	#removeDataset() {
		this.#working.datasets.length <= 1 || (this.#working.datasets.pop(), this.#commit(), this.#render());
	}
};
var p$1 = `http://www.w3.org/2000/svg`;
var m$1 = class {
	#alt;
	#color;
	#commitStroke;
	#height;
	#holder;
	#onChange;
	#pointX;
	#pointY;
	#status;
	#strokeWidth;
	#svg;
	#width;
	readOnly;
	#activePointerId;
	#lastValid;
	#pendingPoints = [];
	#working;
	constructor(e) {
		x(e.profile, `studio.drawing/canonical`, `drawing`), this.readOnly = S(e), this.#lastValid = parseStudioDrawingDocument(e.value), this.#working = structuredClone(this.#lastValid), this.#onChange = e.onChange, this.#holder = w(e.holder, `Drawing editor`);
		let n = document.createElement(`p`);
		n.textContent = this.readOnly ? `Drawing is read-only.` : `Draw with a pointer, or enter a point and use Add point. Arrow keys move the point; Space adds it and Enter commits the stroke.`, this.#alt = document.createElement(`textarea`), this.#alt.setAttribute(`aria-label`, `Drawing alternative text`), this.#alt.disabled = this.readOnly, this.#alt.maxLength = 5e3, this.#alt.rows = 3, this.#alt.value = this.#lastValid.alt, this.#alt.addEventListener(`input`, () => {
			this.#working.alt = this.#alt.value, this.#commitWorking();
		}), this.#width = E(`Drawing width`, this.#lastValid.width, this.readOnly, 1, 4096, 1), this.#height = E(`Drawing height`, this.#lastValid.height, this.readOnly, 1, 4096, 1), this.#width.addEventListener(`input`, () => this.#changeDimensions()), this.#height.addEventListener(`input`, () => this.#changeDimensions()), this.#color = T(`Drawing color token`, `#000000`, this.readOnly), this.#color.maxLength = 127, this.#color.spellcheck = !1, this.#color.addEventListener(`input`, () => this.#validateStrokeSettings()), this.#strokeWidth = E(`Drawing stroke width`, 2, this.readOnly, .25, 64, .25), this.#strokeWidth.addEventListener(`input`, () => this.#validateStrokeSettings()), this.#svg = document.createElementNS(p$1, `svg`), this.#svg.classList.add(`studio-drawing-canvas`), this.#svg.setAttribute(`role`, `img`), this.#svg.setAttribute(`aria-label`, this.#lastValid.alt), this.#svg.setAttribute(`aria-description`, `Arrow keys move the drawing point. Space adds a point. Enter commits and Escape discards the current stroke.`), this.#svg.setAttribute(`aria-keyshortcuts`, `ArrowUp ArrowDown ArrowLeft ArrowRight Space Enter Escape`), this.#svg.setAttribute(`preserveAspectRatio`, `xMidYMid meet`), this.#svg.tabIndex = this.readOnly ? -1 : 0, this.#svg.addEventListener(`pointerdown`, (e) => this.#beginPointerStroke(e)), this.#svg.addEventListener(`pointermove`, (e) => this.#continuePointerStroke(e)), this.#svg.addEventListener(`pointerup`, (e) => this.#finishPointerStroke(e)), this.#svg.addEventListener(`pointercancel`, (e) => this.#cancelPointerStroke(e)), this.#svg.addEventListener(`keydown`, (e) => this.#handleCanvasKey(e)), this.#pointX = E(`Drawing point x`, 0, this.readOnly, 0, this.#lastValid.width, 1), this.#pointY = E(`Drawing point y`, 0, this.readOnly, 0, this.#lastValid.height, 1);
		let r = k$1(`Add drawing point`, () => this.#addKeyboardPoint());
		this.#commitStroke = k$1(`Commit drawing stroke`, () => this.#completeStroke(), !0);
		let i = k$1(`Discard current drawing stroke`, () => {
			this.#pendingPoints = [], this.#renderDrawing();
		}), a = k$1(`Remove last drawing stroke`, () => this.#removeLastStroke(), this.#lastValid.strokes.length === 0);
		for (let e of [
			r,
			this.#commitStroke,
			i,
			a
		]) e.hidden = this.readOnly;
		this.#status = document.createElement(`p`), this.#status.setAttribute(`aria-live`, `polite`), this.#status.className = `studio-authoring-status`, this.#holder.append(n, this.#alt, this.#width, this.#height, this.#color, this.#strokeWidth, this.#svg, this.#pointX, this.#pointY, r, this.#commitStroke, i, a, this.#status), this.#renderDrawing();
	}
	destroy() {
		this.#holder.remove();
	}
	focus() {
		this.#svg.focus();
	}
	value() {
		return structuredClone(this.#lastValid);
	}
	#addKeyboardPoint() {
		if (this.readOnly) return;
		let e = {
			x: Number(this.#pointX.value),
			y: Number(this.#pointY.value)
		};
		if (!this.#validPoint(e)) {
			this.#invalid();
			return;
		}
		this.#appendPoint(e), this.#renderDrawing();
	}
	#appendPoint(e) {
		if (this.#pendingPoints.length >= 1e4) {
			this.#status.textContent = `A drawing stroke can contain at most 10000 points.`;
			return;
		}
		let t = this.#pendingPoints.at(-1);
		(t?.x !== e.x || t.y !== e.y) && this.#pendingPoints.push(e);
	}
	#beginPointerStroke(e) {
		if (!(this.readOnly || e.button !== 0)) {
			e.preventDefault(), this.#activePointerId = e.pointerId, this.#pendingPoints = [this.#pointFromPointer(e)];
			try {
				this.#svg.setPointerCapture(e.pointerId);
			} catch {}
			this.#renderDrawing();
		}
	}
	#cancelPointerStroke(e) {
		e.pointerId === this.#activePointerId && (this.#activePointerId = void 0, this.#pendingPoints = [], this.#renderDrawing());
	}
	#changeDimensions() {
		this.readOnly || (this.#working.width = Number(this.#width.value), this.#working.height = Number(this.#height.value), this.#commitWorking() && (this.#pointX.max = String(this.#lastValid.width), this.#pointY.max = String(this.#lastValid.height), this.#pointX.value = String(A$1(Number(this.#pointX.value), 0, this.#lastValid.width)), this.#pointY.value = String(A$1(Number(this.#pointY.value), 0, this.#lastValid.height)), this.#renderDrawing()));
	}
	#commitWorking() {
		if (this.readOnly) return !1;
		try {
			return this.#lastValid = parseStudioDrawingDocument(this.#working), this.#working = structuredClone(this.#lastValid), this.#onChange?.({
				valid: !0,
				value: this.value()
			}), this.#svg.setAttribute(`aria-label`, this.#lastValid.alt), !0;
		} catch {
			return this.#invalid(), !1;
		}
	}
	#completeStroke() {
		if (!(this.readOnly || this.#pendingPoints.length === 0)) try {
			let e = this.#parseStroke(this.#pendingPoints);
			if (this.#working.strokes = [...this.#lastValid.strokes, e], !this.#commitWorking()) return;
			this.#pendingPoints = [], this.#renderDrawing();
		} catch {
			this.#invalid();
		}
	}
	#continuePointerStroke(e) {
		e.pointerId === this.#activePointerId && (e.preventDefault(), this.#appendPoint(this.#pointFromPointer(e)), this.#renderDrawing());
	}
	#finishPointerStroke(e) {
		e.pointerId === this.#activePointerId && (e.preventDefault(), this.#appendPoint(this.#pointFromPointer(e)), this.#activePointerId = void 0, this.#completeStroke());
	}
	#handleCanvasKey(e) {
		if (this.readOnly) return;
		let t = e.shiftKey ? 10 : 1, n = Number(this.#pointX.value), r = Number(this.#pointY.value);
		switch (e.key) {
			case `ArrowLeft`:
				n -= t;
				break;
			case `ArrowRight`:
				n += t;
				break;
			case `ArrowUp`:
				r -= t;
				break;
			case `ArrowDown`:
				r += t;
				break;
			case ` `:
				e.preventDefault(), this.#addKeyboardPoint();
				return;
			case `Enter`:
				e.preventDefault(), this.#completeStroke();
				return;
			case `Escape`:
				e.preventDefault(), this.#pendingPoints = [], this.#renderDrawing();
				return;
			default: return;
		}
		e.preventDefault(), this.#pointX.value = String(A$1(n, 0, this.#lastValid.width)), this.#pointY.value = String(A$1(r, 0, this.#lastValid.height));
	}
	#invalid() {
		this.#onChange?.({
			valid: !1,
			value: this.value()
		});
	}
	#parseStroke(e) {
		let n = parseStudioDrawingDocument({
			alt: this.#lastValid.alt,
			height: this.#lastValid.height,
			strokes: [{
				color: this.#color.value,
				points: structuredClone(e),
				width: Number(this.#strokeWidth.value)
			}],
			width: this.#lastValid.width
		}).strokes[0];
		if (n === void 0) throw TypeError(`Drawing stroke is unavailable.`);
		return n;
	}
	#pointFromPointer(e) {
		let t = this.#svg.getBoundingClientRect(), n = t.width > 0 ? (e.clientX - t.left) / t.width * this.#lastValid.width : e.offsetX, r = t.height > 0 ? (e.clientY - t.top) / t.height * this.#lastValid.height : e.offsetY;
		return {
			x: A$1(Number.isFinite(n) ? n : 0, 0, this.#lastValid.width),
			y: A$1(Number.isFinite(r) ? r : 0, 0, this.#lastValid.height)
		};
	}
	#removeLastStroke() {
		this.readOnly || this.#lastValid.strokes.length === 0 || (this.#working = structuredClone(this.#lastValid), this.#working.strokes.pop(), this.#commitWorking() && this.#renderDrawing());
	}
	#renderDrawing() {
		this.#svg.setAttribute(`viewBox`, `0 0 ${String(this.#lastValid.width)} ${String(this.#lastValid.height)}`), this.#svg.replaceChildren();
		for (let e of this.#lastValid.strokes) this.#svg.append(this.#strokeElement(e));
		if (this.#pendingPoints.length > 0) try {
			this.#svg.append(this.#strokeElement(this.#parseStroke(this.#pendingPoints)));
		} catch {}
		this.#commitStroke.disabled = this.#pendingPoints.length === 0, this.#status.textContent = `${String(this.#lastValid.strokes.length)} committed strokes; ${String(this.#pendingPoints.length)} points in the current stroke.`;
		let e = this.#holder.querySelector(`[aria-label="Remove last drawing stroke"]`);
		e !== null && (e.disabled = this.#lastValid.strokes.length === 0);
	}
	#strokeElement(e) {
		let t = document.createElementNS(p$1, `polyline`);
		return t.setAttribute(`fill`, `none`), t.setAttribute(`points`, e.points.map((e) => `${e.x},${e.y}`).join(` `)), t.setAttribute(`stroke`, e.color.startsWith(`#`) ? e.color : `currentColor`), t.setAttribute(`stroke-linecap`, `round`), t.setAttribute(`stroke-linejoin`, `round`), t.setAttribute(`stroke-width`, String(e.width)), t;
	}
	#validPoint(e) {
		try {
			return this.#parseStroke([e]), !0;
		} catch {
			return !1;
		}
	}
	#validateStrokeSettings() {
		if (!this.readOnly) {
			try {
				this.#parseStroke(this.#pendingPoints.length === 0 ? [{
					x: 0,
					y: 0
				}] : this.#pendingPoints);
			} catch {
				this.#invalid();
			}
			this.#renderDrawing();
		}
	}
};
var h = class {
	#holder;
	#onChange;
	readOnly;
	#lastValid;
	#working;
	constructor(e) {
		x(e.profile, `studio.table/canonical`, `table`), this.readOnly = S(e), this.#lastValid = parseStudioTableDocument(e.value), this.#working = structuredClone(this.#lastValid), this.#onChange = e.onChange, this.#holder = w(e.holder, `Table editor`), this.#render();
	}
	destroy() {
		this.#holder.remove();
	}
	focus() {
		this.#holder.querySelector(`input,textarea,button`)?.focus();
	}
	value() {
		return structuredClone(this.#lastValid);
	}
	#addColumn() {
		if (!(this.readOnly || this.#lastValid.columns.length >= 50)) {
			this.#working = structuredClone(this.#lastValid), this.#working.columns.push(`Column ${String(this.#working.columns.length + 1)}`);
			for (let e of this.#working.rows) e.push(``);
			this.#commit() && this.#render();
		}
	}
	#addRow() {
		this.readOnly || this.#lastValid.rows.length >= 1e3 || (this.#working = structuredClone(this.#lastValid), this.#working.rows.push(this.#working.columns.map(() => ``)), this.#commit() && this.#render());
	}
	#commit() {
		if (this.readOnly) return !1;
		try {
			return this.#lastValid = parseStudioTableDocument(this.#working), this.#working = structuredClone(this.#lastValid), this.#onChange?.({
				valid: !0,
				value: this.value()
			}), !0;
		} catch {
			return this.#onChange?.({
				valid: !1,
				value: this.value()
			}), !1;
		}
	}
	#removeColumn() {
		if (!(this.readOnly || this.#lastValid.columns.length <= 1)) {
			this.#working = structuredClone(this.#lastValid), this.#working.columns.pop();
			for (let e of this.#working.rows) e.pop();
			this.#commit() && this.#render();
		}
	}
	#removeRow() {
		this.readOnly || this.#lastValid.rows.length === 0 || (this.#working = structuredClone(this.#lastValid), this.#working.rows.pop(), this.#commit() && this.#render());
	}
	#render() {
		this.#holder.replaceChildren();
		let e = document.createElement(`p`);
		e.textContent = `Table cells are text. HTML and executable content are not interpreted.`;
		let t = T(`Table caption`, this.#working.caption ?? ``, this.readOnly);
		t.maxLength = 500, t.addEventListener(`input`, () => {
			t.value.length === 0 ? delete this.#working.caption : this.#working.caption = t.value, this.#commit();
		}), this.#holder.append(e, t);
		let n = document.createElement(`table`);
		n.setAttribute(`aria-label`, `Table data`);
		let r = document.createElement(`thead`), i = document.createElement(`tr`);
		i.append(O$1(`Row`));
		for (let [e, t] of this.#working.columns.entries()) {
			let n = O$1(`Column ${String(e + 1)}`), r = T(`Table column ${String(e + 1)} heading`, t, this.readOnly);
			r.maxLength = 500, r.addEventListener(`input`, () => {
				this.#working.columns[e] = r.value, this.#commit();
			}), n.replaceChildren(r), i.append(n);
		}
		r.append(i), n.append(r);
		let a = document.createElement(`tbody`);
		for (let [e, t] of this.#working.rows.entries()) {
			let n = document.createElement(`tr`), r = document.createElement(`th`);
			r.scope = `row`, r.textContent = String(e + 1), n.append(r);
			for (let [r, i] of t.entries()) {
				let t = document.createElement(`td`), a = document.createElement(`textarea`);
				a.setAttribute(`aria-label`, `Table row ${String(e + 1)}, column ${String(r + 1)}`), a.disabled = this.readOnly, a.maxLength = 5e3, a.rows = 2, a.value = i, a.addEventListener(`input`, () => {
					let t = this.#working.rows[e];
					t !== void 0 && (t[r] = a.value, this.#commit());
				}), t.append(a), n.append(t);
			}
			a.append(n);
		}
		if (n.append(a), this.#holder.append(n), !this.readOnly) {
			let e = document.createElement(`div`);
			e.className = `studio-authoring-actions`, e.append(k$1(`Add table row`, () => this.#addRow(), this.#working.rows.length >= 1e3), k$1(`Remove last table row`, () => this.#removeRow(), this.#working.rows.length === 0), k$1(`Add table column`, () => this.#addColumn(), this.#working.columns.length >= 50), k$1(`Remove last table column`, () => this.#removeColumn(), this.#working.columns.length <= 1)), this.#holder.append(e);
		}
	}
};
var g = class {
	#amount;
	#currency;
	#holder;
	#onChange;
	readOnly;
	#lastValid;
	constructor(e) {
		this.readOnly = S(e), this.#lastValid = parseStudioMoneyValue(e.value), this.#onChange = e.onChange, this.#holder = w(e.holder, `Money editor`), this.#amount = T(`Exact decimal amount`, this.#lastValid.amount, this.readOnly), this.#amount.inputMode = `decimal`, this.#currency = T(`Three-letter currency`, this.#lastValid.currency, this.readOnly), this.#currency.maxLength = 3, this.#currency.autocapitalize = `characters`, this.#amount.addEventListener(`input`, () => this.#commit()), this.#currency.addEventListener(`input`, () => this.#commit()), this.#holder.append(this.#amount, this.#currency);
	}
	destroy() {
		this.#holder.remove();
	}
	focus() {
		this.#amount.focus();
	}
	value() {
		return structuredClone(this.#lastValid);
	}
	#commit() {
		if (!this.readOnly) try {
			this.#lastValid = parseStudioMoneyValue({
				amount: this.#amount.value,
				currency: this.#currency.value.toUpperCase()
			}), this.#onChange?.({
				valid: !0,
				value: this.value()
			});
		} catch {
			this.#onChange?.({
				valid: !1,
				value: this.value()
			});
		}
	}
};
var _ = class {
	#holder;
	#onChange;
	#source;
	readOnly;
	#lastValid;
	constructor(e) {
		this.readOnly = S(e), this.#lastValid = v(e.value), this.#onChange = e.onChange, this.#holder = w(e.holder, `Scoped style editor`);
		let t = document.createElement(`p`);
		t.textContent = `Use only self, heading, content, media, or action targets and approved properties.`, this.#source = document.createElement(`textarea`), this.#source.setAttribute(`aria-label`, `Scoped CSS source`), this.#source.disabled = this.readOnly, this.#source.rows = 10, this.#source.value = serializeScopedCss(this.#lastValid), this.#source.addEventListener(`input`, () => this.#commit()), this.#holder.append(t, this.#source);
	}
	destroy() {
		this.#holder.remove();
	}
	focus() {
		this.#source.focus();
	}
	value() {
		return structuredClone(this.#lastValid);
	}
	#commit() {
		if (!this.readOnly) try {
			let e = parseScopedCss(this.#source.value);
			compileStudioScopedStyleSheet(`authoring-preview`, e), this.#lastValid = e, this.#onChange?.({
				valid: !0,
				value: this.value()
			});
		} catch {
			this.#onChange?.({
				valid: !1,
				value: this.value()
			});
		}
	}
};
function parseScopedCss(e) {
	if (e.length > 1e5) throw RangeError(`Scoped CSS source exceeds 100000 characters.`);
	let t = [], n = /\s*(self|heading|content|media|action)\s*\{([^{}]*)\}\s*/guy, r = 0;
	for (; r < e.length;) {
		n.lastIndex = r;
		let i = n.exec(e);
		if (i?.index !== r) throw TypeError(`Scoped CSS is invalid near character ${r + 1}.`);
		let a = Object.create(null);
		for (let e of (i[2] ?? ``).split(`;`)) {
			if (e.trim().length === 0) continue;
			let t = e.indexOf(`:`);
			if (t < 1) throw TypeError(`Scoped CSS declaration requires property: value.`);
			let n = e.slice(0, t).trim().toLowerCase(), r = e.slice(t + 1).trim();
			if (Object.hasOwn(a, n)) throw TypeError(`Scoped CSS property ${n} is declared twice.`);
			a[n] = r;
		}
		t.push({
			declarations: a,
			target: i[1]
		}), r = n.lastIndex;
	}
	let a = { rules: t };
	return compileStudioScopedStyleSheet(`authoring-preview`, a), a;
}
function serializeScopedCss(e) {
	return e.rules.map((e) => {
		let t = Object.entries(e.declarations).sort(([e], [t]) => e.localeCompare(t)).map(([e, t]) => `  ${e}: ${t};`).join(`
`);
		return `${e.target} {\n${t}\n}`;
	}).join(`

`);
}
function v(e) {
	if (!j$1(e) || !Array.isArray(e.rules)) throw TypeError(`Scoped styles require a structured rule collection.`);
	let t = structuredClone(e);
	return compileStudioScopedStyleSheet(`authoring-preview`, t), t;
}
function y(e) {
	if (typeof e != `string` || e.length > 1e6) throw RangeError(`Source text exceeds its 1000000-character limit.`);
	return e;
}
function b(e) {
	switch (e) {
		case `studio.source/code`: return `code`;
		case `studio.source/latex`: return `latex`;
		case `studio.source/mermaid`: return `mermaid`;
		default: throw TypeError(`Unknown Studio source profile "${String(e)}".`);
	}
}
function x(e, t, n) {
	if (e !== void 0 && e !== t) throw TypeError(`Unknown Studio ${n} profile "${e}".`);
}
function S(e) {
	return e.readOnly === !0 || e.binding !== void 0 && e.binding.source.kind !== `static-value`;
}
function C(e) {
	if (e !== void 0) {
		if (e === `studio.rich-text/documentation` || e === `studio.rich-text/marketing` || e === `studio.rich-text/portable`) return e;
		throw TypeError(`Unknown Studio rich-text profile "${e}".`);
	}
}
function w(e, t) {
	let n = document.createElement(`section`);
	return n.className = `studio-authoring-control`, n.setAttribute(`aria-label`, t), e.append(n), n;
}
function T(e, t, n) {
	let r = document.createElement(`input`);
	return r.type = `text`, r.setAttribute(`aria-label`, e), r.disabled = n, r.value = t, r;
}
function E(e, t, n, r, i, a) {
	let o = document.createElement(`input`);
	return o.type = `number`, o.setAttribute(`aria-label`, e), o.disabled = n, o.max = String(i), o.min = String(r), o.step = String(a), o.value = String(t), o;
}
function D(e, t, n, r) {
	let i = document.createElement(`select`);
	i.setAttribute(`aria-label`, e), i.disabled = r;
	for (let e of t) {
		let t = document.createElement(`option`);
		t.value = e, t.textContent = e, t.selected = e === n, i.append(t);
	}
	return i;
}
function O$1(e) {
	let t = document.createElement(`th`);
	return t.scope = `col`, t.textContent = e, t;
}
function k$1(e, t, n = !1) {
	let r = document.createElement(`button`);
	return r.type = `button`, r.textContent = e, r.setAttribute(`aria-label`, e), r.disabled = n, r.addEventListener(`click`, t), r;
}
function A$1(e, t, n) {
	return Math.min(n, Math.max(t, e));
}
function j$1(e) {
	return typeof e == `object` && !!e && !Array.isArray(e);
}
//#endregion
//#region node_modules/@kumwe/studio/dist/resource-authoring-control.js
function mountStudioResourceBindingControl(e) {
	return new n(e);
}
function isStudioResourceReference(e) {
	return !p(e) || !u(e, [
		`id`,
		`kind`,
		`resourceType`
	]) ? !1 : e.kind === `resource-reference` && m(e.id) && f(e.resourceType);
}
var n = class {
	#cancel;
	#clear;
	#currentRegion;
	#holder;
	#loadMore;
	#onChange;
	#results;
	#retry;
	#search;
	#searchButton;
	#service;
	#status;
	#type;
	#types;
	readOnly;
	#abort;
	#current;
	#debounce;
	#destroyed = !1;
	#items = [];
	#nextCursor;
	#requestSequence = 0;
	#retryQuery;
	constructor(e) {
		this.readOnly = e.readOnly || o(e.binding), this.#current = l(e.binding), this.#holder = document.createElement(`section`), this.#holder.className = `studio-resource-binding-control`, this.#holder.setAttribute(`aria-label`, `Resource browser for ${e.label}`), this.#onChange = e.onChange, this.#service = e.service, this.#types = c(e.service.resourceTypes), this.#currentRegion = document.createElement(`p`), this.#currentRegion.className = `studio-resource-current`, this.#currentRegion.setAttribute(`aria-live`, `polite`), this.#type = document.createElement(`select`), this.#type.setAttribute(`aria-label`, `Resource type`);
		for (let e of this.#types) {
			let t = document.createElement(`option`);
			t.value = e.id, t.textContent = a(e.label), t.selected = e.id === this.#current?.resourceType, this.#type.append(t);
		}
		this.#type.disabled = this.#types.length === 0, this.#type.addEventListener(`change`, () => this.#resetSearch()), this.#search = document.createElement(`input`), this.#search.type = `search`, this.#search.maxLength = 160, this.#search.setAttribute(`aria-label`, `Search authorized resources`), this.#search.disabled = this.#types.length === 0, this.#search.addEventListener(`input`, () => this.#scheduleSearch()), this.#search.addEventListener(`keydown`, (e) => {
			e.key === `Enter` && (e.preventDefault(), this.#runSearch(void 0, !1));
		}), this.#searchButton = r(`Search resources`, () => {
			this.#runSearch(void 0, !1);
		}), this.#searchButton.disabled = this.#types.length === 0, this.#cancel = r(`Cancel resource search`, () => this.#cancelSearch(!0)), this.#cancel.hidden = !0, this.#retry = r(`Retry resource search`, () => {
			let e = this.#retryQuery;
			e !== void 0 && this.#runSearch(e.cursor, e.cursor !== void 0);
		}), this.#retry.hidden = !0, this.#clear = r(`Clear selected resource`, () => this.#clearSelection()), this.#clear.disabled = this.readOnly || this.#current === void 0, this.#status = document.createElement(`p`), this.#status.className = `studio-resource-status`, this.#status.setAttribute(`aria-live`, `polite`), this.#status.textContent = this.#types.length === 0 ? `No authorized resource types are available.` : `Enter a search term or browse all authorized resources.`, this.#results = document.createElement(`ul`), this.#results.className = `studio-resource-results`, this.#results.setAttribute(`aria-label`, `Authorized resource results`), this.#loadMore = r(`Load more resources`, () => {
			this.#nextCursor !== void 0 && this.#runSearch(this.#nextCursor, !0);
		}), this.#loadMore.hidden = !0;
		let t = document.createElement(`div`);
		t.className = `studio-resource-search`, t.append(this.#type, this.#search, this.#searchButton, this.#cancel, this.#retry), this.#holder.append(this.#currentRegion, t, this.#status, this.#results, this.#loadMore, this.#clear), e.holder.append(this.#holder), this.#renderCurrent(e.binding, e.multiple);
	}
	current() {
		return this.#current === void 0 ? void 0 : structuredClone(this.#current);
	}
	destroy() {
		this.#destroyed = !0, this.#cancelSearch(!1), this.#holder.remove();
	}
	focus() {
		this.#search.focus();
	}
	#cancelSearch(e) {
		this.#debounce !== void 0 && (clearTimeout(this.#debounce), this.#debounce = void 0), this.#abort?.abort(), this.#abort = void 0, this.#requestSequence += 1, this.#setBusy(!1), e && !this.#destroyed && (this.#status.textContent = `Resource search cancelled.`);
	}
	#clearSelection() {
		this.readOnly || this.#current === void 0 || (this.#current = void 0, this.#clear.disabled = !0, this.#currentRegion.textContent = `No resource selected.`, this.#onChange?.({}));
	}
	#renderCurrent(e, t) {
		this.#current === void 0 ? e === void 0 ? this.#currentRegion.textContent = `No resource selected.` : this.#currentRegion.textContent = `This ${e.source.kind} binding is host-managed.` : this.#currentRegion.textContent = `Selected ${this.#current.resourceType}: ${this.#current.id}.`, this.readOnly && this.#currentRegion.append(document.createTextNode(` Selection is read-only${t ? ` for this collection port` : ``}.`));
	}
	#renderResults() {
		let e = this.#items.map((e) => {
			let t = document.createElement(`li`), n = a(e.label), i = document.createElement(`span`);
			return i.textContent = `${n} (${e.id})`, t.append(i), this.readOnly || t.append(r(this.#current?.id === e.id && this.#current.resourceType === e.resourceType ? `Selected ${n}` : this.#current === void 0 ? `Select ${n}` : `Replace with ${n}`, () => this.#select(e), this.#current?.id === e.id && this.#current.resourceType === e.resourceType)), t;
		});
		this.#results.replaceChildren(...e), this.#loadMore.hidden = this.#nextCursor === void 0;
	}
	#resetSearch() {
		this.#cancelSearch(!1), this.#items = [], this.#nextCursor = void 0, this.#retryQuery = void 0, this.#renderResults(), this.#status.textContent = `Enter a search term or browse all authorized resources.`;
	}
	async #runSearch(e, t) {
		if (this.#destroyed || this.#type.value === ``) return;
		this.#cancelSearch(!1);
		let n = new AbortController();
		this.#abort = n;
		let r = ++this.#requestSequence, a = {
			...e === void 0 ? {} : { cursor: e },
			limit: 20,
			resourceType: this.#type.value,
			...this.#search.value === `` ? {} : { search: this.#search.value }
		};
		Object.freeze(a), this.#retryQuery = a, this.#retry.hidden = !0, this.#status.textContent = t ? `Loading more authorized resources…` : `Searching…`, this.#setBusy(!0);
		try {
			let e = parseStudioResourceSearchPage(await this.#service.search(a, n.signal), a);
			if (n.signal.aborted || this.#destroyed || r !== this.#requestSequence) return;
			this.#items = t ? i(this.#items, e.items) : e.items, this.#nextCursor = e.nextCursor, this.#retryQuery = void 0, this.#renderResults(), this.#status.textContent = this.#items.length === 0 ? `No authorized resources match this search.` : `${this.#items.length} authorized resource${this.#items.length === 1 ? `` : `s`} shown.`;
		} catch {
			if (n.signal.aborted || this.#destroyed || r !== this.#requestSequence) return;
			this.#retry.hidden = !1, this.#status.textContent = `Resource search is unavailable. No selection was changed.`;
		} finally {
			r === this.#requestSequence && (this.#abort = void 0, this.#setBusy(!1));
		}
	}
	#scheduleSearch() {
		this.#debounce !== void 0 && clearTimeout(this.#debounce), this.#debounce = setTimeout(() => {
			this.#debounce = void 0, this.#runSearch(void 0, !1);
		}, 300);
	}
	#select(e) {
		this.readOnly || (this.#current = {
			id: e.id,
			kind: `resource-reference`,
			resourceType: e.resourceType
		}, this.#clear.disabled = !1, this.#currentRegion.textContent = `Selected ${e.resourceType}: ${e.id}.`, this.#renderResults(), this.#onChange?.({ source: structuredClone(this.#current) }));
	}
	#setBusy(e) {
		this.#cancel.hidden = !e, this.#searchButton.disabled = this.#types.length === 0, this.#type.disabled = this.#types.length === 0, this.#search.disabled = this.#types.length === 0, this.#loadMore.disabled = e, this.#results.setAttribute(`aria-busy`, String(e));
	}
};
function r(e, t, n = !1) {
	let r = document.createElement(`button`);
	return r.type = `button`, r.textContent = e, r.setAttribute(`aria-label`, e), r.disabled = n, r.addEventListener(`click`, t), r;
}
function i(e, t) {
	let n = new Set(e.map((e) => `${e.resourceType}\u0000${e.id}`));
	for (let e of t) {
		let t = `${e.resourceType}\u0000${e.id}`;
		if (n.has(t)) throw TypeError(`Resource search repeated an existing item.`);
		n.add(t);
	}
	return [...e, ...t];
}
function a(e) {
	return e.defaultMessage ?? e.key;
}
function o(e) {
	return e !== void 0 && e.source.kind !== `resource-reference`;
}
function parseStudioResourceSearchPage(e, t) {
	if (!p(e) || !u(e, [`items`, `nextCursor`]) || !Array.isArray(e.items)) throw TypeError(`Resource search returned an invalid page.`);
	if (e.items.length > t.limit) throw RangeError(`Resource search returned too many items.`);
	let n = e.items.map((e) => s(e, t.resourceType));
	if (new Set(n.map((e) => `${e.resourceType}\u0000${e.id}`)).size !== n.length) throw TypeError(`Resource search returned duplicate items.`);
	let r = e.nextCursor;
	if (r !== void 0 && (typeof r != `string` || r.length === 0 || r.length > 500)) throw TypeError(`Resource search returned an invalid cursor.`);
	return {
		items: n,
		...r === void 0 ? {} : { nextCursor: r }
	};
}
function s(e, t) {
	if (!p(e) || !u(e, [
		`id`,
		`label`,
		`resourceType`
	]) || !m(e.id) || e.resourceType !== t || !d(e.label)) throw TypeError(`Resource search returned an invalid item.`);
	return {
		id: e.id,
		label: structuredClone(e.label),
		resourceType: t
	};
}
function c(e) {
	if (e.length > 100) throw RangeError(`Resource type inventory exceeds 100 entries.`);
	let t = /* @__PURE__ */ new Set();
	return e.map((e) => {
		if (!p(e) || !u(e, [`id`, `label`]) || !f(e.id) || !d(e.label) || t.has(e.id)) throw TypeError(`Resource type inventory is invalid or duplicated.`);
		return t.add(e.id), structuredClone(e);
	});
}
function l(e) {
	if (e?.source.kind === `resource-reference`) {
		if (!isStudioResourceReference(e.source)) throw TypeError(`Resource binding is not canonical.`);
		return structuredClone(e.source);
	}
}
function u(e, t) {
	let n = Object.keys(e);
	return n.length <= t.length && n.every((e) => t.includes(e));
}
function d(e) {
	return !p(e) || !u(e, [`key`, `defaultMessage`]) || !f(e.key) ? !1 : e.defaultMessage === void 0 || typeof e.defaultMessage == `string` && e.defaultMessage.length >= 1 && e.defaultMessage.length <= 500;
}
function f(e) {
	return typeof e == `string` && e.length <= 160 && /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\/[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/u.test(e);
}
function p(e) {
	if (typeof e != `object` || !e || Array.isArray(e)) return !1;
	let t = Object.getPrototypeOf(e);
	return t === Object.prototype || t === null;
}
function m(e) {
	return typeof e == `string` && e.length <= 240 && ![
		`__proto__`,
		`prototype`,
		`constructor`
	].includes(e) && /^[A-Za-z0-9][A-Za-z0-9._:/-]*$/u.test(e);
}
//#endregion
//#region node_modules/@kumwe/studio/dist/kumwe-studio.js
var KumweStudioElement = class extends i$16 {
	static properties = {
		announcement: {
			attribute: !1,
			state: !0
		},
		authoringControlRegistry: { attribute: !1 },
		canvasDirectManipulation: {
			attribute: !1,
			state: !0
		},
		canvasGeometry: {
			attribute: !1,
			state: !0
		},
		commandSession: { attribute: !1 },
		configuration: { attribute: !1 },
		contentModel: { attribute: !1 },
		designControls: { attribute: !1 },
		document: { attribute: !1 },
		messages: { attribute: !1 },
		patterns: { attribute: !1 },
		paletteFilter: {
			attribute: !1,
			state: !0
		},
		paletteOpen: {
			attribute: !1,
			state: !0
		},
		previewBinding: { attribute: !1 },
		previewState: {
			attribute: !1,
			state: !0
		},
		resourceSearchService: { attribute: !1 },
		selectedNodeId: {
			attribute: !1,
			state: !0
		},
		theme: { attribute: !1 },
		viewports: { attribute: !1 }
	};
	static styles = i$17`
    :host {
      --studio-border: #d7dce2;
      --studio-panel: #f7f8fa;
      --studio-primary: #3157d5;
      color: #18202a;
      display: block;
      font:
        400 0.9375rem/1.45 system-ui,
        sans-serif;
      min-height: 30rem;
    }

    .workspace {
      display: grid;
      grid-template-columns:
        minmax(10rem, 13rem) minmax(18rem, 1fr) minmax(11rem, 15rem)
        minmax(12rem, 16rem);
      min-height: inherit;
    }

    .panel,
    .canvas {
      border: 1px solid var(--studio-border);
      min-inline-size: 0;
      padding: 1rem;
    }

    .panel {
      background: var(--studio-panel);
    }

    h2 {
      font-size: 0.8125rem;
      letter-spacing: 0.05em;
      margin: 0 0 0.75rem;
      text-transform: uppercase;
    }

    button {
      background: white;
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      color: inherit;
      cursor: pointer;
      font: inherit;
      padding: 0.5rem 0.625rem;
      text-align: left;
    }

    button:disabled {
      cursor: not-allowed;
      opacity: 0.55;
    }

    button:focus-visible {
      outline: 0.1875rem solid color-mix(in srgb, var(--studio-primary), transparent 55%);
      outline-offset: 0.125rem;
    }

    button[aria-pressed='true'] {
      border-color: var(--studio-primary);
      box-shadow: inset 0.1875rem 0 0 var(--studio-primary);
    }

    .palette,
    .tree {
      display: grid;
      gap: 0.5rem;
      list-style: none;
      margin: 0;
      padding: 0;
    }

    .toolbar {
      display: flex;
      gap: 0.5rem;
      justify-content: flex-end;
      margin-bottom: 1rem;
    }

    .command-palette-toggle {
      font-size: 0.8125rem;
      margin-bottom: 0.75rem;
      padding: 0.375rem 0.5rem;
    }

    .command-palette {
      background: var(--studio-panel);
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      display: grid;
      gap: 0.5rem;
      margin-bottom: 1rem;
      padding: 0.75rem;
    }

    .command-palette input {
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      box-sizing: border-box;
      font: inherit;
      inline-size: 100%;
      min-inline-size: 0;
      padding: 0.5rem 0.625rem;
    }

    .command-palette .hint {
      margin: 0;
    }

    .command-results {
      display: grid;
      gap: 0.375rem;
      list-style: none;
      margin: 0;
      padding: 0;
    }

    .command-empty {
      color: #5d6671;
      margin: 0;
    }

    .canvas-chip {
      touch-action: none;
    }

    .drop-indicator {
      border: 1px dashed var(--studio-primary);
      border-radius: 0.375rem;
      font-weight: 600;
      margin: 0 0 0.75rem;
      padding: 0.375rem 0.5rem;
    }

    .node-children {
      border-inline-start: 1px solid var(--studio-border);
      margin: 0.5rem 0 0 0.75rem;
      padding-inline-start: 0.75rem;
    }

    .empty {
      color: #5d6671;
      padding: 3rem 1rem;
      text-align: center;
    }

    .hint {
      color: #5d6671;
      font-size: 0.75rem;
      margin: 0 0 0.75rem;
      overflow-wrap: anywhere;
    }

    .unresolved {
      background: #fbe9e9;
      border: 1px solid #e5b6b6;
      border-radius: 0.25rem;
      color: #7c1d1d;
      font-size: 0.75rem;
      padding: 0 0.25rem;
    }

    .outline-controls {
      display: flex;
      flex-wrap: wrap;
      gap: 0.375rem;
      margin-top: 0.375rem;
    }

    .outline-controls button {
      font-size: 0.8125rem;
      padding: 0.375rem 0.5rem;
    }

    .outline-move-destination-label {
      display: grid;
      flex-basis: 100%;
      font-size: 0.75rem;
      gap: 0.25rem;
      min-inline-size: 0;
    }

    .outline-move-destination {
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      box-sizing: border-box;
      font: inherit;
      inline-size: 100%;
      max-inline-size: 100%;
      min-inline-size: 0;
      padding: 0.375rem 0.5rem;
    }

    .viewport-switcher {
      display: flex;
      flex-wrap: wrap;
      gap: 0.375rem;
      margin-bottom: 0.75rem;
    }

    .viewport-switcher button {
      font-size: 0.8125rem;
      padding: 0.375rem 0.5rem;
    }

    .preview-region {
      background: white;
      border: 1px solid var(--studio-border);
      border-radius: 0.5rem;
      margin-bottom: 1rem;
      padding: 0.75rem;
    }

    .preview-region h2 {
      margin-bottom: 0.25rem;
    }

    .preview-status {
      color: #5d6671;
      font-size: 0.8125rem;
      margin: 0 0 0.625rem;
    }

    .preview-surface-slot {
      display: block;
      max-inline-size: 100%;
      overflow: visible;
    }

    .preview-stage {
      isolation: isolate;
      overflow: auto;
      position: relative;
    }

    .preview-stage:focus-visible {
      outline: 0.1875rem solid color-mix(in srgb, var(--studio-primary), transparent 55%);
      outline-offset: 0.125rem;
    }

    .preview-surface-slot::slotted(iframe) {
      border: 0;
      display: block;
      inline-size: 100%;
      min-block-size: 20rem;
    }

    .preview-canvas-overlay {
      inset-block-start: 0;
      inset-inline-start: 0;
      max-inline-size: none;
      overflow: hidden;
      pointer-events: none;
      position: absolute;
      z-index: 1;
    }

    .preview-canvas-region {
      fill: transparent;
      pointer-events: all;
      stroke: transparent;
      stroke-width: 2;
      vector-effect: non-scaling-stroke;
    }

    .preview-canvas-overlay[data-interactive='false'] .preview-canvas-region {
      pointer-events: none;
    }

    .preview-canvas-overlay[data-interactive='true'] {
      pointer-events: auto;
    }

    .canvas-edit-toggle {
      margin-bottom: 0.625rem;
    }

    .preview-canvas-region[data-hovered='true'] {
      fill: color-mix(in srgb, var(--studio-primary), transparent 92%);
      stroke: color-mix(in srgb, var(--studio-primary), transparent 35%);
    }

    .preview-canvas-region[data-selected='true'] {
      fill: color-mix(in srgb, var(--studio-primary), transparent 88%);
      stroke: var(--studio-primary);
      stroke-width: 3;
    }

    .preview-canvas-drop-indicator {
      fill: color-mix(in srgb, var(--studio-primary), transparent 72%);
      pointer-events: none;
      stroke: var(--studio-primary);
      stroke-width: 2;
      vector-effect: non-scaling-stroke;
    }

    .preview-canvas-status {
      background: white;
      border: 1px dashed var(--studio-primary);
      border-radius: 0.375rem;
      font-size: 0.8125rem;
      font-weight: 600;
      margin: 0.5rem 0 0;
      padding: 0.375rem 0.5rem;
    }

    .breadcrumb ol {
      align-items: center;
      display: flex;
      flex-wrap: wrap;
      gap: 0.375rem;
      list-style: none;
      margin: 0 0 0.75rem;
      padding: 0;
    }

    .breadcrumb li + li::before {
      content: '\\203A';
      margin-inline-end: 0.375rem;
    }

    .breadcrumb button {
      font-size: 0.8125rem;
      padding: 0.25rem 0.375rem;
    }

    .breadcrumb-current {
      font-weight: 600;
    }

    .diagnostics {
      grid-column: 1 / -1;
    }

    .diagnostics-list {
      display: grid;
      gap: 0.5rem;
      list-style: none;
      margin: 0;
      padding: 0;
    }

    .diagnostics-empty {
      color: #5d6671;
      margin: 0;
    }

    .diagnostic-severity {
      font-size: 0.75rem;
      font-weight: 600;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .diagnostic-severity::after {
      content: ':';
    }

    .statusbar {
      align-items: center;
      border: 1px solid var(--studio-border);
      display: flex;
      gap: 0.5rem;
      grid-column: 1 / -1;
      padding: 0.5rem 1rem;
    }

    .save-state[data-dirty='true'] {
      color: #7c4a03;
    }

    .assistive {
      block-size: 1px;
      clip-path: inset(50%);
      inline-size: 1px;
      margin: -1px;
      overflow: hidden;
      padding: 0;
      position: absolute;
      white-space: nowrap;
    }

    dl {
      display: grid;
      gap: 0.75rem;
      margin: 0;
    }

    dt {
      color: #5d6671;
      font-size: 0.75rem;
      text-transform: uppercase;
    }

    dd {
      margin: 0.125rem 0 0;
      overflow-wrap: anywhere;
    }

    .inspector-section {
      margin-top: 1rem;
    }

    .inspector-section h3 {
      font-size: 0.75rem;
      letter-spacing: 0.05em;
      margin: 0 0 0.5rem;
      text-transform: uppercase;
    }

    .inspector-rows {
      display: grid;
      gap: 0.5rem;
      list-style: none;
      margin: 0 0 0.5rem;
      padding: 0;
    }

    .inspector-row {
      align-items: center;
      display: flex;
      flex-wrap: wrap;
      gap: 0.375rem;
    }

    .inspector-authoring-row {
      display: grid;
      gap: 0.375rem;
    }

    .inspector-authoring-control,
    .inspector-resource-control {
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      min-inline-size: 0;
      overflow: auto;
      padding: 0.5rem;
    }

    .inspector-authoring-control :is(input, select, textarea, button),
    .inspector-resource-control :is(input, select, textarea, button) {
      font: inherit;
      max-inline-size: 100%;
    }

    .inspector-authoring-control textarea {
      box-sizing: border-box;
      inline-size: 100%;
    }

    .studio-resource-search {
      display: grid;
      gap: 0.375rem;
      grid-template-columns: minmax(0, 1fr);
    }

    .studio-resource-current,
    .studio-resource-status {
      color: #5d6671;
      font-size: 0.75rem;
      overflow-wrap: anywhere;
    }

    .studio-resource-results {
      display: grid;
      gap: 0.375rem;
      list-style: none;
      padding: 0;
    }

    .studio-resource-results li {
      align-items: center;
      display: flex;
      flex-wrap: wrap;
      gap: 0.375rem;
      justify-content: space-between;
      overflow-wrap: anywhere;
    }

    .inspector-name {
      font-size: 0.8125rem;
      font-weight: 600;
      overflow-wrap: anywhere;
    }

    .inspector input {
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      flex: 1 1 6rem;
      font: inherit;
      min-inline-size: 0;
      padding: 0.375rem 0.5rem;
    }

    .inspector input:disabled {
      background: var(--studio-panel);
      cursor: not-allowed;
      opacity: 0.55;
    }

    .inspector select {
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      flex: 1 1 6rem;
      font: inherit;
      min-inline-size: 0;
      padding: 0.375rem 0.5rem;
    }

    .inspector select:disabled {
      background: var(--studio-panel);
      cursor: not-allowed;
      opacity: 0.55;
    }

    .inspector textarea {
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      flex: 1 1 6rem;
      font: inherit;
      min-block-size: 3rem;
      min-inline-size: 0;
      padding: 0.375rem 0.5rem;
      resize: vertical;
    }

    .inspector-binding-model {
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      display: grid;
      gap: 0.375rem;
      padding: 0.5rem;
    }

    .inspector-binding-control {
      align-items: center;
      background: white;
      border-inline-start: 0.1875rem solid var(--studio-border);
      display: grid;
      flex-basis: 100%;
      gap: 0.25rem;
      padding: 0.375rem 0.5rem;
    }

    .inspector-binding-control > :is(input, select, textarea) {
      inline-size: 100%;
    }

    .inspector-binding-path,
    .inspector-binding-status {
      color: #5d6671;
      flex-basis: 100%;
      font-size: 0.75rem;
      overflow-wrap: anywhere;
    }

    .inspector-provenance {
      color: #5d6671;
      flex-basis: 100%;
      font-size: 0.75rem;
    }

    .outline-slot-label {
      color: #5d6671;
      display: block;
      font-size: 0.75rem;
      margin: 0.25rem 0;
    }

    .inspector button {
      font-size: 0.8125rem;
      padding: 0.375rem 0.5rem;
    }

    .inspector-binding-value {
      font-size: 0.75rem;
      overflow-wrap: anywhere;
    }

    .inspector-empty {
      color: #5d6671;
      margin: 0 0 0.5rem;
    }

    @media (max-width: 60rem) {
      .workspace {
        grid-template-columns: minmax(16rem, 1fr);
      }
    }

    /* SR-019: no chrome motion is essential, so a reduced-motion preference
       zeroes every animation and transition the shell declares now or later. */
    @media (prefers-reduced-motion: reduce) {
      :host,
      *,
      *::before,
      *::after {
        animation-delay: 0s !important;
        animation-duration: 0s !important;
        animation-iteration-count: 1 !important;
        scroll-behavior: auto !important;
        transition-delay: 0s !important;
        transition-duration: 0s !important;
      }
    }
  `;
	#activeViewportId;
	#authoringControls = /* @__PURE__ */ new Map();
	#authoringControlsReady = Promise.resolve();
	#authoringDiagnostics = /* @__PURE__ */ new Map();
	#defaultAuthoringControlRegistry = new StudioAuthoringControlRegistry();
	#announcementPending = !1;
	#commandSequence = 0;
	#bindingProjection;
	#defaultDefinitions = createCoreProductionBlockDefinitions();
	#defaultPatterns = createCoreProductionPatterns();
	#diagnostics = [];
	#drag;
	#hoveredPreviewNodeId;
	#internalDocumentUpdate = !1;
	#lastDirty = !1;
	#paletteInvoker;
	#pendingFocusNodeId;
	#pendingPaletteFocus = !1;
	#onDocumentKeydown = (e) => {
		e.key === `Escape` && (this.#drag !== void 0 || this.#previewDrag !== void 0) && this.#cancelDrag() && (e.preventDefault(), e.stopPropagation());
	};
	#pendingPreviewAnnouncements = [];
	#activePreviewBinding;
	#previewBindingGeneration;
	#previewSurface;
	#previewDrag;
	#removedNodes = [];
	#registry;
	#resourceBindingControls = /* @__PURE__ */ new Map();
	#session;
	#sessionGeneration = ``;
	get activeViewport() {
		let e = this.#orderedViewports();
		if (e.length === 0) return;
		let t = e.find((e) => e.id === this.#activeViewportId), n = e.find((e) => e.id === this.configuration?.session.preview.initialViewport);
		return t ?? n ?? e.find((e) => e.base) ?? e[0];
	}
	get stateVersion() {
		return this.#session?.stateVersion ?? 0;
	}
	get dirty() {
		return this.#session?.dirty ?? !1;
	}
	get diagnostics() {
		return [...this.#diagnostics, ...this.#authoringDiagnostics.values()].map((e) => structuredClone(e));
	}
	get selection() {
		return this.#session?.selection ?? [];
	}
	get authoringReady() {
		return this.#authoringControlsReady;
	}
	get sessionMode() {
		return this.#session?.mode;
	}
	execute(e) {
		if (this.configuration?.session.sessionState === `read-only`) {
			let e = `The current Studio session is read-only.`;
			throw this.#announce(`studio.shell/announce-conflict`, { message: e }), Error(e);
		}
		let t = this.#session;
		if (t === void 0) throw Error(`Load a blueprint document before executing a command.`);
		let n;
		try {
			n = t.execute(e);
		} catch (e) {
			throw e instanceof StudioCommandError && O.has(e.code) ? this.#announce(`studio.shell/announce-conflict`, { message: e.message }) : this.#announce(`studio.shell/announce-command-failed`, { message: e instanceof Error ? e.message : String(e) }), e;
		}
		return this.#assignInternalDocument(n), this.selectedNodeId = t.selection[0], this.#emitDocumentChange({
			command: e,
			document: n,
			source: `command`
		}), this.#syncDirty(), n;
	}
	selectNode(e) {
		let t = this.#session;
		if (t === void 0) throw Error(`Load a blueprint document before selecting a node.`);
		if (e === void 0) {
			t.clearSelection(), this.selectedNodeId = void 0, this.#previewSurface?.selectNode(void 0);
			return;
		}
		t.select([e]), this.selectedNodeId = e, this.#previewSurface?.selectNode(e);
	}
	markSaved(e, t) {
		let n = this.#session;
		n !== void 0 && (n.markSaved(e ?? n.savedRevision, t), this.#assignInternalDocument(n.document), this.#syncDirty());
	}
	refreshPreviewGeometry() {
		this.#previewSurface?.refreshGeometry();
	}
	teardownPreview(e) {
		this.#previewSurface !== void 0 && (this.#queuePreviewAnnouncement(`studio.shell/announce-preview-torn-down`, { reason: e }), this.#previewSurface.teardown(e));
	}
	disconnectedCallback() {
		this.#destroyAuthoringControls(), this.#destroyResourceBindingControls(), this.ownerDocument.removeEventListener(`keydown`, this.#onDocumentKeydown, !0), this.teardownPreview(`studio.preview/surface-disconnected`), super.disconnectedCallback();
	}
	connectedCallback() {
		super.connectedCallback(), this.ownerDocument.addEventListener(`keydown`, this.#onDocumentKeydown, !0);
	}
	notifyPreviewMessage(e) {
		e.type === `studio.preview/reload` ? this.#queuePreviewAnnouncement(`studio.shell/announce-preview-reloaded`, { reason: e.payload.reason }) : e.type === `studio.preview/teardown` && this.#queuePreviewAnnouncement(`studio.shell/announce-preview-torn-down`, { reason: e.payload.reason });
	}
	redo() {
		let e = this.#session;
		if (this.#isReadOnly() || e?.canRedo !== !0) return this.document;
		this.#captureOutlineFocus();
		let t = e.redo();
		return this.#assignInternalDocument(t), this.selectedNodeId = e.selection[0], this.#emitDocumentChange({
			command: null,
			document: t,
			source: `redo`
		}), this.#syncDirty(), this.#announce(`studio.shell/announce-redid`), t;
	}
	undo() {
		let e = this.#session;
		if (this.#isReadOnly() || e?.canUndo !== !0) return this.document;
		this.#captureOutlineFocus();
		let t = e.undo();
		return this.#assignInternalDocument(t), this.selectedNodeId = e.selection[0], this.#emitDocumentChange({
			command: null,
			document: t,
			source: `undo`
		}), this.#syncDirty(), this.#announce(`studio.shell/announce-undid`), t;
	}
	willUpdate(e) {
		(e.has(`viewports`) || e.has(`theme`)) && (this.#activeViewportId = void 0), e.has(`configuration`) && this.#rebuildRegistry(), (e.has(`configuration`) || e.has(`previewBinding`)) && this.#synchronizePreviewSurface(), (e.has(`document`) || e.has(`configuration`) || e.has(`commandSession`)) && (this.#internalDocumentUpdate ? this.#internalDocumentUpdate = !1 : this.#rebuildSession()), (e.has(`document`) || e.has(`configuration`) || e.has(`contentModel`)) && this.#revalidate();
	}
	updated(e) {
		e.has(`authoringControlRegistry`) && this.#destroyAuthoringControls(), e.has(`resourceSearchService`) && this.#destroyResourceBindingControls();
		for (let e of this.shadowRoot?.querySelectorAll(`select[data-current-value]`) ?? []) {
			let t = e.dataset.currentValue;
			t !== void 0 && e.value !== t && (e.value = t);
		}
		this.#announcementPending = !1;
		let t = this.#pendingPreviewAnnouncements.shift();
		t !== void 0 && t !== this.announcement && (this.announcement = t, this.#announcementPending = !0), this.#pendingPaletteFocus && (this.#pendingPaletteFocus = !1, this.shadowRoot?.querySelector(`.command-palette input`)?.focus());
		for (let e of this.shadowRoot?.querySelectorAll(`select.layout-role-select`) ?? []) e.value = e.dataset.role ?? ``;
		(e.has(`document`) || e.has(`configuration`) || e.has(`previewBinding`) || e.has(`theme`) || e.has(`viewports`)) && this.#schedulePreview(), this.#authoringControlsReady = this.#authoringControlsReady.catch(() => void 0).then(async () => {
			await this.#synchronizeAuthoringControls(), this.#synchronizeResourceBindingControls();
		});
		let n = this.#pendingFocusNodeId;
		n !== void 0 && (this.#pendingFocusNodeId = void 0, this.#focusOutlineEntry(n));
	}
	render() {
		let e = this.#session, n = this.#isReadOnly(), i = this.document?.roots ?? [], a = this.document === void 0 || this.selectedNodeId === void 0 ? void 0 : findOutlineLocation(this.document.roots, this.selectedNodeId)?.node, o = [...this.#diagnostics, ...this.#authoringDiagnostics.values()].sort((e, t) => P[e.severity] - P[t.severity]);
		return b$12`
      <div
        class="workspace"
        @keydown=${(e) => {
			this.#onWorkspaceKeydown(e);
		}}
      >
        <aside class="panel" aria-label=${this.#text(`studio.shell/palette-label`)}>
          <h2>${this.#text(`studio.shell/palette-heading`)}</h2>
          <ul class="palette">
            ${this.#activeDefinitions().map((e) => b$12`
                <li>
                  <button
                    type="button"
                    ?disabled=${!this.#canInsertDefinition(e)}
                    @click=${() => this.#requestInsert(e)}
                  >
                    ${G(e.label)}
                  </button>
                </li>
              `)}
          </ul>
          ${this.#activePatterns().length === 0 ? A$10 : b$12`
                  <h2 class="pattern-heading">${this.#text(`studio.shell/patterns-heading`)}</h2>
                  <ul class="palette pattern-palette">
                    ${this.#activePatterns().map((e) => b$12`
                        <li>
                          <button
                            type="button"
                            class="pattern-apply"
                            data-pattern-id=${e.id}
                            ?disabled=${this.#patternDestination(e) === void 0}
                            @click=${() => {
			this.#applyPattern(e);
		}}
                          >
                            ${G(e.label)}
                          </button>
                        </li>
                      `)}
                  </ul>
                `}
        </aside>

        <main
          class="canvas"
          aria-label=${this.#text(`studio.shell/canvas-label`)}
          data-viewport=${this.activeViewport?.id ?? A$10}
          @pointermove=${(e) => {
			this.#onCanvasPointerMove(e);
		}}
          @pointerup=${(e) => {
			this.#onCanvasPointerUp(e);
		}}
          @pointercancel=${(e) => {
			this.#onCanvasPointerCancel(e);
		}}
        >
          ${this.#renderViewportSwitcher()} ${this.#renderBreadcrumb()} ${this.#renderPreview()}
          <button
            type="button"
            class="command-palette-toggle"
            aria-expanded=${this.paletteOpen === !0 ? `true` : `false`}
            @click=${(e) => {
			this.#togglePalette(e);
		}}
          >
            ${this.#text(`studio.shell/command-palette-toggle`)}
          </button>
          ${this.#renderCommandPalette()}
          <div class="toolbar" role="group" aria-label=${this.#text(`studio.shell/history-label`)}>
            <button
              type="button"
              ?disabled=${e?.canUndo !== !0 || n}
              @click=${() => {
			this.undo();
		}}
            >
              ${this.#text(`studio.shell/undo`)}
            </button>
            <button
              type="button"
              ?disabled=${e?.canRedo !== !0 || n}
              @click=${() => {
			this.redo();
		}}
            >
              ${this.#text(`studio.shell/redo`)}
            </button>
          </div>
          ${this.#renderDropIndicator()}
          ${i.length === 0 ? b$12`<p class="empty">${this.#text(`studio.shell/canvas-empty`)}</p>` : this.#previewCapabilityAvailable() && this.previewBinding !== void 0 ? A$10 : b$12`<ul class="tree structural-canvas-fallback">
                    ${i.map((e) => this.#renderCanvasNode(e))}
                  </ul>`}
        </main>

        <aside class="panel outline" aria-label=${this.#text(`studio.shell/outline-heading`)}>
          <h2>${this.#text(`studio.shell/outline-heading`)}</h2>
          <p class="hint">${this.#text(`studio.shell/outline-hint`)}</p>
          ${i.length === 0 ? b$12`<p class="empty">${this.#text(`studio.shell/outline-empty`)}</p>` : b$12`<ul class="tree">
                  ${i.map((e) => this.#renderOutlineNode(e))}
                </ul>`}
        </aside>

        <aside class="panel inspector" aria-label=${this.#text(`studio.shell/inspector-heading`)}>
          <h2>${this.#text(`studio.shell/inspector-heading`)}</h2>
          ${a === void 0 ? b$12`<p>${this.#text(`studio.shell/inspector-empty`)}</p>` : this.#renderInspector(a)}
        </aside>

        <section
          class="panel diagnostics"
          aria-label=${this.#text(`studio.shell/diagnostics-heading`)}
        >
          <h2>${this.#text(`studio.shell/diagnostics-heading`)}</h2>
          ${o.length === 0 ? b$12`<p class="diagnostics-empty">
                  ${this.#text(`studio.shell/diagnostics-empty`)}
                </p>` : b$12`<ul class="diagnostics-list">
                  ${o.map((e) => this.#renderDiagnostic(e))}
                </ul>`}
        </section>

        <footer class="statusbar" aria-label=${this.#text(`studio.shell/status-label`)}>
          ${e === void 0 ? A$10 : b$12`<span class="save-state" data-dirty=${e.dirty ? `true` : `false`}>
                  ${this.#text(e.dirty ? `studio.shell/save-state-unsaved` : `studio.shell/save-state-saved`)}
                </span>`}
          <p class="assistive" aria-live="polite">${this.announcement ?? ``}</p>
        </footer>
      </div>
    `;
	}
	#addOverride(e, t) {
		let n = this.shadowRoot?.querySelector(`input.inspector-add-override-name`) ?? null, r = this.shadowRoot?.querySelector(`input.inspector-add-override-value`) ?? null;
		if (n === null || r === null) return;
		let i = n.value.trim();
		if (i.length === 0) {
			this.#announce(`studio.shell/announce-name-required`);
			return;
		}
		let a = this.#parseJsonInput(r.value, i);
		a !== void 0 && this.#setNodeProperty(e, i, a.value, t) && (n.value = ``, r.value = ``);
	}
	#addProperty(e) {
		let t = this.shadowRoot?.querySelector(`input.inspector-add-property-name`) ?? null, n = this.shadowRoot?.querySelector(`input.inspector-add-property-value`) ?? null;
		if (t === null || n === null) return;
		let r = t.value.trim();
		if (r.length === 0) {
			this.#announce(`studio.shell/announce-name-required`);
			return;
		}
		let i = this.#parseJsonInput(n.value, r);
		i !== void 0 && this.#setNodeProperty(e, r, i.value, void 0) && (t.value = ``, n.value = ``);
	}
	#announce(e, t) {
		this.announcement = messageText(e, this.messages, t), this.#announcementPending = !0;
	}
	#assignInternalDocument(e) {
		this.#internalDocumentUpdate = !0, this.document = e;
	}
	#assignedSizeRole(e, t, n) {
		return n === void 0 ? e.sizeRoles?.[t] : e.responsiveSizeRoles?.[t]?.[n.id];
	}
	#axisText(e) {
		return this.#text(k[e]);
	}
	#cancelDrag() {
		let e = this.#previewDrag;
		if (e !== void 0) return this.#previewDrag = void 0, this.#releasePreviewDragCapture(e), e.active && this.#announce(`studio.shell/announce-drag-cancelled`, { label: e.label }), this.requestUpdate(), !0;
		let t = this.#drag;
		return t !== void 0 && (this.#drag = void 0, this.#releaseDragCapture(t), t.active && this.#announce(`studio.shell/announce-drag-cancelled`, { label: t.label }), this.requestUpdate(), !0);
	}
	#captureOutlineFocus() {
		let e = this.shadowRoot?.activeElement;
		e instanceof HTMLElement && e.classList.contains(`outline-entry`) && e.dataset.nodeId !== void 0 && (this.#pendingFocusNodeId = e.dataset.nodeId);
	}
	#closePalette(e) {
		this.paletteOpen = !1, this.paletteFilter = ``;
		let t = this.#paletteInvoker;
		this.#paletteInvoker = void 0, e && t?.isConnected === !0 && t.focus();
	}
	#commandEnvelope(e, t) {
		return this.#commandSequence += 1, {
			artifactId: e.id,
			baseStateVersion: t.stateVersion,
			contractVersion: e.contractVersion,
			id: `studio-shell-command-${this.#commandSequence}`,
			kind: `command`,
			sessionGeneration: this.#sessionGeneration
		};
	}
	#currentInspectorNode(e) {
		return this.document === void 0 ? void 0 : findOutlineLocation(this.document.roots, e)?.node;
	}
	#deleteNode(e) {
		let t = this.#session, n = this.document;
		if (t === void 0 || n === void 0 || !this.#canMutateNode(e, `studio.command/remove-node`)) return;
		let r = findOutlineLocation(n.roots, e.id);
		if (r === void 0) return;
		let i = r.index > 0 ? r.collection[r.index - 1]?.id : void 0, a = r.parentNodeId, o = this.#nodeLabel(e), s = { position: r.index };
		r.parentNodeId !== void 0 && r.slot !== void 0 && (s.parentNodeId = r.parentNodeId, s.slot = r.slot);
		let c = structuredClone(r.node), l = {
			...this.#commandEnvelope(n, t),
			payload: { nodeId: e.id },
			type: `studio.command/remove-node`
		};
		if (this.#runShellCommand(l)) {
			this.#removedNodes.push({
				destination: s,
				label: o,
				node: c
			});
			let e = this.configuration?.session.limits.maxHistoryEntries ?? 100;
			this.#removedNodes.length > e && this.#removedNodes.splice(0, this.#removedNodes.length - e);
			let t = i ?? a ?? this.document?.roots[0]?.id;
			t !== void 0 && (this.#selectNode(t), this.#pendingFocusNodeId = t), this.#announce(`studio.shell/announce-deleted`, { label: o });
		}
	}
	#restoreLastNode() {
		let e = this.#session, t = this.document, n = this.#lastRestorableNode(), r = n === void 0 ? void 0 : this.#restoreDestination(n);
		if (e === void 0 || t === void 0 || n === void 0 || r === void 0 || !this.#permits(`studio.command/restore-node`)) return;
		let i = {
			...this.#commandEnvelope(t, e),
			payload: {
				destination: r,
				node: structuredClone(n.node)
			},
			type: `studio.command/restore-node`
		};
		this.#runShellCommand(i) && (this.#removedNodes.splice(this.#removedNodes.lastIndexOf(n), 1), this.#selectNode(n.node.id), this.#pendingFocusNodeId = n.node.id, this.#announce(`studio.shell/announce-restored`, { label: n.label }));
	}
	#lastRestorableNode() {
		let e = this.document;
		if (e === void 0) return;
		let t = collectDocumentIds(e.roots);
		for (let e = this.#removedNodes.length - 1; e >= 0; --e) {
			let n = this.#removedNodes[e];
			if (!(n === void 0 || [...collectDocumentIds([n.node])].some((e) => t.has(e))) && this.#restoreDestination(n) !== void 0) return n;
		}
	}
	#restoreDestination(e) {
		let t = this.document;
		if (t === void 0) return;
		let n = e.destination.parentNodeId;
		if (n === void 0) return this.#session?.mode === `hybrid` ? void 0 : { position: Math.min(e.destination.position, t.roots.length) };
		let r = e.destination.slot, i = findOutlineLocation(t.roots, n)?.node, a = this.#findDefinition(i ?? e.node)?.slots.find((e) => e.id === r);
		if (i === void 0 || r === void 0 || a?.accepts.types.includes(e.node.type) !== !0) return;
		let o = i.slots[r] ?? [];
		if (!(o.length >= a.maximum)) {
			if (this.#session?.mode === `hybrid`) {
				let t = i.authoring.slots?.[r]?.allowedBlocks ?? i.authoring.allowedBlocks;
				if (!this.#isComposableSlot(i, r) || t?.includes(e.node.type) === !1) return;
			}
			return {
				parentNodeId: n,
				position: Math.min(e.destination.position, o.length),
				slot: r
			};
		}
	}
	#duplicateNode(e) {
		let t = this.#session, n = this.document;
		if (t === void 0 || n === void 0 || !this.#canMutateNode(e, `studio.command/duplicate-node`)) return;
		let r = allocateDuplicateIdMap(n.roots, e), i = r[e.id];
		if (i === void 0) return;
		let a = {
			...this.#commandEnvelope(n, t),
			payload: {
				idMap: r,
				nodeId: e.id
			},
			type: `studio.command/duplicate-node`
		};
		this.#runShellCommand(a) && (this.#selectNode(i), this.#pendingFocusNodeId = i, this.#announce(`studio.shell/announce-duplicated`, { label: this.#nodeLabel(e) }));
	}
	#emitDocumentChange(e) {
		this.dispatchEvent(new CustomEvent(`studio-document-change`, {
			bubbles: !0,
			composed: !0,
			detail: e
		}));
	}
	#filteredPaletteEntries() {
		let e = (this.paletteFilter ?? ``).trim().toLowerCase(), t = this.#paletteEntries();
		return e.length === 0 ? t : t.filter((t) => t.label.toLowerCase().includes(e));
	}
	#activeDefinitions() {
		return this.configuration?.blockDefinitions ?? this.#defaultDefinitions;
	}
	#activePatterns() {
		return this.patterns ?? (this.configuration?.blockDefinitions === void 0 ? this.#defaultPatterns : []);
	}
	#findDefinition(e) {
		return this.#activeDefinitions().find((t) => t.type === e.type && t.version === e.version);
	}
	#focusOutlineEntry(e) {
		let t = this.shadowRoot?.querySelectorAll(`button.outline-entry`);
		if (t !== void 0) {
			for (let n of t) if (n.dataset.nodeId === e) {
				n.focus();
				return;
			}
		}
	}
	#insertDefinition(e) {
		let t = this.#session, n = this.document, r = this.#insertionDestination(e);
		if (t === void 0 || n === void 0 || r === void 0) return;
		let i = collectDocumentIds(n.roots), a = e.type.slice(e.type.indexOf(`/`) + 1), o = 1, c = `${a}-${o}`;
		for (; i.has(c);) o += 1, c = `${a}-${o}`;
		let l = {
			authoring: { mode: isCoreProductionBlockType(e.type) && e.slots.length > 0 ? `structural` : `content` },
			bindings: {},
			id: c,
			properties: isCoreProductionBlockType(e.type) ? coreProductionInitialProperties(e.type) : {},
			slots: Object.fromEntries(e.slots.map((e) => [e.id, []])),
			type: e.type,
			version: e.version
		}, d = {
			...this.#commandEnvelope(n, t),
			payload: {
				destination: r,
				node: l
			},
			type: `studio.command/insert-node`
		};
		this.#runShellCommand(d) && (this.#selectNode(c), this.#pendingFocusNodeId = c, this.#announce(`studio.shell/announce-inserted`, { label: G(e.label) }));
	}
	#isReadOnly() {
		return this.configuration?.session.sessionState === `read-only` || this.#session?.sessionState === `read-only`;
	}
	#canInsertDefinition(e) {
		return this.#insertionDestination(e) !== void 0;
	}
	#canMutateNode(e, t) {
		if (!this.#permits(t)) return !1;
		if (this.#session?.mode !== `hybrid`) return !0;
		let n = this.document;
		if (n === void 0) return !1;
		let r = findOutlineLocation(n.roots, e.id);
		if (r?.parentNodeId === void 0 || r.slot === void 0 || this.#subtreeContainsLockedNode(e)) return !1;
		let i = findOutlineLocation(n.roots, r.parentNodeId)?.node;
		if (i === void 0 || !this.#isComposableSlot(i, r.slot)) return !1;
		let a = i.authoring.slots?.[r.slot]?.allowedBlocks ?? i.authoring.allowedBlocks;
		return t !== `studio.command/duplicate-node` || a?.includes(e.type) !== !1;
	}
	#insertionDestination(e) {
		if (!this.#permits(`studio.command/insert-node`)) return;
		let t = this.document;
		if (t === void 0) return;
		let n = this.selectedNodeId === void 0 ? void 0 : findOutlineLocation(t.roots, this.selectedNodeId)?.node, r = n === void 0 ? void 0 : this.#findDefinition(n);
		if (n === void 0 || r === void 0) return this.#session?.mode === `hybrid` ? void 0 : { position: t.roots.length };
		for (let t of r.slots) if (t.accepts.types.includes(e.type) && !(this.#session?.mode === `hybrid` && (!this.#isComposableSlot(n, t.id) || (n.authoring.slots?.[t.id]?.allowedBlocks ?? n.authoring.allowedBlocks)?.includes(e.type) === !1))) return {
			parentNodeId: n.id,
			position: n.slots[t.id]?.length ?? 0,
			slot: t.id
		};
		return this.#session?.mode === `hybrid` ? void 0 : { position: t.roots.length };
	}
	#isComposableSlot(e, t) {
		return e.authoring.mode === `structural` || e.authoring.slots?.[t]?.composable === !0;
	}
	#subtreeContainsLockedNode(e) {
		let t = [e];
		for (; t.length > 0;) {
			let e = t.pop();
			if (e === void 0) break;
			if (e.authoring.mode === `locked`) return !0;
			for (let n of Object.values(e.slots)) t.push(...n);
		}
		return !1;
	}
	#moveNode(e, t) {
		let n = this.#session, r = this.document;
		if (n === void 0 || r === void 0 || !this.#canMutateNode(e, `studio.command/reorder-children`)) return;
		let i = findOutlineLocation(r.roots, e.id);
		if (i === void 0) return;
		let a = i.index + t;
		if (a < 0 || a >= i.collection.length) return;
		let o = i.collection.map((e) => e.id), [s] = o.splice(i.index, 1);
		if (s === void 0) return;
		o.splice(a, 0, s);
		let c = { order: o };
		i.parentNodeId !== void 0 && i.slot !== void 0 && (c.parentNodeId = i.parentNodeId, c.slot = i.slot);
		let l = {
			...this.#commandEnvelope(r, n),
			payload: c,
			type: `studio.command/reorder-children`
		};
		this.#runShellCommand(l) && (this.#pendingFocusNodeId = e.id, this.#announce(t === -1 ? `studio.shell/announce-moved-up` : `studio.shell/announce-moved-down`, { label: this.#nodeLabel(e) }));
	}
	#moveDestinations(e) {
		let t = this.document, n = t === void 0 ? void 0 : findOutlineLocation(t.roots, e.id);
		if (t === void 0 || n === void 0 || !this.#permits(`studio.command/move-node`)) return [];
		let r = [];
		for (let t of this.#moveCollections(e)) {
			let i = n.parentNodeId === t.parentNodeId && n.slot === t.slot, a = t.collection.filter((t) => t.id !== e.id);
			for (let o = 0; o <= a.length; o += 1) {
				if (i && o === n.index) continue;
				let s = { position: o };
				if (t.parentNodeId !== void 0 && t.slot !== void 0 && (s.parentNodeId = t.parentNodeId, s.slot = t.slot), !this.#canMoveNodeTo(e, s)) continue;
				let c = {
					destination: s,
					id: `${t.parentNodeId ?? `document`}--${t.slot ?? `roots`}--${o}`,
					label: this.#text(`studio.shell/move-destination-option`, {
						collection: t.label,
						count: String(a.length + 1),
						position: String(o + 1)
					})
				};
				if (i) {
					let t = a.map((e) => e.id);
					t.splice(o, 0, e.id), c.order = t;
				}
				r.push(c);
			}
		}
		return r;
	}
	#moveCollections(e) {
		let t = this.document;
		if (t === void 0) return [];
		let n = [{
			collection: t.roots,
			label: this.#text(`studio.shell/document-roots`),
			specificity: 0
		}], r = t.roots.map((e) => ({
			node: e,
			specificity: 1
		}));
		for (; r.length > 0;) {
			let t = r.shift();
			if (t === void 0) break;
			let { node: i, specificity: a } = t, o = this.#findDefinition(i);
			for (let e of Object.values(i.slots)) r.push(...e.map((e) => ({
				node: e,
				specificity: a + 1
			})));
			for (let t of o?.slots ?? []) t.accepts.types.includes(e.type) && n.push({
				collection: i.slots[t.id] ?? [],
				label: this.#text(`studio.shell/move-slot-collection`, {
					parent: `${this.#nodeLabel(i)} (${i.id})`,
					slot: G(t.label)
				}),
				parentNodeId: i.id,
				slot: t.id,
				specificity: a
			});
		}
		return n;
	}
	#canMoveNodeTo(e, t) {
		let n = this.document, r = n === void 0 ? void 0 : findOutlineLocation(n.roots, e.id);
		if (n === void 0 || r === void 0 || !this.#permits(`studio.command/move-node`)) return !1;
		let i = t.parentNodeId;
		if (i === e.id || i !== void 0 && findAncestry([e], i).length > 0) return !1;
		let a = r.parentNodeId === t.parentNodeId && r.slot === t.slot;
		if (!a && r.parentNodeId !== void 0 && r.slot !== void 0) {
			let t = findOutlineLocation(n.roots, r.parentNodeId)?.node, i = this.#findDefinition(t ?? e)?.slots.find((e) => e.id === r.slot);
			if (t === void 0 || i === void 0 || r.collection.length - 1 < i.minimum) return !1;
		}
		if (i === void 0) {
			let e = n.roots.length - +(r.parentNodeId === void 0);
			return this.#session?.mode !== `hybrid` && t.position <= e;
		}
		if (t.slot === void 0) return !1;
		let o = findOutlineLocation(n.roots, i)?.node, s = (o === void 0 ? void 0 : this.#findDefinition(o))?.slots.find((e) => e.id === t.slot);
		if (o === void 0 || s?.accepts.types.includes(e.type) !== !0) return !1;
		let c = (o.slots[t.slot] ?? []).length - +!!a;
		if (t.position > c || c + 1 > s.maximum) return !1;
		if (this.#session?.mode !== `hybrid`) return !0;
		if (r.parentNodeId === void 0 || r.slot === void 0 || this.#subtreeContainsLockedNode(e) || !this.#isComposableSlot(o, t.slot)) return !1;
		let l = findOutlineLocation(n.roots, r.parentNodeId)?.node;
		return l === void 0 || !this.#isComposableSlot(l, r.slot) ? !1 : (o.authoring.slots?.[t.slot]?.allowedBlocks ?? o.authoring.allowedBlocks)?.includes(e.type) !== !1;
	}
	#moveNodeToOption(e, t) {
		let n = this.#session, r = this.document;
		if (n === void 0 || r === void 0 || !this.#canMoveNodeTo(e, t.destination)) return;
		let i;
		if (t.order === void 0) i = {
			...this.#commandEnvelope(r, n),
			payload: {
				destination: structuredClone(t.destination),
				nodeId: e.id
			},
			type: `studio.command/move-node`
		};
		else {
			let e = { order: [...t.order] };
			t.destination.parentNodeId !== void 0 && t.destination.slot !== void 0 && (e.parentNodeId = t.destination.parentNodeId, e.slot = t.destination.slot), i = {
				...this.#commandEnvelope(r, n),
				payload: e,
				type: `studio.command/reorder-children`
			};
		}
		this.#runShellCommand(i) && (this.#selectNode(e.id), this.#pendingFocusNodeId = e.id, this.#announce(`studio.shell/announce-moved-to`, {
			destination: t.label,
			label: this.#nodeLabel(e)
		}));
	}
	#moveOutlineFocus(e, t) {
		let n = [...this.shadowRoot?.querySelectorAll(`button.outline-entry`) ?? []], r = n.findIndex((t) => t === e);
		r !== -1 && n[r + t]?.focus();
	}
	#nodeLabel(e) {
		let t = this.#findDefinition(e);
		return t === void 0 ? e.type : G(t.label);
	}
	#onCanvasPointerCancel(e) {
		this.#drag?.pointerId === e.pointerId && this.#cancelDrag();
	}
	#onCanvasPointerMove(e) {
		let t = this.#drag;
		if (t === void 0 || e.pointerId !== t.pointerId) return;
		t.active = !0;
		let n = this.#resolveDragIndex(e, t);
		n !== void 0 && (t.targetIndex = n), this.requestUpdate();
	}
	#onCanvasPointerUp(e) {
		let t = this.#drag;
		if (t === void 0 || e.pointerId !== t.pointerId || (this.#drag = void 0, this.#releaseDragCapture(t), !t.active)) return;
		if (this.requestUpdate(), t.targetIndex === t.sourceIndex) {
			this.#announce(`studio.shell/announce-drag-cancelled`, { label: t.label });
			return;
		}
		let n = this.#session, r = this.document, i = r === void 0 ? void 0 : findOutlineLocation(r.roots, t.nodeId)?.node;
		if (n === void 0 || r === void 0 || i === void 0 || !this.#canMutateNode(i, `studio.command/reorder-children`)) return;
		let a = [...t.order], [o] = a.splice(t.sourceIndex, 1);
		if (o === void 0) return;
		a.splice(t.targetIndex, 0, o);
		let s = { order: a };
		t.parentNodeId !== void 0 && t.slot !== void 0 && (s.parentNodeId = t.parentNodeId, s.slot = t.slot);
		let c = {
			...this.#commandEnvelope(r, n),
			payload: s,
			type: `studio.command/reorder-children`
		};
		this.#runShellCommand(c) && (this.#selectNode(t.nodeId), this.#announce(`studio.shell/announce-dropped`, {
			count: String(t.order.length),
			label: t.label,
			position: String(t.targetIndex + 1)
		}));
	}
	#onChipPointerDown(e, t) {
		let n = this.document;
		if (n === void 0 || this.#session === void 0 || !this.#canMutateNode(t, `studio.command/reorder-children`) || e.button !== 0 || this.#drag !== void 0) return;
		let r = findOutlineLocation(n.roots, t.id);
		if (r === void 0 || r.collection.length < 2) return;
		let i = {
			active: !1,
			label: this.#nodeLabel(t),
			nodeId: t.id,
			order: r.collection.map((e) => e.id),
			pointerId: e.pointerId,
			sourceIndex: r.index,
			targetIndex: r.index
		};
		r.parentNodeId !== void 0 && r.slot !== void 0 && (i.parentNodeId = r.parentNodeId, i.slot = r.slot);
		let a = e.currentTarget;
		if (a instanceof Element) {
			i.capture = a;
			try {
				a.setPointerCapture(e.pointerId);
			} catch {}
		}
		this.#drag = i;
	}
	#onInspectorValueKeydown(e, t, n, r) {
		let i = e.currentTarget;
		if (i instanceof HTMLInputElement) {
			if (e.key === `Enter`) {
				e.preventDefault();
				let a = this.#parseJsonInput(i.value, n);
				if (a === void 0) return;
				this.#setNodeProperty(t, n, a.value, r);
				let o = this.#currentInspectorNode(t.id) ?? t;
				i.value = this.#serializedInspectorValue(o, n, r);
				return;
			}
			if (e.key === `Escape`) {
				e.preventDefault(), e.stopPropagation();
				let a = this.#currentInspectorNode(t.id) ?? t;
				i.value = this.#serializedInspectorValue(a, n, r), this.#announce(`studio.shell/announce-edit-cancelled`, { property: n });
			}
		}
	}
	#onLayoutRoleChange(e, t, n) {
		let r = e.currentTarget;
		if (!(r instanceof HTMLSelectElement)) return;
		let i = r.value, a = this.#sizeRoleTargetViewport();
		if (i.length !== 0 && i !== this.#assignedSizeRole(t, n, a) && !this.#setSizeRole(t, n, i)) {
			let e = this.#currentInspectorNode(t.id) ?? t;
			r.value = this.#assignedSizeRole(e, n, a) ?? ``;
		}
	}
	#onLayoutRoleInputKeydown(e, t, n) {
		let r = e.currentTarget;
		if (!(r instanceof HTMLInputElement)) return;
		let i = this.#sizeRoleTargetViewport();
		if (e.key === `Enter`) {
			e.preventDefault();
			let a = r.value.trim();
			if (!W(a)) {
				this.#announce(`studio.shell/announce-size-role-invalid`, { axis: this.#axisText(n) });
				return;
			}
			this.#setSizeRole(t, n, a);
			let o = this.#currentInspectorNode(t.id) ?? t;
			r.value = this.#assignedSizeRole(o, n, i) ?? ``;
			return;
		}
		if (e.key === `Escape`) {
			e.preventDefault(), e.stopPropagation();
			let a = this.#currentInspectorNode(t.id) ?? t;
			r.value = this.#assignedSizeRole(a, n, i) ?? ``, this.#announce(`studio.shell/announce-edit-cancelled`, { property: this.#axisText(n) });
		}
	}
	#onOutlineKeydown(e, t) {
		if (e.key === `ArrowUp` || e.key === `ArrowDown`) {
			e.preventDefault();
			let n = e.key === `ArrowUp` ? -1 : 1;
			e.altKey ? this.#moveNode(t, n) : this.#moveOutlineFocus(e.currentTarget, n);
			return;
		}
		if (e.key === `Delete`) {
			e.preventDefault(), this.#deleteNode(t);
			return;
		}
		(e.key === `d` || e.key === `D`) && (e.ctrlKey || e.metaKey) && (e.preventDefault(), this.#duplicateNode(t));
	}
	#onPaletteEntryKeydown(e) {
		if (e.key !== `ArrowUp` && e.key !== `ArrowDown`) return;
		e.preventDefault();
		let t = this.#paletteResultButtons(), n = t.findIndex((t) => t === e.currentTarget);
		if (n !== -1) {
			if (e.key === `ArrowDown`) {
				t[n + 1]?.focus();
				return;
			}
			if (n === 0) {
				this.shadowRoot?.querySelector(`.command-palette input`)?.focus();
				return;
			}
			t[n - 1]?.focus();
		}
	}
	#onPaletteInputKeydown(e) {
		if (e.key === `ArrowDown`) {
			e.preventDefault(), this.#paletteResultButtons()[0]?.focus();
			return;
		}
		if (e.key === `Enter`) {
			e.preventDefault();
			let t = this.#filteredPaletteEntries().find((e) => !e.disabled);
			t !== void 0 && this.#runPaletteEntry(t);
		}
	}
	#onWorkspaceKeydown(e) {
		if ((e.key === `k` || e.key === `K`) && (e.ctrlKey || e.metaKey)) {
			e.preventDefault(), this.#togglePalette(e);
			return;
		}
		if (e.key === `Escape`) {
			if (this.#cancelDrag()) {
				e.preventDefault();
				return;
			}
			this.paletteOpen === !0 && (e.preventDefault(), this.#closePalette(!0));
		}
	}
	#orderedViewports() {
		return [...this.viewports ?? this.theme?.viewports ?? []].sort((e, t) => e.order - t.order);
	}
	#activeDesignControls() {
		return this.designControls ?? this.theme?.designControls;
	}
	#propertyTargetViewport() {
		let e = this.activeViewport;
		return e === void 0 || e.base ? void 0 : e;
	}
	#designControlProperty(e, t) {
		return e.propertyControls?.find((e) => e.control.endsWith(`/${t.id}`))?.property ?? t.id;
	}
	#applyRecipe(e, t) {
		let n = this.document, r = this.#session, i = this.theme;
		if (n === void 0 || r === void 0 || i === void 0 || !this.#permits(`studio.command/batch`)) return;
		let a;
		try {
			a = recipeSelectionOperations(e, i, t);
		} catch (e) {
			this.#announce(`studio.shell/announce-command-failed`, { message: e instanceof Error ? e.message : String(e) });
			return;
		}
		let o = {
			...this.#commandEnvelope(n, r),
			payload: { operations: a },
			type: `studio.command/batch`
		};
		if (!this.#runShellCommand(o)) return;
		let s = i.recipes.find((e) => e.id === t);
		this.#announce(`studio.shell/announce-recipe-applied`, { recipe: s === void 0 ? t : G(s.label) });
	}
	#applyPattern(e) {
		let t = this.#session, n = this.document, r = this.#patternDestination(e);
		if (t === void 0 || n === void 0 || r === void 0) return;
		let i = {
			...this.#commandEnvelope(n, t),
			payload: {
				destination: r,
				idMap: this.#allocatePatternIdMap(e),
				nodes: structuredClone(e.roots),
				pattern: {
					id: e.id,
					revision: e.revision,
					version: e.version
				}
			},
			type: `studio.command/apply-pattern`
		};
		if (this.#runShellCommand(i)) {
			let t = i.payload.idMap[e.roots[0]?.id ?? ``];
			t !== void 0 && (this.#selectNode(t), this.#pendingFocusNodeId = t), this.#announce(`studio.shell/announce-pattern-applied`, { pattern: G(e.label) });
		}
	}
	#allocatePatternIdMap(e) {
		let t = collectDocumentIds(this.document?.roots ?? []), n = {}, r = [...e.roots];
		for (; r.length > 0;) {
			let e = r.shift();
			if (e === void 0) break;
			let i = 1, a = `${e.id}-pattern-${i}`;
			for (; t.has(a);) i += 1, a = `${e.id}-pattern-${i}`;
			t.add(a), Object.defineProperty(n, e.id, {
				configurable: !0,
				enumerable: !0,
				value: a,
				writable: !0
			});
			for (let t of Object.values(e.slots)) r.push(...t);
		}
		return n;
	}
	#patternDestination(e) {
		if (!this.#permits(`studio.command/apply-pattern`) || e.roots.length === 0) return;
		let t = this.#activeDefinitions(), n = [...e.roots];
		for (; n.length > 0;) {
			let e = n.pop();
			if (e === void 0 || !t.some((t) => t.type === e.type && t.version === e.version)) return;
			for (let t of Object.values(e.slots)) n.push(...t);
		}
		let r = this.document;
		if (r === void 0) return;
		let i = this.selectedNodeId === void 0 ? void 0 : findOutlineLocation(r.roots, this.selectedNodeId)?.node, a = i === void 0 ? void 0 : this.#findDefinition(i);
		if (i !== void 0 && a !== void 0) for (let t of a.slots) {
			let n = i.slots[t.id] ?? [];
			if (e.roots.every((e) => t.accepts.types.includes(e.type)) && n.length + e.roots.length <= t.maximum && (this.#session?.mode !== `hybrid` || this.#isComposableSlot(i, t.id) && e.roots.every((e) => (i.authoring.slots?.[t.id]?.allowedBlocks ?? i.authoring.allowedBlocks)?.includes(e.type) !== !1))) return {
				parentNodeId: i.id,
				position: n.length,
				slot: t.id
			};
		}
		return this.#session?.mode === `hybrid` ? void 0 : { position: r.roots.length };
	}
	#paletteEntries() {
		let e = this.#session, t = this.document, n = this.#isReadOnly(), r = [], i = t === void 0 || this.selectedNodeId === void 0 ? void 0 : findOutlineLocation(t.roots, this.selectedNodeId);
		if (i !== void 0) {
			let e = i.node, t = i.index === 0, n = i.index === i.collection.length - 1;
			r.push({
				disabled: !this.#canMutateNode(e, `studio.command/reorder-children`) || t,
				id: `move-up`,
				label: this.#text(`studio.shell/move-up`),
				run: () => {
					this.#moveNode(e, -1);
				}
			}, {
				disabled: !this.#canMutateNode(e, `studio.command/reorder-children`) || n,
				id: `move-down`,
				label: this.#text(`studio.shell/move-down`),
				run: () => {
					this.#moveNode(e, 1);
				}
			}, {
				disabled: !this.#canMutateNode(e, `studio.command/duplicate-node`),
				id: `duplicate`,
				label: this.#text(`studio.shell/duplicate`),
				run: () => {
					this.#duplicateNode(e);
				}
			}, {
				disabled: !this.#canMutateNode(e, `studio.command/remove-node`),
				id: `delete`,
				label: this.#text(`studio.shell/delete`),
				run: () => {
					this.#deleteNode(e);
				}
			});
			for (let t of this.#moveDestinations(e)) r.push({
				disabled: !1,
				id: `move-to-${t.id}`,
				label: this.#text(`studio.shell/command-move-to`, { destination: t.label }),
				run: () => {
					this.#moveNodeToOption(e, t);
				}
			});
		}
		r.push({
			disabled: n || !this.#permits(`studio.command/restore-node`) || this.#lastRestorableNode() === void 0,
			id: `restore-last-deleted`,
			label: this.#text(`studio.shell/restore-last-deleted`),
			run: () => {
				this.#restoreLastNode();
			}
		}, {
			disabled: n || e?.canUndo !== !0,
			id: `undo`,
			label: this.#text(`studio.shell/undo`),
			run: () => {
				this.undo();
			}
		}, {
			disabled: n || e?.canRedo !== !0,
			id: `redo`,
			label: this.#text(`studio.shell/redo`),
			run: () => {
				this.redo();
			}
		}, {
			disabled: i === void 0,
			id: `clear-selection`,
			label: this.#text(`studio.shell/command-clear-selection`),
			run: () => {
				this.#session?.clearSelection(), this.selectedNodeId = void 0, this.#previewSurface?.selectNode(void 0), this.#announce(`studio.shell/announce-selection-cleared`);
			}
		});
		for (let e of this.#activeDefinitions()) r.push({
			disabled: !this.#canInsertDefinition(e),
			id: `insert-${e.type}@${e.version}`,
			label: this.#text(`studio.shell/command-insert`, { label: G(e.label) }),
			run: () => {
				this.#insertDefinition(e);
			}
		});
		for (let e of this.#activePatterns()) r.push({
			disabled: this.#patternDestination(e) === void 0,
			id: `apply-pattern-${e.id}`,
			label: this.#text(`studio.shell/command-apply-pattern`, { pattern: G(e.label) }),
			run: () => {
				this.#applyPattern(e);
			}
		});
		return r;
	}
	#paletteResultButtons() {
		return [...this.shadowRoot?.querySelectorAll(`button.command-entry`) ?? []].filter((e) => !e.disabled);
	}
	#parseJsonInput(e, t) {
		try {
			return { value: JSON.parse(e) };
		} catch {
			this.#announce(`studio.shell/announce-invalid-value`, { label: t });
			return;
		}
	}
	#queuePreviewAnnouncement(e, t) {
		if (this.#announcementPending && this.isUpdatePending) {
			this.#pendingPreviewAnnouncements.push(messageText(e, this.messages, t));
			return;
		}
		this.#announce(e, t);
	}
	#rebuildRegistry() {
		let e = new BlockRegistry();
		for (let t of this.#activeDefinitions()) try {
			e.register(t);
		} catch {}
		this.#registry = e;
	}
	#rebuildSession() {
		if (this.commandSession !== void 0) this.#session = this.commandSession, this.#sessionGeneration = this.configuration?.session.sessionGeneration ?? this.document?.revision ?? ``;
		else if (this.document === void 0) this.#session = void 0, this.#sessionGeneration = ``;
		else {
			let e = this.configuration?.session.sessionGeneration ?? this.document.revision, t = {
				document: this.document,
				sessionGeneration: e,
				sessionState: this.configuration?.session.sessionState ?? `editable`
			};
			this.configuration !== void 0 && (t.mode = resolveSessionMode(this.configuration.session));
			let n = this.configuration?.session.limits.maxHistoryEntries;
			n !== void 0 && (t.maximumHistoryEntries = n), this.#session = new StudioSession(t), this.#sessionGeneration = e;
		}
		this.#drag = void 0, this.selectedNodeId = void 0, this.#previewSurface?.selectNode(void 0), this.#syncDirty();
	}
	#permits(e) {
		let t = this.#session;
		return t !== void 0 && permittedCommandTypes(t.mode).has(e);
	}
	#releaseDragCapture(e) {
		try {
			e.capture?.releasePointerCapture(e.pointerId);
		} catch {}
	}
	#removeBinding(e, t) {
		let n = this.#session, r = this.document;
		if (n === void 0 || r === void 0 || !this.#permits(`studio.command/remove-binding`)) return;
		let i = {
			...this.#commandEnvelope(r, n),
			payload: {
				nodeId: e.id,
				port: t
			},
			type: `studio.command/remove-binding`
		};
		this.#runShellCommand(i) && this.#announce(`studio.shell/announce-binding-removed`, { port: t });
	}
	#resetInheritedProperty(e, t) {
		let n = this.#session, r = this.document;
		if (n === void 0 || r === void 0 || !this.#permits(`studio.command/reset-inherited-property`) || Object.keys(e.responsive?.[t] ?? {}).length === 0) return;
		let i = {
			...this.#commandEnvelope(r, n),
			payload: {
				nodeId: e.id,
				property: t
			},
			type: `studio.command/reset-inherited-property`
		};
		this.#runShellCommand(i) && this.#announce(`studio.shell/announce-inheritance-reset`, { property: t });
	}
	#renderBreadcrumb() {
		let e = this.document?.roots;
		if (e === void 0 || this.selectedNodeId === void 0) return A$10;
		let n = findAncestry(e, this.selectedNodeId);
		return n.length === 0 ? A$10 : b$12`
      <nav class="breadcrumb" aria-label=${this.#text(`studio.shell/breadcrumb-label`)}>
        <ol>
          ${n.map((e, r) => r === n.length - 1 ? b$12`<li>
                  <span class="breadcrumb-current" aria-current="true">
                    ${this.#nodeLabel(e)}
                  </span>
                </li>` : b$12`<li>
                  <button
                    type="button"
                    class="breadcrumb-entry"
                    data-node-id=${e.id}
                    @click=${() => {
			this.#selectNode(e.id);
		}}
                  >
                    ${this.#nodeLabel(e)}
                  </button>
                </li>`)}
        </ol>
      </nav>
    `;
	}
	#renderCanvasNode(e) {
		let n = this.#findDefinition(e), r = Object.entries(e.slots);
		return b$12`
      <li>
        <button
          type="button"
          class="canvas-chip"
          data-node-id=${e.id}
          aria-pressed=${this.selectedNodeId === e.id ? `true` : `false`}
          @click=${() => {
			this.#selectNode(e.id);
		}}
          @pointerdown=${(t) => {
			this.#onChipPointerDown(t, e);
		}}
        >
          ${n === void 0 ? e.type : G(n.label)}
        </button>
        ${r.map(([e, n]) => b$12`
            <section class="node-children" aria-label=${e}>
              <ul class="tree">
                ${n.map((e) => this.#renderCanvasNode(e))}
              </ul>
            </section>
          `)}
      </li>
    `;
	}
	#renderCommandPalette() {
		if (this.paletteOpen !== !0) return A$10;
		let e = this.#filteredPaletteEntries();
		return b$12`
      <section
        class="command-palette"
        aria-label=${this.#text(`studio.shell/command-palette-label`)}
      >
        <input
          type="text"
          aria-label=${this.#text(`studio.shell/command-palette-input-label`)}
          .value=${this.paletteFilter ?? ``}
          @input=${(e) => {
			let t = e.currentTarget;
			t instanceof HTMLInputElement && (this.paletteFilter = t.value);
		}}
          @keydown=${(e) => {
			this.#onPaletteInputKeydown(e);
		}}
        />
        <p class="hint">${this.#text(`studio.shell/command-palette-hint`)}</p>
        ${e.length === 0 ? b$12`<p class="command-empty">${this.#text(`studio.shell/command-palette-empty`)}</p>` : b$12`
                <ul
                  class="command-results"
                  aria-label=${this.#text(`studio.shell/command-palette-results-label`)}
                >
                  ${e.map((e) => b$12`
                      <li>
                        <button
                          type="button"
                          class="command-entry"
                          data-command-id=${e.id}
                          ?disabled=${e.disabled}
                          @click=${() => {
			this.#runPaletteEntry(e);
		}}
                          @keydown=${(e) => {
			this.#onPaletteEntryKeydown(e);
		}}
                        >
                          ${e.label}
                        </button>
                      </li>
                    `)}
                </ul>
              `}
      </section>
    `;
	}
	#renderDiagnostic(e) {
		let n = b$12`<span class="diagnostic-severity">
      ${this.#text(N[e.severity])}
    </span>`, r = V(e), i = e.location?.nodeId;
		return b$12`
      <li data-diagnostic-code=${e.code}>
        ${i === void 0 ? b$12`<span class="diagnostic-text">${n} ${r}</span>` : b$12`
                <button
                  type="button"
                  class="diagnostic-entry"
                  data-node-id=${i}
                  @click=${() => {
			this.#revealDiagnosticNode(i);
		}}
                >
                  ${n} ${r}
                </button>
              `}
      </li>
    `;
	}
	#renderDropIndicator() {
		let e = this.#drag;
		return e?.active === !0 ? b$12`
      <p class="drop-indicator">
        ${this.#text(`studio.shell/drag-drop-position`, {
			count: String(e.order.length),
			label: e.label,
			position: String(e.targetIndex + 1)
		})}
      </p>
    ` : A$10;
	}
	#renderInspector(e) {
		let n = this.#isReadOnly();
		return b$12`
      <dl>
        <div>
          <dt>${this.#text(`studio.shell/inspector-identifier`)}</dt>
          <dd>${e.id}</dd>
        </div>
        <div>
          <dt>${this.#text(`studio.shell/inspector-type`)}</dt>
          <dd>${e.type}@${e.version}</dd>
        </div>
      </dl>
      ${n ? b$12`<p class="hint inspector-read-only">
              ${this.#text(`studio.shell/inspector-read-only`)}
            </p>` : b$12`<p class="hint">${this.#text(`studio.shell/inspector-hint`)}</p>`}
      ${this.#renderInspectorRecipes(e, !this.#permits(`studio.command/batch`))}
      ${this.#renderInspectorDesign(e, !this.#permits(`studio.command/set-property`))}
      ${this.#renderInspectorProperties(e, !this.#permits(`studio.command/set-property`))}
      ${this.#renderInspectorAuthoringControls(e, n)}
      ${this.#renderInspectorResourceBindings(e, !this.#permits(`studio.command/set-binding`))}
      ${this.#renderInspectorBindings(e, !this.#permits(`studio.command/set-binding`))}
      ${this.#renderInspectorOverrides(e, !this.#permits(`studio.command/set-property`))}
      ${this.#renderInspectorLayout(e, !this.#permits(`studio.command/set-size-role`))}
    `;
	}
	#renderInspectorAuthoringControls(e, n) {
		let i = this.#inspectorAuthoringTargets(e, n);
		return i.length === 0 ? A$10 : b$12`
      <section class="inspector-section inspector-authoring" aria-label="Studio authoring controls">
        <h3>Authoring</h3>
        <ul class="inspector-rows">
          ${i.map((e) => b$12`
              <li
                class="inspector-authoring-row"
                data-authoring-kind=${e.kind}
                data-authoring-name=${e.name}
              >
                <span class="inspector-name">${e.label}</span>
                <div
                  class="inspector-authoring-control"
                  data-authoring-key=${e.key}
                  data-authoring-control=${e.control}
                ></div>
              </li>
            `)}
        </ul>
      </section>
    `;
	}
	#inspectorAuthoringTargets(e, t) {
		let n = this.#findDefinition(e);
		if (n === void 0) return [];
		let r = this.authoringControlRegistry ?? this.#defaultAuthoringControlRegistry, i = [];
		for (let a of n.propertyControls ?? []) {
			if (!r.supports(a.control)) continue;
			let n = a.control === STUDIO_AUTHORING_CONTROL_IDS.scopedCss ? L(a.control) : e.properties[a.property] ?? (I(a.control) ? L(a.control) : void 0);
			i.push({
				control: a.control,
				key: `${e.id}:property:${a.property}`,
				kind: `property`,
				label: a.label === void 0 ? a.help === void 0 ? a.property : G(a.help) : G(a.label),
				name: a.property,
				nodeId: e.id,
				readOnly: t,
				value: n
			});
		}
		for (let a of n.ports) {
			let n = a.authoring;
			if (n?.control === void 0 || !r.supports(n.control)) continue;
			let o = e.bindings[a.id], s = o?.source.kind === `static-value` ? o.source.value : I(n.control) ? L(n.control) : void 0;
			i.push({
				...o === void 0 ? {} : { binding: o },
				control: n.control,
				key: `${e.id}:port:${a.id}`,
				kind: `port`,
				label: G(a.label) || a.id,
				name: a.id,
				nodeId: e.id,
				...n.profile === void 0 ? {} : { profile: n.profile },
				readOnly: t || n.readOnly === !0 || o !== void 0 && o.source.kind !== `static-value`,
				value: s
			});
		}
		return i;
	}
	async #synchronizeAuthoringControls() {
		if (!this.isConnected || this.shadowRoot === null) {
			this.#destroyAuthoringControls();
			return;
		}
		let e = this.document === void 0 || this.selectedNodeId === void 0 ? void 0 : this.#currentInspectorNode(this.selectedNodeId), t = e === void 0 ? [] : this.#inspectorAuthoringTargets(e, this.#isReadOnly()), n = /* @__PURE__ */ new Map();
		for (let e of this.shadowRoot.querySelectorAll(`[data-authoring-key]`)) {
			let t = e.dataset.authoringKey;
			t !== void 0 && n.set(t, e);
		}
		let r = new Set(t.map((e) => e.key));
		for (let [e, t] of this.#authoringControls) (!r.has(e) || n.get(e) !== t.holder) && this.#destroyAuthoringControl(e, t);
		for (let e of [...this.#authoringDiagnostics.keys()]) r.has(e) || this.#authoringDiagnostics.delete(e);
		let i = this.authoringControlRegistry ?? this.#defaultAuthoringControlRegistry;
		for (let e of t) {
			let t = n.get(e.key);
			if (t === void 0) continue;
			let r = R(e), a = this.#authoringControls.get(e.key);
			if (a?.holder === t && a.signature === r) continue;
			let o = a !== void 0 && this.shadowRoot.activeElement !== null && a.holder.contains(this.shadowRoot.activeElement);
			a !== void 0 && this.#destroyAuthoringControl(e.key, a), t.replaceChildren();
			try {
				let n = {
					...e.binding === void 0 ? {} : { binding: e.binding },
					holder: t,
					onChange: (t) => {
						this.#acceptAuthoringControlChange(e, t);
					},
					...e.profile === void 0 ? {} : { profile: e.profile },
					readOnly: e.readOnly,
					usage: `studio.media/content`,
					value: structuredClone(e.value)
				}, a = await i.mount(e.control, n), s = this.#currentInspectorNode(e.nodeId), c = s ? this.#inspectorAuthoringTargets(s, this.#isReadOnly()).find((t) => t.key === e.key) : void 0;
				if (!t.isConnected || c === void 0 || R(c) !== r) {
					a.destroy();
					continue;
				}
				this.#authoringControls.set(e.key, {
					handle: a,
					holder: t,
					signature: r
				}), this.#setAuthoringDiagnostic(e.key, void 0), o && a.focus();
			} catch (n) {
				t.replaceChildren(document.createTextNode(n instanceof Error ? `Control unavailable: ${n.message}` : `Control unavailable.`)), this.#setAuthoringDiagnostic(e.key, {
					code: `studio.authoring/control-unavailable`,
					location: { nodeId: e.nodeId },
					message: {
						defaultMessage: `The ${e.label} authoring control is unavailable.`,
						key: `studio.authoring/control-unavailable`
					},
					parameters: {
						control: e.control,
						name: e.name
					},
					severity: `error`
				});
			}
		}
	}
	#acceptAuthoringControlChange(e, t) {
		let n = this.#currentInspectorNode(e.nodeId);
		if (n === void 0 || e.readOnly) return;
		if (!t.valid) {
			this.#setAuthoringDiagnostic(e.key, {
				code: `studio.authoring/invalid-control-value`,
				location: { nodeId: n.id },
				message: {
					defaultMessage: `${e.label} contains an invalid value.`,
					key: `studio.authoring/invalid-control-value`
				},
				parameters: {
					control: e.control,
					name: e.name
				},
				severity: `error`
			});
			return;
		}
		let r;
		if (e.kind === `property`) {
			let i = B(t.value);
			if (i === void 0) {
				this.#setAuthoringValueDiagnostic(e, n.id);
				return;
			}
			e.control === STUDIO_AUTHORING_CONTROL_IDS.scopedCss ? (this.dispatchEvent(new CustomEvent(`studio-scoped-style-change`, {
				bubbles: !0,
				composed: !0,
				detail: {
					nodeId: n.id,
					value: i
				}
			})), r = !0) : r = this.#setNodeProperty(n, e.name, i, void 0);
		} else r = this.#setAuthoringPortValue(n, e.name, t.value);
		if (!r) return;
		this.#setAuthoringDiagnostic(e.key, void 0);
		let i = this.#currentInspectorNode(n.id), a = i ? this.#inspectorAuthoringTargets(i, this.#isReadOnly()).find((t) => t.key === e.key) : void 0, o = this.#authoringControls.get(e.key);
		o !== void 0 && a !== void 0 && (o.signature = R(a));
	}
	#setAuthoringPortValue(e, t, n) {
		let r = e.bindings[t];
		if (r !== void 0 && r.source.kind !== `static-value`) return !1;
		if (n === void 0) return r === void 0 || (this.#removeBinding(e, t), this.#currentInspectorNode(e.id)?.bindings[t] === void 0);
		let i = B(n);
		if (i === void 0) return !1;
		let a = this.#session, o = this.document;
		if (a === void 0 || o === void 0 || !this.#permits(`studio.command/set-binding`)) return !1;
		let s = r === void 0 ? {
			onError: `error`,
			onNull: `empty`,
			source: {
				kind: `static-value`,
				value: i
			},
			transforms: []
		} : {
			...r,
			source: {
				kind: `static-value`,
				value: i
			}
		}, c = {
			...this.#commandEnvelope(o, a),
			payload: {
				binding: s,
				nodeId: e.id,
				port: t
			},
			type: `studio.command/set-binding`
		};
		return this.#runShellCommand(c) ? (this.#announce(`studio.shell/announce-binding-set`, { port: t }), !0) : !1;
	}
	#setAuthoringValueDiagnostic(e, t) {
		this.#setAuthoringDiagnostic(e.key, {
			code: `studio.authoring/non-canonical-control-value`,
			location: { nodeId: t },
			message: {
				defaultMessage: `${e.label} did not produce bounded canonical JSON.`,
				key: `studio.authoring/non-canonical-control-value`
			},
			parameters: {
				control: e.control,
				name: e.name
			},
			severity: `error`
		});
	}
	#setAuthoringDiagnostic(e, t) {
		let n = this.#authoringDiagnostics.get(e);
		if (t === void 0) {
			if (n === void 0) return;
			this.#authoringDiagnostics.delete(e), this.requestUpdate();
			return;
		}
		(n?.code !== t.code || n.message.defaultMessage !== t.message.defaultMessage) && (this.#authoringDiagnostics.set(e, t), this.requestUpdate());
	}
	#destroyAuthoringControl(e, t) {
		try {
			t.handle.destroy();
		} catch {}
		this.#authoringControls.delete(e);
	}
	#destroyAuthoringControls() {
		for (let [e, t] of this.#authoringControls) this.#destroyAuthoringControl(e, t);
		this.#authoringDiagnostics.clear();
	}
	#renderInspectorResourceBindings(e, n) {
		let i = this.#inspectorResourceBindingTargets(e, n);
		if (i.length === 0) return A$10;
		let a = this.resourceSearchService !== void 0 && this.#resourcePortAdvertised();
		return b$12`
      <section class="inspector-section inspector-resource-bindings" aria-label="Resource bindings">
        <h3>Resources</h3>
        <ul class="inspector-rows">
          ${i.map((e) => b$12`
              <li class="inspector-row" data-resource-port=${e.port}>
                <span class="inspector-name">${e.label}</span>
                ${a ? b$12`<div
                        class="inspector-resource-control"
                        data-resource-authoring-key=${e.key}
                      ></div>` : b$12`<p class="inspector-binding-status resource-browser-unavailable">
                        Resource browsing is unavailable in this
                        session.${e.binding === void 0 ? `` : ` The stored ${e.binding.source.kind} binding remains unchanged.`}
                      </p>`}
              </li>
            `)}
        </ul>
      </section>
    `;
	}
	#inspectorResourceBindingTargets(e, t) {
		let n = this.#findDefinition(e);
		return n === void 0 ? [] : n.ports.filter((e) => e.valueType === `resource`).map((n) => {
			let r = e.bindings[n.id];
			return {
				...r === void 0 ? {} : { binding: r },
				key: `resource:${e.id}:${n.id}`,
				label: G(n.label) || n.id,
				multiple: n.multiple,
				nodeId: e.id,
				port: n.id,
				readOnly: t || n.authoring?.readOnly === !0 || r !== void 0 && r.source.kind !== `resource-reference`
			};
		});
	}
	#synchronizeResourceBindingControls() {
		let e = this.resourceSearchService;
		if (!this.isConnected || this.shadowRoot === null || e === void 0 || !this.#resourcePortAdvertised()) {
			this.#destroyResourceBindingControls();
			return;
		}
		let t = this.document === void 0 || this.selectedNodeId === void 0 ? void 0 : this.#currentInspectorNode(this.selectedNodeId), n = t === void 0 ? [] : this.#inspectorResourceBindingTargets(t, this.#isReadOnly()), r = /* @__PURE__ */ new Map();
		for (let e of this.shadowRoot.querySelectorAll(`[data-resource-authoring-key]`)) {
			let t = e.dataset.resourceAuthoringKey;
			t !== void 0 && r.set(t, e);
		}
		let i = new Set(n.map((e) => e.key));
		for (let [e, t] of this.#resourceBindingControls) (!i.has(e) || r.get(e) !== t.holder) && this.#destroyResourceBindingControl(e, t);
		let a = !1;
		for (let e of [...this.#authoringDiagnostics.keys()]) e.startsWith(`resource:`) && !i.has(e) && (this.#authoringDiagnostics.delete(e), a = !0);
		a && this.requestUpdate();
		for (let t of n) {
			let n = r.get(t.key);
			if (n === void 0) continue;
			let i = z(t), a = this.#resourceBindingControls.get(t.key);
			if (a?.holder === n && a.signature === i) continue;
			let o = a !== void 0 && this.shadowRoot.activeElement !== null && a.holder.contains(this.shadowRoot.activeElement);
			a !== void 0 && this.#destroyResourceBindingControl(t.key, a), n.replaceChildren();
			try {
				let r = mountStudioResourceBindingControl({
					...t.binding === void 0 ? {} : { binding: t.binding },
					holder: n,
					label: t.label,
					multiple: t.multiple,
					onChange: (e) => this.#acceptResourceBindingChange(t, e),
					readOnly: t.readOnly,
					service: e
				}), a = this.#currentInspectorNode(t.nodeId), s = a ? this.#inspectorResourceBindingTargets(a, this.#isReadOnly()).find((e) => e.key === t.key) : void 0;
				if (!n.isConnected || s === void 0 || z(s) !== i) {
					r.destroy();
					continue;
				}
				this.#resourceBindingControls.set(t.key, {
					handle: r,
					holder: n,
					signature: i
				}), this.#setAuthoringDiagnostic(t.key, void 0), o && r.focus();
			} catch {
				n.replaceChildren(document.createTextNode(`Resource browser is unavailable.`)), this.#setAuthoringDiagnostic(t.key, {
					code: `studio.authoring/resource-control-unavailable`,
					location: { nodeId: t.nodeId },
					message: {
						defaultMessage: `The ${t.label} resource browser is unavailable.`,
						key: `studio.authoring/resource-control-unavailable`
					},
					parameters: { port: t.port },
					severity: `error`
				});
			}
		}
	}
	#acceptResourceBindingChange(e, t) {
		let n = this.#currentInspectorNode(e.nodeId);
		if (n === void 0 || e.readOnly) return;
		let r = this.#inspectorResourceBindingTargets(n, this.#isReadOnly()).find((t) => t.key === e.key);
		if (r === void 0 || r.readOnly) return;
		let i;
		if (t.source === void 0) {
			if (n.bindings[e.port] === void 0) return;
			this.#removeBinding(n, e.port), i = this.#currentInspectorNode(n.id)?.bindings[e.port] === void 0;
		} else i = this.#setResourceReferenceBinding(n, e.port, t.source);
		if (!i) return;
		this.#setAuthoringDiagnostic(e.key, void 0);
		let a = this.#currentInspectorNode(n.id), o = a ? this.#inspectorResourceBindingTargets(a, this.#isReadOnly()).find((t) => t.key === e.key) : void 0, s = this.#resourceBindingControls.get(e.key);
		s !== void 0 && o !== void 0 && (s.signature = z(o));
	}
	#setResourceReferenceBinding(e, t, n) {
		if (!isStudioResourceReference(n)) return !1;
		let r = e.bindings[t];
		if (r !== void 0 && r.source.kind !== `resource-reference`) return !1;
		let i = this.#session, a = this.document;
		if (i === void 0 || a === void 0 || !this.#permits(`studio.command/set-binding`)) return !1;
		let o = {
			onError: `error`,
			onNull: `empty`,
			source: structuredClone(n),
			transforms: []
		}, s = {
			...this.#commandEnvelope(a, i),
			payload: {
				binding: o,
				nodeId: e.id,
				port: t
			},
			type: `studio.command/set-binding`
		};
		return this.#runShellCommand(s) ? (this.#announce(`studio.shell/announce-binding-set`, { port: t }), !0) : !1;
	}
	#destroyResourceBindingControl(e, t) {
		try {
			t.handle.destroy();
		} catch {}
		this.#resourceBindingControls.delete(e);
	}
	#destroyResourceBindingControls() {
		for (let [e, t] of this.#resourceBindingControls) this.#destroyResourceBindingControl(e, t);
		let e = !1;
		for (let t of [...this.#authoringDiagnostics.keys()]) t.startsWith(`resource:`) && (this.#authoringDiagnostics.delete(t), e = !0);
		e && this.isConnected && this.requestUpdate();
	}
	#renderInspectorRecipes(e, n) {
		let i = this.theme;
		if (i === void 0) return A$10;
		let a = i.recipes.filter((t) => t.blockType === e.type);
		if (a.length === 0) return A$10;
		let s = e.properties[RECIPE_MARKER_PROPERTY];
		return b$12`
      <section
        class="inspector-section inspector-recipes"
        aria-label=${this.#text(`studio.shell/inspector-recipes-heading`)}
      >
        <h3>${this.#text(`studio.shell/inspector-recipes-heading`)}</h3>
        <label class="inspector-row">
          <span class="inspector-name">${this.#text(`studio.shell/inspector-recipe-label`)}</span>
          <select
            class="inspector-recipe-select"
            data-current-value=${typeof s == `string` ? s : ``}
            ?disabled=${n}
            @change=${(t) => {
			let n = t.currentTarget;
			n instanceof HTMLSelectElement && n.value !== `` && this.#applyRecipe(e, n.value);
		}}
          >
            <option value="" disabled .selected=${typeof s != `string`}>
              ${this.#text(`studio.shell/inspector-recipe-placeholder`)}
            </option>
            ${a.map((e) => b$12`
                <option value=${e.id} .selected=${s === e.id}>
                  ${G(e.label)}
                </option>
              `)}
          </select>
        </label>
      </section>
    `;
	}
	#renderInspectorDesign(e, n) {
		let i = this.#findDefinition(e), a = this.#activeDesignControls();
		if (i === void 0 || a === void 0) return A$10;
		let o = i.themeControls.map((e) => a.find((t) => t.id === e)).filter((e) => e !== void 0);
		if (o.length === 0) return A$10;
		let s = this.#propertyTargetViewport();
		return b$12`
      <section
        class="inspector-section inspector-design"
        aria-label=${this.#text(`studio.shell/inspector-design-heading`)}
      >
        <h3>${this.#text(`studio.shell/inspector-design-heading`)}</h3>
        <ul class="inspector-rows">
          ${o.map((r) => {
			let a = this.#designControlProperty(i, r), o = e.properties[a], c = s === void 0 ? void 0 : e.responsive?.[a]?.[s.id], l = c ?? o;
			return b$12`
              <li class="inspector-row" data-control=${r.id}>
                <label class="inspector-name" for=${`design-${e.id}-${r.id}`}>
                  ${G(r.label)}
                </label>
                <span class="inspector-provenance">
                  ${s === void 0 ? this.#text(`studio.shell/inspector-provenance-base`) : c === void 0 ? this.#text(`studio.shell/inspector-provenance-inherited`, { value: JSON.stringify(o) }) : this.#text(`studio.shell/inspector-provenance-overridden`, {
				value: JSON.stringify(c),
				viewport: G(s.label)
			})}
                </span>
                <select
                  id=${`design-${e.id}-${r.id}`}
                  class="inspector-design-select"
                  data-current-value=${typeof l == `string` ? l : ``}
                  data-property=${a}
                  ?disabled=${n}
                  @change=${(t) => {
				let n = t.currentTarget;
				n instanceof HTMLSelectElement && n.value !== `` && this.#setNodeProperty(e, a, n.value, s);
			}}
                >
                  <option value="" disabled .selected=${typeof l != `string`}>
                    ${this.#text(`studio.shell/inspector-design-placeholder`)}
                  </option>
                  ${r.choices.map((e) => b$12`
                      <option value=${e.id} .selected=${l === e.id}>
                        ${G(e.label)}
                      </option>
                    `)}
                </select>
                <button
                  type="button"
                  class="inspector-design-unset"
                  data-property=${a}
                  ?disabled=${n || (s === void 0 ? o : c) === void 0}
                  @click=${() => {
				this.#unsetNodeProperty(e, a, s);
			}}
                >
                  ${this.#text(`studio.shell/inspector-design-unset`)}
                </button>
              </li>
            `;
		})}
        </ul>
      </section>
    `;
	}
	#renderInspectorBindings(e, n) {
		let r = this.#bindingProjection?.nodes.find((t) => t.nodeId === e.id);
		if (r === void 0) return this.#modelPortAdvertised() ? b$12`
          <section
            class="inspector-section inspector-bindings"
            aria-label=${this.#text(`studio.shell/inspector-bindings-heading`)}
          >
            <h3>${this.#text(`studio.shell/inspector-bindings-heading`)}</h3>
            <p class="inspector-empty inspector-binding-model-unavailable">
              ${this.#text(`studio.shell/inspector-binding-model-unavailable`)}
            </p>
          </section>
        ` : this.#renderLegacyInspectorBindings(e, n);
		let i = !this.#bindingProjection?.diagnostics.some((e) => e.code.startsWith(`studio.binding/model-`)), a = new Set((this.#findDefinition(e)?.ports ?? []).filter((e) => e.valueType === `resource`).map((e) => e.id)), o = r.ports.filter((e) => !a.has(e.port));
		return b$12`
      <section
        class="inspector-section inspector-bindings"
        aria-label=${this.#text(`studio.shell/inspector-bindings-heading`)}
      >
        <h3>${this.#text(`studio.shell/inspector-bindings-heading`)}</h3>
        <p class="hint inspector-binding-model">
          ${this.#text(`studio.shell/inspector-binding-model`, { model: `${this.contentModel?.id ?? ``}@${this.contentModel?.version ?? ``}#${this.contentModel?.revision ?? ``}` })}
        </p>
        ${i ? o.length === 0 ? b$12`<p class="inspector-empty">
                  ${this.#text(`studio.shell/inspector-bindings-empty`)}
                </p>` : b$12`<ul class="inspector-rows">
                  ${o.map((t) => this.#renderProjectedBindingPort(e, t, n))}
                </ul>` : b$12`<p class="inspector-empty inspector-binding-model-mismatch">
                ${this.#text(`studio.shell/inspector-binding-model-mismatch`)}
              </p>`}
      </section>
    `;
	}
	#renderProjectedBindingPort(e, n, i) {
		let a = this.#findDefinition(e)?.ports.find((e) => e.id === n.port), o = n.boundFieldPath, s = o === void 0 ? `` : JSON.stringify(o), c = n.candidates.find((e) => JSON.stringify(e.fieldPath) === s), l = a === void 0 ? n.port : G(a.label);
		return b$12`
      <li class="inspector-row inspector-binding-model" data-port=${n.port}>
        <label class="inspector-name" for=${`binding-${e.id}-${n.port}`}>
          ${l}
          ${n.required === !0 ? this.#text(`studio.shell/inspector-binding-required`) : A$10}
        </label>
        ${n.valueType === void 0 ? A$10 : b$12`<span class="inspector-binding-status">
                ${this.#text(`studio.shell/inspector-binding-accepts`, {
			cardinality: n.multiple === !0 ? `many` : `one`,
			"value-type": n.valueType
		})}
              </span>`}
        ${n.status === `non-field-source` ? b$12`<span class="inspector-binding-status">
                ${this.#text(`studio.shell/inspector-binding-non-field-source`)}
              </span>` : n.status === `invalid` ? b$12`<span class="inspector-binding-status">
                  ${this.#text(`studio.shell/inspector-binding-invalid`)}
                </span>` : A$10}
        <select
          id=${`binding-${e.id}-${n.port}`}
          class="inspector-binding-field"
          data-port=${n.port}
          data-current-value=${s}
          data-authoring-control=${c?.control ?? A$10}
          ?disabled=${i || n.valueType === void 0 || n.candidates.length === 0}
          @change=${(t) => {
			let r = t.currentTarget;
			if (!(r instanceof HTMLSelectElement) || r.value === ``) return;
			let i = n.candidates.find((e) => JSON.stringify(e.fieldPath) === r.value);
			i !== void 0 && this.#setFieldBinding(e, n.port, i);
		}}
        >
          <option value="" .selected=${c === void 0}>
            ${n.candidates.length === 0 ? this.#text(`studio.shell/inspector-binding-no-compatible-fields`) : this.#text(`studio.shell/inspector-binding-field-placeholder`)}
          </option>
          ${n.candidates.map((e) => b$12`
              <option
                value=${JSON.stringify(e.fieldPath)}
                data-authoring-control=${e.control ?? A$10}
                .selected=${JSON.stringify(e.fieldPath) === s}
              >
                ${G(e.label)} (${e.fieldPath.join(`.`)})
              </option>
            `)}
        </select>
        ${o === void 0 ? A$10 : b$12`<code class="inspector-binding-path">${o.join(`.`)}</code>`}
        ${c === void 0 ? A$10 : this.#renderDeclaredFieldControl(c)}
        ${n.binding === void 0 ? A$10 : b$12`<button
                type="button"
                class="inspector-binding-remove"
                data-port=${n.port}
                aria-label=${this.#text(`studio.shell/inspector-remove-binding-label`, { port: n.port })}
                ?disabled=${i}
                @click=${() => {
			this.#removeBinding(e, n.port);
		}}
              >
                ${this.#text(`studio.shell/inspector-remove-binding`)}
              </button>`}
      </li>
    `;
	}
	#renderDeclaredFieldControl(e) {
		let n = this.#fieldAtPath(e.fieldPath), i = e.control;
		if (n === void 0 || i === void 0) return b$12`<div class="inspector-binding-control">
        <span class="inspector-binding-status">
          ${this.#text(`studio.shell/inspector-binding-control-undeclared`)}
        </span>
      </div>`;
		let a = G(n.label), o = this.#text(`studio.shell/inspector-binding-control-label`, {
			control: i,
			field: a
		}), s;
		switch (i) {
			case `studio.control/date`:
				s = b$12`<input type="date" aria-label=${o} disabled />`;
				break;
			case `studio.control/date-time`:
				s = b$12`<input type="datetime-local" aria-label=${o} disabled />`;
				break;
			case `studio.control/number`:
				s = b$12`<input type="number" aria-label=${o} disabled />`;
				break;
			case `studio.control/select`:
				s = b$12`<select aria-label=${o} disabled>
          <option>${this.#text(`studio.shell/inspector-binding-control-preview`)}</option>
          ${(n.enumValues ?? []).map((e) => b$12`<option value=${e.value}>${G(e.label)}</option>`)}
        </select>`;
				break;
			case `studio.control/switch`:
				s = b$12`<input type="checkbox" aria-label=${o} disabled />`;
				break;
			case `studio.control/multi-line-text`:
				s = b$12`<textarea aria-label=${o} disabled></textarea>`;
				break;
			case `studio.control/single-line-text`:
				s = b$12`<input
          type="text"
          aria-label=${o}
          placeholder=${n.authoring?.placeholder === void 0 ? A$10 : G(n.authoring.placeholder)}
          disabled
        />`;
				break;
			default: s = b$12`<span class="inspector-binding-status">
          ${this.#text(`studio.shell/inspector-binding-control-unavailable`, { control: i })}
        </span>`;
		}
		return b$12`<div
      class="inspector-binding-control"
      data-authoring-control=${i}
      aria-label=${o}
    >
      <span class="inspector-binding-status">${i}</span>
      ${s}
    </div>`;
	}
	#renderLegacyInspectorBindings(e, n) {
		let i = this.#findDefinition(e)?.ports ?? [], a = new Set(i.filter((e) => e.valueType === `resource`).map((e) => e.id)), o = Object.entries(e.bindings).filter(([e]) => !a.has(e)), s = i.length === 0 || i.some((e) => e.valueType !== `resource`);
		return b$12`
      <section
        class="inspector-section inspector-bindings"
        aria-label=${this.#text(`studio.shell/inspector-bindings-heading`)}
      >
        <h3>${this.#text(`studio.shell/inspector-bindings-heading`)}</h3>
        ${o.length === 0 ? b$12`<p class="inspector-empty">
                ${this.#text(`studio.shell/inspector-bindings-empty`)}
              </p>` : b$12`
                <ul class="inspector-rows">
                  ${o.map(([r, i]) => b$12`
                      <li class="inspector-row">
                        <span class="inspector-name">${r}</span>
                        <code class="inspector-binding-value">${JSON.stringify(i)}</code>
                        <button
                          type="button"
                          class="inspector-binding-remove"
                          data-port=${r}
                          aria-label=${this.#text(`studio.shell/inspector-remove-binding-label`, { port: r })}
                          ?disabled=${n}
                          @click=${() => {
			this.#removeBinding(e, r);
		}}
                        >
                          ${this.#text(`studio.shell/inspector-remove-binding`)}
                        </button>
                      </li>
                    `)}
                </ul>
              `}
        ${s ? b$12`<div class="inspector-row inspector-set-binding-form">
                <input
                  type="text"
                  class="inspector-binding-port"
                  aria-label=${this.#text(`studio.shell/inspector-binding-port-label`)}
                  ?disabled=${n}
                />
                <input
                  type="text"
                  class="inspector-binding-value-input"
                  aria-label=${this.#text(`studio.shell/inspector-binding-value-label`)}
                  ?disabled=${n}
                />
                <button
                  type="button"
                  class="inspector-binding-set"
                  ?disabled=${n}
                  @click=${() => {
			this.#setBinding(e);
		}}
                >
                  ${this.#text(`studio.shell/inspector-set-binding`)}
                </button>
              </div>` : A$10}
      </section>
    `;
	}
	#renderInspectorLayout(e, n) {
		let i = this.#sizeRoleVocabulary();
		return b$12`
      <section
        class="inspector-section inspector-layout"
        aria-label=${this.#text(`studio.shell/inspector-layout-heading`)}
      >
        <h3>${this.#text(`studio.shell/inspector-layout-heading`)}</h3>
        ${i?.length === 0 ? b$12`<p class="inspector-empty layout-no-roles">
                ${this.#text(`studio.shell/inspector-layout-no-roles`)}
              </p>` : b$12`
                ${i === void 0 ? b$12`<p class="hint layout-fallback-hint">
                        ${this.#text(`studio.shell/inspector-layout-fallback-hint`)}
                      </p>` : A$10}
                <ul class="inspector-rows">
                  ${A.map((t) => this.#renderLayoutAxis(e, t, i, n))}
                </ul>
              `}
      </section>
    `;
	}
	#renderInspectorOverrides(e, n) {
		let i = this.activeViewport;
		if (i === void 0) return A$10;
		let a = G(i.label), o = [];
		for (let [t, n] of Object.entries(e.properties)) o.push({
			base: n,
			override: e.responsive?.[t]?.[i.id],
			property: t
		});
		for (let [t, n] of Object.entries(e.responsive ?? {})) {
			if (Object.hasOwn(e.properties, t)) continue;
			let r = n[i.id];
			r !== void 0 && o.push({
				base: void 0,
				override: r,
				property: t
			});
		}
		return b$12`
      <section
        class="inspector-section inspector-overrides"
        aria-label=${this.#text(`studio.shell/inspector-overrides-heading`, { viewport: a })}
      >
        <h3>
          ${this.#text(`studio.shell/inspector-overrides-heading`, { viewport: a })}
        </h3>
        ${o.length === 0 ? b$12`<p class="inspector-empty">
                ${this.#text(`studio.shell/inspector-overrides-empty`, { viewport: a })}
              </p>` : b$12`
                <ul class="inspector-rows">
                  ${o.map(({ base: r, override: o, property: s }) => o === void 0 ? b$12`
                          <li class="inspector-row inspector-inherited" data-property=${s}>
                            <span class="inspector-name">${s}</span>
                            <span class="inspector-provenance">
                              ${this.#text(`studio.shell/inspector-provenance-inherited`, { value: JSON.stringify(r) })}
                            </span>
                            <button
                              type="button"
                              class="inspector-inheritance-reset"
                              data-property=${s}
                              ?disabled=${n || !this.#permits(`studio.command/reset-inherited-property`) || Object.keys(e.responsive?.[s] ?? {}).length === 0}
                              @click=${() => {
			this.#resetInheritedProperty(e, s);
		}}
                            >
                              ${this.#text(`studio.shell/inspector-reset-inheritance`)}
                            </button>
                          </li>
                        ` : b$12`
                          <li class="inspector-row">
                            <span class="inspector-name">${s}</span>
                            <span class="inspector-provenance">
                              ${this.#text(`studio.shell/inspector-provenance-overridden`, {
			value: JSON.stringify(o),
			viewport: a
		})}
                            </span>
                            <input
                              type="text"
                              class="inspector-override-input"
                              data-property=${s}
                              aria-label=${this.#text(`studio.shell/inspector-override-value-label`, {
			property: s,
			viewport: a
		})}
                              .value=${JSON.stringify(o)}
                              ?disabled=${n}
                              @keydown=${(t) => {
			this.#onInspectorValueKeydown(t, e, s, i);
		}}
                            />
                            <button
                              type="button"
                              class="inspector-override-remove"
                              data-property=${s}
                              aria-label=${this.#text(`studio.shell/inspector-remove-override-label`, {
			property: s,
			viewport: a
		})}
                              ?disabled=${n}
                              @click=${() => {
			this.#unsetNodeProperty(e, s, i);
		}}
                            >
                              ${this.#text(`studio.shell/inspector-remove-override`)}
                            </button>
                            <button
                              type="button"
                              class="inspector-inheritance-reset"
                              data-property=${s}
                              ?disabled=${n || !this.#permits(`studio.command/reset-inherited-property`)}
                              @click=${() => {
			this.#resetInheritedProperty(e, s);
		}}
                            >
                              ${this.#text(`studio.shell/inspector-reset-inheritance`)}
                            </button>
                          </li>
                        `)}
                </ul>
              `}
        <div class="inspector-row inspector-add-override-form">
          <input
            type="text"
            class="inspector-add-override-name"
            aria-label=${this.#text(`studio.shell/inspector-add-override-name-label`)}
            ?disabled=${n}
          />
          <input
            type="text"
            class="inspector-add-override-value"
            aria-label=${this.#text(`studio.shell/inspector-add-override-value-label`)}
            ?disabled=${n}
          />
          <button
            type="button"
            class="inspector-add-override-submit"
            ?disabled=${n}
            @click=${() => {
			this.#addOverride(e, i);
		}}
          >
            ${this.#text(`studio.shell/inspector-add-override`)}
          </button>
        </div>
      </section>
    `;
	}
	#renderInspectorProperties(e, n) {
		let r = this.authoringControlRegistry ?? this.#defaultAuthoringControlRegistry, i = new Set((this.#findDefinition(e)?.propertyControls ?? []).filter((e) => r.supports(e.control)).map((e) => e.property)), a = Object.entries(e.properties).filter(([e]) => !i.has(e));
		return b$12`
      <section
        class="inspector-section inspector-properties"
        aria-label=${this.#text(`studio.shell/inspector-properties`)}
      >
        <h3>${this.#text(`studio.shell/inspector-properties`)}</h3>
        ${a.length === 0 ? b$12`<p class="inspector-empty">
                ${this.#text(`studio.shell/inspector-properties-empty`)}
              </p>` : b$12`
                <ul class="inspector-rows">
                  ${a.map(([r, i]) => b$12`
                      <li class="inspector-row">
                        <span class="inspector-name">${r}</span>
                        <span class="inspector-provenance">
                          ${this.#text(`studio.shell/inspector-provenance-base`)}
                        </span>
                        <input
                          type="text"
                          class="inspector-property-input"
                          data-property=${r}
                          aria-label=${this.#text(`studio.shell/inspector-property-value-label`, { property: r })}
                          .value=${JSON.stringify(i)}
                          ?disabled=${n}
                          @keydown=${(t) => {
			this.#onInspectorValueKeydown(t, e, r, void 0);
		}}
                        />
                        <button
                          type="button"
                          class="inspector-property-unset"
                          data-property=${r}
                          aria-label=${this.#text(`studio.shell/inspector-unset-label`, { property: r })}
                          ?disabled=${n}
                          @click=${() => {
			this.#unsetNodeProperty(e, r, void 0);
		}}
                        >
                          ${this.#text(`studio.shell/inspector-unset`)}
                        </button>
                      </li>
                    `)}
                </ul>
              `}
        <div class="inspector-row inspector-add-property-form">
          <input
            type="text"
            class="inspector-add-property-name"
            aria-label=${this.#text(`studio.shell/inspector-add-property-name-label`)}
            ?disabled=${n}
          />
          <input
            type="text"
            class="inspector-add-property-value"
            aria-label=${this.#text(`studio.shell/inspector-add-property-value-label`)}
            ?disabled=${n}
          />
          <button
            type="button"
            class="inspector-add-property-submit"
            ?disabled=${n}
            @click=${() => {
			this.#addProperty(e);
		}}
          >
            ${this.#text(`studio.shell/inspector-add-property`)}
          </button>
        </div>
      </section>
    `;
	}
	#renderLayoutAxis(e, n, i, a) {
		let o = this.#axisText(n), s = e.sizeRoles?.[n], c = this.#sizeRoleTargetViewport(), l = this.#assignedSizeRole(e, n, c), u = c === void 0 ? void 0 : G(c.label), d = u === void 0 ? this.#text(`studio.shell/inspector-layout-role-label-base`, { axis: o }) : this.#text(`studio.shell/inspector-layout-role-label-viewport`, {
			axis: o,
			viewport: u
		}), f = u === void 0 ? this.#text(`studio.shell/inspector-layout-unset-label-base`, { axis: o }) : this.#text(`studio.shell/inspector-layout-unset-label-viewport`, {
			axis: o,
			viewport: u
		});
		return b$12`
      <li class="inspector-row layout-axis" data-axis=${n}>
        <span class="inspector-name">${o}</span>
        <span class="inspector-provenance layout-base-state" data-axis=${n}>
          ${s === void 0 ? this.#text(`studio.shell/inspector-layout-base-none`) : this.#text(`studio.shell/inspector-layout-base-role`, { role: s })}
        </span>
        ${u === void 0 ? A$10 : b$12`
                <span class="inspector-provenance layout-viewport-state" data-axis=${n}>
                  ${l === void 0 ? s === void 0 ? this.#text(`studio.shell/inspector-provenance-inherited-none`) : this.#text(`studio.shell/inspector-provenance-inherited`, { value: s }) : this.#text(`studio.shell/inspector-provenance-overridden`, {
			value: l,
			viewport: u
		})}
                </span>
              `}
        ${i === void 0 ? b$12`
                <input
                  type="text"
                  class="layout-role-input"
                  data-axis=${n}
                  aria-label=${d}
                  .value=${l ?? ``}
                  ?disabled=${a}
                  @keydown=${(t) => {
			this.#onLayoutRoleInputKeydown(t, e, n);
		}}
                />
              ` : b$12`
                <select
                  class="layout-role-select"
                  data-axis=${n}
                  data-role=${l ?? ``}
                  aria-label=${d}
                  ?disabled=${a}
                  @change=${(t) => {
			this.#onLayoutRoleChange(t, e, n);
		}}
                >
                  <option value="" disabled ?selected=${l === void 0}>
                    ${this.#text(`studio.shell/inspector-layout-role-placeholder`)}
                  </option>
                  ${i.map((e) => b$12`
                      <option value=${e.id} ?selected=${l === e.id}>
                        ${G(e.label)}
                      </option>
                    `)}
                </select>
              `}
        <button
          type="button"
          class="layout-role-unset"
          data-axis=${n}
          aria-label=${f}
          ?disabled=${a || l === void 0}
          @click=${() => {
			this.#unsetSizeRole(e, n);
		}}
        >
          ${this.#text(`studio.shell/inspector-layout-unset`)}
        </button>
      </li>
    `;
	}
	#renderOutlineControls(e) {
		let n = this.document === void 0 ? void 0 : findOutlineLocation(this.document.roots, e.id), r = !this.#canMutateNode(e, `studio.command/reorder-children`), i = n === void 0 || n.index === 0, a = n === void 0 || n.index === n.collection.length - 1, o = this.#moveDestinations(e);
		return b$12`
      <div
        class="outline-controls"
        role="group"
        aria-label=${this.#text(`studio.shell/block-actions`)}
      >
        <button
          type="button"
          class="outline-move-up"
          ?disabled=${r || i}
          @click=${() => {
			this.#moveNode(e, -1);
		}}
        >
          ${this.#text(`studio.shell/move-up`)}
        </button>
        <button
          type="button"
          class="outline-move-down"
          ?disabled=${r || a}
          @click=${() => {
			this.#moveNode(e, 1);
		}}
        >
          ${this.#text(`studio.shell/move-down`)}
        </button>
        <button
          type="button"
          class="outline-duplicate"
          ?disabled=${!this.#canMutateNode(e, `studio.command/duplicate-node`)}
          @click=${() => {
			this.#duplicateNode(e);
		}}
        >
          ${this.#text(`studio.shell/duplicate`)}
        </button>
        <button
          type="button"
          class="outline-delete"
          ?disabled=${!this.#canMutateNode(e, `studio.command/remove-node`)}
          @click=${() => {
			this.#deleteNode(e);
		}}
        >
          ${this.#text(`studio.shell/delete`)}
        </button>
        <label class="outline-move-destination-label">
          <span>${this.#text(`studio.shell/move-destination-label`)}</span>
          <select
            class="outline-move-destination"
            ?disabled=${o.length === 0}
            @change=${(t) => {
			let n = t.currentTarget;
			if (!(n instanceof HTMLSelectElement)) return;
			let r = o.find((e) => e.id === n.value);
			r !== void 0 && this.#moveNodeToOption(e, r), n.value = ``;
		}}
          >
            <option value="" selected disabled>
              ${this.#text(`studio.shell/move-destination-placeholder`)}
            </option>
            ${o.map((e) => b$12`
                <option value=${e.id}>${e.label}</option>
              `)}
          </select>
        </label>
      </div>
    `;
	}
	#renderOutlineNode(e) {
		let n = this.#findDefinition(e), i = this.selectedNodeId === e.id, a = Object.entries(e.slots);
		return b$12`
      <li>
        <button
          type="button"
          class="outline-entry"
          data-node-id=${e.id}
          aria-pressed=${i ? `true` : `false`}
          @click=${() => {
			this.#selectNode(e.id);
		}}
          @keydown=${(t) => {
			this.#onOutlineKeydown(t, e);
		}}
        >
          ${n === void 0 ? b$12`${e.type}
                  <span class="unresolved">${this.#text(`studio.shell/unresolved-block`)}</span>` : G(n.label)}
        </button>
        ${i ? this.#renderOutlineControls(e) : A$10}
        ${a.map(([n, i]) => {
			if (i.length === 0) return A$10;
			let a = this.#slotLabel(e, n);
			return b$12`
            <section class="node-children" aria-label=${a}>
              <span class="outline-slot-label">${a}</span>
              <ul class="tree">
                ${i.map((e) => this.#renderOutlineNode(e))}
              </ul>
            </section>
          `;
		})}
      </li>
    `;
	}
	#renderPreview() {
		let e = this.#previewCapabilityAvailable() && this.previewBinding !== void 0, n = e ? this.previewState ?? `connecting` : `unavailable`, i = n === `closed` ? `studio.shell/preview-closed` : n === `connecting` ? `studio.shell/preview-connecting` : n === `current` ? `studio.shell/preview-current` : n === `rendering` ? `studio.shell/preview-rendering` : n === `stale` ? `studio.shell/preview-stale` : `studio.shell/preview-unavailable`;
		return b$12`
      <section
        class="preview-region"
        data-preview-state=${n}
        aria-label=${this.#text(`studio.shell/preview-label`)}
      >
        <h2>${this.#text(`studio.shell/preview-heading`)}</h2>
        <p class="preview-status">${this.#text(i)}</p>
        ${e && n === `current` && this.canvasGeometry !== void 0 ? b$12`
                <button
                  type="button"
                  class="canvas-edit-toggle"
                  aria-pressed=${this.canvasDirectManipulation === !0 ? `true` : `false`}
                  @click=${() => {
			this.canvasDirectManipulation = this.canvasDirectManipulation !== !0, !this.canvasDirectManipulation && this.#previewDrag !== void 0 && this.#cancelDrag(), this.#announce(`studio.shell/announce-canvas-mode`, { state: this.#text(this.canvasDirectManipulation ? `studio.shell/canvas-mode-editing` : `studio.shell/canvas-mode-interacting`) });
		}}
                >
                  ${this.#text(`studio.shell/canvas-edit-toggle`)}
                </button>
              ` : A$10}
        ${e && n !== `closed` ? b$12`
                <div class="preview-stage" tabindex="0">
                  <slot
                    class="preview-surface-slot"
                    name="preview"
                    @slotchange=${() => {
			queueMicrotask(() => {
				this.refreshPreviewGeometry();
			});
		}}
                  ></slot>
                  ${this.#renderPreviewCanvasOverlay()}
                </div>
                ${this.#renderPreviewCanvasStatus()}
              ` : A$10}
      </section>
    `;
	}
	#renderPreviewCanvasOverlay() {
		let e = this.canvasGeometry;
		if (e === void 0 || e.viewport.width <= 0 || e.viewport.height <= 0) return A$10;
		let n = this.#previewDrag?.active === !0 ? this.#previewDrag.target?.indicator : void 0, a = Object.entries(e.measurements).sort(([e], [t]) => (e === this.selectedNodeId) - +(t === this.selectedNodeId));
		return b$12`
      <svg
        class="preview-canvas-overlay"
        data-interactive=${this.canvasDirectManipulation === !0 ? `true` : `false`}
        width=${String(e.viewport.width)}
        height=${String(e.viewport.height)}
        viewBox=${`0 0 ${e.viewport.width} ${e.viewport.height}`}
        preserveAspectRatio="xMinYMin meet"
        aria-hidden="true"
        @pointermove=${(e) => {
			this.#onPreviewCanvasPointerMove(e);
		}}
        @pointerup=${(e) => {
			this.#onPreviewCanvasPointerUp(e);
		}}
        @pointercancel=${(e) => {
			this.#onPreviewCanvasPointerCancel(e);
		}}
      >
        ${a.flatMap(([e, t]) => t.map((t, n) => w$12`
              <rect
                class="preview-canvas-region"
                data-node-id=${e}
                data-rect-index=${String(n)}
                data-hovered=${this.#hoveredPreviewNodeId === e ? `true` : `false`}
                data-selected=${this.selectedNodeId === e ? `true` : `false`}
                x=${String(t.x)}
                y=${String(t.y)}
                width=${String(t.width)}
                height=${String(t.height)}
                @pointerenter=${() => {
			this.#hoveredPreviewNodeId = e, this.requestUpdate();
		}}
                @pointerleave=${() => {
			this.#hoveredPreviewNodeId === e && (this.#hoveredPreviewNodeId = void 0, this.requestUpdate());
		}}
                @pointerdown=${(t) => {
			this.#onPreviewCanvasPointerDown(t, e);
		}}
              ></rect>
            `))}
        ${n === void 0 ? A$10 : w$12`
                <rect
                  class="preview-canvas-drop-indicator"
                  x=${String(n.x)}
                  y=${String(n.y)}
                  width=${String(n.width)}
                  height=${String(n.height)}
                ></rect>
              `}
      </svg>
    `;
	}
	#renderPreviewCanvasStatus() {
		let e = this.#previewDrag;
		return e?.active !== !0 || e.target === void 0 ? A$10 : b$12`
      <p class="preview-canvas-status">
        ${this.#text(`studio.shell/visual-drop-target`, {
			destination: e.target.label,
			label: e.label
		})}
      </p>
    `;
	}
	#onPreviewCanvasPointerDown(e, t) {
		if (this.canvasDirectManipulation !== !0 || e.button !== 0 || this.#previewDrag !== void 0) return;
		let n = this.document, r = n === void 0 ? void 0 : findOutlineLocation(n.roots, t)?.node;
		if (r === void 0 || (this.#selectNode(t), this.#moveDestinations(r).length === 0)) return;
		let i = {
			active: !1,
			label: this.#nodeLabel(r),
			nodeId: t,
			originX: e.clientX,
			originY: e.clientY,
			pointerId: e.pointerId
		}, a = e.currentTarget;
		if (a instanceof Element) {
			i.capture = a;
			try {
				a.setPointerCapture(e.pointerId);
			} catch {}
		}
		this.#previewDrag = i;
	}
	#onPreviewCanvasPointerMove(e) {
		let t = this.#previewDrag;
		if (t?.pointerId !== e.pointerId || !t.active && Math.hypot(e.clientX - t.originX, e.clientY - t.originY) < 4) return;
		t.active = !0;
		let n = this.#previewCanvasPoint(e), r = this.document, i = r === void 0 ? void 0 : findOutlineLocation(r.roots, t.nodeId)?.node;
		if (n !== void 0 && i !== void 0) {
			let e = this.#resolvePreviewDropTarget(i, n.x, n.y);
			e === void 0 ? delete t.target : t.target = e;
		}
		this.requestUpdate();
	}
	#onPreviewCanvasPointerUp(e) {
		let t = this.#previewDrag;
		if (t?.pointerId !== e.pointerId) return;
		if (this.#previewDrag = void 0, this.#releasePreviewDragCapture(t), this.requestUpdate(), !t.active) {
			this.#selectNode(t.nodeId);
			return;
		}
		let n = this.document, r = n === void 0 ? void 0 : findOutlineLocation(n.roots, t.nodeId)?.node;
		if (r === void 0 || t.target === void 0) {
			this.#announce(`studio.shell/announce-drag-cancelled`, { label: t.label });
			return;
		}
		this.#moveNodeToOption(r, t.target);
	}
	#onPreviewCanvasPointerCancel(e) {
		this.#previewDrag?.pointerId === e.pointerId && this.#cancelDrag();
	}
	#releasePreviewDragCapture(e) {
		try {
			e.capture?.hasPointerCapture(e.pointerId) === !0 && e.capture.releasePointerCapture(e.pointerId);
		} catch {}
	}
	#previewCanvasPoint(e) {
		let t = this.canvasGeometry, n = e.currentTarget;
		if (t === void 0 || !(n instanceof SVGElement)) return;
		let r = n instanceof SVGSVGElement ? n : n.ownerSVGElement;
		if (r === null) return;
		let i = r.getBoundingClientRect();
		return i.width <= 0 || i.height <= 0 ? {
			x: e.clientX,
			y: e.clientY
		} : {
			x: (e.clientX - i.left) / i.width * t.viewport.width,
			y: (e.clientY - i.top) / i.height * t.viewport.height
		};
	}
	#resolvePreviewDropTarget(e, t, n) {
		let r = this.#previewDropTargets(e), i, a = 1 / 0;
		for (let e of r) {
			let r = Math.hypot(e.distanceX - t, e.distanceY - n);
			(r < a || r === a && (i === void 0 || e.specificity > i.specificity)) && (i = e, a = r);
		}
		return i;
	}
	#previewDropTargets(e) {
		let t = this.canvasGeometry, n = this.document;
		if (t === void 0 || n === void 0) return [];
		let r = this.#moveDestinations(e), i = this.#moveCollections(e), a = [];
		for (let o of r) {
			let r = i.find((e) => e.parentNodeId === o.destination.parentNodeId && e.slot === o.destination.slot);
			if (r === void 0) continue;
			let s = r.collection.filter((t) => t.id !== e.id), c = s.map((e) => H(t.measurements[e.id] ?? []));
			if (c.every((e) => e !== void 0) && c.length > 0) {
				let e = U(c, o.destination.position);
				a.push({
					...o,
					distanceX: e.x + e.width / 2,
					distanceY: e.y + e.height / 2,
					indicator: e,
					specificity: r.specificity
				});
				continue;
			}
			if (s.length !== 0 || r.parentNodeId === void 0) continue;
			let l = H(t.measurements[r.parentNodeId] ?? []);
			if (l === void 0) continue;
			let u = findOutlineLocation(n.roots, r.parentNodeId)?.node, d = this.#findDefinition(u ?? e)?.slots.filter((t) => (u?.slots[t.id] ?? []).length === 0 && t.accepts.types.includes(e.type)) ?? [], f = Math.max(0, d.findIndex((e) => e.id === r.slot)), p = l.height / Math.max(1, d.length), m = {
				height: Math.max(4, p - 8),
				width: Math.max(4, l.width - 8),
				x: l.x + 4,
				y: l.y + f * p + 4
			};
			a.push({
				...o,
				distanceX: m.x + m.width / 2,
				distanceY: m.y + m.height / 2,
				indicator: m,
				specificity: r.specificity
			});
		}
		return a;
	}
	#renderViewportSwitcher() {
		let e = this.#orderedViewports();
		if (e.length === 0) return A$10;
		let n = this.activeViewport;
		return b$12`
      <section class="viewport-switcher" aria-label=${this.#text(`studio.shell/viewport-label`)}>
        ${e.map((e) => b$12`
            <button
              type="button"
              class="viewport-option"
              data-viewport-id=${e.id}
              aria-pressed=${n?.id === e.id ? `true` : `false`}
              @click=${() => {
			this.#selectViewport(e);
		}}
            >
              ${G(e.label)}
            </button>
          `)}
      </section>
    `;
	}
	#requestInsert(e) {
		let t = this.#insertionDestination(e);
		if (t === void 0) return;
		let n = {
			definition: e,
			parentId: t.parentNodeId ?? null
		};
		t.slot !== void 0 && (n.slot = t.slot), this.dispatchEvent(new CustomEvent(`studio-insert-request`, {
			bubbles: !0,
			composed: !0,
			detail: n
		}));
	}
	#resolveDragIndex(e, t) {
		let n = [...this.shadowRoot?.querySelectorAll(`button.canvas-chip`) ?? []].filter((e) => {
			let n = e.dataset.nodeId;
			return n !== void 0 && t.order.includes(n);
		}), r, i = 1 / 0;
		for (let a of n) {
			let n = a.getBoundingClientRect();
			if (n.height <= 0) continue;
			let o = a.dataset.nodeId;
			if (o === void 0) continue;
			if (e.clientY >= n.top && e.clientY <= n.bottom) return t.order.indexOf(o);
			let s = Math.abs(e.clientY - (n.top + n.height / 2));
			s < i && (i = s, r = t.order.indexOf(o));
		}
		if (r !== void 0) return r;
		for (let n of e.composedPath()) if (n instanceof HTMLElement) {
			let e = n.dataset.nodeId;
			if (e !== void 0 && t.order.includes(e)) return t.order.indexOf(e);
		}
	}
	#revalidate() {
		if (this.document === void 0) {
			this.#diagnostics = [], this.#bindingProjection = void 0;
			return;
		}
		let e = this.#registry ?? new BlockRegistry(), t = validateBlueprint(this.document, e);
		this.#bindingProjection = this.contentModel === void 0 ? void 0 : projectBlueprintFieldBindings(this.document, this.contentModel, this.#activeDefinitions()), this.#diagnostics = [...t.diagnostics, ...this.#bindingProjection?.diagnostics ?? []].sort((e, t) => P[e.severity] - P[t.severity]);
	}
	#revealDiagnosticNode(e) {
		this.#selectNode(e), this.#pendingFocusNodeId = e, this.requestUpdate();
	}
	#runPaletteEntry(e) {
		if (e.disabled) return;
		let t = this.#paletteInvoker;
		this.#closePalette(!1), e.run(), this.#pendingFocusNodeId === void 0 && t?.isConnected === !0 && t.focus();
	}
	#runShellCommand(e) {
		try {
			return this.execute(e), !0;
		} catch {
			return !1;
		}
	}
	#selectNode(e, t = !0) {
		let n = this.#session;
		if (n !== void 0) {
			try {
				n.select([e]);
			} catch {
				return;
			}
			this.selectedNodeId = e, t && this.#previewSurface?.selectNode(e);
		}
	}
	#selectViewport(e) {
		this.activeViewport?.id !== e.id && (this.#activeViewportId = e.id, this.dispatchEvent(new CustomEvent(`studio-viewport-change`, {
			bubbles: !0,
			composed: !0,
			detail: { viewport: e }
		})), this.#announce(`studio.shell/announce-viewport-changed`, { label: G(e.label) }), this.#schedulePreview(), this.requestUpdate());
	}
	#previewCapabilityAvailable() {
		let e = this.configuration?.session;
		return e?.preview.enabled === !0 && e.hostCapabilities.ports.some((e) => e.id === `studio.port/preview` && e.operations.includes(`studio.operation/preview.render`) && e.operations.includes(`studio.operation/preview.cancel`));
	}
	#schedulePreview() {
		let e = this.#previewSurface, t = this.document;
		e !== void 0 && t !== void 0 && e.update(t, this.activeViewport?.id ?? this.configuration?.session.preview.initialViewport);
	}
	#synchronizePreviewSurface() {
		let e = this.previewBinding, t = this.configuration?.session.sessionGeneration;
		if (!this.#previewCapabilityAvailable() || e === void 0 || t === void 0) {
			this.#previewSurface !== void 0 && this.#previewSurface.teardown(`studio.preview/capability-revoked`), this.#previewSurface = void 0, this.#activePreviewBinding = void 0, this.#previewBindingGeneration = void 0, this.previewState = `unavailable`, this.canvasGeometry = void 0;
			return;
		}
		(this.#previewSurface === void 0 || this.#activePreviewBinding !== e || this.#previewBindingGeneration !== t) && (this.#previewSurface?.teardown(`studio.preview/session-replaced`), this.#activePreviewBinding = e, this.#previewBindingGeneration = t, this.#previewSurface = new StudioPreviewSurface(e, {
			onActivated: (e) => {
				this.#selectNode(e, !1), this.requestUpdate();
			},
			onGeometry: (e) => {
				this.canvasGeometry = e, e === void 0 && (this.#hoveredPreviewNodeId = void 0, this.#previewDrag !== void 0 && this.#cancelDrag());
			},
			onMessage: (e) => {
				this.notifyPreviewMessage(e);
			},
			onState: (e) => {
				this.previewState = e;
			}
		}));
	}
	#serializedInspectorValue(e, t, n) {
		let r = n === void 0 ? e.properties[t] : e.responsive?.[t]?.[n.id];
		return r === void 0 ? `` : JSON.stringify(r);
	}
	#fieldAtPath(e) {
		let t = this.contentModel?.fields, n;
		for (let r of e) {
			if (n = t?.find((e) => e.id === r), n === void 0) return;
			t = n.fields;
		}
		return n;
	}
	#modelPortAdvertised() {
		return this.configuration?.session.hostCapabilities.ports.some((e) => e.id === `studio.port/model`) ?? !1;
	}
	#resourcePortAdvertised() {
		return this.configuration?.session.hostCapabilities.ports.some((e) => e.id === `studio.port/resource` && e.operations.includes(`studio.operation/resource.search`)) ?? !1;
	}
	#setFieldBinding(e, t, n) {
		let r = this.#session, i = this.document;
		if (r === void 0 || i === void 0 || !this.#permits(`studio.command/set-binding`)) return;
		let a = e.bindings[t], o = a?.source.kind === `entry-field` ? {
			...a,
			source: {
				fieldPath: [...n.fieldPath],
				kind: `entry-field`
			}
		} : {
			onError: `error`,
			onNull: `empty`,
			source: {
				fieldPath: [...n.fieldPath],
				kind: `entry-field`
			},
			transforms: []
		}, s = {
			...this.#commandEnvelope(i, r),
			payload: {
				binding: o,
				nodeId: e.id,
				port: t
			},
			type: `studio.command/set-binding`
		};
		this.#runShellCommand(s) && this.#announce(`studio.shell/announce-field-bound`, {
			field: n.fieldPath.join(`.`),
			port: t
		});
	}
	#setBinding(e) {
		let t = this.#session, n = this.document, r = this.shadowRoot?.querySelector(`input.inspector-binding-port`) ?? null, i = this.shadowRoot?.querySelector(`input.inspector-binding-value-input`) ?? null;
		if (t === void 0 || n === void 0 || !this.#permits(`studio.command/set-binding`) || r === null || i === null) return;
		let a = r.value.trim();
		if (a.length === 0) {
			this.#announce(`studio.shell/announce-name-required`);
			return;
		}
		if (this.#findDefinition(e)?.ports.some((e) => e.id === a && e.valueType === `resource`)) {
			this.#announce(`studio.shell/announce-invalid-value`, { label: a });
			return;
		}
		let o = this.#parseJsonInput(i.value, a);
		if (o === void 0) return;
		let s = o.value;
		if (typeof s != `object` || !s || Array.isArray(s)) {
			this.#announce(`studio.shell/announce-invalid-value`, { label: a });
			return;
		}
		let c = {
			...this.#commandEnvelope(n, t),
			payload: {
				binding: s,
				nodeId: e.id,
				port: a
			},
			type: `studio.command/set-binding`
		};
		this.#runShellCommand(c) && (r.value = ``, i.value = ``, this.#announce(`studio.shell/announce-binding-set`, { port: a }));
	}
	#setNodeProperty(e, t, n, r) {
		let i = this.#session, a = this.document;
		if (i === void 0 || a === void 0 || !this.#permits(`studio.command/set-property`)) return !1;
		let o = {
			nodeId: e.id,
			property: t,
			value: n
		};
		r !== void 0 && (o.viewport = r.id);
		let s = {
			...this.#commandEnvelope(a, i),
			payload: o,
			type: `studio.command/set-property`
		};
		return this.#runShellCommand(s) ? (r === void 0 ? this.#announce(`studio.shell/announce-property-set`, { property: t }) : this.#announce(`studio.shell/announce-override-set`, {
			property: t,
			viewport: G(r.label)
		}), !0) : !1;
	}
	#setSizeRole(e, t, n) {
		let r = this.#session, i = this.document;
		if (r === void 0 || i === void 0 || !this.#permits(`studio.command/set-size-role`)) return !1;
		let a = this.#sizeRoleTargetViewport(), o = {
			axis: t,
			nodeId: e.id,
			role: n
		};
		a !== void 0 && (o.viewport = a.id);
		let s = {
			...this.#commandEnvelope(i, r),
			payload: o,
			type: `studio.command/set-size-role`
		};
		return this.#runShellCommand(s) ? (a === void 0 ? this.#announce(`studio.shell/announce-size-role-set`, {
			axis: this.#axisText(t),
			role: n
		}) : this.#announce(`studio.shell/announce-size-role-set-viewport`, {
			axis: this.#axisText(t),
			role: n,
			viewport: G(a.label)
		}), !0) : !1;
	}
	#sizeRoleTargetViewport() {
		let e = this.activeViewport;
		return e === void 0 || e.base ? void 0 : e;
	}
	#sizeRoleVocabulary() {
		let e = this.#activeDesignControls();
		if (e === void 0) return;
		let t = [], n = /* @__PURE__ */ new Set();
		for (let r of e) if (r.kind === `size-role`) for (let e of r.choices) n.has(e.id) || (n.add(e.id), t.push(e));
		return t;
	}
	#slotLabel(e, t) {
		let n = this.#findDefinition(e)?.slots.find((e) => e.id === t);
		return this.#text(`studio.shell/outline-slot`, { slot: n === void 0 ? t : G(n.label) });
	}
	#syncDirty() {
		let e = this.#session?.dirty ?? !1;
		e !== this.#lastDirty && (this.#lastDirty = e, this.dispatchEvent(new CustomEvent(`studio-dirty-changed`, {
			bubbles: !0,
			composed: !0,
			detail: { dirty: e }
		})));
	}
	#text(e, t) {
		return messageText(e, this.messages, t);
	}
	#togglePalette(e) {
		if (this.paletteOpen === !0) {
			this.#closePalette(!0);
			return;
		}
		let t = e?.composedPath()[0];
		this.#paletteInvoker = t instanceof HTMLElement ? t : void 0, this.paletteOpen = !0, this.paletteFilter = ``, this.#pendingPaletteFocus = !0;
	}
	#unsetNodeProperty(e, t, n) {
		let r = this.#session, i = this.document;
		if (r === void 0 || i === void 0 || !this.#permits(`studio.command/unset-property`)) return;
		let a = {
			nodeId: e.id,
			property: t
		};
		n !== void 0 && (a.viewport = n.id);
		let o = {
			...this.#commandEnvelope(i, r),
			payload: a,
			type: `studio.command/unset-property`
		};
		this.#runShellCommand(o) && (n === void 0 ? this.#announce(`studio.shell/announce-property-unset`, { property: t }) : this.#announce(`studio.shell/announce-override-removed`, {
			property: t,
			viewport: G(n.label)
		}));
	}
	#unsetSizeRole(e, t) {
		let n = this.#session, r = this.document;
		if (n === void 0 || r === void 0 || !this.#permits(`studio.command/unset-size-role`)) return;
		let i = this.#sizeRoleTargetViewport(), a = {
			axis: t,
			nodeId: e.id
		};
		i !== void 0 && (a.viewport = i.id);
		let o = {
			...this.#commandEnvelope(r, n),
			payload: a,
			type: `studio.command/unset-size-role`
		};
		this.#runShellCommand(o) && (i === void 0 ? this.#announce(`studio.shell/announce-size-role-removed`, { axis: this.#axisText(t) }) : this.#announce(`studio.shell/announce-size-role-removed-viewport`, {
			axis: this.#axisText(t),
			viewport: G(i.label)
		}));
	}
};
var O = /* @__PURE__ */ new Set([
	`read-only-session`,
	`stale-generation`,
	`stale-state`
]);
var k = {
	block: `studio.shell/inspector-layout-axis-block`,
	inline: `studio.shell/inspector-layout-axis-inline`
};
var A = [`inline`, `block`];
var j = /^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/;
var M = /* @__PURE__ */ new Set([
	`__proto__`,
	`constructor`,
	`prototype`
]);
var N = {
	blocking: `studio.shell/severity-blocking`,
	error: `studio.shell/severity-error`,
	information: `studio.shell/severity-information`,
	warning: `studio.shell/severity-warning`
};
var P = {
	blocking: 0,
	error: 1,
	information: 3,
	warning: 2
};
var F = new Set(Object.values(STUDIO_AUTHORING_CONTROL_IDS));
function I(e) {
	return F.has(e);
}
function L(e) {
	switch (e) {
		case `studio.control/rich-text`: return {
			content: [],
			type: `doc`
		};
		case `studio.control/source`: return ``;
		case `studio.control/chart`: return {
			datasets: [{
				label: `Series 1`,
				values: [0]
			}],
			labels: [`Label 1`],
			type: `bar`
		};
		case `studio.control/drawing`: return {
			alt: `Drawing`,
			height: 600,
			strokes: [],
			width: 800
		};
		case `studio.control/money`: return {
			amount: `0`,
			currency: `USD`
		};
		case `studio.control/media-collection`: return [];
		case `studio.control/media-reference`: return;
		case `studio.control/scoped-css`: return { rules: [] };
		case `studio.control/table`: return {
			columns: [`Column 1`],
			rows: [[``]]
		};
	}
}
function R(e) {
	return JSON.stringify({
		bindingKind: e.binding?.source.kind,
		control: e.control,
		kind: e.kind,
		name: e.name,
		profile: e.profile,
		readOnly: e.readOnly,
		value: e.value
	});
}
function z(e) {
	return JSON.stringify({
		binding: e.binding,
		multiple: e.multiple,
		readOnly: e.readOnly
	});
}
function B(e, t = 0) {
	if (t > 32) return;
	if (e === null || typeof e == `boolean`) return e;
	if (typeof e == `number`) return Number.isFinite(e) ? e : void 0;
	if (typeof e == `string`) return e.length <= 1e6 ? e : void 0;
	if (Array.isArray(e)) {
		if (e.length > 1e4) return;
		let n = [];
		for (let r of e) {
			let e = B(r, t + 1);
			if (e === void 0) return;
			n.push(e);
		}
		return n;
	}
	if (typeof e != `object` || e === void 0) return;
	let n = Object.getPrototypeOf(e);
	if (n !== Object.prototype && n !== null) return;
	let r = Object.entries(e);
	if (r.length > 1e3) return;
	let i = {};
	for (let [e, n] of r) {
		if (e === `__proto__` || e === `constructor` || e === `prototype`) return;
		let r = B(n, t + 1);
		if (r === void 0) return;
		i[e] = r;
	}
	return i;
}
function V(e) {
	let t = G(e.message);
	if (e.parameters === void 0) return t;
	let n = t;
	for (let [t, r] of Object.entries(e.parameters)) n = n.replaceAll(`{${t}}`, String(r));
	return n;
}
function H(e) {
	let t = e.filter((e) => e.width > 0 && e.height > 0), n = t[0];
	if (n === void 0) return;
	let r = n.x, i = n.y, a = n.x + n.width, o = n.y + n.height;
	for (let e of t.slice(1)) r = Math.min(r, e.x), i = Math.min(i, e.y), a = Math.max(a, e.x + e.width), o = Math.max(o, e.y + e.height);
	return {
		height: o - i,
		width: a - r,
		x: r,
		y: i
	};
}
function U(e, t) {
	let n = e[0], r = e.at(-1);
	if (n === void 0 || r === void 0) return {
		height: 4,
		width: 4,
		x: 0,
		y: 0
	};
	let i = H(e) ?? n, a = Math.abs(r.x + r.width / 2 - (n.x + n.width / 2)), o = Math.abs(r.y + r.height / 2 - (n.y + n.height / 2)), s = e[Math.max(0, t - 1)] ?? n, c = e[Math.min(e.length - 1, t)] ?? r;
	if (a > o) {
		let a = t === 0 ? n.x : t >= e.length ? r.x + r.width : (s.x + s.width + c.x) / 2;
		return {
			height: Math.max(4, i.height),
			width: 4,
			x: a - 2,
			y: i.y
		};
	}
	let l = t === 0 ? n.y : t >= e.length ? r.y + r.height : (s.y + s.height + c.y) / 2;
	return {
		height: 4,
		width: Math.max(4, i.width),
		x: i.x,
		y: l - 2
	};
}
function W(e) {
	return e.length > 0 && e.length <= 100 && j.test(e) && !M.has(e);
}
function G(e) {
	return e.defaultMessage ?? e.key;
}
//#endregion
//#region node_modules/@kumwe/studio/dist/index.js
function defineKumweStudio(t = `kumwe-studio`) {
	customElements.get(t) === void 0 && customElements.define(t, KumweStudioElement);
}
//#endregion
//#region assets/administrator/components/studio-contributions.ts
function activateStudioContributions() {
	return { runtime: new ContributionRuntime({ generation: "composition-runtime-r0" }) };
}
function activateHostContributions(runtime, documents, trustedOwners) {
	const owners = /* @__PURE__ */ new Map();
	for (const document of documents) {
		const identity = document.kind === "block-definition" ? document.type : document.id;
		const trustedOwner = trustedOwners[`${document.kind} ${identity}`];
		if (trustedOwner === void 0) continue;
		const key = `${trustedOwner}\u0000${document.owner.id}@${document.owner.version}`;
		const entry = owners.get(key) ?? {
			owner: document.owner,
			contributions: { blocks: [] }
		};
		if (document.kind === "block-definition") entry.contributions.blocks.push(document);
		else if (document.kind === "pattern") (entry.contributions.patterns ??= []).push(document);
		else if (document.kind === "field-adapter") (entry.contributions.fieldAdapters ??= []).push(document);
		else if (document.kind === "inspector") (entry.contributions.inspectors ??= []).push(document);
		else if (document.kind === "design-vocabulary") (entry.contributions.designVocabularies ??= []).push(document);
		else if (document.kind === "migration") (entry.contributions.migrations ??= []).push(document);
		owners.set(key, entry);
	}
	let generation = 0;
	for (const { owner: contributionOwner, contributions: contributionSet } of owners.values()) {
		generation += 1;
		runtime.activate(contributionOwner, contributionSet, { generation: `composition-runtime-r${generation}` });
	}
}
function coreStudioTheme(blocks, trustedRenderers, reference) {
	const renderers = [...new Set(blocks.map(({ type }) => trustedRenderers[type]))].filter((renderer) => renderer !== void 0).sort();
	return {
		blockSupport: blocks.map(({ type }) => ({
			renderer: trustedRenderers[type],
			type,
			versions: "^1.0.0"
		})),
		contractVersion: "0.1-draft",
		designControls: layoutDesignControls(),
		id: reference.id,
		kind: "theme",
		label: {
			key: "kumwe.app/administrator-theme",
			defaultMessage: "Administrator"
		},
		owner: {
			id: reference.id,
			version: reference.version
		},
		recipes: layoutRecipes(),
		renderers: renderers.map((id) => ({
			exactPreview: true,
			id,
			surfaces: ["web", "preview"],
			version: "1.0.0"
		})),
		revision: reference.revision,
		version: reference.version,
		viewports: [
			{
				base: true,
				id: "compact",
				label: {
					key: "studio.viewport/compact",
					defaultMessage: "Compact"
				},
				order: 0,
				previewWidth: 360
			},
			{
				base: false,
				id: "medium",
				label: {
					key: "studio.viewport/medium",
					defaultMessage: "Medium"
				},
				order: 1,
				previewWidth: 768
			},
			{
				base: false,
				id: "expanded",
				label: {
					key: "studio.viewport/expanded",
					defaultMessage: "Expanded"
				},
				order: 2,
				previewWidth: 1440
			}
		]
	};
}
function layoutDesignControls() {
	return [
		designControl(CORE_LAYOUT_THEME_CONTROLS.alignment, "enum", "Alignment", [
			["center", "Centre"],
			["end", "End"],
			["start", "Start"],
			["stretch", "Stretch"]
		]),
		designControl(CORE_LAYOUT_THEME_CONTROLS.collapse, "enum", "Responsive collapse", [
			["preserve", "Preserve"],
			["stack", "Stack"],
			["wrap", "Wrap"]
		]),
		designControl(CORE_LAYOUT_THEME_CONTROLS.direction, "enum", "Direction", [["block", "Vertical"], ["inline", "Horizontal"]]),
		designControl(CORE_LAYOUT_THEME_CONTROLS.spacing, "spacing-role", "Spacing", [
			["comfortable", "Comfortable"],
			["compact", "Compact"],
			["none", "None"],
			["spacious", "Spacious"]
		]),
		designControl(CORE_LAYOUT_THEME_CONTROLS.visibility, "enum", "Visibility", [["hidden", "Hidden"], ["visible", "Visible"]])
	];
}
function designControl(id, kind, label, choices) {
	return {
		choices: choices.map(([choice, choiceLabel]) => ({
			id: choice,
			label: {
				key: `core.composition/${id}-${choice}`,
				defaultMessage: choiceLabel
			}
		})),
		id,
		kind,
		label: {
			key: `core.composition/${id}`,
			defaultMessage: label
		}
	};
}
function layoutRecipes() {
	return [{
		blockType: "studio.core/grid",
		designValues: {
			[CORE_LAYOUT_THEME_CONTROLS.alignment]: "stretch",
			[CORE_LAYOUT_THEME_CONTROLS.collapse]: "stack",
			[CORE_LAYOUT_THEME_CONTROLS.spacing]: "comfortable",
			[CORE_LAYOUT_THEME_CONTROLS.visibility]: "visible"
		},
		id: "responsive-content-grid",
		label: {
			key: "core.composition/responsive-content-grid",
			defaultMessage: "Responsive content grid"
		}
	}];
}
//#endregion
//#region assets/administrator/components/studio-host-adapter.ts
function createStudioHttpHostAdapter(baseUrl, options) {
	const base = baseUrl.endsWith("/") ? baseUrl.slice(0, -1) : baseUrl;
	let serial = 0;
	let previewSequence = 0;
	let previewRenderTail;
	let previewUnavailable;
	let unloading = false;
	const markUnloading = () => {
		unloading = true;
		window.setTimeout(() => {
			unloading = false;
		}, 0);
	};
	window.addEventListener("beforeunload", markUnloading);
	window.addEventListener("pagehide", markUnloading);
	window.addEventListener("pageshow", () => {
		unloading = false;
	});
	const failure = (category, code, retryable = false) => {
		serial += 1;
		return new HostPortFailure({
			category,
			contractVersion: STUDIO_CONTRACT_VERSION,
			correlationId: `browser-transport-${serial}`,
			kind: "host-error",
			message: {
				key: code,
				defaultMessage: "The Studio host request could not be completed."
			},
			retryable
		});
	};
	const call = async (port, operation, args, context) => {
		const perform = async () => {
			if (port === "preview" && previewUnavailable !== void 0) throw previewUnavailable;
			const controller = new AbortController();
			const timer = window.setTimeout(() => controller.abort(), 1e4);
			const headers = {
				"content-type": "application/json",
				"X-CSRF-Token": options.csrf
			};
			if (port === "preview" && options.preview !== void 0) {
				headers["X-Kumwe-Studio-Preview-Channel"] = options.preview.channelId;
				headers["X-Kumwe-Studio-Preview-Source"] = options.preview.sourceId;
				headers["X-Kumwe-Studio-Preview-Sequence"] = String(previewSequence);
				previewSequence += 1;
			}
			let response;
			try {
				response = await fetch(`${base}/${port}/${operation}`, {
					body: JSON.stringify({
						arguments: args,
						context
					}),
					credentials: "same-origin",
					headers,
					keepalive: port === "preview" && operation === "cancel" && unloading,
					method: "POST",
					signal: controller.signal
				});
			} catch (reason) {
				const aborted = reason instanceof DOMException && reason.name === "AbortError";
				const unavailable = failure("unavailable", aborted ? "studio.host/transport-timeout" : "studio.host/transport-unavailable", true);
				if (port === "preview") previewUnavailable = unavailable;
				throw unavailable;
			} finally {
				window.clearTimeout(timer);
			}
			let body;
			try {
				body = await response.json();
			} catch {
				throw failure("internal", "studio.host/malformed-response");
			}
			if (!response.ok) {
				if (isHostPortError(body)) throw new HostPortFailure(body);
				throw failure(categoryForStatus(response.status), "studio.host/http-refused", response.status === 429 || response.status >= 502);
			}
			if (!isResult(body)) throw failure("internal", "studio.host/malformed-response");
			return body;
		};
		if (port !== "preview" || operation === "cancel") return perform();
		const pending = previewRenderTail === void 0 ? perform() : previewRenderTail.then(perform);
		const completed = pending.then(() => void 0, () => void 0);
		previewRenderTail = completed;
		completed.finally(() => {
			if (previewRenderTail === completed) previewRenderTail = void 0;
		});
		return pending;
	};
	const adapter = { artifact: {
		dependencies: (reference, context) => call("artifact", "dependencies", { reference: copy(reference) }, context),
		load: (reference, context) => call("artifact", "load", { reference: copy(reference) }, context),
		publish: (reference, context) => call("artifact", "publish", { reference: copy(reference) }, context),
		save: (document, context) => call("artifact", "save", { document: copy(document) }, context),
		unpublish: (reference, context) => call("artifact", "unpublish", { reference: copy(reference) }, context)
	} };
	if (hasPort(options.advertised, "localization")) adapter.localization = { messages: (locale, namespaces, context) => call("localization", "messages", {
		locale,
		namespaces
	}, context) };
	if (hasPort(options.advertised, "model")) adapter.model = {
		get: (reference, context) => call("model", "get", { reference: copy(reference) }, context),
		list: (context) => call("model", "list", {}, context)
	};
	if (hasPort(options.advertised, "permission")) adapter.permission = {
		explain: (operation, context) => call("permission", "explain", { operation }, context),
		refresh: (context) => call("permission", "refresh", {}, context)
	};
	if (hasPort(options.advertised, "recovery")) adapter.recovery = {
		discard: (context) => call("recovery", "discard", {}, context),
		load: (context) => call("recovery", "load", {}, context),
		store: (envelope, context) => call("recovery", "store", { envelope }, context)
	};
	if (hasPort(options.advertised, "resource")) adapter.resource = { search: (query, context) => call("resource", "search", { query: copy(query) }, context) };
	if (hasPort(options.advertised, "telemetry")) adapter.telemetry = { emit: (event, context) => call("telemetry", "emit", { event: copy(event) }, context) };
	if (hasPort(options.advertised, "preview")) adapter.preview = {
		cancel: (draftDigest, context) => call("preview", "cancel", { draftDigest }, context),
		render: (payload, context) => call("preview", "render", { payload: copy(payload) }, context)
	};
	if (hasPort(options.advertised, "media")) adapter.media = {
		abortUpload: (uploadId, context) => call("media", "abort-upload", { uploadId }, context),
		authorizeUpload: (request, context) => call("media", "authorize-upload", { request: copy(request) }, context),
		completeUpload: (uploadId, context) => call("media", "complete-upload", { uploadId }, context),
		get: (assetId, context) => call("media", "get", { assetId }, context),
		importExternal: (url, context) => call("media", "import-external", { url }, context),
		list: (query, context) => call("media", "list", { query: copy(query) }, context),
		uploadStatus: (assetId, context) => call("media", "upload-status", { assetId }, context)
	};
	return adapter;
}
function hasPort(advertised, port) {
	return advertised.has(`studio.port/${port}`);
}
function copy(value) {
	return JSON.parse(JSON.stringify(value));
}
function isResult(value) {
	if (typeof value !== "object" || value === null || Array.isArray(value) || !Object.hasOwn(value, "value")) return false;
	const revision = value.revision;
	return (revision === void 0 || typeof revision === "string" && revision.length > 0) && Object.keys(value).every((key) => key === "value" || key === "revision");
}
function categoryForStatus(status) {
	if (status === 401) return "unauthenticated";
	if (status === 403) return "forbidden";
	if (status === 404) return "not-found";
	if (status === 409) return "conflict";
	if (status === 413) return "limit-exceeded";
	if (status === 422) return "validation-failed";
	if (status === 429) return "rate-limited";
	if (status === 408 || status === 502 || status === 503 || status === 504) return "unavailable";
	if (status >= 500) return "internal";
	return "invalid-request";
}
//#endregion
//#region assets/administrator/components/studio-composition.ts
async function setupStudioComposition() {
	const root = document.querySelector("[data-studio-composition]");
	const encoded = document.querySelector("#studio-composition-boot");
	const status = document.querySelector("[data-studio-composition-status]");
	if (root === null || encoded === null || status === null) return;
	const surfaceMessages = compositionSurfaceMessages(root);
	defineKumweStudio();
	const shell = root.querySelector("kumwe-studio");
	if (shell === null) return;
	try {
		const boot = JSON.parse(encoded.textContent ?? "");
		if (boot.release !== "0.1.0-beta.3") throw new Error("Studio release binding mismatch.");
		const opened = await openHostSession(boot);
		const advertised = new Set(opened.hostCapabilities);
		const adapter = createStudioHttpHostAdapter(boot.endpoints.ports, {
			advertised,
			csrf: boot.csrf,
			...opened.preview === void 0 ? {} : { preview: opened.preview }
		});
		const ids = identifierFactories();
		const configuration = studioConfiguration(boot, opened);
		const handle = await openStudioSession(adapter, {
			configuration,
			identifiers: ids
		});
		const model = (await handle.models?.get(boot.document.model))?.value ?? boot.model;
		const { runtime } = activateStudioContributions();
		activateHostContributions(runtime, boot.contributions, boot.contributionOwners);
		const generation = runtime.current;
		const admittedBlocks = new Set(boot.contributions.filter((contribution) => contribution.kind === "block-definition").map((contribution) => `${contribution.type}@${contribution.version}#${contribution.revision}`));
		const blocks = generation.blocks().filter((block) => {
			const trustedRenderer = boot.blockRenderers[block.type];
			return admittedBlocks.has(`${block.type}@${block.version}#${block.revision}`) && trustedRenderer !== void 0 && block.rendererRequirements.some(({ capability }) => capability === trustedRenderer);
		});
		const admittedPatterns = new Set(boot.contributions.filter((contribution) => contribution.kind === "pattern").map((contribution) => `${contribution.id}@${contribution.version}#${contribution.revision}`));
		const theme = coreStudioTheme(blocks, boot.blockRenderers, boot.document.dependencyLock.theme);
		const messages = await loadMessages(adapter.localization, boot, opened, ids);
		shell.configuration = {
			session: configuration,
			blockDefinitions: blocks
		};
		shell.contentModel = model;
		shell.document = handle.session.document;
		shell.messages = messages;
		shell.patterns = generation.contributions("pattern").filter((pattern) => admittedPatterns.has(`${pattern.id}@${pattern.version}#${pattern.revision}`));
		shell.theme = theme;
		shell.designControls = theme.designControls;
		shell.viewports = theme.viewports;
		let disposePreview = () => void 0;
		let saveTail = Promise.resolve();
		let conflicted = false;
		let lifecycleChanging = false;
		const quarantineConflict = () => {
			if (conflicted) return;
			conflicted = true;
			disposePreview();
			handle.dispose();
			status.textContent = surfaceMessages.conflict;
			const refusal = document.createElement("div");
			refusal.className = "notice error";
			refusal.dataset.studioCompositionConflict = "";
			refusal.setAttribute("role", "alert");
			refusal.textContent = surfaceMessages.conflict;
			shell.replaceWith(refusal);
		};
		const save = () => {
			saveTail = saveTail.then(async () => {
				if (conflicted || !handle.session.dirty) return;
				try {
					const acceptedStateVersion = handle.session.stateVersion;
					const accepted = await handle.save();
					shell.markSaved(accepted.revision, acceptedStateVersion);
					await shell.updateComplete;
					status.textContent = surfaceMessages.saved;
				} catch (error) {
					if (isHostPortFailure(error) && error.error.category === "conflict") {
						quarantineConflict();
						return;
					}
					status.textContent = surfaceMessages.saveFailed;
					throw error;
				}
			}).catch(() => void 0);
			return saveTail;
		};
		shell.addEventListener("studio-document-change", (event) => {
			if (conflicted || lifecycleChanging || handle.session.sessionState === "read-only") return;
			const detail = event.detail;
			try {
				if (detail.source === "command" && detail.command !== null) handle.session.execute(detail.command);
				else if (detail.source === "undo") handle.session.undo();
				else if (detail.source === "redo") handle.session.redo();
				shell.document = handle.session.document;
				save();
			} catch {
				status.textContent = surfaceMessages.changeRefused;
			}
		});
		shell.addEventListener("studio-insert-request", (event) => {
			if (conflicted || lifecycleChanging || handle.session.sessionState === "read-only") return;
			const detail = event.detail;
			const command = insertCommand(shell, handle, detail, opened.sessionGeneration);
			shell.execute(command);
			shell.selectNode(command.payload.node.id);
		});
		if (opened.preview !== void 0 && adapter.preview !== void 0) {
			const frame = root.querySelector("[data-studio-preview]");
			if (frame !== null) {
				const preview = previewBinding(frame, opened.preview, opened, adapter.preview, handle, shell, save);
				shell.previewBinding = preview.binding;
				disposePreview = preview.dispose;
			}
		}
		let disposed = false;
		const dispose = () => {
			if (disposed) return;
			disposed = true;
			disposePreview();
			handle.dispose();
		};
		const lifecycleButtons = [...root.querySelectorAll("[data-studio-publish], [data-studio-unpublish]")];
		const mayChangeLifecycle = (target) => target === "published" ? opened.lifecycle.canPublish : opened.lifecycle.canUnpublish;
		for (const button of lifecycleButtons) button.hidden = !mayChangeLifecycle(button.hasAttribute("data-studio-publish") ? "published" : "draft");
		let lifecycleTail = Promise.resolve();
		const changeLifecycle = (target) => {
			lifecycleTail = lifecycleTail.then(async () => {
				if (conflicted || !mayChangeLifecycle(target) || boot.status === target) return;
				lifecycleChanging = true;
				shell.inert = true;
				shell.setAttribute("aria-busy", "true");
				for (const button of lifecycleButtons) button.disabled = true;
				status.textContent = target === "published" ? surfaceMessages.publishing : surfaceMessages.unpublishing;
				if (target === "published") {
					await save();
					await shell.updateComplete;
					if (handle.session.dirty) throw new Error("The latest draft was not accepted before publication.");
				}
				const operationId = `studio.operation/artifact.${target === "published" ? "publish" : "unpublish"}`;
				const context = {
					expectedRevision: handle.revision,
					idempotencyKey: ids.idempotencyKey(operationId),
					operationId,
					protocolVersion: opened.protocolVersion,
					requestId: ids.requestId(operationId),
					resourceContextKey: opened.resourceContextKey,
					sessionGeneration: opened.sessionGeneration
				};
				const reference = {
					id: boot.artifact.id,
					revision: handle.revision,
					version: boot.artifact.version
				};
				if ((target === "published" ? await adapter.artifact.publish(reference, context) : await adapter.artifact.unpublish(reference, context)).revision === void 0) throw new Error("The lifecycle mutation omitted its accepted revision.");
				dispose();
				window.location.reload();
			}).catch((error) => {
				if (isHostPortFailure(error) && error.error.category === "conflict") {
					quarantineConflict();
					return;
				}
				status.textContent = surfaceMessages.lifecycleFailed;
				lifecycleChanging = false;
				shell.inert = false;
				shell.removeAttribute("aria-busy");
				for (const button of lifecycleButtons) button.disabled = false;
			});
		};
		root.querySelector("[data-studio-publish]")?.addEventListener("click", () => changeLifecycle("published"));
		root.querySelector("[data-studio-unpublish]")?.addEventListener("click", () => changeLifecycle("draft"));
		status.textContent = surfaceMessages.ready;
		window.addEventListener("beforeunload", dispose, { once: true });
		window.addEventListener("pagehide", dispose, { once: true });
	} catch {
		status.textContent = surfaceMessages.openFailed;
	}
}
function compositionSurfaceMessages(root) {
	const messages = {
		changeRefused: root.dataset.messageChangeRefused,
		conflict: root.dataset.messageConflict,
		lifecycleFailed: root.dataset.messageLifecycleFailed,
		openFailed: root.dataset.messageOpenFailed,
		publishing: root.dataset.messagePublishing,
		ready: root.dataset.messageReady,
		saveFailed: root.dataset.messageSaveFailed,
		saved: root.dataset.messageSaved,
		unpublishing: root.dataset.messageUnpublishing
	};
	if (Object.values(messages).some((message) => message === void 0 || message === "")) throw new Error("The Studio composition surface message catalogue is incomplete.");
	return messages;
}
async function openHostSession(boot) {
	const response = await fetch(boot.endpoints.session, {
		body: JSON.stringify({
			mode: "blueprint",
			resourceId: boot.artifact.id,
			resourceKind: "blueprint"
		}),
		credentials: "same-origin",
		headers: {
			"content-type": "application/json",
			"X-CSRF-Token": boot.csrf
		},
		method: "POST"
	});
	if (!response.ok) throw new Error("Studio session opening was refused.");
	const value = await response.json();
	if (value.protocolVersion !== STUDIO_WIRE_PROTOCOL_VERSION) throw new Error("Studio protocol version mismatch.");
	return value;
}
function studioConfiguration(boot, opened) {
	const operations = new Set(opened.hostCapabilities.filter((capability) => capability.startsWith("studio.operation/")));
	return {
		actor: boot.actor,
		artifacts: {
			blueprint: boot.artifact,
			model: boot.document.model,
			theme: boot.document.dependencyLock.theme
		},
		blocks: boot.document.dependencyLock.blocks,
		composite: "single",
		contractVersion: "0.1-draft",
		displayPreferences: {
			calendar: "gregory",
			hourCycle: "h23",
			numberingSystem: "latn"
		},
		features: {
			clipboardMediaUpload: operations.has("studio.operation/media.authorize-upload"),
			collaboration: false,
			customInspectors: false,
			executablePlugins: false,
			externalMediaImport: operations.has("studio.operation/media.import-external"),
			offlineRecovery: [
				"store",
				"load",
				"discard"
			].every((operation) => operations.has(`studio.operation/recovery.${operation}`))
		},
		hostCapabilities: fullCapabilities(opened),
		limits: {
			maxChildrenPerSlot: 100,
			maxCommandBatch: 100,
			maxContributionsPerPlugin: 1e3,
			maxDepth: 32,
			maxExtensionBytes: 262144,
			maxHistoryEntries: 100,
			maxLocaleBytes: 262144,
			maxMediaBatch: 100,
			maxMediaUploadBytes: 52428800,
			maxNodes: 5e3,
			maxPluginCount: 100,
			maxPreviewBytes: 1048576,
			maxPreviewRequestsPerMinute: 60,
			maxPropertyBytes: 65536,
			maxRichTextBytes: 1048576,
			maxRichTextDepth: 32,
			maxSlotsPerNode: 32
		},
		locale: {
			direction: boot.locale.direction,
			fallbacks: boot.locale.fallbacks,
			requested: boot.locale.requested,
			resolved: boot.locale.resolved,
			timeZone: boot.locale.timezone
		},
		mode: "blueprint",
		permissions: opened.permissions,
		plugins: [],
		protocolVersion: opened.protocolVersion,
		preview: {
			allowApproximateRenderer: false,
			enabled: opened.preview !== void 0 && operations.has("studio.operation/preview.render"),
			initialViewport: "expanded",
			sameOriginRequired: true
		},
		resourceContext: {
			key: opened.resourceContextKey,
			resource: {
				id: boot.artifact.id,
				type: "kumwe.app/content-blueprint"
			},
			revision: boot.artifact.revision,
			scopes: [{
				id: boot.site,
				kind: "kumwe.app/site"
			}],
			surface: "kumwe.app/administrator"
		},
		sessionGeneration: opened.sessionGeneration,
		sessionId: `studio-session-${crypto.randomUUID()}`,
		sessionState: boot.status === "draft" ? "editable" : "read-only"
	};
}
function fullCapabilities(opened) {
	const grouped = /* @__PURE__ */ new Map();
	for (const capability of opened.hostCapabilities) {
		const match = /^studio\.operation\/([a-z0-9-]+)\./.exec(capability);
		if (match?.[1] === void 0) continue;
		const id = `studio.port/${match[1]}`;
		grouped.set(id, [...grouped.get(id) ?? [], capability]);
	}
	return {
		capabilities: [],
		contractVersion: "0.1-draft",
		host: {
			generation: opened.sessionGeneration,
			id: "kumwe.app/studio-host",
			version: "2.0.0"
		},
		kind: "host-capabilities",
		ports: [...grouped.entries()].sort(([left], [right]) => left.localeCompare(right)).map(([id, operations]) => ({
			id,
			operations: operations.sort(),
			version: "1.0.0"
		})),
		protocolVersions: [opened.protocolVersion]
	};
}
function identifierFactories() {
	let request = 0;
	let mutation = 0;
	return {
		requestId: () => `requests/browser-${++request}-${crypto.randomUUID()}`,
		idempotencyKey: () => `operations/browser-${++mutation}-${crypto.randomUUID()}`
	};
}
async function loadMessages(localization, boot, opened, ids) {
	if (localization === void 0) return {};
	const context = {
		locale: boot.locale.resolved,
		operationId: "studio.operation/localization.messages",
		protocolVersion: opened.protocolVersion,
		requestId: ids.requestId("studio.operation/localization.messages"),
		resourceContextKey: opened.resourceContextKey,
		sessionGeneration: opened.sessionGeneration
	};
	const result = await localization.messages(boot.locale.resolved, ["studio.shell"], context);
	return Object.fromEntries(Object.entries(result.value).map(([key, defaultMessage]) => [key, { defaultMessage }]));
}
function insertCommand(shell, handle, detail, sessionGeneration) {
	const properties = isCoreLayoutBlockType(detail.definition.type) ? coreLayoutInitialProperties(detail.definition.type) : {};
	const slots = Object.fromEntries(detail.definition.slots.map(({ id }) => [id, []]));
	let position = shell.document?.roots.length ?? 0;
	if (detail.parentId !== null && detail.slot !== void 0) position = findNode(shell.document?.roots ?? [], detail.parentId)?.slots[detail.slot]?.length ?? 0;
	return {
		artifactId: handle.session.document.id,
		baseStateVersion: shell.stateVersion,
		contractVersion: "0.1-draft",
		expectedRevision: handle.revision,
		id: `commands/insert-${crypto.randomUUID()}`,
		kind: "command",
		payload: {
			destination: {
				...detail.parentId === null ? {} : { parentNodeId: detail.parentId },
				position,
				...detail.slot === void 0 ? {} : { slot: detail.slot }
			},
			node: {
				authoring: { mode: isCoreLayoutBlockType(detail.definition.type) ? "structural" : "content" },
				bindings: {},
				id: `nodes/${crypto.randomUUID()}`,
				properties,
				slots,
				type: detail.definition.type,
				version: detail.definition.version
			}
		},
		sessionGeneration,
		type: "studio.command/insert-node"
	};
}
function findNode(nodes, id) {
	for (const node of nodes) {
		if (node.id === id) return node;
		for (const children of Object.values(node.slots)) {
			const found = findNode(children, id);
			if (found !== void 0) return found;
		}
	}
}
function previewBinding(frame, metadata, opened, previewPort, handle, shell, save) {
	if (new URL(metadata.origin).origin !== window.location.origin) throw new Error("The Studio preview origin is not the current application origin.");
	const bridge = localPreviewBridge(metadata.origin);
	let activeFrame = frame;
	let documentSequence = 0;
	let documentTail = Promise.resolve();
	let navigationGeneration = 0;
	let loaded;
	let stagingFrame;
	let geometry = observePreviewGeometry(activeFrame, shell);
	let disposed = false;
	const attempts = /* @__PURE__ */ new Set();
	let loadedAttempt;
	const cancelAttempt = (attempt) => {
		if (attempt.cancellation !== void 0) return attempt.cancellation;
		const cancellation = previewPort.cancel(attempt.digest, previewContext(opened, "cancel")).then(() => void 0);
		attempt.cancellation = cancellation;
		return cancellation;
	};
	const navigate = (rendered, signal, generation) => {
		const pending = documentTail.then(async () => {
			throwIfAborted(signal);
			if (disposed || generation !== navigationGeneration) throw abortError();
			const previewUrl = new URL(metadata.documentPath, metadata.origin);
			if (previewUrl.origin !== window.location.origin) throw new Error("The Studio preview document URL is not same-origin.");
			const sequence = documentSequence;
			documentSequence += 1;
			previewUrl.search = new URLSearchParams({
				channel: metadata.channelId,
				context: opened.resourceContextKey,
				generation: opened.sessionGeneration,
				render: rendered.requestId,
				sequence: String(sequence),
				source: metadata.sourceId
			}).toString();
			const candidate = createStagingPreviewFrame(activeFrame);
			stagingFrame = candidate;
			if (activeFrame.parentElement === null) throw new Error("The Studio preview frame is no longer attached.");
			activeFrame.after(candidate);
			try {
				await loadPreviewFrame(candidate, previewUrl, rendered, signal);
				throwIfAborted(signal);
				if (disposed || generation !== navigationGeneration) throw abortError();
				geometry.dispose();
				activeFrame.remove();
				candidate.slot = "preview";
				candidate.dataset.studioPreview = "";
				activeFrame = candidate;
				stagingFrame = void 0;
				geometry = observePreviewGeometry(activeFrame, shell);
				candidate.hidden = false;
				geometry.refreshAfterLayout();
			} catch (error) {
				candidate.remove();
				if (stagingFrame === candidate) stagingFrame = void 0;
				throw error;
			}
		});
		documentTail = pending.then(() => void 0, () => void 0);
		return pending;
	};
	const client = new PreviewClient({
		channelId: metadata.channelId,
		sessionGeneration: opened.sessionGeneration,
		source: bridge.clientSource,
		target: bridge.hostTarget,
		targetOrigin: metadata.origin
	});
	const host = new PreviewHost({
		channelId: metadata.channelId,
		renderer: "core.renderer/layout",
		sessionGeneration: opened.sessionGeneration,
		source: bridge.hostSource,
		target: bridge.clientTarget,
		targetOrigin: metadata.origin,
		viewports: [
			"compact",
			"medium",
			"expanded"
		],
		measure: async (markers, signal) => {
			const current = loaded;
			if (current === void 0 || activeFrame.hidden || activeFrame.contentWindow === null || new URLSearchParams(activeFrame.contentWindow.location.search).get("render") !== current.requestId || markers.some((marker) => !current.markers.includes(marker))) throw new Error("The Studio preview measurement does not belong to the current rendered draft.");
			return measurePreviewFrame(activeFrame, markers, signal);
		},
		render: async (payload, signal) => {
			const sameDigestPredecessors = [...attempts].filter(({ digest }) => digest === payload.draftDigest);
			if (sameDigestPredecessors.length > 0) {
				await Promise.all(sameDigestPredecessors.map(cancelAttempt));
				for (const predecessor of sameDigestPredecessors) {
					attempts.delete(predecessor);
					if (loadedAttempt === predecessor) loadedAttempt = void 0;
				}
				throwIfAborted(signal);
			}
			const generation = ++navigationGeneration;
			const attempt = {
				accepted: false,
				digest: payload.draftDigest
			};
			attempts.add(attempt);
			loaded = void 0;
			activeFrame.hidden = true;
			let aborted = signal.aborted;
			const cancel = () => cancelAttempt(attempt);
			const onAbort = () => {
				aborted = true;
				cancel().catch(() => void 0);
			};
			signal.addEventListener("abort", onAbort, { once: true });
			try {
				const result = await previewPort.render(payload, previewContext(opened, "render"));
				if (aborted || signal.aborted) throw abortError();
				await navigate(result.value, signal, generation);
				throwIfAborted(signal);
				loaded = result.value;
				attempt.accepted = true;
				loadedAttempt = attempt;
				return result.value;
			} catch (error) {
				await cancel();
				attempts.delete(attempt);
				throw error;
			} finally {
				signal.removeEventListener("abort", onAbort);
			}
		}
	});
	host.onDispose(({ draftDigest }) => {
		const digest = draftDigest ?? loaded?.draftDigest;
		const attempt = loadedAttempt !== void 0 && (digest === void 0 || loadedAttempt.digest === digest) ? loadedAttempt : [...attempts].find((candidate) => candidate.accepted && candidate.digest === digest);
		loaded = void 0;
		if (attempt !== void 0) {
			if (loadedAttempt === attempt) loadedAttempt = void 0;
			cancelAttempt(attempt).catch(() => void 0).finally(() => attempts.delete(attempt));
		}
	});
	host.onSelect(({ nodeId, reveal }) => {
		if (loaded === void 0 || reveal !== true) return;
		const marker = Object.entries(loaded.markerMap).find(([, id]) => id === nodeId)?.[0];
		if (marker === void 0) return;
		previewMarkerElement(activeFrame, marker)?.scrollIntoView({
			block: "nearest",
			inline: "nearest"
		});
		geometry.refreshAfterLayout();
	});
	host.onViewport(({ height, viewport, width: requestedWidth }) => {
		const width = requestedWidth ?? (viewport === void 0 ? void 0 : {
			compact: 360,
			medium: 768,
			expanded: 1440
		}[viewport]);
		if (width !== void 0) {
			activeFrame.style.inlineSize = `${String(width)}px`;
			if (stagingFrame !== void 0) stagingFrame.style.inlineSize = `${String(width)}px`;
		}
		if (height !== void 0) {
			activeFrame.style.blockSize = `${String(height)}px`;
			if (stagingFrame !== void 0) stagingFrame.style.blockSize = `${String(height)}px`;
		}
		geometry.refreshAfterLayout();
	});
	host.announce();
	return {
		binding: {
			client,
			stage: async (draft, { signal }) => {
				await save();
				await shell.updateComplete;
				throwIfAborted(signal);
				if (handle.session.dirty) throw new Error("The preview draft is not the current accepted artifact revision.");
				if (draft.revision !== handle.revision) throw new Error("The preview intent was superseded by the accepted artifact revision.");
				return {
					artifactId: draft.id,
					draftDigest: await computePreviewDraftDigest(draft, { subtle: crypto.subtle }),
					draftRevision: draft.revision
				};
			}
		},
		dispose: () => {
			if (disposed) return;
			disposed = true;
			navigationGeneration += 1;
			geometry.dispose();
			activeFrame.hidden = true;
			stagingFrame?.remove();
			stagingFrame = void 0;
			loaded = void 0;
			loadedAttempt = void 0;
			for (const attempt of attempts) cancelAttempt(attempt).catch(() => void 0);
			attempts.clear();
			host.teardown("studio.preview/session-ended");
		}
	};
}
function createStagingPreviewFrame(active) {
	const candidate = document.createElement("iframe");
	const omitted = /* @__PURE__ */ new Set([
		"data-studio-preview",
		"hidden",
		"slot",
		"src"
	]);
	for (const attribute of active.attributes) if (!omitted.has(attribute.name)) candidate.setAttribute(attribute.name, attribute.value);
	candidate.hidden = true;
	return candidate;
}
/**
* Coalesce volatile same-origin layout signals into the Studio shell's explicit remeasurement seam.
*/
function observePreviewGeometry(frame, shell) {
	let disposed = false;
	let animationFrame;
	let settlementFrame;
	let innerDispose = () => void 0;
	const refresh = () => {
		if (disposed || animationFrame !== void 0) return;
		animationFrame = window.requestAnimationFrame(() => {
			animationFrame = void 0;
			if (!disposed) shell.refreshPreviewGeometry();
		});
	};
	const refreshAfterLayout = () => {
		refresh();
		if (disposed || settlementFrame !== void 0) return;
		settlementFrame = window.requestAnimationFrame(() => {
			settlementFrame = void 0;
			refresh();
		});
	};
	const attachInner = () => {
		innerDispose();
		try {
			const previewWindow = frame.contentWindow;
			const previewDocument = frame.contentDocument;
			if (previewWindow === null || previewDocument === null || previewWindow.location.origin !== window.location.origin) return;
			const observer = new ResizeObserver(refreshAfterLayout);
			if (previewDocument.documentElement !== null) observer.observe(previewDocument.documentElement);
			if (previewDocument.body !== null) observer.observe(previewDocument.body);
			const viewport = previewWindow.visualViewport;
			previewWindow.addEventListener("resize", refreshAfterLayout, { passive: true });
			previewWindow.addEventListener("scroll", refreshAfterLayout, { passive: true });
			previewDocument.addEventListener("load", refreshAfterLayout, true);
			viewport?.addEventListener("resize", refreshAfterLayout, { passive: true });
			viewport?.addEventListener("scroll", refreshAfterLayout, { passive: true });
			previewDocument.fonts?.ready.then(refreshAfterLayout, () => void 0);
			innerDispose = () => {
				observer.disconnect();
				previewWindow.removeEventListener("resize", refreshAfterLayout);
				previewWindow.removeEventListener("scroll", refreshAfterLayout);
				previewDocument.removeEventListener("load", refreshAfterLayout, true);
				viewport?.removeEventListener("resize", refreshAfterLayout);
				viewport?.removeEventListener("scroll", refreshAfterLayout);
			};
			refreshAfterLayout();
		} catch {}
	};
	const frameObserver = new ResizeObserver(refreshAfterLayout);
	frameObserver.observe(frame);
	frame.addEventListener("load", attachInner);
	window.addEventListener("resize", refreshAfterLayout, { passive: true });
	window.addEventListener("scroll", refreshAfterLayout, { passive: true });
	window.visualViewport?.addEventListener("resize", refreshAfterLayout, { passive: true });
	window.visualViewport?.addEventListener("scroll", refreshAfterLayout, { passive: true });
	attachInner();
	return {
		dispose: () => {
			if (disposed) return;
			disposed = true;
			innerDispose();
			frameObserver.disconnect();
			frame.removeEventListener("load", attachInner);
			window.removeEventListener("resize", refreshAfterLayout);
			window.removeEventListener("scroll", refreshAfterLayout);
			window.visualViewport?.removeEventListener("resize", refreshAfterLayout);
			window.visualViewport?.removeEventListener("scroll", refreshAfterLayout);
			if (animationFrame !== void 0) window.cancelAnimationFrame(animationFrame);
			if (settlementFrame !== void 0) window.cancelAnimationFrame(settlementFrame);
		},
		refreshAfterLayout
	};
}
function previewContext(opened, operation) {
	return {
		operationId: `studio.operation/preview.${operation}`,
		protocolVersion: opened.protocolVersion,
		requestId: `requests/preview-${operation}-${crypto.randomUUID()}`,
		resourceContextKey: opened.resourceContextKey,
		sessionGeneration: opened.sessionGeneration
	};
}
async function loadPreviewFrame(frame, url, rendered, signal) {
	await new Promise((resolve, reject) => {
		let settled = false;
		const finish = (error) => {
			if (settled) return;
			settled = true;
			window.clearTimeout(timeout);
			frame.removeEventListener("load", onLoad);
			if (error === void 0) resolve();
			else reject(error);
		};
		const onLoad = () => {
			try {
				throwIfAborted(signal);
				const document = frame.contentDocument;
				const location = frame.contentWindow?.location;
				if (document === null || location === void 0 || location.origin !== window.location.origin || location.pathname !== url.pathname || new URLSearchParams(location.search).get("render") !== rendered.requestId || document.contentType !== "text/html" || document.querySelector("[data-kis-surface=\"core.administrator.content-editor\"]") === null) throw new Error("The Studio preview document did not load its same-origin HTML contract.");
				const actual = Array.from(document.querySelectorAll("[data-studio-preview-marker]")).map((element) => element.getAttribute("data-studio-preview-marker"));
				if (actual.some((marker) => marker === null) || actual.length !== rendered.markers.length || rendered.markers.some((marker) => !actual.includes(marker))) throw new Error("The Studio preview marker inventory does not match the rendered response.");
				finish();
			} catch (error) {
				finish(error instanceof Error ? error : /* @__PURE__ */ new Error("The Studio preview document was invalid."));
			}
		};
		const timeout = window.setTimeout(() => finish(/* @__PURE__ */ new Error("The Studio preview document did not finish loading.")), 1e4);
		frame.addEventListener("load", onLoad);
		frame.src = url.toString();
	});
}
async function measurePreviewFrame(frame, markers, signal) {
	throwIfAborted(signal);
	const document = frame.contentDocument;
	const previewWindow = frame.contentWindow;
	if (document === null || previewWindow === null || previewWindow.location.origin !== window.location.origin) throw new Error("The Studio preview frame is not available for same-origin measurement.");
	const requested = new Set(markers);
	const rects = Object.create(null);
	const elements = document.querySelectorAll("[data-studio-preview-marker]");
	if (elements.length > 1e5) throw new Error("The Studio preview marker inventory is too large.");
	for (const element of elements) {
		const marker = element.getAttribute("data-studio-preview-marker");
		if (marker === null || !requested.has(marker)) continue;
		const fragments = element.getClientRects();
		if (fragments.length > 1e3) throw new Error("A Studio preview marker has too many rectangles.");
		for (const rect of fragments) {
			const member = {
				height: cssExtent(rect.height),
				width: cssExtent(rect.width),
				x: cssCoordinate(rect.x),
				y: cssCoordinate(rect.y)
			};
			(rects[marker] ??= []).push(member);
		}
	}
	throwIfAborted(signal);
	return {
		rects,
		viewport: {
			devicePixelRatio: positiveRatio(previewWindow.devicePixelRatio),
			height: cssExtent(previewWindow.innerHeight),
			scrollX: cssCoordinate(previewWindow.scrollX),
			scrollY: cssCoordinate(previewWindow.scrollY),
			width: cssExtent(previewWindow.innerWidth)
		}
	};
}
function previewMarkerElement(frame, marker) {
	for (const element of frame.contentDocument?.querySelectorAll("[data-studio-preview-marker]") ?? []) if (element.getAttribute("data-studio-preview-marker") === marker) return element;
}
function cssCoordinate(value) {
	if (!Number.isFinite(value) || Math.abs(value) > 1e8) throw new Error("A Studio preview coordinate is outside the protocol bounds.");
	return value;
}
function cssExtent(value) {
	if (!Number.isFinite(value) || value < 0 || value > 1e8) throw new Error("A Studio preview extent is outside the protocol bounds.");
	return value;
}
function positiveRatio(value) {
	if (!Number.isFinite(value) || value <= 0 || value > 100) throw new Error("The Studio preview pixel ratio is outside the protocol bounds.");
	return value;
}
function throwIfAborted(signal) {
	if (signal.aborted) throw abortError();
}
function abortError() {
	return new DOMException("The Studio preview operation was aborted.", "AbortError");
}
function localPreviewBridge(origin) {
	const clientSource = new LocalMessageSource();
	const hostSource = new LocalMessageSource();
	const clientTarget = { postMessage: (data) => clientSource.emit({
		data,
		origin,
		source: hostTarget
	}) };
	const hostTarget = { postMessage: (data) => hostSource.emit({
		data,
		origin,
		source: clientTarget
	}) };
	return {
		clientSource,
		clientTarget,
		hostSource,
		hostTarget
	};
}
var LocalMessageSource = class {
	listeners = /* @__PURE__ */ new Set();
	addEventListener(_type, listener) {
		this.listeners.add(listener);
	}
	removeEventListener(_type, listener) {
		this.listeners.delete(listener);
	}
	emit(event) {
		queueMicrotask(() => this.listeners.forEach((listener) => listener(event)));
	}
};
//#endregion
export { setupStudioComposition };
