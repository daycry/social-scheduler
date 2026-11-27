import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

const colorTokens = [
    'canvas',
    'surface',
    'surface-raised',
    'surface-hover',
    'border',
    'border-subtle',
    'control-border',
    'foreground',
    'foreground-strong',
    'foreground-secondary',
    'foreground-muted',
    'foreground-subtle',
    'accent',
    'accent-strong',
    'accent-soft',
    'accent-marker',
    'accent-fill',
    'accent-hover',
    'accent-active',
    'on-accent',
    'check',
    'danger',
    'danger-fill',
    'danger-hover',
    'danger-active',
    'danger-badge',
    'on-danger',
    'danger-ink',
    'danger-soft',
    'danger-border',
    'success-ink',
    'success-soft',
    'success-border',
    'warning-ink',
    'warning-soft',
    'warning-border',
    'warning-panel-border',
    'warning-outline',
    'info-ink',
    'info-soft',
    'info-border',
    'focus',
    'overlay',
    'overlay-strong',
    'chrome',
    'chrome-panel',
    'chrome-hover',
    'chrome-border',
    'chrome-text',
    'chrome-muted',
    'chrome-strong',
    'chrome-accent',
    'chrome-avatar-start',
    'chrome-avatar-end',
    'chrome-active',
    'chrome-decoration',
    'chrome-teal',
    'chrome-sky',
    'progress',
];

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            // `nav`: ancho a partir del cual los ocho modulos de la cabecera caben con su texto (T-85).
            screens: { nav: '1400px' },
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                ...Object.fromEntries(colorTokens.map((name) => [name, `rgb(var(--color-${name}) / <alpha-value>)`])),
                // Identidad propia: azul-noche para la barra, indigo como acento, teal de apoyo.
                night: { 800: '#162033', 900: '#0f172a', 950: '#0a1020' },
            },
            boxShadow: {
                card: '0 1px 2px rgb(15 23 42 / 0.04), 0 1px 3px rgb(15 23 42 / 0.06)',
                'chrome-active': 'inset 0 -2px rgb(var(--color-chrome-active))',
            },
        },
    },

    plugins: [forms],
};
