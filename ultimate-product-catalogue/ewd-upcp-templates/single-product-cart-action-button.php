<div class='ewd-upcp-single-product-action-button-container'>

	<?php $this->maybe_print_cart_quantity_select(); ?>

	<div class='ewd-upcp-product-action-button' data-product_id='<?php echo esc_attr( $this->product->id ); ?>'>
		
		<?php echo esc_html( $this->get_action_button_label() ); ?>

	</div>

</div>