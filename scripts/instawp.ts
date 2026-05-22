type InstaWpApiResponse<T> = {
  message: string
  success?: boolean
} & (
  {
    status: true
    data: T
  } | {
    status: false
    data: never
  }
)

type InstaWpSite = {
  id: number
  name: string
}

const CONCURRENCY = 4;
const COMMAND_ID = 1958; // InstaWP "Update CheckView plugin" — reinstalls from inspry/checkview development branch ZIP
const REQUEST_TIMEOUT_MS = 120_000;
const MAX_ATTEMPTS = 3;
const BACKOFF_BASE_MS = 2_000;
const BACKOFF_JITTER_MS = 1_000;
const MAX_LOGGED_BODY_BYTES = 4_000;
// Pattern stripped from any logged body excerpt before it reaches stderr.
// Defense-in-depth: if InstaWP's debug envelope ever echoes our inbound
// `Authorization` header (some Laravel apps do in development mode), this
// prevents the bearer token from landing in GitHub Actions logs. We also
// strip the literal API key value in `redactForLog` in case the token ever
// appears bare (no `Bearer ` prefix) in a response body.
const SECRET_REDACT_REGEX = /Bearer\s+[A-Za-z0-9._\-+/=]+/gi;
// wp-cli phrasing that proves command 1958's `wp plugin install ... --activate`
// step ran to completion. Sample positive stdout:
//   "Plugin installed successfully.\nActivating 'checkview'...\nPlugin 'checkview' activated.\nSuccess: Installed 1 of 1 plugins."
// Match a regex so newline-wrapped output and the canonical wp-cli "Success:
// Installed N of M plugins" line both count.
const INSTALL_EVIDENCE_REGEX = /(Plugin installed successfully|Plugin 'checkview' activated|Success:\s+Installed\s+\d+\s+of\s+\d+\s+plugins)/i;

function truncate(s: string, n: number): string {
  return s.length > n ? `${s.slice(0, n)}…(truncated ${s.length - n} chars)` : s
}

function redactForLog(s: string): string {
  let out = s.replace(SECRET_REDACT_REGEX, 'Bearer <redacted>')
  const key = process.env.INSTAWP_API_KEY
  if (key && key.length >= 16) out = out.split(key).join('<redacted-api-key>')
  return out
}

/**
 * Parse a JSON response body. We read the body as text first (instead of
 * `response.json()`) so the raw body is available for error messages when
 * JSON parsing fails — native fetch locks the stream after `.json()` starts
 * consuming, so reading text afterward isn't possible.
 *
 * No body-size cap: this script targets a single known endpoint
 * (app.instawp.io) that returns small JSON; bounding the read against
 * gigabyte-streaming hostile upstreams would be premature for a CI script
 * with one trusted upstream.
 */
async function parseJsonResponse<T>(response: Response, context: string): Promise<T> {
  let text: string
  try {
    text = await response.text()
  } catch (err) {
    const reason = err instanceof Error ? err.message : String(err)
    throw new Error(`Response for ${context} body read failed: ${reason}`)
  }
  try {
    return JSON.parse(text) as T
  } catch (err) {
    const reason = err instanceof Error ? err.message : String(err)
    const contentType = response.headers.get('content-type') ?? '<no content-type>'
    throw new Error(`Response for ${context} was not JSON (Content-Type=[${contentType}], ${reason}). body=[${truncate(redactForLog(text), MAX_LOGGED_BODY_BYTES)}]`)
  }
}

/**
 * Parse an HTTP Retry-After header value. Accepts both delta-seconds (`"60"`)
 * and HTTP-date (`"Thu, 22 May 2026 02:00:00 GMT"`). Returns milliseconds, or
 * undefined if header is absent / unparseable.
 */
function parseRetryAfter(headerValue: string | null): number | undefined {
  if (!headerValue) return undefined
  const seconds = Number(headerValue)
  if (Number.isFinite(seconds) && seconds >= 0) return Math.floor(seconds * 1000)
  const date = Date.parse(headerValue)
  if (Number.isFinite(date)) return Math.max(0, date - Date.now())
  return undefined
}

/**
 * Update the CheckView plugin on InstaWP sites using the `development` branch.
 *
 * Previously this script blasted all ~118 depdev-tagged sites in parallel via
 * Promise.all and only checked `responseData.success === true`. The InstaWP
 * API returns `success: true` for accepted-but-not-executed commands when
 * hit with that level of concurrency, so most sites silently no-op'd while
 * the workflow reported "All sites updated successfully." Empirically, after
 * a development push only ~3 of 12 form fixtures actually picked up the new
 * plugin version. Re-running the same command sequentially against the
 * stuck sites worked every time.
 *
 * Fix: bounded concurrency + per-site timeout + retry + require wp-cli
 * stdout that proves the install actually completed. On any genuine
 * post-retry failure, fail the workflow loudly rather than silently
 * masking it.
 *
 * @link https://documenter.getpostman.com/view/21495096/2s8YzUyhUf
 */
(async () => {
  const INSTAWP_API_KEY = process.env.INSTAWP_API_KEY

  if (!INSTAWP_API_KEY) {
    console.error('InstaWP API key not found.')
    process.exitCode = 1
    return
  }

  try {
    const base = 'https://app.instawp.io/api/v2/'

    const headers = new Headers()
    headers.append('Accept', 'application/json')
    headers.append('Content-Type', 'application/json')
    headers.append('Authorization', `Bearer ${INSTAWP_API_KEY}`)

    const sitesEndpoint = new URL('sites', base)
    sitesEndpoint.searchParams.append('per_page', '999')
    sitesEndpoint.searchParams.append('tags', '6042') // depdev tag id

    console.log('Fetching sites...')

    // Sites-list fetch with a single re-poll on empty response. InstaWP's tag
    // index can transiently return an empty array during reindex; failing the
    // whole workflow on the first read would be flaky. One retry after 5s
    // covers that without masking a real "tag deleted" config error.
    let sites: InstaWpSite[] = []
    let sitesMessage = ''
    for (let attempt = 1; attempt <= 2; attempt++) {
      const sitesResp = await fetch(sitesEndpoint, { headers, signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS) })
      if (!sitesResp.ok) {
        throw new Error(`Failed to fetch sites: HTTP ${sitesResp.status} ${sitesResp.statusText}`)
      }
      const sitesPayload = await parseJsonResponse<InstaWpApiResponse<InstaWpSite[]>>(sitesResp, 'sites list')
      if (sitesPayload.status !== true) {
        throw new Error(`Sites API returned status=false: ${sitesPayload.message}`)
      }
      sites = Array.isArray(sitesPayload.data) ? sitesPayload.data : []
      sitesMessage = sitesPayload.message
      if (sites.length > 0) break
      if (attempt < 2) {
        console.log('Sites list returned empty — retrying once after 5s (may be tag reindex).')
        await new Promise((r) => setTimeout(r, 5_000))
      }
    }
    if (sites.length === 0) {
      throw new Error(`No sites returned for depdev tag after retry. Has the tag id changed? API message=[${sitesMessage}]`)
    }

    console.log(`Found ${sites.length} sites. Updating with concurrency=${CONCURRENCY}, timeout=${REQUEST_TIMEOUT_MS}ms, max attempts=${MAX_ATTEMPTS}...`)

    type Failure = { site: InstaWpSite; attempts: number; message: string }
    const failedSites: Failure[] = []
    const okSites: string[] = []

    type AttemptOutcome = { retryable: boolean; retryAfterMs?: number }

    async function attemptUpdate(site: InstaWpSite): Promise<void> {
      const commandEndpoint = new URL(`sites/${site.id}/execute-command`, base)
      const updateResult = await fetch(commandEndpoint, {
        method: 'POST',
        headers,
        body: JSON.stringify({ command_id: COMMAND_ID }),
        signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
      })

      if (!updateResult.ok) {
        // 4xx (except 408/429) is a request problem — never going to fix itself; don't retry.
        const retryable = updateResult.status >= 500 || updateResult.status === 408 || updateResult.status === 429
        const retryAfterMs = parseRetryAfter(updateResult.headers.get('retry-after'))
        const err: Error & AttemptOutcome = Object.assign(
          new Error(`HTTP ${updateResult.status} ${updateResult.statusText}`),
          { retryable, retryAfterMs },
        )
        throw err
      }

      const responseData = await parseJsonResponse<InstaWpApiResponse<string>>(updateResult, `site ${site.name} (${site.id})`)

      // Discriminate on the literal-union `status` field (the actual TS
      // discriminant). `success` is documented as optional, so a response with
      // `status: true, data: <stdout>` and no `success` field is still valid.
      if (responseData.status !== true) {
        const err: Error & AttemptOutcome = Object.assign(
          new Error(`Command rejected: ${responseData.message}`),
          { retryable: false }, // command-level reject is rarely transient
        )
        throw err
      }

      // The API returns `status: true` even when the command was accepted but
      // the wp-cli step did not actually execute (this was the silent no-op
      // the previous parallel version was missing). Require the response data
      // to contain wp-cli stdout that proves the plugin install completed.
      const stdout = typeof responseData.data === 'string' ? responseData.data : ''
      if (!INSTALL_EVIDENCE_REGEX.test(stdout)) {
        const err: Error & AttemptOutcome = Object.assign(
          new Error(`Command accepted but no install evidence in response. data=[${truncate(redactForLog(stdout), MAX_LOGGED_BODY_BYTES)}]`),
          { retryable: true }, // silent-noop class — empirically resolves on retry
        )
        throw err
      }
    }

    async function updateSite(site: InstaWpSite): Promise<void> {
      let lastError: string = `no attempts made for ${site.name}`
      for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
        try {
          await attemptUpdate(site)
          okSites.push(site.name)
          return
        } catch (error) {
          lastError = error instanceof Error ? error.message : 'Unknown error'
          const outcome = error as Partial<AttemptOutcome>
          // Stop early on non-retryable errors (4xx other than 408/429,
          // explicit command rejection). Silent-noop responses are retryable.
          // Errors without an explicit `retryable` field (AbortError from
          // timeout, network resets, parse failures) fall through as retryable
          // since they're typically transient — `undefined === false` is false.
          if (outcome.retryable === false) break
          if (attempt < MAX_ATTEMPTS) {
            const backoffMs = BACKOFF_BASE_MS * attempt
            const jitterMs = Math.floor(Math.random() * BACKOFF_JITTER_MS)
            const sleepMs = Math.max(outcome.retryAfterMs ?? 0, backoffMs) + jitterMs
            await new Promise((r) => setTimeout(r, sleepMs))
          }
        }
      }
      failedSites.push({ site, attempts: MAX_ATTEMPTS, message: lastError })
    }

    for (let i = 0; i < sites.length; i += CONCURRENCY) {
      const batch = sites.slice(i, i + CONCURRENCY)
      await Promise.all(batch.map(updateSite))
      console.log(`Progress ${Math.min(i + CONCURRENCY, sites.length)}/${sites.length} (ok=${okSites.length}, failed=${failedSites.length})`)
    }

    if (failedSites.length) {
      console.log(`\nFailed executions (${failedSites.length}/${sites.length}) after ${MAX_ATTEMPTS} attempts:`)
      for (const f of failedSites) {
        console.log(`  - ${f.site.name} (${f.site.id}): ${f.message}`)
      }
      // Fail the workflow when any sites didn't actually update — the previous
      // version masked these failures by only logging them. Use exitCode so
      // pending stdout flushes before the process exits.
      process.exitCode = 1
    } else {
      console.log(`\nAll ${sites.length} sites updated successfully. Get to testin!`)
    }
  } catch (error) {
    if (error instanceof Error) {
      console.error(error.message)
    } else {
      console.error(error)
    }
    process.exitCode = 1
  }
})()
