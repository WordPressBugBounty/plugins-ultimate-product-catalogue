<div class='ewd-upcp-single-product-tabs-menu'>

	<ul>

		<?php if ( ! in_array( 'details', $this->get_option( 'hide-product-tabs' ) ) ) { ?>

			<li class='ewd-upcp-single-product-menu-tab <?php echo ( $this->is_starting_tab( 'details' ) ? 'ewd-upcp-single-product-menu-tab-selected' : '' ); ?>' data-tab='details'>
				<?php echo esc_html( $this->get_label( 'label-product-details-tab' ) ); ?>
			</li>

		<?php } ?>

		<?php if ( ! in_array( 'additional_information', $this->get_option( 'hide-product-tabs' ) ) ) { ?>

			<li class='ewd-upcp-single-product-menu-tab <?php echo ( $this->is_starting_tab( 'additional_information' ) ? 'ewd-upcp-single-product-menu-tab-selected' : '' ); ?>' data-tab='additional_information'>
				<?php echo esc_html( $this->get_label( 'label-additional-info-tab' ) ); ?>
			</li>

		<?php } ?>

		<?php if ( $this->get_option( 'product-inquiry-form' ) and ! in_array( 'contact', $this->get_option( 'hide-product-tabs' ) ) ) { ?>

			<li class='ewd-upcp-single-product-menu-tab <?php echo ( $this->is_starting_tab( 'contact' ) ? 'ewd-upcp-single-product-menu-tab-selected' : '' ); ?>' data-tab='contact'>
				<?php echo esc_html( $this->get_label( 'label-contact-form-tab' ) ); ?>
			</li>

		<?php } ?>

		<?php if ( $this->get_option( 'product-reviews' ) and ! in_array( 'reviews', $this->get_option( 'hide-product-tabs' ) ) ) { ?>

			<li class='ewd-upcp-single-product-menu-tab <?php echo ( $this->is_starting_tab( 'reviews' ) ? 'ewd-upcp-single-product-menu-tab-selected' : '' ); ?>' data-tab='reviews'>
				<?php echo esc_html( $this->get_label( 'label-customer-reviews-tab' ) ); ?>
			</li>

		<?php } ?>

		<?php if ( $this->get_option( 'product-faqs' ) and ! in_array( 'faqs', $this->get_option( 'hide-product-tabs' ) ) ) { ?>

			<li class='ewd-upcp-single-product-menu-tab <?php echo ( $this->is_starting_tab( 'faqs' ) ? 'ewd-upcp-single-product-menu-tab-selected' : '' ); ?>' data-tab='faqs'>
				<?php echo esc_html( $this->get_label( 'label-faqs-tab' ) ); ?>
			</li>

		<?php } ?>

		<?php foreach ( $this->get_product_page_tabs() as $key => $tab ) { ?>

			<?php if ( in_array( $tab->name, $this->get_option( 'hide-product-tabs' ) ) ) { continue; } ?>

			<li class='ewd-upcp-single-product-menu-tab <?php echo ( $this->is_starting_tab( sanitize_title( $tab->name ) ) ? 'ewd-upcp-single-product-menu-tab-selected' : '' ); ?>' data-tab='<?php echo esc_attr( sanitize_title( $tab->name ) ); ?>'>
				<?php echo esc_html( $this->product->convert_custom_fields( $tab->name ) ); ?>
			</li>

		<?php } ?>

	</ul>

</div>