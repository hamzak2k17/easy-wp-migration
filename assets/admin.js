/**
 * Easy WP Migration — Admin scripts.
 *
 * Provides the reusable EWPM.Job polling loop, EWPM.UI progress rendering,
 * and EWPM.Export page logic.
 *
 * @package EasyWPMigration
 */

( function () {
	'use strict';

	window.EWPM = window.EWPM || {};

	var config = window.ewpmData || {};

	/* ------------------------------------------------------------------ */
	/*  AJAX helper                                                        */
	/* ------------------------------------------------------------------ */

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

	function sleep( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, ms );
		} );
	}

	function formatBytes( bytes ) {
		if ( ! bytes ) return '0 B';
		var k = 1024;
		var sizes = [ 'B', 'KB', 'MB', 'GB' ];
		var i = Math.floor( Math.log( bytes ) / Math.log( k ) );
		return parseFloat( ( bytes / Math.pow( k, i ) ).toFixed( 2 ) ) + ' ' + sizes[ i ];
	}

	function escHtml( str ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str || '' ) );
		return div.innerHTML;
	}

	/* ------------------------------------------------------------------ */
	/*  EWPM.Job — start, poll, finalize, cancel                          */
	/* ------------------------------------------------------------------ */

	EWPM.Job = {
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
					var startResult = await ajaxPost( 'ewpm_job_start', {
						job_type: jobType,
						params: params,
					} );

					jobId = startResult.job_id;

					while ( true ) { // eslint-disable-line no-constant-condition
						if ( cancelled ) {
							await sleep( 500 );
							try {
								var cancelProgress = await ajaxPost(
									'ewpm_job_tick',
									{ job_id: jobId }
								);
								if ( onProgress ) { onProgress( cancelProgress ); }
								if ( onCancel ) { onCancel( cancelProgress ); }
							} catch ( e ) {
								if ( onCancel ) {
									onCancel( { cancelled: true, progress_label: 'Job was cancelled.' } );
								}
							}
							return;
						}

						var progress = await ajaxPost( 'ewpm_job_tick', {
							job_id: jobId,
						} );

						if ( progress.cancelled ) {
							if ( onCancel ) { onCancel( progress ); }
							return;
						}

						if ( progress.error ) {
							if ( onError ) { onError( new Error( progress.error ) ); }
							return;
						}

						if ( onProgress ) { onProgress( progress ); }

						if ( progress.done ) {
							var result = await ajaxPost( 'ewpm_job_finalize', {
								job_id: jobId,
							} );
							if ( onDone ) { onDone( result ); }
							return;
						}

						await sleep( 500 );
					}
				} catch ( err ) {
					if ( onError ) { onError( err ); }
				}
			} )();

			return {
				cancel: function () {
					cancelled = true;
					if ( jobId ) {
						ajaxPost( 'ewpm_job_cancel', { job_id: jobId } ).catch( function () {} );
					}
				},
				getJobId: function () { return jobId; },
			};
		},

		/**
		 * Resume polling an existing job by its ID.
		 *
		 * @param {Object} opts Same as start() but with job_id instead of job_type/params.
		 * @return {{ cancel: Function, getJobId: Function }}
		 */
		resume: function ( opts ) {
			var jobId      = opts.job_id;
			var onProgress = opts.onProgress;
			var onDone     = opts.onDone;
			var onError    = opts.onError;
			var onCancel   = opts.onCancel;
			var cancelled  = false;

			( async function run() {
				try {
					while ( true ) { // eslint-disable-line no-constant-condition
						if ( cancelled ) {
							if ( onCancel ) { onCancel( { cancelled: true } ); }
							return;
						}

						var progress = await ajaxPost( 'ewpm_job_tick', { job_id: jobId } );

						if ( progress.cancelled ) {
							if ( onCancel ) { onCancel( progress ); }
							return;
						}

						if ( progress.error ) {
							if ( onError ) { onError( new Error( progress.error ) ); }
							return;
						}

						if ( onProgress ) { onProgress( progress ); }

						if ( progress.done ) {
							var result = await ajaxPost( 'ewpm_job_finalize', { job_id: jobId } );
							if ( onDone ) { onDone( result ); }
							return;
						}

						await sleep( 500 );
					}
				} catch ( err ) {
					if ( onError ) { onError( err ); }
				}
			} )();

			return {
				cancel: function () {
					cancelled = true;
					ajaxPost( 'ewpm_job_cancel', { job_id: jobId } ).catch( function () {} );
				},
				getJobId: function () { return jobId; },
			};
		},
	};

	/* ------------------------------------------------------------------ */
	/*  EWPM.UI — reusable progress bar                                    */
	/* ------------------------------------------------------------------ */

	EWPM.UI = {
		renderProgress: function ( container, progressData ) {
			if ( ! container ) { return; }

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

	/* ------------------------------------------------------------------ */
	/*  EWPM.Export — export page logic                                    */
	/* ------------------------------------------------------------------ */

	EWPM.Export = {
		init: function () {
			var form       = document.getElementById( 'ewpm-export-form' );
			var startBtn   = document.getElementById( 'ewpm-export-start' );
			var cancelBtn  = document.getElementById( 'ewpm-export-cancel' );
			var progressEl = document.getElementById( 'ewpm-export-progress' );
			var resultEl   = document.getElementById( 'ewpm-export-result' );

			if ( ! form || ! startBtn ) { return; }

			var jobHandle = null;

			// Output radio toggle for backup name field.
			var outputRadios    = form.querySelectorAll( 'input[name="ewpm_output"]' );
			var backupNameWrap  = document.getElementById( 'ewpm-backup-name-wrap' );

			outputRadios.forEach( function ( radio ) {
				radio.addEventListener( 'change', function () {
					if ( backupNameWrap ) {
						backupNameWrap.style.display = radio.value === 'backup' && radio.checked ? 'block' : 'none';
					}
				} );
			} );

			// Check for a running job in sessionStorage (page reload resume).
			var storedJobId = sessionStorage.getItem( 'ewpm_export_job_id' );
			if ( storedJobId ) {
				this.resumeJob( storedJobId, form, startBtn, cancelBtn, progressEl, resultEl );
			}

			// Start button click.
			startBtn.addEventListener( 'click', function () {
				var params = EWPM.Export.collectParams( form );

				progressEl.innerHTML    = '';
				progressEl.style.display = 'block';
				resultEl.innerHTML      = '';
				resultEl.style.display  = 'none';
				startBtn.disabled       = true;
				cancelBtn.style.display = 'inline-block';

				EWPM.Export.setFormDisabled( form, true );

				jobHandle = EWPM.Job.start( {
					job_type: 'export',
					params: params,

					onProgress: function ( data ) {
						EWPM.UI.renderProgress( progressEl, data );
						if ( jobHandle ) {
							sessionStorage.setItem( 'ewpm_export_job_id', jobHandle.getJobId() );
						}
					},

					onDone: function ( result ) {
						sessionStorage.removeItem( 'ewpm_export_job_id' );
						cancelBtn.style.display = 'none';
						startBtn.disabled       = false;
						EWPM.Export.setFormDisabled( form, false );
						EWPM.Export.renderResult( resultEl, result );
					},

					onError: function ( err ) {
						sessionStorage.removeItem( 'ewpm_export_job_id' );
						cancelBtn.style.display = 'none';
						startBtn.disabled       = false;
						EWPM.Export.setFormDisabled( form, false );
						resultEl.style.display = 'block';
						resultEl.className     = 'ewpm-export-result ewpm-export-result--error';
						resultEl.textContent   = 'Error: ' + err.message;
					},

					onCancel: function ( data ) {
						sessionStorage.removeItem( 'ewpm_export_job_id' );
						cancelBtn.style.display = 'none';
						startBtn.disabled       = false;
						EWPM.Export.setFormDisabled( form, false );
						EWPM.UI.renderProgress( progressEl, data );
						resultEl.style.display = 'block';
						resultEl.className     = 'ewpm-export-result';
						resultEl.textContent   = 'Export cancelled.';
					},
				} );
			} );

			cancelBtn.addEventListener( 'click', function () {
				if ( jobHandle ) { jobHandle.cancel(); }
			} );
		},

		/**
		 * Resume a job from sessionStorage after page reload.
		 */
		resumeJob: function ( jobId, form, startBtn, cancelBtn, progressEl, resultEl ) {
			progressEl.innerHTML     = '';
			progressEl.style.display = 'block';
			resultEl.style.display   = 'none';
			startBtn.disabled        = true;
			cancelBtn.style.display  = 'inline-block';
			EWPM.Export.setFormDisabled( form, true );

			var jobHandle = EWPM.Job.resume( {
				job_id: jobId,

				onProgress: function ( data ) {
					EWPM.UI.renderProgress( progressEl, data );
				},

				onDone: function ( result ) {
					sessionStorage.removeItem( 'ewpm_export_job_id' );
					cancelBtn.style.display = 'none';
					startBtn.disabled       = false;
					EWPM.Export.setFormDisabled( form, false );
					EWPM.Export.renderResult( resultEl, result );
				},

				onError: function ( err ) {
					sessionStorage.removeItem( 'ewpm_export_job_id' );
					cancelBtn.style.display = 'none';
					startBtn.disabled       = false;
					EWPM.Export.setFormDisabled( form, false );
					resultEl.style.display = 'block';
					resultEl.className     = 'ewpm-export-result ewpm-export-result--error';
					resultEl.textContent   = 'Error: ' + err.message;
				},

				onCancel: function ( data ) {
					sessionStorage.removeItem( 'ewpm_export_job_id' );
					cancelBtn.style.display = 'none';
					startBtn.disabled       = false;
					EWPM.Export.setFormDisabled( form, false );
					EWPM.UI.renderProgress( progressEl, data );
					resultEl.style.display = 'block';
					resultEl.className     = 'ewpm-export-result';
					resultEl.textContent   = 'Export cancelled.';
				},
			} );

			cancelBtn.addEventListener( 'click', function () {
				if ( jobHandle ) { jobHandle.cancel(); }
			} );
		},

		/**
		 * Collect form parameters for the export job.
		 */
		collectParams: function ( form ) {
			var components = {};
			form.querySelectorAll( '.ewpm-export-component__checkbox' ).forEach( function ( cb ) {
				components[ cb.dataset.component ] = cb.checked;
			} );

			var exclusionPresets = {};
			form.querySelectorAll( '.ewpm-export-exclusion__checkbox' ).forEach( function ( cb ) {
				exclusionPresets[ cb.dataset.exclusionPreset ] = cb.checked;
			} );

			var customExcl = document.getElementById( 'ewpm-custom-exclusions' );
			var outputRadio = form.querySelector( 'input[name="ewpm_output"]:checked' );
			var backupName  = document.getElementById( 'ewpm-backup-name' );

			return {
				components: components,
				exclusion_presets: exclusionPresets,
				custom_exclusions: customExcl ? customExcl.value : '',
				save_as_backup: outputRadio ? outputRadio.value === 'backup' : false,
				backup_name: backupName ? backupName.value : '',
			};
		},

		/**
		 * Render the export result.
		 */
		renderResult: function ( container, result ) {
			container.style.display = 'block';
			container.className     = 'ewpm-export-result ewpm-export-result--success';

			var html = '<h3>Export complete!</h3>';
			html += '<p><strong>' + escHtml( result.filename ) + '</strong> (' + escHtml( result.size_human ) + ')</p>';

			if ( result.stats ) {
				html += '<p>';
				html += 'Files: ' + ( result.stats.files_archived || 0 ).toLocaleString();
				html += ' &middot; DB rows: ' + ( result.stats.db_rows || 0 ).toLocaleString();
				html += '</p>';
			}

			if ( result.download_url ) {
				html += '<p><a href="' + escHtml( result.download_url ) + '" class="button button-primary">Download Archive</a></p>';
			}

			if ( result.saved_as_backup ) {
				html += '<p><em>Saved as server backup.</em></p>';
			}

			if ( result.warnings && result.warnings.length > 0 ) {
				html += '<details class="ewpm-export-warnings"><summary>Warnings (' + result.warnings.length + ')</summary><ul>';
				result.warnings.forEach( function ( w ) {
					html += '<li>' + escHtml( w ) + '</li>';
				} );
				html += '</ul></details>';
			}

			container.innerHTML = html;
		},

		/**
		 * Enable/disable form controls during export.
		 */
		setFormDisabled: function ( form, disabled ) {
			form.querySelectorAll( 'input, textarea, select' ).forEach( function ( el ) {
				el.disabled = disabled;
			} );
		},
	};

	/* ------------------------------------------------------------------ */
	/*  Auto-init on DOMContentLoaded                                      */
	/* ------------------------------------------------------------------ */

	document.addEventListener( 'DOMContentLoaded', function () {
		EWPM.Export.init();
	} );

} )();
