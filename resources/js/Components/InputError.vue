<script setup>
defineProps({
    message: {
        type: String,
    },
});
</script>

<template>
    <div :class="{ 'sr-only': !message }">
        <p class="text-sm text-danger" role="alert" aria-atomic="true">
            {{ message }}
        </p>
    </div>
</template>
