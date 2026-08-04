# Known issues

> Items 1 and 5 below were **fixed in 1.1.0** and are struck through.
> See CHANGELOG.md.

# Original list, deliberately not fixed in 1.0.0

1.0.0 is a pure repackage of the WPCode snippet. Behaviour is identical on
purpose: if something breaks after installing it, the cause is packaging, not
logic. Every item below was found while packaging and is queued for 1.0.1 so it
lands as its own change with its own before/after test.

---

## ~~1. `inmx_qty_map()` goes stale within a request~~ FIXED IN 1.1.0

`includes/core/data.php`

```php
function inmx_qty_map( ?WC_Cart $cart = null ): array {
    static $map = null;
    if ( null !== $map ) return $map;   // ← ignores $cart from here on
```

The map is computed once per request and frozen. Every later call returns the
first result, whatever cart is passed in.

**Why it matters.** WooCommerce runs `calculate_totals()` more than once in a
single request in ordinary flows - updating a quantity on the cart page, and
again after `inmx_manage_gifts()` adds or removes a gift, which itself changes
cart contents. The second pass reads a map built from the pre-change cart, so
`inmx_apply_prices()` can select the tier for the old quantity. The customer is
then charged the wrong per-item price, and it is a silent wrong number rather
than an error.

**Fix.** Key the cache on cart contents rather than caching a single value:

```php
static $cache = [];
$cart = $cart ?? WC()->cart;
if ( ! $cart instanceof WC_Cart ) return [];
$key = md5( wp_json_encode( array_map(
    fn( $i ) => [ (int) $i['product_id'], (int) $i['quantity'] ],
    $cart->get_cart()
) ) );
if ( isset( $cache[ $key ] ) ) return $cache[ $key ];
```

**Test before shipping.** Cart with N items at tier 1, raise the quantity to
cross into tier 2 in one update, and confirm the line total matches tier 2 in the
same request - not on the next page load.

## 2. Analytics loads every matching order into memory

`includes/admin/analytics.php`

`wc_get_orders()` with `'limit' => -1` and `'return' => 'objects'` instantiates
every matching order. On a store with a long history this is the single heaviest
page in the plugin.

Admin-only and on demand, so it cannot affect customers. It still matters here:
all nine sites share one CPU allowance, so one admin opening "All time" spends
budget that belongs to every other site.

**Fix.** Aggregate in SQL over the meta directly, or page through with
`'return' => 'ids'` and a bounded batch size.

## 3. Gift management writes to the cart inside a totals hook

`includes/core/pricing.php`

`inmx_manage_gifts()` calls `add_to_cart()` and `remove_cart_item()` on
`woocommerce_before_calculate_totals`. Both re-enter totals calculation, which is
why the `static $busy` re-entrancy guard exists.

It works, and it has been running in production on zensha.pk. It is fragile: the
guard makes the second pass a no-op, so a gift change requested during a
recalculation is dropped rather than applied late. Worth revisiting with
`woocommerce_cart_loaded_from_session` plus an explicit recalculation, but only
with a test that covers add, remove, and tier change in one request.

## 4. No nonce of our own on the settings save

`includes/admin/settings.php`

Not a defect. `WC_Admin_Settings::save()` verifies the `woocommerce-settings`
nonce before firing `woocommerce_settings_save_inmx_bundle`, so the handler is
unreachable without one. Recorded here so nobody "fixes" it by adding a second
nonce check that breaks saving.

## 5. Mini-cart repositioning is theme-coupled (still open)

`assets/js/frontend.js` targets `.shopping-cart-widget-body.wd-scroll` - a
Woodmart class. On any other theme the mini-cart widget still renders but is not
moved into the scrolling container.

Fine while every store using this runs Woodmart. It needs a selector filter
before this ships anywhere else.

## 6. `free_delivery` was cosmetic - FIXED IN 1.1.0

The per-tier checkbox rendered a badge and a line of perk text and nothing else.
There was no `woocommerce_package_rates` filter or any other shipping code in
the plugin. It read correctly on bindiya.pk only because the store's own
free-shipping threshold of Rs 1,500 happened to sit between the 2-pack (1,399)
and the 3-pack (1,999).

Found while packaging 1.0.0 by grepping for every use of the setting rather than
trusting that a configurable option was wired to something.
