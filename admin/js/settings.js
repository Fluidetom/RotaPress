(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		/* Auto-grow body textarea. */
		var bodyField = document.getElementById('rp-email-body');
		if (bodyField) {
			function growBody() {
				bodyField.style.height = 'auto';
				bodyField.style.height = bodyField.scrollHeight + 'px';
			}
			growBody();
			bodyField.addEventListener('input', growBody);
		}

		/* Color picker live preview. */
		document.querySelectorAll('.rp-color-picker').forEach(function (picker) {
			function updateSwatch() {
				var swatch = picker.previousElementSibling;
				if (swatch && swatch.classList.contains('rp-color-swatch')) {
					swatch.style.background = picker.value;
				}
			}
			picker.addEventListener('input', updateSwatch);
			picker.addEventListener('change', updateSwatch);
		});

		/* Test email button. */
		var testBtn    = document.getElementById('rp-test-email-btn');
		var testAddr   = document.getElementById('rp-test-email-addr');
		var testMode   = document.getElementById('rp-test-email-mode');
		var testStatus = document.getElementById('rp-test-email-status');

		if (testBtn && testAddr && testMode && testStatus && window.rotapress_settings) {
			var cfg = window.rotapress_settings;

			testBtn.addEventListener('click', function () {
				var email    = testAddr.value.trim();
				var modeVal  = testMode.value;
				if (!email) { testAddr.focus(); return; }

				var payload = { to: email };
				if (modeVal === 'dummy') {
					payload.mode = 'dummy';
				} else {
					payload.mode  = 'events';
					payload.count = parseInt(modeVal, 10);
				}

				testBtn.disabled = true;
				testStatus.textContent = cfg.i18n.sending;
				testStatus.className   = '';
				testStatus.style.color = '';

				fetch(cfg.api_base + '/test-email', {
					method:  'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce':   cfg.nonce
					},
					body: JSON.stringify(payload)
				})
				.then(function (r) {
					/* Capture error body before throwing so we can surface the message. */
					if (!r.ok) {
						return r.json().then(function (d) {
							throw new Error(d.message || ('HTTP ' + r.status));
						});
					}
					return r.json();
				})
				.then(function (data) {
					if (data.mode === 'events') {
						testStatus.textContent = cfg.i18n.sent_ok_n.replace('%d', data.sent);
					} else {
						testStatus.textContent = cfg.i18n.sent_ok;
					}
					testStatus.style.color = '#00a32a';
				})
				.catch(function (err) {
					testStatus.textContent = err.message || cfg.i18n.sent_fail;
					testStatus.style.color = '#d63638';
				})
				.finally(function () {
					testBtn.disabled = false;
				});
			});
		}
	});
})();
