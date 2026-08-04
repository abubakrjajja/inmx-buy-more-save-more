        (function ($) {
            var offers = [];
            try { offers = JSON.parse($('#inmx-offers-json').val()) || []; } catch(e) {}

            /* ── Helpers ── */
            function esc(v) {
                return String(v == null ? '' : v)
                    .replace(/&/g,'&amp;').replace(/"/g,'&quot;')
                    .replace(/</g,'&lt;').replace(/>/g,'&gt;');
            }
            function catOptions(sel) {
                var html = '<option value="">-- Select Category --</option>';
                (window.INMX_CATS || []).forEach(function(c) {
                    html += '<option value="'+c.id+'"'+(parseInt(c.id)===parseInt(sel)?' selected':'')+'>'+esc(c.name)+'</option>';
                });
                return html;
            }
            function widgetOptions(sel) {
                var html = '<option value="">None</option>';
                Object.keys(window.INMX_WIDGET_TYPES || {}).forEach(function(slug) {
                    var label = window.INMX_WIDGET_TYPES[slug];
                    html += '<option value="'+slug+'"'+(slug===sel?' selected':'')+'>'+esc(label)+'</option>';
                });
                return html;
            }

            /* ── Tier card renderer ── */
            function renderTier(t, ti) {
                t = t || {};
                var giftOpt = (t.free_gift_product_id && t.free_gift_product_name)
                    ? '<option value="'+esc(t.free_gift_product_id)+'" selected>'+esc(t.free_gift_product_name.replace(/\s*\(#\d+\)\s*$/, '').trim())+'</option>'
                    : '';

                return '<div class="inmx-tier-card" data-ti="'+ti+'" style="background:#fafafa;border:1px solid #ddd;border-radius:6px;padding:13px 15px;margin-bottom:8px">'
                    /* ── Row A: pricing controls ── */
                    +'<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:11px">'
                    +'<label style="font-size:11px;font-weight:700;color:#444">Min Qty *<br>'
                    +'<input type="number" data-f="qty" value="'+esc(t.qty||'')+'" min="1" placeholder="3" class="small-text" style="width:60px;margin-top:3px"></label>'
                    +'<label style="font-size:11px;font-weight:700;color:#444">Sale Price / Item<br>'
                    +'<input type="number" data-f="price_per_item" value="'+esc(t.price_per_item!=null?t.price_per_item:'')+'" min="0" step="any" placeholder="750" class="small-text" style="width:86px;margin-top:3px"></label>'
                    +'<label style="font-size:11px;font-weight:700;color:#444">Regular Price / Item<br>'
                    +'<input type="number" data-f="original_price" value="'+esc(t.original_price!=null?t.original_price:'')+'" min="0" step="any" placeholder="1200" class="small-text" style="width:86px;margin-top:3px"></label>'
                    +'<label style="font-size:11px;font-weight:700;color:#444;text-align:center">Free Delivery<br>'
                    +'<input type="checkbox" data-f="free_delivery" value="1"'+(t.free_delivery?' checked':'')+' style="margin-top:6px;width:16px;height:16px"></label>'
                    // --- begin inserted Highlight label block ---
                    +'<label style="font-size:11px;font-weight:700;color:#444;text-align:center">Highlight<br>'
                    +'<input type="checkbox" data-f="highlight" value="1"'+(t.highlight?' checked':'')+' style="margin-top:6px;width:16px;height:16px"></label>'
                    // --- end inserted Highlight label block ---
                    +'<div style="margin-left:auto">'
                    +'<button type="button" class="button button-small inmx-del-tier" style="color:#b32d2e;border-color:#b32d2e">&#10005; Remove Tier</button>'
                    +'</div>'
                    +'</div>'
                    /* ── Row B: gift + perk text ── */
                    +'<div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">'
                    /* Gift product select + hidden inputs */
                    +'<div style="flex-shrink:0">'
                    +'<label style="display:block;font-size:11px;font-weight:700;color:#444;margin-bottom:4px">Free Gift Product <em style="font-weight:400">(optional)</em></label>'
                    +'<select class="inmx-tier-gift" data-ti="'+ti+'" style="min-width:230px">'+giftOpt+'</select>'
                    +'<input type="hidden" data-f="free_gift_product_id" value="'+esc(t.free_gift_product_id||0)+'">'
                    +'<input type="hidden" data-f="free_gift_product_name" value="'+esc(t.free_gift_product_name||'')+'">'
                    +'</div>'
                    /* Custom perk text */
                    +'<div style="flex:1;min-width:240px">'
                    +'<label style="display:block;font-size:11px;font-weight:700;color:#444;margin-bottom:4px">Custom Perk Message <em style="font-weight:400">(leave blank for auto)</em></label>'
                    +'<input type="text" data-f="custom_perk_text" value="'+esc(t.custom_perk_text||'')+'" placeholder="e.g. Add {needed} more {cat} for {currency}{total} + Free Delivery" style="width:100%;box-sizing:border-box">'
                    +'</div>'
                    // --- begin inserted badge label block ---
                    +'<div style="flex-shrink:0;min-width:180px">'
                    +'<label style="display:block;font-size:11px;font-weight:700;color:#444;margin-bottom:4px">'
                    +'Badge Label <em style="font-weight:400">(optional — e.g. ⭐ Best Selling)</em></label>'
                    +'<input type="text" data-f="badge_label" value="'+esc(t.badge_label||'')+'" '
                    +'placeholder="e.g. ⭐ Best Selling" style="width:100%;box-sizing:border-box">'
                    +'</div>'
                    // --- end inserted badge label block ---
                    // --- begin pack label block ---
                    +'<div style="flex-shrink:0;min-width:180px">'
                    +'<label style="display:block;font-size:11px;font-weight:700;color:#444;margin-bottom:4px">'
                    +'Pack Label <em style="font-weight:400">(optional — e.g. Any 3 Bandana)</em></label>'
                    +'<input type="text" data-f="pack_label" value="'+esc(t.pack_label||'')+'" '
                    +'placeholder="e.g. Any 3 Bandana" style="width:100%;box-sizing:border-box">'
                    +'</div>'
                    // --- end pack label block ---
                    +'</div>'
                    +'</div>';
            }

            /* ── Offer card renderer ── */
            function renderOffer(offer, oi) {
                offer = offer || {};
                var tiersHtml = (offer.tiers && offer.tiers.length)
                    ? offer.tiers.map(function(t, ti) { return renderTier(t, ti); }).join('')
                    : '<p class="inmx-no-tiers" style="color:#999;margin:0 0 8px">No tiers yet — click <strong>+ Add Tier</strong>.</p>';

                return '<div class="postbox inmx-offer" data-oi="'+oi+'" style="padding:18px 20px;margin-bottom:18px">'
                    /* Header */
                    +'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">'
                    +'<h3 style="margin:0;font-size:13px;font-weight:700;color:#333">Offer '+(oi+1)+(offer.name?' &mdash; <em>'+esc(offer.name)+'</em>':'')+'</h3>'
                    +'<button type="button" class="button button-small inmx-del-offer" style="color:#b32d2e;border-color:#b32d2e">Remove Offer</button>'
                    +'</div>'
                    /* Meta: name, category, redirect */
                    +'<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px;padding:12px 14px;background:#f9f9f9;border-radius:6px">'
                    +'<input type="text" data-f="name" value="'+esc(offer.name||'')+'" placeholder="Internal label (e.g. Bandana Bundle)" style="width:200px">'
                    +'<select data-f="category_id" style="min-width:210px">'+catOptions(offer.category_id)+'</select>'
                    +'<input type="url" data-f="redirect_url" value="'+esc(offer.redirect_url||'')+'" placeholder="Add More button URL (optional)" style="width:260px">'
                    +'</div>'
                    +'<div style="display:flex;align-items:center;gap:8px;padding:8px 14px;'
                    +'background:#f0f6fc;border:1px solid #d0e6f7;border-radius:6px;margin-bottom:10px">'
                    +'<input type="checkbox" data-f="auto_highlight" value="1"'
                    +(offer.auto_highlight?' checked':'')
                    +' style="width:15px;height:15px;margin:0;flex-shrink:0">'
                    +'<label style="font-size:11px;font-weight:700;color:#444;margin:0;cursor:pointer">'
                    +'Auto Highlight Next Tier '
                    +'<em style="font-weight:400;color:#888">'
                    +'— automatically applies dark card to whichever tier is next</em>'
                    +'</label>'
                    +'</div>'
                    /* Display location — widget selectors */
                    +'<div style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;'
                    +'padding:10px 14px;background:#f0f6fc;border:1px solid #d0e6f7;'
                    +'border-radius:6px;margin-bottom:14px">'
                    +'<span style="font-size:11px;font-weight:700;color:#0073aa;'
                    +'white-space:nowrap;align-self:center">Widget per location:</span>'

                    +'<label style="font-size:11px;font-weight:700;color:#444;margin:0">'
                    +'Product Page<br>'
                    +'<select data-f="widget_product" style="margin-top:4px;min-width:110px">'
                    + widgetOptions(offer.widget_product)
                    +'</select></label>'

                    +'<label style="font-size:11px;font-weight:700;color:#444;margin:0">'
                    +'Cart Page<br>'
                    +'<select data-f="widget_cart" style="margin-top:4px;min-width:110px">'
                    + widgetOptions(offer.widget_cart)
                    +'</select></label>'

                    +'<label style="font-size:11px;font-weight:700;color:#444;margin:0">'
                    +'Checkout<br>'
                    +'<select data-f="widget_checkout" style="margin-top:4px;min-width:110px">'
                    + widgetOptions(offer.widget_checkout)
                    +'</select></label>'

                    +'<label style="font-size:11px;font-weight:700;color:#444;margin:0">'
                    +'Side Cart<br>'
                    +'<select data-f="widget_mini" style="margin-top:4px;min-width:110px">'
                    + widgetOptions(offer.widget_mini)
                    +'</select></label>'

                    +'<span style="font-size:10px;color:#888;width:100%;margin-top:5px;display:block">'
                    +'<strong style="color:#555">Perk text variables</strong> &nbsp;'
                    +'<span style="display:inline-block;margin-right:10px"><em>Qty:</em> '
                    +'<code>{needed}</code> <code>{min_qty}</code> <code>{current_qty}</code></span>'
                    +'<span style="display:inline-block;margin-right:10px"><em>Price:</em> '
                    +'<code>{sale_price}</code> <code>{regular_price}</code> '
                    +'<code>{sale_total}</code> <code>{regular_total}</code></span>'
                    +'<span style="display:inline-block;margin-right:10px"><em>Discount:</em> '
                    +'<code>{discount_percentage}</code> <code>{discount_amount}</code> '
                    +'<code>{discount_total}</code></span>'
                    +'<span style="display:inline-block"><em>Other:</em> '
                    +'<code>{cat}</code> <code>{gift}</code> <code>{currency}</code></span>'
                    +'</span>'
                    +'</div>'
                    /* Tiers */
                    +'<div class="inmx-tiers-wrap">'+tiersHtml+'</div>'
                    +'<button type="button" class="button inmx-add-tier" style="margin-top:4px">+ Add Tier</button>'
                    +'</div>';
            }

            /* ── Render all offers ── */
            function renderAll() {
                $('#inmx-offers-list').html(
                    offers.length
                        ? offers.map(renderOffer).join('')
                        : '<p style="color:#999;margin:0 0 14px">No offers yet — click <strong>+ Add Offer</strong>.</p>'
                );
                initGiftSearches();
            }

            /* ── SelectWoo on every tier gift select ── */
            function initGiftSearches() {
                $('.inmx-tier-gift').each(function() {
                    var $el = $(this);
                    if ($el.data('select2') || $el.hasClass('select2-hidden-accessible')) return;

                    $el.selectWoo({
                        ajax: {
                            url: ajaxurl,
                            dataType: 'json',
                            delay: 300,
                            data: function(params) {
                                return {
                                    term:     params.term || '',
                                    action:   'woocommerce_json_search_products',
                                    security: (typeof wc_enhanced_select_params !== 'undefined')
                                              ? wc_enhanced_select_params.search_products_nonce : ''
                                };
                            },
                            processResults: function(data) {
                                var res = [];
                                if (data && typeof data === 'object') {
                                    $.each(data, function(id, text) { res.push({ id: id, text: text }); });
                                }
                                return { results: res };
                            },
                            cache: true
                        },
                        minimumInputLength: 2,
                        allowClear: true,
                        placeholder: 'Search product by name...',
                        width: '230px'
                    }).on('change', function() {
                        var val  = $el.val();
                        /* Strip the " (#ID)" suffix WooCommerce appends to product names */
                        var text = val ? $el.find('option:selected').text().replace(/\s*\(#\d+\)\s*$/, '').trim() : '';
                        /* Write into the hidden inputs that readDom() will pick up */
                        var $card = $el.closest('.inmx-tier-card');
                        $card.find('[data-f="free_gift_product_id"]').val(val || 0);
                        $card.find('[data-f="free_gift_product_name"]').val(text);
                    });
                });
            }

            /* ── Snapshot live DOM into offers[] before any mutation ── */
            function readDom() {
                $('#inmx-offers-list .inmx-offer').each(function() {
                    var oi = parseInt($(this).data('oi'), 10);
                    if (!offers[oi]) return;

                    /* Offer-level meta fields (exclude fields inside tier cards) */
                    $(this).find('[data-f]').not('.inmx-tier-card [data-f]').each(function() {
                        var f  = $(this).data('f');
                        var el = $(this);
                        if (el.attr('type') === 'checkbox') {
                            offers[oi][f] = el.is(':checked');
                        } else {
                            var v = el.val().trim();
                            offers[oi][f] = (f === 'category_id') ? (parseInt(v, 10) || 0) : v;
                        }
                    });

                    /* Tier cards */
                    var tiers = [];
                        $(this).find('.inmx-tier-card').each(function() {
                            var t = {};
                            $(this).find('[data-f]').each(function() {
                                var f = $(this).data('f');
                                var el = $(this);
                                if (f === 'free_delivery' || f === 'highlight') {
                                    t[f] = el.is(':checked');
                                } else if (f === 'free_gift_product_id') {
                                    t[f] = parseInt(el.val(), 10) || 0;
                                } else if (['qty', 'price_per_item', 'original_price'].indexOf(f) >= 0) {
                                    var v = el.val().trim();
                                    t[f] = v !== '' ? parseFloat(v) : null;
                                } else {
                                    t[f] = el.val().trim() || '';
                                }
                            });
                            if (t.qty) tiers.push(t);
                        });
                    offers[oi].tiers = tiers;
                });
            }

            /* ── Event handlers ── */
            $('#inmx-add-offer').on('click', function() {
                readDom();
                offers.push({ name: '', category_id: '', redirect_url: '',
                             show_product: true, show_cart: true, show_checkout: true, show_mini: true,
                             tiers: [] });
                renderAll();
            });

            $(document).on('click', '.inmx-add-tier', function() {
                readDom();
                var oi = parseInt($(this).closest('.inmx-offer').data('oi'), 10);
                offers[oi].tiers = offers[oi].tiers || [];
                offers[oi].tiers.push({
                    qty: '', price_per_item: null, original_price: null,
                    free_delivery: false, free_gift_product_id: 0,
                    free_gift_product_name: '', custom_perk_text: ''
                });
                renderAll();
                setTimeout(function() {
                    $('#inmx-offers-list .inmx-offer[data-oi="'+oi+'"] .inmx-tier-card:last-child input[data-f="qty"]').focus();
                }, 60);
            });

            $(document).on('click', '.inmx-del-tier', function() {
                readDom();
                var oi = parseInt($(this).closest('.inmx-offer').data('oi'), 10);
                var ti = parseInt($(this).closest('.inmx-tier-card').data('ti'), 10);
                offers[oi].tiers.splice(ti, 1);
                renderAll();
            });

            $(document).on('click', '.inmx-del-offer', function() {
                if (!confirm('Remove this offer and all its tiers?')) return;
                readDom();
                offers.splice(parseInt($(this).closest('.inmx-offer').data('oi'), 10), 1);
                renderAll();
            });

            $('form').on('submit.inmx', function() {
                readDom();
                $('#inmx-offers-json').val(JSON.stringify(offers));
            });

            renderAll();
        }(jQuery));
