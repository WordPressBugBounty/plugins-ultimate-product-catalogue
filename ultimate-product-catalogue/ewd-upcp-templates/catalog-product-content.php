<div class='ewd-upcp-catalog-product-list-body-div'>

	<div class='ewd-upcp-catalog-product-description'>
		<?php echo wp_kses_post( $this->product->description ); ?>
	</div>

	<div class='ewd-upcp-catalog-product-list-custom-fields'>
		<?php $this->maybe_print_custom_fields(); ?>
	</div>

</div>