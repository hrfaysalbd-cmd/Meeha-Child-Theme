/** Round 25 — pictures of the house ads (the renderer draws them) for the pickers. */
import { useEffect, useState } from 'react'
import type { MeehaCardsApi } from './cards'
import type { AdChoice } from './style'

const houseCache = new Map<string, string>()

/** A data: URL picture of one house ad (the renderer draws it). */
export function useHouseThumbs(MC: MeehaCardsApi | null) {
  const [thumbs, setThumbs] = useState<Record<string, string>>(() => Object.fromEntries(houseCache))
  useEffect(() => {
    if (!MC?.drawHouseAd || !MC.houseAds) return
    let live = true
    MC.loadFonts()
      .catch(() => undefined)
      .then(() => {
        if (!live) return
        const out: Record<string, string> = {}
        for (const k of Object.keys(MC.houseAds || {})) {
          try {
            const c = MC.drawHouseAd!(k, null, 1080, 120)
            out[k] = c.toDataURL('image/png')
            houseCache.set(k, out[k])
          } catch {
            /* skip */
          }
        }
        setThumbs(out)
      })
    return () => {
      live = false
    }
  }, [MC])
  return thumbs
}

export function adThumb(c: AdChoice | null, house: Record<string, string>) {
  if (!c) return ''
  if (c.kind === 'house') return house[c.key] || ''
  if (c.kind === 'theme') return c.url
  return c.thumb || c.image
}
