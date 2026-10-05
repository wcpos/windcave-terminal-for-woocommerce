(function () {
	'use strict';

	var STATES = {
		IDLE: 'idle', STARTING: 'starting', POLLING: 'polling',
		ANSWERING: 'answering', CANCELLING: 'cancelling', FINAL: 'final'
	};

	function createController(env) {
		var root = env.root;
		var doc = env.doc;
		var config = env.config;
		var strings = config.i18n;
		var current = STATES.IDLE;
		var sequence = 0;
		var timer = null;
		var pollInFlight = false;
		var startedAt = 0;
		var timedOut = false;
		var errorDelay = config.pollIntervalMs;
		var logs = [];
		var primary = root.querySelector('.wctwc-primary-action');
		var station = root.querySelector('.wctwc-station-select');
		var aside = root.querySelector('.wctwc-abandon');
		var status = root.querySelector('.wctwc-payment-status');
		var prompt = root.querySelector('.wctwc-prompt');
		var buttons = root.querySelector('.wctwc-prompt-buttons');
		var logContent = root.querySelector('.wctwc-log-content');
		var logText = root.querySelector('.wctwc-payment-log-textarea');
		var toggleLog = root.querySelector('.wctwc-toggle-log');
		var copyLog = root.querySelector('.wctwc-copy-log');

		function log(message) {
			logs.push(new Date(env.now()).toISOString() + ' ' + message);
			logText.value = logs.join('\n');
		}

		function clearTimer() {
			if (timer !== null) {
				env.clearTimeout(timer);
				timer = null;
			}
		}

		function setState(next) {
			clearTimer();
			sequence += 1;
			current = next;
			primary.disabled = next === STATES.STARTING || next === STATES.ANSWERING || next === STATES.CANCELLING;
			var promptButtons = buttons.querySelectorAll('button');
			for (var i = 0; i < promptButtons.length; i++) {
				promptButtons[i].disabled = next === STATES.ANSWERING || next === STATES.CANCELLING;
			}
		}

		function actionMode(mode) {
			primary.setAttribute('data-wctwc-mode', mode);
			primary.textContent = mode === 'cancel' ? strings.cancelAction : strings.startAction;
		}

		function renderPrompt(display) {
			root.querySelector('.wctwc-prompt-line1').textContent = display.line1;
			root.querySelector('.wctwc-prompt-line2').textContent = display.line2;
			while (buttons.firstChild) {
				buttons.removeChild(buttons.firstChild);
			}
			display.buttons.forEach(function (item) {
				var button = doc.createElement('button');
				button.type = 'button';
				button.className = 'button wctwc-prompt-button';
				button.setAttribute('data-name', item.name);
				button.textContent = item.label;
				button.disabled = current === STATES.ANSWERING || current === STATES.CANCELLING;
				button.addEventListener('click', function () {
					var label = item.label.trim().toUpperCase();
					if (label === 'CANCEL') {
						cancel();
					} else {
						answer(item.name, label === 'YES' || label === 'NO' ? label : (item.name === 'B1' ? 'YES' : 'NO'));
					}
				});
				buttons.appendChild(button);
			});
			prompt.hidden = !(display.line1 || display.line2 || display.buttons.length);
		}

		function request(action, extra) {
			var fields = {
				action: action,
				order_id: root.getAttribute('data-order-id'),
				order_token: root.getAttribute('data-order-token')
			};
			var headers = { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' };
			Object.keys(extra || {}).forEach(function (key) { fields[key] = extra[key]; });
			var body = Object.keys(fields).map(function (key) {
				return encodeURIComponent(key) + '=' + encodeURIComponent(fields[key]);
			}).join('&');
			if (root.getAttribute('data-pos') === '1') {
				headers['X-WCPOS'] = '1';
			}
			log('Sending ' + action + ' request.');
			return env.fetch(config.ajaxUrl, {
				method: 'POST', credentials: 'same-origin', headers: headers, body: body
			}).then(function (response) {
				return response.text().then(function (text) {
					var json = JSON.parse(text);
					log(action + ' response: ' + text);
					return { ok: response.ok && json.success, code: response.status, data: json.data };
				});
			}).catch(function () {
				log(action + ' request failed.');
				return { ok: false, code: 0, data: strings.requestFailed };
			});
		}

		function schedule(delay) {
			clearTimer();
			if (!timedOut) {
				timer = env.setTimeout(pollNow, delay);
			}
		}

		function handleResult(reply, wasCancelling) {
			var result = reply.data;
			if (result && result.prompt) {
				renderPrompt(result.prompt);
			}
			if (!reply.ok || result.status === 'error') {
				status.textContent = (typeof result === 'string' ? result : result && result.message) || strings.requestFailed;
				if (current === STATES.STARTING) {
					setState(STATES.IDLE);
					actionMode('start');
				} else if (current === STATES.POLLING || current === STATES.ANSWERING || current === STATES.CANCELLING) {
					setState(STATES.POLLING);
					actionMode('cancel');
					if (reply.code === 409) {
						errorDelay = config.pollIntervalMs;
						schedule(config.pollIntervalMs);
					} else {
						schedule(Math.min(errorDelay, 10000));
						errorDelay = Math.min(errorDelay * 2, 10000);
					}
				}
				return;
			}
			errorDelay = config.pollIntervalMs;
			switch (result.status) {
			case 'pending':
				setState(STATES.POLLING);
				actionMode('cancel');
				status.textContent = result.message || strings.waiting;
				if (wasCancelling && result.message) {
					aside.hidden = false;
				}
				schedule(config.pollIntervalMs);
				break;
			case 'paid':
			case 'already_paid':
				setState(STATES.FINAL);
				status.textContent = strings.completing;
				prompt.hidden = true;
				if (result.redirect_url) {
					env.navigate(result.redirect_url);
				}
				break;
			case 'declined':
			case 'station_busy':
				setState(STATES.FINAL);
				status.textContent = result.message || (result.status === 'declined' ? strings.declined : strings.stationBusy);
				actionMode('start');
				break;
			case 'abandoned':
				setState(STATES.FINAL);
				status.textContent = strings.abandoned;
				actionMode('start');
				aside.hidden = true;
				break;
			case 'idle':
				setState(STATES.IDLE);
				actionMode('start');
				break;
			case 'verification_failed':
			case 'conflict':
				setState(STATES.FINAL);
				status.textContent = result.status === 'conflict' ? strings.conflict : strings.verificationFailed;
				primary.disabled = true;
				break;
			}
		}

		function send(action, extra, wasCancelling) {
			var seq = sequence;
			return request(action, extra).then(function (reply) {
				if (seq === sequence) {
					handleResult(reply, wasCancelling);
				}
			});
		}

		function start() {
			if (current !== STATES.IDLE && current !== STATES.FINAL) { return; }
			if (!station.value) {
				status.textContent = strings.selectStation;
				return;
			}
			startedAt = env.now();
			timedOut = false;
			errorDelay = config.pollIntervalMs;
			aside.hidden = true;
			setState(STATES.STARTING);
			status.textContent = strings.sending;
			return send('wctwc_start_payment', { station: station.value });
		}

		function pollNow() {
			if (current !== STATES.POLLING || timedOut) { return; }
			if (env.now() - startedAt > config.pollTimeoutMs) {
				timedOut = true;
				setState(STATES.POLLING);
				status.textContent = strings.timedOut;
				aside.hidden = false;
				actionMode('cancel');
				return;
			}
			// Keep checking the deadline even when the outstanding fetch stalls.
			schedule(config.pollIntervalMs);
			if (pollInFlight) { return; }
			pollInFlight = true;
			var seq = sequence;
			return request('wctwc_poll_payment').then(function (reply) {
				pollInFlight = false;
				if (seq === sequence) {
					handleResult(reply, false);
				}
			});
		}

		function answer(name, value) {
			if (current !== STATES.POLLING) { return; }
			setState(STATES.ANSWERING);
			return send('wctwc_answer_prompt', { button: name, value: value });
		}

		function cancel() {
			if (current !== STATES.POLLING) { return; }
			setState(STATES.CANCELLING);
			return send('wctwc_cancel_payment', null, true);
		}

		function abandon() {
			if (current === STATES.IDLE) { return; }
			clearTimer();
			sequence += 1;
			return send('wctwc_abandon_payment');
		}

		function syncPayButton() {
			var pay = doc.querySelector('#place_order');
			var selected = doc.querySelector('input[name="payment_method"]:checked');
			if (pay) {
				pay.style.display = selected && selected.value === root.getAttribute('data-gateway-id') ? 'none' : '';
			}
		}

		function init() {
			if (root.wctwcBound) { return; }
			root.wctwcBound = true;
			if (!doc.wctwcPayGuardBound) {
				doc.wctwcPayGuardBound = true;
				doc.addEventListener('change', function (event) {
					if (event.target.name === 'payment_method') { syncPayButton(); }
				});
			}
			syncPayButton();
			if (toggleLog) {
				toggleLog.addEventListener('click', function () {
					var expanded = toggleLog.getAttribute('data-expanded') !== 'true';
					toggleLog.setAttribute('data-expanded', String(expanded));
					logContent.style.display = expanded ? '' : 'none';
					toggleLog.textContent = expanded ? strings.logsShown : strings.logsHidden;
				});
				copyLog.addEventListener('click', function () {
					if (env.clipboard) {
						env.clipboard.writeText(logText.value).then(function () {
							copyLog.textContent = strings.copied;
						}, function () { copyLog.textContent = strings.copyFailed; });
					} else {
						copyLog.textContent = strings.copyFailed;
					}
				});
				root.querySelector('.wctwc-clear-log').addEventListener('click', function () {
					logs = [];
					logText.value = '';
				});
			}
			// Ordinary checkout renders only logs, without the order-pay controls.
			if (!primary) { return; }
			primary.addEventListener('click', function () {
				if (primary.getAttribute('data-wctwc-mode') === 'cancel') { cancel(); }
				else { start(); }
			});
			aside.addEventListener('click', abandon);
			if (root.getAttribute('data-resume') === '1') {
				startedAt = env.now();
				setState(STATES.POLLING);
				actionMode('cancel');
				status.textContent = strings.waiting;
				pollNow();
			}
		}

		return {
			init: init, state: function () { return current; },
			start: start, cancel: cancel, abandon: abandon, answer: answer, pollNow: pollNow
		};
	}

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = { createController: createController, STATES: STATES };
	} else {
		var boot = function () {
			var roots = document.querySelectorAll('#wctwc-payment-interface');
			for (var i = 0; i < roots.length; i++) {
				if (!roots[i].wctwcBound) {
					createController({
						doc: document, root: roots[i], config: window.wctwcPaymentData,
						fetch: function (url, options) { return window.fetch(url, options); },
						setTimeout: function (fn, delay) { return window.setTimeout(fn, delay); },
						clearTimeout: function (id) { window.clearTimeout(id); },
						now: function () { return Date.now(); },
						navigate: function (url) { window.location.assign(url); },
						clipboard: window.navigator.clipboard
					}).init();
				}
			};
		};
		if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); }
		else { boot(); }
		if (window.jQuery) { window.jQuery(document.body).on('updated_checkout', boot); }
	}
}());
