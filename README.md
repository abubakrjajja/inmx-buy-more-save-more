# INMX Buy More Save More

Quantity-tier bundle offers for WooCommerce. Links a product category to pricing
tiers, then renders upsell widgets on the product page, cart, checkout and side
cart, applies the tier price to the cart, manages per-tier free gifts, and
records the discount on the order so it shows in emails and admin.

Originally a WPCode PHP snippet ("Bundle Upsell System v6"). Packaged as a
plugin at v1.0.0 with no change to how it behaves.

---

## What it does

| Piece | Behaviour |
|---|---|
| **Offers** | One offer maps one product category to a list of quantity tiers. |
| **Tiers** | Each tier sets a minimum qty, sale price per item, regular price per item, optional free delivery, optional free gift product, optional badge/pack labels, and optional custom perk text. |
| **Widgets** | `Goal Circles` (thumbnail progress row) and `Pricing Table` (vertical stepper). Chosen per offer, per location. |
| **Pricing** | Tier price is applied on `woocommerce_before_calculate_totals` with `set_price()`. Never written to the product. |
| **Gifts** | The active tier's gift is added to the cart automatically; every other tier's gift is removed. Gifts are priced at 0 and excluded from quantity counts. |
| **Orders** | Discount totals are saved to `_inmx_bundle_discounts` at checkout and injected into every order totals table, including emails. |
| **Analytics** | WooCommerce → Settings → Buy More Save More → Analytics. |

Category matching includes child categories, so an offer on a parent category
covers everything beneath it.

## Requirements

- WordPress 6.0+
- WooCommerce (any version with HPOS support; declared compatible with HPOS and
  the cart/checkout blocks)
- PHP 8.0+

## Install

Upload the release zip through **Plugins → Add New → Upload Plugin**, or:

```bash
wp plugin install inmx-buy-more-save-more.zip --activate
```

### Migrating from the WPCode snippet

The plugin reads the **same `inmx_offers` option** the snippet used, so an
existing configuration is picked up with no import step.

**Set the WPCode snippet to Draft before activating the plugin.** Running both
at once fatals the site with `Cannot redeclare inmx_render_widget()`.

## Configure

**WooCommerce → Settings → Buy More Save More**

Perk text variables, usable in any tier's custom perk message:

| Group | Variables |
|---|---|
| Quantity | `{needed}` `{min_qty}` `{current_qty}` `{qty}` |
| Price | `{sale_price}` `{regular_price}` `{price}` |
| Totals | `{sale_total}` `{regular_total}` `{total}` |
| Discount | `{discount_percentage}` `{discount_amount}` `{discount_total}` |
| Other | `{cat}` `{gift}` `{currency}` |

Leave the perk message blank to get an auto-generated one.

## Updates

Updates arrive through the normal WordPress Plugins screen, served from GitHub
Releases. See [docs/UPDATES.md](docs/UPDATES.md) for the token setup on a
private repo, how to ship a release, and how to turn updates off on staging.

## Layout

```
inmx-buy-more-save-more.php     bootstrap: constants, WooCommerce gate, HPOS, updater wiring
includes/core/data.php          offers, widget registry, cart maths, category matching, perk text
includes/core/pricing.php       gift management and tier prices on the cart
includes/core/orders.php        discount capture at checkout, totals-table rows
includes/frontend/widgets.php   the two widget renderers
includes/frontend/display-hooks.php  where widgets appear, AJAX fragments, gift item filters
includes/frontend/assets.php    CSS/JS enqueue
includes/admin/settings.php     WooCommerce settings tab and save handler
includes/admin/analytics.php    Analytics sub-tab
includes/updater/              source-agnostic update client + GitHub source
assets/                        frontend.css, frontend.js, admin.js
```

## Notes for whoever works on this next

- **The `inmx_` function prefix is not namespaced.** It is shared with other INMX
  snippets on these stores. Before adding a new top-level function, grep the
  other snippets in `custom-plugins/` for the name.
- **The mini-cart widget is Woodmart-specific.** `assets/js/frontend.js` moves
  the widget into `.shopping-cart-widget-body.wd-scroll .wd-scroll-content`
  because no WooCommerce hook fires inside that container. On a non-Woodmart
  theme the mini-cart widget renders but does not reposition.
- **`inmx_qty_map()` caches statically for the whole request** and ignores its
  `$cart` argument after the first call. That is inherited snippet behaviour,
  preserved deliberately in 1.0.0 so the repackage could be verified as a pure
  repackage. See `docs/ROADMAP.md`.
- **Analytics loads every matching order** with `limit => -1`. Admin-only and
  on demand, but it is the heaviest thing here. Also in `docs/ROADMAP.md`.
