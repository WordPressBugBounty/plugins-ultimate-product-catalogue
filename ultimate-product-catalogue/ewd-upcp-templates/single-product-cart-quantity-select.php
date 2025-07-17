<div class='ewd-upcp-product-action-quantity'>

	<select name='ewd-upcp-product-action-quantity'>

		<?php for ( $i = 1; $i <= $this->get_max_item_quantity(); $i++ ) { ?>

			<option value='<?php echo $i; ?>'><?php echo $i; ?></option>
			
		<?php } ?>

	</select>

</div>