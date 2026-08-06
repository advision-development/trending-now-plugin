/**
 * Trending Now admin behaviour.
 *
 * No build step, no dependencies.
 */
( function () {
	'use strict';

	var settings = window.advtnAdmin || { i18n: {} };

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	/* ----------------------------------------------------------------- */
	/* Type-specific field visibility                                     */
	/* ----------------------------------------------------------------- */

	function applyType( row ) {
		var select = row.querySelector( '.advtn-type' );
		if ( ! select ) {
			return;
		}

		var isGdelt = select.value === 'gdelt';

		row.querySelectorAll( '.advtn-field-gdelt' ).forEach( function ( el ) {
			el.hidden = ! isGdelt;
		} );
		row.querySelectorAll( '.advtn-field-url' ).forEach( function ( el ) {
			el.hidden = isGdelt;
		} );
	}

	/* ----------------------------------------------------------------- */
	/* Ordering                                                           */
	/* ----------------------------------------------------------------- */

	function reindex( container ) {
		Array.prototype.forEach.call( container.children, function ( row, index ) {
			var order = row.querySelector( '.advtn-order' );
			if ( order ) {
				order.value = String( index );
			}
		} );
	}

	function move( row, direction ) {
		var container = row.parentNode;

		if ( direction === 'up' && row.previousElementSibling ) {
			container.insertBefore( row, row.previousElementSibling );
		} else if ( direction === 'down' && row.nextElementSibling ) {
			container.insertBefore( row.nextElementSibling, row );
		}

		reindex( container );
	}

	/* ----------------------------------------------------------------- */
	/* Test fetch                                                         */
	/* ----------------------------------------------------------------- */

	function collectConfig( row ) {
		var config = {};

		row.querySelectorAll( 'input, select' ).forEach( function ( field ) {
			var match = /\[([a-z_]+)\]$/.exec( field.name || '' );
			if ( ! match ) {
				return;
			}

			var key = match[ 1 ];
			if ( key === '_delete' || key === 'order' ) {
				return;
			}

			config[ key ] = field.type === 'checkbox' ? ( field.checked ? '1' : '' ) : field.value;
		} );

		// The fetch runs against whatever is on screen, saved or not.
		config.enabled = '1';

		return config;
	}

	function renderResult( target, payload ) {
		var lines = [];

		lines.push(
			( payload.ok ? '✓ ' : '✗ ' ) +
				'HTTP ' + ( payload.http_code === null ? '—' : payload.http_code ) +
				' · ' + payload.duration_ms + 'ms' +
				' · ' + payload.item_count + '/' + payload.raw_count + ' usable'
		);

		if ( payload.error ) {
			lines.push( 'Error: ' + payload.error );
		}

		( payload.items || [] ).forEach( function ( item ) {
			lines.push(
				'• ' + item.title +
					'\n  ' + item.url +
					'\n  ' + ( item.published_at || 'no date' ) +
					' · ' + item.site_name +
					( item.has_image ? ' · image' : '' )
			);
		} );

		target.textContent = lines.join( '\n' );
		target.hidden = false;
	}

	function testFetch( row ) {
		var button = row.querySelector( '.advtn-test' );
		var target = row.querySelector( '.advtn-test-result' );
		var body = new FormData();

		body.append( 'action', 'advtn_test_fetch' );
		body.append( 'nonce', settings.nonce );

		var config = collectConfig( row );
		Object.keys( config ).forEach( function ( key ) {
			body.append( 'config[' + key + ']', config[ key ] );
		} );

		button.disabled = true;
		button.textContent = settings.i18n.testing || 'Testing…';

		window
			.fetch( settings.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( json && json.success ) {
					renderResult( target, json.data );
				} else {
					target.textContent =
						'✗ ' + ( ( json && json.data && json.data.message ) || settings.i18n.failed );
					target.hidden = false;
				}
			} )
			.catch( function ( error ) {
				target.textContent = '✗ ' + error.message;
				target.hidden = false;
			} )
			.finally( function () {
				button.disabled = false;
				button.textContent = settings.i18n.testFetch || 'Test fetch';
			} );
	}

	/* ----------------------------------------------------------------- */
	/* Wiring                                                             */
	/* ----------------------------------------------------------------- */

	ready( function () {
		var container = document.getElementById( 'advtn-sources' );

		if ( container ) {
			container.querySelectorAll( '.advtn-source' ).forEach( applyType );
			reindex( container );

			container.addEventListener( 'change', function ( event ) {
				if ( event.target.classList.contains( 'advtn-type' ) ) {
					applyType( event.target.closest( '.advtn-source' ) );
				}
			} );

			container.addEventListener( 'click', function ( event ) {
				var row = event.target.closest( '.advtn-source' );
				if ( ! row ) {
					return;
				}

				if ( event.target.classList.contains( 'advtn-move' ) ) {
					event.preventDefault();
					move( row, event.target.dataset.dir );
				}

				if ( event.target.classList.contains( 'advtn-test' ) ) {
					event.preventDefault();
					testFetch( row );
				}
			} );

			var dragged = null;

			container.addEventListener( 'dragstart', function ( event ) {
				dragged = event.target.closest( '.advtn-source' );
				if ( dragged ) {
					dragged.classList.add( 'is-dragging' );
					event.dataTransfer.effectAllowed = 'move';
				}
			} );

			container.addEventListener( 'dragover', function ( event ) {
				event.preventDefault();
				var over = event.target.closest( '.advtn-source' );
				if ( ! dragged || ! over || over === dragged ) {
					return;
				}

				var box = over.getBoundingClientRect();
				var after = event.clientY > box.top + box.height / 2;
				container.insertBefore( dragged, after ? over.nextElementSibling : over );
			} );

			container.addEventListener( 'dragend', function () {
				if ( dragged ) {
					dragged.classList.remove( 'is-dragging' );
					dragged = null;
					reindex( container );
				}
			} );

			var addButton = document.getElementById( 'advtn-add-source' );
			var template = document.getElementById( 'advtn-source-template' );

			if ( addButton && template ) {
				addButton.addEventListener( 'click', function () {
					var index = container.children.length;
					var html = template.innerHTML.replace( /sources\[9999\]/g, 'sources[' + index + ']' );
					var holder = document.createElement( 'div' );

					holder.innerHTML = html;

					var row = holder.querySelector( '.advtn-source' );
					container.appendChild( row );
					applyType( row );
					reindex( container );
					row.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				} );
			}
		}

		document.querySelectorAll( '.advtn-confirm' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				if ( ! window.confirm( settings.i18n.confirm || 'Are you sure?' ) ) {
					event.preventDefault();
				}
			} );
		} );

		document.querySelectorAll( '.advtn-copy' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var field = document.getElementById( button.dataset.target );
				if ( ! field ) {
					return;
				}

				field.select();
				field.setSelectionRange( 0, 99999 );

				if ( navigator.clipboard ) {
					navigator.clipboard.writeText( field.value );
				} else {
					document.execCommand( 'copy' );
				}
			} );
		} );
	} );
} )();
