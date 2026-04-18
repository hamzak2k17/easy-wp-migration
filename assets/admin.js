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
			var urlPanel     = document.getElementById( 'ewpm-import-url-panel' );

			radios.forEach( function ( r ) {
				r.addEventListener( 'change', function () {
					uploadPanel.style.display = r.value === 'upload' && r.checked ? 'block' : 'none';
					backupPanel.style.display = r.value === 'backup' && r.checked ? 'block' : 'none';
					if ( urlPanel ) { urlPanel.style.display = r.value === 'url' && r.checked ? 'block' : 'none'; }
				} );
			} );

			this.bindUrlPull();
		},

		bindUrlPull: function () {
			var checkBtn   = document.getElementById( 'ewpm-import-url-check' );
			var urlInput   = document.getElementById( 'ewpm-import-url-input' );
			var probeResult = document.getElementById( 'ewpm-import-url-probe-result' );
			var pullProgress = document.getElementById( 'ewpm-import-url-pull-progress' );

			if ( ! checkBtn || ! urlInput ) { return; }

			checkBtn.addEventListener( 'click', function () {
				var url = urlInput.value.trim();

				if ( ! url || ( ! url.startsWith( 'http://' ) && ! url.startsWith( 'https://' ) ) ) {
					probeResult.innerHTML = '<p class="ewpm-export-result--error" style="padding:8px;">Enter a valid http:// or https:// URL.</p>';
					return;
				}

				checkBtn.disabled = true;
				probeResult.innerHTML = '<p>Checking link...</p>';

				ajaxPost( 'ewpm_probe_migration_url', { url: url } ).then( function ( data ) {
					checkBtn.disabled = false;
					var html = '<div class="ewpm-dev-result ewpm-dev-result--success" style="margin-top:8px;">';
					html += '<strong>Link is valid.</strong> ';
					html += 'File size: ' + escHtml( data.size_human || 'unknown' );
					if ( data.supports_range ) { html += ' (resumable)'; }
					if ( data.filename_hint ) { html += '<br>Filename: ' + escHtml( data.filename_hint ); }
					if ( data.disk_warning ) { html += '<br><span style="color:#dba617;">' + escHtml( data.disk_warning ) + '</span>'; }
					html += '</div>';
					html += '<button type="button" class="button button-primary" id="ewpm-import-url-start-pull" style="margin-top:10px;">Start Pull</button>';
					probeResult.innerHTML = html;

					document.getElementById( 'ewpm-import-url-start-pull' ).addEventListener( 'click', function () {
						EWPM.Import.startUrlPull( url );
					} );
				} ).catch( function ( err ) {
					checkBtn.disabled = false;
					var msg = err.message || 'Unknown error';
					probeResult.innerHTML = '<div class="ewpm-dev-result ewpm-dev-result--error" style="margin-top:8px;">' + escHtml( msg ) + '</div>';
				} );
			} );
		},

		startUrlPull: function ( url ) {
			var probeResult  = document.getElementById( 'ewpm-import-url-probe-result' );
			var pullProgress = document.getElementById( 'ewpm-import-url-pull-progress' );

			probeResult.innerHTML = '';
			pullProgress.style.display = 'block';
			pullProgress.innerHTML     = '';

			var self = this;

			EWPM.Job.start( {
				job_type: 'url_pull',
				params: { url: url },

				onProgress: function ( data ) {
					EWPM.UI.renderProgress( pullProgress, data );
				},

				onDone: function ( result ) {
					pullProgress.style.display = 'none';
					self.archivePath = result.pulled_path;
					self.loadPreview( result.pulled_path );
				},

				onError: function ( err ) {
					pullProgress.innerHTML = '<div class="ewpm-dev-result ewpm-dev-result--error">Pull failed: ' + escHtml( err.message ) + '</div>';
				},

				onCancel: function ( data ) {
					EWPM.UI.renderProgress( pullProgress, data );
					pullProgress.innerHTML += '<p>Pull cancelled.</p>';
				},
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
	/*  EWPM.Backups — backups tab logic                                   */
	/* ------------------------------------------------------------------ */

	EWPM.Backups = {
		allBackups: [],
		selectedFiles: [],
		restoreTarget: null,

		init: function () {
			if ( ! document.getElementById( 'ewpm-backups-page' ) ) { return; }
			this.loadList();
			this.bindFilters();
			this.bindRestoreModal();
			this.bindDeleteModal();
			this.bindCleanup();
			this.bindBulkDelete();
		},

		loadList: function () {
			var self = this;
			ajaxPost( 'ewpm_list_backups', {} ).then( function ( data ) {
				self.allBackups = data;
				self.renderList( data );
				self.updateSummary( data );
			} ).catch( function ( err ) {
				document.getElementById( 'ewpm-backups-list' ).innerHTML =
					'<p class="ewpm-export-result--error">Failed to load backups: ' + escHtml( err.message ) + '</p>';
			} );
		},

		renderList: function ( backups ) {
			var container = document.getElementById( 'ewpm-backups-list' );

			if ( backups.length === 0 ) {
				container.innerHTML = '<p>' + escHtml( 'No backups yet. Create one via the Export tab, or they\'ll be created automatically before each import.' ) + '</p>';
				return;
			}

			var downloadNonce = config.nonce;
			var html = '<table class="ewpm-backups-table"><thead><tr>';
			html += '<th><input type="checkbox" id="ewpm-backups-select-all"></th>';
			html += '<th>Type</th><th>Filename</th><th>Source</th><th>Size</th><th>Created</th><th>Actions</th>';
			html += '</tr></thead><tbody>';

			backups.forEach( function ( b ) {
				var typeLabel = b.is_auto_snapshot ? 'Auto' : 'User';
				var typeCls   = b.is_auto_snapshot ? 'ewpm-backup-type--auto' : 'ewpm-backup-type--user';
				var source    = b.source_url || ( b.metadata_error ? 'unreadable' : '—' );

				html += '<tr data-filename="' + escHtml( b.filename ) + '" data-auto="' + ( b.is_auto_snapshot ? '1' : '0' ) + '">';
				html += '<td><input type="checkbox" class="ewpm-backup-check" value="' + escHtml( b.filename ) + '"></td>';
				html += '<td><span class="ewpm-backup-type ' + typeCls + '">' + typeLabel + '</span></td>';
				html += '<td class="ewpm-backup-filename">' + escHtml( b.filename ) + '</td>';
				html += '<td>' + escHtml( source ) + '</td>';
				html += '<td>' + escHtml( b.size_human ) + '</td>';
				html += '<td title="' + escHtml( b.date ) + '">' + escHtml( b.created_human ) + '</td>';
				html += '<td class="ewpm-backup-actions">';
				html += '<button class="button button-small ewpm-backup-restore" data-path="' + escHtml( b.path ) + '">Restore</button> ';

				var dlUrl = config.ajaxUrl + '?action=ewpm_download_archive&backup_filename=' + encodeURIComponent( b.filename ) + '&_wpnonce=' + downloadNonce;
				html += '<a href="' + dlUrl + '" class="button button-small">Download</a> ';
				html += '<button class="button button-small ewpm-backup-delete" data-filename="' + escHtml( b.filename ) + '">Delete</button> ';
				html += '<button class="button button-small ewpm-backup-miglink" data-filename="' + escHtml( b.filename ) + '">Migration Link</button>';
				html += '<div class="ewpm-backup-row-progress" style="display:none;"></div>';
				html += '<div class="ewpm-backup-row-result" style="display:none;"></div>';
				html += '</td></tr>';

				// Details row.
				html += '<tr class="ewpm-backup-details-row" style="display:none;" data-detail-for="' + escHtml( b.filename ) + '">';
				html += '<td colspan="7"><div class="ewpm-backup-details">';

				if ( b.metadata ) {
					var m = b.metadata;
					var s = m.source || {};
					html += '<table class="ewpm-preview-table">';
					html += '<tr><td>Source URL</td><td>' + escHtml( s.site_url || '' ) + '</td></tr>';
					html += '<tr><td>WP Version</td><td>' + escHtml( s.wp_version || '' ) + '</td></tr>';
					html += '<tr><td>PHP</td><td>' + escHtml( s.php_version || '' ) + '</td></tr>';
					html += '<tr><td>Plugin Version</td><td>' + escHtml( m.plugin_version || '' ) + '</td></tr>';
					html += '<tr><td>Tables</td><td>' + ( m.stats?.db_tables || 0 ) + '</td></tr>';
					html += '<tr><td>DB Rows</td><td>' + ( m.stats?.db_rows || 0 ).toLocaleString() + '</td></tr>';
					html += '<tr><td>Files</td><td>' + ( m.stats?.total_files || 0 ).toLocaleString() + '</td></tr>';
					html += '</table>';
				} else {
					html += '<p>Metadata: ' + escHtml( b.metadata_error || 'unreadable' ) + '</p>';
				}

				html += '</div></td></tr>';
			} );

			html += '</tbody></table>';
			container.innerHTML = html;

			// Bind row events.
			container.querySelectorAll( '.ewpm-backup-filename' ).forEach( function ( el ) {
				el.style.cursor = 'pointer';
				el.addEventListener( 'click', function () {
					var fn = el.closest( 'tr' ).dataset.filename;
					var detail = container.querySelector( 'tr[data-detail-for="' + fn + '"]' );
					if ( detail ) { detail.style.display = detail.style.display === 'none' ? '' : 'none'; }
				} );
			} );

			container.querySelectorAll( '.ewpm-backup-restore' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					EWPM.Backups.restoreTarget = { path: btn.dataset.path, row: btn.closest( 'tr' ) };
					EWPM.Backups.showRestoreModal();
				} );
			} );

			container.querySelectorAll( '.ewpm-backup-delete' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					EWPM.Backups.showDeleteModal( btn.dataset.filename, btn.closest( 'tr' ) );
				} );
			} );

			container.querySelectorAll( '.ewpm-backup-miglink' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					EWPM.MigLinks.showGenerateModal( btn.dataset.filename );
				} );
			} );

			// Select all checkbox.
			var selectAll = document.getElementById( 'ewpm-backups-select-all' );
			if ( selectAll ) {
				selectAll.addEventListener( 'change', function () {
					container.querySelectorAll( '.ewpm-backup-check' ).forEach( function ( cb ) { cb.checked = selectAll.checked; } );
					EWPM.Backups.updateBulkBar();
				} );
			}

			container.querySelectorAll( '.ewpm-backup-check' ).forEach( function ( cb ) {
				cb.addEventListener( 'change', function () { EWPM.Backups.updateBulkBar(); } );
			} );
		},

		updateSummary: function ( backups ) {
			var total = backups.reduce( function ( sum, b ) { return sum + b.size_bytes; }, 0 );
			var el = document.getElementById( 'ewpm-backups-storage-summary' );
			if ( el ) { el.textContent = backups.length + ' backups using ' + formatBytes( total ); }
		},

		updateBulkBar: function () {
			var checked = document.querySelectorAll( '.ewpm-backup-check:checked' );
			var bar     = document.querySelector( '.ewpm-backups-bulk' );
			var count   = document.getElementById( 'ewpm-backups-selected-count' );

			if ( checked.length > 0 ) {
				bar.style.display = 'flex';
				count.textContent = checked.length + ' selected';
			} else {
				bar.style.display = 'none';
			}
		},

		bindFilters: function () {
			var self    = this;
			var radios  = document.querySelectorAll( 'input[name="ewpm_backup_filter"]' );
			var search  = document.getElementById( 'ewpm-backups-search' );

			function applyFilter() {
				var filter = document.querySelector( 'input[name="ewpm_backup_filter"]:checked' );
				var query  = search ? search.value.toLowerCase() : '';
				var filtered = self.allBackups.filter( function ( b ) {
					if ( filter && filter.value === 'user' && b.is_auto_snapshot ) { return false; }
					if ( filter && filter.value === 'auto' && ! b.is_auto_snapshot ) { return false; }
					if ( query && b.filename.toLowerCase().indexOf( query ) === -1 ) { return false; }
					return true;
				} );
				self.renderList( filtered );
			}

			radios.forEach( function ( r ) { r.addEventListener( 'change', applyFilter ); } );
			if ( search ) { search.addEventListener( 'input', applyFilter ); }
		},

		showRestoreModal: function () {
			var modal      = document.getElementById( 'ewpm-restore-modal' );
			var confirmBtn = document.getElementById( 'ewpm-restore-modal-confirm' );
			var input      = document.getElementById( 'ewpm-restore-confirm-input' );
			modal.style.display = 'flex';
			modal.querySelectorAll( '.ewpm-restore-check' ).forEach( function ( cb ) { cb.checked = false; } );
			input.value = '';
			confirmBtn.disabled = true;
		},

		bindRestoreModal: function () {
			var modal      = document.getElementById( 'ewpm-restore-modal' );
			var confirmBtn = document.getElementById( 'ewpm-restore-modal-confirm' );
			var cancelBtn  = document.getElementById( 'ewpm-restore-modal-cancel' );
			var input      = document.getElementById( 'ewpm-restore-confirm-input' );
			if ( ! modal ) { return; }

			function update() {
				var allChecked = true;
				modal.querySelectorAll( '.ewpm-restore-check' ).forEach( function ( cb ) { if ( ! cb.checked ) { allChecked = false; } } );
				confirmBtn.disabled = ! ( allChecked && input.value === 'IMPORT' );
			}

			modal.querySelectorAll( '.ewpm-restore-check' ).forEach( function ( cb ) { cb.addEventListener( 'change', update ); } );
			input.addEventListener( 'input', update );
			cancelBtn.addEventListener( 'click', function () { modal.style.display = 'none'; } );

			confirmBtn.addEventListener( 'click', function () {
				modal.style.display = 'none';
				EWPM.Backups.runRestore();
			} );
		},

		runRestore: function () {
			var target    = this.restoreTarget;
			if ( ! target ) { return; }

			var row       = target.row;
			var progEl    = row.querySelector( '.ewpm-backup-row-progress' );
			var resultEl  = row.querySelector( '.ewpm-backup-row-result' );
			var doSnap    = document.getElementById( 'ewpm-restore-auto-snapshot' ).checked;

			progEl.style.display = 'block';
			progEl.innerHTML     = '';
			resultEl.style.display = 'none';

			( async function () {
				try {
					var snapFilename = null;
					if ( doSnap ) {
						EWPM.UI.renderProgress( progEl, { phase_label: 'Creating safety snapshot...', progress_percent: 0, progress_label: 'Backing up before restore...' } );
						var snapResult = await EWPM.Import.runSnapshot( progEl );
						snapFilename = snapResult.filename;
					}

					await new Promise( function ( resolve, reject ) {
						EWPM.Job.start( {
							job_type: 'import',
							params: { archive_path: target.path, conflict_strategy: 'overwrite', replace_paths: false, stop_on_db_error: false },
							onProgress: function ( data ) { EWPM.UI.renderProgress( progEl, data ); },
							onDone: function ( result ) {
								progEl.style.display = 'none';
								resultEl.style.display = 'block';
								resultEl.className = 'ewpm-backup-row-result ewpm-dev-result ewpm-dev-result--success';
								var html = '<strong>Restore complete!</strong> ' + ( result.db_statements || 0 ) + ' statements, ' + ( result.files_extracted || 0 ) + ' files.';
								if ( snapFilename ) { html += ' Snapshot: ' + escHtml( snapFilename ); }
								resultEl.innerHTML = html;
								resolve( result );
							},
							onError: function ( err ) {
								progEl.style.display = 'none';
								resultEl.style.display = 'block';
								resultEl.className = 'ewpm-backup-row-result ewpm-dev-result ewpm-dev-result--error';
								resultEl.innerHTML = 'Restore failed: ' + escHtml( err.message );
								if ( snapFilename ) { resultEl.innerHTML += '<br>Safety snapshot: ' + escHtml( snapFilename ); }
								reject( err );
							},
							onCancel: function () {
								progEl.style.display = 'none';
								resultEl.style.display = 'block';
								resultEl.className = 'ewpm-backup-row-result ewpm-dev-result ewpm-dev-result--error';
								resultEl.textContent = 'Restore cancelled.';
								resolve();
							},
						} );
					} );
				} catch ( err ) {
					resultEl.style.display = 'block';
					resultEl.className = 'ewpm-backup-row-result ewpm-dev-result ewpm-dev-result--error';
					resultEl.innerHTML = 'Error: ' + escHtml( err.message );
				}
			} )();
		},

		deleteTarget: null,
		deleteRow: null,

		showDeleteModal: function ( filename, row ) {
			this.deleteTarget = filename;
			this.deleteRow    = row;
			var modal = document.getElementById( 'ewpm-delete-modal' );
			document.getElementById( 'ewpm-delete-modal-message' ).textContent = 'Delete "' + filename + '" permanently? This cannot be undone.';
			modal.style.display = 'flex';
		},

		bindDeleteModal: function () {
			var modal      = document.getElementById( 'ewpm-delete-modal' );
			var confirmBtn = document.getElementById( 'ewpm-delete-modal-confirm' );
			var cancelBtn  = document.getElementById( 'ewpm-delete-modal-cancel' );
			if ( ! modal ) { return; }

			cancelBtn.addEventListener( 'click', function () { modal.style.display = 'none'; } );
			confirmBtn.addEventListener( 'click', function () {
				modal.style.display = 'none';
				var fn  = EWPM.Backups.deleteTarget;
				var row = EWPM.Backups.deleteRow;

				ajaxPost( 'ewpm_delete_backup', { filename: fn } ).then( function () {
					if ( row ) { row.style.opacity = '0.3'; setTimeout( function () { row.remove(); var detailRow = document.querySelector( 'tr[data-detail-for="' + fn + '"]' ); if ( detailRow ) { detailRow.remove(); } }, 300 ); }
					EWPM.Backups.allBackups = EWPM.Backups.allBackups.filter( function ( b ) { return b.filename !== fn; } );
					EWPM.Backups.updateSummary( EWPM.Backups.allBackups );
				} ).catch( function ( err ) {
					alert( 'Delete failed: ' + err.message ); // eslint-disable-line no-alert
				} );
			} );
		},

		bindBulkDelete: function () {
			var btn = document.getElementById( 'ewpm-backups-bulk-delete' );
			if ( ! btn ) { return; }

			btn.addEventListener( 'click', function () {
				var checked = document.querySelectorAll( '.ewpm-backup-check:checked' );
				var names   = Array.from( checked ).map( function ( cb ) { return cb.value; } );
				if ( names.length === 0 ) { return; }
				if ( ! confirm( 'Delete ' + names.length + ' backup(s) permanently?' ) ) { return; } // eslint-disable-line no-alert

				ajaxPost( 'ewpm_delete_backups_bulk', { filenames: names } ).then( function ( data ) {
					data.deleted.forEach( function ( fn ) {
						var row = document.querySelector( 'tr[data-filename="' + fn + '"]' );
						if ( row ) { row.remove(); }
						var detail = document.querySelector( 'tr[data-detail-for="' + fn + '"]' );
						if ( detail ) { detail.remove(); }
					} );
					EWPM.Backups.allBackups = EWPM.Backups.allBackups.filter( function ( b ) { return ! data.deleted.includes( b.filename ); } );
					EWPM.Backups.updateSummary( EWPM.Backups.allBackups );
					EWPM.Backups.updateBulkBar();

					if ( data.failed.length > 0 ) {
						alert( 'Some deletes failed: ' + data.failed.map( function ( f ) { return f.filename + ': ' + f.error; } ).join( '\n' ) ); // eslint-disable-line no-alert
					}
				} );
			} );
		},

		bindCleanup: function () {
			var btn    = document.getElementById( 'ewpm-run-cleanup-now' );
			var result = document.getElementById( 'ewpm-cleanup-result' );
			if ( ! btn ) { return; }

			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				ajaxPost( 'ewpm_run_cleanup_now', {} ).then( function ( data ) {
					btn.disabled = false;
					result.textContent = 'Cleaned up ' + data.deleted.length + ' auto-snapshot(s), freed ' + data.freed_human + '.';
					if ( data.deleted.length > 0 ) { EWPM.Backups.loadList(); }
				} ).catch( function ( err ) {
					btn.disabled = false;
					result.textContent = 'Error: ' + err.message;
				} );
			} );
		},
	};

	/* ------------------------------------------------------------------ */
	/*  EWPM.MigLinks — migration link management                         */
	/* ------------------------------------------------------------------ */

	EWPM.MigLinks = {
		init: function () {
			if ( ! document.getElementById( 'ewpm-backups-page' ) ) { return; }
			this.bindGenerateModal();
			this.bindRevokeAll();
			this.loadLinks();
		},

		showGenerateModal: function ( filename ) {
			var modal = document.getElementById( 'ewpm-miglink-modal' );
			document.getElementById( 'ewpm-miglink-filename' ).textContent = filename;
			document.getElementById( 'ewpm-miglink-form' ).style.display = 'block';
			document.getElementById( 'ewpm-miglink-result' ).style.display = 'none';
			modal.dataset.filename = filename;
			modal.style.display = 'flex';
		},

		bindGenerateModal: function () {
			var modal       = document.getElementById( 'ewpm-miglink-modal' );
			var cancelBtn   = document.getElementById( 'ewpm-miglink-modal-cancel' );
			var generateBtn = document.getElementById( 'ewpm-miglink-generate' );
			var doneBtn     = document.getElementById( 'ewpm-miglink-done' );
			var copyBtn     = document.getElementById( 'ewpm-miglink-copy' );
			var expirySelect = document.getElementById( 'ewpm-miglink-expiry' );
			var customWrap   = document.getElementById( 'ewpm-miglink-custom-wrap' );
			var longWarning  = document.getElementById( 'ewpm-miglink-long-warning' );

			if ( ! modal ) { return; }

			expirySelect.addEventListener( 'change', function () {
				customWrap.style.display = expirySelect.value === 'custom' ? 'inline' : 'none';
				longWarning.style.display = ( expirySelect.value === '604800' || expirySelect.value === 'custom' ) ? 'block' : 'none';
			} );

			cancelBtn.addEventListener( 'click', function () { modal.style.display = 'none'; } );
			doneBtn.addEventListener( 'click', function () { modal.style.display = 'none'; EWPM.MigLinks.loadLinks(); } );

			generateBtn.addEventListener( 'click', function () {
				var ttl;
				if ( expirySelect.value === 'custom' ) {
					var val  = parseInt( document.getElementById( 'ewpm-miglink-custom-val' ).value, 10 ) || 24;
					var unit = parseInt( document.getElementById( 'ewpm-miglink-custom-unit' ).value, 10 ) || 3600;
					ttl = val * unit;
				} else {
					ttl = parseInt( expirySelect.value, 10 );
				}

				generateBtn.disabled = true;

				ajaxPost( 'ewpm_generate_migration_link', {
					filename: modal.dataset.filename,
					ttl_seconds: ttl,
				} ).then( function ( data ) {
					generateBtn.disabled = false;
					document.getElementById( 'ewpm-miglink-form' ).style.display = 'none';
					document.getElementById( 'ewpm-miglink-result' ).style.display = 'block';
					document.getElementById( 'ewpm-miglink-url' ).value = data.url_pretty;
					document.getElementById( 'ewpm-miglink-url-fallback' ).value = data.url_fallback;

					EWPM.MigLinks.startCountdown( data.expires_at );
				} ).catch( function ( err ) {
					generateBtn.disabled = false;
					alert( 'Error: ' + err.message ); // eslint-disable-line no-alert
				} );
			} );

			copyBtn.addEventListener( 'click', function () {
				var url = document.getElementById( 'ewpm-miglink-url' ).value;
				if ( navigator.clipboard ) {
					navigator.clipboard.writeText( url ).then( function () {
						copyBtn.textContent = 'Copied!';
						setTimeout( function () { copyBtn.textContent = 'Copy'; }, 2000 );
					} );
				} else {
					var input = document.getElementById( 'ewpm-miglink-url' );
					input.select();
					document.execCommand( 'copy' );
					copyBtn.textContent = 'Copied!';
					setTimeout( function () { copyBtn.textContent = 'Copy'; }, 2000 );
				}
			} );
		},

		startCountdown: function ( expiresAt ) {
			var el = document.getElementById( 'ewpm-miglink-expiry-countdown' );
			function update() {
				var remaining = expiresAt - Math.floor( Date.now() / 1000 );
				if ( remaining <= 0 ) { el.textContent = 'Expired'; return; }
				var h = Math.floor( remaining / 3600 );
				var m = Math.floor( ( remaining % 3600 ) / 60 );
				el.textContent = 'Expires in: ' + h + 'h ' + m + 'm';
				setTimeout( update, 30000 );
			}
			update();
		},

		loadLinks: function () {
			var container = document.getElementById( 'ewpm-miglinks-table-container' );
			var badge     = document.getElementById( 'ewpm-miglinks-badge' );
			var details   = document.getElementById( 'ewpm-miglinks-details' );

			if ( ! container ) { return; }

			ajaxPost( 'ewpm_list_migration_links', {} ).then( function ( data ) {
				if ( ! data || data.length === 0 ) {
					container.innerHTML = '<p>No migration links generated yet.</p>';
					badge.style.display = 'none';
					return;
				}

				var active = data.filter( function ( l ) { return l.status === 'Active'; } ).length;
				if ( active > 0 ) {
					badge.textContent = ' (' + active + ' active)';
					badge.style.display = 'inline';
					details.open = true;
				} else {
					badge.style.display = 'none';
				}

				var html = '<table class="ewpm-backups-table"><thead><tr>';
				html += '<th>Status</th><th>Filename</th><th>Created</th><th>Expires</th><th>Accessed</th><th>Actions</th>';
				html += '</tr></thead><tbody>';

				data.forEach( function ( l ) {
					var statusCls = { Active: 'ewpm-status--active', Expired: 'ewpm-status--expired', Revoked: 'ewpm-status--revoked', 'File Missing': 'ewpm-status--missing' }[ l.status ] || '';

					var expiresIn = '';
					var remaining = ( l.expires_at || 0 ) - Math.floor( Date.now() / 1000 );
					if ( l.status === 'Active' ) {
						var h = Math.floor( remaining / 3600 );
						var m = Math.floor( ( remaining % 3600 ) / 60 );
						expiresIn = h + 'h ' + m + 'm';
					} else if ( l.status === 'Expired' ) {
						expiresIn = 'expired';
					} else {
						expiresIn = '—';
					}

					var created = new Date( ( l.created_at || 0 ) * 1000 ).toLocaleString();
					var accessed = l.access_count ? l.access_count + 'x' + ( l.last_access_ip ? ' (' + l.last_access_ip + ')' : '' ) : '—';

					html += '<tr>';
					html += '<td><span class="ewpm-status-badge ' + statusCls + '">' + escHtml( l.status ) + '</span></td>';
					html += '<td class="ewpm-backup-filename">' + escHtml( l.filename || '' ) + '</td>';
					html += '<td>' + escHtml( created ) + '</td>';
					html += '<td>' + escHtml( expiresIn ) + '</td>';
					html += '<td>' + accessed + '</td>';
					html += '<td>';
					if ( l.status === 'Active' ) {
						html += '<button class="button button-small ewpm-miglink-revoke" data-tid="' + escHtml( l.tid ) + '">Revoke</button>';
					}
					html += '</td></tr>';
				} );

				html += '</tbody></table>';
				container.innerHTML = html;

				container.querySelectorAll( '.ewpm-miglink-revoke' ).forEach( function ( btn ) {
					btn.addEventListener( 'click', function () {
						btn.disabled = true;
						ajaxPost( 'ewpm_revoke_migration_link', { tid: btn.dataset.tid } ).then( function () {
							EWPM.MigLinks.loadLinks();
						} ).catch( function ( err ) {
							btn.disabled = false;
							alert( 'Revoke failed: ' + err.message ); // eslint-disable-line no-alert
						} );
					} );
				} );
			} ).catch( function () {
				container.innerHTML = '<p>Failed to load migration links.</p>';
			} );
		},

		bindRevokeAll: function () {
			var btn = document.getElementById( 'ewpm-revoke-all-links' );
			if ( ! btn ) { return; }

			btn.addEventListener( 'click', function () {
				if ( ! confirm( 'Revoke ALL migration links? This regenerates the secret key and instantly invalidates every existing link.' ) ) { return; } // eslint-disable-line no-alert

				ajaxPost( 'ewpm_revoke_all_migration_links', {} ).then( function ( data ) {
					alert( data.message || 'All links revoked.' ); // eslint-disable-line no-alert
					EWPM.MigLinks.loadLinks();
				} );
			} );
		},
	};

	/* ------------------------------------------------------------------ */
	/*  Auto-init on DOMContentLoaded                                      */
	/* ------------------------------------------------------------------ */

	document.addEventListener( 'DOMContentLoaded', function () {
		EWPM.Export.init();
		EWPM.Import.init();
		EWPM.Backups.init();
		EWPM.MigLinks.init();
	} );

} )();
