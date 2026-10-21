<?php
/**
 * Admin UI: Checkout Scripts (scan + trust + setup), Alerts, Settings.
 *
 * Written for a non-technical store owner: plain language first, the PCI/CSP
 * mechanics tucked behind "why this matters" / "technical details".
 *
 * @package CyberShield_Checkout_Script_Monitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CSCSM_Admin.
 */
class CSCSM_Admin {

	/**
	 * @var CSCSM_Inventory
	 */
	private $inventory;

	/**
	 * @var CSCSM_CSP
	 */
	private $csp;

	public function __construct( CSCSM_Inventory $inventory, CSCSM_CSP $csp ) {
		$this->inventory = $inventory;
		$this->csp       = $csp;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_cscsm_scan', array( $this, 'handle_scan' ) );
		add_action( 'admin_post_cscsm_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_cscsm_rebaseline', array( $this, 'handle_rebaseline' ) );
		add_action( 'admin_post_cscsm_enable_monitoring', array( $this, 'handle_enable_monitoring' ) );
		add_action( 'admin_post_cscsm_trust', array( $this, 'handle_trust' ) );
		add_action( 'admin_post_cscsm_untrust', array( $this, 'handle_untrust' ) );
		add_action( 'admin_post_cscsm_clear_violations', array( $this, 'handle_clear_violations' ) );
		add_action( 'admin_post_cscsm_dismiss_notice', array( $this, 'handle_dismiss_notice' ) );
		add_action( 'admin_notices', array( $this, 'funnel_notice' ) );
		add_filter( 'admin_footer_text', array( $this, 'admin_footer_rating' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Load the plugin's admin stylesheet on its own screens only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'cybershield-checkout-script-monitor' ) ) {
			return;
		}
		wp_enqueue_style( 'cscsm-admin', CSCSM_URL . 'assets/admin.css', array(), CSCSM_VERSION );
	}

	public function menu() {
		$cap = 'manage_options';
		add_menu_page(
			__( 'CyberShield Checkout Script Monitor', 'cybershield-checkout-script-monitor' ),
			__( 'CyberShield Checkout Script Monitor', 'cybershield-checkout-script-monitor' ),
			$cap,
			'cybershield-checkout-script-monitor',
			array( $this, 'render_home' ),
			'dashicons-visibility',
			58
		);
		add_submenu_page( 'cybershield-checkout-script-monitor', __( 'Checkout Scripts', 'cybershield-checkout-script-monitor' ), __( 'Checkout Scripts', 'cybershield-checkout-script-monitor' ), $cap, 'cybershield-checkout-script-monitor', array( $this, 'render_home' ) );

		// Show a count bubble on Alerts when monitoring is on and there is something new.
		$alerts_label = __( 'Alerts', 'cybershield-checkout-script-monitor' );
		if ( $this->csp->is_enabled() ) {
			$count = count( (array) $this->csp->recent_violations( 100 ) );
			if ( $count > 0 ) {
				$alerts_label .= ' <span class="awaiting-mod">' . (int) $count . '</span>';
			}
		}
		add_submenu_page( 'cybershield-checkout-script-monitor', __( 'Alerts', 'cybershield-checkout-script-monitor' ), $alerts_label, $cap, 'cybershield-checkout-script-monitor-alerts', array( $this, 'render_alerts' ) );
		add_submenu_page( 'cybershield-checkout-script-monitor', __( 'Settings', 'cybershield-checkout-script-monitor' ), __( 'Settings', 'cybershield-checkout-script-monitor' ), $cap, 'cybershield-checkout-script-monitor-settings', array( $this, 'render_settings' ) );
	}

	/**
	 * On our own admin screens, swap the WordPress footer credit for a friendly
	 * thank-you note. Every other admin screen is left untouched.
	 *
	 * @param string $text Default admin footer text.
	 * @return string
	 */
	public function admin_footer_rating( $text ) {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'cybershield-checkout-script-monitor' ) ) {
			return $text;
		}

		// TODO (post-launch): once the plugin is listed on WordPress.org, replace this
		// feedback line with a delayed, dismissible "leave a review" prompt — shown
		// after the merchant has actually used monitoring for a while (e.g. cleared a
		// first alert, or ~14 days in) — linking to
		// https://wordpress.org/support/plugin/cybershield-checkout-script-monitor/reviews/ .
		// Before launch a review CTA has no valid destination and invites premature
		// ratings, so we ask for direct feedback first.
		$mailto = 'mailto:feedback@cybershieldstudio.com?subject=' . rawurlencode( 'CyberShield Checkout Script Monitor feedback' );

		return sprintf(
			/* translators: 1: plugin name (bold), 2: opening feedback-email link tag, 3: closing link tag. */
			__( 'Enjoying %1$s? Found a bug or have an idea? %2$sEmail us%3$s.', 'cybershield-checkout-script-monitor' ),
			'<strong>' . esc_html__( 'CyberShield Checkout Script Monitor', 'cybershield-checkout-script-monitor' ) . '</strong>',
			'<a href="' . esc_url( $mailto ) . '">',
			'</a>'
		);
	}

	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'cybershield-checkout-script-monitor' ) );
		}
	}

	private function redirect( $page, $notice = '' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => $page,
					'cscsm_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// -------------------------------------------------------------- Handlers.

	public function handle_scan() {
		$this->guard();
		check_admin_referer( 'cscsm_scan' );
		$this->inventory->scan();
		$this->redirect( 'cybershield-checkout-script-monitor', 'scanned' );
	}

	public function handle_save_settings() {
		$this->guard();
		check_admin_referer( 'cscsm_settings' );
		$enabled = isset( $_POST['csp_report_only'] ) ? 1 : 0;
		update_option( CSCSM_OPT_SETTINGS, array( 'csp_report_only' => $enabled ) );
		$this->redirect( 'cybershield-checkout-script-monitor-settings', 'saved' );
	}

	public function handle_rebaseline() {
		$this->guard();
		check_admin_referer( 'cscsm_rebaseline' );
		$this->csp->rebaseline();
		$this->redirect( 'cybershield-checkout-script-monitor', 'rebaselined' );
	}

	public function handle_enable_monitoring() {
		$this->guard();
		check_admin_referer( 'cscsm_enable_monitoring' );
		update_option( CSCSM_OPT_SETTINGS, array( 'csp_report_only' => 1 ) );
		$this->redirect( 'cybershield-checkout-script-monitor', 'monitoring_on' );
	}

	public function handle_trust() {
		$this->guard();
		check_admin_referer( 'cscsm_trust' );
		$uri = isset( $_POST['uri'] ) ? sanitize_text_field( wp_unslash( $_POST['uri'] ) ) : '';
		if ( '' !== $uri ) {
			$this->csp->add_baseline_host( $uri );
			$this->csp->forget_host( $uri );
		}
		$this->redirect( 'cybershield-checkout-script-monitor-alerts', 'trusted' );
	}

	public function handle_untrust() {
		$this->guard();
		check_admin_referer( 'cscsm_untrust' );
		$host = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : '';
		if ( '' !== $host ) {
			$this->csp->remove_baseline_host( $host );
		}
		$this->redirect( 'cybershield-checkout-script-monitor', 'untrusted' );
	}

	public function handle_clear_violations() {
		$this->guard();
		check_admin_referer( 'cscsm_clear_violations' );
		$this->csp->clear_violations();
		$this->redirect( 'cybershield-checkout-script-monitor-alerts', 'cleared' );
	}

	public function handle_dismiss_notice() {
		$this->guard();
		check_admin_referer( 'cscsm_dismiss_notice' );
		update_option( CSCSM_OPT_NOTICE, 1 );
		$this->redirect( 'cybershield-checkout-script-monitor', '' );
	}

	// --------------------------------------------------------------- Notices.

	private function notice_text() {
		$k = isset( $_GET['cscsm_notice'] ) ? sanitize_key( wp_unslash( $_GET['cscsm_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map = array(
			'scanned'       => __( 'Scan complete. Here is what is running on your checkout.', 'cybershield-checkout-script-monitor' ),
			'saved'         => __( 'Settings saved.', 'cybershield-checkout-script-monitor' ),
			'rebaselined'   => __( 'Your trusted list is saved.', 'cybershield-checkout-script-monitor' ),
			'cleared'       => __( 'Alerts cleared.', 'cybershield-checkout-script-monitor' ),
			'trusted'       => __( 'Added to your trusted list. We will not alert you about it again.', 'cybershield-checkout-script-monitor' ),
			'untrusted'     => __( 'Removed from your trusted list.', 'cybershield-checkout-script-monitor' ),
			'monitoring_on' => __( 'Monitoring is on. We will alert you if a new script appears on your checkout.', 'cybershield-checkout-script-monitor' ),
		);
		if ( isset( $map[ $k ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $k ] ) . '</p></div>';
		}
	}

	public function funnel_notice() {
		if ( get_option( CSCSM_OPT_NOTICE ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'cybershield-checkout-script-monitor' ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=cscsm_dismiss_notice' ), 'cscsm_dismiss_notice' );
		echo '<div class="notice notice-info"><p>';
		echo wp_kses_post(
			sprintf(
				/* translators: %s: Webpage Security Checker link. */
				__( 'Want an outside view of your checkout? Run a free scan with the %s.', 'cybershield-checkout-script-monitor' ),
				'<a href="https://cybershieldstudio.com/tools/page-checker?utm_source=wp-plugin&amp;utm_medium=wp-admin&amp;utm_campaign=cscsm" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Webpage Security Checker', 'cybershield-checkout-script-monitor' ) . '</a>'
			)
		);
		echo ' <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'cybershield-checkout-script-monitor' ) . '</a>';
		echo '</p></div>';
	}

	// ----------------------------------------------------------------- Home.

	public function render_home() {
		$this->guard();
		$snap        = $this->inventory->get_snapshot();
		$baseline    = $this->csp->get_baseline();
		$has_scan    = ! empty( $snap['scanned_at'] );
		$has_trusted = ! empty( $baseline['hosts'] );
		$monitoring  = $this->csp->is_enabled();
		$setup_done  = $has_scan && $has_trusted && $monitoring;
		$post_url    = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Checkout Scripts', 'cybershield-checkout-script-monitor' ); ?></h1>
			<?php $this->notice_text(); ?>
			<p style="max-width:750px;font-size:14px;">
				<?php esc_html_e( 'This tool watches the code (called scripts) running on your checkout page and tells you when something new appears. Usually that is a routine update, but it can also be a sign your store was changed without your knowledge.', 'cybershield-checkout-script-monitor' ); ?>
			</p>

			<?php if ( $setup_done ) : ?>
				<div class="notice notice-success inline" style="margin:16px 0;max-width:750px;"><p style="font-size:14px;">
					<strong><?php esc_html_e( 'You are all set.', 'cybershield-checkout-script-monitor' ); ?></strong>
					<?php esc_html_e( 'Monitoring is on. If a new script appears on your checkout, it will show up under Alerts for you to review.', 'cybershield-checkout-script-monitor' ); ?>
				</p></div>
			<?php else : ?>
				<?php $this->render_setup_card( $snap, $has_scan, $has_trusted, $monitoring, $post_url ); ?>
			<?php endif; ?>

			<?php if ( $has_trusted ) { $this->render_trusted_list( $baseline, $post_url ); } ?>
			<?php if ( $has_scan ) { $this->render_scripts_table( $snap, $post_url, $baseline ); } ?>
			<?php $this->render_why(); ?>
		</div>
		<?php
	}

	private function render_setup_card( $snap, $has_scan, $has_trusted, $monitoring, $post_url ) {
		$count = $has_scan ? (int) $snap['total'] : 0;
		$check = '<span class="dashicons dashicons-yes-alt" style="color:#008a20;vertical-align:text-bottom;"></span> ';
		?>
		<div class="card" style="max-width:750px;margin:16px 0;padding:2px 20px 16px;">
			<h2><?php esc_html_e( 'Get set up in 3 steps', 'cybershield-checkout-script-monitor' ); ?></h2>

			<p style="font-size:14px;margin:14px 0 2px;">
				<?php echo $has_scan ? wp_kses_post( $check ) : ''; ?><strong><?php esc_html_e( 'Step 1 — Check your checkout', 'cybershield-checkout-script-monitor' ); ?></strong>
			</p>
			<p style="margin:0 0 8px;color:#50575e;"><?php esc_html_e( 'We look at your own store pages and list every script running on them.', 'cybershield-checkout-script-monitor' ); ?></p>
			<?php if ( ! $has_scan ) : ?>
				<form method="post" action="<?php echo $post_url; // phpcs:ignore ?>" style="margin:0 0 6px;">
					<input type="hidden" name="action" value="cscsm_scan" />
					<?php wp_nonce_field( 'cscsm_scan' ); ?>
					<?php submit_button( __( 'Scan my checkout', 'cybershield-checkout-script-monitor' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<p style="font-size:14px;margin:16px 0 2px;<?php echo $has_scan ? '' : 'opacity:.5;'; ?>">
				<?php echo $has_trusted ? wp_kses_post( $check ) : ''; ?><strong><?php esc_html_e( 'Step 2 — Mark them as trusted', 'cybershield-checkout-script-monitor' ); ?></strong>
			</p>
			<p style="margin:0 0 8px;color:#50575e;<?php echo $has_scan ? '' : 'opacity:.5;'; ?>"><?php esc_html_e( 'If everything in the list below looks expected, save it as your trusted list. From then on we only flag scripts that are new.', 'cybershield-checkout-script-monitor' ); ?></p>
			<?php if ( $has_scan && ! $has_trusted ) : ?>
				<form method="post" action="<?php echo $post_url; // phpcs:ignore ?>" style="margin:0 0 6px;">
					<input type="hidden" name="action" value="cscsm_rebaseline" />
					<?php wp_nonce_field( 'cscsm_rebaseline' ); ?>
					<?php
					/* translators: %d: number of scripts found. */
					submit_button( sprintf( _n( 'Trust the %d script found', 'Trust the %d scripts found', $count, 'cybershield-checkout-script-monitor' ), $count ), 'primary', 'submit', false );
					?>
					<p class="description" style="margin-top:6px;"><?php esc_html_e( 'See something you do not recognize? Remove it from your store first, then scan again before you save the list.', 'cybershield-checkout-script-monitor' ); ?></p>
				</form>
			<?php endif; ?>

			<p style="font-size:14px;margin:16px 0 2px;<?php echo $has_trusted ? '' : 'opacity:.5;'; ?>">
				<?php echo $monitoring ? wp_kses_post( $check ) : ''; ?><strong><?php esc_html_e( 'Step 3 — Turn on monitoring', 'cybershield-checkout-script-monitor' ); ?></strong>
			</p>
			<p style="margin:0 0 8px;color:#50575e;<?php echo $has_trusted ? '' : 'opacity:.5;'; ?>"><?php esc_html_e( 'We keep watching your checkout and alert you if a new script shows up. This never changes or slows your store.', 'cybershield-checkout-script-monitor' ); ?></p>
			<?php if ( $has_trusted && ! $monitoring ) : ?>
				<form method="post" action="<?php echo $post_url; // phpcs:ignore ?>" style="margin:0;">
					<input type="hidden" name="action" value="cscsm_enable_monitoring" />
					<?php wp_nonce_field( 'cscsm_enable_monitoring' ); ?>
					<?php submit_button( __( 'Turn on monitoring', 'cybershield-checkout-script-monitor' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_scripts_table( $snap, $post_url, $baseline ) {
		$trusted_hosts = ( ! empty( $baseline['hosts'] ) && is_array( $baseline['hosts'] ) ) ? $baseline['hosts'] : array();
		$has_baseline  = ! empty( $trusted_hosts );

		// Sort by "Loaded by" so scripts from the same source (WooCommerce, Stripe,
		// WordPress core) group together instead of scattering. Read-only display sort.
		$order   = ( isset( $_GET['cscsm_order'] ) && 'desc' === sanitize_key( wp_unslash( $_GET['cscsm_order'] ) ) ) ? 'desc' : 'asc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scripts = ( isset( $snap['scripts'] ) && is_array( $snap['scripts'] ) ) ? $snap['scripts'] : array();
		usort(
			$scripts,
			function ( $a, $b ) use ( $order ) {
				$cmp = strcasecmp( $this->loaded_by( $a ), $this->loaded_by( $b ) );
				if ( 0 === $cmp ) {
					$cmp = strcasecmp( (string) $a['src'], (string) $b['src'] );
				}
				return ( 'desc' === $order ) ? -$cmp : $cmp;
			}
		);
		$sort_url = esc_url(
			add_query_arg(
				array(
					'page'      => 'cybershield-checkout-script-monitor',
					'cscsm_order' => ( 'asc' === $order ) ? 'desc' : 'asc',
				),
				admin_url( 'admin.php' )
			)
		);
		$arrow = ( 'asc' === $order ) ? '&#9650;' : '&#9660;';
		?>
		<h2><?php esc_html_e( 'What is running on your checkout now', 'cybershield-checkout-script-monitor' ); ?></h2>
		<p>
			<?php
			/* translators: %s: date/time of the last scan. */
			printf( esc_html__( 'Last checked: %s.', 'cybershield-checkout-script-monitor' ), esc_html( $snap['scanned_at'] ) );
			?>
			<form method="post" action="<?php echo $post_url; // phpcs:ignore ?>" style="display:inline;margin-left:8px;">
				<input type="hidden" name="action" value="cscsm_scan" />
				<?php wp_nonce_field( 'cscsm_scan' ); ?>
				<?php submit_button( __( 'Scan again', 'cybershield-checkout-script-monitor' ), 'secondary small', 'submit', false ); ?>
			</form>
		</p>

		<?php if ( ! empty( $snap['errors'] ) ) : ?>
			<div class="notice notice-warning inline" style="max-width:750px;"><p>
				<?php esc_html_e( 'Some pages could not be checked:', 'cybershield-checkout-script-monitor' ); ?>
				<?php
				$parts = array();
				foreach ( $snap['errors'] as $label => $msg ) {
					$parts[] = $label . ': ' . $msg;
				}
				echo esc_html( implode( '; ', $parts ) );
				?>
			</p></div>
		<?php endif; ?>

		<?php if ( empty( $scripts ) ) : ?>
			<p><em><?php esc_html_e( 'No outside scripts found on your checkout. That is a simple, low-risk setup.', 'cybershield-checkout-script-monitor' ); ?></em></p>
		<?php else : ?>
			<?php
			$total  = (int) ( isset( $snap['total'] ) ? $snap['total'] : count( $scripts ) );
			$ext    = (int) ( isset( $snap['third_party'] ) ? $snap['third_party'] : 0 );
			$no_sri = (int) ( isset( $snap['missing_sri'] ) ? $snap['missing_sri'] : 0 );
			?>
			<div class="cscsm-stats">
				<div class="cscsm-stat">
					<span class="cscsm-stat__num"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
					<span class="cscsm-stat__label"><?php esc_html_e( 'scripts on your checkout', 'cybershield-checkout-script-monitor' ); ?></span>
				</div>
				<div class="cscsm-stat">
					<span class="cscsm-stat__num"><?php echo esc_html( number_format_i18n( $ext ) ); ?></span>
					<span class="cscsm-stat__label"><?php esc_html_e( 'from outside companies', 'cybershield-checkout-script-monitor' ); ?></span>
				</div>
				<div class="cscsm-stat<?php echo $no_sri > 0 ? ' cscsm-stat--warn' : ''; ?>">
					<span class="cscsm-stat__num"><?php echo esc_html( number_format_i18n( $no_sri ) ); ?></span>
					<span class="cscsm-stat__label"><?php esc_html_e( 'without a tamper check', 'cybershield-checkout-script-monitor' ); ?></span>
				</div>
			</div>
			<table class="widefat cscsm-scripts<?php echo $has_baseline ? '' : ' striped'; ?>" style="max-width:960px;">
				<thead><tr>
					<th><?php esc_html_e( 'Script', 'cybershield-checkout-script-monitor' ); ?></th>
					<th><?php esc_html_e( 'Source', 'cybershield-checkout-script-monitor' ); ?></th>
					<th>
						<a href="<?php echo $sort_url; // phpcs:ignore ?>" style="text-decoration:none;" title="<?php esc_attr_e( 'Sort by who loaded each script', 'cybershield-checkout-script-monitor' ); ?>"><?php esc_html_e( 'Loaded by', 'cybershield-checkout-script-monitor' ); ?> <span aria-hidden="true"><?php echo $arrow; // phpcs:ignore ?></span></a>
					</th>
					<th><?php esc_html_e( 'Tamper check', 'cybershield-checkout-script-monitor' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $scripts as $s ) :
					$host      = isset( $s['host'] ) ? (string) $s['host'] : '';
					$trusted   = $has_baseline && '' !== $host && in_array( $host, $trusted_hosts, true );
					$row_class = $has_baseline ? ( $trusted ? 'cscsm-row--trusted' : 'cscsm-row--untrusted' ) : '';
					?>
					<tr class="<?php echo esc_attr( $row_class ); ?>">
						<td><code class="cscsm-script"><?php echo esc_html( $s['src'] ); ?></code></td>
						<td>
							<code class="cscsm-host"><?php echo '' !== $host ? esc_html( $host ) : '&mdash;'; ?></code>
							<?php if ( $has_baseline && $trusted ) : ?>
								<span class="cscsm-badge cscsm-badge--trusted cscsm-source-badge"><?php esc_html_e( 'trusted', 'cybershield-checkout-script-monitor' ); ?></span>
							<?php elseif ( $has_baseline ) : ?>
								<span class="cscsm-badge cscsm-badge--untrusted cscsm-source-badge"><?php esc_html_e( 'not in your list', 'cybershield-checkout-script-monitor' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( $this->loaded_by( $s ) ); ?>
							<?php if ( ! empty( $s['third_party'] ) ) : ?>
								<span class="cscsm-badge cscsm-badge--external"><?php esc_html_e( 'external', 'cybershield-checkout-script-monitor' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $s['has_sri'] ) ) : ?>
								<span class="cscsm-badge cscsm-badge--sri-on"><span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'Locked', 'cybershield-checkout-script-monitor' ); ?></span>
							<?php else : ?>
								<span class="cscsm-badge cscsm-badge--sri-off" title="<?php esc_attr_e( 'A tamper check (called SRI) lets the browser reject this script if it has been altered. It is optional and only some providers offer it.', 'cybershield-checkout-script-monitor' ); ?>"><?php esc_html_e( 'Not set', 'cybershield-checkout-script-monitor' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description" style="max-width:750px;">
				<?php esc_html_e( '"Source" is the domain each script loads from, the same value you trust in the list above. "Loaded by" names who added it (a WordPress component or plugin for your own scripts, or the outside company for external ones); click it to group by source. "Tamper check" is an optional extra lock some providers offer; a missing one is common and not itself a problem.', 'cybershield-checkout-script-monitor' ); ?>
				<?php if ( $has_baseline ) { esc_html_e( 'Rows marked "trusted" come from a domain in your trusted list; rows marked "not in your list" are from a source not yet approved.', 'cybershield-checkout-script-monitor' ); } ?>
			</p>
		<?php endif; ?>
		<?php
	}

	private function loaded_by( $s ) {
		if ( ! empty( $s['source'] ) ) {
			return $s['source'];
		}
		if ( empty( $s['third_party'] ) ) {
			return __( 'Your store', 'cybershield-checkout-script-monitor' );
		}
		return __( 'Another company', 'cybershield-checkout-script-monitor' );
	}

	private function render_trusted_list( $baseline, $post_url ) {
		?>
		<h2><?php esc_html_e( 'Your trusted script sources', 'cybershield-checkout-script-monitor' ); ?></h2>
		<p style="max-width:750px;">
			<?php
			/* translators: %s: date the trusted list was saved. */
			printf( esc_html__( 'Saved on %s. These are the domains (website addresses) your checkout is allowed to load scripts from. We only alert you about scripts from anywhere else.', 'cybershield-checkout-script-monitor' ), esc_html( $baseline['created_at'] ) );
			?>
		</p>
		<table class="widefat striped" style="max-width:600px;">
			<thead><tr>
				<th><?php esc_html_e( 'Trusted domain', 'cybershield-checkout-script-monitor' ); ?></th>
				<th style="width:90px;"></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $baseline['hosts'] as $h ) : ?>
				<tr>
					<td><code><?php echo esc_html( $h ); ?></code></td>
					<td>
						<form method="post" action="<?php echo $post_url; // phpcs:ignore ?>" style="margin:0;">
							<input type="hidden" name="action" value="cscsm_untrust" />
							<input type="hidden" name="host" value="<?php echo esc_attr( $h ); ?>" />
							<?php wp_nonce_field( 'cscsm_untrust' ); ?>
							<?php submit_button( __( 'Remove', 'cybershield-checkout-script-monitor' ), 'secondary small', 'submit', false ); ?>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo $post_url; // phpcs:ignore ?>" style="margin:12px 0 6px;">
			<input type="hidden" name="action" value="cscsm_rebaseline" />
			<?php wp_nonce_field( 'cscsm_rebaseline' ); ?>
			<?php submit_button( __( 'Add anything new from my latest scan', 'cybershield-checkout-script-monitor' ), 'secondary', 'submit', false ); ?>
		</form>
		<p class="description" style="max-width:750px;"><?php esc_html_e( 'This adds any new domains found in your most recent scan and keeps everything you already trust. It never removes a domain on its own, so a script that is temporarily unavailable will not be dropped. To remove a domain, use Remove next to it.', 'cybershield-checkout-script-monitor' ); ?></p>
		<?php
	}

	private function render_why() {
		?>
		<details style="margin-top:22px;max-width:750px;">
			<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Why this matters (and the PCI DSS 6.4.3 connection)', 'cybershield-checkout-script-monitor' ); ?></summary>
			<div style="padding:8px 0;color:#3c434a;">
				<p><?php esc_html_e( 'Attackers who slip a hidden script onto a checkout page can quietly copy your customers\' card details. So knowing exactly what runs on your checkout, and noticing when it changes, is a basic security habit for any online store.', 'cybershield-checkout-script-monitor' ); ?></p>
				<p><?php esc_html_e( 'It also lines up with a PCI DSS rule (Requirement 6.4.3) that asks merchants to keep a list of the scripts on their payment pages and be able to tell when one changes. This tool helps you do that. It is a readiness and visibility aid; it does not make you PCI compliant, and that decision stays yours.', 'cybershield-checkout-script-monitor' ); ?></p>
				<p><?php esc_html_e( 'The scan lists scripts across your storefront (home, shop, cart, and checkout) so you get the full picture. Ongoing monitoring then watches your cart and checkout, the pages where customers enter card details, and alerts you to anything new there.', 'cybershield-checkout-script-monitor' ); ?></p>
				<p><em><?php esc_html_e( 'One limit worth knowing: the scan sees scripts written into the page. Scripts added later by another script (like a tag manager) may not show here, which is one more reason to turn on monitoring.', 'cybershield-checkout-script-monitor' ); ?></em></p>
			</div>
		</details>
		<?php
	}

	// --------------------------------------------------------------- Alerts.

	public function render_alerts() {
		$this->guard();
		$enabled  = $this->csp->is_enabled();
		$baseline = $this->csp->get_baseline();
		$rows     = $this->csp->recent_violations( 100 );
		$post_url = esc_url( admin_url( 'admin-post.php' ) );
		$home_url = esc_url( admin_url( 'admin.php?page=cybershield-checkout-script-monitor' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Alerts', 'cybershield-checkout-script-monitor' ); ?></h1>
			<?php $this->notice_text(); ?>
			<p style="max-width:750px;font-size:14px;"><?php esc_html_e( 'These are scripts that have appeared on your checkout but are not on your trusted list. Often it is just an update from a tool you already use, but it can also mean something was added without your knowledge. For each one: trust it if you recognize it, or consider removing it from your store if you do not.', 'cybershield-checkout-script-monitor' ); ?></p>

			<?php if ( ! $enabled ) : ?>
				<div class="notice notice-warning inline" style="max-width:750px;"><p><?php
					printf(
						/* translators: %s: Checkout Scripts link. */
						esc_html__( 'Monitoring is off, so nothing is being tracked yet. Turn it on from %s.', 'cybershield-checkout-script-monitor' ),
						'<a href="' . $home_url . '">' . esc_html__( 'Checkout Scripts', 'cybershield-checkout-script-monitor' ) . '</a>' // phpcs:ignore
					);
				?></p></div>
			<?php elseif ( empty( $baseline['hosts'] ) ) : ?>
				<div class="notice notice-warning inline" style="max-width:750px;"><p><?php
					printf(
						/* translators: %s: Checkout Scripts link. */
						esc_html__( 'You have not set a trusted list yet, so we cannot tell what is new. Finish setup on %s.', 'cybershield-checkout-script-monitor' ),
						'<a href="' . $home_url . '">' . esc_html__( 'Checkout Scripts', 'cybershield-checkout-script-monitor' ) . '</a>' // phpcs:ignore
					);
				?></p></div>
			<?php endif; ?>

			<?php if ( $rows ) : ?>
				<table class="widefat cscsm-scripts" style="max-width:1000px;margin-top:12px;">
					<thead><tr>
						<th><?php esc_html_e( 'New script', 'cybershield-checkout-script-monitor' ); ?></th>
						<th><?php esc_html_e( 'Times seen', 'cybershield-checkout-script-monitor' ); ?></th>
						<th><?php esc_html_e( 'Last seen', 'cybershield-checkout-script-monitor' ); ?></th>
						<th><?php esc_html_e( 'What to do', 'cybershield-checkout-script-monitor' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr class="cscsm-row--untrusted">
							<td><code class="cscsm-script"><?php echo esc_html( $r->blocked_uri ); ?></code></td>
							<td><?php echo (int) $r->hits; ?></td>
							<td><?php echo esc_html( $r->last_seen ); ?></td>
							<td>
								<form method="post" action="<?php echo $post_url; // phpcs:ignore ?>" style="display:inline;">
									<input type="hidden" name="action" value="cscsm_trust" />
									<input type="hidden" name="uri" value="<?php echo esc_attr( $r->blocked_uri ); ?>" />
									<?php wp_nonce_field( 'cscsm_trust' ); ?>
									<?php submit_button( __( 'Trust it', 'cybershield-checkout-script-monitor' ), 'secondary small', 'submit', false ); ?>
								</form>
								<details style="display:inline-block;margin-left:8px;vertical-align:middle;">
									<summary style="cursor:pointer;color:#2271b1;font-weight:400;"><?php esc_html_e( 'I do not recognize this', 'cybershield-checkout-script-monitor' ); ?></summary>
									<div style="padding:6px 0;max-width:440px;color:#3c434a;font-weight:400;"><?php esc_html_e( 'If you did not add this and do not recognize the company, treat it as a red flag. Ask whoever manages your website or plugins to find and remove the script, then scan again. Use the free outside check linked at the top of the page if you want a second opinion.', 'cybershield-checkout-script-monitor' ); ?></div>
								</details>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<form method="post" action="<?php echo $post_url; // phpcs:ignore ?>" style="margin:14px 0;">
					<input type="hidden" name="action" value="cscsm_clear_violations" />
					<?php wp_nonce_field( 'cscsm_clear_violations' ); ?>
					<?php submit_button( __( 'Clear all alerts', 'cybershield-checkout-script-monitor' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php elseif ( $enabled && ! empty( $baseline['hosts'] ) ) : ?>
				<div class="notice notice-success inline" style="margin-top:12px;max-width:750px;"><p><?php esc_html_e( 'All clear. Nothing new has appeared on your checkout since you set your trusted list.', 'cybershield-checkout-script-monitor' ); ?></p></div>
			<?php else : ?>
				<p><em><?php esc_html_e( 'No alerts yet.', 'cybershield-checkout-script-monitor' ); ?></em></p>
			<?php endif; ?>
		</div>
		<?php
	}

	// ------------------------------------------------------------- Settings.

	public function render_settings() {
		$this->guard();
		$enabled  = $this->csp->is_enabled();
		$post_url = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Settings', 'cybershield-checkout-script-monitor' ); ?></h1>
			<?php $this->notice_text(); ?>

			<p style="font-size:14px;">
				<?php esc_html_e( 'Monitoring:', 'cybershield-checkout-script-monitor' ); ?>
				<?php if ( $enabled ) : ?>
					<span class="cscsm-badge cscsm-badge--trusted"><?php esc_html_e( 'On', 'cybershield-checkout-script-monitor' ); ?></span>
				<?php else : ?>
					<span class="cscsm-badge cscsm-badge--sri-off"><?php esc_html_e( 'Off', 'cybershield-checkout-script-monitor' ); ?></span>
				<?php endif; ?>
			</p>

			<form method="post" action="<?php echo $post_url; // phpcs:ignore ?>">
				<input type="hidden" name="action" value="cscsm_save_settings" />
				<?php wp_nonce_field( 'cscsm_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Monitoring', 'cybershield-checkout-script-monitor' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="csp_report_only" value="1" <?php checked( $enabled ); ?> />
								<?php esc_html_e( 'Watch my checkout and alert me when a new script appears.', 'cybershield-checkout-script-monitor' ); ?>
							</label>
							<p class="description" style="max-width:700px;"><?php esc_html_e( 'This never blocks, changes, or slows anything for your customers. It quietly compares what loads on your cart and checkout against your trusted list and shows anything new under Alerts. Set your trusted list first (see Checkout Scripts).', 'cybershield-checkout-script-monitor' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'cybershield-checkout-script-monitor' ) ); ?>
			</form>

			<details style="margin-top:16px;max-width:700px;">
				<summary style="cursor:pointer;color:#50575e;"><?php esc_html_e( 'Technical details', 'cybershield-checkout-script-monitor' ); ?></summary>
				<p style="color:#50575e;"><?php esc_html_e( 'Monitoring sends a Content-Security-Policy-Report-Only header on the cart and checkout, built from your trusted list. Browsers report (but never block) any script outside that list to a private endpoint on your own site (/wp-json/cybershield-checkout-script-monitor/v1/csp-report). Reports are stored in your own database; the visitor IP address and referrer are dropped and never saved.', 'cybershield-checkout-script-monitor' ); ?></p>
			</details>
		</div>
		<?php
	}
}
