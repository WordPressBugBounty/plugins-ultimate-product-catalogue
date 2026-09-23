jQuery(function($){
	var $deactivateLink = $('#the-list').find('[data-slug="ultimate-product-catalogue"] span.deactivate a'),
		$overlay        = $('#ewd-upcp-deactivate-survey-ultimate-product-catalogue'),
		$cancelButton   = $('#ewd-upcp-deactivation-cancel'),
		$form           = $overlay.find('form'),
		formOpen        = false;
	// Plugin listing table deactivate link.
	$deactivateLink.on('click', function(event) {
		event.preventDefault();
		$overlay.css('display', 'table');
		formOpen = true;
		$form.find('.ewd-upcp-deactivate-survey-option:first-of-type input[type=radio]').focus();
	});
	// Exit the survey without deactivating or submitting the form
	$cancelButton.on( 'click', function( event ) {
	 	$overlay.css('display', 'none');
	 	formOpen = false;
	});
	// Survey radio option selected.
	$form.on('change', 'input[type=radio]', function(event) {
		event.preventDefault();
		$form.find('input[type=text], .error').hide();
		$form.find('.ewd-upcp-deactivate-survey-option').removeClass('selected');
		$(this).closest('.ewd-upcp-deactivate-survey-option').addClass('selected').find('input[type=text]').show();
	});
	// Survey Skip & Deactivate.
	$form.on('click', '.ewd-upcp-deactivate-survey-deactivate', function(event) {
		event.preventDefault();
		location.href = $deactivateLink.attr('href');
	});
	// Survey submit.
	$form.submit(function(event) {
		event.preventDefault();
		if (! $form.find('input[type=radio]:checked').val()) {
			$form.find('.ewd-upcp-deactivate-survey-footer').prepend('<span class="error">Please select an option below</span>');
			return;
		}
		var data = {
			action: 'ewd_upcp_submit_deactivation_survey',
			nonce: ewd_upcp_deactivation_data.nonce,
			code: $form.find('.selected input[type=radio]').val(),
			details: $form.find('.selected input[type=text]').val()
		}
		var submitSurvey = $.post(ewd_upcp_deactivation_data.ajax_url, data);
		submitSurvey.always(function() {
			location.href = $deactivateLink.attr('href');
		});
	});
	// Exit key closes survey when open.
	$(document).keyup(function(event) {
		if (27 === event.keyCode && formOpen) {
			$overlay.hide();
			formOpen = false;
			$deactivateLink.focus();
		}
	});
});
