(() => {
	'use strict';
	for (const output of document.querySelectorAll('.inkos-price')) {
		const form = output.closest('form');
		if (!form) continue;
		const quote = form.querySelector('[name="inkos_quote_id"]');
		let timer;
		let revision = 0;
		const update = () => {
			const current = ++revision;
			clearTimeout(timer);
			quote.value = '';
			timer = setTimeout(async () => {
				const variation = form.querySelector('[name="variation_id"]')?.value;
				const product = output.dataset.variable === '1' ? Number(variation) : Number(output.dataset.product);
				const quantity = Number(form.querySelector('[name="quantity"]')?.value || 1);
				if (!product || !Number.isInteger(quantity) || quantity <= 0) {
					output.textContent = 'Select a configuration and quantity.';
					return;
				}
				output.textContent = 'Calculating print price…';
				try {
					const response = await fetch(output.dataset.url, {
						method: 'POST', credentials: 'same-origin',
						body: new URLSearchParams({ product, quantity })
					});
					const json = await response.json();
					if (current !== revision) return;
					if (!json.success) throw new Error(json.data?.message || 'Print pricing is unavailable.');
					if (!json.data.mapped) {
						output.textContent = '';
						return;
					}
					quote.value = json.data.quoteId;
					output.textContent = json.data.formatted + ' for ' + json.data.quantity + ' · valid until ' + new Date(json.data.expiresAt).toLocaleTimeString();
				} catch (error) {
					if (current === revision) output.textContent = error.message;
				}
			}, 180);
		};
		form.addEventListener('input', update);
		form.addEventListener('change', update);
		if (window.jQuery) window.jQuery(form).on('found_variation reset_data', update);
		update();
	}
})();
