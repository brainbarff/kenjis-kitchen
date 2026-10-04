# Kenji's Kitchen React and Firebase apps

The legacy PHP/MySQL POS remains in its existing folders. The React workspace is additive and shares Firebase project `kenji-s-kitchen-otp`.

## Layout

```text
apps/
  ordering/                 Customer menu, cart, order history, auth entry point
  admin/                    Staff dashboard and fulfillment entry point
packages/shared/src/        Firebase initialization, OTP/auth modal, shared styles
functions/                  Trusted checkout callable
public-ordering/             Ordering build output (generated)
public-admin/                Admin build output (generated)
firestore.rules              Customer/admin access rules
tools/set-admin.js           Trusted custom-claim provisioning utility
```

## Configure Firebase

1. In Firebase Console for `kenji-s-kitchen-otp`, register a Web App and put its public web configuration values in a root `.env` file based on `.env.firebase.example`. The web API key and App Check site key are public identifiers; never put service-account credentials or SMTP secrets in `VITE_` variables.
2. Enable Email/Password and Email link (passwordless) in Authentication. If using SMS registration, enable Phone, configure reCAPTCHA and SMS regions/billing as required by Firebase, and add both production domains and local development hosts under Authentication authorized domains. Email verification uses a sign-in link because Firebase Auth does not issue numeric email OTPs natively.
3. Enable Firestore and deploy `firestore.rules`. Menu documents belong in `menuItems/{id}` and need `name`, numeric `price`, boolean `active`; optional `description`, `category`, numeric `stock`, and `variants: [{"name":"XL","price":600}]` are supported. Variant prices are selected in the customer menu and revalidated by the checkout function; stock is tracked against the shared menu item.
4. Set up Firebase App Check with reCAPTCHA v3 and put the site key into `VITE_FIREBASE_APPCHECK_SITE_KEY`. The checkout callable enforces App Check. For local development, register a debug token in the Firebase Console, use it only on a trusted developer machine, and never commit it.
5. Provision the first staff administrator from a trusted machine. Install `firebase-admin` in `functions`, set `GOOGLE_APPLICATION_CREDENTIALS` to a protected service-account file with project IAM access, then run `node tools/set-admin.js <auth-uid>`. The admin app and Firestore rules require the `admin: true` custom claim. A profile field alone cannot grant admin access.

The shared auth modal offers standard email/password sign-in. Registration requires either an email sign-in verification link followed by setting a password, or an SMS code followed by linking an email/password credential to that verified phone account. Firebase's web SDK cannot send a numeric email code itself.

## Local use and deploy

Install root dependencies with `npm install`; install Functions dependencies with `npm --prefix functions install`.

```sh
npm run dev:ordering
npm run dev:admin
npm run build:apps
```

Hosting target names must be bound to actual Firebase Hosting site IDs for this project; site IDs are intentionally not guessed:

```sh
firebase target:apply hosting ordering <ordering-site-id> --project kenji-s-kitchen-otp
firebase target:apply hosting admin <admin-site-id> --project kenji-s-kitchen-otp
```

Add `order.kenjiskitchen.com` as the custom domain for the ordering site and `admin.kenjiskitchen.com` for the admin site in Firebase Hosting. Then run:

```sh
npm run build:apps
firebase deploy --only hosting:ordering,hosting:admin,firestore:rules,functions --project kenji-s-kitchen-otp
```

`firebase.json` maps each target to its own generated public directory and supplies SPA rewrites. The admin client is only a usability gate; Firestore security rules and the callable's server checks are the enforcement boundary.

## Current scope

The ordering app includes live menu loading, size/options pricing, cart, callable checkout, and order status history. The admin app includes claim-gated access, live orders/menu, menu and stock editing, ordering availability, and order status progression. Payment provider integration, delivery configuration, and receipt printing need business-specific fields/provider credentials and are not enabled by this starter workspace. Orders currently use the Firebase callable and do not synchronize with the existing MySQL POS automatically.
