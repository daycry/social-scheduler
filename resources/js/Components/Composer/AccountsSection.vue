<script setup>
import NetworkIcon from '@/Components/NetworkIcon.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { Link } from '@inertiajs/vue3';
import { useComposerContext } from '@/Composables/useComposer';

const { brand, accounts, form, editable, targetFor, typesOf, typeInfo, toggleAccount, errorsFor } = useComposerContext();
</script>

<template>
    <!-- Cuentas y tipo -->
    <section class="card p-4">
        <h2 class="mb-3 font-medium text-slate-900">Dónde publicar</h2>
        <p v-if="accounts.length === 0" class="text-sm text-slate-600">
            Esta marca no tiene cuentas conectadas.
            <Link :href="route('brands.connections.index', brand.id)" class="link">Conectar redes</Link>
        </p>
        <ul class="space-y-3">
            <li v-for="account in accounts" :key="account.id" class="rounded border border-slate-200 p-3">
                <label class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        :checked="!!targetFor(account.id)"
                        :disabled="!editable"
                        @change="toggleAccount(account, $event.target.checked)"
                    />
                    <NetworkIcon :network="account.network" size="sm" /><span class="font-medium">{{ account.network_label }}</span>
                    <span class="text-slate-600">{{ account.name }}</span>
                    <span v-if="account.status !== 'activa'" class="rounded bg-amber-100 px-2 text-xs text-amber-800">Requiere reconexión</span>
                </label>

                <div v-if="targetFor(account.id)" class="mt-2 space-y-2 pl-6">
                    <div>
                        <InputLabel :for="`type-${account.id}`" value="Tipo de contenido" />
                        <select
                            :id="`type-${account.id}`"
                            v-model="targetFor(account.id).content_type"
                            :disabled="!editable"
                            class="field mt-1 w-auto"
                        >
                            <option v-for="t in typesOf(account.network)" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                    </div>
                    <ul v-if="typeInfo(targetFor(account.id), account.network)?.limitations.length" class="list-disc space-y-0.5 pl-5 text-xs text-amber-700">
                        <li v-for="l in typeInfo(targetFor(account.id), account.network).limitations" :key="l">{{ l }}</li>
                    </ul>
                    <label v-if="targetFor(account.id).content_type === 'instagram_reel'" class="flex items-center gap-2 text-sm">
                        <input type="checkbox" :disabled="!editable" :checked="targetFor(account.id).options.share_to_feed !== false" @change="targetFor(account.id).options.share_to_feed = $event.target.checked" />
                        Compartir también en el feed
                    </label>
                    <div v-if="targetFor(account.id).content_type === 'facebook_text'">
                        <InputLabel :for="`link-${account.id}`" value="Enlace (opcional)" />
                        <input :id="`link-${account.id}`" v-model="targetFor(account.id).options.link" :disabled="!editable" type="url" class="field mt-1" placeholder="https://" />
                    </div>
                    <div v-if="account.network === 'youtube'" class="space-y-2">
                        <div>
                            <InputLabel :for="`yt-title-${account.id}`" value="Título (por defecto, la primera línea del texto)" />
                            <input :id="`yt-title-${account.id}`" v-model="targetFor(account.id).options.title" :disabled="!editable" type="text" maxlength="100" class="field mt-1" />
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
                        <div v-else>
                            <InputLabel :for="`tt-privacy-${account.id}`" value="Visibilidad (Direct Post)" />
                            <select :id="`tt-privacy-${account.id}`" :value="targetFor(account.id).options.privacy_level ?? 'SELF_ONLY'" :disabled="!editable" class="field mt-1 w-auto" @change="targetFor(account.id).options.privacy_level = $event.target.value">
                                <option value="PUBLIC_TO_EVERYONE">Público</option>
                                <option value="MUTUAL_FOLLOW_FRIENDS">Amigos</option>
                                <option value="FOLLOWER_OF_CREATOR">Seguidores</option>
                                <option value="SELF_ONLY">Solo yo</option>
                            </select>
                        </div>
                    </div>
                    <ul v-if="errorsFor(account.id).length" class="space-y-0.5 text-sm text-red-600" role="alert">
                        <li v-for="e in errorsFor(account.id)" :key="e">{{ e }}</li>
                    </ul>
                </div>
            </li>
        </ul>
        <p v-if="form.errors.targets" class="mt-2 text-sm text-red-600">{{ form.errors.targets }}</p>
    </section>
</template>
