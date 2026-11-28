<script setup>
defineProps({
    preview: /** @type {import('vue').PropType<import('@/types/reports').ReportPreview | null>} */ (Object),
    label: { type: /** @type {import('vue').PropType<(type: string) => string>} */ (Function), required: true },
});
/** @param {number | null | undefined} v */
const fmt = (v) => (v === null || v === undefined ? 'No disponible' : new Intl.NumberFormat('es-ES', { maximumFractionDigits: 1 }).format(v));
</script>

<template>
    <section v-if="preview" class="card space-y-3 p-5" data-preview aria-label="Vista previa del informe">
        <h2 class="text-sm font-medium text-foreground"
            >Vista previa · {{ preview.brand.name }} · {{ preview.period.from }} - {{ preview.period.to }}</h2
        >
        <div
            v-for="(b, i) in preview.blocks"
            :key="i"
            class="min-w-0 rounded-md border border-border p-3 text-sm"
            :data-preview-block="b.type"
        >
            <h3 class="font-medium text-foreground">{{ b.title || label(b.type) }}</h3>
            <p
                v-if="b.type === 'text'"
                class="mt-1 whitespace-pre-wrap break-words text-foreground-secondary"
                >{{ b.text }}</p
            >
            <ul v-else-if="b.type === 'kpis'" class="mt-1 grid gap-1 sm:grid-cols-2">
                <li v-for="c in preview.cards" :key="c.key" class="text-foreground-secondary"
                    >{{ c.label }}: <strong>{{ fmt(c.total) }}</strong></li
                >
            </ul>
            <p v-else-if="b.type === 'evolution'" class="mt-1 text-foreground-secondary">
                {{ preview.timeline.points.length ? `${preview.timeline.label}: ${preview.timeline.points.length} ${preview.timeline.granularity === 'week' ? 'semanas' : 'días'} con serie.` : 'Sin serie con datos en este periodo.' }}
            </p>
            <ul v-else-if="b.type === 'top_posts'" class="mt-1 space-y-1 text-foreground-secondary">
                <li v-for="n in preview.networks.filter((x) => x.posts.length)" :key="n.label"
                    >{{ n.label }}: {{ n.posts.length }} publicaciones destacadas</li
                >
                <li v-if="!preview.networks.some((x) => x.posts.length)" class="text-foreground-muted"
                    >Sin publicaciones con métricas en este periodo.</li
                >
            </ul>
            <div v-else-if="b.type === 'competitors'" class="mt-1 overflow-x-auto">
                <p v-if="!b.data.competitors.length && !b.data.own" class="text-foreground-muted"
                    >Sin competidores con datos.</p
                >
                <ul v-else class="space-y-1 text-foreground-secondary">
                    <li v-if="b.data.own"
                        >@{{ b.data.own.username }} (tu cuenta): {{ fmt(b.data.own.followers_end) }} seguidores</li
                    >
                    <li v-for="c in b.data.competitors" :key="c.username"
                        >@{{ c.username }}: {{ fmt(c.followers_end) }} seguidores</li
                    >
                </ul>
            </div>
            <div v-else-if="b.type === 'ads'" class="mt-1 text-foreground-secondary">
                <p v-if="!b.data.available" class="break-words">{{ b.data.message }}</p>
                <template v-else>
                    <p
                        >Cuenta {{ b.data.account }}: {{ b.data.campaigns.length }} campañas ·
                        {{ preview.period.from }} - {{ preview.period.to }}</p
                    >
                    <ul class="mt-1 space-y-1">
                        <li v-for="c in b.data.campaigns" :key="c.name" class="break-words">{{ c.name }}</li>
                    </ul>
                </template>
            </div>
        </div>
    </section>
</template>
