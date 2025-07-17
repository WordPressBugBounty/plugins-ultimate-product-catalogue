<?php
if ( !defined( 'ABSPATH' ) ) exit;

if ( !class_exists( 'ewdupcpHelper' ) ) {
/**
 * Class to to provide helper functions
 *
 * @since 5.1.0
 */
class ewdupcpHelper {

  // Hold the class instance.
  private static $instance = null;

  // Links for the help button
  private static $documentation_link = 'https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/';
  private static $tutorials_link = 'https://www.youtube.com/playlist?list=PLEndQUuhlvSoTRGeY6nWXbxbhmgepTyLi';
  private static $support_center_link = 'https://www.etoilewebdesign.com/support-center/?Plugin=UPCP&Type=FAQs';

  // Values for when to trigger the help button to display
  private static $post_types = array( EWD_UPCP_PRODUCT_POST_TYPE, EWD_UPCP_CATALOG_POST_TYPE );
  private static $taxonomies = array( EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY, EWD_UPCP_PRODUCT_TAG_TAXONOMY );
  private static $additional_pages = array( 'ewd-upcp-export', 'ewd-upcp-import', 'ewd-upcp-custom-fields', 'ewd-upcp-product-page', 'ewd-upcp-about-us' );

  /**
   * The constructor is private
   * to prevent initiation with outer code.
   * 
   **/
  private function __construct() {}

  /**
   * The object is created from within the class itself
   * only if the class has no instance.
   */
  public static function getInstance() {

    if ( self::$instance == null ) {

      self::$instance = new ewdupcpHelper();
    }
 
    return self::$instance;
  }

  /**
   * Handle ajax requests in admin area for logged out users
   * @since 5.1.0
   */
  public static function admin_nopriv_ajax() {

    wp_send_json_error(
      array(
        'error' => 'loggedout',
        'msg'   => sprintf( __( 'You have been logged out. Please %slogin again%s.', 'ultimate-product-catalogue' ), '<a href="' . wp_login_url( admin_url( 'admin.php?page=ewd-upcp-dashboard' ) ) . '">', '</a>' ),
      )
    );
  }

  /**
   * Handle ajax requests where an invalid nonce is passed with the request
   * @since 5.1.0
   */
  public static function bad_nonce_ajax() {

    wp_send_json_error(
      array(
        'error' => 'badnonce',
        'msg'   => __( 'The request has been rejected because it does not appear to have come from this site.', 'ultimate-product-catalogue' ),
      )
    );
  }

  /**
   * Escapes PHP data being passed to JS, recursively
   * @since 5.1.0
   */
  public static function escape_js_recursive( $values ) {

    $return_values = array();

    foreach ( (array) $values as $key => $value ) {

      if ( is_array( $value ) ) {

        $value = ewdupcpHelper::escape_js_recursive( $value );
      }
      elseif ( ! is_scalar( $value ) ) { 

        continue;
      }
      else {

        $value = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
      }
      
      $return_values[ $key ] = $value;
    }

    return $return_values;
  }

  public static function display_help_button() {

    if ( ! ewdupcpHelper::should_button_display() ) { return; }

    ewdupcpHelper::enqueue_scripts();

    $page_details = self::get_page_details();

    ?>
      <button class="ewd-upcp-dashboard-help-button" aria-label="Help">?</button>

      <div class="ewd-upcp-dashboard-help-modal ewd-upcp-hidden">
        <div class="ewd-upcp-dashboard-help-description">
          <?php echo esc_html( $page_details['description'] ); ?>
        </div>
        <div class="ewd-upcp-dashboard-help-tutorials">
          <?php foreach ( $page_details['tutorials'] as $tutorial ) { ?>
            <a href="<?php echo esc_url( $tutorial['url'] ); ?>" target="_blank">
              <?php echo esc_html( $tutorial['title'] ); ?>
            </a>
          <?php } ?>
        </div>
        <div class="ewd-upcp-dashboard-help-links">
          <?php if ( ! empty( self::$documentation_link ) ) { ?>
              <a href="<?php echo esc_url( self::$documentation_link ); ?>" target="_blank" aria-label="Documentation">
                <?php _e( 'Documentation', 'ultimate-product-catalogue' ); ?>
              </a>
          <?php } ?>
          <?php if ( ! empty( self::$tutorials_link ) ) { ?>
              <a href="<?php echo esc_url( self::$tutorials_link ); ?>" target="_blank" aria-label="YouTube Tutorials">
                <?php _e( 'YouTube Tutorials', 'ultimate-product-catalogue' ); ?>
              </a>
          <?php } ?>
          <?php if ( ! empty( self::$support_center_link ) ) { ?>
              <a href="<?php echo esc_url( self::$support_center_link ); ?>" target="_blank" aria-label="Support Center">
                <?php _e( 'Support Center', 'ultimate-product-catalogue' ); ?>
              </a>
          <?php } ?>
        </div>
      </div>
    <?php
  }

  public static function should_button_display() {
    global $post;
    
    $page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
    $taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_text_field( $_GET['taxonomy'] ) : '';

    if ( isset( $_GET['post'] ) ) {

      $post = get_post( intval( $_GET['post'] ) );
      $post_type = $post->post_type;
    }
    else {
      
      $post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( $_GET['post_type'] ) : '';
    }

    if ( in_array( $post_type, self::$post_types ) ) { return true; }

    if ( in_array( $taxonomy, self::$taxonomies ) ) { return true; }

    if ( in_array( $page, self::$additional_pages ) ) { return true; }

    return false;
  }

  public static function enqueue_scripts() {

    wp_enqueue_style( 'ewd-upcp-admin-helper-button', EWD_UPCP_PLUGIN_URL . '/assets/css/ewd-upcp-helper-button.css', array(), EWD_UPCP_VERSION );

    wp_enqueue_script( 'ewd-upcp-admin-helper-button', EWD_UPCP_PLUGIN_URL . '/assets/js/ewd-upcp-helper-button.js', array( 'jquery' ), EWD_UPCP_VERSION, true );
  }

  public static function get_page_details() {
    global $post;

    $page_details = array(
      'upcp_product' => array(
        'description' => __( 'The Products page displays a list of all your products and allows you to manage them using quick edit, bulk actions, import/export tools, and sorting/search filters. It serves as the main hub for organizing and updating product data at scale. The product edit screen is for inputting individual product details. You can assign products to categories, and then add products to a catalog on the catalog edit screen.', 'ultimate-product-catalogue' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/products/import',
            'title' => 'Import Products'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/products/export',
            'title' => 'Export Products'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/products/create',
            'title' => 'Add or Edit a Product'
          ),
        )
      ),
      'upcp_catalog' => array(
        'description' => __( 'The Catalogs page displays a list of all your catalogs and allows you to manage them using quick edit, bulk actions and sorting/search filters. The catalog edit screen allows you to group together products and/or categories for display. You can sort the order and manage which products or categories are included at the bottom.', 'ultimate-product-catalogue' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/catalogs/create',
            'title' => 'Add or Edit a Catalog'
          ),
        )
      ),
      'upcp-product-category' => array(
        'description' => __( 'The categories screen allows you to create and manage product groupings (e.g. bicycles, flowers). You can assign images, set category order, and create sub-categories for advanced filtering.', 'ultimate-product-catalogue' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/products/categories',
            'title' => 'Add or Edit a Category/Subcategory'
          ),
        )
      ),
      'upcp-product-tag' => array(
        'description' => __( 'The tags screen lets you create shared labels that group products with similar characteristics (e.g. color, model year). Tags can be used for filtering in the catalog sidebar.', 'ultimate-product-catalogue' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/products/tags',
            'title' => 'Add or Edit a Tag'
          ),
        )
      ),
      'ewd-upcp-custom-fields' => array(
        'description' => __( 'This section allows you to create additional product attributes (e.g. color, size, PDFs) that can be shown on product pages, thumbnails, or used for catalog filtering. Custom fields can be text-based or selection-based, and can be reused across products.', 'ultimate-product-catalogue' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/custom-fields/create',
            'title' => 'Add or Edit a Custom Field'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/custom-fields/products',
            'title' => 'Using Custom Fields with Products'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/custom-fields/filters',
            'title' => 'Custom Fields and Filtering'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/custom-fields/shortcodes',
            'title' => 'Displaying Custom Fields via Shortcode'
          ),
        )
      ),
      'ewd-upcp-settings' => array(
        'description' => __( 'The Basic Settings page allows you to configure global catalog behaviors.', 'ultimate-product-catalogue' ),
        'tutorials'   => array(
        )
      ),
      'ewd-upcp-settings-ewd-upcp-basic-tab' => array(
        'description' => __( 'The Basic Settings page allows you to configure global catalog behaviors.', 'ultimate-product-catalogue' ),
        'tutorials'   => array(
        )
      ),
      'ewd-upcp-settings-ewd-upcp-premium-tab' => array(
        'description' => __( 'The Premium Settings page lets you configure advanced catalog features like product page style, lightbox behavior, infinite scroll, inquiries, product reviews, FAQs, and comparison tools. These settings enhance interactivity, filtering, and integration with other plugins.', 'ultimate-product-catalogue' ),
        'tutorials'   => array(
        )
      ),
      'ewd-upcp-settings-ewd-upcp-woocommerce-tab' => array(
        'description' => __( 'The WooCommerce settings allow you to integrate UPCP with WooCommerce, enabling seamless syncing of products, and use of WooCommerce checkout.', 'ultimate-product-catalogue' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/woocommerce/sync',
            'title' => 'WooCommerce Sync'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/woocommerce/checkout',
            'title' => 'WooCommerce Checkout'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/woocommerce/product-page',
            'title' => 'WooCommerce Product Page'
          ),
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/woocommerce/faq',
            'title' => 'WooCommerce FAQs'
          ),
        )
      ),
      'ewd-upcp-settings-ewd-upcp-seo-tab' => array(
        'description' => __( 'The SEO Settings page lets you customize product page URLs and meta titles to improve search engine visibility. Options include pretty permalinks, custom slugs, Yoast SEO integration, and dynamic title structures using product or category names.', 'ultimate-product-catalogue' ),
        'tutorials'   => array(
        )
      ),
      'ewd-upcp-settings-ewd-upcp-labelling-tab' => array(
        'description' => __( 'The Labelling Settings page allows you to customize or translate the wording used throughout the catalog interface. It’s ideal for adapting the plugin to a different language or simply modifying default labels to better suit your site’s tone.', 'ultimate-product-catalogue' ),
        'tutorials'   => array(
        )
      ),
      'ewd-upcp-settings-ewd-upcp-styling-tab' => array(
        'description' => __( 'The Styling Settings page allows you to customize the appearance of the catalog, product pages, and filtering sidebar.', 'ultimate-product-catalogue' ),
        'tutorials'   => array(
          array(
            'url'   => 'https://doc.etoilewebdesign.com/plugins/ultimate-product-catalog/user/styling/css',
            'title' => 'Adding Custom CSS to your Catalogs and Products'
          ),
        )
      ),
    );

    $tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : '';
    $page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
    $taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_text_field( $_GET['taxonomy'] ) : '';

    if ( isset( $_GET['post'] ) ) {

      $post = get_post( intval( $_GET['post'] ) );
      $post_type = $post->post_type;
    }
    else {
      
      $post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( $_GET['post_type'] ) : '';
    }

    if ( in_array( $page . '-' . $tab, array_keys( $page_details ) ) ) { return $page_details[ $page . '-' . $tab ]; }

    if ( in_array( $page, array_keys( $page_details ) ) ) { return $page_details[ $page ]; }

    if ( in_array( $taxonomy, array_keys( $page_details ) ) ) { return $page_details[ $taxonomy ]; }

    if ( in_array( $post_type, array_keys( $page_details ) ) ) { return $page_details[ $post_type ]; }

    return array( 'description', 'tutorials' => array() );
  }
}

}