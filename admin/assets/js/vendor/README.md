# Bundled third-party admin assets

Libraries here are shipped with the plugin rather than loaded from a CDN, as
required by the WordPress Plugin Directory guidelines. Files are unmodified
vendor distributions.

## sweetalert2

- **Version:** 9.17.4
- **File:** `sweetalert2.all.min.js` (the `all` build; injects its own styles)
- **Source:** https://cdn.jsdelivr.net/npm/sweetalert2@9.17.4/dist/sweetalert2.all.min.js
- **Upstream:** https://github.com/sweetalert2/sweetalert2
- **License:** MIT (see `sweetalert2-LICENSE.txt`)

Consumed by `admin/assets/js/checkview-admin.js` via the global `Swal`.

### Upgrading

1. Replace `sweetalert2.all.min.js` with the new distribution.
2. Update `Checkview_Admin::SWEETALERT2_VERSION` in
   `admin/class-checkview-admin.php` to match — that constant is the asset
   cache-busting version.
3. Re-check `admin/assets/css/checkview-swal2.css`, which overrides
   SweetAlert2's own class names (`.swal2-*`) and can break across major
   versions.
