=== AutoSocial Poster ===
Contributors:      hectorguedea
Tags:              woocommerce, facebook, instagram, social media, auto post, meta graph api, social posting
Requires at least: 6.0
Tested up to:      6.7
Requires PHP:      8.0
Stable tag:        1.2.1
License:           GPL-2.0+
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Automatically publish your WooCommerce products to Facebook Page and Instagram Business via Meta Graph API — as many times per day as you want.

== Description ==

AutoSocial Poster picks eligible WooCommerce products (published, in-stock, with a public HTTPS featured image) and publishes them to your Facebook Page and/or Instagram Business account on a fully customizable schedule, using the Meta Graph API.

Tokens are stored encrypted at rest. No token is ever exposed in HTML or URLs.

**Features**

* Schedule as many daily post times as you want — add or remove slots freely (1 to 20 per day)
* Each time slot posts one product to all enabled platforms
* Publishes to Facebook Page (photo post with caption)
* Publishes to Instagram Business (container → publish flow)
* Separate Hashtags field — auto-appended to every post, or place `{hashtags}` manually in the template
* Fully customizable caption template with placeholders:
  `{product_title}`, `{description}`, `{short_description}`, `{price}`, `{product_url}`, `{sku}`, `{categories}`, `{tags}`, `{hashtags}`
  (`{description}` = main Product Description; `{short_description}` = WooCommerce excerpt)
* UTM parameters automatically added to product URLs
* Posted-product history — never repeats a product until all have been posted, then cycles again
* Category include/exclude filters
* Admin log table (last 500 entries) with product, platform, status, and API response
* Token age tracker — warns you at 53+ days so you never miss a renewal
* "Test Connection" buttons for Facebook and Instagram
* "Post Now" button for immediate testing
* "Reset History" to make all products eligible again
* **Step-by-step Setup Guide** built into the plugin — covers getting your Meta token, finding your Page ID, linking Instagram, and renewing tokens
* **Setup checklist** on the Settings page with real-time progress tracking
* Standalone top-level menu in the WordPress admin (not buried inside WooCommerce)
* WP-Cron health card with ready-to-paste crontab commands

**Security**

* Access tokens encrypted at rest with AES-256-CBC, keyed from WordPress auth salts
* Tokens sent via `Authorization: Bearer` header — never in URLs or HTML attributes
* Transient-based rate limiting on manual post actions
* Full data cleanup on plugin uninstall via `uninstall.php`

**Requirements**

* WordPress 6.0 or higher
* WooCommerce 7.0 or higher
* PHP 8.0 or higher
* A Meta Developer App with the following permissions:
  - `pages_manage_posts`
  - `pages_read_engagement`
  - `pages_show_list`
  - `instagram_basic` (only if posting to Instagram)
  - `instagram_content_publish` (only if posting to Instagram)
* A Facebook Page Access Token (Page tokens obtained from a long-lived User Token do not expire)
* An Instagram Business or Creator account linked to the Facebook Page (optional — only if posting to Instagram)

== Installation ==

1. Upload the `autosocial-poster` folder to `/wp-content/plugins/`, or install via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin — you will be redirected to the **Setup Guide** automatically.
3. Follow the Setup Guide to create a Meta App and obtain your Page Access Token.
4. Go to **AutoSocial Poster → Settings**, fill in your credentials, and click **Test Connection**.
5. Add your posting time slots, enable the platforms you want, turn on auto-posting, and save.

== Setup Guide ==

The plugin includes a full built-in setup guide at **AutoSocial Poster → Setup Guide**. It covers:

**Part 1 — Facebook**

1. Register as a Meta Developer at developers.facebook.com (free).
2. Create a Meta App (Business type).
3. Open **Graph API Explorer** → select your app → add permissions:
   `pages_manage_posts`, `pages_read_engagement`, `pages_show_list`, `instagram_basic`, `instagram_content_publish`
4. Click **Generate Access Token** and authorize the popup.
5. Switch from "User Token" to your Facebook Page in the token dropdown.
6. Extend via the **Access Token Debugger** → "Extend Access Token". Page tokens derived from long-lived User tokens often show **Expires: Never**.
7. Your Page ID is the `id` field returned in the Explorer response, or find it at your Facebook Page → About.
8. Enter the token and Page ID in **AutoSocial Poster → Settings → Facebook card**.

**Part 2 — Instagram (optional)**

1. Make sure your Instagram account is a Business or Creator account.
2. Connect it to your Facebook Page: Facebook Page → Settings → Instagram → Connect account.
3. In Graph API Explorer (with the Page token selected), run:
   `me?fields=instagram_business_account`
   The `id` inside `instagram_business_account` is your Instagram Business Account ID.
4. Enter it in **Settings → Instagram card**. The Facebook token is reused — no separate token needed.

**Token renewal**

Page Access Tokens obtained via Graph API Explorer from a long-lived User Token typically show **Expires: Never** in the Access Token Debugger — meaning no renewal is needed. If your token does expire, the plugin warns you at 53+ days. To renew, repeat steps 3–6 from Part 1 and paste the new token in Settings.

== WP-Cron reliability ==

By default the plugin uses WordPress's built-in WP-Cron, which only fires when someone visits your site. On low-traffic stores, scheduled posts may be delayed.

**Recommended:** set up a real system crontab.

1. Add to `wp-config.php`:
   `define( 'DISABLE_WP_CRON', true );`

2. Add to your server's crontab (`crontab -e` via SSH):
   `*/5 * * * * curl -s "https://yoursite.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1`

The exact command with your site URL is shown in the **WP-Cron Health** card inside Settings.

== Frequently Asked Questions ==

= How many posts per day can I schedule? =

As many as you want — there is no fixed limit in the plugin (up to 20 time slots). Each slot posts one product. Add or remove slots freely from the Schedule card in Settings.

= My image is not accepted by Instagram =

Instagram requires the product featured image to be:
- Publicly accessible over HTTPS (not on a local/dev server)
- A JPEG or PNG, at least 320×320 px
- Aspect ratio between 4:5 and 1.91:1

= The post fired but I don't see it on Facebook/Instagram =

Check **AutoSocial Poster → Logs** for the API response. Common causes:
- Token expired or lacking required permissions
- Incorrect Page ID or Instagram Business Account ID
- Image URL not publicly reachable (e.g. local dev environment)

= What happens when all products have been posted? =

The posted-history resets automatically and the rotation starts again from the beginning. You can also reset it manually with the "Reset Posted History" button in Settings.

= Is my access token stored securely? =

Yes. Tokens are encrypted with AES-256-CBC before being written to the database, using a key derived from your WordPress auth salts (defined in `wp-config.php`). They are never rendered in HTML form fields or passed as URL parameters.

= Do I need a separate token for Instagram? =

No. The plugin uses the same Facebook Page Access Token for both platforms. Just enter your Instagram Business Account ID and leave the Instagram token field blank.

== Changelog ==

= 1.2.1 =
* Fix: percent-encode non-ASCII characters in product image URLs (e.g. filenames with Unicode ellipsis `…`) before sending to Meta API — prevents HTTP 400 "Only photo or video" when Instagram cannot fetch the image.

= 1.2.0 =
* New: `{description}` placeholder maps to the main WooCommerce Product Description field (vs `{short_description}` which maps to the excerpt).
* New: default caption template updated to use `{description}` so products with no excerpt still get a caption body.
* New: other plugins' admin notices are suppressed on AutoSocial pages — cleaner UI.
* Fix: `wp_unslash()` added before sanitizing caption template and hashtags fields — prevents character corruption on save.
* Fix: default hashtags updated (removed personal tag, added genre/collector tags).

= 1.1.0 =
* New: standalone top-level menu — plugin no longer lives inside the WooCommerce menu.
* New: unlimited daily post slots — add or remove time slots freely (replaces fixed 2-post limit).
* New: full Setup Guide page built into the admin with step-by-step Meta token instructions.
* New: visual onboarding checklist with progress bar on the Settings page.
* New: inline help panels on each credential field ("How to find it" and "How to get a token").
* New: first-activation redirect to the Setup Guide.
* New: connection test results tracked — checklist updates when tests pass.
* Fix: CSS versioned via filemtime to prevent stale cache in development.
* Fix: token test flags reset when credentials change.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.0 =
Adds `{description}` placeholder for main product description, fixes hashtag saving, and cleans up the admin UI. Safe to update — no database changes.

= 1.1.0 =
Major UX update: standalone menu, unlimited post scheduling, and built-in setup guide. No database changes — safe to update.

= 1.0.0 =
Initial release — no upgrade steps required.
