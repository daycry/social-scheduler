<script setup>
import { router } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import BaseDialog from '@/Components/BaseDialog.vue';
import InputError from '@/Components/InputError.vue';
import { useNavigationContext } from '@/Composables/useNavigationContext';
import { navigationDestination } from '@/navigation';

defineOptions({ name: 'UserOnboarding' });
defineProps({ returnFocusTo: { type: /** @type {import('vue').PropType<HTMLButtonElement | null>} */ (Object), default: null } });
const { ctx, page, brand } = useNavigationContext();
const open = ref(false);
const processing = ref(false);
const error = ref('');
const heading = ref(/** @type {HTMLHeadingElement | null} */ (null));
/** @type {import('vue').ComputedRef<'brand' | 'connection' | 'post'>} */
const step = computed(() => {
    const current = page.props.onboarding;
    return current === 'connection' || current === 'post' ? current : 'brand';
});
const content = computed(() => ({
    brand: {
        number: 1, title: 'Crea o elige una marca', description: 'Una marca reúne las cuentas y el contenido que gestionas.',
        label: 'Ir a Marcas', href: navigationDestination(ctx.value, 'account', 'brands'),
    },
    connection: {
        number: 2, title: 'Conecta una cuenta', description: 'Elige una red social en Conexiones para vincularla a tu marca.',
        label: brand.value ? 'Ir a Conexiones' : 'Elegir una marca',
        href: navigationDestination(ctx.value, 'connections'),
    },
    post: {
        number: 3, title: 'Crea tu primera publicación', description: 'Prepara el texto y guarda un borrador para revisarlo antes de publicar.',
        label: brand.value ? 'Abrir el compositor' : 'Elegir una marca',
        href: navigationDestination(ctx.value, 'planning', 'create') ?? navigationDestination(ctx.value, 'account', 'brands'),
    },
})[step.value]);

/** @param {Exclude<import('@/types/shell').OnboardingStep, 'welcome'>} next @param {() => void} [after] */
function persist(next, after) {
    if (processing.value) return;
    processing.value = true;
    error.value = '';
    router.patch(route('profile.onboarding'), { step: next }, {
        preserveScroll: true,
        onSuccess: after,
        onError: (errors) => { error.value = errors.step ?? 'No se pudo guardar el progreso.'; },
        onFinish: () => { processing.value = false; },
    });
}

function launch() {
    error.value = '';
    open.value = true;
    if (!['brand', 'connection', 'post'].includes(page.props.onboarding ?? '')) {
        persist('brand');
    }
}
defineExpose({ launch });

function finish() {
    persist('completed', () => { open.value = false; });
}
function skip() {
    persist('skipped', () => { open.value = false; });
}
function close() {
    if (processing.value) return;
    if (page.props.onboarding === 'welcome') skip();
    else open.value = false;
}

function advance() {
    if (step.value === 'post') {
        finish();
        return;
    }
    persist(step.value === 'brand' ? 'connection' : 'post', async () => {
        await nextTick();
        heading.value?.focus();
    });
}

function goToStep() {
    const href = content.value.href;
    if (!href) return;
    persist(step.value, () => {
        open.value = false;
        router.visit(href);
    });
}
</script>

<template>
    <BaseDialog
        v-if="open"
        open
        :return-focus-to="returnFocusTo"
        role="alertdialog"
        aria-labelledby="onboarding-title"
        aria-describedby="onboarding-description"
        class="dialog-panel max-w-lg space-y-4 p-4 sm:p-6"
        @close="close"
    >
        <p class="text-sm font-medium text-foreground-muted">Primeros pasos · {{ content.number }} de 3</p>
        <h2
            id="onboarding-title"
            ref="heading"
            tabindex="-1"
            data-dialog-initial-focus
            class="text-lg font-semibold text-foreground-strong"
            >{{ content.title }}</h2
        >
        <p id="onboarding-description" class="text-sm text-foreground-muted">{{ content.description }}</p>
        <button
            v-if="content.href"
            type="button"
            class="btn-secondary"
            :disabled="processing"
            @click="goToStep"
            >{{ content.label }}</button
        >
        <p class="text-sm text-foreground-muted">Puedes retomar o repetir la guía en «Ayuda y atajos».</p>
        <InputError :message="error" />
        <div class="flex flex-wrap justify-end gap-2">
            <button type="button" class="btn-secondary" :disabled="processing" @click="skip">Saltar guía</button>
            <button
                type="button"
                class="btn-primary"
                :disabled="processing"
                @click="advance"
                >{{ step === 'post' ? 'Terminar guía' : 'Siguiente' }}</button
            >
        </div>
    </BaseDialog>
</template>
