jQuery(function($){
	$(".intralink-content-preview").click(function(){
		$(this).toggleClass('preview-open');
		$(this).parent().find('.intralink-content').animate({ 
			opacity: 'toggle', 
			height: 'toggle',
		}, 'fast' );
		return false;
	});
});