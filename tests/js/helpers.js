'use strict';

// Trimmed from Square Terminal's dependency-free DOM, clock and fetch helpers.
class FakeElement {
	constructor(tagName) {
		this.tagName = tagName;
		this.attributes = {};
		this.children = [];
		this.listeners = {};
		this.style = {};
		this.textContent = '';
		this.className = '';
		this.value = '';
		this.hidden = false;
		this.disabled = false;
		this.checked = false;
	}
	setAttribute(name, value) { this.attributes[name] = String(value); }
	getAttribute(name) { return Object.hasOwn(this.attributes, name) ? this.attributes[name] : null; }
	appendChild(child) {
		child.parentNode = this;
		this.children.push(child);
		return child;
	}
	removeChild(child) {
		this.children.splice(this.children.indexOf(child), 1);
		child.parentNode = null;
		return child;
	}
	get firstChild() { return this.children[0] || null; }
	set textContent(value) {
		this.children.forEach(function (child) { child.parentNode = null; });
		this.children = [];
		this.text = String(value);
	}
	get textContent() { return this.text + this.children.map(function (child) { return child.textContent; }).join(''); }
	addEventListener(type, handler) {
		(this.listeners[type] = this.listeners[type] || []).push(handler);
	}
	dispatch(type, target) {
		(this.listeners[type] || []).forEach(function (handler) { handler({ target: target }); });
		if (this.parentNode) { this.parentNode.dispatch(type, target); }
	}
	click() { if (!this.disabled) { this.dispatch('click', this); } }
	matches(selector) {
		if (selector.charAt(0) === '#') { return this.getAttribute('id') === selector.slice(1); }
		if (selector.charAt(0) === '.') { return this.className.split(/\s+/).includes(selector.slice(1)); }
		var attr = selector.match(/^(\w+)\[([^=]+)="([^"]*)"\](:checked)?$/);
		if (attr) {
			return this.tagName === attr[1] && this.getAttribute(attr[2]) === attr[3] && (!attr[4] || this.checked);
		}
		return this.tagName === selector;
	}
	querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
	querySelectorAll(selector) {
		var out = [];
		function walk(node) {
			node.children.forEach(function (child) {
				if (child.matches(selector)) { out.push(child); }
				walk(child);
			});
		}
		walk(this);
		return out;
	}
}

function buildDom(opts) {
	var doc = new FakeElement('document');
	doc.createElement = function (tag) { return new FakeElement(tag); };
	doc.body = doc.appendChild(new FakeElement('body'));
	var root = doc.body.appendChild(new FakeElement('div'));
	root.setAttribute('id', 'wctwc-payment-interface');
	var attrs = {
		'order-id': '99', 'order-token': 'token+&123', 'default-station': 'STATION1',
		'lock-station': '0', resume: opts.resume ? '1' : '0', pos: opts.pos || '0',
		'gateway-id': 'windcave_terminal'
	};
	Object.keys(attrs).forEach(function (key) { root.setAttribute('data-' + key, attrs[key]); });
	var els = {};
	function add(key, tag, className, parent) {
		var node = new FakeElement(tag);
		node.className = 'wctwc-' + className;
		els[key] = (parent || root).appendChild(node);
		return node;
	}
	add('station', 'select', 'station-select').value = opts.station === undefined ? 'STATION1' : opts.station;
	els.station.setAttribute('id', 'wctwc-station-select');
	add('primary', 'button', 'primary-action').setAttribute('data-wctwc-mode', opts.resume ? 'cancel' : 'start');
	add('aside', 'button', 'abandon').hidden = true;
	add('prompt', 'div', 'prompt').hidden = true;
	add('line1', 'p', 'prompt-line1', els.prompt);
	add('line2', 'p', 'prompt-line2', els.prompt);
	add('buttons', 'div', 'prompt-buttons', els.prompt);
	add('status', 'div', 'payment-status');
	add('logging', 'div', 'logging-section');
	add('toggle', 'button', 'toggle-log', els.logging).setAttribute('data-expanded', 'false');
	add('copy', 'button', 'copy-log', els.logging);
	add('clear', 'button', 'clear-log', els.logging);
	add('logContent', 'div', 'log-content', els.logging).style.display = 'none';
	add('logText', 'textarea', 'payment-log-textarea', els.logContent);
	els.pay = doc.body.appendChild(new FakeElement('button'));
	els.pay.setAttribute('id', 'place_order');
	['gateway', 'otherGateway'].forEach(function (key) {
		var radio = doc.body.appendChild(new FakeElement('input'));
		radio.name = 'payment_method';
		radio.setAttribute('name', radio.name);
		radio.value = key === 'gateway' ? attrs['gateway-id'] : 'cod';
		radio.setAttribute('value', radio.value);
		radio.checked = key === 'gateway';
		els[key] = radio;
	});
	return { doc: doc, root: root, els: els };
}

function createClock() {
	var seq = 0;
	var timers = {};
	var current = 0;
	var delays = [];
	return {
		delays: delays,
		setTimeout: function (fn, delay) {
			var id = ++seq;
			timers[id] = { fn: fn, at: current + delay };
			delays.push(delay);
			return id;
		},
		clearTimeout: function (id) { delete timers[id]; },
		now: function () { return current; },
		setNow: function (value) { current = value; },
		pending: function () { return Object.keys(timers).length; },
		lastDelay: function () { return delays[delays.length - 1]; },
		runNext: function () {
			var ids = Object.keys(timers);
			if (!ids.length) { return false; }
			ids.sort(function (a, b) { return timers[a].at - timers[b].at; });
			var id = ids[0];
			var timer = timers[id];
			current = Math.max(current, timer.at);
			delete timers[id];
			timer.fn();
			return true;
		}
	};
}

function createFetch() {
	var calls = [];
	var outstanding = [];
	return {
		calls: calls,
		impl: function (url, options) {
			var params = {};
			options.body.split('&').forEach(function (pair) {
				var kv = pair.split('=');
				params[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1]);
			});
			calls.push({ url: url, options: options, params: params, action: params.action });
			return new Promise(function (resolve, reject) { outstanding.push({ resolve: resolve, reject: reject }); });
		},
		lastCall: function () { return calls[calls.length - 1]; },
		settle: function (body, httpStatus, index) {
			var deferred = outstanding.splice(index || 0, 1)[0];
			var status = httpStatus || 200;
			deferred.resolve({
				ok: status >= 200 && status < 300, status: status,
				text: function () { return Promise.resolve(JSON.stringify(body)); }
			});
		},
		fail: function () { outstanding.shift().reject(new Error('network')); }
	};
}

function makeConfig(overrides) {
	var i18n = {};
	[
		'startAction', 'cancelAction', 'abandonAction', 'sending', 'waiting', 'completing',
		'selectStation', 'declined', 'stationBusy', 'abandoned', 'timedOut', 'requestFailed',
		'verificationFailed', 'conflict', 'logsShown', 'logsHidden', 'copied', 'copyFailed'
	].forEach(function (key) { i18n[key] = '[' + key + ']'; });
	return Object.assign({
		ajaxUrl: 'https://store.test/wp-admin/admin-ajax.php',
		pollIntervalMs: 1500, pollTimeoutMs: 300000, i18n: i18n
	}, overrides);
}

function setup(createController, opts) {
	opts = opts || {};
	var dom = buildDom(opts);
	var clock = createClock();
	var fetch = createFetch();
	var config = makeConfig(opts.config);
	var navigations = [];
	var controller = createController({
		doc: dom.doc, root: dom.root, config: config, fetch: fetch.impl,
		setTimeout: clock.setTimeout, clearTimeout: clock.clearTimeout, now: clock.now,
		navigate: function (url) { navigations.push(url); }, clipboard: opts.clipboard
	});
	controller.init();
	return {
		controller: controller, dom: dom, els: dom.els, clock: clock,
		fetch: fetch, config: config, navigations: navigations
	};
}

async function flush() {
	for (var i = 0; i < 8; i++) { await Promise.resolve(); }
}

module.exports = { FakeElement, buildDom, createClock, createFetch, makeConfig, setup, flush };
