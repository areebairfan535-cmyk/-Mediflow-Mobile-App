import { useEffect, useState } from 'react'
import { Redirect } from 'expo-router'
import { auth } from '../src/api'
import { Loading } from '../src/ui'

/**
 * Entry gate: a stored token means straight to the app, otherwise log in.
 *
 * A token with no clinic saved beside it is a real third case — an account
 * that exists but has not been attached to a medical record yet. It goes to
 * the screen that explains that, not into tabs that have nothing to show.
 * Read from the device, so this stays instant and works offline.
 */
export default function Index() {
  const [where, setWhere] = useState(null)

  useEffect(() => {
    auth.tokens().then(({ access, org }) => {
      setWhere(!access ? '/login' : (org ? '/(tabs)' : '/pending'))
    })
  }, [])

  if (where === null) return <Loading label="Starting…" />

  return <Redirect href={where} />
}
