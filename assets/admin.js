/**
 * Easy WP Migration — Admin scripts.
 *
 * Provides the reusable EWPM.Job polling loop and EWPM.UI progress
 * rendering. Every long-running operation (export, import, URL pull,
 * restore) uses EWPM.Job.start() to drive the server-side job framework.
 *
 * @package EasyWPMigration
 */

( function () {
	'use strict';

	window.EWPM = window.EWPM || {};

	var config = window.ewpmData || {};

	/**
	 * POST to a WordPress AJAX action.
	 *
	 * @param {string} action  The wp_ajax_ action name.
	 * @param {Object} data    Key/value pairs to send.
	 * @return {Promise<Object>} The response data on success.
	 */
	function ajaxPost( action, data ) {
		var formData = new FormData();
		formData.append( 'action', action );
		formData.append( 'nonce', config.nonce );

		Object.keys( data || {} ).forEach( function ( key ) {
			var value = data[ key ];
			formData.append(
				key,
				typeof value === 'object' ? JSON.stringify( value ) : value
			);
		} );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} )
			.then( function ( response ) {
				return response.json().then( function ( json ) {
					if ( ! response.ok || ! json.success ) {
						var msg =
							( json.data && json.data.error ) ||
							'Request failed (HTTP ' + response.status + ')';
						throw new Error( msg );
					}
					return json.data;
				} );
			} );
	}

	/**
	 * Sleep for a given number of milliseconds.
	 *
	 * @param {number} ms Milliseconds to wait.
	 * @return {Promise<void>}
	 */
	function sleep( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, ms );
		} );
	}

	/* ------------------------------------------------------------------ */
	/*  EWPM.Job — start, poll, finalize, cancel                          */
	/* ------------------------------------------------------------------ */

	EWPM.Job = {
		/**
		 * Start a job and poll ticks until done.
		 *
		 * @param {Object}   opts
		 * @param {string}   opts.job_type   Job type identifier.
		 * @param {Object}   opts.params     Parameters for the job.
		 * @param {Function} opts.onProgress Called with progress payload each tick.
		 * @param {Function} opts.onDone     Called with finalize result.
		 * @param {Function} opts.onError    Called with Error on failure.
		 * @param {Function} opts.onCancel   Called with progress payload on cancel.
		 * @return {{ cancel: Function, getJobId: Function }}
		 */
		start: function ( opts ) {
			var jobType    = opts.job_type;
			var params     = opts.params || {};
			var onProgress = opts.onProgress;
			var onDone     = opts.onDone;
			var onError    = opts.onError;
			var onCancel   = opts.onCancel;

			var cancelled = false;
			var jobId     = null;

			( async function run() {
				try {
					/* 1. Start the job. */
					var startResult = await ajaxPost( 'ewpm_job_start', {
						job_type: jobType,
						params: params,
					} );

					jobId = startResult.job_id;

					/* 2. Poll ticks. */
					while ( true ) { // eslint-disable-line no-constant-condition
						if ( cancelled ) {
							break;
						}

						var progress = await ajaxPost( 'ewpm_job_tick', {
							job_id: jobId,
						} );

						if ( progress.cancelled ) {
							if ( onCancel ) {
								onCancel( progress );
							}
							return;
						}

						if ( progress.error ) {
							if ( onError ) {
								onError( new Error( progress.error ) );
							}
							return;
						}

						if ( onProgress ) {
							onProgress( progress );
						}

						if ( progress.done ) {
							/* 3. Finalize. */
							var result = await ajaxPost( 'ewpm_job_finalize', {
								job_id: jobId,
							} );
							if ( onDone ) {
								onDone( result );
							}
							return;
						}

						/* Wait between ticks to avoid hammering the server. */
						await sleep( 500 );
					}
				} catch ( err ) {
					if ( onError ) {
						onError( err );
					}
				}
			} )();

			return {
				/**
				 * Request cancellation of the running job.
				 */
				cancel: function () {
					cancelled = true;
					if ( jobId ) {
						ajaxPost( 'ewpm_job_cancel', { job_id: jobId } ).catch(
							function () {
								/* Best-effort cancel — ignore errors. */
							}
						);
					}
				},

				/**
				 * Get the job ID (null until the start request completes).
				 *
				 * @return {string|null}
				 */
				getJobId: function () {
					return jobId;
				},
			};
		},
	};

	/* ------------------------------------------------------------------ */
	/*  EWPM.UI — reusable progress bar component                         */
	/* ------------------------------------------------------------------ */

	EWPM.UI = {
		/**
		 * Render or update a progress bar inside a container.
		 *
		 * Creates the DOM structure on first call; subsequent calls
		 * update values in place.
		 *
		 * @param {HTMLElement} container    Parent element.
		 * @param {Object}     progressData Progress payload from a tick.
		 */
		renderProgress: function ( container, progressData ) {
			if ( ! container ) {
				return;
			}

			var wrapper = container.querySelector( '.ewpm-progress' );

			if ( ! wrapper ) {
				wrapper = document.createElement( 'div' );
				wrapper.className = 'ewpm-progress';
				wrapper.innerHTML =
					'<div class="ewpm-progress__phase"></div>' +
					'<div class="ewpm-progress__bar-wrapper">' +
					'<div class="ewpm-progress__bar"></div>' +
					'</div>' +
					'<div class="ewpm-progress__info">' +
					'<span class="ewpm-progress__label"></span>' +
					'<span class="ewpm-progress__percent"></span>' +
					'</div>';
				container.appendChild( wrapper );
			}

			var pct = progressData.progress_percent || 0;

			wrapper.querySelector( '.ewpm-progress__phase' ).textContent =
				progressData.phase_label || '';
			wrapper.querySelector( '.ewpm-progress__bar' ).style.width =
				pct + '%';
			wrapper.querySelector( '.ewpm-progress__label' ).textContent =
				progressData.progress_label || '';
			wrapper.querySelector( '.ewpm-progress__percent' ).textContent =
				pct + '%';

			/* State classes. */
			wrapper.classList.remove(
				'ewpm-progress--done',
				'ewpm-progress--cancelled',
				'ewpm-progress--error'
			);

			if ( progressData.done ) {
				wrapper.classList.add( 'ewpm-progress--done' );
			} else if ( progressData.cancelled ) {
				wrapper.classList.add( 'ewpm-progress--cancelled' );
			} else if ( progressData.error ) {
				wrapper.classList.add( 'ewpm-progress--error' );
			}
		},
	};
} )();
