# Advanced Image Style Warmer

Advanced Image Style Warmer pre-generates Drupal image style derivatives so
front-end and CDN requests do not pay the cost of on-the-fly image processing.
It is aimed at large sites (many files, remote storage such as S3) where the
legacy `image_style_warmer` approach does not scale.

For each image style you choose **Immediate** warming (on file save) or
**Queue** warming (background cron). Historical files are handled with a
parallel **Drush backfill** that uses integer FID ranges and short-lived worker
processes. A local **registry** table records which `(file, style)` pairs are
already warm so workers avoid expensive remote `file_exists()` checks.

## Features

- Per image style: **Immediate** or **Queue** (mutually exclusive), configured in the admin UI.
- **Bundled queue items** — one queue row per file with all queue styles, not one row per style.
- **Stateful registry** (`advanced_image_style_warmer_registry`) for fast skip logic on S3 and similar backends.
- **Parallel backfill** via `drush aisw:backfill` (master + worker subprocesses, no SQL `OFFSET`).
- **Registry-aware** queue and backfill paths that trust the registry before calling `createDerivative()`.
- **File replace** detection (URI or size change) clears registry rows so derivatives can be rebuilt.
- **Bulk actions** to warm selected files or media image fields.
- Optional **Crop** module hooks to re-warm the underlying file when crops change.
- Settings migration from **Image Style Warmer** when that module is still enabled during install/`updatedb`.

## Requirements

- Drupal 10 or 11.
- Core **File** and **Image** modules (declared in `advanced_image_style_warmer.info.yml`).
- PHP 8.1 or newer.
- **Symfony Process** (declared in this module’s `composer.json`; usually satisfied via Drupal core).
- **Drush 12+** strongly recommended for backfill and operational commands (`vendor/bin/drush`).

Optional (no hard dependency):

- **Media** — enables the “Warm image styles for media” bulk action.
- **Crop** — entity hooks re-warm files when crop entities change.
- **Image Style Warmer** — only needed if you want automatic settings migration from the old module.

## Installation

Install in a Composer-managed Drupal project when the package is available:

```bash
composer require drupal/advanced_image_style_warmer
drush en advanced_image_style_warmer -y
drush updatedb -y
drush cr
```

For local development or manual installation, place this repository at:

```text
web/modules/custom/advanced_image_style_warmer
```

Enable the module:

```bash
drush en advanced_image_style_warmer -y
drush updatedb -y
drush cr
```

`updatedb` creates the registry table and runs update hooks (for example the
`in_queue` column on existing sites). If you are replacing **Image Style
Warmer**, keep it enabled during the first `updatedb` so settings can migrate,
then disable and uninstall it when you are satisfied.

### After installation

1. Configure styles at **Configuration → Media → Advanced Image Style Warmer**
   (`/admin/config/media/advanced-image-styles-warmer`).
2. Ensure **cron** runs regularly, or drain the queue manually:
   `drush queue:run advanced_image_style_warmer`.
3. For existing files, run a one-time **backfill** for each style you care
   about (see [Drush commands](#drush-commands)).

## Repository layout

```text
advanced_image_style_warmer.info.yml
advanced_image_style_warmer.module
advanced_image_style_warmer.install
advanced_image_style_warmer.services.yml
advanced_image_style_warmer.routing.yml
advanced_image_style_warmer.links.menu.yml
drush.services.yml
composer.json
config/
  install/
  optional/
  schema/
src/
  Commands/BackfillCommands.php
  Form/SettingsForm.php
  Plugin/
    Action/
    QueueWorker/
  Registry.php
  Warmer.php
tests/src/Functional/
README.md
LICENSE
.gitlab-ci.yml
```

## Configuration

**Path:** `/admin/config/media/advanced-image-styles-warmer`  
**Permission:** `administer image styles`

The form lists every image style on the site. For each style, choose at most one:

| Mode | When derivatives are built |
| --- | --- |
| **Immediate** | Synchronously on `file` insert/update (permanent image files only). |
| **Queue** | Asynchronously via the `advanced_image_style_warmer` queue worker (cron). |

Settings are stored in config `advanced_image_style_warmer.settings` and are
safe to export with configuration sync.

### Bulk actions

After install, two actions may be available under *Structure → Actions* (or Views bulk actions):

- **Warm image styles for files** (`file` entities).
- **Warm image styles for media** (optional; requires the Media module).

These run the same warming logic as a file save for the selected entities.

## How warming works

### Three tiers

| Tier | Trigger | Mechanism |
| --- | --- | --- |
| 1. Immediate | `file` insert/update | Synchronous `ImageStyle::createDerivative()` for configured styles. |
| 2. Queue | Same hooks, after immediate | One queue item per file: `{ "fid": N, "styles": ["a", "b"] }`. |
| 3. Backfill | `drush aisw:backfill` | Master process spawns worker Drush commands over FID ranges. |

### Registry

Table `{advanced_image_style_warmer_registry}` stores `(fid, style_name,
warmed, in_queue, timestamp)`. Before generating, the module checks the
registry instead of calling `file_exists()` on remote derivatives (queue and
backfill use a registry-trusted code path).

Registry rows are cleared or updated when:

- An image style is flushed (full flush) or deleted.
- A partial style flush includes the **source** file URI.
- A file is deleted.
- A file’s **URI or size** changes on update.

### Derivatives and storage

Warming uses Drupal core’s image API: `ImageStyle::buildUri()` and
`createDerivative()`. The module does not implement S3 itself. If your site
stores files or derivatives on S3 (for example via S3FS), a successful warm
writes a new object at the derivative URI, the same as on-demand generation.

## Drush commands

All commands use the `aisw:` alias prefix.

### `aisw:backfill`

Warm existing files in parallel over FID ranges.

```bash
drush aisw:backfill --styles=hero,thumbnail --concurrency=8 --chunk-size=5000
```

| Option | Default | Description |
| --- | --- | --- |
| `--styles` | All styles configured Immediate + Queue | Comma-separated image style machine names. |
| `--concurrency` | `4` | Maximum concurrent worker processes. |
| `--chunk-size` | `5000` | Width of each FID range per worker. |
| `--min-fid` / `--max-fid` | Auto from `file_managed` | Override FID bounds. |
| `--progress-interval` | `15` | Heartbeat interval in seconds (`0` disables). |

Multisite: pass `--uri` on the master; workers inherit it.

```bash
drush --uri=https://www.example.com aisw:backfill --styles=large_row_article_thumbnail
```

Progress lines use `[notice]` and include **scanned**, **skipped (registry warm)**,
**attempted**, **succeeded**, **failed (still pending)**, and **derivatives**
(registry marks from successful generation). A **running total** is printed
after each chunk and on heartbeats.

Workers resolve Drush from `DRUSH_COMMAND`, `runtime.drush-script`, or
`vendor/bin/drush` under `DRUPAL_ROOT` or its parent directory.

### `aisw:status`

Per-style counts from the registry.

```bash
drush aisw:status
```

| Column | Meaning |
| --- | --- |
| **Warmed** | Rows with `warmed = 1` (successful generation recorded). |
| **Pending** | Rows with `warmed = 0` (still need generation). |
| **Queued** | Pending rows with `in_queue = 1` (queue item outstanding). |

### `aisw:latest`

List the most recently warmed files for manual checks (source + derivative URIs).

```bash
drush aisw:latest --style=large_row_article_thumbnail --limit=20
drush aisw:latest --style=thumbnail --limit=5 --format=json
```

| Option | Default | Description |
| --- | --- | --- |
| `--style` | (required) | Image style machine name. |
| `--limit` | `10` | Rows to return (max 500). |

### `aisw:worker`

Hidden command used only by the backfill master. Do not run manually unless debugging.

### Queue maintenance

```bash
drush queue:run advanced_image_style_warmer
```

## Upgrading from Image Style Warmer

If `image_style_warmer` is enabled when you install or run `updatedb`, settings map as follows:

| Legacy config | Advanced Image Style Warmer |
| --- | --- |
| `initial_image_styles` | **Immediate** |
| `queue_image_styles` | **Queue** |

Migration runs from `advanced_image_style_warmer_update_10001()` and on install
when the legacy module is present. It does not overwrite non-empty new settings.

## Verifying operation

Check registry totals:

```bash
drush aisw:status
```

Inspect recent warmed files and URIs:

```bash
drush aisw:latest --style=YOUR_STYLE --limit=10
```

Confirm derivatives exist (local example):

```bash
drush php:eval '
$style = \Drupal::entityTypeManager()->getStorage("image_style")->load("thumbnail");
$file = \Drupal\file\Entity\File::load(1);
if ($style && $file) {
  echo $style->buildUri($file->getFileUri());
}
'
```

Watch failures during backfill:

```bash
drush watchdog:show --filter=advanced_image_style_warmer --count=20
```

## Troubleshooting

### Backfill workers fail with `Could not open input file: drush`

The master could not resolve a Drush PHP script. Run backfill from the project
where `vendor/bin/drush` exists, or set `DRUSH_COMMAND` to that script before
invoking Drush:

```bash
export DRUSH_COMMAND=/opt/drupal/vendor/bin/drush
drush aisw:backfill --styles=thumbnail
```

### Many **attempted** but few **succeeded** / low **Warmed** in `aisw:status`

**Attempted** means the registry still had pending work; **succeeded** means
`createDerivative()` completed and the registry was updated. Failures stay
pending and are retried on the next run. Check `advanced_image_style_warmer`
log messages (missing source file, toolkit errors, S3 timeouts, permissions).

### Backfill looks stuck but heartbeats continue

Large FID ranges with many images can take minutes per chunk. Use heartbeats and
`aisw:latest` to confirm progress. Empty FID spans finish quickly; dense ranges
dominate runtime.

### Queue never drains

Ensure cron runs or run `drush queue:run advanced_image_style_warmer` manually.
Confirm at least one style is set to **Queue** in configuration.

### `aisw:status` **Warmed** does not match backfill “files warmed” (old logs)

Registry **Warmed** counts successful registry updates (approximate derivative
successes). Older log wording counted every file with pending work as “warmed.”
Current backfill output separates **attempted**, **succeeded**, and **failed**.

### Route or Drush command not found

Rebuild caches and clear Drush command discovery:

```bash
drush cr
drush cache:clear drush
```

### Replacing Image Style Warmer

Disable the old module only after configuration and a sample backfill look
correct. Uninstalling the old module does not remove generated derivative files.

## Automated tests

Functional tests live under `tests/src/Functional/`. On Drupal.org, CI uses
`.gitlab-ci.yml`. From a full Drupal core checkout:

```bash
php core/scripts/run-tests.sh --url http://localhost --module advanced_image_style_warmer
```

## License

This project is licensed under the GNU General Public License version 2 or later
(GPL-2.0-or-later). See [LICENSE](LICENSE).

Copyright (C) 2026 Stefanos Chrs (root@stefanoschrs.com)
