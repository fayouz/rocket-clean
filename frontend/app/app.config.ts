/**
 * Identity of the application and its own menu entries: the rest of the interface (layout, dashboard,
 * administration pages) is shared by every Rocket application.
 */
export default defineAppConfig({
  ui: {
    colors: {
      primary: 'emerald',
      neutral: 'slate',
    },
  },
  rocket: {
    id: 'clean',
    name: 'Rocket Clean',
    icon: 'i-lucide-sparkles',
    // Login page subtitle.
    tagline: 'Les ménages de tes lieux : planning, checklist, photos et stock, depuis un téléphone.',
    // Public pages (no account): a cleaning by its secret link.
    publicPaths: ['/m/'],
    // Main menu: the domain pages ("label" entries start a group).
    navigation: [
      { label: 'Ménage', type: 'label' },
      { label: 'Ménages du jour', icon: 'i-lucide-sparkles', to: '/menage' },
      { label: 'Lieux', icon: 'i-lucide-map-pin', to: '/places' },
      { label: 'Linge', type: 'label' },
      { label: 'Vue d’ensemble', icon: 'i-lucide-shirt', to: '/linge', exact: true },
      { label: 'Kits et besoins', icon: 'i-lucide-layers', to: '/linge/kits' },
      { label: 'Lavages', icon: 'i-lucide-washing-machine', to: '/linge/lavages' },
      { label: 'Blanchisserie', icon: 'i-lucide-truck', to: '/linge/blanchisserie' },
    ] as { label: string, icon?: string, to?: string, type?: 'label', exact?: boolean, exactQuery?: boolean, admin?: boolean }[],
    // Extra entries of the Administration menu.
    adminNavigation: [
    ] as { label: string, icon: string, to: string, exactQuery?: boolean }[],
    // "Services & raccourcis" of the dashboard, besides the documentation, changelog and API.
    shortcuts: [] as { label: string, description: string, icon: string, to: string, admin?: boolean }[],
    // Hero banner of the dashboard: one quote per day.
    quotes: [
      ['Un lieu bien tenu se remarque à peine ; c’est justement le but.', 'Adage d’intendance'],
      ['Une checklist courte vaut mieux qu’une mémoire longue.', 'Adage de ménage'],
      ['Ce qui n’est pas noté n’est pas fait.', 'Adage de gestion'],
    ] as [string, string][],
  },
})
