import { toValue, useId } from 'vue';

/** @param {import("vue").MaybeRefOrGetter<string>} active @param {{sharedPanel?: boolean}} [options] */
export function useTabs(active, { sharedPanel = false } = {}) {
    const prefix = useId();
    /** @param {string} key */
    const tabId = key => `${prefix}-tab-${key}`;
    /** @param {string} key */
    const panelId = key => sharedPanel ? `${prefix}-panel` : `${prefix}-panel-${key}`;

    /** @param {string} key */
    const tabProps = key => ({
        id: tabId(key),
        role: 'tab',
        'aria-controls': panelId(key),
        'aria-selected': toValue(active) === key,
        tabindex: toValue(active) === key ? 0 : -1,
    });

    /** @param {string} key */
    const panelProps = key => ({
        id: panelId(key),
        role: 'tabpanel',
        'aria-labelledby': tabId(key),
        tabindex: 0,
    });

    return { tabProps, panelProps };
}

/** @param {KeyboardEvent} event */
export function onTabsKeydown(event) {
    if (event.altKey || event.ctrlKey || event.metaKey || event.defaultPrevented) return;

    const list = /** @type {Element | null} */ (event.currentTarget);
    if (typeof list?.querySelectorAll !== 'function') return;

    const target = /** @type {Element | null} */ (event.target);
    const tabs = Array.from(/** @type {NodeListOf<HTMLElement>} */ (list.querySelectorAll('[role="tab"]:not(:disabled)')))
        .filter(tab => tab.closest('[role="tablist"]') === list);
    const selected = target?.closest?.('[role="tab"]');
    const index = tabs.findIndex(tab => tab === selected);
    if (index < 0) return;

    let next;
    switch (event.key) {
        case 'ArrowRight': next = (index + 1) % tabs.length; break;
        case 'ArrowLeft': next = (index - 1 + tabs.length) % tabs.length; break;
        case 'Home': next = 0; break;
        case 'End': next = tabs.length - 1; break;
        default: return;
    }

    event.preventDefault();
    tabs[next].focus();
    if (tabs[next].getAttribute('aria-selected') !== 'true') tabs[next].click();
}
