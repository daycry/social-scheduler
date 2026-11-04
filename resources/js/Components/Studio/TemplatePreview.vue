<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { templatePreview } from '@/Studio/previews';
const props = defineProps({ template: { type: Object, required: true }, kit: { type: Object, default: () => ({}) } });
const container = ref(null), url = ref(''), failed = ref(false);
let observer, visible = false, generation = 0;
const load = async () => {
    if (!visible) return;
    const token = ++generation;
    failed.value = false;
    try {
        const blob = await templatePreview(props.template.key, props.kit);
        if (token !== generation) return;
        if (url.value) URL.revokeObjectURL(url.value);
        url.value = URL.createObjectURL(blob);
    } catch { if (token === generation) failed.value = true; }
};
onMounted(() => {
    observer = new IntersectionObserver(entries => {
        if (entries.some(entry => entry.isIntersecting)) { visible = true; observer.disconnect(); load(); }
    }, { rootMargin: '160px' });
    observer.observe(container.value);
});
watch(() => [props.template.key, JSON.stringify(props.kit)], load);
onBeforeUnmount(() => { generation++; observer?.disconnect(); if (url.value) URL.revokeObjectURL(url.value); });
</script>

<template>
    <div ref="container" class="flex aspect-[4/3] items-center justify-center overflow-hidden rounded-xl bg-slate-100 p-3" aria-hidden="true">
        <img v-if="url" :src="url" alt="" class="max-h-full max-w-full object-contain shadow-sm" />
        <span v-else class="text-center text-xs text-slate-500">{{ failed ? 'Vista previa no disponible' : 'Preparando vista previa…' }}</span>
    </div>
</template>
