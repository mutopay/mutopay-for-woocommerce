# Local Testing Guide

A clean WordPress + WooCommerce environment for testing this plugin, including the security checks the WordPress.org reviewers run.

## Prerequisites

- Docker Desktop running
- Node.js 18+
- The MutoPay Worker running locally (`npm run dev` in `/Users/ahmad/projects/mutopay`) if you want to exercise the connect flow end-to-end

## Setup

Install `@wordpress/env` globally (one time):

```bash
npm install -g @wordpress/env
```

The repo includes a `.wp-env.json` that mounts this plugin, pulls the latest WordPress and WooCommerce, and turns on `WP_DEBUG`, `WP_DEBUG_LOG`, and `SCRIPT_DEBUG`. WooCommerce is listed before this plugin so `Requires Plugins: woocommerce` is satisfied at activation time.

To pin specific versions for reproducible runs, replace `"core": null` with e.g. `"https://wordpress.org/wordpress-6.9.zip"` and swap `woocommerce.latest-stable.zip` for a versioned URL like `woocommerce.9.4.2.zip`.

Boot the environment from the plugin root:

```bash
cd /Users/ahmad/projects/mutopay-for-woocommerce
wp-env start
```

First run takes a few minutes (downloads WordPress + WooCommerce, builds containers).

When it finishes:

- WP admin: http://localhost:8888/wp-admin
- Credentials: `admin` / `password`
- Tests instance (isolated, for PHPUnit): http://localhost:8889

## Activate plugins and seed WooCommerce

```bash
wp-env run cli wp plugin activate woocommerce mutopay-for-woocommerce
wp-env run cli wp option update woocommerce_store_address "Test"
wp-env run cli wp option update woocommerce_default_country "US:CA"
wp-env run cli wp option update woocommerce_currency "USD"
```

## Point the plugin at a local Worker

After enabling the MutoPay gateway once in `WooCommerce > Settings > Payments > MutoPay`, override `base_url` so the plugin hits your local Worker instead of production:

```bash
wp-env run cli wp option patch update woocommerce_mutopay_settings base_url "http://host.docker.internal:5173"
```

`host.docker.internal` is how containers reach the Mac host.

## Testing the CSRF fix on the OAuth callback

This is the specific issue the WordPress.org reviewer flagged. The plugin now generates a one-time `state` token on the WP side, passes it to MutoPay via `return_url`, and verifies it on the callback.

### Happy path

1. `WooCommerce > Settings > Payments > MutoPay > Connect to MutoPay`.
2. Complete the connect flow on your local MutoPay instance.
3. You land back on the settings page with "Connected as ..." and a success notice.

### Attack simulation (what the reviewer cares about)

Each of these should produce a `403 Invalid or expired connect request` page. No credentials should be saved.

1. **No state, fake token** (CSRF link):
   ```
   http://localhost:8888/wp-admin/admin.php?page=mutopay-connect&token=fake-token
   ```
2. **Wrong state**:
   ```
   http://localhost:8888/wp-admin/admin.php?page=mutopay-connect&token=fake-token&state=wrong
   ```
3. **Replay**: complete the connect flow successfully, then refresh the callback URL. The transient is deleted on first use, so the second hit must 403.
4. **Capability check**: log in as a Subscriber user, hit the callback URL. Should 403 (or be blocked by `add_submenu_page`'s capability check before the handler runs).

Create a Subscriber user for test 4:

```bash
wp-env run cli wp user create subscriber sub@test.local --role=subscriber --user_pass=password
```

## Watching the debug log

```bash
wp-env run cli tail -n 100 -f wp-content/debug.log
```

Filter for plugin errors:

```bash
wp-env run cli tail -f wp-content/debug.log | grep -i mutopay
```

## Running Plugin Check

WordPress.org runs the Plugin Check plugin against submissions. Run it locally before resubmitting:

```bash
wp-env run cli wp plugin install plugin-check --activate
wp-env run cli wp plugin check mutopay-for-woocommerce
```

## Common commands

| Command | Purpose |
|---|---|
| `wp-env start` | Boot containers |
| `wp-env stop` | Stop containers, preserve DB |
| `wp-env destroy` | Wipe everything, fresh slate |
| `wp-env run cli wp ...` | Run any wp-cli command |
| `wp-env logs` | Container logs |
| `wp-env clean all` | Reset both dev and tests DBs |

## Troubleshooting

### `fatal: could not open '.git/objects/pack/...'` on first start

This happens when `core` is pointed at a Git ref (e.g. `WordPress/WordPress#6.7`) and the clone aborts mid-way, leaving a corrupt cache. The `.wp-env.json` in this repo uses the release zip (`https://wordpress.org/wordpress-6.7.2.zip`) to avoid this entirely, but if you've already hit it, wipe the cache and retry:

```bash
wp-env destroy
rm -rf ~/.wp-env
wp-env start
```

### `no space left on device` or stalled downloads

Usually Docker volumes have filled up:

```bash
docker system df
docker system prune -a --volumes
```

### `host.docker.internal` doesn't resolve

On Linux, add `--add-host=host.docker.internal:host-gateway` to Docker. On macOS and Windows it works out of the box.

## Alternative: wp-now (no Docker)

If Docker is unavailable, `@wp-now/wp-now` runs WordPress on the PHP built-in server with SQLite:

```bash
npm install -g @wp-now/wp-now
cd /Users/ahmad/projects/mutopay-for-woocommerce
wp-now start --wp=6.7
```

Lighter and faster to start, but WooCommerce has to be installed manually via the plugins screen, and some integrations behave subtly differently against SQLite than MySQL.

## Files added for the local env

- `.wp-env.json` — wp-env config (mounts plugin, installs WooCommerce, enables debug)
- `.wp-env-uploads/` — auto-created upload directory mount (gitignored)
