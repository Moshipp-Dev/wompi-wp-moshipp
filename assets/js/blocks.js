/**
 * Registro de Nequi y Daviplata en el Checkout por bloques de WooCommerce.
 * Sin paso de build: usa los globals wc.wcBlocksRegistry / wp.element.
 */
( function () {
	'use strict';

	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wp || ! window.wp.element ) {
		return;
	}

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var el = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;
	var decodeEntities = window.wp.htmlEntities.decodeEntities;

	function AcceptanceCheckbox( props ) {
		var i18n = props.settings.i18n;
		var links = props.settings.legalLinks || {};
		return el(
			'p',
			{ className: 'wompi-mp-acceptance' },
			el(
				'label',
				null,
				el( 'input', {
					type: 'checkbox',
					checked: props.checked,
					onChange: function ( e ) {
						props.onChange( e.target.checked );
					},
				} ),
				el(
					'span',
					{ className: 'wompi-mp-acceptance-text' },
					i18n.acceptPrefix + ' ',
					el( 'a', { href: links.policy, target: '_blank', rel: 'noopener' }, i18n.policyText ),
					' ' + i18n.acceptMiddle + ' ',
					el( 'a', { href: links.personal_data, target: '_blank', rel: 'noopener' }, i18n.personalText ),
					' ' + i18n.acceptSuffix + ' *'
				)
			)
		);
	}

	function methodLabel( settings, fallback ) {
		var children = [];
		if ( settings.icon ) {
			children.push(
				el( 'img', {
					key: 'icon',
					src: settings.icon,
					alt: '',
					style: { height: '22px', width: 'auto', marginRight: '10px' },
				} )
			);
		}
		children.push( decodeEntities( settings.title || fallback ) );
		return el(
			'span',
			{ className: 'wompi-mp-method-label', style: { display: 'flex', alignItems: 'center' } },
			children
		);
	}

	function useSetupHandler( props, buildResult ) {
		var onPaymentSetup = props.eventRegistration.onPaymentSetup;
		var emitResponse = props.emitResponse;
		useEffect(
			function () {
				var unsubscribe = onPaymentSetup( function () {
					return buildResult( emitResponse );
				} );
				return unsubscribe;
			},
			[ onPaymentSetup, emitResponse, buildResult ]
		);
	}

	/* ------------------------------ Nequi ------------------------------ */

	var nequiSettings = getSetting( 'wompi_nequi_data', null );

	if ( nequiSettings ) {
		var NequiContent = function ( props ) {
			var phoneState = useState( '' );
			var phone = phoneState[ 0 ];
			var setPhone = phoneState[ 1 ];
			var acceptState = useState( false );
			var accepted = acceptState[ 0 ];
			var setAccepted = acceptState[ 1 ];

			useSetupHandler( props, function ( emitResponse ) {
				var clean = phone.replace( /\D/g, '' );
				if ( ! /^3\d{9}$/.test( clean ) ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: nequiSettings.i18n.phoneInvalid,
					};
				}
				if ( ! accepted ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: nequiSettings.i18n.acceptRequired,
					};
				}
				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							wompi_mp_nequi_phone: clean,
							wompi_mp_accept: '1',
						},
					},
				};
			} );

			return el(
				'div',
				{ className: 'wompi-mp-fields wompi-mp-nequi' },
				nequiSettings.description
					? el( 'p', { className: 'wompi-mp-desc' }, decodeEntities( nequiSettings.description ) )
					: null,
				el(
					'p',
					{ className: 'wompi-mp-field' },
					el( 'label', { htmlFor: 'wompi-mp-blocks-phone' }, nequiSettings.i18n.phoneLabel + ' *' ),
					el( 'input', {
						id: 'wompi-mp-blocks-phone',
						type: 'tel',
						inputMode: 'numeric',
						maxLength: 10,
						placeholder: '3001234567',
						value: phone,
						onChange: function ( e ) {
							setPhone( e.target.value );
						},
					} )
				),
				el( AcceptanceCheckbox, {
					settings: nequiSettings,
					checked: accepted,
					onChange: setAccepted,
				} ),
				nequiSettings.brandHtml
					? el( 'div', { dangerouslySetInnerHTML: { __html: nequiSettings.brandHtml } } )
					: null
			);
		};

		registerPaymentMethod( {
			name: 'wompi_nequi',
			label: methodLabel( nequiSettings, 'Nequi' ),
			ariaLabel: decodeEntities( nequiSettings.title || 'Nequi' ),
			content: el( NequiContent, null ),
			edit: el( 'div', null, decodeEntities( nequiSettings.title || 'Nequi' ) ),
			canMakePayment: function () {
				return true;
			},
			supports: {
				features: nequiSettings.supports || [ 'products' ],
			},
		} );
	}

	/* ---------------------------- Daviplata ---------------------------- */

	var daviSettings = getSetting( 'wompi_daviplata_data', null );

	if ( daviSettings ) {
		var DaviContent = function ( props ) {
			var typeState = useState( 'CC' );
			var docType = typeState[ 0 ];
			var setDocType = typeState[ 1 ];
			var numberState = useState( '' );
			var docNumber = numberState[ 0 ];
			var setDocNumber = numberState[ 1 ];
			var acceptState = useState( false );
			var accepted = acceptState[ 0 ];
			var setAccepted = acceptState[ 1 ];

			useSetupHandler( props, function ( emitResponse ) {
				var clean = docNumber.replace( /\D/g, '' );
				if ( ! /^\d{4,15}$/.test( clean ) ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: daviSettings.i18n.docInvalid,
					};
				}
				if ( ! accepted ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: daviSettings.i18n.acceptRequired,
					};
				}
				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							wompi_mp_doc_type: docType,
							wompi_mp_doc_number: clean,
							wompi_mp_accept: '1',
						},
					},
				};
			} );

			var options = [];
			Object.keys( daviSettings.docTypes || {} ).forEach( function ( code ) {
				options.push( el( 'option', { key: code, value: code }, daviSettings.docTypes[ code ] ) );
			} );

			return el(
				'div',
				{ className: 'wompi-mp-fields wompi-mp-daviplata' },
				daviSettings.description
					? el( 'p', { className: 'wompi-mp-desc' }, decodeEntities( daviSettings.description ) )
					: null,
				el(
					'div',
					{ className: 'wompi-mp-cols' },
					el(
						'p',
						{ className: 'wompi-mp-field' },
						el( 'label', { htmlFor: 'wompi-mp-blocks-doc-type' }, daviSettings.i18n.docTypeLabel + ' *' ),
						el(
							'select',
							{
								id: 'wompi-mp-blocks-doc-type',
								value: docType,
								onChange: function ( e ) {
									setDocType( e.target.value );
								},
							},
							options
						)
					),
					el(
						'p',
						{ className: 'wompi-mp-field' },
						el( 'label', { htmlFor: 'wompi-mp-blocks-doc-number' }, daviSettings.i18n.docNumberLabel + ' *' ),
						el( 'input', {
							id: 'wompi-mp-blocks-doc-number',
							type: 'text',
							inputMode: 'numeric',
							maxLength: 15,
							value: docNumber,
							onChange: function ( e ) {
								setDocNumber( e.target.value );
							},
						} )
					)
				),
				el( AcceptanceCheckbox, {
					settings: daviSettings,
					checked: accepted,
					onChange: setAccepted,
				} ),
				daviSettings.brandHtml
					? el( 'div', { dangerouslySetInnerHTML: { __html: daviSettings.brandHtml } } )
					: null
			);
		};

		registerPaymentMethod( {
			name: 'wompi_daviplata',
			label: methodLabel( daviSettings, 'Daviplata' ),
			ariaLabel: decodeEntities( daviSettings.title || 'Daviplata' ),
			content: el( DaviContent, null ),
			edit: el( 'div', null, decodeEntities( daviSettings.title || 'Daviplata' ) ),
			canMakePayment: function () {
				return true;
			},
			supports: {
				features: daviSettings.supports || [ 'products' ],
			},
		} );
	}
} )();
