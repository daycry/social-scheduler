<script setup>
import PresetSection from '@/Components/Composer/PresetSection.vue';
import ScheduleSection from '@/Components/Composer/ScheduleSection.vue';
import NotesPanel from '@/Components/Composer/NotesPanel.vue';
</script>

<template>
    <PresetSection id="global-presets" title="Ajustes globales" label="Ajustes globales" subtitle="Programación y organización de la publicación">
        <ScheduleSection class="!rounded-none !border-0 !bg-transparent !p-0 !shadow-none" />
        <div class="border-t border-slate-100 pt-5">
            <NotesPanel class="!rounded-none !border-0 !bg-transparent !p-0 !shadow-none" />
        </div>
    </PresetSection>
</template>
