<script setup>
import { computed, nextTick, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import BaseDialog from '@/Components/BaseDialog.vue';
import Checkbox from '@/Components/Checkbox.vue';
import CommandPalette from '@/Components/CommandPalette.vue';
import InputError from '@/Components/InputError.vue';
import Onboarding from '@/Components/Onboarding.vue';
import { useNavigationContext } from '@/Composables/useNavigationContext';
import { useShortcuts } from '@/Composables/useShortcuts';
import { shortcutHelp } from '@/lib/shortcuts';
import { navigationDestination } from '@/navigation';

defineProps({ light: Boolean });
const { ctx, page } = useNavigationContext();
const palette = ref(/** @type {InstanceType<typeof CommandPalette> | null} */ (null));
const onboarding = ref(/** @type {InstanceType<typeof Onboarding> | null} */ (null));
const helpButton = ref(/** @type {HTMLButtonElement | null} */ (null));
const helpOpen = ref(false);
const form = useForm({ single_key_shortcuts: page.props.auth.user.single_key_shortcuts ?? true });
/** @type {import('vue').ComputedRef<Record<string, string | undefined>>} */
const links = computed(() => ({
    analytics: navigationDestination(ctx.value, 'analytics'),
    planning: navigationDestination(ctx.value, 'planning'),
    studio: navigationDestination(ctx.value, 'studio'),
    create: navigationDestination(ctx.value, 'planning', 'create'),
}));

/** @param {string} id */
function action(id) {
    if (id === 'help') return () => { helpOpen.value = true; };
    if (id === 'search') return () => palette.value?.openPalette();
    const href = links.value[id];
    return href ? () => router.visit(href) : undefined;
}

useShortcuts({
    enabled: () => page.props.auth.user.single_key_shortcuts ?? true,
    paletteOpen: () => palette.value?.open ?? false,
    actions: action,
});
const global = computed(() => shortcutHelp('global', { available: (id) => action(id) !== undefined }));
const inEditor = computed(() => ctx.value.is('studio.designs.edit'));
const groups = computed(() => [
    { title: 'Toda la aplicación', entries: global.value },
    ...(inEditor.value ? [
        { title: 'Editor del Estudio', entries: shortcutHelp('studio-editor') },
        { title: 'Con el lienzo enfocado', entries: shortcutHelp('studio-canvas') },
    ] : []),
]);
const guideLabel = computed(() => {
    const step = page.props.onboarding;
    if (step === 'completed' || step === 'skipped') return 'Repetir guía de primeros pasos';
    if (step === 'brand' || step === 'connection' || step === 'post') return 'Continuar guía de primeros pasos';
    return 'Abrir guía de primeros pasos';
});

function saveShortcuts() {
    form.patch(route('profile.shortcuts'), { preserveScroll: true });
}

async function openGuide() {
    helpOpen.value = false;
    await nextTick();
    onboarding.value?.launch();
}
</script>

<template>
    <CommandPalette ref="palette" :light="light" />
    <button
        v-if="page.props.onboarding === 'welcome'"
        type="button"
        class="shrink-0 rounded-lg px-2 py-1.5 text-xs font-medium"
        :class="light ? 'bg-accent-soft text-accent-strong' : 'bg-chrome-active/20 text-chrome-strong'"
        aria-label="Empezar guía de primeros pasos"
        @click="openGuide"
        >Guía</button
    >
    <button
        ref="helpButton"
        type="button"
        class="h-9 w-8 shrink-0 rounded-lg text-base font-semibold"
        :class="light ? 'text-foreground-muted hover:bg-surface-hover' : 'text-chrome-muted hover:bg-chrome-hover hover:text-chrome-strong'"
        aria-label="Ayuda y atajos"
        aria-haspopup="dialog"
        :aria-keyshortcuts="page.props.auth.user.single_key_shortcuts === false ? undefined : '?'"
        title="Ayuda y atajos"
        @click="helpOpen = true"
        >?</button
    >
    <BaseDialog
        v-if="helpOpen"
        open
        close-on-backdrop
        role="alertdialog"
        aria-labelledby="global-help-title"
        aria-describedby="global-help-description"
        class="dialog-panel max-w-lg space-y-4 p-4 sm:p-6"
        @close="helpOpen = false"
    >
        <h2
            id="global-help-title"
            tabindex="-1"
            data-dialog-initial-focus
            class="text-lg font-semibold text-foreground-strong"
            >Ayuda y atajos</h2
        >
        <p id="global-help-description" class="text-sm text-foreground-muted"
            >Los atajos globales actúan fuera de los campos de edición y de los diálogos.</p
        >
        <form class="space-y-2" @submit.prevent="saveShortcuts">
            <label class="flex items-center gap-2 text-sm text-foreground-secondary">
                <Checkbox v-model:checked="form.single_key_shortcuts" />
                Activar los atajos globales de una sola tecla
            </label>
            <InputError :message="form.errors.single_key_shortcuts" />
            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" class="btn-secondary" :disabled="form.processing || !form.isDirty"
                    >Guardar atajos</button
                >
                <p v-if="form.recentlySuccessful" role="status" class="text-sm text-foreground-muted"
                    >Atajos guardados.</p
                >
            </div>
        </form>
        <section v-for="group in groups" :key="group.title" class="space-y-2">
            <h3 class="text-sm font-semibold text-foreground-strong">{{ group.title }}</h3>
            <dl class="space-y-1 text-sm text-foreground-secondary">
                <div v-for="entry in group.entries" :key="entry.keys" class="flex justify-between gap-3">
                    <dt>{{ entry.label }}</dt>
                    <dd class="shrink-0">
                        <kbd>{{ entry.keys }}</kbd>
                        <span v-if="entry.single && page.props.auth.user.single_key_shortcuts === false">
                            · Desactivado</span
                        >
                    </dd>
                </div>
            </dl>
        </section>
        <div class="flex flex-wrap justify-end gap-2">
            <button
                v-if="page.props.auth.user.email_verified_at"
                type="button"
                class="btn-secondary"
                @click="openGuide"
                >{{ guideLabel }}</button
            >
            <button type="button" class="btn-primary" @click="helpOpen = false">Cerrar ayuda</button>
        </div>
    </BaseDialog>
    <Onboarding ref="onboarding" :return-focus-to="helpButton" />
</template>
