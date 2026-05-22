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
// wp-cli phrasing that proves command 1958's `wp plugin install ... --activate`
// step ran to completion. Matching any one of these is sufficient. Sample
// positive stdout: "Plugin installed successfully.\nActivating 'checkview'...\nPlugin 'checkview' activated.\nSuccess: Installed 1 of 1 plugins."
const INSTALL_EVIDENCE_PATTERNS = [
  'Plugin installed successfully',
  "Plugin 'checkview' activated",
  'Success: Installed 1 of 1 plugins',
];

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

    const sitesResp = await fetch(sitesEndpoint, { headers, signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS) })

    if (!sitesResp.ok) {
      throw new Error(`Failed to fetch sites: HTTP ${sitesResp.status} ${sitesResp.statusText}`)
    }

    let sitesPayload: InstaWpApiResponse<InstaWpSite[]>
    try {
      sitesPayload = await sitesResp.json() as InstaWpApiResponse<InstaWpSite[]>
    } catch (err) {
      const body = await sitesResp.text().catch(() => '<unreadable>')
      throw new Error(`Sites response was not JSON: ${(err as Error).message}. body=[${body.slice(0, 200)}]`)
    }

    const sites = sitesPayload.status === true ? sitesPayload.data : []
    if (!Array.isArray(sites) || sites.length === 0) {
      throw new Error(`No sites returned for depdev tag (got ${sites?.length ?? 'non-array'}). Has the tag id changed?`)
    }

    console.log(`Found ${sites.length} sites. Updating with concurrency=${CONCURRENCY}, timeout=${REQUEST_TIMEOUT_MS}ms, max attempts=${MAX_ATTEMPTS}...`)

    type Failure = { site: InstaWpSite; attempts: number; message: string }
    const failedSites: Failure[] = []
    const okSites: string[] = []

    async function attemptUpdate(site: InstaWpSite): Promise<string> {
      const commandEndpoint = new URL(`sites/${site.id}/execute-command`, base)
      const updateResult = await fetch(commandEndpoint, {
        method: 'POST',
        headers,
        body: JSON.stringify({ command_id: COMMAND_ID }),
        signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
      })

      if (!updateResult.ok) {
        throw new Error(`HTTP ${updateResult.status} ${updateResult.statusText}`)
      }

      let responseData: InstaWpApiResponse<string>
      try {
        responseData = await updateResult.json() as InstaWpApiResponse<string>
      } catch (err) {
        const body = await updateResult.text().catch(() => '<unreadable>')
        throw new Error(`Response was not JSON: ${(err as Error).message}. body=[${body.slice(0, 200)}]`)
      }

      if (responseData.success !== true || responseData.status !== true) {
        throw new Error(`Command rejected: ${responseData.message}`)
      }

      // The API returns `success: true` even when the command was accepted
      // but the wp-cli step did not actually execute (this is the silent
      // no-op the previous parallel version was missing). Require the
      // response data to contain wp-cli stdout that proves the plugin
      // install completed.
      const stdout = typeof responseData.data === 'string' ? responseData.data : ''
      const installed = INSTALL_EVIDENCE_PATTERNS.some((p) => stdout.includes(p))

      if (!installed) {
        throw new Error(`Command accepted but no install evidence in response. data=[${stdout.slice(0, 1000)}]`)
      }

      return stdout
    }

    async function updateSite(site: InstaWpSite): Promise<void> {
      let lastError = ''
      for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
        try {
          await attemptUpdate(site)
          okSites.push(site.name)
          return
        } catch (error) {
          lastError = error instanceof Error ? error.message : 'Unknown error'
          if (attempt < MAX_ATTEMPTS) {
            await new Promise((r) => setTimeout(r, BACKOFF_BASE_MS * attempt))
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
