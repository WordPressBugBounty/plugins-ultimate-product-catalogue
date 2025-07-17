<div class='ewd-upcp-catalog-header-bar'>
	
	<?php foreach ( $this->get_catalog_views() as $catalog_view ) { ?>

		<?php 
		$view_icon = $catalog_view;
		if ( $catalog_view == 'thumbnail' ) { $view_icon = 'grid'; }
		if ( $catalog_view == 'detail' ) { $view_icon = 'excerpt'; }
		?>

		<div class='ewd-upcp-toggle-icon ewd-upcp-toggle-icon-<?php echo esc_attr( $this->get_option( 'color-scheme' ) ); ?>' data-view='<?php echo esc_attr( $catalog_view ); ?>'><span class="dashicons dashicons-<?php echo $view_icon; ?>-view"></span></div>

	<?php } ?>

</div>