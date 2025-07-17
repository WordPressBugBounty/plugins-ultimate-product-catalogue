<div class='ewd-upcp-catalog-sidebar-price-filter'>

	<span>
		<?php echo esc_html( $this->get_label( 'label-price-filter' ) ); ?>
	</span>

	<div id='ewd-upcp-price-filter'></div>

	<div id='ewd-upcp-price-range'>
		
		<span>
			
			<input type='text' value='<?php echo esc_attr( $this->sidebar_min_price ); ?>' name='ewd-upcp-price-slider-min' <?php echo ( $this->get_option( 'disable-slider-filter-text-inputs' ) ? 'disabled' : '' ); ?> data-min_price='<?php echo esc_attr( $this->sidebar_min_price ); ?>' />

		</span>

		<span class='ewd-upcp-price-slider-divider'> - </span>

		<span>
			
			<input type='text' value='<?php echo esc_attr( $this->sidebar_max_price ); ?>' name='ewd-upcp-price-slider-max' <?php echo ( $this->get_option( 'disable-slider-filter-text-inputs' ) ? 'disabled' : '' ); ?> data-max_price='<?php echo esc_attr( $this->sidebar_max_price ); ?>' />

		</span>

	</div>

</div>