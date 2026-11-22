# InkOS for WooCommerce

Connect your print shop’s WooCommerce site to InkOS. Shoppers choose a product or variation and see the print price for their quantity. Accepted checkouts appear in the InkOS order register with one order and linked work order per print line.

[Download the installable plugin ZIP](dist/inkos-woocommerce.zip?raw=true) · [E-commerce API contract and developer examples](https://github.com/InkOS-io/ecommerce-api)

## Install and connect

1. Install and activate WooCommerce. The plugin supports PHP 7.4+, WordPress 6.5+ and WooCommerce 8.8+. Use versions supported by your WooCommerce installation.
2. In WordPress **Plugins → Add Plugin → Upload Plugin**, select `dist/inkos-woocommerce.zip`, then install and activate **InkOS for WooCommerce**.
3. An InkOS shop administrator creates a storefront and enables its sellable configurations. Each configuration identifies a fixed product and set of specifications. Copy the storefront credential when InkOS issues it.
4. A WordPress administrator opens **WooCommerce → InkOS**. Enter the InkOS server origin (for example, `https://print.example.com`) and the storefront credential. Choose **Test and save connection**. The screen shows the verified storefront and currency.
5. Use the same currency in **WooCommerce → Settings → General**, with two decimal places. Use free pickup, free shipping or virtual products, and configure WooCommerce so the line and order totals exactly match the InkOS terms.

The credential is stored server-side as a non-autoloaded WordPress option. For a deployment-managed secret, define both constants in `wp-config.php` and run **Test and save connection**.

```php
define( 'INKOS_ECOMMERCE_URL', 'https://print.example.com' );
define( 'INKOS_ECOMMERCE_TOKEN', getenv( 'INKOS_STOREFRONT_TOKEN' ) );
```

Verify the connection again after changing credentials, the origin or WordPress authentication salts. Revoking the storefront in InkOS stops new API calls. Existing Woo orders stay bound to their original storefront.

## Map what shoppers select

1. Create a published, purchasable WooCommerce simple product or a variable product with specific variations. WooCommerce requires a numeric regular price and stock availability to enable purchasing.
2. In **Products**, find the simple product ID, or expand **Product data → Variations** to find an individual variation ID. For example, map the matte postcard variation and the gloss postcard variation separately.
3. Open **WooCommerce → InkOS**. Enter that ID, select the InkOS configuration by name and options, then click **Save mapping**. Repeat for each sellable variation. Selecting **Remove mapping** restores ordinary WooCommerce pricing for new carts.
4. Open the product as a shopper, select a variation and change quantity. The product form shows the InkOS batch total and expiration. In the cart, WooCommerce multiplies the unrounded unit equivalent by the quantity. InkOS’s agreed line total is the final checkout check.

Each mapped product form, cart line and checkout uses a server-side request. The Store API product catalog exposes a one-unit InkOS display price, cached for up to five minutes within its validity period. The quantity selector requests fresh terms. WooCommerce’s native price-sort indexes, aggregate price filters and third-party product feeds use its saved catalog fields. Use an appropriate regular price there and connect custom quote clients to the published contract.

## Checkout and production

Classic checkout and the Cart/Checkout blocks both verify the original quotes before payment. Changing quantity requests new terms. If an unchanged line expires, the customer removes and re-adds the line to review the new price. Mapped and ordinary products require separate checkouts. One print order can contain up to 50 lines. Additional tax, discounts, paid shipping or fees that would change the agreed InkOS total block placement.

The plugin sends the order when WooCommerce records **Processing**, **Completed** or **On hold**. This includes accepted offline payment methods such as bank transfer. A pending payment or failed checkout waits for an eligible status. An existing Woo gateway transaction ID is captured on first transmission as a trace reference. InkOS retains its own payment reconciliation, artwork approval, deposit and production-release workflow.

The WooCommerce order’s **InkOS synchronization** section displays the external reference, status and InkOS order/work-order numbers. The full receipt with immutable quote IDs, InkOS IDs, currency and total is stored in protected order metadata through WooCommerce CRUD. Staff can locate each corresponding record in InkOS by number or storefront reference.

## Outages and staff retry

Before transmission the plugin persists the exact checkout payload, including the external order ID and payment reference. It reuses the same idempotency key and payload after timeouts, concurrent gateway callbacks and repeated scheduled actions. The original order key stays in WooCommerce. Only its derived external reference is transmitted.

WooCommerce Action Scheduler writes a recovery action before the request. Transport failures, HTTP 408, 429 and 5xx responses retry with backoff up to one day between attempts. An available Retry-After header postpones automatic retry. Ensure WP-Cron or a real Action Scheduler runner is active. Find jobs in **WooCommerce → Status → Scheduled Actions**, group `inkos-woocommerce`.

**Needs review** identifies an invalid configuration, rejected original terms, credential problem or conflict. Inspect the message on the order. After restoring the connection, choose **Retry InkOS synchronization** in the WooCommerce order’s **Order actions** menu. A retry always preserves the buyer’s terms. If InkOS never accepted an expired quote, arrange a new customer-approved checkout and reconcile the original payment in WooCommerce. A replay of a previously accepted checkout recovers the original receipt even after expiration.

## Reproduce a sandbox checkout

1. Use an isolated InkOS database with synthetic shop, customer and product data. Set up the storefront through the administration endpoints in the [API developer guide](https://github.com/InkOS-io/ecommerce-api). Keep storefront credentials outside the plugin source.
2. Install WordPress and WooCommerce in a separate local site with HPOS enabled. Configure the shop’s currency, enable guest checkout and enable the built-in **Direct bank transfer** gateway for the test. Use synthetic accounts and disable real customer email delivery in this environment.
3. Install the ZIP and map two test item IDs as above. Use virtual, non-taxable products or free pickup where appropriate. If using a loopback HTTP InkOS API, set `WP_ENVIRONMENT_TYPE` to `local` in this disposable WordPress installation and enter a literal loopback origin such as `http://127.0.0.1:8080`. Outside local loopback the connection requires HTTPS.
4. Run the included Python 3 test against the local site. Substitute the actual WooCommerce product/variation IDs. Each argument is `ID:quantity`. This command creates an accepted synthetic WooCommerce order and real InkOS order/work-order pairs in the sandbox.

```sh
python3 tests/store-checkout.py http://127.0.0.1:8081 101:3 104:7
```

5. Open the printed Woo order and confirm its synchronized references. Inspect the corresponding InkOS register and production board for the original quantity, options, price, external reference and release state. In **Order actions**, a completed receipt suppresses further transmission.
6. To reproduce a worker outage, interrupt connectivity **after** gateway acceptance and before successful delivery. Restore the connection and run the pending job using **Scheduled Actions**. Compare the external reference and the recovered receipt. Interruption of the earlier price-verification call correctly prevents placement.

Build the identical upload archive with `python3 tools/package.py`. The archive has one top-level `inkos-woocommerce` folder and includes installation instructions.

## Verified journeys

In isolated WordPress 7.1.5, WooCommerce 11.3.1 and real InkOS/PostgreSQL instances, a browser displayed CAD 5.31 for three simple products and CAD 12.39 for seven units of a variation. The Store API guest checkout accepted CAD 17.70 through the sandbox bank-transfer gateway and received two InkOS order and work-order pairs. A classic guest checkout for three units preserved a CAD 10.01 batch total through to the InkOS receipt.

A second Store API checkout exercised a dropped response after the InkOS transaction committed. WooCommerce persisted a retry, and the worker received HTTP 200 for the original HTTP 201 result with identical order and work-order IDs, payload, key and payment reference. Replaying the WooCommerce payment event after synchronization sent no further request.

The integration uses the [WooCommerce block checkout lifecycle](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/how-checkout-processes-an-order/), [supported cart and checkout hooks](https://developer.woocommerce.com/docs/block-development/reference/hooks/hook-alternatives/), [HPOS order-storage APIs](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/) and [Action Scheduler APIs](https://actionscheduler.org/api/). Admin writes check WordPress capabilities and [request nonces](https://developer.wordpress.org/reference/functions/check_admin_referer/).
