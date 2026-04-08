(() => {
	if (typeof wp === 'undefined' || !wp.blocks || !wp.element || !wp.blockEditor || !wp.components) {
		return;
	}

	const { registerBlockType } = wp.blocks;
	const { createElement } = wp.element;
	const { InspectorControls } = wp.blockEditor;
	const { PanelBody, TextControl } = wp.components;

	registerBlockType('ai-semantic-search-for-posts/search-block', {
		title: 'AI Semantic Search for Posts',
		icon: 'search',
		category: 'widgets',
		attributes: {
			placeholder: {
				type: 'string',
				default: 'Search...',
			},
		},
		edit: (props) => {
			const placeholder = props.attributes.placeholder || 'Search...';

			return createElement(
				'div',
				{ className: props.className },
				createElement(
					InspectorControls,
					null,
					createElement(
						PanelBody,
						{ title: 'Search Settings', initialOpen: true },
						createElement(TextControl, {
							label: 'Placeholder',
							value: placeholder,
							onChange: (value) => props.setAttributes({ placeholder: value }),
						})
					)
				),
				createElement('div', { className: 'components-placeholder' },
					createElement('p', null, 'Semantic Search block renders on frontend.'),
					createElement('p', null, 'Placeholder: ' + placeholder)
				)
			);
		},
		save: () => null,
	});
})();
