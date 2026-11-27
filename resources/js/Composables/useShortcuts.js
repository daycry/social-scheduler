import { onMounted, onUnmounted } from 'vue';
import { hasOpenModal, shortcutAction } from '@/lib/shortcuts';

/** @param {{enabled: () => boolean, paletteOpen: () => boolean, actions: (id: string) => (() => void) | undefined}} options */
export function useShortcuts({ enabled, paletteOpen, actions }) {
    /** @param {KeyboardEvent} event */
    const onKey = (event) => {
        const id = shortcutAction(event, {
            singleKeys: enabled(),
            modalOpen: hasOpenModal(),
            paletteOpen: paletteOpen(),
            available: (key) => actions(key) !== undefined,
        });
        const action = id && actions(id);
        if (!action) return;
        event.preventDefault();
        action();
    };
    onMounted(() => window.addEventListener('keydown', onKey));
    onUnmounted(() => window.removeEventListener('keydown', onKey));
}
