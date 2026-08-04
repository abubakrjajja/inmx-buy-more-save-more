# Changelog

## 1.1.0 - 2026-08-04

### The saving is now a named discount line, not a silent reprice

Until 1.0.0 a qualifying cart item had its price rewritten with `set_price()`.
The customer saw Rs 799 on the product page and Rs 666.33 in the cart with
nothing explaining the difference, and the saving appeared nowhere on the order,
in any email, or in any WooCommerce report.

Products now keep their own price and the saving is added as a negative fee
named after the tier:

```
Subtotal                              Rs 2,397
AZAADI SALE: Any 3 Hand Bindis         -Rs 398
Total                                 Rs 1,999
```

Because it is a real WooCommerce fee, that line renders natively on the cart,
the checkout, the order screen, My Account and every order email. The custom
totals-row injection added in 1.0.0 was removed in the same change, since
keeping it would have shown the discount twice everywhere.

### Free Delivery now actually does something

The per-tier "Free Delivery" checkbox previously drew a badge and nothing else.
There was no shipping code in the plugin at all. On one live store it happened to
look right only because the store's own free-shipping threshold fell between the
2-pack and the 3-pack.

When a bundle is active, the tier now decides: it grants free delivery or it
does not, and the store's threshold is not consulted for that cart. Paid rates
are withdrawn when delivery is free, so a customer cannot pay for shipping the
offer already promised them.

This also closes a hole the discount change would otherwise have opened. Moving
the saving off the item price raises the subtotal that WooCommerce measures its
own free-shipping threshold against, which on that store would have started giving
away free delivery one tier early.

Override with the `inmx_bmsm_grants_free_delivery` filter.

### Fixed: wrong tier price within a single request

`inmx_qty_map()` cached one map per PHP process and ignored its `$cart` argument
after the first call. WooCommerce runs `calculate_totals()` more than once per
request in ordinary flows, including once more after this plugin adds or removes
a gift, so a later pass could read a map built from the pre-change cart and
select the wrong tier. The result was a silently wrong price rather than an
error. The cache is now keyed on cart contents.

Demonstrated rather than assumed: four carts calculated in one PHP process all
priced at tier 1 before the fix, and at their correct tiers after.

### Fixed: gift rows did not say what the gift was

The cart and side cart replaced the gift's name outright, producing a row that
read "Free Gift" over "1 x FREE". Gift rows now show the product's own name with
the Free Gift note beneath it.

### Changed: order savings measured from what was actually charged

`_inmx_bundle_discounts`, which feeds the Analytics tab, was derived from the
tier's configured regular price - a number an admin types and can get wrong. On
one store that field held pack totals instead of per-item prices, which would
have recorded Rs 13,988 of savings on a Rs 1,996 order.

It is now measured the same way the cart fee is, so the number in the report and
the number on the order are the same by construction.

---

## 1.0.0 - 2026-08-04

Initial release. The WPCode snippet "Bundle Upsell System v6" repackaged as a
plugin with no behaviour change, verified byte-identical to the copy running on
the origin store apart from line endings.

- 1,664 lines split into core / frontend / admin modules
- ~12 KB of inline CSS and ~21 KB of inline JS moved out of `wp_head` and
  `admin_footer` into cacheable asset files
- Reads the same `inmx_offers` option, so snippet configuration carries over
- HPOS and cart/checkout-blocks compatibility declared
- WooCommerce dependency gate: an admin notice instead of a fatal
- `uninstall.php` deletes nothing by default; order records never
- Source-agnostic update client with a GitHub Releases source
- Release workflow that fails the build if the tag and plugin header disagree
