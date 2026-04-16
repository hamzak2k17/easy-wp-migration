<?php
/**
 * Dev Tools admin page template.
 *
 * Only accessible when both EWPM_DEV_MODE and WP_DEBUG are true.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

$state_manager = new EWPM_State();
$job_ids       = $state_manager->list_all();
?>

<div class="wrap ewpm-wrap">
	<h1><?php esc_html_e( 'Dev Tools', 'easy-wp-migration' ); ?></h1>

	<div class="ewpm-dev-warning">
		<?php esc_html_e( 'Dev Mode — do not leave enabled in production. Remove EWPM_DEV_MODE from wp-config.php when done.', 'easy-wp-migration' ); ?>
	</div>

	<!-- Dummy Job Test -->
	<div class="ewpm-dev-section">
		<h3><?php esc_html_e( 'Dummy Job Test', 'easy-wp-migration' ); ?></h3>

		<div class="ewpm-dev-controls">
			<label for="ewpm-dummy-delay">
				<?php esc_html_e( 'Delay per tick (ms):', 'easy-wp-migration' ); ?>
			</label>
			<input type="number" id="ewpm-dummy-delay" value="50" min="0" max="5000" step="10">

			<button type="button" class="button button-primary" id="ewpm-dummy-start">
				<?php esc_html_e( 'Start Dummy Job', 'easy-wp-migration' ); ?>
			</button>

			<button type="button" class="button ewpm-cancel-btn" id="ewpm-dummy-cancel" style="display:none;">
				<?php esc_html_e( 'Cancel', 'easy-wp-migration' ); ?>
			</button>
		</div>

		<div id="ewpm-dummy-progress"></div>
		<div id="ewpm-dummy-result"></div>
	</div>

	<!-- Database Export Test -->
	<div class="ewpm-dev-section">
		<h3><?php esc_html_e( 'Database Export Test', 'easy-wp-migration' ); ?></h3>

		<div class="ewpm-dev-controls">
			<label for="ewpm-dbexport-chunk">
				<?php esc_html_e( 'Chunk size (rows per query):', 'easy-wp-migration' ); ?>
			</label>
			<input type="number" id="ewpm-dbexport-chunk" value="1000" min="100" max="10000" step="100">

			<button type="button" class="button button-primary" id="ewpm-dbexport-start">
				<?php esc_html_e( 'Start DB Export', 'easy-wp-migration' ); ?>
			</button>

			<button type="button" class="button ewpm-cancel-btn" id="ewpm-dbexport-cancel" style="display:none;">
				<?php esc_html_e( 'Cancel', 'easy-wp-migration' ); ?>
			</button>
		</div>

		<div id="ewpm-dbexport-progress"></div>
		<div id="ewpm-dbexport-result"></div>
	</div>

	<!-- Import Test -->
	<div class="ewpm-dev-section">
		<h3><?php esc_html_e( 'Import Test', 'easy-wp-migration' ); ?></h3>

		<div class="ewpm-dev-warning" style="margin-bottom: 12px;">
			<?php esc_html_e( 'Import will overwrite your current site. This dev tool has NO auto-snapshot. If you cancel mid-flight, your site will be broken. Take a manual backup first via the Export tab.', 'easy-wp-migration' ); ?>
		</div>

		<div class="ewpm-dev-controls" style="flex-wrap: wrap;">
			<label for="ewpm-import-archive">
				<?php esc_html_e( 'Pick archive:', 'easy-wp-migration' ); ?>
			</label>
			<select id="ewpm-import-archive">
				<option value=""><?php esc_html_e( '-- loading --', 'easy-wp-migration' ); ?></option>
			</select>

			<label for="ewpm-import-conflict">
				<?php esc_html_e( 'Conflict:', 'easy-wp-migration' ); ?>
			</label>
			<select id="ewpm-import-conflict">
				<option value="overwrite"><?php esc_html_e( 'Overwrite', 'easy-wp-migration' ); ?></option>
				<option value="skip"><?php esc_html_e( 'Skip', 'easy-wp-migration' ); ?></option>
				<option value="rename-old"><?php esc_html_e( 'Rename old', 'easy-wp-migration' ); ?></option>
			</select>

			<label>
				<input type="checkbox" id="ewpm-import-replace-paths">
				<?php esc_html_e( 'Replace filesystem paths', 'easy-wp-migration' ); ?>
			</label>

			<label>
				<input type="checkbox" id="ewpm-import-stop-error">
				<?php esc_html_e( 'Stop on first DB error', 'easy-wp-migration' ); ?>
			</label>
		</div>

		<div class="ewpm-dev-controls" style="margin-top: 8px;">
			<label for="ewpm-import-confirm">
				<?php esc_html_e( 'Type IMPORT to enable:', 'easy-wp-migration' ); ?>
			</label>
			<input type="text" id="ewpm-import-confirm" placeholder="IMPORT" style="width: 100px;">

			<button type="button" class="button button-primary" id="ewpm-import-start" disabled>
				<?php esc_html_e( 'Start Import Test', 'easy-wp-migration' ); ?>
			</button>

			<button type="button" class="button ewpm-cancel-btn" id="ewpm-import-cancel" style="display:none;">
				<?php esc_html_e( 'Cancel', 'easy-wp-migration' ); ?>
			</button>
		</div>

		<div id="ewpm-import-progress"></div>
		<div id="ewpm-import-result"></div>
	</div>

	<!-- Active Jobs -->
	<div class="ewpm-dev-section">
		<h3><?php esc_html_e( 'Active Jobs', 'easy-wp-migration' ); ?></h3>

		<?php if ( empty( $job_ids ) ) : ?>
			<p><?php esc_html_e( 'No active job state files.', 'easy-wp-migration' ); ?></p>
		<?php else : ?>
			<table class="ewpm-jobs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Job ID', 'easy-wp-migration' ); ?></th>
						<th><?php esc_html_e( 'Type', 'easy-wp-migration' ); ?></th>
						<th><?php esc_html_e( 'Phase', 'easy-wp-migration' ); ?></th>
						<th><?php esc_html_e( 'Progress', 'easy-wp-migration' ); ?></th>
						<th><?php esc_html_e( 'Age', 'easy-wp-migration' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'easy-wp-migration' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $job_ids as $jid ) : ?>
						<?php
						try {
							$jstate = $state_manager->load( $jid );
						} catch ( EWPM_State_Exception $e ) {
							continue;
						}

						$created = strtotime( $jstate['created_at'] ?? '' );
						$age     = $created ? human_time_diff( $created, time() ) : '?';
						?>
						<tr>
							<td><code><?php echo esc_html( $jid ); ?></code></td>
							<td><?php echo esc_html( $jstate['type'] ?? '?' ); ?></td>
							<td><?php echo esc_html( $jstate['phase_label'] ?? $jstate['phase'] ?? '?' ); ?></td>
							<td><?php echo esc_html( ( $jstate['progress_percent'] ?? 0 ) . '%' ); ?></td>
							<td><?php echo esc_html( $age ); ?></td>
							<td>
								<button type="button"
									class="button button-small ewpm-dev-delete-state"
									data-job-id="<?php echo esc_attr( $jid ); ?>">
									<?php esc_html_e( 'Delete', 'easy-wp-migration' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<!-- Cleanup -->
	<div class="ewpm-dev-section">
		<h3><?php esc_html_e( 'Cleanup', 'easy-wp-migration' ); ?></h3>

		<button type="button" class="button" id="ewpm-dev-cleanup">
			<?php esc_html_e( 'Run Stale Cleanup Now', 'easy-wp-migration' ); ?>
		</button>

		<div id="ewpm-dev-cleanup-result"></div>
	</div>
</div>

<script>
( function () {
	'use strict';

	var startBtn  = document.getElementById( 'ewpm-dummy-start' );
	var cancelBtn = document.getElementById( 'ewpm-dummy-cancel' );
	var delayInput = document.getElementById( 'ewpm-dummy-delay' );
	var progressEl = document.getElementById( 'ewpm-dummy-progress' );
	var resultEl   = document.getElementById( 'ewpm-dummy-result' );
	var cleanupBtn = document.getElementById( 'ewpm-dev-cleanup' );
	var cleanupResult = document.getElementById( 'ewpm-dev-cleanup-result' );

	var jobHandle = null;

	/* -- Dummy Job --------------------------------------------------- */

	startBtn.addEventListener( 'click', function () {
		progressEl.innerHTML = '';
		resultEl.innerHTML   = '';
		startBtn.disabled    = true;
		cancelBtn.style.display = 'inline-block';

		var delayMs = parseInt( delayInput.value, 10 ) || 50;

		jobHandle = EWPM.Job.start( {
			job_type: 'dummy',
			params: { delay_ms: delayMs },

			onProgress: function ( data ) {
				EWPM.UI.renderProgress( progressEl, data );
			},

			onDone: function ( result ) {
				cancelBtn.style.display = 'none';
				startBtn.disabled = false;
				resultEl.className = 'ewpm-dev-result ewpm-dev-result--success';
				resultEl.textContent = JSON.stringify( result, null, 2 );
			},

			onError: function ( err ) {
				cancelBtn.style.display = 'none';
				startBtn.disabled = false;
				resultEl.className = 'ewpm-dev-result ewpm-dev-result--error';
				resultEl.textContent = 'Error: ' + err.message;
			},

			onCancel: function ( data ) {
				cancelBtn.style.display = 'none';
				startBtn.disabled = false;
				EWPM.UI.renderProgress( progressEl, data );
				resultEl.className = 'ewpm-dev-result';
				resultEl.textContent = 'Job cancelled.';
			},
		} );
	} );

	cancelBtn.addEventListener( 'click', function () {
		if ( jobHandle ) {
			jobHandle.cancel();
		}
	} );

	/* -- Database Export ---------------------------------------------- */

	var dbStartBtn  = document.getElementById( 'ewpm-dbexport-start' );
	var dbCancelBtn = document.getElementById( 'ewpm-dbexport-cancel' );
	var dbChunkInput = document.getElementById( 'ewpm-dbexport-chunk' );
	var dbProgressEl = document.getElementById( 'ewpm-dbexport-progress' );
	var dbResultEl   = document.getElementById( 'ewpm-dbexport-result' );

	var dbJobHandle = null;

	dbStartBtn.addEventListener( 'click', function () {
		dbProgressEl.innerHTML = '';
		dbResultEl.innerHTML   = '';
		dbStartBtn.disabled    = true;
		dbCancelBtn.style.display = 'inline-block';

		var chunkSize = parseInt( dbChunkInput.value, 10 ) || 1000;

		dbJobHandle = EWPM.Job.start( {
			job_type: 'db_export',
			params: { chunk_size: chunkSize },

			onProgress: function ( data ) {
				EWPM.UI.renderProgress( dbProgressEl, data );
			},

			onDone: function ( result ) {
				dbCancelBtn.style.display = 'none';
				dbStartBtn.disabled = false;

				var html = '<strong>Export complete!</strong><br>';
				html += 'Tables: ' + ( result.tables_count || 0 ) + '<br>';
				html += 'Rows: ' + ( result.rows_count || 0 ).toLocaleString() + '<br>';
				html += 'Size: ' + formatBytes( result.bytes || 0 ) + '<br>';

				if ( result.warnings && result.warnings.length > 0 ) {
					html += '<br><strong>Warnings:</strong><br>';
					result.warnings.forEach( function ( w ) {
						html += '- ' + escHtml( w ) + '<br>';
					} );
				}

				// Build download link using the job_id from the handle.
				var jobId = dbJobHandle ? dbJobHandle.getJobId() : '';
				if ( jobId ) {
					var downloadUrl = window.ewpmData.ajaxUrl
						+ '?action=ewpm_dev_download_sql'
						+ '&job_id=' + encodeURIComponent( jobId )
						+ '&_wpnonce=<?php echo esc_js( wp_create_nonce( 'ewpm_dev_download_sql' ) ); ?>';
					html += '<br><a href="' + escHtml( downloadUrl ) + '" class="button">Download SQL</a>';
				}

				dbResultEl.className = 'ewpm-dev-result ewpm-dev-result--success';
				dbResultEl.innerHTML = html;
			},

			onError: function ( err ) {
				dbCancelBtn.style.display = 'none';
				dbStartBtn.disabled = false;
				dbResultEl.className = 'ewpm-dev-result ewpm-dev-result--error';
				dbResultEl.textContent = 'Error: ' + err.message;
			},

			onCancel: function ( data ) {
				dbCancelBtn.style.display = 'none';
				dbStartBtn.disabled = false;
				EWPM.UI.renderProgress( dbProgressEl, data );
				dbResultEl.className = 'ewpm-dev-result';
				dbResultEl.textContent = 'DB export cancelled.';
			},
		} );
	} );

	dbCancelBtn.addEventListener( 'click', function () {
		if ( dbJobHandle ) {
			dbJobHandle.cancel();
		}
	} );

	/* -- Import Test ------------------------------------------------- */

	var impArchiveSelect = document.getElementById( 'ewpm-import-archive' );
	var impConflictSelect = document.getElementById( 'ewpm-import-conflict' );
	var impReplacePathsCb = document.getElementById( 'ewpm-import-replace-paths' );
	var impStopErrorCb = document.getElementById( 'ewpm-import-stop-error' );
	var impConfirmInput = document.getElementById( 'ewpm-import-confirm' );
	var impStartBtn = document.getElementById( 'ewpm-import-start' );
	var impCancelBtn = document.getElementById( 'ewpm-import-cancel' );
	var impProgressEl = document.getElementById( 'ewpm-import-progress' );
	var impResultEl = document.getElementById( 'ewpm-import-result' );
	var impJobHandle = null;

	// Enable start button only when user types IMPORT.
	if ( impConfirmInput && impStartBtn ) {
		impConfirmInput.addEventListener( 'input', function () {
			impStartBtn.disabled = ( impConfirmInput.value !== 'IMPORT' );
		} );
	}

	// Load backup list.
	if ( impArchiveSelect ) {
		var fd = new FormData();
		fd.append( 'action', 'ewpm_dev_list_backups' );
		fd.append( 'nonce', window.ewpmData.nonce );
		fetch( window.ewpmData.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				impArchiveSelect.innerHTML = '';
				if ( json.success && json.data.length > 0 ) {
					json.data.forEach( function ( b ) {
						var opt = document.createElement( 'option' );
						opt.value = b.path;
						opt.textContent = b.filename + ' (' + b.size_human + ', ' + b.date + ')';
						impArchiveSelect.appendChild( opt );
					} );
				} else {
					var opt = document.createElement( 'option' );
					opt.value = '';
					opt.textContent = 'No backups found. Run an export with "Save as backup" first.';
					impArchiveSelect.appendChild( opt );
				}
			} );
	}

	// Start import.
	if ( impStartBtn ) {
		impStartBtn.addEventListener( 'click', function () {
			var archivePath = impArchiveSelect ? impArchiveSelect.value : '';
			if ( ! archivePath ) { alert( 'Select an archive first.' ); return; } // eslint-disable-line no-alert

			impProgressEl.innerHTML = '';
			impResultEl.innerHTML = '';
			impStartBtn.disabled = true;
			impCancelBtn.style.display = 'inline-block';

			impJobHandle = EWPM.Job.start( {
				job_type: 'import',
				params: {
					archive_path: archivePath,
					conflict_strategy: impConflictSelect ? impConflictSelect.value : 'overwrite',
					replace_paths: impReplacePathsCb ? impReplacePathsCb.checked : false,
					stop_on_db_error: impStopErrorCb ? impStopErrorCb.checked : false,
				},

				onProgress: function ( data ) {
					EWPM.UI.renderProgress( impProgressEl, data );
				},

				onDone: function ( result ) {
					impCancelBtn.style.display = 'none';
					impStartBtn.disabled = false;
					impConfirmInput.value = '';

					var html = '<strong>Import complete!</strong><br>';
					html += 'Source: ' + escHtml( result.source_url || '' ) + '<br>';
					html += 'Destination: ' + escHtml( result.destination_url || '' ) + '<br>';
					html += 'DB statements: ' + ( result.db_statements || 0 ).toLocaleString() + '<br>';
					html += 'Files extracted: ' + ( result.files_extracted || 0 ).toLocaleString() + '<br>';

					if ( result.db_errors && result.db_errors.length > 0 ) {
						html += '<br><strong>DB Errors (' + result.db_errors.length + '):</strong><br>';
						result.db_errors.slice( 0, 10 ).forEach( function ( e ) {
							html += '- ' + escHtml( e.error || '' ) + '<br>';
						} );
					}

					if ( result.warnings && result.warnings.length > 0 ) {
						html += '<br><strong>Warnings (' + result.warnings.length + '):</strong><br>';
						result.warnings.slice( 0, 10 ).forEach( function ( w ) {
							html += '- ' + escHtml( w ) + '<br>';
						} );
					}

					if ( result.note ) {
						html += '<br><em>' + escHtml( result.note ) + '</em>';
					}

					impResultEl.className = 'ewpm-dev-result ewpm-dev-result--success';
					impResultEl.innerHTML = html;
				},

				onError: function ( err ) {
					impCancelBtn.style.display = 'none';
					impStartBtn.disabled = false;
					impConfirmInput.value = '';
					impResultEl.className = 'ewpm-dev-result ewpm-dev-result--error';
					impResultEl.textContent = 'Error: ' + err.message;
				},

				onCancel: function ( data ) {
					impCancelBtn.style.display = 'none';
					impStartBtn.disabled = false;
					impConfirmInput.value = '';
					EWPM.UI.renderProgress( impProgressEl, data );
					impResultEl.className = 'ewpm-dev-result ewpm-dev-result--error';
					impResultEl.textContent = 'Import cancelled. Your site may be in an inconsistent state.';
				},
			} );
		} );

		impCancelBtn.addEventListener( 'click', function () {
			if ( impJobHandle ) { impJobHandle.cancel(); }
		} );
	}

	/* -- Helpers ------------------------------------------------------ */

	function formatBytes( bytes ) {
		if ( bytes === 0 ) return '0 B';
		var k = 1024;
		var sizes = [ 'B', 'KB', 'MB', 'GB' ];
		var i = Math.floor( Math.log( bytes ) / Math.log( k ) );
		return parseFloat( ( bytes / Math.pow( k, i ) ).toFixed( 2 ) ) + ' ' + sizes[ i ];
	}

	function escHtml( str ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	/* -- Delete State ------------------------------------------------ */

	document.querySelectorAll( '.ewpm-dev-delete-state' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var jobId = btn.getAttribute( 'data-job-id' );
			btn.disabled = true;

			var formData = new FormData();
			formData.append( 'action', 'ewpm_dev_delete_state' );
			formData.append( 'nonce', window.ewpmData.nonce );
			formData.append( 'job_id', jobId );

			fetch( window.ewpmData.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					if ( json.success ) {
						btn.closest( 'tr' ).remove();
					} else {
						btn.disabled = false;
						alert( json.data && json.data.error || 'Delete failed.' ); // eslint-disable-line no-alert
					}
				} )
				.catch( function () {
					btn.disabled = false;
				} );
		} );
	} );

	/* -- Cleanup ----------------------------------------------------- */

	cleanupBtn.addEventListener( 'click', function () {
		cleanupBtn.disabled = true;

		var formData = new FormData();
		formData.append( 'action', 'ewpm_dev_cleanup' );
		formData.append( 'nonce', window.ewpmData.nonce );

		fetch( window.ewpmData.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				cleanupBtn.disabled = false;
				if ( json.success ) {
					cleanupResult.className = 'ewpm-dev-result ewpm-dev-result--success';
					cleanupResult.textContent = 'Cleaned up ' + json.data.deleted + ' stale state file(s).';
				} else {
					cleanupResult.className = 'ewpm-dev-result ewpm-dev-result--error';
					cleanupResult.textContent = json.data && json.data.error || 'Cleanup failed.';
				}
			} )
			.catch( function ( err ) {
				cleanupBtn.disabled = false;
				cleanupResult.className = 'ewpm-dev-result ewpm-dev-result--error';
				cleanupResult.textContent = 'Network error: ' + err.message;
			} );
	} );
} )();
</script>
