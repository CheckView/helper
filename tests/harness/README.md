# Verification harness

Standalone assertion scripts that drive the plugin's real methods and check them
against the real source of the plugins we integrate with.

```bash
tests/harness/fetch-fixtures.sh          # once, plus --wordpress for two cases
php tests/harness/run.php                # all cases
php tests/harness/run.php formidable     # only matching cases
```

Exit code is 1 if any assertion fails.

## Why this exists alongside `tests/`

The PHPUnit suite in `tests/` cannot bootstrap from an ordinary checkout — it
needs a provisioned WordPress test install. That made every fix in this plugin
unverifiable by anyone who just cloned the repo, so the checks that actually
caught the bugs lived in a scratch directory and were lost between sessions.

These cases need nothing but `php`. They stub the handful of WordPress functions
each one touches, then call the plugin's real methods.

## What the cases are for

Most of them are not testing our logic in isolation. They are pinning **our
assumptions about other people's code** — the assumptions that turned out to be
wrong nearly every time a bug was found here:

- a hook name built by string concatenation, so grepping the literal finds nothing
- a callback registered at a priority `remove_action()` must match exactly
- a default value one call deeper than the file being read
- a shortcode tag with an undocumented alias
- an upstream `WHERE` clause we had reimplemented slightly differently

So the cases read the shipped third-party source and assert the correspondence.
If WP Armour moves a priority, or CleanTalk changes what its sentinel returns, or
Formidable redefines "published", the relevant case fails — rather than the bypass
silently becoming a no-op and a test run going quietly wrong.

Where a case can execute rather than pattern-match, it does: `formidable_form_list`
runs the extracted `WHERE` clause against a real SQLite table of the row shapes
Formidable produces, and `gf_plural_quoted` runs WordPress's own shortcode parser.

## Fixtures are deliberately not committed

`fetch-fixtures.sh` downloads current stable builds from wordpress.org into
`tests/harness/fixtures/`, which is git-ignored. Pinning copies in the repo would
defeat the purpose — several cases exist precisely to notice when one of these
plugins changes, so they have to read whatever is current.

Gravity Forms is not fetchable (commercial), so the GF cases assert against
WordPress core's shortcode parser and our own source instead.

## Skips are not passes

A case skips, rather than fails, when:

- a fixture is missing — run `fetch-fixtures.sh`
- the change it covers is not in the tree yet — it names the PR

The second is what makes this useful during the current backlog: on `development`
every case skips and reports which PR it is waiting for. As each merges, its case
starts running on its own. Run it after each merge.

`run.php` always lists skips separately from passes and never counts a skip as
green, so a run that verified nothing cannot look like a run that verified
everything.

## Adding a case

Drop a file in `cases/`. It should:

1. `require_once __DIR__ . '/../bootstrap.php';`
2. declare what it needs — `cv_need_fixture()`, `cv_need_wordpress()`,
   `cv_need_change()`
3. assert with `cv_ok()`
4. end with `cv_finish()`

Each case runs in its own process, so it can freely declare `add_action()`,
`Checkview_Admin_Logs` and similar without colliding with any other case.

Prefer extracting the thing under test from the shipped file over restating it —
a copied constant drifts, an extracted one cannot.
