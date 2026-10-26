<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useComposerContext } from '@/Composables/useComposer';

const { brand, post, form, editable, campaigns, creatingCampaign, campaignError, createCampaign, savingCampaign, saveCampaign } = useComposerContext();

// Alta en línea de una campaña (C-03): el nombre se pide aquí mismo y la nueva queda elegida.
const adding = ref(false);
const newName = ref('');
const add = () => createCampaign(newName.value, { onCreated: () => { adding.value = false; newName.value = ''; } });
</script>

<template>
    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-2">
        <h1 class="min-w-0 flex-1 basis-40 truncate text-base font-bold text-slate-900 sm:text-lg">
            {{ post ? 'Editar publicación' : 'Nueva publicación' }} · {{ brand.name }}
        </h1>

        <div class="flex min-w-0 flex-wrap items-center gap-2" data-testid="campaign-picker">
            <label class="flex min-w-0 items-center gap-2 text-sm">
                <span class="shrink-0 text-xs font-medium text-slate-600">Campaña</span>
                <select v-model="form.campaign_id" :disabled="savingCampaign" class="field max-w-[11rem] py-1 text-sm sm:max-w-[16rem]" data-testid="campaign-select" @change="saveCampaign">
                    <option :value="null">Sin campaña</option>
                    <option v-for="c in campaigns" :key="c.id" :value="c.id">{{ c.name }}{{ c.archived ? ' (archivada)' : '' }}</option>
                </select>
            </label>
            <button v-if="editable && !adding" type="button" class="btn-secondary shrink-0 text-xs" data-testid="campaign-add" @click="adding = true">Nueva campaña</button>
            <form v-if="adding" class="flex min-w-0 flex-wrap items-center gap-2" @submit.prevent="add">
                <label class="min-w-0">
                    <span class="sr-only">Nombre de la nueva campaña</span>
                    <input v-model="newName" type="text" maxlength="120" required class="field w-40 py-1 text-sm" placeholder="Nombre" data-testid="campaign-new-name" />
                </label>
                <button type="submit" class="btn-secondary shrink-0 text-xs" :disabled="creatingCampaign" data-testid="campaign-create">Crear</button>
                <button type="button" class="shrink-0 text-xs font-medium text-slate-700 underline" @click="adding = false">Cancelar</button>
            </form>
            <p v-if="campaignError" class="w-full text-xs text-red-600" role="alert">{{ campaignError }}</p>
        </div>

        <!-- Hueco para las notas y las acciones de las fases siguientes -->
        <slot />
        <Link :href="route('brands.show', brand.id)" class="btn-secondary shrink-0" data-testid="composer-close">Cerrar</Link>
    </div>
</template>
