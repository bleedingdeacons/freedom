=== Freedom ===
Contributors: thebleedingdeacons
Requires at least: 6.1
Tested up to: 7.1.1
Requires PHP: 8.4
Stable tag: 0.1.0
Build date: 2026/09/28 19:21:57
License: MIT (Modified)

Zero-configuration settings for MAUI apps. A tablet proves a Google account through Fellowship and receives its application's key/value configuration, secrets sealed to its own key. Requires Fellowship, Unity and Scrutiny.

== Description ==

Freedom is the WordPress half of a pair. The other half is **freedom-sharp**, a C# library an app uses to fetch its settings from here instead of having them built into the APK.

An admin creates an application, sets its values and marks the secret ones. A tablet signs in with a Google account — a Unity member's own, or one of the application's common tablet accounts — through Fellowship's existing Google sign-in, and sends a public key. It gets back a token.

On every start the tablet asks for a manifest: every key it should hold, each with a version. It fetches only what changed and deletes what was removed. Plain values come back as they are; secret values come back sealed to that tablet's own key, in the same envelope Fellowship uses for Link's messages.

One tablet can be given its own value for any key. Removing it hands the tablet the application's value again at its next start.

**What the server can and cannot read.** Freedom stores every value encrypted at rest, and can read them all — that is what lets an admin set them. A tablet that has been sent a secret knows it; revoking the tablet stops future values but does not take back past ones. Rotate the credential itself for that.

== Installation ==

1. Install and activate Unity, Scrutiny and Fellowship first. Freedom refuses to activate without them, and needs a Fellowship with the IdentityBroker (2.5.0 or later).
2. Activate Freedom. Its tables are created on activation, and repaired on load if a later version adds one.
3. Under **Freedom**, add an application. Its slug and callback URI must match what is built into the app.
4. Add the shared Google accounts the application's tablets sign in with, under **Common accounts**. Members can always sign in with their own.
5. Set the application's values under **Configuration**.

Nothing new is needed in Google's console: sign-in returns through Fellowship's existing callback.

== Frequently Asked Questions ==

= What happens when the site is unreachable? =

The tablet keeps the configuration it has. Only a refusal — a revoked or blocked tablet, or an account that may no longer use the application — clears it.

= Can I pause an application without taking anything away? =

Disable it. Its tablets keep what they hold and are refused anything new until it is enabled again.

== Changelog ==

= 0.1.0 =
* First release.
