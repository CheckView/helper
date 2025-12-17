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

/**
 * Update the CheckView plugin on InstaWP sites using the `development` branch.
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

    console.log(`Found ${sites.length} sites. Updating...`)

    const failedSites: {
      site: InstaWpSite
      message: string
    }[] = []

    const updatePromises = sites.map(async (site) => {
      try {
        console.log(`Updating site: ${site.name} (${site.id})...`)

        const commandEndpoint = new URL(`sites/${site.id}/execute-command`, base)
        const updateResult = await fetch(commandEndpoint, {
          method: 'POST',
          headers,
          body: JSON.stringify({ command_id: 1958 }) // Update CheckView plugin command id
        })

        if (!updateResult.ok) {
          throw new Error(`Failed to execute command for site: ${site.name} (${site.id})`)
        }

        const responseData = await updateResult.json() as InstaWpApiResponse<null>

        if (!responseData.success) {
          throw new Error(`Command execution error for site: ${site.name} (${site.id}): ${responseData.message}`)
        }
      } catch (error) {
        if (error instanceof Error) {
          failedSites.push({site, message: error.message})
        } else {
          failedSites.push({site, message: 'Unknown error'})
        }
      }
    })

    await Promise.all(updatePromises)

    if (failedSites.length) {
      console.log('Failed executions:', failedSites)
    } else {
      console.log('All sites updated successfully. Get to testin!')
    }
  } catch (error) {
    if (error instanceof Error) {
      console.error(error.message)
    } else {
      console.error(error)
    }
  }
})()
