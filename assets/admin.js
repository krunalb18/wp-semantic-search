document.addEventListener('DOMContentLoaded', function () {
	var startButton = document.getElementById('ss-start-index');
	var progressWrap = document.getElementById('ss-progress-wrap');
	var progressBar = document.getElementById('ss-progress-bar');
	var progressLabel = document.getElementById('ss-progress-label');
	var testButton = document.getElementById('ss-test-connection');
	var testResult = document.getElementById('ss-test-result');
	var forceCheckbox = document.getElementById('ss-force-reindex');

	if (typeof Embedix === 'undefined') {
		return;
	}

	var i18n = Embedix.i18n || {};

	if (testButton && testResult) {
		testButton.addEventListener('click', async function () {
			testButton.disabled = true;
			testResult.textContent = i18n.testing || 'Testing...';
			testResult.style.color = '';

			try {
				var res = await fetch(Embedix.restUrl + 'test-connection', {
					method: 'POST',
					headers: {
						'X-WP-Nonce': Embedix.nonce,
						'Content-Type': 'application/json',
					},
				});

				var data = await res.json();
				if (data.success) {
					testResult.textContent = (i18n.testOk || 'Connection successful!') + ' (' + data.dimensions + ' dimensions)';
					testResult.style.color = 'green';
				} else {
					testResult.textContent = (i18n.testFail || 'Connection failed. Check your API key and try again.') + ' ' + (data.message || '');
					testResult.style.color = 'red';
				}
			} catch (error) {
				testResult.textContent = i18n.testFail || 'Connection failed. Check your API key and try again.';
				testResult.style.color = 'red';
			} finally {
				testButton.disabled = false;
			}
		});
	}

	if (!startButton || !progressWrap || !progressBar || !progressLabel) {
		return;
	}

	async function pollStatus() {
		try {
			var res = await fetch(Embedix.restUrl + 'index-status', {
				headers: {
					'X-WP-Nonce': Embedix.nonce,
				},
			});

			var data = await res.json();
			var total = Number(data.total || 0);
			var done = Number(data.done || 0);
			var pct = total > 0 ? Math.round((done / total) * 100) : 0;

			progressBar.style.width = pct + '%';
			progressLabel.textContent = done + ' / ' + total + ' (' + pct + '%)';

			if (data.running) {
				setTimeout(pollStatus, 2000);
			} else {
				progressLabel.textContent = i18n.indexDone || 'Indexing complete!';
				startButton.disabled = false;
			}
		} catch (error) {
			setTimeout(pollStatus, 4000);
		}
	}

	startButton.addEventListener('click', async function () {
		startButton.disabled = true;
		progressWrap.style.display = 'block';

		var forceReindex = forceCheckbox ? forceCheckbox.checked : false;

		try {
			await fetch(Embedix.restUrl + 'start-index', {
				method: 'POST',
				headers: {
					'X-WP-Nonce': Embedix.nonce,
					'Content-Type': 'application/json',
				},
				body: JSON.stringify({ force: forceReindex }),
			});
			pollStatus();
		} catch (error) {
			progressLabel.textContent = i18n.indexStartFail || 'Failed to start indexing. Please try again.';
			startButton.disabled = false;
		}
	});
});
