<?php
namespace TC_BF\Admin;

if ( ! defined('ABSPATH') ) exit;

/**
 * Admin_Event_Recurrence — template/instance recurrence tool for sc_event
 * ("Policy A": every occurrence is a real, independent sc_event post).
 *
 * Port of the legacy "SC Events - updated recurrence tool" Code Snippet,
 * hardened against the internals it silently depended on. Verified against
 * Sugar Calendar 3.13.0. Same data model as the snippet — meta keys are
 * unchanged so months of generated instances keep working:
 *   _original_event_id            instance → template post ID
 *   _tc_is_template               template flag
 *   _tc_lock_from_template_sync   per-instance lock (sync skips locked)
 *   _tc_recur_type / _tc_recur_until_date   template recurrence config
 *   _tc_instance_start_ts / _tc_instance_key  dedupe identity
 *
 * What is deliberately DIFFERENT from the snippet (each fixes a verified
 * defect; see the audit in the PR/commit that introduced this file):
 *
 * 1. Event rows are created/updated explicitly via sugar_calendar_add_event()
 *    / sugar_calendar_update_event(). The snippet only wrote the two legacy
 *    date meta keys and worked solely because the nested wp_insert_post fired
 *    Sugar Calendar's Metaboxes::save while the template's sc_mb_nonce was in
 *    $_POST — outside an admin edit-screen POST no wp_sc_events row would be
 *    created and instances would be invisible to every SC query.
 *
 * 2. Occurrence math runs on the row's naive wall-clock in a FIXED zone (UTC)
 *    instead of round-tripping pseudo-epochs through the site timezone. The
 *    snippet's chain shifted every occurrence past a CET↔CEST boundary by one
 *    hour (a 09:00 weekly ride generated across late March became 08:00).
 *    Off-boundary the fixed math is bit-identical to the snippet's.
 *
 * 3. Dedupe matches on _tc_instance_start_ts within ±1h of the computed
 *    occurrence, not on string equality of _tc_instance_key: legacy keys
 *    encode wall-clock + template-date offset, so exact-key matching against
 *    the corrected math would duplicate boundary-crossing occurrences.
 *    New instances store the true wall-clock in _tc_instance_key.
 *
 * 4. Meta copy excludes by RULE: any key with prefix 'sc_event_'/'sc_recur'/
 *    '_tc_' (plus a short exact list). The snippet excluded only 4 of the 28
 *    keys intercepted by SC's back-compat shim; a raw legacy row like
 *    sc_event_day on a template would be replayed through the shim into the
 *    instance's live table row (its delete_post_meta maps to a table write of
 *    '' — a PHP 8 TypeError for date-part keys). Prefix rules also fix the
 *    snippet's misspelled 'sc_event_time_zone' exclusion (real key:
 *    sc_event_timezone) and keep 'sc_map_address' copyable.
 *
 * 5. Generation/sync run on wp_after_insert_post — AFTER Sugar Calendar has
 *    persisted the template's just-submitted dates (save_post prio 10). The
 *    snippet ran on save_post_sc_event, which WordPress fires before generic
 *    save_post, so "change the date + tick Generate" in one Update generated
 *    from the template's PREVIOUS dates.
 *
 * 6. Sync restores each instance's row dates via sugar_calendar_update_event
 *    after wp_update_post. This is essential, not defensive: the nested save
 *    re-fires SC's Metaboxes::save with the template's $_POST, which
 *    overwrites the instance row's start/end with template dates every pass.
 *    The lock check stays FIRST in the loop so locked instances never even
 *    reach wp_update_post (the nested handlers are part of what the lock
 *    protects against).
 *
 * 7. Templates whose event row carries Sugar Calendar's own recurrence are
 *    refused: SC Pro's Advanced Recurring converts such posts to another post
 *    type and would expand every generated instance into phantom occurrences.
 *    TC recurrence and SC-native recurrence are mutually exclusive.
 *
 * 8. sc_eventmeta rows (event URL, ticketing config, …) are copied via the
 *    event-meta API in generate and sync. The snippet copied only wp_postmeta;
 *    event-table extras reached instances only through the leaked-$_POST
 *    side channel. Speaker relations (Pro, separate relations table) are NOT
 *    copied — same as the snippet; document per-instance if ever needed.
 *
 * Coexistence: while the legacy snippet's class exists, every callback here
 * defers (the snippet keeps doing the work) and an admin notice asks for the
 * snippet to be deactivated. Detection is per-callback, not at plugins_loaded,
 * because Code Snippets' load timing relative to this plugin is not guaranteed.
 *
 * The module is gated by the Settings → Recurrence tab (its own option group
 * 'tc_bf_recurrence_settings'; never reuse 'tc_bf_settings' — each TCBF tab
 * posts exactly its own group or the other tabs' options get reset through
 * their sanitizers). Disabling only hides the tooling: instances are ordinary
 * posts and are never touched by the switch.
 */
final class Admin_Event_Recurrence {

	// Linkage / flags (identical to the legacy snippet — live data model).
	const META_ORIGINAL_ID    = '_original_event_id';
	const META_TEMPLATE_FLAG  = '_tc_is_template';
	const META_LOCK_FROM_SYNC = '_tc_lock_from_template_sync';

	// Template recurrence config.
	const META_RECUR_TYPE  = '_tc_recur_type';        // daily|weekly|monthly|yearly
	const META_RECUR_UNTIL = '_tc_recur_until_date';  // YYYY-MM-DD

	// Instance dedupe identity.
	const META_INSTANCE_TS  = '_tc_instance_start_ts';
	const META_INSTANCE_KEY = '_tc_instance_key';     // 'Y-m-d H:i' wall-clock

	// UI fields (plugin-owned names; the snippet used tc_sc_* — never both render).
	const FIELD_GEN  = 'tc_bf_recur_generate';
	const FIELD_SYNC = 'tc_bf_recur_sync';
	const FIELD_DEL  = 'tc_bf_recur_delete';
	const NONCE_KEY  = 'tc_bf_recurrence_nonce';

	// Settings (group: tc_bf_recurrence_settings — own group, see class docblock).
	const OPT_ENABLED         = 'tcbf_recurrence_enabled';
	const OPT_INSTANCE_STATUS = 'tcbf_recurrence_instance_status';
	const OPT_MAX_OCCURRENCES = 'tcbf_recurrence_max_occurrences';

	const SNIPPET_CLASS = 'TC_SC_Instances_SeparateUI_Sync';
	const NOTICE_TRANSIENT_PREFIX = 'tc_bf_recur_notice_';

	/** @var array|null Actions captured at save_post time, executed on wp_after_insert_post. */
	private static $pending = null;

	/** @var bool Reentrancy guard around nested inserts/updates. */
	private static $running = false;

	public static function init() : void {
		add_action('admin_init', [__CLASS__, 'register_settings']);
		add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
		add_action('save_post_sc_event', [__CLASS__, 'on_save'], 40, 3);
		// Actions run after SC has persisted the template's submitted dates (save_post:10).
		add_action('wp_after_insert_post', [__CLASS__, 'run_pending_actions'], 20, 2);

		add_filter('manage_sc_event_posts_columns', [__CLASS__, 'admin_column']);
		add_action('manage_sc_event_posts_custom_column', [__CLASS__, 'admin_column_content'], 10, 2);

		add_action('admin_notices', [__CLASS__, 'admin_notices']);
	}

	/* -------------------- ACTIVATION STATE -------------------- */

	public static function is_enabled() : bool {
		return (int) get_option(self::OPT_ENABLED, 1) === 1;
	}

	public static function legacy_snippet_active() : bool {
		return class_exists(self::SNIPPET_CLASS);
	}

	/** Module does work only when enabled AND the legacy snippet is gone. */
	private static function is_active() : bool {
		return self::is_enabled() && ! self::legacy_snippet_active();
	}

	/* -------------------- SETTINGS -------------------- */

	public static function register_settings() : void {
		register_setting('tc_bf_recurrence_settings', self::OPT_ENABLED, [
			'type'              => 'boolean',
			'sanitize_callback' => function($v){ return (int)(!empty($v)); },
			'default'           => 1,
		]);
		register_setting('tc_bf_recurrence_settings', self::OPT_INSTANCE_STATUS, [
			'type'              => 'string',
			'sanitize_callback' => function($v){
				return in_array($v, ['publish','draft'], true) ? $v : 'publish';
			},
			'default'           => 'publish',
		]);
		register_setting('tc_bf_recurrence_settings', self::OPT_MAX_OCCURRENCES, [
			'type'              => 'integer',
			'sanitize_callback' => function($v){ return min(10000, max(1, absint($v))); },
			'default'           => 5000,
		]);
	}

	/** Rendered from Settings::render() for the 'recurrence' tab. */
	public static function render_settings_tab() : void {
		$enabled = (int) get_option(self::OPT_ENABLED, 1) === 1;
		$status  = (string) get_option(self::OPT_INSTANCE_STATUS, 'publish');
		$max     = (int) get_option(self::OPT_MAX_OCCURRENCES, 5000);

		if ( self::legacy_snippet_active() ) {
			echo '<div class="notice notice-warning"><p><strong>'
				. esc_html__('Legacy recurrence snippet is still active.', 'tc-booking-flow-next') . '</strong> '
				. esc_html__('The TCBF recurrence module is deferring to it. Deactivate the "SC Events - updated recurrence tool" snippet in Code Snippets to switch over.', 'tc-booking-flow-next')
				. '</p></div>';
		}

		// Cheap counts (found_posts, no row hydration).
		$tpl_q = new \WP_Query([
			'post_type' => 'sc_event', 'post_status' => 'any', 'posts_per_page' => 1,
			'fields' => 'ids', 'no_found_rows' => false, 'meta_key' => self::META_TEMPLATE_FLAG, 'meta_value' => '1',
		]);
		$inst_q = new \WP_Query([
			'post_type' => 'sc_event', 'post_status' => 'any', 'posts_per_page' => 1,
			'fields' => 'ids', 'no_found_rows' => false,
			'meta_query' => [[ 'key' => self::META_ORIGINAL_ID, 'compare' => 'EXISTS' ]],
		]);
		?>
		<form method="post" action="options.php">
			<?php settings_fields('tc_bf_recurrence_settings'); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr(self::OPT_ENABLED); ?>"><?php esc_html_e('Enable recurrence tools', 'tc-booking-flow-next'); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr(self::OPT_ENABLED); ?>" id="<?php echo esc_attr(self::OPT_ENABLED); ?>" value="1" <?php checked($enabled); ?> />
							<?php esc_html_e('Show the Recurrence metabox, event Type column and template tools.', 'tc-booking-flow-next'); ?>
						</label>
						<p class="description"><?php esc_html_e('Disabling only hides the tooling. Already-generated events are normal events and are not affected.', 'tc-booking-flow-next'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr(self::OPT_INSTANCE_STATUS); ?>"><?php esc_html_e('New instance status', 'tc-booking-flow-next'); ?></label>
					</th>
					<td>
						<select name="<?php echo esc_attr(self::OPT_INSTANCE_STATUS); ?>" id="<?php echo esc_attr(self::OPT_INSTANCE_STATUS); ?>">
							<option value="publish" <?php selected($status, 'publish'); ?>><?php esc_html_e('Published', 'tc-booking-flow-next'); ?></option>
							<option value="draft" <?php selected($status, 'draft'); ?>><?php esc_html_e('Draft', 'tc-booking-flow-next'); ?></option>
						</select>
						<p class="description"><?php esc_html_e('Post status for newly generated event instances.', 'tc-booking-flow-next'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr(self::OPT_MAX_OCCURRENCES); ?>"><?php esc_html_e('Safety cap per generation', 'tc-booking-flow-next'); ?></label>
					</th>
					<td>
						<input type="number" class="small-text" name="<?php echo esc_attr(self::OPT_MAX_OCCURRENCES); ?>" id="<?php echo esc_attr(self::OPT_MAX_OCCURRENCES); ?>" value="<?php echo esc_attr((string) $max); ?>" min="1" max="10000" step="1" />
						<p class="description"><?php esc_html_e('Maximum occurrences one "Generate now" run may create (runaway-loop backstop).', 'tc-booking-flow-next'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Overview', 'tc-booking-flow-next'); ?></th>
					<td>
						<p>
							<?php
							printf(
								/* translators: 1: template count, 2: instance count */
								esc_html__('%1$d templates, %2$d generated instances.', 'tc-booking-flow-next'),
								(int) $tpl_q->found_posts,
								(int) $inst_q->found_posts
							);
							?>
						</p>
						<p class="description"><?php esc_html_e('Per-event controls (Repeat, Until, Generate / Update all instances / Delete instances, per-instance Lock) live in the Recurrence box on each event\'s edit screen.', 'tc-booking-flow-next'); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* -------------------- ADMIN UI -------------------- */

	public static function add_metabox() : void {
		if ( ! self::is_active() ) return;

		add_meta_box(
			'tc-bf-event-recurrence',
			__('Recurrence (Template) + Instances', 'tc-booking-flow-next'),
			[__CLASS__, 'render_metabox'],
			'sc_event',
			'side',
			'high'
		);
	}

	public static function render_metabox( \WP_Post $post ) : void {

		wp_nonce_field('tc_bf_recurrence_' . $post->ID, self::NONCE_KEY);

		$orig_id = (int) get_post_meta($post->ID, self::META_ORIGINAL_ID, true);

		// Instance view.
		if ( $orig_id ) {
			$locked = (int) get_post_meta($post->ID, self::META_LOCK_FROM_SYNC, true);
			$tpl    = get_post($orig_id);

			echo '<p><strong>' . esc_html__('Instance event', 'tc-booking-flow-next') . '</strong></p>';
			echo '<p style="font-size:12px;opacity:.9;">'
				. esc_html__('Template:', 'tc-booking-flow-next') . ' ';
			if ( $tpl ) {
				echo '<a href="' . esc_url(get_edit_post_link($orig_id)) . '">#' . (int) $orig_id . '</a>';
			} else {
				echo '<code>#' . (int) $orig_id . '</code> ' . esc_html__('(deleted)', 'tc-booking-flow-next');
			}
			echo '</p><hr style="margin:10px 0;">';

			echo '<label style="display:block;margin:0 0 6px;">';
			echo '<input type="checkbox" name="' . esc_attr(self::META_LOCK_FROM_SYNC) . '" value="1" ' . checked($locked, 1, false) . '> ';
			echo '<strong>' . esc_html__('Lock this instance', 'tc-booking-flow-next') . '</strong>';
			echo '</label>';
			echo '<p style="margin:0;font-size:12px;opacity:.85;">' . esc_html__('If enabled, template sync will NOT overwrite this instance (title/content/meta/terms). Unlocked edits here are overwritten on the next "Update all instances".', 'tc-booking-flow-next') . '</p>';
			return;
		}

		// Template view.
		$type  = (string) get_post_meta($post->ID, self::META_RECUR_TYPE, true);
		$until = (string) get_post_meta($post->ID, self::META_RECUR_UNTIL, true);

		$types = [
			''        => __('— no recurrence —', 'tc-booking-flow-next'),
			'daily'   => __('Daily', 'tc-booking-flow-next'),
			'weekly'  => __('Weekly', 'tc-booking-flow-next'),
			'monthly' => __('Monthly', 'tc-booking-flow-next'),
			'yearly'  => __('Yearly', 'tc-booking-flow-next'),
		];

		echo '<p style="margin:0 0 8px;"><strong>' . esc_html__('Template recurrence', 'tc-booking-flow-next') . '</strong></p>';
		echo '<p style="margin:0 0 10px;font-size:12px;opacity:.9;">' . esc_html__('Used ONLY by the TC generator — leave Sugar Calendar\'s own Repeat on "Never".', 'tc-booking-flow-next') . '</p>';

		echo '<p style="margin:0 0 8px;">';
		echo '<label style="display:block;font-size:12px;margin-bottom:4px;">' . esc_html__('Repeat', 'tc-booking-flow-next') . '</label>';
		echo '<select name="' . esc_attr(self::META_RECUR_TYPE) . '" style="width:100%;">';
		foreach ( $types as $k => $label ) {
			echo '<option value="' . esc_attr($k) . '" ' . selected($type, $k, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select></p>';

		echo '<p style="margin:0 0 10px;">';
		echo '<label style="display:block;font-size:12px;margin-bottom:4px;">' . esc_html__('Until (date)', 'tc-booking-flow-next') . '</label>';
		echo '<input type="date" name="' . esc_attr(self::META_RECUR_UNTIL) . '" value="' . esc_attr($until) . '" style="width:100%;">';
		echo '</p>';

		echo '<hr style="margin:10px 0;">';

		echo '<label style="display:block;margin:0 0 6px;">';
		echo '<input type="checkbox" name="' . esc_attr(self::FIELD_GEN) . '" value="1"> ';
		echo '<strong>' . esc_html__('Generate now', 'tc-booking-flow-next') . '</strong></label>';
		echo '<p style="margin:0 0 10px;font-size:12px;opacity:.85;">' . esc_html__('Creates instances from the NEXT occurrence onward.', 'tc-booking-flow-next') . '</p>';

		echo '<label style="display:block;margin:0 0 6px;">';
		echo '<input type="checkbox" name="' . esc_attr(self::FIELD_SYNC) . '" value="1"> ';
		echo '<strong>' . esc_html__('Update all instances', 'tc-booking-flow-next') . '</strong></label>';
		echo '<p style="margin:0 0 10px;font-size:12px;opacity:.85;">' . esc_html__('Copies content/meta/terms from the template but preserves each instance\'s date/time. Locked instances are skipped.', 'tc-booking-flow-next') . '</p>';

		echo '<label style="display:block;margin:0 0 6px;">';
		echo '<input type="checkbox" name="' . esc_attr(self::FIELD_DEL) . '" value="1"> ';
		echo '<strong>' . esc_html__('Delete generated instances', 'tc-booking-flow-next') . '</strong></label>';
		echo '<p style="margin:0;font-size:12px;opacity:.85;">' . esc_html__('Permanently deletes all instances generated from this template.', 'tc-booking-flow-next') . '</p>';
	}

	/* -------------------- SAVE / ACTION CAPTURE -------------------- */

	public static function on_save( $post_id, $post, $update ) : void {
		if ( ! self::is_active() ) return;
		if ( self::$running ) return;
		if ( wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) ) return;
		if ( ! current_user_can('edit_post', $post_id) ) return;

		// Post-bound nonce: fails for nested instance inserts (their ID differs),
		// which is the primary recursion guard — same design as the snippet.
		if ( empty($_POST[self::NONCE_KEY]) ) return;
		if ( ! wp_verify_nonce($_POST[self::NONCE_KEY], 'tc_bf_recurrence_' . $post_id) ) return;

		$orig_id = (int) get_post_meta($post_id, self::META_ORIGINAL_ID, true);

		// INSTANCE: only the lock checkbox is editable.
		if ( $orig_id ) {
			update_post_meta($post_id, self::META_LOCK_FROM_SYNC, ! empty($_POST[self::META_LOCK_FROM_SYNC]) ? 1 : 0);
			return;
		}

		// TEMPLATE: persist config.
		$type  = isset($_POST[self::META_RECUR_TYPE]) ? sanitize_key((string) $_POST[self::META_RECUR_TYPE]) : '';
		$until = isset($_POST[self::META_RECUR_UNTIL]) ? sanitize_text_field((string) $_POST[self::META_RECUR_UNTIL]) : '';

		if ( ! in_array($type, ['', 'daily', 'weekly', 'monthly', 'yearly'], true) ) $type = '';
		if ( $until && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) ) $until = '';

		update_post_meta($post_id, self::META_RECUR_TYPE, $type);
		update_post_meta($post_id, self::META_RECUR_UNTIL, $until);
		update_post_meta($post_id, self::META_TEMPLATE_FLAG, 1);

		// Defer the actions to wp_after_insert_post so they read the template's
		// event row AFTER Sugar Calendar persisted this request's date edits.
		self::$pending = [
			'post_id'  => (int) $post_id,
			'delete'   => ! empty($_POST[self::FIELD_DEL]),
			'generate' => ! empty($_POST[self::FIELD_GEN]),
			'sync'     => ! empty($_POST[self::FIELD_SYNC]),
		];
	}

	public static function run_pending_actions( $post_id, $post = null ) : void {
		if ( self::$running || self::$pending === null ) return;
		if ( (int) $post_id !== self::$pending['post_id'] ) return;

		$actions       = self::$pending;
		self::$pending = null;
		self::$running = true;

		try {
			$done = [];
			if ( $actions['delete'] ) {
				$done['deleted'] = self::delete_instances($actions['post_id']);
			} else {
				if ( $actions['generate'] ) {
					$done['generated'] = self::generate_instances($actions['post_id']);
				}
				if ( $actions['sync'] ) {
					$done['synced'] = self::sync_instances($actions['post_id']);
				}
			}
			if ( $done ) {
				self::stash_notice($done);
			}
		} finally {
			self::$running = false;
		}
	}

	/* -------------------- GENERATE -------------------- */

	/**
	 * @return int|null Number of instances created, or null when nothing to do.
	 */
	private static function generate_instances( int $template_id ) : ?int {

		$type      = (string) get_post_meta($template_id, self::META_RECUR_TYPE, true);
		$until_str = (string) get_post_meta($template_id, self::META_RECUR_UNTIL, true);
		if ( ! $type || ! $until_str ) return null;

		$template = get_post($template_id);
		if ( ! $template ) return null;

		$tpl_event = self::get_event_row($template_id);
		if ( ! $tpl_event ) {
			self::stash_notice(['error' => __('Template has no Sugar Calendar event row — save it with valid dates first.', 'tc-booking-flow-next')]);
			return null;
		}

		// SC-native recurrence on the template is a hard conflict (Pro Advanced
		// Recurring would expand every generated instance into phantom occurrences).
		if ( ! empty($tpl_event->recurrence) ) {
			self::stash_notice(['error' => __('This template uses Sugar Calendar\'s own recurrence. Set SC Repeat to "Never" before using the TC generator — the two are mutually exclusive.', 'tc-booking-flow-next')]);
			return null;
		}

		// Fixed-zone wall-clock math: the row's start/end are naive local
		// datetimes; interpreting them as UTC keeps '+1 week' etc. from
		// shifting the wall-clock across DST boundaries.
		$utc       = new \DateTimeZone('UTC');
		$tpl_start = date_create_immutable((string) $tpl_event->start, $utc);
		$tpl_end   = date_create_immutable((string) $tpl_event->end, $utc);
		if ( ! $tpl_start ) return null;

		$duration = ( $tpl_end && $tpl_end >= $tpl_start ) ? ($tpl_end->getTimestamp() - $tpl_start->getTimestamp()) : 0;

		$until_dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $until_str . ' 23:59:59', $utc);
		if ( ! $until_dt ) return null;

		// Existing instances, loaded once: dedupe by start-timestamp proximity
		// (±1h tolerates instances the legacy math drifted across DST).
		$existing_ts = self::existing_instance_timestamps($template_id);

		$max_occ = (int) get_option(self::OPT_MAX_OCCURRENCES, 5000);
		$status  = (string) get_option(self::OPT_INSTANCE_STATUS, 'publish');
		$taxes   = get_object_taxonomies('sc_event', 'names');

		$current = self::next_date($tpl_start, $type);
		$made    = 0;
		$safety  = 0;

		while ( $current <= $until_dt && $safety < $max_occ ) {
			$safety++;

			$occ_ts = $current->getTimestamp();

			$dupe = false;
			foreach ( $existing_ts as $ts ) {
				if ( abs($ts - $occ_ts) <= HOUR_IN_SECONDS ) { $dupe = true; break; }
			}

			if ( ! $dupe ) {
				$new_id = self::create_instance($template, $tpl_event, $current, $duration, $status, $taxes);
				if ( $new_id ) {
					$existing_ts[] = $occ_ts;
					$made++;
				}
			}

			$current = self::next_date($current, $type);
		}

		return $made;
	}

	private static function next_date( \DateTimeImmutable $d, string $type ) : \DateTimeImmutable {
		switch ( $type ) {
			case 'daily':   return $d->modify('+1 day');
			case 'weekly':  return $d->modify('+1 week');
			case 'monthly': return $d->modify('+1 month');
			case 'yearly':  return $d->modify('+1 year');
		}
		return $d;
	}

	/**
	 * Create one instance post + its event row + copied meta/terms/eventmeta.
	 *
	 * @return int 0 on failure.
	 */
	private static function create_instance( \WP_Post $template, $tpl_event, \DateTimeImmutable $occ_start, int $duration, string $status, array $taxes ) : int {

		$base_slug = ! empty($template->post_name) ? $template->post_name : sanitize_title($template->post_title);

		$new_id = wp_insert_post([
			'post_type'    => 'sc_event',
			'post_status'  => $status,
			'post_title'   => $template->post_title,
			'post_content' => $template->post_content,
			'post_excerpt' => $template->post_excerpt,
			'post_name'    => $base_slug . '-' . $occ_start->format('d-m-Y'),
		], true);

		if ( is_wp_error($new_id) || ! $new_id ) return 0;
		$new_id = (int) $new_id;

		update_post_meta($new_id, self::META_ORIGINAL_ID, (int) $template->ID);
		update_post_meta($new_id, self::META_INSTANCE_TS, $occ_start->getTimestamp());
		update_post_meta($new_id, self::META_INSTANCE_KEY, $occ_start->format('Y-m-d H:i'));

		// Event row: explicit, never dependent on leaked $_POST admin context.
		$occ_end = $occ_start->modify('+' . $duration . ' seconds');
		self::ensure_event_row($new_id, $template->post_title, $status, $occ_start, $occ_end, $tpl_event);

		self::copy_post_meta((int) $template->ID, $new_id);
		self::copy_terms((int) $template->ID, $new_id, $taxes);
		self::copy_event_meta($tpl_event, $new_id);

		return $new_id;
	}

	/**
	 * Create or correct the instance's wp_sc_events row with occurrence dates
	 * and the template row's non-date fields. Recurrence columns are always
	 * blanked — instances must never recur themselves.
	 */
	private static function ensure_event_row( int $post_id, string $title, string $status, \DateTimeImmutable $start, \DateTimeImmutable $end, $tpl_event ) : void {

		$data = [
			'start'               => $start->format('Y-m-d H:i:s'),
			'end'                 => $end->format('Y-m-d H:i:s'),
			'all_day'             => ! empty($tpl_event->all_day) ? 1 : 0,
			'start_tz'            => (string) ($tpl_event->start_tz ?? ''),
			'end_tz'              => (string) ($tpl_event->end_tz ?? ''),
			'status'              => $status,
			'recurrence'          => '',
			'recurrence_interval' => 0,
			'recurrence_count'    => 0,
			'recurrence_end'      => '',
			'recurrence_end_tz'   => '',
		];

		// Pro-only row fields worth inheriting when present.
		if ( isset($tpl_event->venue_id) && ! empty($tpl_event->venue_id) ) {
			$data['venue_id'] = (int) $tpl_event->venue_id;
		}

		$row = self::get_event_row($post_id);

		if ( $row ) {
			sugar_calendar_update_event((int) $row->id, $data);
			return;
		}

		sugar_calendar_add_event(array_merge($data, [
			'object_id'      => $post_id,
			'object_type'    => 'post',
			'object_subtype' => 'sc_event',
			'title'          => $title,
		]));
	}

	/* -------------------- SYNC -------------------- */

	/**
	 * @return int|null Number of instances updated.
	 */
	private static function sync_instances( int $template_id ) : ?int {

		$template = get_post($template_id);
		if ( ! $template ) return null;

		$tpl_event = self::get_event_row($template_id);
		$taxes     = get_object_taxonomies('sc_event', 'names');
		$ids       = self::instance_ids($template_id);
		if ( ! $ids ) return 0;

		$synced = 0;

		foreach ( $ids as $id ) {
			$id = (int) $id;

			// Lock check FIRST: locked instances must not even reach
			// wp_update_post — the nested SC/TCBF save handlers replaying the
			// template's $_POST are part of what the lock protects against.
			if ( (int) get_post_meta($id, self::META_LOCK_FROM_SYNC, true) === 1 ) continue;

			// Snapshot the instance's own dates from its event row.
			$row = self::get_event_row($id);
			if ( ! $row ) continue;

			$inst_start  = (string) $row->start;
			$inst_end    = (string) $row->end;
			$inst_allday = ! empty($row->all_day) ? 1 : 0;

			wp_update_post([
				'ID'           => $id,
				'post_title'   => $template->post_title,
				'post_content' => $template->post_content,
				'post_excerpt' => $template->post_excerpt,
			]);

			self::copy_post_meta($template_id, $id);
			self::copy_terms($template_id, $id, $taxes);
			if ( $tpl_event ) {
				self::copy_event_meta($tpl_event, $id);
			}

			// ESSENTIAL restore (not defensive): the nested save above re-fired
			// SC's Metaboxes::save with the template's $_POST, which overwrites
			// this instance row's start/end with template dates.
			$row2 = self::get_event_row($id);
			if ( $row2 ) {
				sugar_calendar_update_event((int) $row2->id, [
					'start'               => $inst_start,
					'end'                 => $inst_end,
					'all_day'             => $inst_allday,
					'recurrence'          => '',
					'recurrence_interval' => 0,
					'recurrence_count'    => 0,
					'recurrence_end'      => '',
					'recurrence_end_tz'   => '',
				]);
			}

			// Keep dedupe identity aligned with the (restored) start.
			$start_dt = date_create_immutable($inst_start, new \DateTimeZone('UTC'));
			if ( $start_dt ) {
				update_post_meta($id, self::META_INSTANCE_TS, $start_dt->getTimestamp());
				update_post_meta($id, self::META_INSTANCE_KEY, $start_dt->format('Y-m-d H:i'));
			}

			$synced++;
		}

		return $synced;
	}

	/* -------------------- DELETE -------------------- */

	private static function delete_instances( int $template_id ) : int {
		$deleted = 0;
		foreach ( self::instance_ids($template_id) as $id ) {
			// SC's deleted_post hook removes the wp_sc_events row.
			if ( wp_delete_post((int) $id, true) ) $deleted++;
		}
		return $deleted;
	}

	/* -------------------- COPY HELPERS -------------------- */

	/**
	 * Keys never copied template → instance.
	 *
	 * Prefix rules cover every key Sugar Calendar's back-compat shim
	 * intercepts ('sc_event_*', 'sc_recur*') — replaying those through
	 * update/delete_post_meta would rewrite the instance's live table row
	 * (or fatal on PHP 8 for the delete path) — plus this module's own
	 * key-space ('_tc_*'). 'sc_map_address' (Google Maps) intentionally
	 * remains copyable.
	 */
	private static function is_excluded_meta_key( string $key ) : bool {

		foreach ( ['sc_event_', 'sc_recur', '_tc_'] as $prefix ) {
			if ( strpos($key, $prefix) === 0 ) return true;
		}

		return in_array($key, [
			'sc_all_recurring',
			'sc_all_day',
			self::META_ORIGINAL_ID,
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_page_template',
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
			'_wp_desired_post_slug',
		], true);
	}

	/**
	 * Copy raw postmeta template → instance for the copyable key-space.
	 * get_post_meta() with no key returns only physically stored rows (the
	 * shim does not intercept the all-meta read), so shim-backed virtual
	 * values never appear here; excluded keys are skipped before any
	 * delete/update so the shim is never engaged.
	 */
	private static function copy_post_meta( int $from, int $to ) : void {

		$all = get_post_meta($from);
		if ( empty($all) || ! is_array($all) ) return;

		foreach ( $all as $key => $values ) {
			$key = (string) $key;
			if ( self::is_excluded_meta_key($key) ) continue;

			delete_post_meta($to, $key);
			foreach ( (array) $values as $v ) {
				add_post_meta($to, $key, maybe_unserialize($v));
			}
		}
	}

	private static function copy_terms( int $from, int $to, array $taxes ) : void {
		foreach ( $taxes as $tax ) {
			$term_ids = wp_get_object_terms($from, $tax, ['fields' => 'ids']);
			if ( ! is_wp_error($term_ids) ) {
				wp_set_object_terms($to, $term_ids, $tax);
			}
		}
	}

	/**
	 * Copy sc_eventmeta values (location, color, capacity, …) from the
	 * template's event row to the instance's, via Sugar Calendar's own
	 * schema-curated helper. The snippet never did this; these values only
	 * reached instances via leaked admin $_POST.
	 */
	private static function copy_event_meta( $tpl_event, int $to_post_id ) : void {

		if ( ! function_exists('sugar_calendar_copy_event_meta_data') ) return;

		$to_row = self::get_event_row($to_post_id);
		if ( ! $to_row ) return;

		sugar_calendar_copy_event_meta_data((int) $tpl_event->id, (int) $to_row->id);
	}

	/* -------------------- LOOKUPS -------------------- */

	/** @return object|null Sugar Calendar event row for a post, or null. */
	private static function get_event_row( int $post_id ) {
		if ( ! function_exists('sugar_calendar_get_event_by_object') ) return null;
		$event = sugar_calendar_get_event_by_object($post_id, 'post');
		return ( ! empty($event) && $event->exists() ) ? $event : null;
	}

	private static function instance_ids( int $template_id ) : array {
		return get_posts([
			'post_type'      => 'sc_event',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => self::META_ORIGINAL_ID,
			'meta_value'     => $template_id,
			'no_found_rows'  => true,
		]);
	}

	/**
	 * Start timestamps of all existing instances (backfilling missing
	 * _tc_instance_start_ts from the event row, mirroring the snippet's
	 * backfill so pre-existing instances always participate in dedupe).
	 */
	private static function existing_instance_timestamps( int $template_id ) : array {

		$out = [];
		$utc = new \DateTimeZone('UTC');

		foreach ( self::instance_ids($template_id) as $id ) {
			$id = (int) $id;
			$ts = (int) get_post_meta($id, self::META_INSTANCE_TS, true);

			if ( $ts <= 0 ) {
				$row = self::get_event_row($id);
				if ( $row ) {
					$dt = date_create_immutable((string) $row->start, $utc);
					if ( $dt ) {
						$ts = $dt->getTimestamp();
						update_post_meta($id, self::META_INSTANCE_TS, $ts);
						if ( get_post_meta($id, self::META_INSTANCE_KEY, true) === '' ) {
							update_post_meta($id, self::META_INSTANCE_KEY, $dt->format('Y-m-d H:i'));
						}
					}
				}
			}

			if ( $ts > 0 ) $out[] = $ts;
		}

		return $out;
	}

	/* -------------------- ADMIN LIST COLUMN -------------------- */

	public static function admin_column( $cols ) {
		if ( ! self::is_active() ) return $cols;
		$cols['tc_type'] = __('Type', 'tc-booking-flow-next');
		return $cols;
	}

	public static function admin_column_content( $col, $post_id ) : void {
		if ( $col !== 'tc_type' || ! self::is_active() ) return;

		if ( get_post_meta($post_id, self::META_ORIGINAL_ID, true) ) {
			$locked = (int) get_post_meta($post_id, self::META_LOCK_FROM_SYNC, true);
			echo $locked
				? esc_html__('Instance (Locked)', 'tc-booking-flow-next')
				: esc_html__('Instance', 'tc-booking-flow-next');
			return;
		}

		if ( get_post_meta($post_id, self::META_TEMPLATE_FLAG, true) ) {
			echo '<strong>' . esc_html__('Template', 'tc-booking-flow-next') . '</strong>';
			return;
		}

		echo esc_html__('Event', 'tc-booking-flow-next');
	}

	/* -------------------- NOTICES -------------------- */

	private static function stash_notice( array $data ) : void {
		set_transient(self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(), $data, 60);
	}

	public static function admin_notices() : void {

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		$on_event_screen = $screen && $screen->post_type === 'sc_event';

		// Coexistence warning while the legacy snippet is still active.
		if ( self::is_enabled() && self::legacy_snippet_active() && $on_event_screen ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__('TCBF: the legacy recurrence snippet is still active — the plugin\'s recurrence module is deferring to it. Deactivate the snippet in Code Snippets to complete the migration.', 'tc-booking-flow-next')
				. '</p></div>';
		}

		if ( ! self::is_active() ) return;

		// Result notice from the last generate/sync/delete.
		$key  = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$data = get_transient($key);
		if ( is_array($data) && $on_event_screen ) {
			delete_transient($key);

			if ( isset($data['error']) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__('TCBF Recurrence:', 'tc-booking-flow-next') . ' ' . esc_html((string) $data['error']) . '</p></div>';
			} else {
				$parts = [];
				if ( isset($data['generated']) ) {
					/* translators: %d: number of instances */
					$parts[] = sprintf(esc_html__('%d instances generated', 'tc-booking-flow-next'), (int) $data['generated']);
				}
				if ( isset($data['synced']) ) {
					/* translators: %d: number of instances */
					$parts[] = sprintf(esc_html__('%d instances updated', 'tc-booking-flow-next'), (int) $data['synced']);
				}
				if ( isset($data['deleted']) ) {
					/* translators: %d: number of instances */
					$parts[] = sprintf(esc_html__('%d instances deleted', 'tc-booking-flow-next'), (int) $data['deleted']);
				}
				if ( $parts ) {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('TCBF Recurrence:', 'tc-booking-flow-next') . ' ' . esc_html(implode(', ', $parts)) . '.</p></div>';
				}
			}
		}

		// Reminder on instance edit screens: sync overwrites unlocked edits.
		if ( $on_event_screen && $screen->base === 'post' && ! empty($_GET['post']) ) {
			$pid  = absint($_GET['post']);
			$orig = (int) get_post_meta($pid, self::META_ORIGINAL_ID, true);
			if ( $orig && (int) get_post_meta($pid, self::META_LOCK_FROM_SYNC, true) !== 1 ) {
				echo '<div class="notice notice-info"><p>'
					. sprintf(
						/* translators: %d: template post ID */
						esc_html__('This is a generated instance of template #%d. Content, pricing and EB edits here will be overwritten by the template\'s "Update all instances" unless you lock this instance in the Recurrence box.', 'tc-booking-flow-next'),
						$orig
					)
					. '</p></div>';
			}
		}
	}
}
