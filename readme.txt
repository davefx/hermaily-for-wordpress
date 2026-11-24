=== Hermaily ===
Contributors: davefx
Tags: email, smtp, wp mail, transactional email, deliverability
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Send every WordPress email through Hermaily, an email service hosted in the European Union. Uses its HTTPS API, so no SMTP ports are needed.

== Description ==

Hermaily replaces the way WordPress sends email. Instead of PHP's `mail()` or an SMTP connection, every message that goes through `wp_mail()` (password resets, WooCommerce orders, contact forms) is sent with the [Hermaily](https://hermaily.com) API over HTTPS.

Many hosts block the SMTP ports (587 and 465). Port 443, the one HTTPS uses, is never blocked, so this works on any host.

= Features =

* **One setting to start:** paste your API key and email goes out through Hermaily. The plugin proposes a sender on the key's domain and tells you when something will stop email from going out: an unverified domain, an account pending approval or paused, or a key meant only for newsletters.
* **Key in wp-config.php** if you prefer: `define( 'HERMAILY_API_KEY', 'hm_…' );`. The field in the settings is then locked.
* **Default sender**, name and email, for messages that do not say who they are from.
* **Force sender** for plugins that send "from" someone else, such as contact forms that use the visitor's address. The original sender becomes the Reply-To, so your replies still reach them.
* **Test email** button that shows Hermaily's own explanation when something is wrong.
* **Log** of what was sent: date, recipients, subject and the real status (delivered, bounced, marked as spam…), with every event Hermaily recorded. Email content is never stored. You choose how many days it is kept, or turn it off.
* Attachments, CC, BCC, Reply-To, HTML and custom headers work as they do with `wp_mail()`, and the core `wp_mail_from`, `wp_mail_from_name`, `wp_mail_content_type`, `wp_mail_succeeded` and `wp_mail_failed` hooks keep working.

No templates, no campaigns, nothing else to configure.

= For developers =

The `hermaily_payload` filter receives the request body before it is sent, and the `wp_mail()` arguments, so you can add a `tag` or `metadata` to group messages in Hermaily's statistics and webhooks:

`add_filter( 'hermaily_payload', function ( $payload ) { $payload['tag'] = 'shop'; return $payload; } );`

Messages whose body is already a full multipart MIME message (a `Content-Type` header with its own `boundary`) are left to WordPress.

== Installation ==

1. Install and activate the plugin.
2. In your [Hermaily dashboard](https://app.hermaily.com/api-keys), create an API key for your verified domain.
3. Go to **Settings → Hermaily**, paste the key and save.
4. Send yourself the test email from the same page.

== Frequently Asked Questions ==

= Do I need a Hermaily account? =

Yes. The plugin sends through your Hermaily account, with the limits of your plan. See [hermaily.com](https://hermaily.com).

= Hermaily refuses my messages with "forbidden" =

Each API key belongs to one domain, and the sender must be on that domain or one of its subdomains. Set a default sender on that domain, and turn on "Force sender" if some plugin sends from other addresses.

= What happens if I remove the key? =

WordPress goes back to sending email by itself.

= Is the content of my emails stored? =

Not by this plugin. The log keeps the date, recipients, subject, Hermaily's message id and status. Hermaily keeps messages for your plan's history period; see its privacy policy.

== External services ==

= Hermaily =

This plugin sends your site's email through the Hermaily API (https://api.hermaily.com), provided by Hermaily. It is the whole purpose of the plugin, and it only happens once you have entered an API key.

* **Every time WordPress sends an email** (`wp_mail()`), the plugin sends Hermaily the API key, the sender, recipients (To, CC, BCC, Reply-To), subject, body, extra headers and attachments of that message.
* **When an administrator opens the plugin's settings page**, at most once a day, it sends the API key to ask which domain the key belongs to and whether that domain is verified.
* **When an administrator opens a message in the plugin's log, or refreshes the log**, it sends the API key and the Hermaily ids of those messages to ask for their current status and events.

Hermaily's terms of service: https://app.hermaily.com/legal/terms
Hermaily's privacy policy: https://hermaily.com/en/privacy/

= Author's plugin catalog (davefx.com) =

The "More plugins" tab of the settings page lists the author's other plugins. When an administrator opens that tab, the plugin downloads the list from https://davefx.com/plugins.json, at most once a week. Nothing about the site or its users is sent: the request identifies itself only as DFX-Plugin-Showcase and its version, although, as with any web request, davefx.com's server receives the IP address of the server making it. If the file cannot be reached, a copy bundled with the plugin is shown.

* Privacy Policy: https://davefx.com/en/privacy-policy/

== Changelog ==

= 1.0.0 =
* First release: wp_mail() through the Hermaily API, default and forced sender, key in wp-config.php, test email and log with the real delivery status.
