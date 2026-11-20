const vue = require('eslint-plugin-vue');

module.exports = [
    ...vue.configs['flat/essential'],
    {
        files: ['resources/js/Components/**/*.vue'],
        rules: {
            'vue/multi-word-component-names': [
                'error',
                { ignores: ['Checkbox', 'Dropdown', 'Icon', 'Inspector', 'Modal'] },
            ],
        },
    },
    {
        // Inertia identifica las páginas por su ruta de archivo.
        files: ['resources/js/Pages/**/*.vue'],
        rules: { 'vue/multi-word-component-names': 'off' },
    },
];
