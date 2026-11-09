<script setup>
import NetworkIcon from '@/Components/NetworkIcon.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PresetsPanel from '@/Components/Composer/PresetsPanel.vue';
import FirstCommentField from '@/Components/Composer/FirstCommentField.vue';
import ManualToggle from '@/Components/Composer/ManualToggle.vue';
import InstagramAudioField from '@/Components/Composer/InstagramAudioField.vue';
import PresetSection from '@/Components/Composer/PresetSection.vue';
import InstagramCollaboratorsField from '@/Components/Composer/InstagramCollaboratorsField.vue';
import { watchEffect } from 'vue';
import { hasYoutubeFields, switchesFor } from '@/Composables/composerOptions';
import { useComposerContext } from '@/Composables/useComposer';
import { PRIVACY_LABELS, loadTiktokCreator, tiktokCreator } from '@/Composables/tiktokCreator';

const { accounts, brand, editable, targetFor, firstCommentMax, typeLabel } = useComposerContext();

// Direct Post de TikTok: al elegir la cuenta se consulta `creator_info` (nombre, visibilidades e interacciones de esa cuenta).
watchEffect(() => {
    for (const a of accounts.value) {
        if (a.network === 'tiktok' && a.direct_post && targetFor(a.id)) loadTiktokCreator(brand.value.id, a.id);
    }
});

// Sin valor por defecto: el usuario elige la visibilidad en cada publicación (Content Sharing Guidelines de TikTok).
const setPrivacy = (account, value) => {
    const options = targetFor(account.id).options;
    if (value) options.privacy_level = value;
    else delete options.privacy_level;
};
const brandedContent = (account) => targetFor(account.id).options.brand_content_toggle === true;

const hasOptions = (account) => {
    const t = targetFor(account.id);
    if (!t) return false;
    if (firstCommentMax.value[t.content_type] != null) return true; // primer comentario (C-01b)
    if (account.audio?.embedded_video_types?.includes(t.content_type)) return true;
    return t.content_type === 'instagram_reel' || t.content_type === 'facebook_text'
        || account.network === 'youtube' || account.network === 'tiktok';
};
</script>

<template>
    <!-- Opciones por cuenta (las que ya existían) y errores de cada destino -->
    <template v-for="account in accounts" :key="account.id">
        <PresetSection
            v-if="hasOptions(account)" :id="`network-presets-${account.id}`"
            :title="`Ajustes de ${account.network_label}`" :label="`Opciones de ${account.network_label} (${account.name})`"
            :subtitle="account.name" :badge="typeLabel(targetFor(account.id).content_type)"
        >
            <template #icon><NetworkIcon :network="account.network" size="md" /></template>
            <label v-if="targetFor(account.id).content_type === 'instagram_reel'" class="composer-setting-row rounded-xl border border-slate-200/80 px-3 py-3">
                <span>Compartir también en el feed</span>
                <input type="checkbox" class="composer-preset-toggle" :disabled="!editable" :checked="targetFor(account.id).options.share_to_feed !== false" @change="targetFor(account.id).options.share_to_feed = $event.target.checked" />
            </label>
            <div v-if="targetFor(account.id).content_type === 'facebook_text'">
                <InputLabel :for="`link-${account.id}`" value="Enlace (opcional)" />
                <input :id="`link-${account.id}`" v-model="targetFor(account.id).options.link" :disabled="!editable" type="url" class="field mt-1" placeholder="https://" @keydown.enter.prevent />
            </div>
            <div v-if="account.network === 'facebook' && ['facebook_reel', 'facebook_video'].includes(targetFor(account.id).content_type)">
                <InputLabel :for="`fb-title-${account.id}`" value="Título de Facebook (opcional)" />
                <input :id="`fb-title-${account.id}`" v-model="targetFor(account.id).options.facebook_title" :disabled="!editable" type="text" maxlength="255" class="field mt-1" @keydown.enter.prevent />
                <p class="mt-1 text-xs text-slate-600">Título del vídeo o Reel, independiente del texto de la publicación.</p>
            </div>
            <div v-if="account.network === 'youtube'" class="space-y-2">
                <div>
                    <InputLabel :for="`yt-title-${account.id}`" value="Título (por defecto, la primera línea del texto)" />
                    <input :id="`yt-title-${account.id}`" v-model="targetFor(account.id).options.title" :disabled="!editable" type="text" maxlength="100" class="field mt-1" @keydown.enter.prevent />
                </div>
                <div>
                    <InputLabel :for="`yt-privacy-${account.id}`" value="Visibilidad" />
                    <select :id="`yt-privacy-${account.id}`" :value="targetFor(account.id).options.privacy ?? 'public'" :disabled="!editable" class="field mt-1 w-auto" @change="targetFor(account.id).options.privacy = $event.target.value">
                        <option value="public">Público</option>
                        <option value="unlisted">No listado</option>
                        <option value="private">Privado</option>
                    </select>
                </div>
            </div>
            <div v-if="account.network === 'tiktok'" class="space-y-1 text-sm">
                <p v-if="!account.direct_post" class="text-xs text-slate-600">Se enviará como borrador a tu bandeja de TikTok; lo terminas de publicar en la app.</p>
                <div v-else class="space-y-2" data-testid="tiktok-direct">
                    <p v-if="tiktokCreator(account.id).loading" role="status" class="text-xs text-slate-600">Consultando tu cuenta de TikTok…</p>
                    <div v-else-if="tiktokCreator(account.id).error" class="space-y-1">
                        <p role="alert" class="text-xs text-red-700">{{ tiktokCreator(account.id).error }}</p>
                        <button type="button" class="text-sm text-indigo-700 underline" @click="loadTiktokCreator(brand.id, account.id, true)">Reintentar consulta de TikTok</button>
                    </div>
                    <template v-else-if="tiktokCreator(account.id).data">
                        <p class="flex items-center gap-2 text-sm text-slate-800" data-testid="tiktok-creator">
                            <img v-if="tiktokCreator(account.id).data.avatar_url" :src="tiktokCreator(account.id).data.avatar_url" alt="" class="h-6 w-6 rounded-full" referrerpolicy="no-referrer" />
                            Se publicará en <strong>{{ tiktokCreator(account.id).data.nickname }}</strong>
                        </p>
                        <div>
                            <InputLabel :for="`tt-privacy-${account.id}`" value="¿Quién puede ver este vídeo? (obligatorio)" />
                            <select :id="`tt-privacy-${account.id}`" :value="targetFor(account.id).options.privacy_level ?? ''" :disabled="!editable" class="field mt-1 w-auto" required @change="setPrivacy(account, $event.target.value)">
                                <option value="" disabled>Elige la visibilidad</option>
                                <option
                                    v-for="level in tiktokCreator(account.id).data.privacy_level_options" :key="level" :value="level"
                                    :disabled="level === 'SELF_ONLY' && brandedContent(account)"
                                >{{ PRIVACY_LABELS[level] ?? level }}{{ level === 'SELF_ONLY' && brandedContent(account) ? ' (no admite contenido de marca)' : '' }}</option>
                            </select>
                        </div>
                        <p v-if="tiktokCreator(account.id).data.max_video_post_duration_sec" class="text-xs text-slate-600">
                            Duración máxima en esta cuenta: {{ tiktokCreator(account.id).data.max_video_post_duration_sec }} s.
                        </p>
                    </template>
                </div>
            </div>
            <InstagramCollaboratorsField v-if="account.network === 'instagram' && ['instagram_image', 'instagram_reel', 'instagram_carousel'].includes(targetFor(account.id).content_type)" :account="account" />
            <!-- Solo aparecen las opciones admitidas; la indicación de IA de Instagram es por publicación. -->
            <PresetsPanel v-if="hasYoutubeFields(account) || switchesFor(account, targetFor(account.id)).length" :account="account" />
            <FirstCommentField :account="account" />
            <ManualToggle :account="account" />
            <p v-if="targetFor(account.id).content_type !== 'instagram_reel' && account.audio?.embedded_video_types?.includes(targetFor(account.id).content_type)" class="rounded-xl bg-slate-50 px-3 py-3 text-xs leading-relaxed text-slate-600" data-testid="network-audio-note">{{ account.audio.note }}</p>
            <InstagramAudioField v-if="targetFor(account.id).content_type === 'instagram_reel'" :account="account" />
        </PresetSection>
    </template>
</template>
