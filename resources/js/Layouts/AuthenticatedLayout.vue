<script setup>
import FlashBanner from '@/Components/FlashBanner.vue';
import BrandLogo from '@/Components/BrandLogo.vue';
import GlobalActions from '@/Components/GlobalActions.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import Icon from '@/Components/Icon.vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import { Link, router } from '@inertiajs/vue3';
import { useNavigationContext } from '@/Composables/useNavigationContext';
import { activeModule, sideItems, visibleModules } from '@/navigation';
import { computed, onMounted, ref, watch } from 'vue';

defineProps({ fullBleed: Boolean });

/**
 * Shell de la aplicación: barra superior oscura con módulos, selector de marca, avisos y menú de usuario;
 * barra lateral contextual por módulo (colapsable) y menú móvil. La marca actual y las cuentas por red llegan
 * por Inertia (`shell`), compartidas por HandleInertiaRequests.
 */
const { page, shell, brand, ctx } = useNavigationContext();
const user = computed(() => page.props.auth.user);
const unread = computed(() => page.props.unreadNotifications ?? 0);
// OAuth de varias cuentas sin confirmar: se avisa en cualquier página salvo en la propia de Conexiones de esa marca (ya muestra la tarjeta).
const pendingConnections = computed(() =>
    (page.props.pendingConnections ?? []).filter((p) => !route().current('brands.connections.index', p.brand_id)),
);

const mobileOpen = ref(false);
const collapsed = ref(false);
onMounted(() => (collapsed.value = localStorage.getItem('ss.sidebar') === 'collapsed'));
watch(collapsed, (v) => localStorage.setItem('ss.sidebar', v ? 'collapsed' : 'open'));
watch(() => page.url, () => (mobileOpen.value = false));

const current = computed(() => activeModule(ctx.value));
const activeKey = computed(() => current.value.key);
const modules = computed(() => visibleModules(ctx.value).map((m) => ({ key: m.key, label: m.label, icon: m.icon, href: m.href(ctx.value), badge: m.badge ? m.badge(ctx.value) : 0 })));
const sideItemsList = computed(() => sideItems(current.value, ctx.value));
const q = computed(() => ctx.value.q);
const moduleTitle = computed(() => current.value.label);

// ---------------------------------------------------------------- selector de marca
/** @param {number} id */
const switchBrand = (id) => {
    const name = route().current();
    if (name?.startsWith('studio.')) return router.get(route('studio.index', { brand_id: id }));
    if (name === 'brands.connections.index' || name === 'brands.posts.create') {
        return router.get(route(name, id));
    }
    const [path, qs] = page.url.split('?');
    /** @type {Record<string, string | number>} */
    const params = Object.fromEntries(new URLSearchParams(qs ?? ''));
    params.brand_id = id;
    router.get(path, params);
};

const initials = computed(() => (user.value?.name ?? '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase());
</script>

<template>
    <div class="min-h-screen bg-canvas">
        <a
            href="#contenido"
            class="sr-only z-[100] rounded bg-surface px-3 py-2 text-sm font-medium text-accent-strong focus:not-sr-only focus:fixed focus:left-2 focus:top-2"
            >Saltar al contenido</a
        >

        <!-- Barra superior -->
        <header class="sticky top-0 z-40 bg-chrome text-chrome-text shadow-md">
            <div class="flex h-14 items-center gap-2 px-3 sm:gap-3 sm:px-5">
                <button
                    type="button"
                    class="rounded-md p-2 text-chrome-muted hover:bg-chrome-hover hover:text-chrome-strong lg:hidden"
                    aria-label="Abrir menú"
                    :aria-expanded="mobileOpen"
                    @click="mobileOpen = !mobileOpen"
                >
                    <Icon :name="mobileOpen ? 'x' : 'menu'" class="h-5 w-5" />
                </button>

                <Link :href="route('dashboard', q)" class="shrink-0" aria-label="Social Scheduler, inicio"
                    ><BrandLogo dark compact
                /></Link>

                <nav class="ms-2 hidden items-center gap-1 lg:flex nav:ms-6" aria-label="Módulos">
                    <Link
                        v-for="m in modules"
                        :key="m.key"
                        :href="m.href"
                        :title="m.label"
                        class="rounded-md px-2 py-1.5 text-sm font-medium transition hover:bg-chrome-hover hover:text-chrome-strong nav:px-3"
                        :class="activeKey === m.key ? 'bg-chrome-hover text-chrome-strong shadow-chrome-active' : 'text-chrome-muted'"
                        :aria-current="activeKey === m.key ? 'page' : undefined"
                        ><Icon :name="m.icon" class="h-5 w-5 nav:hidden" /><span class="sr-only nav:not-sr-only">{{
                            m.label
                        }}</span
                        ><span
                            v-if="m.badge"
                            class="ms-1.5 rounded-full bg-danger-badge px-1.5 py-0.5 text-[10px] font-bold text-on-danger"
                            data-testid="inbox-badge"
                            >{{ m.badge > 99 ? '99+' : m.badge }}</span
                        ></Link
                    >
                </nav>

                <div class="ms-auto flex min-w-0 items-center gap-1 sm:gap-2">
                    <GlobalActions />

                    <!-- Selector de marca -->
                    <Dropdown align="right" width="72" class="min-w-0">
                        <template #trigger="{ open }">
                            <button
                                type="button"
                                class="flex max-w-[7rem] items-center gap-1.5 rounded-lg border border-chrome-border bg-chrome-strong/5 px-2 py-1.5 text-sm text-chrome-strong hover:bg-chrome-hover min-[420px]:max-w-[10rem] sm:max-w-[16rem] sm:gap-2 sm:px-2.5"
                                aria-label="Cambiar de marca"
                                :aria-expanded="open"
                                :title="brand?.name"
                                data-testid="brand-switcher"
                            >
                                <img
                                    v-if="brand?.avatar_url"
                                    :src="brand.avatar_url"
                                    alt=""
                                    class="h-6 w-6 shrink-0 rounded-md object-cover"
                                />
                                <span
                                    v-else
                                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-accent-fill text-xs font-bold text-on-accent"
                                    aria-hidden="true"
                                    >{{ (brand?.name ?? '+').slice(0, 1).toUpperCase() }}</span
                                >
                                <span class="truncate">{{ brand?.name ?? 'Sin marca' }}</span>
                                <Icon name="chevronDown" class="h-4 w-4 shrink-0 text-chrome-muted" />
                            </button>
                        </template>
                        <template #content>
                            <p
                                class="px-4 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-foreground-subtle"
                                >Tus marcas</p
                            >
                            <button
                                v-for="b in shell.brands"
                                :key="b.id"
                                type="button"
                                class="dropdown-item flex items-center gap-2"
                                @click="switchBrand(b.id)"
                            >
                                <img
                                    v-if="b.avatar_url"
                                    loading="lazy"
                                    :src="b.avatar_url"
                                    alt=""
                                    class="h-6 w-6 shrink-0 rounded-md object-cover"
                                />
                                <span
                                    v-else
                                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-accent-marker text-xs font-bold text-accent-strong"
                                    aria-hidden="true"
                                    >{{ b.name.slice(0, 1).toUpperCase() }}</span
                                >
                                <span class="min-w-0 flex-1 truncate">{{ b.name }}</span>
                                <Icon v-if="brand && brand.id === b.id" name="check" class="h-4 w-4 text-accent" />
                            </button>
                            <p v-if="shell.brands.length === 0" class="px-4 py-2 text-sm text-foreground-subtle"
                                >Aún no tienes marcas.</p
                            >
                            <div class="mt-1 border-t border-border-subtle pt-1">
                                <DropdownLink :href="route('brands.index')">Gestionar marcas</DropdownLink>
                                <DropdownLink :href="route('brands.create')">Crear marca</DropdownLink>
                            </div>
                        </template>
                    </Dropdown>

                    <!-- Avisos -->
                    <Link
                        :href="route('notifications.index')"
                        class="relative rounded-lg p-2 text-chrome-muted hover:bg-chrome-hover hover:text-chrome-strong"
                        :aria-label="unread ? `Avisos, ${unread} sin leer` : 'Avisos'"
                    >
                        <Icon name="bell" class="h-5 w-5" />
                        <span
                            v-if="unread"
                            class="absolute -end-0.5 -top-0.5 flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-danger-badge px-1 text-[10px] font-bold text-on-danger"
                            data-testid="unread-badge"
                            >{{ unread > 99 ? '99+' : unread }}</span
                        >
                    </Link>

                    <!-- Usuario -->
                    <Dropdown align="right" width="48">
                        <template #trigger="{ open }">
                            <button
                                type="button"
                                class="flex shrink-0 items-center gap-2 rounded-lg p-1 hover:bg-chrome-hover"
                                aria-label="Menú de usuario"
                                :aria-expanded="open"
                            >
                                <span
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-chrome-avatar-start to-chrome-avatar-end text-xs font-bold text-chrome-strong"
                                    >{{ initials }}</span
                                >
                            </button>
                        </template>
                        <template #content>
                            <div class="border-b border-border-subtle px-4 py-2">
                                <p class="truncate text-sm font-medium text-foreground-strong">{{ user.name }}</p>
                                <p class="truncate text-xs text-foreground-subtle">{{ user.email }}</p>
                            </div>
                            <DropdownLink :href="route('profile.edit')">Perfil</DropdownLink>
                            <DropdownLink :href="route('saved-texts.index')">Textos guardados</DropdownLink>
                            <DropdownLink :href="route('api-keys.index')">API keys</DropdownLink>
                            <DropdownLink :href="route('notifications.index')">Avisos</DropdownLink>
                            <DropdownLink :href="route('profile.notifications')"
                                >Notificaciones del navegador</DropdownLink
                            >
                            <DropdownLink :href="route('profile.export')">Exportar mis datos</DropdownLink>
                            <DropdownLink :href="route('activity.index')">Actividad</DropdownLink>
                            <DropdownLink :href="route('goals.index')">Objetivos</DropdownLink>
                            <DropdownLink :href="route('webhooks.index')">Webhooks</DropdownLink>
                            <DropdownLink v-if="page.props.canViewSystemStatus" :href="route('system.index')"
                                >Estado del sistema</DropdownLink
                            >
                            <DropdownLink :href="route('logout')" method="post" as="button">Salir</DropdownLink>
                        </template>
                    </Dropdown>
                </div>
            </div>

            <!-- Menú móvil -->
            <div
                v-show="mobileOpen"
                class="border-t border-chrome-strong/10 bg-chrome-panel px-3 pb-4 pt-2 lg:hidden"
                data-testid="mobile-menu"
            >
                <p class="px-2 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-chrome-muted">Módulos</p>
                <Link
                    v-for="m in modules"
                    :key="m.key"
                    :href="m.href"
                    class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium"
                    :class="activeKey === m.key ? 'bg-chrome-hover text-chrome-strong' : 'text-chrome-muted hover:bg-chrome-strong/5'"
                >
                    <Icon :name="m.icon" class="h-5 w-5" />{{ m.label
                    }}<span
                        v-if="m.badge"
                        class="ms-auto rounded-full bg-danger-badge px-1.5 py-0.5 text-[10px] font-bold text-on-danger"
                        >{{ m.badge > 99 ? '99+' : m.badge }}</span
                    >
                </Link>
                <p
                    class="px-2 pb-1 pt-4 text-xs font-semibold uppercase tracking-wide text-chrome-muted"
                    >{{ moduleTitle }}</p
                >
                <Link
                    v-for="i in sideItemsList"
                    :key="i.key"
                    :href="i.href"
                    class="flex items-center gap-3 rounded-md px-3 py-2 text-sm"
                    :class="i.active ? 'bg-chrome-hover text-chrome-strong' : 'text-chrome-muted hover:bg-chrome-strong/5'"
                >
                    <NetworkIcon v-if="i.network !== undefined" :network="i.network" size="sm" />
                    <Icon v-else :name="i.icon" class="h-5 w-5" />
                    {{ i.label }}<span v-if="i.plus" class="ms-auto text-lg leading-none text-chrome-accent">+</span>
                </Link>
            </div>
        </header>

        <div class="flex">
            <!-- Barra lateral contextual (escritorio) -->
            <aside
                class="sticky top-14 hidden h-[calc(100vh-3.5rem)] shrink-0 flex-col border-e border-border bg-surface transition-[width] duration-200 lg:flex"
                :class="collapsed ? 'w-16' : 'w-60'"
                aria-label="Navegación del módulo"
            >
                <p
                    v-if="!collapsed"
                    class="px-4 pb-1 pt-5 text-xs font-semibold uppercase tracking-wide text-foreground-subtle"
                    >{{ moduleTitle }}</p
                >
                <nav class="flex-1 space-y-0.5 overflow-y-auto px-2 py-2">
                    <Link
                        v-for="i in sideItemsList"
                        :key="i.key"
                        :href="i.href"
                        :title="collapsed ? i.label : undefined"
                        class="group flex items-center gap-3 rounded-lg px-2.5 py-2 text-sm font-medium transition"
                        :class="[i.active ? 'bg-accent-soft text-accent-strong' : 'text-foreground-muted hover:bg-surface-hover hover:text-foreground-strong', collapsed ? 'justify-center' : '']"
                        :aria-current="i.active ? 'page' : undefined"
                    >
                        <NetworkIcon
                            v-if="i.network !== undefined"
                            :network="i.network"
                            :class="i.plus ? 'opacity-50 grayscale group-hover:opacity-100 group-hover:grayscale-0' : ''"
                        />
                        <Icon v-else :name="i.icon" class="h-5 w-5 shrink-0" />
                        <span v-if="!collapsed" class="min-w-0 flex-1">
                            <span class="block truncate">{{ i.label }}</span>
                            <span
                                v-if="i.sub"
                                class="block truncate text-xs font-normal text-foreground-muted"
                                >{{ i.sub }}</span
                            >
                        </span>
                        <span
                            v-if="i.plus && !collapsed"
                            class="flex h-5 w-5 items-center justify-center rounded-full bg-surface-hover text-foreground-subtle group-hover:bg-accent-fill group-hover:text-on-accent"
                            aria-hidden="true"
                            ><Icon name="plus" class="h-3.5 w-3.5"
                        /></span>
                    </Link>
                </nav>
                <button
                    type="button"
                    class="m-2 flex items-center justify-center gap-2 rounded-lg px-2 py-2 text-xs font-medium text-foreground-subtle hover:bg-surface-hover"
                    :aria-label="collapsed ? 'Expandir barra lateral' : 'Contraer barra lateral'"
                    @click="collapsed = !collapsed"
                >
                    <Icon :name="collapsed ? 'chevronRight' : 'chevronLeft'" class="h-4 w-4" /><span v-if="!collapsed"
                        >Contraer</span
                    >
                </button>
            </aside>

            <div class="min-w-0 flex-1">
                <div v-if="$slots.header" class="border-b border-border bg-surface">
                    <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
                        <slot name="header" />
                    </div>
                </div>

                <main
                    id="contenido"
                    class="mx-auto"
                    :class="fullBleed ? 'w-full min-w-0 p-3 sm:p-4' : 'max-w-7xl px-4 py-6 sm:px-6 lg:px-8'"
                >
                    <FlashBanner
                        :success="page.flash?.success ?? page.props.flash?.success"
                        :error="page.flash?.error ?? page.props.flash?.error"
                        class="mb-4"
                        data-testid="flash"
                    />
                    <div
                        v-for="p in pendingConnections"
                        :key="p.brand_id"
                        class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border-2 border-warning-outline bg-warning-soft p-3 text-sm text-warning-ink"
                        role="note"
                        data-pending-connection
                    >
                        <span
                            >Falta un paso para terminar de conectar las cuentas de «{{ p.brand_name }}»: elige cuáles
                            conectar.</span
                        >
                        <Link
                            :href="route('brands.connections.index', p.brand_id) + '#eleccion-cuentas'"
                            class="font-semibold underline"
                        >
                            Elegir cuentas
                        </Link>
                    </div>
                    <slot />
                </main>
            </div>
        </div>
    </div>
</template>
