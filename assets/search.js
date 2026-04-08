document.addEventListener('DOMContentLoaded', function () {
	var widgets = document.querySelectorAll('.ss-shortcode-search');
	if (!widgets.length || typeof SSFront === 'undefined' || !SSFront.restUrl) {
		return;
	}

	widgets.forEach(function (container) {
		var placeholder = container.getAttribute('data-placeholder') || 'Search...';
		container.innerHTML = '';

		var wrapper = document.createElement('div');
		wrapper.className = 'ss-search-widget';

		var row = document.createElement('div');
		row.className = 'ss-search-row';

		var input = document.createElement('input');
		input.type = 'text';
		input.placeholder = placeholder;

		var button = document.createElement('button');
		button.type = 'button';
		button.textContent = 'Search';

		var results = document.createElement('div');
		results.className = 'ss-search-results';

		var renderResults = function (items) {
			results.innerHTML = '';
			if (!Array.isArray(items) || !items.length) {
				results.innerHTML = '<p class="ss-muted">No results found.</p>';
				return;
			}

			var list = document.createElement('ul');
			list.className = 'ss-results';

			items.forEach(function (item) {
				var li = document.createElement('li');
				li.className = 'ss-result-item';
				var title = document.createElement('a');
				title.href = item.url || '#';
				title.textContent = item.title || 'Untitled';

				var match = document.createElement('span');
				match.className = 'ss-match-word';
				match.textContent = item.matched_word ? 'Match: ' + item.matched_word : '';

				var excerpt = document.createElement('p');
				excerpt.textContent = item.excerpt || '';

				li.appendChild(title);
				if (item.matched_word) {
					li.appendChild(match);
				}
				li.appendChild(excerpt);
				list.appendChild(li);
			});

			results.appendChild(list);
		};

		var runSearch = async function () {
			var q = input.value.trim();
			if (!q) {
				return;
			}

			button.disabled = true;
			button.textContent = 'Searching...';
			results.innerHTML = '<p class="ss-muted">Searching...</p>';

			try {
				var url = SSFront.restUrl + '?q=' + encodeURIComponent(q) + '&limit=10';
				var response = await fetch(url, { credentials: 'same-origin' });
				var data = await response.json();
				renderResults(data.results || []);
			} catch (error) {
				results.innerHTML = '<p class="ss-error">Search failed. Please try again.</p>';
			} finally {
				button.disabled = false;
				button.textContent = 'Search';
			}
		};

		button.addEventListener('click', runSearch);
		input.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				runSearch();
			}
		});

		row.appendChild(input);
		row.appendChild(button);
		wrapper.appendChild(row);
		wrapper.appendChild(results);
		container.appendChild(wrapper);
	});
});
