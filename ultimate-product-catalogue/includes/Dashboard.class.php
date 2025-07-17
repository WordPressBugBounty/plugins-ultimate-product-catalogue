<?php
if ( !defined( 'ABSPATH' ) ) exit;

if ( !class_exists( 'ewdupcpDashboard' ) ) {
/**
 * Class to handle plugin dashboard
 *
 * @since 5.0.0
 */
class ewdupcpDashboard {

	public $message;
	public $status = true;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_dashboard_to_menu' ), 99 );
	}

	public function add_dashboard_to_menu() {
		global $ewd_upcp_controller;
		global $submenu;

		add_submenu_page( 
			'edit.php?post_type=upcp_product', 
			'Dashboard', 
			'Dashboard', 
			$ewd_upcp_controller->settings->get_setting( 'access-role' ), 
			'ewd-upcp-dashboard', 
			array($this, 'display_dashboard_screen') 
		);

		// Create a new sub-menu in the order that we want
		$new_submenu = array();
		$menu_item_count = 3;

		if ( ! isset( $submenu['edit.php?post_type=upcp_product'] ) or  ! is_array( $submenu['edit.php?post_type=upcp_product'] ) ) { return; }
		
		foreach ( $submenu['edit.php?post_type=upcp_product'] as $key => $sub_item ) {
			
			if ( $sub_item[0] == 'Dashboard' ) { $new_submenu[0] = $sub_item; }
			elseif ( $sub_item[0] == 'Settings' ) { $new_submenu[ sizeof( $submenu ) + 1 ] = $sub_item; }
			else {
				
				$new_submenu[$menu_item_count] = $sub_item;
				$menu_item_count++;
			}
		}

		ksort( $new_submenu );
		
		$submenu['edit.php?post_type=upcp_product'] = $new_submenu;
	}

	public function display_dashboard_screen() { 
		global $ewd_upcp_controller;

		$permission = $ewd_upcp_controller->permissions->check_permission( 'premium' );

		$args = array(
			'post_type'			=> EWD_UPCP_CATALOG_POST_TYPE,
			'posts_per_page'	=> 10
		);

		$catalogs = get_posts( $args );

		?>

		<div id="ewd-upcp-dashboard-content-area">
		
			<?php if ( ! $permission or get_option("EWD_UPCP_Trial_Happening") == "Yes" ) {
				$premium_info = '<div class="ewd-upcp-dashboard-visit-our-site">';
				$premium_info .= sprintf( __( '<a href="%s" target="_blank">Visit our website</a> to learn how to upgrade or get a free trial of the premium version.'), 'https://www.etoilewebdesign.com/premium-upgrade-instructions/?utm_source=upcp_dashboard&utm_content=visit_our_site_link' );
				$premium_info .= '</div>';

				$premium_info = apply_filters( 'ewd_dashboard_top', $premium_info, 'UPCP', 'https://www.etoilewebdesign.com/license-payment/?Selected=UPCP&Quantity=1' );

				echo wp_kses(
					$premium_info,
					apply_filters( 'ewd_dashboard_top_kses_allowed_tags', wp_kses_allowed_html( 'post' ) )
				);
			} ?>

			<ul class="ewd-upcp-dashboard-support-widgets">
				<li>
					<div class="ewd-upcp-dashboard-support-widgets-title"><?php _e('YouTube Tutorials', 'ultimate-product-catalogue'); ?></div>
					<div class="ewd-upcp-dashboard-support-widgets-text-and-link">
						<div class="ewd-upcp-dashboard-support-widgets-text"><span class="dashicons dashicons-star-empty"></span>Get help with our video tutorials</div>
						<a class="ewd-upcp-dashboard-support-widgets-link" href="https://www.youtube.com/watch?v=-AwTj0pfooo&list=PLEndQUuhlvSoTRGeY6nWXbxbhmgepTyLi&index=6&ab_channel=%C3%89toileWebDesign%C3%89toileWebDesign" target="_blank"><?php _e('View', 'ultimate-product-catalogue'); ?></a>
					</div>
				</li>
				<li>
					<div class="ewd-upcp-dashboard-support-widgets-title"><?php _e('Documentation', 'ultimate-product-catalogue'); ?></div>
					<div class="ewd-upcp-dashboard-support-widgets-text-and-link">
						<div class="ewd-upcp-dashboard-support-widgets-text"><span class="dashicons dashicons-star-empty"></span>View our in-depth plugin documentation</div>
						<a class="ewd-upcp-dashboard-support-widgets-link" href="https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/?utm_source=upcp_dashboard&utm_content=icons_documentation" target="_blank"><?php _e('View', 'ultimate-product-catalogue'); ?></a>
					</div>
				</li>
				<li>
					<div class="ewd-upcp-dashboard-support-widgets-title"><?php _e('Plugin FAQs', 'ultimate-product-catalogue'); ?></div>
					<div class="ewd-upcp-dashboard-support-widgets-text-and-link">
						<div class="ewd-upcp-dashboard-support-widgets-text"><span class="dashicons dashicons-star-empty"></span>Access plugin and info and FAQs here.</div>
						<a class="ewd-upcp-dashboard-support-widgets-link" href="https://wordpress.org/plugins/ultimate-product-catalogue/#faq" target="_blank"><?php _e('View', 'ultimate-product-catalogue'); ?></a>
					</div>
				</li>
				<li>
					<div class="ewd-upcp-dashboard-support-widgets-title"><?php _e('Get Support', 'ultimate-product-catalogue'); ?></div>
					<div class="ewd-upcp-dashboard-support-widgets-text-and-link">
						<div class="ewd-upcp-dashboard-support-widgets-text"><span class="dashicons dashicons-star-empty"></span>Need more help? Get in touch.</div>
						<a class="ewd-upcp-dashboard-support-widgets-link" href="https://www.etoilewebdesign.com/support-center/?utm_source=upcp_dashboard&utm_content=icons_get_support" target="_blank"><?php _e('View', 'ultimate-product-catalogue'); ?></a>
					</div>
				</li>
			</ul>
	
			<div class="ewd-upcp-dashboard-catalogs">
				<div class="ewd-upcp-dashboard-catalogs-title"><?php _e('Catalogs', 'ultimate-product-catalogue'); ?></div>
				<table class='ewd-upcp-overview-table wp-list-table widefat fixed striped posts'>
					<thead>
						<tr>
							<th><?php _e("Name", 'ultimate-product-catalogue'); ?></th>
							<th><?php _e("Shortcode", 'ultimate-product-catalogue'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
							if ( empty( $catalogs ) ) {echo "<tr><td colspan='3'>" . __("No catalogs to display yet. Create a catalog for it to be displayed here.", 'ultimate-product-catalogue') . "</td></tr>";}
							else {
								foreach ( $catalogs as $catalog ) { ?>
									<tr>
										<td><a href='post.php?post=<?php echo esc_html( $catalog->ID ); ?>&action=edit'><?php echo esc_html( $catalog->post_title ); ?></a></td>
										<td>[product-catalogue id='<?php echo sanitize_text_field( $catalog->ID ); ?>']</td>
									</tr>
								<?php }
							}
						?>
					</tbody>
				</table>
			</div>

			<?php if ( ! $permission or get_option("EWD_UPCP_Trial_Happening") == "Yes" ) { ?>
				<div class="ewd-upcp-dashboard-get-premium-and-trial<?php echo get_option( 'EWD_UPCP_Trial_Happening' ) == 'Yes' ? ' trial-happening' : ''; ?>">
					<div id="ewd-upcp-dashboard-new-footer-one">
						<div class="ewd-upcp-dashboard-new-footer-one-inside">
							<div class="ewd-upcp-dashboard-new-footer-one-left">
								<div class="ewd-upcp-dashboard-new-footer-one-title">What's Included in Our Premium Version?</div>
								<ul class="ewd-upcp-dashboard-new-footer-one-benefits">
									<li>Unlimited Products</li>
									<li>Custom Fields</li>
									<li>WooCommerce Sync and Checkout</li>
									<li>Import/Export Products</li>
									<li>Advanced Product Page Layouts</li>
									<li>Advanced Display and Styling Options</li>
									<li>Product Page SEO Options</li>
									<li>Inquiry Form and Inquiry Cart</li>
									<li>Product Sorting Options</li>
								</ul>
							</div>
							<div class="ewd-upcp-dashboard-new-footer-one-buttons">
								<a class="ewd-upcp-dashboard-new-upgrade-button" href="https://www.etoilewebdesign.com/license-payment/?Selected=UPCP&Quantity=1&utm_source=upcp_dashboard&utm_content=footer_upgrade" target="_blank">UPGRADE NOW</a>
								<?php if ( ! get_option("EWD_UPCP_Trial_Happening") ) { 
									$trial_info = '';
									echo apply_filters( 'ewd_trial_button', $trial_info, 'UPCP' );
								} ?>
							</div>
						</div>
					</div>
					<?php if ( get_option( "EWD_UPCP_Trial_Happening" ) == "Yes" ) { ?>
						<div class="ewd-upcp-dashboard-trial-container">
							<?php do_action( 'ewd_trial_happening', 'UPCP' ); ?>
						</div>
					<?php } ?>
				</div>
			<?php } ?>	

			<div class="ewd-upcp-dashboard-testimonials-and-other-plugins">

				<div class="ewd-upcp-dashboard-testimonials-container">
					<div class="ewd-upcp-dashboard-testimonials-container-title"><?php _e( 'What People Are Saying', 'ultimate-product-catalogue' ); ?></div>
					<ul class="ewd-upcp-dashboard-testimonials">
						<?php $randomTestimonial = rand(0,2);
						if($randomTestimonial == 0){ ?>
							<li id="ewd-upcp-dashboard-testimonial-one">
								<img src="<?php echo plugins_url( '../assets/img/dash-asset-stars.png', __FILE__ ); ?>">
								<div class="ewd-upcp-dashboard-testimonial-title">"Excellent and Works Perfect"</div>
								<div class="ewd-upcp-dashboard-testimonial-author">- @starmazing</div>
								<div class="ewd-upcp-dashboard-testimonial-text">A great way to organise products for mobile and desktop users. Result was perfect and more we could expect. Thanks a lot! <a href="https://wordpress.org/support/topic/excellent-to-design-a-shop-works-perfect-with-betheme/" target="_blank">read more</a></div>
							</li>
						<?php }
						if($randomTestimonial == 1){ ?>
							<li id="ewd-upcp-dashboard-testimonial-two">
								<img src="<?php echo plugins_url( '../assets/img/dash-asset-stars.png', __FILE__ ); ?>">
								<div class="ewd-upcp-dashboard-testimonial-title">"Love It!"</div>
								<div class="ewd-upcp-dashboard-testimonial-author">- @kevdogg</div>
								<div class="ewd-upcp-dashboard-testimonial-text">I am using the product catalog on two sites now ~ the premium version. It is fast and easy. Their support is fast and helpful... <a href="https://wordpress.org/support/topic/love-it-2027/" target="_blank">read more</a></div>
							</li>
						<?php }
						if($randomTestimonial == 2){ ?>
							<li id="ewd-upcp-dashboard-testimonial-three">
								<img src="<?php echo plugins_url( '../assets/img/dash-asset-stars.png', __FILE__ ); ?>">
								<div class="ewd-upcp-dashboard-testimonial-title">"Great plugin and TOP-Support"</div>
								<div class="ewd-upcp-dashboard-testimonial-author">- @bildfabrik</div>
								<div class="ewd-upcp-dashboard-testimonial-text">I searched for a plugin like this for month. Now my search is over – due to this great piece of work from Etoile Web Design... <a href="https://wordpress.org/support/topic/great-plugin-and-top-support-9/" target="_blank">read more</a></div>
							</li>
						<?php } ?>
					</ul>
				</div>

				<div class="ewd-upcp-dashboard-other-plugins-container">
					<div class="ewd-upcp-dashboard-other-plugins-container-title"><?php _e('Other plugins by Etoile', 'ultimate-product-catalogue'); ?></div>
					<ul class="ewd-upcp-dashboard-other-plugins">
						<li>
							<a href="https://wordpress.org/plugins/ultimate-faqs/" target="_blank"><img src="<?php echo plugins_url( '../assets/img/ewd-ufaq-icon.png', __FILE__ ); ?>"></a>
							<div class="ewd-upcp-dashboard-other-plugins-text">
								<div class="ewd-upcp-dashboard-other-plugins-title">Ultimate FAQs</div>
								<div class="ewd-upcp-dashboard-other-plugins-blurb">An easy-to-use FAQ plugin that lets you create, order and publicize FAQs, with many styles and options!</div>
							</div>
						</li>
						<li>
							<a href="https://wordpress.org/plugins/ultimate-reviews/" target="_blank"><img src="<?php echo plugins_url( '../assets/img/ewd-urp-icon.png', __FILE__ ); ?>"></a>
							<div class="ewd-upcp-dashboard-other-plugins-text">
								<div class="ewd-upcp-dashboard-other-plugins-title">Ultimate Reviews</div>
								<div class="ewd-upcp-dashboard-other-plugins-blurb">Let visitors submit reviews and display them right in the tabbed page layout!</div>
							</div>
						</li>
					</ul>
				</div>

			</div>

			<?php if ( ! $permission or get_option("EWD_UPCP_Trial_Happening") == "Yes" ) { ?>
				<div class="ewd-upcp-dashboard-guarantee">
					<img src="<?php echo plugins_url( '../assets/img/dash-asset-badge.png', __FILE__ ); ?>" alt="14-Day 100% Money-Back Guarantee">
					<div class="ewd-upcp-dashboard-guarantee-title-and-text">
						<div class="ewd-upcp-dashboard-guarantee-title">14-Day 100% Money-Back Guarantee</div>
						<div class="ewd-upcp-dashboard-guarantee-text">If you're not 100% satisfied with the premium version of our plugin - no problem. You have 14 days to receive a FULL REFUND. We're certain you won't need it, though.</div>
					</div>
				</div>
			<?php } ?>

		</div> <!-- us-dashboard-content-area -->
		
		<div id="ewd-upcp-dashboard-new-footer-two">
			<div class="ewd-upcp-dashboard-new-footer-two-inside">
				<img src="<?php echo plugins_url( '../assets/img/ewd-logo-white.png', __FILE__ ); ?>" class="ewd-upcp-dashboard-new-footer-two-icon">
				<div class="ewd-upcp-dashboard-new-footer-two-blurb">
					At Etoile Web Design, we build reliable, easy-to-use WordPress plugins with a modern look. Rich in features, highly customizable and responsive, plugins by Etoile Web Design can be used as out-of-the-box solutions and can also be adapted to your specific requirements.
				</div>
				<ul class="ewd-upcp-dashboard-new-footer-two-menu">
					<li>SUPPORT</li>
					<li><a href="https://www.youtube.com/watch?v=-AwTj0pfooo&list=PLEndQUuhlvSoTRGeY6nWXbxbhmgepTyLi&index=6&ab_channel=%C3%89toileWebDesign%C3%89toileWebDesign" target="_blank">YouTube Tutorials</a></li>
					<li><a href="https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/?utm_source=upcp_dashboard&utm_content=footer_documentation" target="_blank">Documentation</a></li>
					<li><a href="https://www.etoilewebdesign.com/support-center/?utm_source=upcp_dashboard&utm_content=footer_get_support" target="_blank">Get Support</a></li>
					<li><a href="https://wordpress.org/plugins/ultimate-product-catalogue/#faq" target="_blank">FAQs</a></li>
				</ul>
				<ul class="ewd-upcp-dashboard-new-footer-two-menu">
					<li>SOCIAL</li>
					<li><a href="https://www.facebook.com/EtoileWebDesign/" target="_blank">Facebook</a></li>
					<li><a href="https://twitter.com/EtoileWebDesign" target="_blank">Twitter</a></li>
					<li><a href="https://www.etoilewebdesign.com/category/blog/?utm_source=upcp_dashboard&utm_content=footer_blog" target="_blank">Blog</a></li>
				</ul>
			</div>
		</div> <!-- ewd-upcp-dashboard-new-footer-two -->
		
	<?php }

	public function display_notice() {
		if ( $this->status ) {
			echo "<div class='updated'><p>" . $this->message . "</p></div>";
		}
		else {
			echo "<div class='error'><p>" . $this->message . "</p></div>";
		}
	}
}
} // endif
