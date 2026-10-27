<script setup>
import { nextTick, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';

/**
 * Vistas guardadas del calendario (C-03a): elegir aplica marca, red, estado y vista; «Guardar vista» guarda los filtros actuales con
 * un nombre; cada vista se puede renombrar, fijar como predeterminada y borrar. Panel accesible: Escape lo cierra y el foco vuelve al botón.
 */
const props = defineProps({
    views: { type: Array, default: () => [] },
    activeView: { type: Number, default: null },
    filters: { type: Object, required: true },
    view: { type: String, required: true },
    date: { type: String, required: true },
});

const open = ref(false);
const button = ref(null);
const panel = ref(null);
const editing = ref(null);
const editName = ref('');
const form = useForm({ name: '' });
const rename = useForm({ name: '' });

const close = () => {
    open.value = false;
    editing.value = null;
    nextTick(() => button.value?.focus());
};
const toggle = async () => {
    open.value = !open.value;
    if (open.value) {
        await nextTick();
        panel.value?.querySelector('button, input')?.focus();
    }
};
const onKeydown = (e) => {
    if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        close();
    }
};

const current = () => ({
    brand_id: props.filters.brand_id ? Number(props.filters.brand_id) : null,
    network: props.filters.network || null,
    status: props.filters.status || null,
    view: props.view,
});
const apply = (v) => {
    router.get(route('calendar.index'), { saved_view: v.id, date: props.date }, { preserveScroll: true, replace: true });
    close();
};
const save = () =>
    form.transform((d) => ({ ...d, filters: current() })).post(route('calendar-views.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
const startRename = (v) => {
    editing.value = v.id;
    rename.name = v.name;
    rename.clearErrors();
};
const doRename = (v) =>
    rename.put(route('calendar-views.update', v.id), { preserveScroll: true, onSuccess: () => (editing.value = null) });
const toggleDefault = (v) => router.put(route('calendar-views.update', v.id), { is_default: !v.is_default }, { preserveScroll: true });
const remove = (v) => router.delete(route('calendar-views.destroy', v.id), { preserveScroll: true });
const summary = (v) =>
    [v.filters.network, v.filters.status, { week: 'semana', month: 'mes', list: 'lista' }[v.filters.view]].filter(Boolean).join(' · ') || 'Sin filtros';
</script>

<template>
    <div class="sm:relative">
        <button ref="button" type="button" class="btn btn-secondary py-1.5 text-sm" aria-haspopup="dialog" :aria-expanded="open" data-testid="views-open" @click="toggle">
            Vistas<span v-if="activeView" class="sr-only"> (hay una vista aplicada)</span><span v-if="activeView" aria-hidden="true"> ●</span>
        </button>

        <div
            v-if="open"
            ref="panel"
            role="dialog"
            aria-label="Vistas guardadas"
            class="absolute inset-x-3 z-30 mt-1 rounded-lg border border-slate-200 bg-white p-3 text-sm shadow-lg sm:inset-x-auto sm:right-0 sm:top-full sm:w-96"
            data-testid="views-menu"
            @keydown="onKeydown"
        >
            <p v-if="views.length === 0" class="text-slate-600" data-testid="views-empty">Aún no tienes vistas guardadas. Filtra el calendario y pulsa «Guardar vista».</p>
            <ul v-else class="max-h-64 divide-y divide-slate-100 overflow-y-auto" aria-label="Vistas">
                <li v-for="v in views" :key="v.id" class="py-2" data-testid="view-row">
                    <form v-if="editing === v.id" class="space-y-1" @submit.prevent="doRename(v)">
                        <label class="block">
                            <span class="sr-only">Nuevo nombre de la vista {{ v.name }}</span>
                            <input v-model="rename.name" type="text" maxlength="120" required class="field py-1" data-testid="view-rename-input" />
                        </label>
                        <InputError :message="rename.errors.name" />
                        <span class="flex gap-2">
                            <button type="submit" class="btn btn-primary px-3 py-1 text-xs" :disabled="rename.processing" data-testid="view-rename-save">Guardar nombre</button>
                            <button type="button" class="btn btn-secondary px-3 py-1 text-xs" @click="editing = null">Cancelar</button>
                        </span>
                    </form>
                    <template v-else>
                        <button
                            type="button"
                            class="block w-full rounded px-2 py-1 text-start hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                            :aria-current="activeView === v.id ? 'true' : undefined"
                            data-testid="view-apply"
                            @click="apply(v)"
                        >
                            <span class="block font-medium text-slate-900">{{ v.name }}<span v-if="v.is_default" class="ms-1 rounded-full bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-900">por defecto</span></span>
                            <span class="block truncate text-xs text-slate-600">{{ summary(v) }}</span>
                        </button>
                        <span class="mt-1 flex flex-wrap gap-3 px-2 text-xs">
                            <button type="button" class="underline" data-testid="view-rename" @click="startRename(v)">Renombrar<span class="sr-only"> {{ v.name }}</span></button>
                            <button type="button" class="underline" :aria-pressed="v.is_default" data-testid="view-default" @click="toggleDefault(v)">{{ v.is_default ? 'Quitar por defecto' : 'Poner por defecto' }}<span class="sr-only"> {{ v.name }}</span></button>
                            <button type="button" class="text-red-700 underline" data-testid="view-delete" @click="remove(v)">Borrar<span class="sr-only"> {{ v.name }}</span></button>
                        </span>
                    </template>
                </li>
            </ul>

            <form class="mt-2 space-y-1 border-t border-slate-100 pt-2" @submit.prevent="save">
                <label class="block">
                    <span class="text-xs font-medium text-slate-700">Guardar los filtros actuales como vista</span>
                    <input v-model="form.name" type="text" maxlength="120" required class="field mt-1 py-1" placeholder="Nombre, p. ej. Pendientes IG" data-testid="view-name" />
                </label>
                <InputError :message="form.errors.name || form.errors['filters.brand_id'] || form.errors['filters.network'] || form.errors['filters.status'] || form.errors['filters.view'] || form.errors.filters" data-testid="view-save-error" />
                <button type="submit" class="btn btn-primary px-3 py-1 text-xs" :disabled="form.processing || !form.name.trim()" data-testid="view-save">Guardar vista</button>
            </form>
        </div>
    </div>
</template>
