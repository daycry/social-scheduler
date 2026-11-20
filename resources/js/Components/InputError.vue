<script setup>
defineProps({
    message: {
        type: String,
    },
});
</script>

<template>
    <div :class="{ 'sr-only': !message }">
        <p class="text-sm text-red-600" role="alert" aria-atomic="true">
            {{ message }}
        </p>
    </div>
</template>
