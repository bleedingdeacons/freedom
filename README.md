# Freedom

[![CI](https://github.com/bleedingdeacons/freedom/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/freedom/actions/workflows/ci.yml)
[![Semgrep](https://github.com/bleedingdeacons/freedom/actions/workflows/semgrep.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/freedom/actions/workflows/semgrep.yml)
[![Coverage Status](https://coveralls.io/repos/github/bleedingdeacons/freedom/badge.svg?branch=main)](https://coveralls.io/github/bleedingdeacons/freedom?branch=main)
![PHPStan](https://img.shields.io/badge/dynamic/yaml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Ffreedom%2Fmain%2Fphpstan.neon.dist&query=%24.parameters.level&label=PHPStan&prefix=level%20&color=brightgreen)
![PHPCS](https://img.shields.io/badge/dynamic/xml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Ffreedom%2Fmain%2F.phpcs.xml.dist&query=%2Fruleset%2Frule%5B1%5D%2F%40ref&label=PHPCS&color=brightgreen)
![Version](https://img.shields.io/badge/version-0.2.2-blue)
![PHP](https://img.shields.io/badge/php-8.4%2B-777bb4)
![Licence](https://img.shields.io/badge/licence-MIT%20(Modified)-green)

The WordPress half of a zero-configuration arrangement for the suite's
MAUI apps. The other half is
[freedom-sharp](https://github.com/bleedingdeacons/freedom-sharp), a C#
library an app uses to fetch its settings from here instead of having
them built in.

Register used to ship with its SMTP password, Unity API key and Better
Stack token inside the APK, in an embedded `devsettings.json`. Anybody
holding a copy of the APK held the credentials, and changing any of them
took a new build and a reinstall on every tablet. With Freedom the app
has three things built in, none of them secret: this site's address, the
application's slug, and a callback URI. Everything else it asks for.

It is built on **Fellowship** for sign-in, on **Unity** for who is a
member, and on **Scrutiny** for audit logging.

## What it does

- An admin creates an **application** (`register`), sets its
  **values** (`smtp.host`, `smtp.password`, …) and marks the ones that
  are secret.
- A tablet signs in with a **Google account**. Fellowship runs the
  Google sign-in, exactly as it does for Link, and Freedom decides whether
  that account may use the application.
- The tablet sends a public key when it signs in and gets a bearer token
  back.
- On every start the tablet asks for the **manifest**: every key it should
  hold, each with a version. It fetches only the keys whose version
  differs from its own, and deletes any it holds that the manifest no
  longer lists.
- Plain values come back as JSON. **Secret values come back sealed to
  that tablet's key.**
- An admin can give one tablet its own value for a key, an **override**.

The app stores what it receives. Freedom is the source of it, not a
vault the app reads from each time; see freedom-sharp's README on what
the app is left holding.

## Who gets in

Two kinds of Google account, both always accepted and with no approval
step:

- **Any Unity member's own account.** Decided by Fellowship's
  `MemberGate`, so a member here is exactly who is a member for Link.
- **The application's common tablet accounts.** A register tablet at an
  intergroup meeting is nobody's phone. Signing it in with a member's own
  account would tie it to that member: their removal from Unity would
  revoke it mid-meeting, and their personal account would be on a device
  anybody picks up. So an application lists the shared accounts its
  tablets use, and a tablet signed in with one of them is admitted.

The check runs:

- **In the browser**, so a stranger is told where they can read it
  (`not_authorised`).
- **At enrolment**, where the tablet row is written.
- **On every request after that.** Removing a member from Unity, or taking
  an account off the list, stops the configuration at the tablet's next
  start rather than whenever somebody remembers to revoke the tablet.
  Putting the account back restores it without anybody signing in again.

Admins can still see, revoke, block and remove tablets.

## Why a tablet is not a Link device

Freedom keeps its own `freedom_tablets` table rather than adding rows to
Fellowship's `fellowship_devices`:

- A Link device belongs to a member. It counts against that member's cap
  of five, carries a push token, and is refused if its account is not a
  member's.
- A register tablet signed in with a common account is none of those
  things. Loosening Link's member gate to admit it would have loosened it
  for Link too.

## Two ways in

**Through the browser.**

1. `GET /freedom/v1/auth/start` checks the application, the callback URI
   and the app's PKCE challenge, then asks Fellowship's `IdentityBroker`
   to begin a Google sign-in for the `freedom` audience.
2. Google returns to Fellowship's callback, as it does for Link.
3. Fellowship asks Freedom whether the redirect is allowed (only this
   application's callback, or loopback if the application allows it) and
   whether this account may have a code.
4. The browser goes back to the app with a one-time code.
5. `POST /freedom/v1/auth/exchange` spends the code with the PKCE
   verifier.

The code is useless without the verifier. That matters because another
app on the tablet that registers the same URI scheme can catch the
redirect. Link has that exposure today; Freedom starts with it closed.

**An application can sign in with its own Google client.** By default
the sign-in uses Fellowship's Google client, so a Register tablet sees
the consent screen Fellowship's client shows for Link. An application
can carry its own Web application client from its own Google Cloud
project instead: **Freedom > the application > Details > Google sign-in
client**. Freedom implements Fellowship's `BringsOwnClient`, and
Fellowship uses that client for the authorization URL, the code
exchange and the ID-token check.

- The secret is write-only, and encrypted under its own key domain
  (`freedom-oauth-clients`), separate from the configuration values'.
- Clearing the client ID goes back to Fellowship's client.
- A secret that will not decrypt also falls back to Fellowship's client,
  and the log says why.
- The client must list Fellowship's callback as an authorized redirect
  URI. The Details tab shows the exact address.

**From a Link session.** Link has already signed in through Google, for
Fellowship. A second trip through the browser would prove nothing new and
would ask a member to sign in twice, so `POST /freedom/v1/auth/session`
takes Link's device token instead.

- It works only for an application that has **Accept a Link session**
  switched on.
- Fellowship re-runs its member gate on the token.
- The tablet is keyed on the Fellowship device, and **stops working as
  soon as that Link enrolment stops**: signed out, revoked, or its member
  removed. `CurrentTablet` asks Fellowship on every request.

The Google console is unchanged: Fellowship's HTTPS `/auth/callback`
remains the only registered redirect.

## Tablet identity, and re-attaching

- **How a tablet is identified.** A tablet is found by a keyed hash of the
  application's slug and the device's own identifier: Android's
  `ANDROID_ID` for a browser sign-in, `fellowship:<id>` for a Link
  hand-off. Neither is stored as sent.
- **Signing in again re-attaches.** A device that signs in again, after a
  reinstall or a revocation, finds its old row. It gets a new token, a new
  key and whichever account it signed in with this time, and **its
  overrides survive**.
- **Blocked tablets cannot re-attach.** A blocked tablet refuses instead.
- **The identifier is not proof, and is not treated as proof.** The app
  reports it and nothing can check it; the Google account is the proof. So
  anybody holding an authorised account can claim another tablet's
  identifier and take over its row, including its overrides. The real
  tablet's token then stops working.
  - The admin list shows when a tablet was re-attached, and the audit
    records it. Block is the answer if that is not what happened.
  - Play Integrity attestation would close this, and is not done.
- **`ANDROID_ID` is per signing key.** A debug build and a release build
  on the same tablet are two tablets. A factory reset makes a new tablet.

## Versions and overrides

Every write to any value in an application first takes the application's
next **revision**, `UPDATE … SET revision = LAST_INSERT_ID(revision + 1)`.
The value is stamped with that number, so **a version is never reused
within an application**. Everything else follows from that:

- **Tablets compare versions for difference, not order.** A key is stale
  when the version the tablet holds is not the manifest's.
- **The effective value is the tablet's override if it has one, otherwise
  the default.** The effective version is the version of whichever row
  won.
- **Removing an override uncovers the default.** The default's version
  differs from the override's, so the tablet fetches the default. No
  bookkeeping is needed.
- **Changing a default under an override changes nothing for that
  tablet.** The override still wins.
- **The manifest is complete, so absence means removal.** There are no
  tombstones.
- **Saving an unchanged value takes no version**, so pressing Save on a
  form nobody touched sends no tablet back for anything.

The manifest carries an ETag over the tablet, every key, every version and
every secret flag. A tablet that sends it back with nothing changed gets
a `304` and no body. That is what most starts cost.

**A failed read is a `500`, never an empty manifest.** A tablet deletes
whatever the manifest leaves out, so an empty answer built from a query
that failed would wipe every tablet that asked while the database was
unwell. `$wpdb` reports errors by returning an empty result, so the
repository checks `last_error` and throws.

## The encryption, stated precisely

| Where | How |
| --- | --- |
| At rest | Every value, secret or not, is encrypted with AES-256-GCM under a key derived from `wp_salt('auth')`, in the `freedom-values` domain. A database dump alone does not open them; this site always can. |
| On the wire | HTTPS only. Every route refuses plain HTTP unless `FREEDOM_ALLOW_INSECURE_TRANSPORT` is defined, which is for a local development site. |
| Secret values | Sealed per value to the tablet's RSA-2048 public key, in **Fellowship's envelope, unchanged**: a fresh AES-256-GCM content key, gzip underneath, the key wrapped with RSA-OAEP. Fields `k` and `p`, with `p` laid out as nonce(12) \| tag(16) \| ciphertext. The key and version travel *inside* the seal, so one sealed value cannot be moved into another key's slot. |
| On the tablet | Wherever the app stores them. freedom-sharp's default is `SecureStorage`. |

**OAEP is SHA-1, on both ends, deliberately.** PHP's
`OPENSSL_PKCS1_OAEP_PADDING` offers nothing else. A C# side switched to
`OaepSHA256` gets values that arrive and silently will not open. The
contract test is two-sided, as it is for Link: `tests/ConfigApiTest.php`
opens the envelope in PHP the way freedom-sharp does, and freedom-sharp's
`EnvelopeOpenerTests` build one the way this does.

**What this does not protect.**

- **The server can read every value.** That is what lets an admin set
  them.
- **A tablet that holds a secret knows it.** Revoking the tablet stops
  future values; it does not take back what it had. Rotate the upstream
  credential instead, which Freedom makes a one-field edit.

**Rotating `wp_salt('auth')` breaks everything at once.** Every stored
value becomes unreadable and every tablet token stops matching. Values
that no longer decrypt are reported to tablets as `unreadable`, never
served as empty, and show as "Unreadable — re-enter it" in the admin.

## Revoke, block, remove, disable

| | The tablet's token | Signing in again | Its overrides |
| --- | --- | --- | --- |
| Revoke | dead | allowed; re-attaches | kept |
| Block | dead | refused | kept |
| Remove | dead | a new tablet | deleted |
| Disable the application | refused with `application_disabled` | refused | kept |

Disabling is a stand-down. The library *keeps* configuration on
`application_disabled` and clears it on `not_authorised`, `tablet_blocked`
or a `401`. So disabling pauses every tablet without taking anything
away, and revoking, blocking or removing the account is how access is
taken away.

## REST API

Everything is under `freedom/v1`, requires HTTPS, and is sent
`Cache-Control: no-store`.

| Route | Auth | What |
| --- | --- | --- |
| `GET /auth/start?application=&redirect_uri=&code_challenge=[&provider=]` | none | Begin a browser sign-in. Answers `{state, authorization_url}`. |
| `POST /auth/exchange` | code + verifier | `{application, code, code_verifier, device_id, public_key, label?, platform?, model?, app_version?}`. `201` new tablet, `200` re-attached. Answers `{token, tablet:{id, label, created_at, reattached}, application:{slug, name}}`. |
| `POST /auth/session` | Link device token | `{application, public_key, label?, platform?, model?, app_version?}`. The same answer, with no browser. |
| `GET /config/manifest[?etag=]` | tablet | `{application, tablet, etag, checked_at, keys:[{key, version, secret}]}` and an `ETag` header; `304` when `If-None-Match` or `?etag=` names it. The query form exists because SiteGround's proxy drops `If-None-Match` before WordPress sees it. |
| `POST /config/values` | tablet | `{keys:[…]}`, at most 100. Answers `{values:[{key, version, secret:false, value} \| {key, version, secret:true, k, p}], missing:[…], unreadable:[…]}`. |
| `POST /tablet/key-fault` | tablet | "I was sent a secret I cannot open." Shown in red on the admin list. |
| `DELETE /tablet` | tablet | Sign out (self-revoke). |

Refusals the library tells apart:

| Code | Status | The app |
| --- | --- | --- |
| `freedom_unauthenticated` | 401 | Signs in again; clears what it holds. |
| `freedom_not_authorised`, `freedom_tablet_blocked` | 403 | Clears what it holds. |
| `freedom_application_disabled` | 403 | Keeps what it holds. |
| `freedom_unavailable`, anything 5xx, 429, no network | — | Keeps what it holds. |

Rate limits use Fellowship's limiter:

- Sign-in: 60 per 15 minutes per address. A room of tablets enrols behind
  one NAT.
- Link hand-offs: 30 per 15 minutes per Link device.
- Manifests: 120 an hour per tablet.
- Values: 60 an hour per tablet.

## Audit

Through Scrutiny:

- **A tablet signed in with a member's own account** is logged against
  that member, as a Link handset is.
- **A tablet signed in with a common account** is logged against the
  tablet (`freedom_tablet`).
- **Adding or removing a common account** is logged against the account
  (`freedom_account`).
- **Addresses never appear** in an audit line.
- **Value edits** are not personal data and go to Freedom's own log as
  key, version and who. The value itself is never logged anywhere.

When Unity deletes a member, every tablet that member's own account
enrolled is revoked. Tablets enrolled with a common account are left
alone, which is one more reason to enrol shared tablets that way.

## The Fellowship surface this depends on

Must agree. A change to any of these in Fellowship is a change to Freedom:

- `Fellowship\Auth\IdentityBroker`: `registerAudience`, `begin`, `redeem`,
  `sessionFor`, `isLive`
- `Fellowship\Auth\SignInAudience`, `BrokeredIdentity`, `LinkSession`,
  `VerifiedIdentity`
- `Fellowship\Auth\BringsOwnClient` and `ProviderClient`, for an
  application's own Google client (Fellowship 2.8.0)
- `Fellowship\Devices\MemberGate`
- `Fellowship\Crypto\MessageSealer`, `DevicePublicKey` (the envelope)
- `Fellowship\Core\RateLimiter`
- the `fellowship/loaded` action, which Freedom boots on

Freedom checks for `IdentityBroker` and `BringsOwnClient` when it boots.
If either is missing it says which Fellowship version it needs, 2.8.0
(`Requires Plugins` cannot check versions).

## Conventions

- `declare(strict_types=1)` and a namespaced `Freedom\` PSR-4 autoloader.
- A `FREEDOM_KILL` kill switch. Tablets keep what they have while it is
  set, because a poll that cannot reach the server changes nothing.
- A `freedom/loaded` action.
- It registers into Unity's container on `fellowship/loaded` and has no
  container of its own.
- Its tables are created on activation and repaired on load when
  `freedom_schema_version` is behind.

## Development

```bash
composer install
OPENSSL_CONF=C:/tools/php85/extras/ssl/openssl.cnf composer test
composer phpstan
composer phpcs
composer build:production
```

- **Checkout layout.** The tests and PHPStan need `unity`, `scrutiny`,
  `sentinel` and **`fellowship`** checked out alongside. The tests
  autoload Fellowship's `src/`, and its `tests/Support`, for
  `InMemoryDeviceRepository`. `FELLOWSHIP_PATH`, `UNITY_PATH` and
  `SCRUTINY_PATH` override the locations.
- **The seam is tested for real.** Fellowship's broker, code store,
  member gate and sealer are real classes in the tests
  (`tests/Support/FreedomWorld.php`), so a passing test means Fellowship
  would actually issue, carry and redeem what Freedom expects.
- **Set `OPENSSL_CONF` on Windows.** Without it, the RSA tests skip
  silently.
