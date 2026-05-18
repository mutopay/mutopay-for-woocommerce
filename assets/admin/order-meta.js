(function () {
	'use strict';

	if (typeof window.mutopayOrderMeta === 'undefined') {
		return;
	}
	var meta = window.mutopayOrderMeta;

	document.addEventListener('DOMContentLoaded', function () {
		var btn = document.getElementById('mutopay-recheck-status');
		if (!btn) {
			return;
		}
		var result = document.getElementById('mutopay-recheck-result');

		btn.addEventListener('click', function () {
			btn.disabled = true;
			btn.textContent = meta.i18n.checking;
			result.textContent = '';
			result.style.color = '';

			var body = 'action=mutopay_recheck_status' +
				'&order_id=' + encodeURIComponent(meta.orderId) +
				'&nonce=' + encodeURIComponent(meta.nonce);

			fetch(meta.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body
			})
				.then(function (r) { return r.json(); })
				.then(function (resp) {
					if (resp.success) {
						result.style.color = '#46b450';
						result.textContent = resp.data.message;
						if (resp.data.changed) {
							location.reload();
						}
					} else {
						result.style.color = '#a00';
						result.textContent = (resp.data && resp.data.message) || meta.i18n.checkFailed;
					}
					btn.disabled = false;
					btn.textContent = meta.i18n.recheck;
				})
				.catch(function () {
					result.style.color = '#a00';
					result.textContent = meta.i18n.requestFailed;
					btn.disabled = false;
					btn.textContent = meta.i18n.recheck;
				});
		});
	});
})();
