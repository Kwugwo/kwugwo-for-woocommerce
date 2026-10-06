=== Kwugwo for WooCommerce ===
Contributors: kwugwo
Tags: woocommerce, payments, nigeria, bank transfer, ussd
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept bank transfer, USSD and pay-with-bank payments in Nigeria, routed through the payment providers you already use.

== Description ==

Kwugwo for WooCommerce adds Kwugwo as a payment option on your store. Kwugwo is a payment orchestration service for Nigerian businesses: you connect the payment providers you already have accounts with, and Kwugwo routes each payment to the right one. Money settles straight into your own provider account; Kwugwo never holds it.

When a customer chooses Kwugwo at checkout, a secure Kwugwo window opens on your store where they can pay by bank transfer, USSD or with their bank app. Their bank details are entered in Kwugwo's window, never on your site.

= Features =

* **Secure payment window**: customers stay on your store while they pay.
* **Bank transfer, USSD and pay with bank**: offer the methods your providers support, chosen in your Kwugwo dashboard.
* **Guided setup**: a checklist and step-by-step guide on the settings page, a "Test connection" button that checks your keys, and a list of your Kwugwo checkouts to pick from, with no IDs to type.
* **Sandbox and Live**: try everything with test payments, then switch to real payments with one setting.
* **Orders update by themselves**: orders are marked paid when Kwugwo confirms the payment, even if the customer closes their browser. A background check every 15 minutes catches any update that was missed.
* **Safe by design**: every payment is confirmed with Kwugwo by your server and checked against the order amount, currency and reference before the order is marked paid.
* **Refunds from WooCommerce**: refund all or part of an order from the order screen.
* **Classic and block checkout**: works with both the classic checkout and the Cart & Checkout blocks, and with High-Performance Order Storage.

= Requirements =

* WooCommerce, with the store currency set to Nigerian Naira (NGN).
* A Kwugwo account with at least one payment provider connected. Sign up at [kwugwo.africa](https://kwugwo.africa).

== Installation ==

1. In your WordPress admin, go to **Plugins → Add New**, search for "Kwugwo for WooCommerce", then install and activate it.
2. Go to **WooCommerce → Settings → Payments → Kwugwo**. The setup checklist at the top shows what is left to do.
3. Follow the four steps of the setup guide on that page:
   1. Get your Kwugwo account ready: connect your payment provider and create a checkout.
   2. Copy your API keys (public and secret) from the API Keys page of your Kwugwo dashboard, then click **Test connection**.
   3. Copy the **Webhook URL** shown on the settings page into the Webhooks page of your Kwugwo dashboard, then paste the signing secret back into the plugin.
   4. Place a test order in Sandbox mode, then set **Mode** to **Live** and add your Live keys and webhook secret.
4. Tick **Turn on Kwugwo** and save.

== Frequently Asked Questions ==

= Which currencies are supported? =

Nigerian Naira (NGN) only, from ₦200 to ₦50,000,000 per order. Kwugwo is hidden at checkout when your store uses another currency.

= Why is Kwugwo not showing at checkout? =

Open the Kwugwo settings and look at the setup checklist. Kwugwo is only shown when it is turned on, the store currency is NGN, and both keys are saved for the selected mode.

= What is the webhook, and do I need it? =

The webhook is how Kwugwo tells your store that a customer has paid. You should set it up: without it, an order only updates when the customer returns to your store or when the background check runs. Add the Webhook URL from the settings page in your Kwugwo dashboard, once for Sandbox and once for Live, and paste each signing secret into the plugin.

= Which webhook events should I choose? =

* `ugwo.successful` (required): marks the order paid.
* `ugwo.updated`: updates the order when a payment is cancelled or refunded.
* `ugwo.activity.updated`: puts the order on hold if the payment provider reverses a payment.
* `ugwo.activity.double_charge_detected`: warns you when a customer pays twice.
* `refund.successful` and `refund.failed`: record refund results on the order.

Sending other events as well does no harm.

= An order is still "Pending payment" but the customer says they paid. =

Bank transfers and USSD can take a few minutes. Open the order and click **Check payment status now** in the Kwugwo payment box. If the payment matches the order, the order moves to Processing.

= What does "Checkout (optional)" do? =

A checkout is a set of payment methods and routing rules you create in your Kwugwo dashboard. Leave it on Automatic to let your routing rules choose, or pick one so that every payment from this store uses it.

= A refund failed and asks for the customer's bank account. =

Some providers cannot send money back to a bank transfer or USSD payer on their own. Enter the customer's bank and 10-digit account number in the Kwugwo payment box on the order, click **Update**, then refund again.

= My site is behind a firewall. =

Allow requests from Kwugwo's address `176.97.192.227` so webhooks can reach your store.

== External services ==

This plugin connects to Kwugwo, a payment orchestration service run by Kwugwo, to take payments. It cannot work without it.

**Kwugwo API** (`https://api.kwugwo.africa` for Live, `https://sandbox-api.kwugwo.africa` for Sandbox). Your server sends requests using your secret key:

* When a customer places an order with Kwugwo: the order amount and currency, the order number and a store reference, a short description containing the order number and store name, and the customer's billing email address. For a new Kwugwo customer, the billing first and last name are also sent.
* When a payment is checked (after a webhook, when the customer returns to your store, from the background check every 15 minutes for unpaid orders, or when you click "Check payment status now"): the Kwugwo payment ID.
* When an unpaid order is cancelled: the Kwugwo payment ID, to cancel the payment request.
* When you refund an order: the Kwugwo payment attempt ID, refund amount, reason, a reference and, if you entered one, the customer's bank and account number.
* When you save keys or click "Test connection" on the settings page: a request listing your Kwugwo checkouts.

**Kwugwo checkout** (`https://checkout.kwugwo.africa`). When the customer reaches the payment page, the plugin opens Kwugwo's secure payment window in a frame. The window receives your public key, the Kwugwo payment ID and your store's web address. The customer enters their payment details there.

**Webhooks**: Kwugwo sends payment updates to your store's webhook URL. No data is sent from your store when receiving them.

The service is provided by Kwugwo: [Terms of service](https://kwugwo.africa/terms), [Privacy policy](https://kwugwo.africa/privacy).

== Privacy ==

The plugin adds suggested text to **Settings → Privacy → Policy Guide** describing the data shared with Kwugwo. It does not track visitors and sends nothing to Kwugwo until a customer chooses Kwugwo at checkout or you configure the plugin.

== Source code ==

* Plugin source: [github.com/Kwugwo/kwugwo-for-woocommerce](https://github.com/Kwugwo/kwugwo-for-woocommerce)
* `assets/js/kwugwo-checkout.js` is the unminified browser build of the official Kwugwo checkout SDK, [@kwugwo/checkout](https://www.npmjs.com/package/@kwugwo/checkout) 0.1.3 (MIT licence). Its source is at [github.com/Kwugwo/checkout-js](https://github.com/Kwugwo/checkout-js). To rebuild it, clone that repository, check out version 0.1.3, then run `npm ci` and `BUILD_VARIANT=dev npx tsup`; the file is `dist/kwugwo-checkout.global.js`.
* All other JavaScript and CSS in the plugin is hand-written and not compiled.

== Screenshots ==

1. The secure Kwugwo payment window on the order payment page.
2. Kwugwo in the WooCommerce payment methods list.
3. The Kwugwo settings page with the setup checklist and guide.

== Changelog ==

= 1.0.0 =
* First release.
