=== OGOship for WooCommerce ===
Contributors: ogoship
Tags: ogoship, fulfillment, 3PL, logistics, shipment tracking
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Companion plugin for the OGOship fulfillment service. Adds OGOship product fields and shows shipment tracking to your customers.

== Description ==

OGOship is a third-party logistics service that stores and ships your products for you.

OGOship connects to your shop through the WooCommerce REST API, so orders, stock levels and fulfillment flow between the two without a plugin. This plugin adds the two things that connection cannot provide on its own.

**OGOship product fields**

A dedicated OGOship tab on the product screen for the warehouse and customs information WooCommerce does not have a field for:

* EAN code, used by the warehouse to identify the item when picking
* Supplier name and supplier code
* Purchase price, for stock valuation
* Product group, for warehouse reporting
* Customs description, country of origin and HS code, for customs declarations on shipments outside the EU
* A "do not send to OGOship" flag for digital goods and services

The same fields are available on individual variations. Leave a variation field blank and it inherits the parent product's value, so you only fill in what genuinely differs — a different EAN per size, for example.

**Shipment tracking for your customers**

Once OGOship ships an order, the tracking number, tracking link and delivery status appear:

* on the order page in My Account
* on the order confirmation page
* in your WooCommerce order emails, both the HTML and the plain-text versions

**For your OGOship support team**

A read-only panel at WooCommerce → Status → OGOship shows whether OGOship is actually reaching your store, when it last did, and which order was most recently given tracking. It stores no credentials.

== Upgrading from "Ogoship API for WooCommerce" ==

This plugin replaces the older *Ogoship API for WooCommerce* (version 3.x), which sent orders to OGOship from your shop. That is no longer how OGOship works: OGOship now reads orders from your store directly.

Your existing product data carries across untouched — both plugins store it in exactly the same place, so there is nothing to migrate and nothing to re-enter.

Install this plugin, then deactivate the old one. While both are active this plugin stays out of the way to avoid showing you two copies of every field, and offers a button to deactivate the old plugin for you.

== Frequently Asked Questions ==

= Do I need this plugin to use OGOship? =

No. OGOship works without it. Install it if you want to enter EAN codes, customs data and supplier details from WooCommerce, or to show your customers their tracking.

= Does it send my orders to OGOship? =

No. OGOship reads orders from your store over the WooCommerce REST API. This plugin never sends anything to OGOship and stores no OGOship credentials.

= Where does the tracking information come from? =

OGOship writes it onto the order when the shipment leaves the warehouse. This plugin only displays it.

= My customers cannot see any tracking. =

Check WooCommerce → Status → OGOship. If the panel shows no recent API activity, OGOship is not reaching your store, and the REST API key may have been revoked under WooCommerce → Settings → Advanced → REST API. If it shows activity but no tracking, the order has not shipped yet.

= Does it work with High-Performance Order Storage? =

Yes, with HPOS on or off, and with both the classic and block-based checkout.

== Screenshots ==

1. The OGOship tab on the product edit screen.
2. The connection status panel at WooCommerce → Status → OGOship.
3. Tracking as the customer sees it on the order page.

== Changelog ==

= 1.0.0 =
* First release of the rewritten plugin.
* Added OGOship fields to individual product variations, inheriting from the parent when left blank.
* Fixed: tracking added by OGOship did not appear on the My Account order page. The previous plugin only recognised tracking it had fetched itself, so shops using the current OGOship integration showed customers nothing.
* Fixed: the tracking link was missing from plain-text order emails.
* Added a tracking column and panel to the admin orders screens.
* Added the WooCommerce → Status → OGOship connection panel.
* Removed order sending, stock updating and product export. OGOship does all of this directly now.

== Upgrade Notice ==

= 1.0.0 =
Replaces "Ogoship API for WooCommerce" 3.x. Your product data carries across untouched. Install this, then deactivate the old plugin.
