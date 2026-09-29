# Bundled third-party admin assets

Libraries here are shipped with the plugin rather than loaded from a CDN, as
required by the WordPress Plugin Directory guidelines. Files are unmodified
vendor distributions.

## sweetalert2

- **Version:** 11.26.25
- **File:** `sweetalert2.all.min.js` (the `all` build; injects its own styles)
- **Source:** `https://registry.npmjs.org/sweetalert2/-/sweetalert2-11.26.25.tgz`
  → `package/dist/sweetalert2.all.min.js`
- **Upstream:** https://github.com/sweetalert2/sweetalert2
- **License:** MIT (see `sweetalert2-LICENSE.txt`)

Consumed by `admin/assets/js/checkview-admin.js` via the global `Swal`.

### Provenance

The bundled file is byte-identical to the one inside the npm tarball above,
and that tarball matches the integrity hash npm publishes for the version:

```
sweetalert2.all.min.js  sha256  4e86f0e22e4771b5b8aac24c613c661806a678b1afa284d03cf6ad03d3e21a0a
tarball                 sha512  +hunCOJdJ6FLj04T9YSLvvZXRjsvIkTeTKP2e4VF8CaBias961BTnWiSFAy7F/CM5eq3QK2Rraoc5Gzftslvkg==
```

Re-verify after any upgrade:

```sh
curl -sSL -o pkg.tgz https://registry.npmjs.org/sweetalert2/-/sweetalert2-<ver>.tgz
tar -xzf pkg.tgz && shasum -a 256 package/dist/sweetalert2.all.min.js
curl -sS https://registry.npmjs.org/sweetalert2/<ver> | jq -r .dist.integrity
```

### Do not use 9.17.3+ or 10.x

Before being bundled, the plugin loaded `sweetalert2@9` from a CDN, which
resolved to 9.17.4. That build (and 9.17.3, and 10.x) contains protestware
tracked as [GHSA-pg98-6v7f-2xfv](https://github.com/advisories/GHSA-pg98-6v7f-2xfv):
when `navigator.language` starts with `ru` **and** the page host ends in
`.ru`/`.su`/`.рф`, after three days it sets
`document.body.style.pointerEvents = "none"` (freezing the whole page) and
loops an audio file from a third-party host. The 9.17.3/9.17.4 tarballs were
published in Nov 2022, two years after 9.17.2, solely to push this onto the
legacy `@9` tag, and the injected build reports `version = "9.17.2"`.

The advisory lists 9.17.3 as safe; it is not. Only 9.17.2 and below, or the
11.22.4+ line, are clean. Grep any candidate build for `pointerEvents="none"`,
`new Audio`, `.mp3`, `navigator.language`, `location.host` before bundling.

### Upgrading

1. Replace `sweetalert2.all.min.js` with the new distribution and re-run the
   provenance check above.
2. Update `Checkview_Admin::SWEETALERT2_VERSION` in
   `admin/class-checkview-admin.php` to match — that constant is the asset
   cache-busting version.
3. Re-check `admin/assets/css/checkview-swal2.css`, which overrides
   SweetAlert2's own class names (`.swal2-*`) and can break across major
   versions (e.g. `.swal2-content` became `.swal2-html-container` in 11).
