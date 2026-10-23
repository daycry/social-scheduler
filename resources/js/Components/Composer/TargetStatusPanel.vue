<script setup>
import StatusBadge from '@/Components/StatusBadge.vue';
import { useComposerContext } from '@/Composables/useComposer';

const { post, accountName } = useComposerContext();
</script>

<template>
    <div v-if="post && post.targets.length" class="card p-4">
        <h2 class="mb-2 font-medium text-slate-900">Estado por red</h2>
        <ul class="space-y-2 text-sm">
            <li v-for="t in post.targets" :key="`${t.account_id}-${t.content_type}`">
                <span class="font-medium">{{ accountName(t.account_id) }}</span>
                <StatusBadge class="ml-2" :status="t.status" :label="t.status_label" />
                <a v-if="t.external_url" :href="t.external_url" target="_blank" rel="noopener" class="link ml-2">Ver</a>
                <p v-if="t.status_note" class="text-xs text-amber-700">{{ t.status_note }}</p>
                <p v-if="t.error_message" class="text-xs text-red-600">{{ t.error_message }}</p>
            </li>
        </ul>
    </div>
</template>
