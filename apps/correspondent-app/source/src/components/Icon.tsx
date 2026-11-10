// Minimal inline Tabler-style outline icon set — keeps the bundle tiny and
// avoids an extra icon-font dependency for a handful of glyphs.
import type { SVGProps } from 'react'

const base = {
  xmlns: 'http://www.w3.org/2000/svg',
  width: 24,
  height: 24,
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 2,
  strokeLinecap: 'round' as const,
  strokeLinejoin: 'round' as const,
}

type Props = SVGProps<SVGSVGElement>

export const IconHome = (p: Props) => (
  <svg {...base} {...p}><path d="M5 12l-2 0l9 -9l9 9l-2 0" /><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7" /><path d="M9 21v-6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6" /></svg>
)
export const IconEdit = (p: Props) => (
  <svg {...base} {...p}><path d="M7 7h-1a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-1" /><path d="M20.385 6.585a2.1 2.1 0 0 0 -2.97 -2.97l-8.415 8.385v3h3z" /><path d="M16 5l3 3" /></svg>
)
export const IconNews = (p: Props) => (
  <svg {...base} {...p}><path d="M16 6h3a1 1 0 0 1 1 1v11a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a1 1 0 0 1 1 -1h3" /><path d="M8 4h8v4h-8z" /><path d="M4 16h3" /><path d="M4 12h3" /><path d="M10 12h10" /><path d="M10 16h10" /></svg>
)
export const IconWallet = (p: Props) => (
  <svg {...base} {...p}><path d="M17 8v-3a1 1 0 0 0 -1 -1h-10a2 2 0 0 0 0 4h12a1 1 0 0 1 1 1v3m0 4v3a1 1 0 0 1 -1 1h-12a2 2 0 0 1 -2 -2v-12" /><path d="M20 12v4h-4a2 2 0 0 1 0 -4h4" /></svg>
)
export const IconUser = (p: Props) => (
  <svg {...base} {...p}><path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" /><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" /></svg>
)
export const IconArrowLeft = (p: Props) => (
  <svg {...base} {...p}><path d="M5 12l14 0" /><path d="M5 12l6 6" /><path d="M5 12l6 -6" /></svg>
)
export const IconUpload = (p: Props) => (
  <svg {...base} {...p}><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 9l5 -5l5 5" /><path d="M12 4l0 12" /></svg>
)
export const IconCheck = (p: Props) => (
  <svg {...base} {...p}><path d="M5 12l5 5l10 -10" /></svg>
)
export const IconX = (p: Props) => (
  <svg {...base} {...p}><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
)
export const IconChevronDown = (p: Props) => (
  <svg {...base} {...p}><path d="M6 9l6 6l6 -6" /></svg>
)
export const IconEye = (p: Props) => (
  <svg {...base} {...p}><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>
)
export const IconHeart = (p: Props) => (
  <svg {...base} {...p}><path d="M19.5 12.572l-7.5 7.428l-7.5 -7.428a5 5 0 1 1 7.5 -6.566a5 5 0 1 1 7.5 6.572" /></svg>
)
export const IconMessage = (p: Props) => (
  <svg {...base} {...p}><path d="M8 9h8" /><path d="M8 13h6" /><path d="M9 18h-3a3 3 0 0 1 -3 -3v-8a3 3 0 0 1 3 -3h10a3 3 0 0 1 3 3v3.5" /><path d="M15 18l5 3v-6" /></svg>
)
export const IconShare = (p: Props) => (
  <svg {...base} {...p}><path d="M6 12m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M18 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M18 18m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M8.7 10.7l6.6 -3.4" /><path d="M8.7 13.3l6.6 3.4" /></svg>
)
export const IconLogout = (p: Props) => (
  <svg {...base} {...p}><path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2" /><path d="M9 12h12l-3 -3" /><path d="M18 15l3 -3" /></svg>
)
export const IconPlus = (p: Props) => (
  <svg {...base} {...p}><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
)
export const IconClock = (p: Props) => (
  <svg {...base} {...p}><path d="M12 7v5l3 3" /><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /></svg>
)
export const IconBold = (p: Props) => (
  <svg {...base} {...p}><path d="M7 5h6a3.5 3.5 0 0 1 0 7h-6z" /><path d="M13 12h1a3.5 3.5 0 0 1 0 7h-7v-7" /></svg>
)
export const IconItalic = (p: Props) => (
  <svg {...base} {...p}><path d="M11 5l6 0" /><path d="M7 19l6 0" /><path d="M14 5l-4 14" /></svg>
)
export const IconH2 = (p: Props) => (
  <svg {...base} {...p}><path d="M4 6v12" /><path d="M12 6v12" /><path d="M4 12h8" /><path d="M20 18v-6a2 2 0 1 0 -4 0" /><path d="M16 18h4" /></svg>
)
export const IconListBullet = (p: Props) => (
  <svg {...base} {...p}><path d="M9 6l11 0" /><path d="M9 12l11 0" /><path d="M9 18l11 0" /><path d="M4 6l0 .01" /><path d="M4 12l0 .01" /><path d="M4 18l0 .01" /></svg>
)
export const IconListNumbers = (p: Props) => (
  <svg {...base} {...p}><path d="M11 6h9" /><path d="M11 12h9" /><path d="M12 18h8" /><path d="M4 16a2 2 0 1 1 4 0c0 .591 -.5 1 -1 1.5l-3 2.5h4" /><path d="M6 10v-6l-2 2" /></svg>
)
export const IconPhotoPlus = (p: Props) => (
  <svg {...base} {...p}><path d="M15 8h.01" /><path d="M12.5 21h-6.5a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v6.5" /><path d="M4 15l4 -4c.928 -.893 2.072 -.893 3 0l3.5 3.5" /><path d="M14 14l1 -1c.67 -.644 1.45 -.824 2.182 -.54" /><path d="M16 19h6" /><path d="M19 16v6" /></svg>
)
export const IconUndo = (p: Props) => (
  <svg {...base} {...p}><path d="M9 14l-4 -4l4 -4" /><path d="M5 10h11a4 4 0 1 1 0 8h-1" /></svg>
)
export const IconRedo = (p: Props) => (
  <svg {...base} {...p}><path d="M15 14l4 -4l-4 -4" /><path d="M19 10h-11a4 4 0 1 0 0 8h1" /></svg>
)
export const IconDownload = (p: Props) => (
  <svg {...base} {...p}><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" /></svg>
)
export const IconHistory = (p: Props) => (
  <svg {...base} {...p}><path d="M12 8l0 4l3 3" /><path d="M3.05 11a9 9 0 1 1 .5 4m-.5 -4v4h4" /></svg>
)
export const IconCoin = (p: Props) => (
  <svg {...base} {...p}><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M14.8 9a2 2 0 0 0 -1.8 -1h-2a2 2 0 0 0 0 4h2a2 2 0 0 1 0 4h-2a2 2 0 0 1 -1.8 -1" /><path d="M12 6v2m0 8v2" /></svg>
)
export const IconCamera = (p: Props) => (
  <svg {...base} {...p}><path d="M5 7h1a2 2 0 0 0 2 -2a1 1 0 0 1 1 -1h6a1 1 0 0 1 1 1a2 2 0 0 0 2 2h1a2 2 0 0 1 2 2v9a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-9a2 2 0 0 1 2 -2" /><path d="M12 13m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /></svg>
)
export const IconBuildingStore = (p: Props) => (
  <svg {...base} {...p}><path d="M3 21l18 0" /><path d="M3 7v1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1l-2 -4h-14z" /><path d="M5 21l0 -10.15" /><path d="M19 21l0 -10.15" /><path d="M9 21v-4a3 3 0 0 1 6 0v4" /></svg>
)
export const IconTrash = (p: Props) => (
  <svg {...base} {...p}><path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /></svg>
)
export const IconAlertTriangle = (p: Props) => (
  <svg {...base} {...p}><path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z" /><path d="M12 16h.01" /></svg>
)
export const IconRefresh = (p: Props) => (
  <svg {...base} {...p}><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
)
export const IconSparkles = (p: Props) => (
  <svg {...base} {...p}><path d="M16 18a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2z" /><path d="M16 7a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2z" /><path d="M9 17a5 5 0 0 1 5 -5a5 5 0 0 1 -5 -5a5 5 0 0 1 -5 5a5 5 0 0 1 5 5z" /></svg>
)
export const IconClipboard = (p: Props) => (
  <svg {...base} {...p}><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /><path d="M9 3m0 2a2 2 0 1 1 4 0a2 2 0 1 1 -4 0" /></svg>
)
export const IconFlame = (p: Props) => (
  <svg {...base} {...p}><path d="M12 12c2 -2.5 0 -5.5 -1.5 -6.5c0.5 2 -0.5 3.5 -1.5 4.5c-1 1 -2 2.5 -2 4.5a5 5 0 0 0 10 0c0 -1.5 -0.5 -3 -1.5 -4c0 2 -1.5 3 -2.5 1.5" /></svg>
)
export const IconTrophy = (p: Props) => (
  <svg {...base} {...p}><path d="M8 21l8 0" /><path d="M12 17l0 4" /><path d="M7 4l10 0" /><path d="M17 4v8a5 5 0 0 1 -10 0v-8" /><path d="M5 9a2 2 0 0 1 -2 -2v-1a1 1 0 0 1 1 -1h3" /><path d="M19 9a2 2 0 0 0 2 -2v-1a1 1 0 0 0 -1 -1h-3" /></svg>
)
export const IconTrendUp = (p: Props) => (
  <svg {...base} {...p}><path d="M3 17l6 -6l4 4l8 -8" /><path d="M14 7l7 0l0 7" /></svg>
)
export const IconTrendDown = (p: Props) => (
  <svg {...base} {...p}><path d="M3 7l6 6l4 -4l8 8" /><path d="M14 17l7 0l0 -7" /></svg>
)
export const IconTarget = (p: Props) => (
  <svg {...base} {...p}><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /><path d="M12 12m-5 0a5 5 0 1 0 10 0a5 5 0 1 0 -10 0" /><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /></svg>
)
export const IconLock = (p: Props) => (
  <svg {...base} {...p}><path d="M5 11m0 2a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2z" /><path d="M8 11v-4a4 4 0 1 1 8 0v4" /></svg>
)
export const IconVideo = (p: Props) => (
  <svg {...base} {...p}><path d="M15 10l4.553 -2.276a1 1 0 0 1 1.447 .894v6.764a1 1 0 0 1 -1.447 .894l-4.553 -2.276v-4z" /><path d="M3 8a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z" /></svg>
)
export const IconPlayerPlay = (p: Props) => (
  <svg {...base} {...p}><path d="M7 4v16l13 -8z" /></svg>
)
export const IconBrandYoutube = (p: Props) => (
  <svg {...base} {...p}><path d="M2 8a4 4 0 0 1 4 -4h12a4 4 0 0 1 4 4v8a4 4 0 0 1 -4 4h-12a4 4 0 0 1 -4 -4v-8z" /><path d="M10 9l5 3l-5 3z" /></svg>
)
export const IconLink = (p: Props) => (
  <svg {...base} {...p}><path d="M9 15l6 -6" /><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" /><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" /></svg>
)
export const IconSearch = (p: Props) => (
  <svg {...base} {...p}><path d="M3 10a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
)
export const IconRectVertical = (p: Props) => (
  <svg {...base} {...p}><path d="M6 5a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z" /></svg>
)
export const IconRectHorizontal = (p: Props) => (
  <svg {...base} {...p}><path d="M3 8a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z" /></svg>
)
export const IconExternalLink = (p: Props) => (
  <svg {...base} {...p}><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" /></svg>
)
// ---- Round 12 editor icons (Tabler outline) ----
export const IconH3 = (p: Props) => (
  <svg {...base} {...p}><path d="M19 14a2 2 0 1 0 -2 -2" /><path d="M17 16a2 2 0 1 0 2 -2" /><path d="M4 6v12" /><path d="M12 6v12" /><path d="M11 18h2" /><path d="M3 18h2" /><path d="M4 12h8" /><path d="M3 6h2" /><path d="M11 6h2" /></svg>
)
export const IconQuote = (p: Props) => (
  <svg {...base} {...p}><path d="M10 11h-4a1 1 0 0 1 -1 -1v-3a1 1 0 0 1 1 -1h3a1 1 0 0 1 1 1v6c0 2.667 -1.333 4.333 -4 5" /><path d="M19 11h-4a1 1 0 0 1 -1 -1v-3a1 1 0 0 1 1 -1h3a1 1 0 0 1 1 1v6c0 2.667 -1.333 4.333 -4 5" /></svg>
)
export const IconHighlight = (p: Props) => (
  <svg {...base} {...p}><path d="M3 19h4l10.5 -10.5a2.828 2.828 0 1 0 -4 -4l-10.5 10.5v4" /><path d="M12.5 5.5l4 4" /><path d="M4.5 13.5l4 4" /><path d="M21 15v4h-8l4 -4z" /></svg>
)
export const IconBox = (p: Props) => (
  <svg {...base} {...p}><path d="M4 5h16v14h-16z" /><path d="M4 5v14" strokeWidth={4} /><path d="M9 10h7" /><path d="M9 14h5" /></svg>
)
export const IconSeparator = (p: Props) => (
  <svg {...base} {...p}><path d="M3 12l2 0" /><path d="M9 12l6 0" /><path d="M19 12l2 0" /><path d="M5 6v-1h14v1" /><path d="M5 18v1h14v-1" /></svg>
)
export const IconLinkOff = (p: Props) => (
  <svg {...base} {...p}><path d="M9 15l3 -3m2 -2l1 -1" /><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" /><path d="M3 3l18 18" /><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" /></svg>
)
// 1.10.0 — লাইভ (broadcast)
export const IconBroadcast = (p: Props) => (
  <svg {...base} {...p}><path d="M18.364 19.364a9 9 0 1 0 -12.728 0" /><path d="M15.536 16.536a5 5 0 1 0 -7.072 0" /><path d="M12 13m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /></svg>
)
export const IconChat = (p: Props) => (
  <svg {...base} {...p}><path d="M8 9h8" /><path d="M8 13h6" /><path d="M18 4a3 3 0 0 1 3 3v8a3 3 0 0 1 -3 3h-5l-5 3v-3h-2a3 3 0 0 1 -3 -3v-8a3 3 0 0 1 3 -3h12z" /></svg>
)
