# Kenji's Kitchen

Kenji's Kitchen is a restaurant ordering and management project with two connected but currently separate application workspaces:

- **Legacy PHP/MySQL system** for staff login, POS, kitchen, inventory, and related modules.
- **React/Firebase workspace** for customer online ordering and the staff admin/fulfillment app.

The React/Firebase ordering app does not currently synchronize orders with the PHP/MySQL POS. Payment provider processing, delivery configuration, and receipt printing are not enabled in the Firebase workspace.

## Technology

| Area | Stack |
| --- | --- |
| Legacy staff system | PHP, MySQL, PDO, HTML/CSS/JavaScript |
| Realtime/API service | Node.js 18+, MySQL, WebSocket |
| Ordering and admin apps | React 18, Vite, Firebase Authentication, Firestore, Cloud Functions |
| Local legacy development | XAMPP (Apache and MySQL) |

## Repository layout

```text
apps/ordering/       React customer storefront
apps/admin/          React staff dashboard and fulfillment app
packages/shared/     Shared Firebase setup, authentication UI, and styles
functions/           Trusted Firebase checkout callable
public-ordering/     Generated ordering build output
public-admin/        Generated admin build output
modules/             Legacy PHP modules, including POS and kitchen workflows
config/              Legacy PHP configuration
database/            MySQL schemas, seeds, and migrations
server.js            Node REST API, OTP email, and realtime WebSocket service
firestore.rules      Firestore authorization rules
tools/set-admin.js   Trusted staff-admin custom-claim utility
```

## Requirements

- PHP and MySQL through XAMPP for the legacy system
- Node.js 20 recommended (the Firebase Functions runtime is Node 20; the root server requires Node 18+)
- npm
- A Firebase project configured for the React apps if using Firebase features

## Run the legacy PHP/MySQL system

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Create/import the database using `database/kenjis_kitchen.sql` in phpMyAdmin. For a populated development database, use `database/kenjis_kitchen_full_seed.sql` instead. Import only one schema into a fresh database.
3. Set database credentials as environment variables (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`) or use the project's PHP configuration defaults (`127.0.0.1`, `kenjis_kitchen`, `root`, blank password).
4. Open `http://localhost/kenjis-kitchen/login.php` (adjust the URL if the project folder has another name).

Legacy online ordering page, where still used:

```text
http://localhost/kenjis-kitchen/modules/online/index.html
```

Use the demo accounts and password supplied by the imported SQL seed. The seeds may differ; check `database/seed_users.php` or your selected SQL file rather than assuming credentials from an older README.

## Run the Node API and realtime service

Copy `.env.example` to `.env` and set the MySQL and SMTP values needed by your local setup. Keep `.env` private and never commit credentials. Then run:

```sh
npm install
npm start
```

The service defaults to port `8787` and provides the REST API, WebSocket updates, and OTP email endpoints. Email delivery requires valid SMTP credentials. For local setup details, see [SETUP_INSTRUCTIONS.md](SETUP_INSTRUCTIONS.md).

## Run the React/Firebase apps

1. Copy `.env.firebase.example` to `.env` and fill in the Firebase Web App values. These `VITE_` values are public client configuration; do not put service-account keys, SMTP passwords, or other secrets in them.
2. Configure Firebase Authentication providers, Firestore, and App Check for the project. See [FIREBASE_REACT_WORKSPACE.md](FIREBASE_REACT_WORKSPACE.md) for the detailed checklist and admin provisioning instructions.
3. Install dependencies:

   ```sh
   npm install
   npm --prefix functions install
   ```

4. Start either app during development:

   ```sh
   npm run dev:ordering
   npm run dev:admin
   ```

5. Build both apps for hosting:

   ```sh
   npm run build:apps
   ```

Deploying Hosting, Firestore rules, or Functions requires access to the configured Firebase project. Hosting targets are configured in `.firebaserc`; verify the target site IDs and project before deployment.

## Firebase workspace scope

The ordering app supports live menu loading, item variants/options, cart, callable checkout, and order status history. The admin app supports custom-claim gated access, live orders and menu, menu/stock editing, ordering availability, and status progression. Checkout is validated by the trusted Cloud Function and Firestore rules. Orders currently remain in Firebase and are not copied into MySQL automatically.

Firebase Authentication supports email/password sign-in. Registration/verification options and their setup requirements are documented in [FIREBASE_REACT_WORKSPACE.md](FIREBASE_REACT_WORKSPACE.md). The first staff administrator must be provisioned from a trusted machine with the `admin: true` custom claim; a client profile field does not grant admin access.

## Database files

- `database/kenjis_kitchen.sql` — base schema and starter data
- `database/kenjis_kitchen_full_seed.sql` — development schema/data seed
- Other `database/*.sql` files — focused migrations or feature updates; apply only when the migration matches the database version

Back up the database before applying migrations to an existing installation.

## Further documentation

- [Setup and deployment guide](SETUP_INSTRUCTIONS.md)
- [React and Firebase workspace guide](FIREBASE_REACT_WORKSPACE.md)
- [Role user guide](ROLE_USER_GUIDE.md)
- [System verification guide](SYSTEM_VERIFICATION_GUIDE.md)
- [Changelog](CHANGELOG.md)
