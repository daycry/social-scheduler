<script setup>
import StatusBadge from '@/Components/StatusBadge.vue';
import { useComposerContext } from '@/Composables/useComposer';

const { post, editable } = useComposerContext();
</script>

<template>
    <div v-if="post" class="flex items-center gap-2 text-sm">
        <StatusBadge :status="post.status" :label="post.status_label" />
        <span v-if="!editable" class="text-slate-600">Esta publicación ya no se puede editar.</span>
    </div>
</template>
