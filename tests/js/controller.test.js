'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { createController, STATES } = require('../../assets/js/payment.js');
const { setup, flush } = require('./helpers');

function pending(overrides) {
	return Object.assign({
		status: 'pending', txn_ref: 'TXN1', txn_status_id: 'P', retry_allowed: false, message: '',
		prompt: { line1: 'Confirm purchase', line2: '12.50', buttons: [{ name: 'B1', label: 'YES' }, { name: 'B2', label: 'NO' }] }
	}, overrides);
}

async function result(h, data, index) {
	h.fetch.settle({ success: true, data: data }, 200, index);
	await flush();
}

async function polling(opts, display) {
	const h = setup(createController, opts);
	h.controller.start();
	await result(h, pending(display));
	return h;
}

test('start posts station, order id and token, then polls on interval', async () => {
	const h = setup(createController, { station: 'STATION & TWO' });
	h.els.primary.click();
	assert.equal(h.controller.state(), STATES.STARTING);
	assert.equal(h.els.primary.disabled, true);
	assert.equal(h.els.status.textContent, h.config.i18n.sending);
	const call = h.fetch.lastCall();
	assert.equal(call.url, h.config.ajaxUrl);
	assert.equal(call.options.method, 'POST');
	assert.equal(call.options.credentials, 'same-origin');
	assert.match(call.options.headers['Content-Type'], /application\/x-www-form-urlencoded/);
	assert.deepEqual(call.params, {
		action: 'wctwc_start_payment', order_id: '99', order_token: 'token+&123', station: 'STATION & TWO'
	});
	h.controller.start();
	assert.equal(h.fetch.calls.length, 1);
	await result(h, pending());
	assert.equal(h.controller.state(), STATES.POLLING);
	assert.equal(h.els.primary.getAttribute('data-wctwc-mode'), 'cancel');
	assert.equal(h.els.primary.textContent, h.config.i18n.cancelAction);
	assert.equal(h.els.primary.disabled, false);
	assert.equal(h.clock.lastDelay(), 1500);
	h.clock.runNext();
	assert.equal(h.clock.now(), 1500);
	assert.deepEqual(h.fetch.lastCall().params, {
		action: 'wctwc_poll_payment', order_id: '99', order_token: 'token+&123'
	});
	await result(h, pending());
	assert.equal(h.clock.lastDelay(), 1500);
});

test('start without a station shows selectStation and makes no request', () => {
	const h = setup(createController, { station: '' });
	h.controller.start();
	assert.equal(h.els.status.textContent, h.config.i18n.selectStation);
	assert.equal(h.controller.state(), STATES.IDLE);
	assert.equal(h.fetch.calls.length, 0);
});

test('pending result renders DL1, DL2 and enabled buttons via textContent', async () => {
	const label = '<img src=x onerror=alert(1)>';
	const h = await polling({}, { prompt: { line1: '<b>DL1</b>', line2: '<i>DL2</i>', buttons: [{ name: 'B1', label }] } });
	assert.equal(h.els.prompt.hidden, false);
	assert.equal(h.els.line1.textContent, '<b>DL1</b>');
	assert.equal(h.els.line2.textContent, '<i>DL2</i>');
	const button = h.els.buttons.firstChild;
	assert.equal(button.textContent, label);
	assert.equal(button.children.length, 0);
	assert.equal(h.els.line1.children.length, 0);
	assert.equal(h.els.line2.children.length, 0);
	assert.equal(button.disabled, false);
	assert.equal(button.type, 'button');
	assert.equal(button.className, 'button wctwc-prompt-button');
	assert.equal(button.getAttribute('data-name'), 'B1');
	h.controller.pollNow();
	await result(h, pending({ prompt: { line1: '', line2: '', buttons: [] } }));
	assert.equal(h.els.prompt.hidden, true);
	assert.equal(h.els.buttons.children.length, 0);
});

test('prompt YES button posts answer B1 YES and returns to polling', async () => {
	const h = await polling();
	h.els.buttons.firstChild.click();
	assert.equal(h.controller.state(), STATES.ANSWERING);
	assert.ok(h.els.buttons.children.every(button => button.disabled));
	assert.deepEqual(h.fetch.lastCall().params, {
		action: 'wctwc_answer_prompt', order_id: '99', order_token: 'token+&123', button: 'B1', value: 'YES'
	});
	await result(h, pending());
	assert.equal(h.controller.state(), STATES.POLLING);
	assert.ok(h.els.buttons.children.every(button => !button.disabled));
	assert.equal(h.clock.pending(), 1);
});

test('prompt CANCEL button posts cancel, not answer', async () => {
	const h = await polling({}, { prompt: { line1: '', line2: '', buttons: [{ name: 'B2', label: ' cancel ' }] } });
	h.els.buttons.firstChild.click();
	assert.equal(h.controller.state(), STATES.CANCELLING);
	assert.equal(h.els.buttons.firstChild.disabled, true);
	assert.deepEqual(h.fetch.lastCall().params, {
		action: 'wctwc_cancel_payment', order_id: '99', order_token: 'token+&123'
	});
	assert.equal(h.fetch.calls.some(call => call.action === 'wctwc_answer_prompt'), false);
	await result(h, pending({ message: 'Terminal did not respond.' }));
	assert.equal(h.controller.state(), STATES.POLLING);
	assert.equal(h.els.status.textContent, 'Terminal did not respond.');
	assert.equal(h.els.aside.hidden, false);
	assert.equal(h.clock.pending(), 1);
});

test('paid navigates to redirect_url and stops polling', async () => {
	const h = await polling();
	h.clock.runNext();
	await result(h, { status: 'paid', redirect_url: 'https://store.test/received/99' });
	assert.equal(h.controller.state(), STATES.FINAL);
	assert.equal(h.els.status.textContent, h.config.i18n.completing);
	assert.equal(h.els.prompt.hidden, true);
	assert.deepEqual(h.navigations, ['https://store.test/received/99']);
	assert.equal(h.clock.pending(), 0);
	h.controller.pollNow();
	assert.equal(h.fetch.calls.length, 2);
});

test('declined shows message, returns to start mode, stops polling', async () => {
	const h = await polling();
	h.clock.runNext();
	await result(h, { status: 'declined', message: '<b>Declined</b>' });
	assert.equal(h.controller.state(), STATES.FINAL);
	assert.equal(h.els.status.textContent, '<b>Declined</b>');
	assert.equal(h.els.status.children.length, 0);
	assert.equal(h.els.primary.getAttribute('data-wctwc-mode'), 'start');
	assert.equal(h.els.primary.textContent, h.config.i18n.startAction);
	assert.equal(h.els.primary.disabled, false);
	assert.equal(h.clock.pending(), 0);
});

test('station_busy shows stationBusy message and allows a new start', async () => {
	const h = setup(createController);
	h.controller.start();
	await result(h, { status: 'station_busy' });
	assert.equal(h.els.status.textContent, h.config.i18n.stationBusy);
	assert.equal(h.controller.state(), STATES.FINAL);
	assert.equal(h.els.primary.getAttribute('data-wctwc-mode'), 'start');
	assert.equal(h.clock.pending(), 0);
	h.els.primary.click();
	assert.equal(h.controller.state(), STATES.STARTING);
	assert.equal(h.fetch.calls.length, 2);
});

test('network failure backs off 1500, 3000, 6000, 10000, 10000', async () => {
	const h = await polling();
	const delays = [];
	for (let i = 0; i < 5; i++) {
		h.clock.runNext();
		h.fetch.fail();
		await flush();
		delays.push(h.clock.lastDelay());
		assert.equal(h.controller.state(), STATES.POLLING);
		assert.equal(h.clock.pending(), 1);
		assert.equal(h.els.status.textContent, h.config.i18n.requestFailed);
	}
	assert.deepEqual(delays, [1500, 3000, 6000, 10000, 10000]);
	h.clock.runNext();
	await result(h, pending());
	assert.equal(h.clock.lastDelay(), 1500);
	h.clock.runNext();
	h.fetch.fail();
	await flush();
	assert.equal(h.clock.lastDelay(), 1500);
});

test('409 keeps normal cadence', async () => {
	const h = await polling();
	for (let i = 0; i < 3; i++) {
		h.clock.runNext();
		h.fetch.settle({ success: false, data: 'Busy' }, 409);
		await flush();
		assert.equal(h.clock.lastDelay(), 1500);
		assert.equal(h.els.status.textContent, 'Busy');
		assert.equal(h.controller.state(), STATES.POLLING);
	}
});

test('late reply after cancel is ignored', async () => {
	const h = await polling();
	h.clock.runNext();
	h.els.primary.click();
	assert.equal(h.fetch.lastCall().action, 'wctwc_cancel_payment');
	await result(h, pending({ message: 'Cancel pending.' }), 1);
	await result(h, { status: 'declined', message: 'Stale response' });
	assert.equal(h.controller.state(), STATES.POLLING);
	assert.equal(h.els.status.textContent, 'Cancel pending.');
	assert.equal(h.els.aside.hidden, false);
	assert.equal(h.clock.pending(), 1);
	h.clock.runNext();
	await result(h, { status: 'paid', redirect_url: '/received/99' });
	assert.deepEqual(h.navigations, ['/received/99']);
});

test('timeout stops polling and shows set-aside button', async () => {
	const h = await polling({ config: { pollTimeoutMs: 3000 } });
	h.clock.setNow(3001);
	h.clock.runNext();
	assert.equal(h.controller.state(), STATES.POLLING);
	assert.equal(h.els.status.textContent, h.config.i18n.timedOut);
	assert.equal(h.els.aside.hidden, false);
	assert.equal(h.els.primary.getAttribute('data-wctwc-mode'), 'cancel');
	assert.equal(h.els.primary.disabled, false);
	assert.equal(h.clock.pending(), 0);
	h.controller.pollNow();
	assert.equal(h.fetch.calls.length, 1);
	h.els.aside.click();
	assert.equal(h.fetch.lastCall().action, 'wctwc_abandon_payment');
	await result(h, { status: 'abandoned' });
	assert.equal(h.controller.state(), STATES.FINAL);
	assert.equal(h.els.aside.hidden, true);
	assert.equal(h.els.status.textContent, h.config.i18n.abandoned);
	assert.equal(h.els.primary.getAttribute('data-wctwc-mode'), 'start');
});

test('resume flag polls immediately on init', async () => {
	const h = setup(createController, { resume: true });
	assert.equal(h.controller.state(), STATES.POLLING);
	assert.equal(h.fetch.calls.length, 1);
	assert.equal(h.fetch.lastCall().action, 'wctwc_poll_payment');
	assert.equal(h.els.primary.getAttribute('data-wctwc-mode'), 'cancel');
	await result(h, pending());
	assert.equal(h.clock.lastDelay(), 1500);
});

test('verification_failed disables the primary action', async () => {
	const h = await polling();
	h.clock.runNext();
	await result(h, { status: 'verification_failed', message: 'Do not override the portal instruction.' });
	assert.equal(h.controller.state(), STATES.FINAL);
	assert.equal(h.els.primary.disabled, true);
	assert.equal(h.els.status.textContent, h.config.i18n.verificationFailed);
	assert.equal(h.clock.pending(), 0);
	h.els.primary.click();
	assert.equal(h.fetch.calls.length, 2);
});

test('X-WCPOS header sent only when data-pos is 1', async () => {
	for (const pos of ['1', '0', 'true', '']) {
		const h = await polling({ pos });
		h.controller.pollNow();
		for (const call of h.fetch.calls) {
			assert.equal(call.options.headers['X-WCPOS'], pos === '1' ? '1' : undefined);
			assert.equal(call.options.credentials, 'same-origin');
		}
	}
});

test('NO and custom prompt labels map to the terminal button values', async () => {
	for (const [name, label, value] of [['B1', ' no ', 'NO'], ['B1', 'Continue', 'YES'], ['B2', 'Back', 'NO']]) {
		const h = await polling({}, { prompt: { line1: '', line2: '', buttons: [{ name, label }] } });
		h.els.buttons.firstChild.click();
		assert.equal(h.fetch.lastCall().params.button, name);
		assert.equal(h.fetch.lastCall().params.value, value);
	}
});

test('only one poll is in flight and an unresolved poll still reaches the deadline', async () => {
	const h = await polling({ config: { pollTimeoutMs: 3000 } });
	h.controller.pollNow();
	h.controller.pollNow();
	h.clock.runNext();
	assert.equal(h.fetch.calls.length, 2);
	h.clock.setNow(3001);
	h.clock.runNext();
	assert.equal(h.els.status.textContent, h.config.i18n.timedOut);
	assert.equal(h.clock.pending(), 0);
	await result(h, pending());
	assert.equal(h.els.status.textContent, h.config.i18n.timedOut);
	assert.equal(h.clock.pending(), 0);
});

test('HTTP and application errors keep polling after poll, answer and cancel', async () => {
	for (const action of ['pollNow', 'answer', 'cancel']) {
		for (const failure of [{ success: false, data: 'HTTP failure' }, { success: true, data: { status: 'error', message: 'Terminal failure' } }]) {
			const h = await polling();
			for (let i = 0; i < 2; i++) {
				h.controller[action]('B1', 'YES');
				h.fetch.settle(failure, failure.success ? 200 : 500);
				await flush();
				assert.equal(h.controller.state(), STATES.POLLING);
				assert.equal(h.clock.lastDelay(), i === 0 ? 1500 : 3000);
				assert.equal(h.els.status.textContent, failure.success ? failure.data.message : failure.data);
			}
			h.clock.runNext();
			await result(h, { status: 'paid', redirect_url: '/received' });
			assert.deepEqual(h.navigations, ['/received']);
		}
	}
});

test('start failure restores idle and permits retry', async () => {
	const h = setup(createController);
	h.controller.start();
	h.fetch.settle({ success: false, data: 'Request rejected.' }, 403);
	await flush();
	assert.equal(h.controller.state(), STATES.IDLE);
	assert.equal(h.els.status.textContent, 'Request rejected.');
	assert.equal(h.els.primary.getAttribute('data-wctwc-mode'), 'start');
	assert.equal(h.els.primary.disabled, false);
	assert.equal(h.clock.pending(), 0);
});

test('already_paid, idle and conflict stop polling with their specified displays', async () => {
	for (const name of ['already_paid', 'idle', 'conflict']) {
		const h = await polling();
		h.controller.pollNow();
		await result(h, { status: name });
		assert.equal(h.clock.pending(), 0);
		assert.equal(h.controller.state(), name === 'idle' ? STATES.IDLE : STATES.FINAL);
		if (name === 'already_paid') {
			assert.equal(h.els.status.textContent, h.config.i18n.completing);
			assert.equal(h.els.prompt.hidden, true);
			assert.deepEqual(h.navigations, []);
		} else if (name === 'conflict') {
			assert.equal(h.els.status.textContent, h.config.i18n.conflict);
			assert.equal(h.els.primary.disabled, true);
		} else {
			assert.equal(h.els.primary.getAttribute('data-wctwc-mode'), 'start');
		}
	}
});

test('pay button follows the selected gateway and init binds only once', () => {
	const h = setup(createController);
	assert.equal(h.els.pay.style.display, 'none');
	h.els.gateway.checked = false;
	h.els.otherGateway.checked = true;
	h.els.otherGateway.dispatch('change', h.els.otherGateway);
	assert.equal(h.els.pay.style.display, '');
	h.els.gateway.checked = true;
	h.els.otherGateway.checked = false;
	h.els.gateway.dispatch('change', h.els.gateway);
	assert.equal(h.els.pay.style.display, 'none');
	h.controller.init();
	assert.equal(h.dom.root.wctwcBound, true);
	assert.equal(h.els.primary.listeners.click.length, 1);
	assert.equal(h.dom.doc.listeners.change.length, 1);
});

test('logs render request and result timestamps, toggle, copy and clear', async () => {
	const copied = [];
	const h = await polling({ clipboard: { writeText: text => { copied.push(text); return Promise.resolve(); } } });
	const lines = h.els.logText.value.split('\n');
	assert.equal(lines.length, 2);
	assert.match(lines[0], /^1970-01-01T00:00:00.000Z Sending wctwc_start_payment request\.$/);
	assert.match(lines[1], /wctwc_start_payment response: .*"status":"pending"/);
	h.els.toggle.click();
	assert.equal(h.els.logContent.style.display, '');
	assert.equal(h.els.toggle.textContent, h.config.i18n.logsShown);
	h.els.toggle.click();
	assert.equal(h.els.logContent.style.display, 'none');
	assert.equal(h.els.toggle.textContent, h.config.i18n.logsHidden);
	h.els.copy.click();
	await flush();
	assert.deepEqual(copied, [h.els.logText.value]);
	assert.equal(h.els.copy.textContent, h.config.i18n.copied);
	h.els.clear.click();
	assert.equal(h.els.logText.value, '');
	h.controller.pollNow();
	assert.equal(h.els.logText.value.split('\n').length, 1);
});
