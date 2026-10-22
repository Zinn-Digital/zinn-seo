/**
 * The SEO blocks in the block editor (and in Page Builder Sandwich, which is the block editor).
 *
 * ONE generic editor for every block: the server describes each block's fields (the same
 * description every other builder reads), so a block — including a custom block made on the
 * site — needs no JavaScript of its own. Each block shows the server's real rendering (the HTML a
 * visitor gets) and switches to its form with one click; an empty block opens on its form.
 *
 * ⛔ zs-150: this file is an editor script (block registration `editor_script_handles`); a
 * visitor's page never loads it.
 */
import { __ } from '@wordpress/i18n';
import {
	Card,
	CardBody,
	CardHeader,
	PanelBody,
	ToolbarButton,
	ToolbarGroup,
	Notice,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';

import { Field } from './Field';
import './blocks.scss';

// The editor's own packages, from WordPress (`window.wp`): this bundle carries only the blocks.
const wp = window.wp || {};
const { registerBlockType } = wp.blocks || {};
const { BlockControls, InspectorControls, useBlockProps } =
	wp.blockEditor || {};
const ServerSideRender = wp.serverSideRender;

const boot = window.zinnSeoBlocks || { blocks: [] };

/**
 * The form: every field of the block.
 *
 * @param {Object}   props
 * @param {Object}   props.block         Block description.
 * @param {Object}   props.attributes    Attributes.
 * @param {Function} props.setAttributes Setter.
 * @return {Element} Form.
 */
function Form( { block, attributes, setAttributes } ) {
	return (
		<Card className="zseo-blk-form" size="small">
			<CardHeader>
				<strong>{ block.title }</strong>
				<span className="zseo-blk-form__desc">
					{ block.description }
				</span>
			</CardHeader>
			<CardBody>
				{ block.fields.map( ( f ) => (
					<Field
						key={ f.key }
						field={ f }
						value={ attributes[ f.key ] }
						onChange={ ( v ) => setAttributes( { [ f.key ]: v } ) }
					/>
				) ) }
			</CardBody>
		</Card>
	);
}

/**
 * The block's edit view: the server's rendering, or the form.
 *
 * @param {Object} block Block description.
 * @return {Function} Edit component.
 */
function makeEdit( block ) {
	return function Edit( { attributes, setAttributes, isSelected } ) {
		const [ editing, setEditing ] = useState( false );
		const props = useBlockProps( { className: 'zseo-blk' } );
		const postId = useSelect(
			( select ) => select( 'core/editor' )?.getCurrentPostId?.(),
			[]
		);
		const preview = (
			<ServerSideRender
				block={ block.name }
				attributes={ attributes }
				urlQueryArgs={ postId ? { post_id: postId } : {} }
				EmptyResponsePlaceholder={ () => (
					<Form
						block={ block }
						attributes={ attributes }
						setAttributes={ setAttributes }
					/>
				) }
			/>
		);
		return (
			<div { ...props }>
				<BlockControls>
					<ToolbarGroup>
						<ToolbarButton
							icon={ editing ? 'visibility' : 'edit' }
							label={
								editing
									? __( 'Show the result', 'zinn-seo' )
									: __( 'Edit the content', 'zinn-seo' )
							}
							onClick={ () => setEditing( ! editing ) }
						/>
					</ToolbarGroup>
				</BlockControls>
				<InspectorControls>
					<PanelBody title={ __( 'Content', 'zinn-seo' ) }>
						{ block.fields.map( ( f ) => (
							<Field
								key={ f.key }
								field={ f }
								value={ attributes[ f.key ] }
								onChange={ ( v ) =>
									setAttributes( { [ f.key ]: v } )
								}
							/>
						) ) }
					</PanelBody>
				</InspectorControls>
				{ ! block.offered && isSelected && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'Page Builder Sandwich offers its own version of this block; this copy keeps working.',
							'zinn-seo'
						) }
					</Notice>
				) }
				{ editing ? (
					<Form
						block={ block }
						attributes={ attributes }
						setAttributes={ setAttributes }
					/>
				) : (
					preview
				) }
			</div>
		);
	};
}

( boot.blocks || [] ).forEach( ( block ) => {
	registerBlockType( block.name, {
		apiVersion: 3,
		title: block.title,
		description: block.description,
		category: 'zinn-seo',
		icon: block.icon,
		keywords: block.keywords,
		example: block.example ? { attributes: block.example } : undefined,
		edit: makeEdit( block ),
		save: () => null,
	} );
} );
