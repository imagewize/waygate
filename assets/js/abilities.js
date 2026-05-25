import { registerAbility, registerAbilityCategory } from '@wordpress/abilities';

registerAbilityCategory( 'waygate-editor', {
	label: 'Waygate Editor',
	description: 'Pattern insertion and management in the block editor',
} );

registerAbility( {
	name: 'waygate/insert-pattern',
	label: 'Insert Pattern',
	description: 'Insert a block pattern at the current cursor position in the editor',
	category: 'waygate-editor',
	input_schema: {
		type: 'object',
		properties: {
			slug: { type: 'string', description: 'Pattern slug to insert, e.g. "elayne/hero-centered"' },
		},
		required: [ 'slug' ],
	},
	output_schema: {
		type: 'object',
		properties: {
			success: { type: 'boolean' },
		},
		required: [ 'success' ],
	},
	callback: async ( { slug } ) => {
		const block = wp.blocks.createBlock( 'core/pattern', { slug } );
		await wp.data.dispatch( 'core/block-editor' ).insertBlock( block );
		return { success: true };
	},
} );
