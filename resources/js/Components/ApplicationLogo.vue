<script setup>
// Logotipo propio de Social Scheduler: una curva ascendente (medir) rematada por un punto (publicar).
const uid = `logo-${Math.random().toString(36).slice(2, 8)}`;
</script>

<template>
    <svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <defs>
            <linearGradient :id="uid" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#6366f1" />
                <stop offset="1" stop-color="#14b8a6" />
            </linearGradient>
        </defs>
        <rect width="32" height="32" rx="8" :fill="`url(#${uid})`" />
        <path d="M9 21l5-5 3 3 6-8" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" />
        <circle cx="23" cy="11" r="2" fill="#fff" />
    </svg>
</template>
