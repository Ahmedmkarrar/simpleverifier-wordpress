# SimpleVerifier Email Validation for WordPress

WordPress plugin that checks email addresses on sign-up, comments, WooCommerce checkout and form-plugin submissions (Contact Form 7 and others) with your own [SimpleVerifier](https://simpleverifier.com) API key. It fails open: if the service can't be reached, submissions go through.

- Needs an API key from a Pro, Business or Agency plan (https://simpleverifier.com/api-keys). Each check uses one API call.
- Requires WordPress 5.8+ and PHP 7.4+.

Full description, FAQ and changelog: [`readme.txt`](readme.txt).

## Install

Download this repo as a zip (or clone it into `wp-content/plugins/simpleverifier-email-validation`), then activate **SimpleVerifier Email Validation** and paste your key under **Settings > SimpleVerifier**.

`php tests/smoke.php` runs the policy checks against stubs.

## License

GPLv2 or later, as WordPress plugins require. See [LICENSE](LICENSE).
