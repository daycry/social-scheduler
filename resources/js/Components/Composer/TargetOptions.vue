<script setup>
import NetworkIcon from '@/Components/NetworkIcon.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PresetsPanel from '@/Components/Composer/PresetsPanel.vue';
import FirstCommentField from '@/Components/Composer/FirstCommentField.vue';
import { watchEffect } from 'vue';
import { useComposerContext } from '@/Composables/useComposer';
import { PRIVACY_LABELS, loadTiktokCreator, tiktokCreator } from '@/Composables/tiktokCreator';

const { accounts, brand, editable, targetFor, firstCommentMax } = useComposerContext();

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
    return t.content_type === 'instagram_reel' || t.content_type === 'facebook_text'
        || account.network === 'youtube' || account.network === 'tiktok';
};
</script>

<template>
    <!-- Opciones por cuenta (las que ya existían) y errores de cada destino -->
    <template v-for="account in accounts" :key="account.id">
        <section v-if="hasOptions(account)" class="card space-y-2 p-4" :aria-label="`Opciones de ${account.network_label} (${account.name})`">
            <h2 class="flex items-center gap-2 font-medium text-slate-900">
                <NetworkIcon :network="account.network" size="sm" /> {{ account.network_label }} · {{ account.name }}
            </h2>
            <label v-if="targetFor(account.id).content_type === 'instagram_reel'" class="flex items-center gap-2 text-sm">
                <input type="checkbox" :disabled="!editable" :checked="targetFor(account.id).options.share_to_feed !== false" @change="targetFor(account.id).options.share_to_feed = $event.target.checked" />
                Compartir también en el feed
            </label>
            <div v-if="targetFor(account.id).content_type === 'facebook_text'">
                <InputLabel :for="`link-${account.id}`" value="Enlace (opcional)" />
                <input :id="`link-${account.id}`" v-model="targetFor(account.id).options.link" :disabled="!editable" type="url" class="field mt-1" placeholder="https://" @keydown.enter.prevent />
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
                    <p v-else-if="tiktokCreator(account.id).error" role="alert" class="text-xs text-red-700">{{ tiktokCreator(account.id).error }}</p>
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
            <!-- Interruptores por red (C-04): se recuerdan por marca y red; solo salen los que la API de la red admite -->
            <PresetsPanel v-if="account.network === 'youtube' || account.network === 'tiktok'" :account="account" />
            <FirstCommentField :account="account" />
        </section>
    </template>
</template>
