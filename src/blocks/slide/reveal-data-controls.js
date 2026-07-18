import { Button, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { areValidRevealDataAttributes } from './reveal-data';

/**
 * Edit the strictly validated generic Reveal data-attribute list.
 *
 * Invalid drafts remain local and are never written into block attributes.
 *
 * @param {Object}   props          Component properties.
 * @param {Array}    props.value    Saved ordered attributes.
 * @param {Function} props.onChange Save a valid ordered list.
 * @return {Element} Attribute controls.
 */
export default function RevealDataControls( { value, onChange } ) {
	const [ draft, setDraft ] = useState( value );
	const isValid = areValidRevealDataAttributes( draft );

	useEffect( () => setDraft( value ), [ value ] );

	const update = ( index, field, nextValue ) => {
		setDraft(
			draft.map( ( attribute, position ) =>
				position === index
					? { ...attribute, [ field ]: nextValue }
					: attribute
			)
		);
	};
	const commit = () => {
		if ( isValid ) {
			onChange( draft );
			return;
		}
		setDraft( value );
	};
	const add = () => {
		let suffix = draft.length + 1;
		let name = `data-custom-${ suffix }`;
		const names = new Set( draft.map( ( attribute ) => attribute.name ) );
		while ( names.has( name ) ) {
			suffix += 1;
			name = `data-custom-${ suffix }`;
		}
		const next = [ ...draft, { name, value: '' } ];
		setDraft( next );
		onChange( next );
	};
	const remove = ( index ) => {
		const next = draft.filter( ( unused, position ) => position !== index );
		setDraft( next );
		onChange( next );
	};

	return (
		<div className="presenter-reveal-data-controls">
			<p>
				{ __(
					'Advanced Reveal data attributes. Typed slide settings cannot be overridden here.',
					'presenter'
				) }
			</p>
			{ draft.map( ( attribute, index ) => (
				<div className="presenter-reveal-data-control" key={ index }>
					<TextControl
						label={ sprintf(
							/* translators: %d: Attribute position. */
							__( 'Attribute %d name', 'presenter' ),
							index + 1
						) }
						value={ attribute.name }
						onChange={ ( nextValue ) =>
							update( index, 'name', nextValue )
						}
						onBlur={ commit }
						aria-invalid={ ! isValid }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Value', 'presenter' ) }
						value={ attribute.value }
						onChange={ ( nextValue ) =>
							update( index, 'value', nextValue )
						}
						onBlur={ commit }
						aria-invalid={ ! isValid }
						__nextHasNoMarginBottom
					/>
					<Button
						variant="secondary"
						isDestructive
						onClick={ () => remove( index ) }
					>
						{ __( 'Remove attribute', 'presenter' ) }
					</Button>
				</div>
			) ) }
			<Button variant="secondary" onClick={ add }>
				{ __( 'Add data attribute', 'presenter' ) }
			</Button>
		</div>
	);
}
