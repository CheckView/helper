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
 * Fix: run with bounded concurrency, and require the response payload to
 * actually contain wp-cli stdout proving the install ran ("Plugin installed
 * successfully" / "activated"). Anything else is reported as a per-site
 * failure with the truncated response so we can see what InstaWP returned.
 *
 * @link https://documenter.getpostman.com/view/21495096/2s8YzUyhUf
 */
(async () => {
  const INSTAWP_API_KEY = process.env.INSTAWP_API_KEY

  if (!INSTAWP_API_KEY) {
    throw new Error('InstaWP API key not found.')
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

    const result = await fetch(sitesEndpoint, { headers })

    if (!result.ok) {
      throw new Error('Failed to fetch sites.')
    }

    const { data: sites } = await result.json() as InstaWpApiResponse<InstaWpSite[]>

    console.log(`Found ${sites.length} sites. Updating with concurrency=${CONCURRENCY}...`)

    type Failure = { site: InstaWpSite; message: string }
    const failedSites: Failure[] = []
    const okSites: string[] = []

    async function updateSite(site: InstaWpSite): Promise<void> {
      try {
        const commandEndpoint = new URL(`sites/${site.id}/execute-command`, base)
        const updateResult = await fetch(commandEndpoint, {
          method: 'POST',
          headers,
          body: JSON.stringify({ command_id: COMMAND_ID })
        })

        if (!updateResult.ok) {
          throw new Error(`HTTP ${updateResult.status} ${updateResult.statusText}`)
        }

        const responseData = await updateResult.json() as InstaWpApiResponse<string>

        if (!responseData.success) {
          throw new Error(`Command rejected: ${responseData.message}`)
        }

        // The API returns `success: true` even when the command was accepted
        // but the wp-cli step did not actually execute (this is the silent
        // no-op the previous parallel version was missing). Require the
        // response data to be a string containing wp-cli stdout that proves
        // the plugin install completed.
        const stdout = typeof responseData.data === 'string' ? responseData.data : ''
        const installed = stdout.includes('Plugin installed successfully') || stdout.includes("Plugin 'checkview' activated")

        if (!installed) {
          throw new Error(`Command accepted but no install evidence in response. data=[${stdout.slice(0, 200)}]`)
        }

        okSites.push(site.name)
      } catch (error) {
        const message = error instanceof Error ? error.message : 'Unknown error'
        failedSites.push({ site, message })
      }
    }

    for (let i = 0; i < sites.length; i += CONCURRENCY) {
      const batch = sites.slice(i, i + CONCURRENCY)
      await Promise.all(batch.map(updateSite))
      console.log(`Progress ${Math.min(i + CONCURRENCY, sites.length)}/${sites.length} (ok=${okSites.length}, failed=${failedSites.length})`)
    }

    if (failedSites.length) {
      console.log(`\nFailed executions (${failedSites.length}/${sites.length}):`)
      for (const f of failedSites) {
        console.log(`  - ${f.site.name} (${f.site.id}): ${f.message}`)
      }
      // Fail the workflow when any sites didn't actually update — the previous
      // version masked these failures by only logging them.
      process.exit(1)
    } else {
      console.log(`\nAll ${sites.length} sites updated successfully. Get to testin!`)
    }
  } catch (error) {
    if (error instanceof Error) {
      console.error(error.message)
    } else {
      console.error(error)
    }
    process.exit(1)
  }
})()
