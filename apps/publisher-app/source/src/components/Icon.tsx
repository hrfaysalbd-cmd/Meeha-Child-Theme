// Minimal inline Tabler-style outline icon set — same approach as the
// correspondent app's icon file, extended with the glyphs a business
// dashboard's sidebar/tables need.
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

export const IconDashboard = (p: Props) => (
  <svg {...base} {...p}><path d="M4 4h6v8h-6z" /><path d="M14 4h6v4h-6z" /><path d="M14 12h6v8h-6z" /><path d="M4 16h6v4h-6z" /></svg>
)
export const IconInbox = (p: Props) => (
  <svg {...base} {...p}><path d="M4 4m0 2a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z" /><path d="M4 13h3.5l2 2h5l2 -2h3.5" /></svg>
)
export const IconUsers = (p: Props) => (
  <svg {...base} {...p}><path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /><path d="M21 21v-2a4 4 0 0 0 -3 -3.85" /></svg>
)
export const IconFileCheck = (p: Props) => (
  <svg {...base} {...p}><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M9 15l2 2l4 -4" /></svg>
)
export const IconWalletCoins = (p: Props) => (
  <svg {...base} {...p}><path d="M17 8v-3a1 1 0 0 0 -1 -1h-10a2 2 0 0 0 0 4h12a1 1 0 0 1 1 1v3m0 4v3a1 1 0 0 1 -1 1h-12a2 2 0 0 1 -2 -2v-12" /><path d="M20 12v4h-4a2 2 0 0 1 0 -4h4" /></svg>
)
export const IconBuildingStore = (p: Props) => (
  <svg {...base} {...p}><path d="M3 21l18 0" /><path d="M3 7v1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1l-2 -4h-14z" /><path d="M5 21l0 -10.15" /><path d="M19 21l0 -10.15" /><path d="M9 21v-4a3 3 0 0 1 6 0v4" /></svg>
)
export const IconShieldSearch = (p: Props) => (
  <svg {...base} {...p}><path d="M11.5 20.6c-.6 .4 -1.4 .4 -2 0c-2.9 -1.7 -6.5 -5.6 -6.5 -10.6v-5l7 -3l7 3v5c0 .3 0 .6 0 .9" /><path d="M18 18m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /><path d="M20.2 20.2l1.8 1.8" /></svg>
)
export const IconBan = (p: Props) => (
  <svg {...base} {...p}><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M5.7 5.7l12.6 12.6" /></svg>
)
export const IconUserShield = (p: Props) => (
  <svg {...base} {...p}><path d="M6 21v-2a4 4 0 0 1 4 -4h2.5" /><path d="M9 7a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /><path d="M16.5 20.25c2.5 -.5 3.5 -2.5 3.5 -4.25v-1.5l-3.5 -1.5l-3.5 1.5v1.5c0 1.75 1 3.75 3.5 4.25z" /></svg>
)
export const IconSettings = (p: Props) => (
  <svg {...base} {...p}><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z" /><path d="M9 12a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /></svg>
)
export const IconLogout = (p: Props) => (
  <svg {...base} {...p}><path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2" /><path d="M9 12h12l-3 -3" /><path d="M18 15l3 -3" /></svg>
)
export const IconCheck = (p: Props) => (
  <svg {...base} {...p}><path d="M5 12l5 5l10 -10" /></svg>
)
export const IconX = (p: Props) => (
  <svg {...base} {...p}><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
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
export const IconPlus = (p: Props) => (
  <svg {...base} {...p}><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
)
export const IconChevronDown = (p: Props) => (
  <svg {...base} {...p}><path d="M6 9l6 6l6 -6" /></svg>
)
export const IconMenu2 = (p: Props) => (
  <svg {...base} {...p}><path d="M4 6l16 0" /><path d="M4 12l16 0" /><path d="M4 18l16 0" /></svg>
)
export const IconAlertTriangle = (p: Props) => (
  <svg {...base} {...p}><path d="M12 9v2m0 4v.01" /><path d="M5.07 19h13.86a2 2 0 0 0 1.75 -2.75l-6.93 -12a2 2 0 0 0 -3.5 0l-6.93 12a2 2 0 0 0 1.75 2.75" /></svg>
)
export const IconGavel = (p: Props) => (
  <svg {...base} {...p}><path d="M13 10l-7.5 7.5" /><path d="M8 7l9 9" /><path d="M14.5 4.5l5 5" /><path d="M3 21h7" /><path d="M6.5 10.5l3 3" /><path d="M16 8l3 -3" /></svg>
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
export const IconPencil = (p: Props) => (
  <svg {...base} {...p}><path d="M7 7h-1a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-1" /><path d="M20.385 6.585a2.1 2.1 0 0 0 -2.97 -2.97l-8.415 8.385v3h3z" /><path d="M16 5l3 3" /></svg>
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
export const IconListDetails = (p: Props) => (
  <svg {...base} {...p}><path d="M13 5h8" /><path d="M13 9h5" /><path d="M13 15h8" /><path d="M13 19h5" /><path d="M3 4m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z" /><path d="M3 14m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z" /></svg>
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
export const IconDotsVertical = (p: Props) => (
  <svg {...base} {...p}><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /><path d="M12 19m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /><path d="M12 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /></svg>
)

// Round 9 follow-up 5 — media gallery
export const IconLibraryPhoto = (p: Props) => (
  <svg {...base} {...p}><path d="M7 3m0 2.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667z" /><path d="M4.012 7.26a2.005 2.005 0 0 0 -1.012 1.737v10c0 1.1 .9 2 2 2h10c.75 0 1.158 -.385 1.5 -1" /><path d="M17 7h.01" /><path d="M7 13l3.644 -3.644a1.21 1.21 0 0 1 1.712 0l3.644 3.644" /><path d="M15 12l1.644 -1.644a1.21 1.21 0 0 1 1.712 0l2.644 2.644" /></svg>
)

// Round 10 — post management + video news
export const IconVideo = (p: Props) => (
  <svg {...base} {...p}><path d="M15 10l4.553 -2.276a1 1 0 0 1 1.447 .894v6.764a1 1 0 0 1 -1.447 .894l-4.553 -2.276v-4z" /><path d="M3 6m0 2a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z" /></svg>
)
export const IconTrash = (p: Props) => (
  <svg {...base} {...p}><path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /></svg>
)
export const IconLock = (p: Props) => (
  <svg {...base} {...p}><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z" /><path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /><path d="M8 11v-4a4 4 0 1 1 8 0v4" /></svg>
)
export const IconCalendarClock = (p: Props) => (
  <svg {...base} {...p}><path d="M10.5 21h-4.5a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v3" /><path d="M16 3v4" /><path d="M8 3v4" /><path d="M4 11h10" /><path d="M18 18m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /><path d="M18 16.5v1.5l.5 .5" /></svg>
)
export const IconUpload = (p: Props) => (
  <svg {...base} {...p}><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 9l5 -5l5 5" /><path d="M12 4l0 12" /></svg>
)
export const IconBrandYoutube = (p: Props) => (
  <svg {...base} {...p}><path d="M2 8a4 4 0 0 1 4 -4h12a4 4 0 0 1 4 4v8a4 4 0 0 1 -4 4h-12a4 4 0 0 1 -4 -4v-8z" /><path d="M10 9l5 3l-5 3z" /></svg>
)
export const IconSearch = (p: Props) => (
  <svg {...base} {...p}><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
)
export const IconPlayerPlay = (p: Props) => (
  <svg {...base} {...p}><path d="M7 4v16l13 -8z" /></svg>
)
export const IconExternalLink = (p: Props) => (
  <svg {...base} {...p}><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" /></svg>
)
export const IconSelector = (p: Props) => (
  <svg {...base} {...p}><path d="M8 9l4 -4l4 4" /><path d="M16 15l-4 4l-4 -4" /></svg>
)
export const IconRectangleVertical = (p: Props) => (
  <svg {...base} {...p}><path d="M7 3m0 2a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z" /></svg>
)
export const IconRectangleHorizontal = (p: Props) => (
  <svg {...base} {...p}><path d="M3 7m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z" /></svg>
)
export const IconFrame = (p: Props) => (
  <svg {...base} {...p}><path d="M4 7l16 0" /><path d="M4 17l16 0" /><path d="M7 4l0 16" /><path d="M17 4l0 16" /></svg>
)
// Round 12 — rich editor glyphs (Tabler outline).
export const IconH3 = (p: Props) => (
  <svg {...base} {...p}><path d="M19 14a2 2 0 1 0 -2 -2" /><path d="M17 16a2 2 0 1 0 2 -2" /><path d="M4 6v12" /><path d="M12 6v12" /><path d="M11 18h2" /><path d="M3 18h2" /><path d="M4 12h8" /><path d="M3 6h2" /><path d="M11 6h2" /></svg>
)
export const IconLink = (p: Props) => (
  <svg {...base} {...p}><path d="M9 15l6 -6" /><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" /><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" /></svg>
)
export const IconUnlink = (p: Props) => (
  <svg {...base} {...p}><path d="M17 22v-2" /><path d="M9 15l6 -6" /><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" /><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" /><path d="M20 17h2" /><path d="M2 7h2" /><path d="M7 2v2" /></svg>
)
export const IconHighlight = (p: Props) => (
  <svg {...base} {...p}><path d="M3 19h4l10.5 -10.5a2.828 2.828 0 1 0 -4 -4l-10.5 10.5v4" /><path d="M12.5 5.5l4 4" /><path d="M4.5 13.5l4 4" /><path d="M21 15v4h-8l4 -4z" /></svg>
)
export const IconQuote = (p: Props) => (
  <svg {...base} {...p}><path d="M10 11h-4a1 1 0 0 1 -1 -1v-3a1 1 0 0 1 1 -1h3a1 1 0 0 1 1 1v6c0 2.667 -1.333 4.333 -4 5" /><path d="M19 11h-4a1 1 0 0 1 -1 -1v-3a1 1 0 0 1 1 -1h3a1 1 0 0 1 1 1v6c0 2.667 -1.333 4.333 -4 5" /></svg>
)
export const IconHighlightBox = (p: Props) => (
  <svg {...base} {...p}><path d="M4 4h16v16h-16z" /><path d="M4 4v16" strokeWidth={4} /><path d="M9 9h7" /><path d="M9 13h7" /><path d="M9 17h4" /></svg>
)
export const IconSeparator = (p: Props) => (
  <svg {...base} {...p}><path d="M3 12l18 0" /><path d="M8 8l4 -4l4 4" /><path d="M16 16l-4 4l-4 -4" /></svg>
)
export const IconKeyboardHide = (p: Props) => (
  <svg {...base} {...p}><path d="M2 3m0 2a2 2 0 0 1 2 -2h16a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-16a2 2 0 0 1 -2 -2z" /><path d="M6 7l0 .01" /><path d="M10 7l0 .01" /><path d="M14 7l0 .01" /><path d="M18 7l0 .01" /><path d="M6 11l0 .01" /><path d="M18 11l0 .01" /><path d="M10 11l4 0" /><path d="M10 21l2 -2l2 2" /></svg>
)
export const IconChevronRight = (p: Props) => (
  <svg {...base} {...p}><path d="M9 6l6 6l-6 6" /></svg>
)
export const IconArrowLeft = (p: Props) => (
  <svg {...base} {...p}><path d="M5 12l14 0" /><path d="M5 12l6 6" /><path d="M5 12l6 -6" /></svg>
)
// Round 20 — analytics dashboard glyphs.
export const IconDeviceMobile = (p: Props) => (
  <svg {...base} {...p}><path d="M6 5a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2v-14z" /><path d="M11 4h2" /><path d="M12 17v.01" /></svg>
)
export const IconDeviceDesktop = (p: Props) => (
  <svg {...base} {...p}><path d="M3 5a1 1 0 0 1 1 -1h16a1 1 0 0 1 1 1v10a1 1 0 0 1 -1 1h-16a1 1 0 0 1 -1 -1v-10z" /><path d="M7 20h10" /><path d="M9 16v4" /><path d="M15 16v4" /></svg>
)
export const IconNews = (p: Props) => (
  <svg {...base} {...p}><path d="M16 6h3a1 1 0 0 1 1 1v11a2 2 0 0 1 -4 0v-13a1 1 0 0 0 -1 -1h-10a1 1 0 0 0 -1 1v12a3 3 0 0 0 3 3h11" /><path d="M8 8l4 0" /><path d="M8 12l4 0" /><path d="M8 16l4 0" /></svg>
)
export const IconTrendingUp = (p: Props) => (
  <svg {...base} {...p}><path d="M3 17l6 -6l4 4l8 -8" /><path d="M14 7l7 0l0 7" /></svg>
)
export const IconClock = (p: Props) => (
  <svg {...base} {...p}><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M12 7v5l3 3" /></svg>
)
// Round 21 — video editor
export const IconPlayerPause = (p: Props) => (
  <svg {...base} {...p}><path d="M6 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z" /><path d="M14 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z" /></svg>
)
export const IconWand = (p: Props) => (
  <svg {...base} {...p}><path d="M6 21l15 -15l-3 -3l-15 15l3 3" /><path d="M15 6l3 3" /><path d="M9 3a2 2 0 0 0 2 2a2 2 0 0 0 -2 2a2 2 0 0 0 -2 -2a2 2 0 0 0 2 -2" /><path d="M19 13a2 2 0 0 0 2 2a2 2 0 0 0 -2 2a2 2 0 0 0 -2 -2a2 2 0 0 0 2 -2" /></svg>
)
export const IconLayoutRows = (p: Props) => (
  <svg {...base} {...p}><path d="M4 4m0 2a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z" /><path d="M4 8h16" /><path d="M4 16h16" /></svg>
)
export const IconBadgeLogo = (p: Props) => (
  <svg {...base} {...p}><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M8 16v-8l4 5l4 -5v8" /></svg>
)
export const IconMovie = (p: Props) => (
  <svg {...base} {...p}><path d="M4 4m0 2a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z" /><path d="M8 4l0 16" /><path d="M16 4l0 16" /><path d="M4 8l4 0" /><path d="M4 16l4 0" /><path d="M4 12l16 0" /><path d="M16 8l4 0" /><path d="M16 16l4 0" /></svg>
)
export const IconAd = (p: Props) => (
  <svg {...base} {...p}><path d="M3 5m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z" /><path d="M7 15v-4a2 2 0 0 1 4 0v4" /><path d="M7 13l4 0" /><path d="M17 9v6h-1.5a1.5 1.5 0 1 1 1.5 -1.5" /></svg>
)
export const IconTextCaption = (p: Props) => (
  <svg {...base} {...p}><path d="M3 5m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z" /><path d="M7 13h6" /><path d="M15 13h2" /><path d="M7 16h3" /><path d="M12 16h5" /></svg>
)
export const IconAspectRatio = (p: Props) => (
  <svg {...base} {...p}><path d="M3 5m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z" /><path d="M7 12v-3h3" /><path d="M17 12v3h-3" /></svg>
)
export const IconScissors = (p: Props) => (
  <svg {...base} {...p}><path d="M6 7m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /><path d="M6 17m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /><path d="M8.6 8.6l10.4 10.4" /><path d="M8.6 15.4l10.4 -10.4" /></svg>
)
export const IconVolume = (p: Props) => (
  <svg {...base} {...p}><path d="M15 8a5 5 0 0 1 0 8" /><path d="M17.7 5a9 9 0 0 1 0 14" /><path d="M6 15h-2a1 1 0 0 1 -1 -1v-4a1 1 0 0 1 1 -1h2l3.5 -4.5a.8 .8 0 0 1 1.5 .5v14a.8 .8 0 0 1 -1.5 .5l-3.5 -4.5" /></svg>
)
export const IconDeviceFloppy = (p: Props) => (
  <svg {...base} {...p}><path d="M6 4h10l4 4v10a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2" /><path d="M12 14m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M14 4l0 4l-6 0l0 -4" /></svg>
)
export const IconPlayerSkipBack = (p: Props) => (
  <svg {...base} {...p}><path d="M20 5v14l-12 -7z" /><path d="M4 5l0 14" /></svg>
)
