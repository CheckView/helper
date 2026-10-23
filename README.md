# CheckView Helper Plugin

WordPress plugin that handles interactions between WordPress sites and the CheckView SaaS platform. Exposes a REST API at `/wp-json/checkview/v1/` for automated form and WooCommerce checkout testing.

## Local Development

### Prerequisites

- Node.js (see `.nvmrc`)
- pnpm
- Docker (required by wp-env)
- The [CheckView SaaS](../checkview) repo set up and running locally

### Quick Start

```shell
pnpm install
pnpm run dev:start
```

This starts a WordPress instance at `http://localhost:8888` with the helper plugin activated, Xdebug enabled, and debug logging on.

### Commands

| Command | Description |
|---|---|
| `pnpm run dev:start` | Start wp-env with Xdebug enabled |
| `pnpm run dev:stop` | Stop wp-env (preserves data) |
| `pnpm run dev:destroy` | Remove wp-env containers and data |
| `pnpm run build` | Build WooCommerce block payment gateway |
| `pnpm start` | Dev watch mode for block gateway |

### WordPress Credentials

- **URL**: `http://localhost:8888/wp-admin`
- **Username**: `admin`
- **Password**: `password`

### Connecting to Local SaaS

The local SaaS needs to communicate with this local WordPress instance. Key details:

1. **Add the site in the SaaS UI** at `http://localhost:3000` using URL `http://localhost:8888`
2. **SSL is bypassed** automatically via the `CHECKVIEW_DEV` constant (set in `.wp-env.json`)
3. **SSRF validation is bypassed** on the SaaS side when `CHECKVIEW_ENV=local`
4. **JWT authentication** still works normally. The helper plugin fetches the public key from `https://app.checkview.io/api/helper/public_key` (production endpoint, publicly accessible). The local SaaS signs tokens with the same private key.
5. **Bot detection** skips IP verification when `WP_ENVIRONMENT_TYPE=local` (set in `.wp-env.json`)

### Dev Constants

These are set automatically in `.wp-env.json`:

| Constant | Value | Effect |
|---|---|---|
| `CHECKVIEW_DEV` | `true` | Bypasses SSL requirement on REST API |
| `WP_ENVIRONMENT_TYPE` | `local` | Skips IP whitelist check in bot detection |
| `WP_DEBUG` | `true` | Enables WordPress debug mode |
| `WP_DEBUG_LOG` | `true` | Writes errors to `wp-content/debug.log` |

### Debug Log

Access the WordPress debug log inside the container:

```shell
docker exec -it $(docker ps -qf "publish=8888") tail -f /var/www/html/wp-content/debug.log
```

CheckView also writes its own logs viewable in WP Admin under CheckView > Logs, or via the `/checkview/v1/get-logs` API endpoint.

## PhpStorm Debugging

### 1. Xdebug Settings

In PhpStorm, navigate to **PHP > Debug > Xdebug**:

- **Force break at first line when no path mapping specified**: Disable
- **Force break at first line when a script is outside the project**: Disable

### 2. Configure Server

In PhpStorm, navigate to **PHP > Servers** and add a server:

- **Name**: `localhost` (or any name)
- **Host**: `localhost`
- **Port**: `8888`
- **Debugger**: `Xdebug`
- **Use path mappings**: Enable
- **Path mapping**: your local repo root maps to `/var/www/html/wp-content/plugins/helper`

### 3. Start Debugging

1. Click **Start Listening for PHP Debug Connections** in the toolbar
2. Set a breakpoint
3. Trigger a request (browse the site, or trigger a test from the SaaS)
4. PhpStorm should break at your breakpoint

## Architecture

Entry point: `checkview.php`

| Directory | Purpose |
|---|---|
| `includes/` | Core classes, loader, utility functions |
| `includes/API/` | REST API endpoints |
| `includes/formhelpers/` | Form plugin integrations (Gravity, Fluent, WPForms, etc.) |
| `includes/woocommercehelper/` | WooCommerce checkout automation and test payment gateway |
| `admin/` | Admin UI, settings pages, logging |
| `public/` | Frontend assets |
| `resources/js/` | Block checkout gateway source (built via `pnpm run build`) |

## Release

Tag format `v1.0.X` on `main` triggers a GitHub Action that pushes to WordPress SVN. Use the `trunk` branch for non-versioned updates (readme/assets only).

The deploy action builds the release with `rsync --exclude-from=.distignore`, so
`.distignore` uses **rsync** pattern semantics (leading `/` = anchored to the
plugin root; bare names match at any depth). Note that Composer's vendor-dir is
`includes/vendor`, which is committed and must ship — the `/vendor` line in
`.distignore` intentionally matches nothing. The action's `composer install`
step is discarded by the second `actions/checkout`; releases contain only what
is committed, so commit build outputs (`assets/js/frontend/blocks.js`,
`includes/vendor/`).

### Plugin Check

WordPress.org runs [Plugin Check](https://wordpress.org/plugins/plugin-check/)
on submissions. Known results for this plugin:

- **`Offloading_Files_Check` flags `https://verify.checkview.io/whitelist.json`**
  in `includes/checkview-functions.php`. This is a false positive: the check's
  regex treats any URL ending in `.json` as an offloaded asset, but this is a
  server-side `wp_remote_get()` to the plugin's own API, which the directory
  guidelines allow. Do not "fix" it by changing the URL.
- All admin assets are bundled locally (`admin/assets/js/vendor/`,
  `admin/assets/fonts/`). Do not reintroduce CDN or Google Fonts URLs — the
  check flags them, and the previous CDN-pinned SweetAlert2 was serving a
  protestware build (see `admin/assets/js/vendor/README.md`).
- Minified JS must ship with its source: `resources/js/frontend/block.js` is the
  source for `assets/js/frontend/blocks.js` and is deliberately **not** in
  `.distignore`.
