/**
 * Pictogrammes de l'application (charte Ramy, lot 7) : traits de 1,75 px dans
 * un carré de 24, couleur du texte. Toujours décoratifs : le libellé voisin
 * porte le sens (aria-hidden).
 */
const PATHS = {
  home: 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
  grid: 'M5 3.5h4a1.5 1.5 0 0 1 1.5 1.5v4A1.5 1.5 0 0 1 9 10.5H5A1.5 1.5 0 0 1 3.5 9V5A1.5 1.5 0 0 1 5 3.5zM15 3.5h4A1.5 1.5 0 0 1 20.5 5v4a1.5 1.5 0 0 1-1.5 1.5h-4A1.5 1.5 0 0 1 13.5 9V5A1.5 1.5 0 0 1 15 3.5zM5 13.5h4a1.5 1.5 0 0 1 1.5 1.5v4A1.5 1.5 0 0 1 9 20.5H5A1.5 1.5 0 0 1 3.5 19v-4A1.5 1.5 0 0 1 5 13.5zM15 13.5h4a1.5 1.5 0 0 1 1.5 1.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a1.5 1.5 0 0 1-1.5-1.5v-4a1.5 1.5 0 0 1 1.5-1.5z',
  bag: 'M5 8h14l-1 12H6zM9 8V6a3 3 0 0 1 6 0v2',
  list: 'M9 6h11M9 12h11M9 18h11M4 6h1M4 12h1M4 18h1',
  user: 'M12 4a4 4 0 1 1 0 8 4 4 0 0 1 0-8zM4 21c1.5-4 4.5-6 8-6s6.5 2 8 6',
  users: 'M9 4.5a3.5 3.5 0 1 1 0 7 3.5 3.5 0 0 1 0-7zM2.5 20c1-3.5 3.5-5.5 6.5-5.5s5.5 2 6.5 5.5M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.8c1.7.7 2.9 2.4 3.5 5.2',
  bell: 'M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15zM10 20.5a2 2 0 0 0 4 0',
  sun: 'M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8zM12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4',
  gauge: 'M4 20V10M10 20V4M16 20v-7M21 20H3',
  split: 'M12 21v-7M12 14 6 8M12 14l6-6M6 8V3.5M18 8V3.5M3.5 6 6 3.5 8.5 6M15.5 6 18 3.5 20.5 6',
  calendar: 'M5.5 5h13a2 2 0 0 1 2 2v11.5a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2zM3.5 10h17M8 3v4M16 3v4',
  map: 'M9 4 3.5 6v14L9 18l6 2 5.5-2V4L15 6zM9 4v14M15 6v14',
  box: 'M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5zM3.5 7.5 12 12l8.5-4.5M12 12v9',
  upload: 'M12 15V4M7.5 8.5 12 4l4.5 4.5M4 15v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4',
  search: 'M11 4.5a6.5 6.5 0 1 1 0 13 6.5 6.5 0 0 1 0-13zM16 16l4.5 4.5',
  phone: 'M5 3.5h3.5l1.5 4.5-2 1.5a12 12 0 0 0 6.5 6.5l1.5-2 4.5 1.5V19a1.5 1.5 0 0 1-1.5 1.5A16.5 16.5 0 0 1 3.5 5 1.5 1.5 0 0 1 5 3.5z',
  x: 'M6 6l12 12M18 6 6 18',
  check: 'm4.5 12.5 5 5 10-11',
  alert: 'M12 3.5 2.5 20h19zM12 10v4.5M12 17.2v.3',
  info: 'M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18zM12 11v5.5M12 7.8v.3',
  left: 'M15 5l-7 7 7 7',
  right: 'm9 5 7 7-7 7',
  truck: 'M3 6.5h11v10H3zM14 10h4l3 3.5v3h-7M7 16.2a1.8 1.8 0 1 1 0 3.6 1.8 1.8 0 0 1 0-3.6zM17.5 16.2a1.8 1.8 0 1 1 0 3.6 1.8 1.8 0 0 1 0-3.6z',
  doc: 'M6 3h8l4 4v14H6zM14 3v4h4M9 12h6M9 16h6',
  menu: 'M4 7h16M4 12h16M4 17h16',
  clock: 'M12 3.5a8.5 8.5 0 1 1 0 17 8.5 8.5 0 0 1 0-17zM12 7.5V12l3 2',
  settings: 'M12 9a3 3 0 1 1 0 6 3 3 0 0 1 0-6zM19 12l2-1.2-2-3.5-2.3.6a7 7 0 0 0-1.7-1L14.5 4.5h-5L9 6.9a7 7 0 0 0-1.7 1L5 7.3l-2 3.5L5 12l-2 1.2 2 3.5 2.3-.6a7 7 0 0 0 1.7 1l.5 2.4h5l.5-2.4a7 7 0 0 0 1.7-1l2.3.6 2-3.5z',
  shield: 'M12 3 4.5 6v5.5c0 4.5 3 8 7.5 9.5 4.5-1.5 7.5-5 7.5-9.5V6zM9 12l2 2 4-4',
  logout: 'M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4M15 16l4-4-4-4M19 12H9',
} as const

export type IconName = keyof typeof PATHS

export function Icon({ name, className = 'size-5' }: { name: IconName; className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      aria-hidden="true"
      focusable="false"
      className={`shrink-0 ${className}`}
      fill="none"
      stroke="currentColor"
      strokeWidth="1.75"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d={PATHS[name]} />
    </svg>
  )
}
