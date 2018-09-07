/**
 * Attach tooltips
 *
 * @param {string} selector
 */
function edd_attach_tooltips( selector ) {
	selector.tooltip( {
		content: function() {
			return $( this ).prop( 'title' );
		},
		tooltipClass: 'edd-ui-tooltip',
		position: {
			my: 'center top',
			at: 'center bottom+10',
			collision: 'flipfit',
		},
		hide: {
			duration: 200,
		},
		show: {
			duration: 200,
		},
	} );
}

jQuery( document ).ready( function( $ ) {
	edd_attach_tooltips( $( '.edd-help-tip' ) );
} );
