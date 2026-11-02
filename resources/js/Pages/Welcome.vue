<script setup>
import BrandLogo from '@/Components/BrandLogo.vue';
import Icon from '@/Components/Icon.vue';
import NetworkIcon from '@/Components/NetworkIcon.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    canLogin: { type: Boolean },
    canRegister: { type: Boolean },
    // Catalogo de redes (Network::offered): la portada es publica y no tiene `shell`.
    networks: { type: Array, default: () => [] },
});

const user = computed(() => usePage().props.auth?.user);

const FEATURES = [
    { icon: 'calendar', title: 'Planificación', text: 'Calendario semanal, mensual y en lista. Arrastra una publicación para reprogramarla y revisa el estado en cada red.', soon: false },
    { icon: 'chart', title: 'Analítica', text: 'Seguidores, visualizaciones, alcance e interacciones por red, con desglose en chips y comparación de periodos.', soon: false },
    { icon: 'doc', title: 'Informes', text: 'Genera informes en PDF o CSV por marca y compártelos con un enlace firmado que caduca.', soon: false },
    { icon: 'bell', title: 'Inbox', text: 'Comentarios y menciones de todas tus redes en una sola bandeja, para responder sin saltar de app.', soon: true },
    { icon: 'link', title: 'SmartLinks', text: 'Una página de enlaces para tu biografía, con estadísticas de clics por botón.', soon: true },
    { icon: 'key', title: 'Agente MCP', text: 'Conecta tu asistente de IA con una API key y deja que programe, consulte métricas y genere informes por ti.', soon: false },
];

const STEPS = [
    { n: '1', title: 'Crea una marca', text: 'Agrupa las cuentas de un cliente o proyecto y fija su zona horaria.' },
    { n: '2', title: 'Conecta tus redes', text: 'Autoriza Instagram, Facebook, YouTube y TikTok con su acceso oficial.' },
    { n: '3', title: 'Programa y mide', text: 'Publica a la hora ideal y sigue el rendimiento desde el panel.' },
];

const FAQ = [
    { q: '¿Qué redes puedo conectar?', a: 'Instagram, Facebook, YouTube y TikTok, cada una con el acceso oficial de la plataforma. Puedes tener varias cuentas por marca.' },
    { q: '¿Guardáis mis contraseñas de las redes?', a: 'No. Usamos OAuth: autorizas desde la propia red y nosotros solo guardamos tokens cifrados que puedes revocar cuando quieras.' },
    { q: '¿Puedo gestionar varias marcas?', a: 'Sí. Cada marca tiene sus cuentas, su calendario y sus informes, y cambias de una a otra desde el selector de la barra superior.' },
    { q: '¿Qué es el agente MCP?', a: 'Una interfaz para que tu asistente de IA trabaje con tu cuenta usando una API key personal: programar, consultar estadísticas o pedir informes.' },
    { q: '¿Puedo borrar mis datos?', a: 'Sí. Puedes desconectar una red, eliminar una marca o borrar tu cuenta desde el perfil. Los detalles están en la política de privacidad.' },
];
</script>

<template>
    <Head title="Programa, publica y mide tus redes" />

    <div class="min-h-screen bg-white text-slate-800">
        <a href="#principal" class="sr-only focus:not-sr-only focus:fixed focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">Saltar al contenido</a>

        <!-- Cabecera -->
        <header class="absolute inset-x-0 top-0 z-20">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
                <Link href="/" aria-label="Social Scheduler, inicio"><BrandLogo dark /></Link>
                <nav class="flex items-center gap-2 text-sm font-medium" aria-label="Principal">
                    <Link v-if="user" :href="route('dashboard')" class="rounded-lg bg-white px-4 py-2 text-slate-900 hover:bg-slate-100">Ir al panel</Link>
                    <template v-else>
                        <Link v-if="canLogin" :href="route('login')" class="rounded-lg px-3 py-2 text-slate-200 hover:bg-white/10 hover:text-white">Entrar</Link>
                        <Link v-if="canRegister" :href="route('register')" class="rounded-lg bg-white px-4 py-2 text-slate-900 hover:bg-slate-100">Crear cuenta</Link>
                    </template>
                </nav>
            </div>
        </header>

        <main id="principal">
            <!-- Hero -->
            <section class="relative overflow-hidden bg-night-900 pb-20 pt-28 text-white sm:pb-28 sm:pt-36">
                <div class="pointer-events-none absolute -right-32 -top-32 h-[28rem] w-[28rem] rounded-full bg-indigo-500/30 blur-3xl"></div>
                <div class="pointer-events-none absolute -bottom-40 -left-20 h-[28rem] w-[28rem] rounded-full bg-teal-500/20 blur-3xl"></div>
                <div class="relative mx-auto grid max-w-6xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-2">
                    <div>
                        <p class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium text-slate-200">
                            <span class="h-1.5 w-1.5 rounded-full bg-teal-400"></span>Con agente MCP para tu asistente de IA
                        </p>
                        <h1 class="mt-5 text-4xl font-extrabold leading-tight tracking-tight sm:text-5xl">Programa, publica y mide tus redes <span class="bg-gradient-to-r from-indigo-300 to-teal-300 bg-clip-text text-transparent">desde un solo sitio</span>.</h1>
                        <p class="mt-5 max-w-xl text-lg text-slate-300">Social Scheduler reúne Instagram, Facebook, YouTube y TikTok en un calendario, un panel de analítica y un generador de informes. Menos pestañas, más tiempo para crear.</p>
                        <div class="mt-8 flex flex-wrap items-center gap-3">
                            <Link v-if="user" :href="route('dashboard')" class="btn-primary px-6 py-3 text-base">Ir al panel</Link>
                            <template v-else>
                                <Link v-if="canRegister" :href="route('register')" class="btn-primary px-6 py-3 text-base">Crear cuenta</Link>
                                <Link v-if="canLogin" :href="route('login')" class="btn border border-white/25 px-6 py-3 text-base text-white hover:bg-white/10">Entrar</Link>
                            </template>
                        </div>
                        <ul class="mt-8 flex flex-wrap items-center gap-3" aria-label="Redes compatibles">
                            <li v-for="n in networks" :key="n.value" class="flex items-center gap-2 rounded-full bg-white/5 py-1 pe-3 ps-1 text-sm text-slate-200 ring-1 ring-white/10"><NetworkIcon :network="n.value" :color="n.color" size="sm" />{{ n.label }}</li>
                        </ul>
                    </div>

                    <!-- Maqueta CSS propia del panel -->
                    <div class="relative" aria-hidden="true">
                        <div class="rounded-2xl bg-white p-4 text-slate-800 shadow-2xl ring-1 ring-white/20">
                            <div class="flex items-center justify-between">
                                <div class="h-3 w-24 rounded bg-slate-200"></div>
                                <div class="flex gap-1.5"><span class="h-3 w-3 rounded-full bg-slate-200"></span><span class="h-3 w-3 rounded-full bg-slate-200"></span></div>
                            </div>
                            <div class="mt-4 grid grid-cols-3 gap-3">
                                <div v-for="(k, i) in [['Seguidores', '12,4 k', '+4,2 %'], ['Visualizaciones', '88,1 k', '+12 %'], ['Interacciones', '3,9 k', '+1,8 %']]" :key="i" class="rounded-xl border border-slate-200 p-3">
                                    <p class="text-[10px] font-medium text-slate-500">{{ k[0] }}</p>
                                    <p class="text-lg font-bold tabular-nums">{{ k[1] }}</p>
                                    <p class="text-[10px] font-semibold text-emerald-700">{{ k[2] }}</p>
                                </div>
                            </div>
                            <div class="mt-4 flex h-28 items-end gap-1.5 rounded-xl bg-slate-50 p-3">
                                <span v-for="(h, i) in [30, 42, 36, 55, 48, 66, 58, 74, 70, 88, 82, 96]" :key="i" class="flex-1 rounded-t bg-gradient-to-t from-indigo-500 to-teal-400" :style="{ height: h + '%' }"></span>
                            </div>
                            <div class="mt-4 grid grid-cols-7 gap-1.5">
                                <span v-for="i in 14" :key="i" class="h-6 rounded" :class="[2, 6, 9, 12].includes(i) ? 'bg-indigo-400' : [4, 11].includes(i) ? 'bg-teal-300' : 'bg-slate-100'"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Funcionalidades -->
            <section class="mx-auto max-w-6xl px-4 py-20 sm:px-6" aria-labelledby="funcionalidades">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 id="funcionalidades" class="text-3xl font-bold tracking-tight text-slate-900">Todo lo que necesitas para tus redes</h2>
                    <p class="mt-3 text-slate-600">Un flujo continuo, desde la idea hasta el informe del mes.</p>
                </div>
                <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <article v-for="f in FEATURES" :key="f.title" class="card p-6">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600"><Icon :name="f.icon" class="h-6 w-6" /></span>
                        <h3 class="mt-4 flex items-center gap-2 text-lg font-semibold text-slate-900">{{ f.title }}<span v-if="f.soon" class="rounded-full bg-teal-50 px-2 py-0.5 text-xs font-medium text-teal-700 ring-1 ring-inset ring-teal-200">Próximamente</span></h3>
                        <p class="mt-2 text-sm text-slate-600">{{ f.text }}</p>
                    </article>
                </div>
            </section>

            <!-- Cómo funciona -->
            <section class="bg-slate-50 py-20" aria-labelledby="como">
                <div class="mx-auto max-w-6xl px-4 sm:px-6">
                    <h2 id="como" class="text-center text-3xl font-bold tracking-tight text-slate-900">Empieza en tres pasos</h2>
                    <ol class="mt-12 grid gap-6 md:grid-cols-3">
                        <li v-for="s in STEPS" :key="s.n" class="card p-6">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-night-900 text-sm font-bold text-white">{{ s.n }}</span>
                            <h3 class="mt-4 font-semibold text-slate-900">{{ s.title }}</h3>
                            <p class="mt-1 text-sm text-slate-600">{{ s.text }}</p>
                        </li>
                    </ol>
                </div>
            </section>

            <!-- FAQ -->
            <section class="mx-auto max-w-3xl px-4 py-20 sm:px-6" aria-labelledby="faq">
                <h2 id="faq" class="text-center text-3xl font-bold tracking-tight text-slate-900">Preguntas frecuentes</h2>
                <div class="mt-10 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
                    <details v-for="item in FAQ" :key="item.q" class="group p-5">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-medium text-slate-900">
                            {{ item.q }}
                            <Icon name="chevronDown" class="h-5 w-5 shrink-0 text-slate-400 transition group-open:rotate-180" />
                        </summary>
                        <p class="mt-3 text-sm text-slate-600">{{ item.a }}</p>
                    </details>
                </div>
            </section>

            <!-- CTA final -->
            <section class="px-4 pb-20 sm:px-6">
                <div class="mx-auto max-w-5xl rounded-3xl bg-night-900 px-6 py-14 text-center text-white">
                    <h2 class="text-3xl font-bold tracking-tight">Pon orden en tus redes hoy</h2>
                    <p class="mx-auto mt-3 max-w-xl text-slate-300">Crea tu cuenta, conecta tu primera red y programa tu primera publicación en minutos.</p>
                    <div class="mt-8 flex flex-wrap justify-center gap-3">
                        <Link v-if="user" :href="route('dashboard')" class="btn-primary px-6 py-3 text-base">Ir al panel</Link>
                        <template v-else>
                            <Link v-if="canRegister" :href="route('register')" class="btn-primary px-6 py-3 text-base">Crear cuenta</Link>
                            <Link v-if="canLogin" :href="route('login')" class="btn border border-white/25 px-6 py-3 text-base text-white hover:bg-white/10">Entrar</Link>
                        </template>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-slate-200 bg-white">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-4 py-8 text-sm text-slate-500 sm:flex-row sm:px-6">
                <BrandLogo />
                <nav class="flex gap-5" aria-label="Legal">
                    <a href="/privacy" class="link">Privacidad</a>
                    <a href="/terms" class="link">Condiciones</a>
                </nav>
                <p>© {{ new Date().getFullYear() }} Social Scheduler</p>
            </div>
        </footer>
    </div>
</template>
