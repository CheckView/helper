type AuthorizationResponse = {
  access_token: string
}

type PressableAPIResponse<T> = {
  message: string
} & (
  {
    errors: string[]
    data: null
  } | {
    errors: null,
    data: T
  }
)

type PressableSite = {
  id: number
  displayName: string
}

/**
 * Update the CheckView plugin on Pressable sites using the `quality` branch.
 * 
 * @link https://my.pressable.com/documentation/api/v1
 */
(async () => {
  const PRESSABLE_CLIENT_ID = process.env.PRESSABLE_CLIENT_ID
  const PRESSABLE_CLIENT_SECRET = process.env.PRESSABLE_CLIENT_SECRET

  if (!PRESSABLE_CLIENT_ID || !PRESSABLE_CLIENT_SECRET) {
    throw new Error('PRESSABLE_CLIENT_ID or PRESSABLE_CLIENT_SECRET environment variable not found.')
  }

  try {
    const apiBase = 'https://my.pressable.com/v1/'
    const qualityTag = 'checkview-qa'

    const headers = new Headers()
    headers.append('Accept', 'application/json')
    headers.append('Content-Type', 'application/json')

    console.log('Fetching token...')

    const authFetch = await fetch(`https://my.pressable.com/auth/token`, {
      method: 'POST',
      headers,
      body: JSON.stringify({
        client_id: PRESSABLE_CLIENT_ID,
        client_secret: PRESSABLE_CLIENT_SECRET,
        grant_type: 'client_credentials'
      })
    })

    if (!authFetch.ok) {
      throw new Error('Failed to obtain access token.')
    }

    const { access_token } = await authFetch.json() as AuthorizationResponse
    headers.append('Authorization', `Bearer ${access_token}`)

    const sitesEndpoint = new URL('sites', apiBase)
    sitesEndpoint.searchParams.append('tag_name', qualityTag)

    console.log('Fetching', sitesEndpoint.toString())

    const sitesFetch = await fetch(sitesEndpoint, {
      method: 'GET',
      headers,
    })

    if (!sitesFetch.ok) {
      throw new Error('Failed to obtain list of sites.')
    }

    const sites = await sitesFetch.json() as PressableAPIResponse<PressableSite[]>

    if (sites.errors) {
      throw new Error(sites.errors.join(', '))
    }

    console.log(`Found ${sites.data.length} sites tagged with "${qualityTag}". Updating...`)

    const failedSites: {
      site: PressableSite
      message: string
    }[] = []

    const updatePromises = sites.data.map(async (site) => {
      try {
        console.log(`Requesting update for site: ${site.displayName} (${site.id})...`)

        const pluginsEndpoint = new URL(`sites/${site.id}/plugins`, apiBase)
        const pluginsResult = await fetch(pluginsEndpoint, {
          method: 'POST',
          headers,
          body: JSON.stringify({
            plugins: [
              {
                path: 'https://github.com/inspry/checkview/archive/refs/heads/quality.zip'
              },
            ]
          }),
        })

        if (!pluginsResult.ok) {
          throw new Error(`Failed to update plugins for site: ${site.displayName} (${site.id})`)
        }

        const pluginsData = await pluginsResult.json() as PressableAPIResponse<null>

        if (pluginsData.message !== "Success") {
          throw new Error(`Got non-success response when updating plugins for site: ${site.displayName} (${site.id})`)
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
      console.log('All sites have been told to update their plugins. Note that it may take some time for the plugin update to complete. Get to testin!')
    }
  } catch (error) {
    if (error instanceof Error) {
      console.error(error.message)
    } else {
      console.error(error)
    }
  }
})()
