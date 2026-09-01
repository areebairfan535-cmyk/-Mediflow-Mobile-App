import { useCallback, useState } from 'react'
import { useFocusEffect } from 'expo-router'

/**
 * Screen data that is already there when you arrive.
 *
 * Every tab used to clear itself and show a spinner on focus, so moving
 * between Home and Bills meant watching the app rebuild something it had
 * fetched ten seconds earlier. On a clinic's wifi that reads as slowness even
 * though the request itself is fast.
 *
 * So: the last answer is kept in memory and shown immediately, and the fresh
 * one is fetched behind it. The spinner appears only when there is genuinely
 * nothing to show — the first visit of a session.
 *
 * Deliberately in memory only, cleared on sign-out. This is medical data: it
 * should not survive on the device after the person using it has gone.
 */
const cache = new Map()

export function clearCache() {
  cache.clear()
}

/**
 * @param key     stable identity for this screen's data
 * @param fetcher async () => the data to keep
 */
export function useData(key, fetcher) {
  const [state, setState] = useState(() => ({
    data: cache.get(key),
    // Only "loading" when the screen has nothing at all to draw.
    loading: !cache.has(key),
    error: null,
    refreshing: false,
  }))

  const load = useCallback(async (mode = 'focus') => {
    setState((s) => ({
      ...s,
      loading: mode === 'focus' ? !cache.has(key) : s.loading,
      refreshing: mode === 'pull',
      error: mode === 'focus' && cache.has(key) ? null : s.error,
    }))

    try {
      const data = await fetcher()
      cache.set(key, data)
      setState({ data, loading: false, error: null, refreshing: false })
    } catch (error) {
      setState((s) => ({
        ...s,
        loading: false,
        refreshing: false,
        // A failed refresh must not wipe what is on screen: yesterday's
        // appointment list is more use than an error page.
        error: s.data ? null : error,
        staleError: s.data ? error : null,
      }))
    }
  }, [key, fetcher])

  useFocusEffect(useCallback(() => { load('focus') }, [load]))

  return {
    ...state,
    reload: () => load('focus'),
    refresh: () => load('pull'),
    /** Write straight into the cache after a local change, with no round trip. */
    set: (data) => { cache.set(key, data); setState((s) => ({ ...s, data })) },
  }
}
