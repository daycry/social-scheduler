<script setup>
import BrandLogo from '@/Components/BrandLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import Icon from '@/Components/Icon.vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { activeModule, MODULES, sideItems } from '@/navigation';
import { computed, onMounted, ref, watch } from 'vue';

/**
 * Shell de la aplicación: barra superior oscura con módulos, selector de marca, avisos y menú de usuario;
 * barra lateral contextual por módulo (colapsable) y menú móvil. La marca actual y las cuentas por red llegan
 * por Inertia (`shell`), compartidas por HandleInertiaRequests.
 */
const page = usePage();
const shell = computed(() => page.props.shell ?? { brands: [], currentBrand: null, accounts: {}, networks: [] });
const user = computed(() => page.props.auth.user);
const unread = computed(() => page.props.unreadNotifications ?? 0);
const brand = computed(() => shell.value.currentBrand);

const mobileOpen = ref(false);
const collapsed = ref(false);
onMounted(() => (collapsed.value = localStorage.getItem('ss.sidebar') === 'collapsed'));
watch(collapsed, (v) => localStorage.setItem('ss.sidebar', v ? 'collapsed' : 'open'));
watch(() => page.url, () => (mobileOpen.value = false));

const is = (...names) => names.some((n) => route().current(n));

const ctx = computed(() => ({
    brand: brand.value,
    q: brand.value ? { brand_id: brand.value.id } : {},
    shell: shell.value,
    page,
    route,
    is,
}));

const current = computed(() => activeModule(ctx.value));
const activeKey = computed(() => current.value.key);
const modules = computed(() => MODULES.map((m) => ({ key: m.key, label: m.label, icon: m.icon, href: m.href(ctx.value) })));
const sideItemsList = computed(() => sideItems(current.value, ctx.value));
const q = computed(() => ctx.value.q);
const moduleTitle = computed(() => current.value.label);

// ---------------------------------------------------------------- selector de marca
const switchBrand = (id) => {
    const name = route().current();
    if (name === 'brands.connections.index' || name === 'brands.posts.create') {
        return router.get(route(name, id));
    }
    const [path, qs] = page.url.split('?');
    const params = Object.fromEntries(new URLSearchParams(qs ?? ''));
    params.brand_id = id;
    router.get(path, params);
};

const initials = computed(() => (user.value?.name ?? '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase());
</script>

<template>
    <div class="min-h-screen bg-slate-50">
        <a href="#contenido" class="sr-only z-[100] rounded bg-white px-3 py-2 text-sm font-medium text-indigo-700 focus:not-sr-only focus:fixed focus:left-2 focus:top-2">Saltar al contenido</a>

        <!-- Barra superior -->
        <header class="sticky top-0 z-40 bg-night-900 text-slate-200 shadow-md">
            <div class="flex h-14 items-center gap-3 px-3 sm:px-5">
                <button type="button" class="rounded-md p-2 text-slate-300 hover:bg-white/10 hover:text-white lg:hidden" aria-label="Abrir menú" :aria-expanded="mobileOpen" @click="mobileOpen = !mobileOpen">
                    <Icon :name="mobileOpen ? 'x' : 'menu'" class="h-5 w-5" />
                </button>

                <Link :href="route('dashboard', q)" class="shrink-0" aria-label="Social Scheduler, inicio"><BrandLogo dark /></Link>

                <nav class="ms-6 hidden items-center gap-1 lg:flex" aria-label="Módulos">
                    <Link
                        v-for="m in modules"
                        :key="m.key"
                        :href="m.href"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition hover:bg-white/10 hover:text-white"
                        :class="activeKey === m.key ? 'bg-white/10 text-white shadow-[inset_0_-2px_0_0_#818cf8]' : 'text-slate-300'"
                        :aria-current="activeKey === m.key ? 'page' : undefined"
                    >{{ m.label }}</Link>
                </nav>

                <div class="ms-auto flex items-center gap-1 sm:gap-2">
                    <!-- Selector de marca -->
                    <Dropdown align="right" width="72">
                        <template #trigger>
                            <button type="button" class="flex max-w-[11rem] items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-2.5 py-1.5 text-sm text-white hover:bg-white/10 sm:max-w-[16rem]" aria-label="Cambiar de marca" data-testid="brand-switcher">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-indigo-500 text-xs font-bold">{{ (brand?.name ?? '+').slice(0, 1).toUpperCase() }}</span>
                                <span class="truncate">{{ brand?.name ?? 'Sin marca' }}</span>
                                <Icon name="chevronDown" class="h-4 w-4 shrink-0 text-slate-400" />
                            </button>
                        </template>
                        <template #content>
                            <p class="px-4 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Tus marcas</p>
                            <button
                                v-for="b in shell.brands"
                                :key="b.id"
                                type="button"
                                class="flex w-full items-center gap-2 px-4 py-2 text-start text-sm text-slate-700 hover:bg-slate-100 focus:bg-slate-100 focus:outline-none"
                                @click="switchBrand(b.id)"
                            >
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-indigo-100 text-xs font-bold text-indigo-700">{{ b.name.slice(0, 1).toUpperCase() }}</span>
                                <span class="min-w-0 flex-1 truncate">{{ b.name }}</span>
                                <Icon v-if="brand && brand.id === b.id" name="check" class="h-4 w-4 text-indigo-600" />
                            </button>
                            <p v-if="shell.brands.length === 0" class="px-4 py-2 text-sm text-slate-500">Aún no tienes marcas.</p>
                            <div class="mt-1 border-t border-slate-100 pt-1">
                                <DropdownLink :href="route('brands.index')">Gestionar marcas</DropdownLink>
                                <DropdownLink :href="route('brands.create')">Crear marca</DropdownLink>
                            </div>
                        </template>
                    </Dropdown>

                    <!-- Avisos -->
                    <Link :href="route('notifications.index')" class="relative rounded-lg p-2 text-slate-300 hover:bg-white/10 hover:text-white" :aria-label="unread ? `Avisos, ${unread} sin leer` : 'Avisos'">
                        <Icon name="bell" class="h-5 w-5" />
                        <span v-if="unread" class="absolute -end-0.5 -top-0.5 flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white" data-testid="unread-badge">{{ unread > 99 ? '99+' : unread }}</span>
                    </Link>

                    <!-- Usuario -->
                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button type="button" class="flex items-center gap-2 rounded-lg p-1 hover:bg-white/10" aria-label="Menú de usuario">
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-teal-500 text-xs font-bold text-white">{{ initials }}</span>
                            </button>
                        </template>
                        <template #content>
                            <div class="border-b border-slate-100 px-4 py-2">
                                <p class="truncate text-sm font-medium text-slate-900">{{ user.name }}</p>
                                <p class="truncate text-xs text-slate-500">{{ user.email }}</p>
                            </div>
                            <DropdownLink :href="route('profile.edit')">Perfil</DropdownLink>
                            <DropdownLink :href="route('api-keys.index')">API keys</DropdownLink>
                            <DropdownLink :href="route('notifications.index')">Avisos</DropdownLink>
                            <Link :href="route('logout')" method="post" as="button" class="block w-full px-4 py-2 text-start text-sm leading-5 text-slate-700 hover:bg-slate-100 focus:bg-slate-100 focus:outline-none">Salir</Link>
                        </template>
                    </Dropdown>
                </div>
            </div>

            <!-- Menú móvil -->
            <div v-show="mobileOpen" class="border-t border-white/10 bg-night-800 px-3 pb-4 pt-2 lg:hidden" data-testid="mobile-menu">
                <p class="px-2 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Módulos</p>
                <Link v-for="m in modules" :key="m.key" :href="m.href" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium" :class="activeKey === m.key ? 'bg-white/10 text-white' : 'text-slate-300 hover:bg-white/5'">
                    <Icon :name="m.icon" class="h-5 w-5" />{{ m.label }}
                </Link>
                <p class="px-2 pb-1 pt-4 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ moduleTitle }}</p>
                <Link v-for="i in sideItemsList" :key="i.key" :href="i.href" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm" :class="i.active ? 'bg-white/10 text-white' : 'text-slate-300 hover:bg-white/5'">
                    <NetworkIcon v-if="i.network" :network="i.network" size="sm" />
                    <Icon v-else :name="i.icon" class="h-5 w-5" />
                    {{ i.label }}<span v-if="i.plus" class="ms-auto text-lg leading-none text-indigo-300">+</span>
                </Link>
            </div>
        </header>

        <div class="flex">
            <!-- Barra lateral contextual (escritorio) -->
            <aside class="sticky top-14 hidden h-[calc(100vh-3.5rem)] shrink-0 flex-col border-e border-slate-200 bg-white transition-[width] duration-200 lg:flex" :class="collapsed ? 'w-16' : 'w-60'" aria-label="Navegación del módulo">
                <p v-if="!collapsed" class="px-4 pb-1 pt-5 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ moduleTitle }}</p>
                <nav class="flex-1 space-y-0.5 overflow-y-auto px-2 py-2">
                    <Link
                        v-for="i in sideItemsList"
                        :key="i.key"
                        :href="i.href"
                        :title="collapsed ? i.label : undefined"
                        class="group flex items-center gap-3 rounded-lg px-2.5 py-2 text-sm font-medium transition"
                        :class="[i.active ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900', collapsed ? 'justify-center' : '']"
                        :aria-current="i.active ? 'page' : undefined"
                    >
                        <NetworkIcon v-if="i.network" :network="i.network" :class="i.plus ? 'opacity-50 grayscale group-hover:opacity-100 group-hover:grayscale-0' : ''" />
                        <Icon v-else :name="i.icon" class="h-5 w-5 shrink-0" />
                        <span v-if="!collapsed" class="min-w-0 flex-1">
                            <span class="block truncate">{{ i.label }}</span>
                            <span v-if="i.sub" class="block truncate text-xs font-normal" :class="i.plus ? 'text-slate-400' : 'text-slate-500'">{{ i.sub }}</span>
                        </span>
                        <span v-if="i.plus && !collapsed" class="flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-slate-500 group-hover:bg-indigo-600 group-hover:text-white" aria-hidden="true"><Icon name="plus" class="h-3.5 w-3.5" /></span>
                    </Link>
                </nav>
                <button type="button" class="m-2 flex items-center justify-center gap-2 rounded-lg px-2 py-2 text-xs font-medium text-slate-500 hover:bg-slate-100" :aria-label="collapsed ? 'Expandir barra lateral' : 'Contraer barra lateral'" @click="collapsed = !collapsed">
                    <Icon :name="collapsed ? 'chevronRight' : 'chevronLeft'" class="h-4 w-4" /><span v-if="!collapsed">Contraer</span>
                </button>
            </aside>

            <div class="min-w-0 flex-1">
                <div v-if="$slots.header" class="border-b border-slate-200 bg-white">
                    <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
                        <slot name="header" />
                    </div>
                </div>

                <main id="contenido" class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot />
                </main>
            </div>
        </div>
    </div>
</template>
