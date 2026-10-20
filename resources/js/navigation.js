/**
 * Navegación del shell en UNA estructura de datos. Para añadir un módulo nuevo (Inbox, SmartLinks, Ads...) basta
 * una entrada en MODULES; para añadir una pantalla a un módulo existente, una entrada en su `side`.
 *
 * Módulo:   { key, label, icon, match: [nombres de ruta], href(ctx), side: [entrada...] }
 * Entrada:  { key, label, icon, match: [nombres de ruta], href(ctx), when?(ctx), isActive?(ctx) }
 *           o { type: 'networks' } (una entrada por red con avatar o «+»; dinámica según las cuentas)
 *
 * `ctx` = { brand, q (query de marca), shell, page, route, is(...names) }. `match` acepta comodines de Ziggy.
 */
export const MODULES = [
    {
        key: 'analytics',
        label: 'Analítica',
        icon: 'chart',
        match: ['dashboard', 'dashboard.network'],
        href: (c) => c.route('dashboard', c.q),
        side: [
            { key: 'summary', label: 'Resumen', icon: 'grid', match: ['dashboard'], href: (c) => c.route('dashboard', c.q) },
            { type: 'networks' },
        ],
    },
    {
        key: 'planning',
        label: 'Planificación',
        icon: 'calendar',
        match: ['calendar.*', 'posts.*', 'brands.posts.*'],
        href: (c) => c.route('calendar.index', c.q),
        side: [
            { key: 'calendar', label: 'Calendario', icon: 'calendar', match: ['calendar.*'], isActive: (c) => c.page.props.view !== 'list', href: (c) => c.route('calendar.index', { ...c.q, view: 'week' }) },
            { key: 'list', label: 'Lista', icon: 'list', match: ['calendar.*'], isActive: (c) => c.page.props.view === 'list', href: (c) => c.route('calendar.index', { ...c.q, view: 'list' }) },
            { key: 'create', label: 'Crear publicación', icon: 'plus', match: ['brands.posts.*', 'posts.*'], when: (c) => !!c.brand, href: (c) => c.route('brands.posts.create', c.brand.id) },
        ],
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
        icon: 'link',
        match: ['brands.connections.*'],
        href: (c) => (c.brand ? c.route('brands.connections.index', c.brand.id) : c.route('brands.index')),
        side: [
            { key: 'connections', label: 'Conexiones de la marca', icon: 'link', match: ['brands.connections.*'], when: (c) => !!c.brand, href: (c) => c.route('brands.connections.index', c.brand.id) },
            { key: 'brands', label: 'Gestionar marcas', icon: 'tag', match: [], href: (c) => c.route('brands.index') },
        ],
    },
];

/** Módulo contextual cuando ninguno de los de la barra superior coincide (no aparece arriba). */
export const ACCOUNT_MODULE = {
    key: 'account',
    label: 'Cuenta',
    icon: 'user',
    match: [],
    side: [
        { key: 'brands', label: 'Marcas', icon: 'tag', match: ['brands.*'], href: (c) => c.route('brands.index') },
        { key: 'notifications', label: 'Avisos', icon: 'bell', match: ['notifications.*'], href: (c) => c.route('notifications.index') },
        { key: 'api-keys', label: 'API keys', icon: 'key', match: ['api-keys.*'], href: (c) => c.route('api-keys.index') },
        { key: 'profile', label: 'Perfil', icon: 'user', match: ['profile.*'], href: (c) => c.route('profile.edit') },
    ],
};

export const activeModule = (c) => MODULES.find((m) => c.is(...m.match)) ?? ACCOUNT_MODULE;

/** Entradas de la barra lateral del módulo, ya resueltas (href, activa, avatar de red...). */
export const sideItems = (module, c) => {
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
        out.push({
            key: e.key,
            label: e.label,
            icon: e.icon,
            href: e.href(c),
            active: c.is(...e.match) && (e.isActive ? e.isActive(c) : true),
        });
    }
    return out;
};
