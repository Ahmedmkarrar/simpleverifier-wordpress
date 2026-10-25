=== SimpleVerifier Email Validation ===
Contributors: simpleverifier
Tags: email validation, email verification, spam, woocommerce, contact form 7
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Check emails on sign-up, comment, WooCommerce and form submissions with your own SimpleVerifier API key. Fails open if the service is down.

== Description ==

This plugin checks the email address a visitor types into your forms and stops addresses that will bounce or that are throwaway, before they reach your list or your inbox. It uses the SimpleVerifier email verification API with **your own API key**.

**An API key from a paid SimpleVerifier plan is required.** API access comes with the Pro, Business and Agency plans. The Starter and Growth plans cover CSV verification in the dashboard only; a key on those plans is answered with a 402 and this plugin then lets every submission through unchecked. Each check uses one API call from your plan. Current prices and included calls are on the [SimpleVerifier API docs](https://simpleverifier.com/docs/api).

= Where it checks =

* WordPress registration form
* Comment form (visitors who are not logged in)
* WooCommerce checkout (classic and block checkout) and My Account registration
* Contact Form 7 email fields
* WPForms email fields
* Gravity Forms email fields

Each can be switched off on the settings page.

= What the three results mean =

* **Invalid**: the address will bounce (bad format, no mail server, mailbox not found) or is a disposable address. Blocked by default.
* **Risky**: delivery could not be confirmed, for example a catch-all domain, a role address such as info@, a provider that hides mailbox status, or a server that did not answer. Many real people land here, so the default is to allow it. You can instead warn once (the visitor submits again to continue) or block.
* **Valid**: the recipient's mail server confirmed the mailbox exists.

An unconfirmed address is never reported as valid.

= Safe by design =

* **Fails open.** A timeout, an API error, a rate limit, an exhausted or missing plan, or a wrong key all let the submission through. A real visitor is never turned away because of an outage or a billing problem. The settings page tells you when this is happening.
* **Short cache.** Results are cached for 60 minutes by default (1 to 1,440), so a repeated address does not use another API call.
* **Rate limits.** At most 60 checks a minute per site and 10 uncached checks per visitor address every ten minutes, so bots cannot drain your plan.
* **Block disposable addresses** with one switch.
* **Custom messages** for the invalid and risky cases, translatable text, and filters for developers.
* **No tracking, no ads, no account on your site.** The plugin phones home to nothing but the API call described below.

= External service =

This plugin sends email addresses to the SimpleVerifier API (https://simpleverifier.com/api/v1/verify) to check them. It is a third-party service operated by the plugin's authors.

* **What is sent:** only the email address, in the body of an HTTPS POST request, plus your API key in the Authorization header. No site address, visitor IP address, form content or any other data is sent.
* **When:** when a visitor submits an email address in a form you have switched on, and when you press Test connection (which sends hello@example.com). Nothing is sent before an API key is saved. Cached and rate-limited submissions send nothing.
* **Terms of service:** https://simpleverifier.com/terms
* **Privacy policy:** https://simpleverifier.com/privacy

The plugin adds suggested wording for your privacy policy under Settings > Privacy.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the Plugins screen, and activate it.
2. Create an API key at https://simpleverifier.com/api-keys (needs the Pro, Business or Agency plan).
3. Go to Settings > SimpleVerifier, paste the key, choose which forms to protect and what to do with invalid and risky addresses, and save.
4. Press Test connection to confirm the key works.

== Frequently Asked Questions ==

= Do I need a paid plan? =

Yes. API access comes with SimpleVerifier's Pro, Business and Agency plans. A key on the Starter or Growth plan gets a 402 reply and the plugin lets every submission through unchecked. The settings page says so when it happens.

= What if SimpleVerifier is down or my plan runs out? =

The form still works. The plugin fails open: every submission goes through unchecked until the service answers again.

= Why is a "risky" address allowed by default? =

Risky means delivery could not be confirmed, not that the address is fake. Catch-all domains and role addresses are common among real customers. Choose warn or block if you prefer.

= Does a slow mail server delay my form? =

A live mailbox check can take several seconds, and occasionally longer. The plugin gives up after 8 seconds by default (2 to 15) and lets the submission through. Repeats of the same address are served from the cache.

= Does it work with Fluent Forms, Elementor forms, Ninja Forms and others? =

Not yet. Developers can call `SVEV_Policy::validate( $email, 'cf7' )` from their own validation code; it returns an empty string to let the address through or a message to show.

= Does it store visitor data? =

Only the cached verdict for an address (a hash as the key, status and a short reason as the value, for the cache time), and a one-way hash of the visitor's IP address as a rate-limit key. Everything is removed when you delete the plugin.

= Can I change the messages? =

Yes, on the settings page, and with the `svev_rejection_message` filter. Use the `svev_should_validate` filter to skip specific addresses or forms.

== Changelog ==

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 0.1.0 =
First release.
