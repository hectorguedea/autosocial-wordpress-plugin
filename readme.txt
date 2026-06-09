=== AutoSocial Poster ===
Contributors:      hectorguedea
Tags:              woocommerce, facebook, instagram, social media, auto post, meta graph api
Requires at least: 6.0
Tested up to:      6.7
Requires PHP:      8.0
Stable tag:        1.0.0
License:           GPL-2.0+
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Automatically publish 2 WooCommerce products per day to a Facebook Page and Instagram Business account via the Meta Graph API.

== Description ==

AutoSocial Poster picks eligible WooCommerce products (published, in-stock, with a public HTTPS featured image) and publishes them to your Facebook Page and/or Instagram Business account twice a day using the Meta Graph API.

**Features**

* Posts 2 products per day at configurable times (follows WordPress timezone)
* Publishes to Facebook Page (photo post with caption)
* Publishes to Instagram Business (container → publish flow)
* Separate Hashtags field — auto-appended to every post
* Fully customizable caption template with placeholders:
  {product_title}, {short_description}, {price}, {product_url}, {sku}, {categories}, {tags}, {hashtags}
* UTM parameters automatically added to product URLs
* Posted-product history — never repeats a product until all have been posted; then cycles again
* Category include/exclude filters
* Admin log table (last 500 entries) with product, platform, status, and API response
* Token age tracker with automatic warning when a token is near expiry (Meta tokens last ~60 days)
* WP-Cron health card with ready-to-paste crontab commands for reliable scheduling
* "Post Now" button for immediate testing
* "Test Connection" buttons for Facebook and Instagram
* "Reset History" to make all products eligible again

**Requirements**

* WordPress 6.0 or higher
* WooCommerce 7.0 or higher
* PHP 8.0 or higher
* A Meta Developer App with the following permissions:
  - `pages_manage_posts`
  - `pages_read_engagement`
  - `instagram_basic`
  - `instagram_content_publish`
* A long-lived Facebook Page Access Token
* An Instagram Business or Creator account linked to the Facebook Page

== Installation ==

1. Upload the `autosocial-poster` folder to `/wp-content/plugins/`, or install via Plugins → Add New → Upload Plugin.
2. Activate the plugin through the Plugins menu.
3. Go to **WooCommerce → Auto Social Poster** (or **Settings → Auto Social Poster**).
4. Fill in your Facebook Page ID, Page Access Token, and Instagram Business Account ID.
5. Enable Facebook and/or Instagram, enable Auto-posting, and save.

== How to get your tokens ==

**Facebook Page Access Token (long-lived)**

1. Go to https://developers.facebook.com and create a Business app.
2. Add "Facebook Login" to the app.
3. Open Graph API Explorer → select your app → generate a User Access Token with:
   `pages_manage_posts`, `pages_read_engagement`
4. Exchange it for a long-lived token:
   GET https://graph.facebook.com/oauth/access_token
     ?grant_type=fb_exchange_token
     &client_id={app-id}
     &client_secret={app-secret}
     &fb_exchange_token={short-lived-token}
5. Then get the Page Access Token (permanent for Pages linked to your Business):
   GET https://graph.facebook.com/{page-id}?fields=access_token&access_token={long-lived-user-token}

**Instagram Business Account ID**

Your Instagram account must be a Business or Creator account linked to the Facebook Page.

GET https://graph.facebook.com/{page-id}?fields=instagram_business_account&access_token={page-token}

The `instagram_business_account.id` value is your Instagram Business Account ID.

**Token expiry**

Long-lived Page Access Tokens for Pages connected to a Business Manager are permanent (do not expire). Standard long-lived user tokens expire in ~60 days. The plugin tracks when you save each token and warns you 7 days before the 60-day mark.

== WP-Cron reliability ==

By default the plugin uses WordPress's built-in WP-Cron, which only fires when someone visits your site. On low-traffic stores, scheduled posts may be delayed.

**Recommended:** set up a real system crontab:

1. Add to wp-config.php:
   define( 'DISABLE_WP_CRON', true );

2. Add to your server's crontab (run `crontab -e` via SSH):
   */5 * * * * curl -s "https://yoursite.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1

The exact command (with your site URL) is shown in the **WP-Cron Health** section of the plugin settings.

== Frequently Asked Questions ==

= My image is not accepted by Instagram =

Instagram requires the product featured image to be:
- Publicly accessible (not behind a login or local server)
- Served over HTTPS
- A JPEG or PNG, at least 320×320 px, aspect ratio between 4:5 and 1.91:1

= The post fired but I don't see it on Facebook/Instagram =

Check **WooCommerce → Social Poster Logs** for the API response. Common issues:
- Token expired or lacks required permissions
- Page ID or Instagram Account ID is wrong
- Image URL is not publicly reachable

= Can I post more than 2 products per day? =

Version 1.0 supports exactly 2 scheduled posts per day (configurable times). This is a deliberate limit to comply with Meta's rate limits and to maintain organic-looking activity.

= What happens when all products have been posted? =

The posted-history resets automatically and the cycle starts again from the beginning.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release — no upgrade steps required.
