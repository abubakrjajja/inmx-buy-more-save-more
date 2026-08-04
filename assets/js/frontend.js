    (function($){
        function inmxRepositionMini(){
    var $scroll = $('.shopping-cart-widget-body.wd-scroll .wd-scroll-content').first();
    var $wraps  = $('.inmx-upsell-wrap.inmx-ctx-mini');
    if ( !$scroll.length || !$wraps.length ) return;
    if ( $wraps.length === 1 && $wraps.closest('.shopping-cart-widget-body.wd-scroll .wd-scroll-content').length ) return;
    var $keeper = $wraps.first();
    $wraps.not($keeper).remove();
    if ( !$keeper.closest('.shopping-cart-widget-body.wd-scroll .wd-scroll-content').length ) {
        $scroll.append($keeper);
    }
}
        $(document).ready(inmxRepositionMini);
        $(document.body).on('wc_fragments_refreshed added_to_cart removed_from_cart', function(){
            setTimeout(inmxRepositionMini, 50);
        });
        if ( window.MutationObserver ) {
            new MutationObserver(function(mutations){
                for (var i = 0; i < mutations.length; i++) {
                    var added = mutations[i].addedNodes;
                    for (var j = 0; j < added.length; j++) {
                        var node = added[j];
                        if ( node.nodeType !== 1 ) continue;
                        if ( $(node).is('.inmx-upsell-wrap.inmx-ctx-mini') || $(node).find('.inmx-upsell-wrap.inmx-ctx-mini').length ) {
                            inmxRepositionMini();
                            return;
                        }
                    }
                }
            }).observe(document.body, { childList: true, subtree: true });
        }
    }(jQuery));
