<script setup>
/** Iconos de trazo (24x24) propios y mínimos para el shell. */
defineProps({ name: { type: String, required: true } });

const PATHS = {
    chart: 'M4 20V10m6 10V4m6 16v-7m4 7H2',
    calendar: 'M7 3v3m10-3v3M4 9h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z',
    list: 'M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01',
    plus: 'M12 5v14M5 12h14',
    doc: 'M7 3h7l5 5v12a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm7 0v5h5M9 13h6M9 17h6',
    link: 'M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1',
    bell: 'M6 9a6 6 0 1112 0c0 5 2 6 2 6H4s2-1 2-6zm4 9a2 2 0 004 0',
    user: 'M12 12a4 4 0 100-8 4 4 0 000 8zm-7 8a7 7 0 0114 0',
    key: 'M15 7a4 4 0 11-3.9 4.9L4 19v-3H2v-2h3l6-6A4 4 0 0115 7zm1 1h.01',
    grid: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
    menu: 'M4 6h16M4 12h16M4 18h16',
    x: 'M6 6l12 12M18 6L6 18',
    chevronDown: 'M6 9l6 6 6-6',
    chevronLeft: 'M15 6l-6 6 6 6',
    chevronRight: 'M9 6l6 6-6 6',
    check: 'M5 12l5 5 9-10',
    logout: 'M15 4h3a1 1 0 011 1v14a1 1 0 01-1 1h-3M10 8l-4 4 4 4m-4-4h11',
    users: 'M9 11a4 4 0 100-8 4 4 0 000 8zm-7 9a7 7 0 0114 0m1-16a4 4 0 010 7.5M22 20a7 7 0 00-5-6.7',
    clock: 'M12 21a9 9 0 100-18 9 9 0 000 18zm0-14v5l3 2',
    folder: 'M3 7a1 1 0 011-1h5l2 2h9a1 1 0 011 1v9a1 1 0 01-1 1H4a1 1 0 01-1-1V7z',
    trash: 'M4 7h16M9 7V4h6v3m-8 0l1 13h8l1-13M10 11v6m4-6v6',
    repeat: 'M17 2l4 4-4 4M3 11V9a3 3 0 013-3h15M7 22l-4-4 4-4m14 3v2a3 3 0 01-3 3H3',
    inbox: 'M4 13l2-8h12l2 8M4 13v6a1 1 0 001 1h14a1 1 0 001-1v-6M4 13h5l1 2h4l1-2h5',
    megaphone: 'M3 11v2a1 1 0 001 1h2l7 4V6L6 10H4a1 1 0 00-1 1zm14-3a4 4 0 010 8',
    bookmark: 'M6 4h12v17l-6-4-6 4V4z',
    tag: 'M3 12V4a1 1 0 011-1h8l9 9-9 9-9-9zm5-4h.01',
};
</script>

<template>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path :d="PATHS[name] ?? PATHS.grid" />
    </svg>
</template>
