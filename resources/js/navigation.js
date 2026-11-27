/**
 * Navegación del shell en UNA estructura de datos. Para añadir un módulo nuevo (Inbox, SmartLinks, Ads...) basta
 * una entrada en MODULES; para añadir una pantalla a un módulo existente, una entrada en su `side`.
 *
 * Módulo:   { key, label, icon, match: [nombres de ruta], href(ctx), when?(ctx), badge?(ctx), side: [entrada...] }
 * Entrada:  { key, label, icon, match: [nombres de ruta], href(ctx), when?(ctx), isActive?(ctx) }
 *           o { type: 'networks' } (una entrada por red con avatar o «+»; dinámica según las cuentas)
 *
 * `match` acepta comodines de Ziggy.
 * `has(name)` comprueba si la ruta conocida por Ziggy está registrada en ejecución: sirve para módulos opcionales (Autolistas, Inbox, Ads) que solo aparecen
 * cuando su ruta está registrada. Un módulo puede llevar `when(ctx)` igual que una entrada.
 */
/**
 * @typedef {object} NavigationContext
 * @property {import('./types/shell').Shell['currentBrand']} brand
 * @property {{ brand_id?: number }} q
 * @property {import('./types/shell').Shell} shell
 * @property {{ props: Pick<import('./types/shell').ShellPageProps, 'view' | 'unreadInbox'> }} page
 * @property {typeof route} route
 * @property {(...names: string[]) => boolean} is
 * @property {(name: keyof import('ziggy-js').RouteList) => boolean} has
 */

/**
 * @typedef {object} NavigationItem
 * @property {string} key
 * @property {string} label
 * @property {string} icon
 * @property {string[]} match
 */

/**
 * @typedef {NavigationItem & {
 *     type?: undefined,
 *     href: (context: NavigationContext) => string | null,
 *     when?: (context: NavigationContext) => boolean,
 *     isActive?: (context: NavigationContext) => boolean,
 * }} NavigationEntry
 */

/**
 * @typedef {NavigationItem & { side: (NavigationEntry | { type: 'networks' })[] }} NavigationGroup
 * @typedef {NavigationGroup & {
 *     href: (context: NavigationContext) => string,
 *     when?: (context: NavigationContext) => boolean,
 *     badge?: (context: NavigationContext) => number,
 * }} NavigationModule
 * @typedef {{ key: string, label: string, href: string, active: boolean }} ResolvedItem
 * @typedef {ResolvedItem & (
 *     { icon: string, network?: undefined, sub?: undefined, plus?: undefined } |
 *     { network: string, sub: string, plus: boolean }
 * )} ResolvedSideItem
 */

/** @type {NavigationModule[]} */
export const MODULES = [
    {
        key: 'studio', label: 'Estudio', icon: 'palette', match: ['studio.*'],
        when: (c) => c.has('studio.index'),
        href: (c) => c.route('studio.index', c.q),
        side: [
            { key: 'designs', label: 'Mis diseños', icon: 'grid', match: ['studio.index', 'studio.designs.*'], href: (c) => c.route('studio.index', c.q) },
            { key: 'templates', label: 'Plantillas de diseño', icon: 'bookmark', match: [], href: (c) => c.route('studio.index', { ...c.q, templates: 1 }) },
            { key: 'brand-kit', label: 'Identidad de marca', icon: 'palette', match: [], href: (c) => c.route('studio.index', { ...c.q, tab: 'brand' }) },
            { key: 'archived-designs', label: 'Papelera de diseños', icon: 'trash', match: [], href: (c) => c.route('studio.index', { ...c.q, archived: 1 }) },
        ],
    },
    {
        key: 'analytics',
        label: 'Analítica',
        icon: 'chart',
        match: ['dashboard', 'dashboard.network', 'competitors.*', 'ads.*'],
        href: (c) => c.route('dashboard', c.q),
        side: [
            { key: 'summary', label: 'Resumen', icon: 'grid', match: ['dashboard'], href: (c) => c.route('dashboard', c.q) },
            { type: 'networks' },
            { key: 'competitors', label: 'Competidores', icon: 'users', match: ['competitors.*'], when: (c) => c.has('competitors.index'), href: (c) => c.route('competitors.index', c.q) },
            { key: 'ads', label: 'Meta Ads', icon: 'megaphone', match: ['ads.*'], when: (c) => c.has('ads.index'), href: (c) => c.route('ads.index', c.q) },
        ],
    },
    {
        key: 'planning',
        label: 'Planificación',
        icon: 'calendar',
        match: ['calendar.*', 'instagram.grid', 'campaigns.*', 'posts.*', 'brands.posts.*', 'best-times.*', 'library.*', 'trash.*', 'autolists.*', 'approvals.*', 'series.*'],
        href: (c) => c.route('calendar.index', c.q),
        side: [
            { key: 'calendar', label: 'Calendario', icon: 'calendar', match: ['calendar.*'], isActive: (c) => c.page.props.view !== 'list', href: (c) => c.route('calendar.index', { ...c.q, view: 'week' }) },
            { key: 'list', label: 'Lista', icon: 'list', match: ['calendar.*'], isActive: (c) => c.page.props.view === 'list', href: (c) => c.route('calendar.index', { ...c.q, view: 'list' }) },
            { key: 'grid', label: 'Cuadrícula', icon: 'grid', match: ['instagram.grid'], when: (c) => c.has('instagram.grid'), href: (c) => c.route('instagram.grid', c.q) },
            { key: 'campaigns', label: 'Campañas', icon: 'tag', match: ['campaigns.*'], when: (c) => c.has('campaigns.index'), href: (c) => c.route('campaigns.index', c.q) },
            { key: 'create', label: 'Crear publicación', icon: 'plus', match: ['brands.posts.*', 'posts.*'], isActive: (c) => !c.is('brands.posts.import.*'), href: (c) => c.brand ? c.route('brands.posts.create', c.brand.id) : null },
            { key: 'import', label: 'Importar CSV', icon: 'doc', match: ['brands.posts.import.*'], href: (c) => c.brand ? c.route('brands.posts.import.show', c.brand.id) : null },
            { key: 'best-times', label: 'Mejores horas', icon: 'clock', match: ['best-times.*'], when: (c) => c.has('best-times.index'), href: (c) => c.route('best-times.index', c.q) },
            { key: 'library', label: 'Plantillas de publicación', icon: 'folder', match: ['library.*'], when: (c) => c.has('library.index'), href: (c) => c.route('library.index', c.q) },
            { key: 'autolists', label: 'Autolistas', icon: 'repeat', match: ['autolists.*'], isActive: (c) => !c.is('autolists.recycle.*'), when: (c) => c.has('autolists.index'), href: (c) => c.route('autolists.index', c.q) },
            { key: 'recycle', label: 'Reciclado de posts', icon: 'repeat', match: ['autolists.recycle.*'], when: (c) => c.has('autolists.recycle.index'), href: (c) => c.route('autolists.recycle.index', c.q) },
            { key: 'approvals', label: 'Aprobaciones', icon: 'users', match: ['approvals.*'], when: (c) => c.has('approvals.index'), href: (c) => c.route('approvals.index') },
            { key: 'series', label: 'Series', icon: 'repeat', match: ['series.*'], when: (c) => c.has('series.index'), href: (c) => c.route('series.index') },
            { key: 'trash', label: 'Papelera de publicaciones', icon: 'trash', match: ['trash.*'], when: (c) => c.has('trash.index'), href: (c) => c.route('trash.index') },
        ],
    },
    {
        key: 'smartlinks',
        label: 'SmartLinks',
        icon: 'link',
        match: ['smartlinks.index', 'smartlinks.save'],
        when: (c) => c.has('smartlinks.index'),
        href: (c) => c.route('smartlinks.index', c.q),
        side: [{ key: 'smartlinks', label: 'Mi página de enlaces', icon: 'link', match: ['smartlinks.*'], href: (c) => c.route('smartlinks.index', c.q) }],
    },
    {
        key: 'sites',
        label: 'Sitios',
        icon: 'folder',
        match: ['sites.*'],
        when: (c) => c.has('sites.index'),
        href: (c) => c.route('sites.index', c.q),
        side: [{ key: 'sites', label: 'Mis sitios', icon: 'folder', match: ['sites.*'], href: (c) => c.route('sites.index', c.q) }],
    },
    {
        key: 'inbox',
        label: 'Inbox',
        icon: 'inbox',
        match: ['inbox.*'],
        badge: (c) => c.page.props.unreadInbox ?? 0,
        when: (c) => c.has('inbox.index'),
        href: (c) => c.route('inbox.index', c.q),
        side: [{ key: 'inbox', label: 'Bandeja de entrada', icon: 'inbox', match: ['inbox.*'], href: (c) => c.route('inbox.index', c.q) }],
    },
    {
        key: 'reports',
        label: 'Informes',
        icon: 'doc',
        match: ['reports.*'],
        href: (c) => c.route('reports.index', c.q),
        side: [{ key: 'reports', label: 'Informes', icon: 'doc', match: ['reports.*'], href: (c) => c.route('reports.index', c.q) }],
    },
    {
        key: 'connections',
        label: 'Conexiones',
        icon: 'plug',
        match: ['brands.connections.*'],
        href: (c) => (c.brand ? c.route('brands.connections.index', c.brand.id) : c.route('brands.index')),
        side: [
            { key: 'connections', label: 'Conexiones de la marca', icon: 'plug', match: ['brands.connections.*'], href: (c) => c.brand ? c.route('brands.connections.index', c.brand.id) : null },
            { key: 'brands', label: 'Gestionar marcas', icon: 'tag', match: [], href: (c) => c.route('brands.index') },
        ],
    },
];

/**
 * Módulo contextual cuando ninguno de los de la barra superior coincide (no aparece arriba).
 * @type {NavigationGroup}
 */
export const ACCOUNT_MODULE = {
    key: 'account',
    label: 'Cuenta',
    icon: 'user',
    match: [],
    side: [
        { key: 'brands', label: 'Marcas', icon: 'tag', match: ['brands.*'], href: (c) => c.route('brands.index') },
        { key: 'notifications', label: 'Avisos', icon: 'bell', match: ['notifications.*'], href: (c) => c.route('notifications.index') },
        { key: 'activity', label: 'Actividad', icon: 'clock', match: ['activity.*'], when: (c) => c.has('activity.index'), href: (c) => c.route('activity.index') },
        { key: 'api-keys', label: 'API keys', icon: 'key', match: ['api-keys.*'], href: (c) => c.route('api-keys.index') },
        { key: 'profile', label: 'Perfil', icon: 'user', match: ['profile.*'], href: (c) => c.route('profile.edit') },
    ],
};

/**
 * Módulos visibles en la barra superior (los opcionales aparecen solo si su ruta existe).
 * @param {NavigationContext} c
 */
export const visibleModules = (c) => MODULES.filter((m) => !m.when || m.when(c));

/** @param {NavigationContext} c */
export const activeModule = (c) => visibleModules(c).find((m) => c.is(...m.match)) ?? ACCOUNT_MODULE;

/**
 * Entradas de la barra lateral del módulo, ya resueltas (href, activa, avatar de red...).
 * @param {NavigationGroup} module
 * @param {NavigationContext} c
 */
export const sideItems = (module, c) => {
    /** @type {ResolvedSideItem[]} */
    const out = [];
    for (const e of module.side) {
        if (e.type === 'networks') {
            for (const n of c.shell.networks) {
                const accs = c.shell.accounts[n.value] ?? [];
                out.push({
                    key: n.value,
                    network: n.value,
                    label: n.label,
                    href: c.route('dashboard.network', { network: n.value, ...c.q }),
                    active: c.is('dashboard.network') && c.route().params.network === n.value,
                    sub: accs[0] ? (accs[0].username ? `@${accs[0].username}` : accs[0].name) : 'Conectar',
                    plus: accs.length === 0,
                });
            }
            continue;
        }
        if (e.when && !e.when(c)) continue;
        const href = e.href(c);
        if (href === null) continue;
        out.push({
            key: e.key,
            label: e.label,
            icon: e.icon,
            href,
            active: c.is(...e.match) && (e.isActive ? e.isActive(c) : true),
        });
    }
    return out;
};
