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
