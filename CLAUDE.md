# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A self-contained WordPress plugin ("MotionPulse Optimize Database", slug `mpodb`) that cleans and shrinks the WP database. Plain PHP + jQuery — there is no build step, package manager, dependency, test suite, or linter in this repo. The repository root *is* the plugin directory: deploying means copying it into `wp-content/plugins/`. There are no commands to build, lint, or test; the only way to exercise the code is inside a running WordPress install (activate the plugin, then use the **MotionPulse DB** admin menu).

The whole UI and all code comments are in Spanish. Keep new user-facing strings and comments in Spanish to match.

## Architecture

Three files carry all the logic:

- [optimize-db.php](optimize-db.php) — plugin header, constants, activation/deactivation, cron wiring, asset enqueue, AJAX registration. Bootstrap only; no business logic.
- [includes/functions.php](includes/functions.php) — every database operation, all `mpodb_*` prefixed, all procedural (no classes).
- [admin/admin-page.php](admin/admin-page.php) — the single admin screen: POST action handling at the top, then inline HTML.

State is persisted entirely in WP options (`mpodb_optimization_status`, `mpodb_initial_size`, `mpodb_final_size`, `mpodb_size_reduction*`, `mpodb_last_run_*`, `mpodb_orphaned_posts_deleted`, `mpodb_orphaned_taxonomies_deleted`, `mpodb_acf_orphans_deleted`, `mpodb_show_welcome`). There is no custom table. `mpodb_get_stats()` is the single read point for the admin page and for the AJAX refresh endpoint, so a new statistic must be added there *and* mirrored in [admin/js/admin-script.js](admin/js/admin-script.js), which repaints the same DOM ids.

### Execution model

`mpodb_optimize_database()` never runs in the request that triggers it. Both the "Optimizar ahora" button (`mpodb_start_manual_optimization()`) and the daily schedule go through WP-Cron events (`mpodb_optimize_database_event`, `mpodb_optimize_database_event_daily`). This matters: **cron context has far fewer post types and taxonomies registered than an admin request**, which is the root cause of the design constraint below.

### The automatic/manual split (do not collapse it)

Automatic pass (cron) only does work that is safe without knowing what is registered: orphan postmeta, exact-duplicate postmeta, revisions, trashed posts, ACF orphans, `OPTIMIZE TABLE`.

Orphaned custom post types and taxonomies are **scan-only** in the automatic pass. `mpodb_scan_orphaned_post_types()` / `mpodb_scan_orphaned_taxonomies()` are read-only by design and their results are rendered as a manual review table; deletion happens only through an explicit admin POST that lands in `mpodb_delete_orphaned_post_type()` / `mpodb_delete_orphaned_taxonomy()`. Making this automatic again caused real data loss (a plugin temporarily deactivated, or a CPT registered only in admin, looks orphaned from cron). See the note at [includes/functions.php:67-74](includes/functions.php#L67-L74).

### Invariants that previous bugs turned into rules

- **Duplicate postmeta is partitioned by `post_id + meta_key + meta_value`, not by `post_id + meta_key`.** WordPress legitimately stores multiple rows sharing a `meta_key` (`add_post_meta` with `$unique = false`). Narrowing the partition destroys multivalued metadata (e.g. TranslatePress language switcher entries).
- **Never assume the `wp_` table prefix.** Always derive from `$wpdb->prefix` / `$wpdb->posts` / `$wpdb->postmeta`; installs in the wild use prefixes like `twf_`.
- **TranslatePress data is off-limits.** Its tables always match `<wp_prefix>trp_*` and hold the translations themselves (it does not duplicate posts per language). Any new table-level operation must be gated on `mpodb_is_table_safe_to_touch()`; any new post-level query must append `mpodb_get_translatepress_exclusion_sql()`.
- **`mpodb_get_translatepress_exclusion_sql()` must be concatenated *after* `$wpdb->prepare()`, never passed through it** — `prepare()` would mangle the `%` in its `LIKE`. The fragment contains only code constants, so there is no injection surface. Same pattern in `mpodb_scan_orphaned_post_types()` and `mpodb_get_deletable_ids_for_post_type()`.
- Deletion helpers double-check their own guards (`in_array(..., mpodb_get_valid_post_types())`, `trp_` prefix) rather than trusting the caller, because they are reachable from POST input.

## Conventions

- Every function and option is prefixed `mpodb_`; every CSS class and DOM id is prefixed `mpodb-`.
- Every PHP file starts with the `if (!defined('ABSPATH')) exit;` guard.
- Admin POST actions use a hidden `mpodb_action` field plus `check_admin_referer()`; the AJAX endpoint uses `check_ajax_referer('mpodb_refresh_nonce')`. The page also gates on `manage_options`.
- Assets are enqueued only on `toplevel_page_mpodb-optimize` and versioned with `MPODB_VERSION` for cache busting.
- **Bumping the version means editing two places**: the `Version:` line in the plugin header and the `MPODB_VERSION` constant in [optimize-db.php](optimize-db.php). They are currently both `3.3` and must stay in sync or cached CSS/JS will be served after a release.
