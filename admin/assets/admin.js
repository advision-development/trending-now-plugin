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

		var type = select.value;

		// Each field declares the types it belongs to, so adding a source type
		// is a markup change rather than another branch here.
		row.querySelectorAll( '.advtn-type-field' ).forEach( function ( el ) {
			var types = ( el.dataset.types || '' ).split( /\s+/ );
			el.hidden = types.indexOf( type ) === -1;
		} );
	}

	/* ----------------------------------------------------------------- */
	/* Mode-specific settings rows                                        */
	/* ----------------------------------------------------------------- */

	function applyMode() {
		var select = document.getElementById( 'advtn-mode' );
		if ( ! select ) {
			return;
		}

		var mode = select.value;

		document.querySelectorAll( '.advtn-mode-row' ).forEach( function ( row ) {
			var modes = ( row.dataset.modes || '' ).split( /\s+/ );
			row.hidden = modes.indexOf( mode ) === -1;
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
		applyMode();

		var modeSelect = document.getElementById( 'advtn-mode' );
		if ( modeSelect ) {
			modeSelect.addEventListener( 'change', applyMode );
		}

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

		// Curated links: repeat rows and expiry shortcuts.
		var linkList = document.getElementById( 'advtn-manual-links' );
		var linkTemplate = document.getElementById( 'advtn-manual-template' );
		var addLink = document.getElementById( 'advtn-add-link' );

		if ( linkList && linkTemplate && addLink ) {
			addLink.addEventListener( 'click', function () {
				var index = linkList.children.length;
				var holder = document.createElement( 'div' );

				holder.innerHTML = linkTemplate.innerHTML.replace( /links\[9999\]/g, 'links[' + index + ']' );

				var row = holder.querySelector( '.advtn-manual' );
				linkList.appendChild( row );
				row.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			} );
		}

		document.addEventListener( 'click', function ( event ) {
			var setBtn = event.target.closest( '.advtn-expiry-set' );
			var clearBtn = event.target.closest( '.advtn-expiry-clear' );

			if ( ! setBtn && ! clearBtn ) {
				return;
			}

			event.preventDefault();

			var field = ( setBtn || clearBtn ).closest( '.advtn-expiry' ).querySelector( '.advtn-expires' );
			if ( ! field ) {
				return;
			}

			if ( clearBtn ) {
				field.value = '';
				return;
			}

			// The field is UTC, so build the value from UTC parts rather than
			// letting toISOString shift it by the browser's offset twice.
			var when = new Date( Date.now() + parseInt( setBtn.dataset.hours, 10 ) * 3600 * 1000 );
			field.value = when.toISOString().slice( 0, 16 );
		} );

		// Only confirm the destructive import strategy, not every import.
		var importForm = document.querySelector( '.advtn-portability__import' );
		if ( importForm ) {
			importForm.addEventListener( 'submit', function ( event ) {
				var replace = importForm.querySelector( 'input[name="advtn_import_mode"][value="replace"]' );

				if ( replace && replace.checked && ! window.confirm( settings.i18n.replace ) ) {
					event.preventDefault();
				}
			} );
		}

		// Select-all checkbox in the item browser.
		document.querySelectorAll( '.advtn-check-all' ).forEach( function ( master ) {
			master.addEventListener( 'change', function () {
				var table = master.closest( 'table' );
				if ( ! table ) {
					return;
				}
				table.querySelectorAll( 'input[name="item_ids[]"]' ).forEach( function ( box ) {
					box.checked = master.checked;
				} );
			} );
		} );

		// Emptying the table deserves more than a single OK.
		document.querySelectorAll( '.advtn-confirm-hard' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				if ( ! window.confirm( settings.i18n.deleteAll ) ) {
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
