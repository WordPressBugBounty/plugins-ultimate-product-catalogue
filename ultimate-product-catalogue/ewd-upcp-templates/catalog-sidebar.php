<?php $this->maybe_print_sidebar_toggle(); ?>

<div id='<?php echo esc_attr( $this->instance_id . '-sidebar' ); ?>' class='ewd-upcp-catalog-sidebar ewd-upcp-catalog-sidebar-checkbox-style-<?php echo esc_attr( $this->get_option( 'styling-sidebar-checkbox-style' ) ); ?><?php echo empty( $this->get_option( 'disable-toggle-sidebar-on-mobile' ) ) ? ' ewd-upcp-catalog-sidebar-hidden' : ''; ?>'>

	<?php $this->maybe_print_sidebar_clear_all(); ?>

	<?php $this->print_sidebar_items(); ?>

</div>
