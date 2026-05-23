# WP.org Review — 2nd Email (2026-05-12)

## Issue 1: Not permitted files
`flare-load/flare-load.log` detected in plugin.
Fix: ensure .log file is not in repo/zip.

## Issue 2: Use wp_enqueue commands
`flare-load.php:103 echo '<script>'` — direct inline script echo, must use wp_add_inline_script().

## Issue 3: Generic prefix issues
- `fp` prefix (2 elements) — too short:
  - `fpConfig` in wp_localize_script (lines 704, 710, 716, 735)
  - `fpMigrateConfig` in wp_localize_script (line 722)
- `flare_press` prefix (8 elements) — needs investigation
- `FLARE_PRESS_URL` still referenced in submitted version
