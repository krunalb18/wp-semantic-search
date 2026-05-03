import { useState } from '@wordpress/element';

export default function SearchBlock({ attributes }) {
	const { placeholder = 'Search...' } = attributes || {};
	const [query, setQuery] = useState('');
	const [results, setResults] = useState([]);
	const [loading, setLoading] = useState(false);

	const runSearch = async () => {
		if (!query.trim()) {
			return;
		}

		setLoading(true);
		try {
			const restUrl = typeof wpApiSettings !== 'undefined' && wpApiSettings.root ? wpApiSettings.root : '/wp-json/';
			const response = await fetch(`${restUrl}embedix/v1/search?q=${encodeURIComponent(query)}&limit=10`);
			const data = await response.json();
			setResults(data.results || []);
		} finally {
			setLoading(false);
		}
	};

	return (
		<div className="ss-search-block">
			<div className="ss-search-input-row">
				<input
					type="text"
					value={query}
					onChange={(e) => setQuery(e.target.value)}
					onKeyDown={(e) => e.key === 'Enter' && runSearch()}
					placeholder={placeholder}
				/>
				<button onClick={runSearch} disabled={loading}>
					{loading ? 'Searching...' : 'Search'}
				</button>
			</div>

			{results.length > 0 && (
				<ul className="ss-results">
					{results.map((result) => (
						<li key={result.post_id}>
							<a href={result.url}>{result.title}</a>
							<p>{result.excerpt}</p>
							<small>Relevance: {(Number(result.score || 0) * 100).toFixed(0)}%</small>
						</li>
					))}
				</ul>
			)}
		</div>
	);
}
