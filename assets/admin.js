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
	/*  EWPM.Import — import page logic                                    */
	/* ------------------------------------------------------------------ */

	EWPM.Import = {
		archivePath: null,

		init: function () {
			var page = document.getElementById( 'ewpm-import-page' );
			if ( ! page ) { return; }

			this.bindSourceTabs();
			this.bindDropzone();
			this.bindBackupPicker();
			this.bindStartButton();
			this.bindModal();
			this.checkResume();
		},

		bindSourceTabs: function () {
			var radios       = document.querySelectorAll( 'input[name="ewpm_import_source"]' );
			var uploadPanel  = document.getElementById( 'ewpm-import-upload-panel' );
			var backupPanel  = document.getElementById( 'ewpm-import-backup-panel' );

			radios.forEach( function ( r ) {
				r.addEventListener( 'change', function () {
					uploadPanel.style.display = r.value === 'upload' && r.checked ? 'block' : 'none';
					backupPanel.style.display = r.value === 'backup' && r.checked ? 'block' : 'none';
				} );
			} );
		},

		bindDropzone: function () {
			var zone      = document.getElementById( 'ewpm-dropzone' );
			var fileInput = document.getElementById( 'ewpm-import-file' );
			if ( ! zone || ! fileInput ) { return; }

			zone.addEventListener( 'click', function () { fileInput.click(); } );
			zone.addEventListener( 'dragover', function ( e ) { e.preventDefault(); zone.classList.add( 'ewpm-dropzone--active' ); } );
			zone.addEventListener( 'dragleave', function () { zone.classList.remove( 'ewpm-dropzone--active' ); } );
			zone.addEventListener( 'drop', function ( e ) {
				e.preventDefault();
				zone.classList.remove( 'ewpm-dropzone--active' );
				if ( e.dataTransfer.files.length > 0 ) { EWPM.Import.startUpload( e.dataTransfer.files[0] ); }
			} );
			fileInput.addEventListener( 'change', function () {
				if ( fileInput.files.length > 0 ) { EWPM.Import.startUpload( fileInput.files[0] ); }
			} );
		},

		startUpload: function ( file ) {
			var info     = document.getElementById( 'ewpm-upload-info' );
			var nameEl   = document.getElementById( 'ewpm-upload-filename' );
			var sizeEl   = document.getElementById( 'ewpm-upload-filesize' );
			var progEl   = document.getElementById( 'ewpm-upload-progress' );
			var dropzone = document.getElementById( 'ewpm-dropzone' );

			nameEl.textContent = file.name;
			sizeEl.textContent = formatBytes( file.size );
			info.style.display = 'block';
			dropzone.style.display = 'none';
			progEl.innerHTML = '';

			( async function () {
				try {
					var start = await ajaxPost( 'ewpm_upload_start', {
						filename: file.name,
						total_size: file.size,
					} );

					var chunkSize   = start.chunk_size_recommended || 1048576;
					var uploadId    = start.upload_id;
					var totalChunks = Math.ceil( file.size / chunkSize );

					for ( var i = 0; i < totalChunks; i++ ) {
						var blob = file.slice( i * chunkSize, ( i + 1 ) * chunkSize );
						var fd   = new FormData();
						fd.append( 'action', 'ewpm_upload_chunk' );
						fd.append( 'nonce', config.nonce );
						fd.append( 'upload_id', uploadId );
						fd.append( 'chunk_index', i );
						fd.append( 'total_chunks', totalChunks );
						fd.append( 'chunk', blob, 'chunk' );

						var resp = await fetch( config.ajaxUrl, {
							method: 'POST',
							credentials: 'same-origin',
							body: fd,
						} ).then( function ( r ) { return r.json(); } );

						if ( ! resp.success ) {
							throw new Error( resp.data && resp.data.error || 'Upload failed' );
						}

						EWPM.UI.renderProgress( progEl, {
							phase_label: 'Uploading',
							progress_percent: resp.data.percent_complete,
							progress_label: formatBytes( resp.data.received_bytes ) + ' uploaded',
						} );
					}

					var final = await ajaxPost( 'ewpm_upload_finalize', { upload_id: uploadId } );

					EWPM.Import.archivePath = final.path;
					EWPM.Import.loadPreview( final.path );
				} catch ( err ) {
					progEl.innerHTML = '<div class="ewpm-export-result ewpm-export-result--error">Upload error: ' + escHtml( err.message ) + '</div>';
					dropzone.style.display = 'block';
				}
			} )();
		},

		bindBackupPicker: function () {
			var select = document.getElementById( 'ewpm-import-backup-select' );
			if ( ! select ) { return; }

			ajaxPost( 'ewpm_list_backups', {} ).then( function ( data ) {
				select.innerHTML = '';
				if ( data.length === 0 ) {
					select.innerHTML = '<option value="">No backups found</option>';
					return;
				}
				var opt = document.createElement( 'option' );
				opt.value = '';
				opt.textContent = '-- Select a backup --';
				select.appendChild( opt );

				data.forEach( function ( b ) {
					var o = document.createElement( 'option' );
					o.value = b.path;
					o.textContent = b.filename + ' (' + b.size_human + ', ' + b.date + ')';
					select.appendChild( o );
				} );
			} ).catch( function () {
				select.innerHTML = '<option value="">Failed to load backups</option>';
			} );

			select.addEventListener( 'change', function () {
				if ( select.value ) {
					EWPM.Import.archivePath = select.value;
					EWPM.Import.loadPreview( select.value );
				}
			} );
		},

		loadPreview: function ( archivePath ) {
			var step2   = document.getElementById( 'ewpm-import-step2' );
			var step3   = document.getElementById( 'ewpm-import-step3' );
			var content = document.getElementById( 'ewpm-import-preview-content' );

			content.innerHTML = '<p>Loading preview...</p>';
			step2.style.display = 'block';

			ajaxPost( 'ewpm_import_preview', { archive_path: archivePath } ).then( function ( data ) {
				var html = '<table class="ewpm-preview-table">';
				html += '<tr><td><strong>Source URL</strong></td><td>' + escHtml( data.source_url ) + '</td></tr>';
				html += '<tr><td><strong>Destination URL</strong></td><td>' + escHtml( data.current_url ) + '</td></tr>';
				html += '<tr><td><strong>WordPress</strong></td><td>' + escHtml( data.source_wp ) + '</td></tr>';
				html += '<tr><td><strong>PHP</strong></td><td>' + escHtml( data.source_php ) + '</td></tr>';
				html += '<tr><td><strong>Plugin version</strong></td><td>' + escHtml( data.plugin_version ) + '</td></tr>';
				html += '<tr><td><strong>Database</strong></td><td>' + ( data.components.database ? data.db_tables + ' tables, ' + data.db_rows.toLocaleString() + ' rows' : 'Not included' ) + '</td></tr>';
				html += '<tr><td><strong>Files</strong></td><td>' + data.file_count.toLocaleString() + ' files (' + escHtml( data.file_bytes_human ) + ')' + '</td></tr>';

				if ( data.url_differs ) {
					html += '<tr><td><strong>URL replacement</strong></td><td>' + escHtml( data.source_url ) + ' &rarr; ' + escHtml( data.current_url ) + '</td></tr>';
				}

				html += '</table>';

				if ( data.warnings.length > 0 ) {
					html += '<div class="ewpm-import-preview-warnings">';
					data.warnings.forEach( function ( w ) {
						html += '<p>&#9888; ' + escHtml( w ) + '</p>';
					} );
					html += '</div>';
				}

				content.innerHTML = html;
				step3.style.display = 'block';
			} ).catch( function ( err ) {
				content.innerHTML = '<div class="ewpm-export-result ewpm-export-result--error">Preview error: ' + escHtml( err.message ) + '</div>';
			} );
		},

		bindStartButton: function () {
			var startBtn = document.getElementById( 'ewpm-import-start' );
			if ( ! startBtn ) { return; }

			startBtn.addEventListener( 'click', function () {
				if ( ! EWPM.Import.archivePath ) { return; }
				EWPM.Import.showModal();
			} );
		},

		showModal: function () {
			var modal = document.getElementById( 'ewpm-import-modal' );
			modal.style.display = 'flex';

			// Reset state.
			modal.querySelectorAll( '.ewpm-consent-check' ).forEach( function ( cb ) { cb.checked = false; } );
			document.getElementById( 'ewpm-import-confirm-input' ).value = '';
			document.getElementById( 'ewpm-import-modal-confirm' ).disabled = true;
		},

		bindModal: function () {
			var modal      = document.getElementById( 'ewpm-import-modal' );
			var confirmBtn = document.getElementById( 'ewpm-import-modal-confirm' );
			var cancelBtn  = document.getElementById( 'ewpm-import-modal-cancel' );
			var confirmInput = document.getElementById( 'ewpm-import-confirm-input' );

			if ( ! modal ) { return; }

			function updateConfirmState() {
				var allChecked = true;
				modal.querySelectorAll( '.ewpm-consent-check' ).forEach( function ( cb ) {
					if ( ! cb.checked ) { allChecked = false; }
				} );
				confirmBtn.disabled = ! ( allChecked && confirmInput.value === 'IMPORT' );
			}

			modal.querySelectorAll( '.ewpm-consent-check' ).forEach( function ( cb ) {
				cb.addEventListener( 'change', updateConfirmState );
			} );
			confirmInput.addEventListener( 'input', updateConfirmState );

			cancelBtn.addEventListener( 'click', function () {
				modal.style.display = 'none';
			} );

			confirmBtn.addEventListener( 'click', function () {
				modal.style.display = 'none';
				EWPM.Import.runImport();
			} );
		},

		runImport: function () {
			var progressArea = document.getElementById( 'ewpm-import-progress-area' );
			var progressEl   = document.getElementById( 'ewpm-import-progress' );
			var cancelBtn    = document.getElementById( 'ewpm-import-cancel' );
			var resultEl     = document.getElementById( 'ewpm-import-result' );
			var step1        = document.getElementById( 'ewpm-import-step1' );
			var step2        = document.getElementById( 'ewpm-import-step2' );
			var step3        = document.getElementById( 'ewpm-import-step3' );

			step1.style.display = 'none';
			step2.style.display = 'none';
			step3.style.display = 'none';
			progressArea.style.display = 'block';
			progressEl.innerHTML = '';
			resultEl.style.display = 'none';
			cancelBtn.style.display = 'inline-block';

			var autoSnapshot = document.getElementById( 'ewpm-import-auto-snapshot' );
			var doSnapshot   = autoSnapshot ? autoSnapshot.checked : true;

			var self = this;

			( async function () {
				try {
					var snapshotFilename = null;

					// Phase 1: Auto-snapshot.
					if ( doSnapshot ) {
						EWPM.UI.renderProgress( progressEl, {
							phase_label: 'Creating safety snapshot...',
							progress_percent: 0,
							progress_label: 'Backing up your current site before import...',
						} );

						var snapshotResult = await self.runSnapshot( progressEl );
						snapshotFilename = snapshotResult.filename;
						sessionStorage.setItem( 'ewpm_import_snapshot', snapshotFilename );
					}

					// Phase 2: Import.
					sessionStorage.setItem( 'ewpm_import_running', 'true' );
					await self.runImportJob( progressEl, cancelBtn, resultEl, snapshotFilename );

				} catch ( err ) {
					resultEl.style.display = 'block';
					resultEl.className = 'ewpm-export-result ewpm-export-result--error';
					resultEl.innerHTML = '<h3>Import failed</h3><p>' + escHtml( err.message ) + '</p>';

					var snap = sessionStorage.getItem( 'ewpm_import_snapshot' );
					if ( snap ) {
						resultEl.innerHTML += '<p>Your safety backup is at <strong>' + escHtml( snap ) + '</strong>. Restore via the Backups tab.</p>';
					}

					cancelBtn.style.display = 'none';
					sessionStorage.removeItem( 'ewpm_import_running' );
				}
			} )();

			cancelBtn.addEventListener( 'click', function () {
				if ( self._importHandle ) { self._importHandle.cancel(); }
			} );
		},

		runSnapshot: function ( progressEl ) {
			return new Promise( function ( resolve, reject ) {
				var date = new Date().toISOString().replace( /[T:]/g, '-' ).substring( 0, 19 );
				EWPM.Job.start( {
					job_type: 'export',
					params: {
						components: { database: true, themes: true, plugins: true, media: true, other_wp_content: true },
						exclusion_presets: {},
						custom_exclusions: '',
						save_as_backup: true,
						backup_name: 'auto-before-import-' + date,
					},
					onProgress: function ( data ) {
						EWPM.UI.renderProgress( progressEl, {
							phase_label: 'Creating safety snapshot...',
							progress_percent: Math.floor( data.progress_percent * 0.3 ),
							progress_label: data.progress_label,
						} );
					},
					onDone: function ( result ) { resolve( result ); },
					onError: function ( err ) { reject( new Error( 'Safety snapshot failed: ' + err.message + '. Import aborted.' ) ); },
					onCancel: function () { reject( new Error( 'Safety snapshot cancelled. Import aborted.' ) ); },
				} );
			} );
		},

		runImportJob: function ( progressEl, cancelBtn, resultEl, snapshotFilename ) {
			var self = this;
			var conflictRadio = document.querySelector( 'input[name="ewpm_import_conflict"]:checked' );
			var replacePaths  = document.getElementById( 'ewpm-import-replace-paths' );
			var stopOnError   = document.getElementById( 'ewpm-import-stop-error' );

			return new Promise( function ( resolve, reject ) {
				self._importHandle = EWPM.Job.start( {
					job_type: 'import',
					params: {
						archive_path: self.archivePath,
						conflict_strategy: conflictRadio ? conflictRadio.value : 'overwrite',
						replace_paths: replacePaths ? replacePaths.checked : false,
						stop_on_db_error: stopOnError ? stopOnError.checked : false,
					},
					onProgress: function ( data ) {
						var adjustedPct = 30 + Math.floor( data.progress_percent * 0.7 );
						EWPM.UI.renderProgress( progressEl, {
							phase_label: data.phase_label,
							progress_percent: adjustedPct,
							progress_label: data.progress_label,
							done: data.done,
							cancelled: data.cancelled,
							error: data.error,
						} );
						sessionStorage.setItem( 'ewpm_import_job_id', self._importHandle.getJobId() );
					},
					onDone: function ( result ) {
						cancelBtn.style.display = 'none';
						sessionStorage.removeItem( 'ewpm_import_running' );
						sessionStorage.removeItem( 'ewpm_import_job_id' );
						EWPM.Import.renderResult( resultEl, result, snapshotFilename );
						resolve( result );
					},
					onError: function ( err ) {
						cancelBtn.style.display = 'none';
						sessionStorage.removeItem( 'ewpm_import_running' );
						sessionStorage.removeItem( 'ewpm_import_job_id' );
						reject( err );
					},
					onCancel: function ( data ) {
						cancelBtn.style.display = 'none';
						sessionStorage.removeItem( 'ewpm_import_running' );
						sessionStorage.removeItem( 'ewpm_import_job_id' );
						EWPM.UI.renderProgress( progressEl, data );
						resultEl.style.display = 'block';
						resultEl.className = 'ewpm-export-result ewpm-export-result--error';
						resultEl.innerHTML = '<h3>Import cancelled</h3><p>Your site may be in an inconsistent state.</p>';

						var snap = sessionStorage.getItem( 'ewpm_import_snapshot' );
						if ( snap ) {
							resultEl.innerHTML += '<p>Restore from <strong>' + escHtml( snap ) + '</strong> via the Backups tab.</p>';
						}
						resolve();
					},
				} );
			} );
		},

		renderResult: function ( container, result, snapshotFilename ) {
			container.style.display = 'block';
			container.className = 'ewpm-export-result ewpm-export-result--success';

			var html = '<h3>Import complete!</h3>';
			html += '<p>Source: ' + escHtml( result.source_url || '' ) + ' &rarr; ' + escHtml( result.destination_url || '' ) + '</p>';
			html += '<p>DB statements: ' + ( result.db_statements || 0 ).toLocaleString();
			html += ' &middot; Files extracted: ' + ( result.files_extracted || 0 ).toLocaleString() + '</p>';

			if ( snapshotFilename ) {
				html += '<p>Safety snapshot: <strong>' + escHtml( snapshotFilename ) + '</strong>. To roll back, go to the Backups tab.</p>';
			}

			if ( result.note ) {
				html += '<p><strong>' + escHtml( result.note ) + '</strong></p>';
			}

			if ( result.db_errors && result.db_errors.length > 0 ) {
				html += '<details class="ewpm-export-warnings"><summary>DB Errors (' + result.db_errors.length + ')</summary><ul>';
				result.db_errors.slice( 0, 20 ).forEach( function ( e ) { html += '<li>' + escHtml( e.error || '' ) + '</li>'; } );
				html += '</ul></details>';
			}

			if ( result.warnings && result.warnings.length > 0 ) {
				html += '<details class="ewpm-export-warnings"><summary>Warnings (' + result.warnings.length + ')</summary><ul>';
				result.warnings.forEach( function ( w ) { html += '<li>' + escHtml( w ) + '</li>'; } );
				html += '</ul></details>';
			}

			html += '<div style="margin-top:16px;">';
			html += '<p><strong>Post-import checklist:</strong></p><ul>';
			html += '<li>Visit your front-end to verify the site loads</li>';
			html += '<li>Check permalink settings (Settings &rarr; Permalinks &rarr; Save)</li>';
			html += '<li>Verify active plugins and theme</li>';
			html += '</ul></div>';

			container.innerHTML = html;
		},

		checkResume: function () {
			var jobId = sessionStorage.getItem( 'ewpm_import_job_id' );
			if ( ! jobId ) { return; }

			var progressArea = document.getElementById( 'ewpm-import-progress-area' );
			var progressEl   = document.getElementById( 'ewpm-import-progress' );
			var cancelBtn    = document.getElementById( 'ewpm-import-cancel' );
			var resultEl     = document.getElementById( 'ewpm-import-result' );
			var step1        = document.getElementById( 'ewpm-import-step1' );
			var step2        = document.getElementById( 'ewpm-import-step2' );
			var step3        = document.getElementById( 'ewpm-import-step3' );

			step1.style.display = 'none';
			step2.style.display = 'none';
			step3.style.display = 'none';
			progressArea.style.display = 'block';
			cancelBtn.style.display = 'inline-block';

			var snap = sessionStorage.getItem( 'ewpm_import_snapshot' );

			EWPM.Job.resume( {
				job_id: jobId,
				onProgress: function ( data ) { EWPM.UI.renderProgress( progressEl, data ); },
				onDone: function ( result ) {
					cancelBtn.style.display = 'none';
					sessionStorage.removeItem( 'ewpm_import_running' );
					sessionStorage.removeItem( 'ewpm_import_job_id' );
					EWPM.Import.renderResult( resultEl, result, snap );
				},
				onError: function ( err ) {
					cancelBtn.style.display = 'none';
					sessionStorage.removeItem( 'ewpm_import_running' );
					sessionStorage.removeItem( 'ewpm_import_job_id' );
					resultEl.style.display = 'block';
					resultEl.className = 'ewpm-export-result ewpm-export-result--error';
					resultEl.innerHTML = '<h3>Import failed</h3><p>' + escHtml( err.message ) + '</p>';
				},
				onCancel: function ( data ) {
					cancelBtn.style.display = 'none';
					sessionStorage.removeItem( 'ewpm_import_running' );
					sessionStorage.removeItem( 'ewpm_import_job_id' );
					EWPM.UI.renderProgress( progressEl, data );
				},
			} );
		},
	};

	/* ------------------------------------------------------------------ */
	/*  Auto-init on DOMContentLoaded                                      */
	/* ------------------------------------------------------------------ */

	document.addEventListener( 'DOMContentLoaded', function () {
		EWPM.Export.init();
		EWPM.Import.init();
	} );

} )();
