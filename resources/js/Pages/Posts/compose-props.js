/** @import { PropType } from 'vue' */
/** @import { ComposePageProps } from '@/types/compose' */

/** @satisfies {Required<import('vue').ComponentObjectPropsOptions<ComposePageProps>>} */
export const composeProps = {
    brand: /** @type {PropType<ComposePageProps['brand']>} */ (Object),
    accounts: /** @type {PropType<ComposePageProps['accounts']>} */ (Array),
    contentTypes: /** @type {PropType<ComposePageProps['contentTypes']>} */ (Object),
    typeLabels: { type: /** @type {PropType<ComposePageProps['typeLabels']>} */ (Object), default: () => ({}) },
    limits: /** @type {PropType<ComposePageProps['limits']>} */ (Object),
    firstCommentMax: {
        type: /** @type {PropType<ComposePageProps['firstCommentMax']>} */ (Object),
        default: () => ({}),
    },
    mediaMeta: { type: /** @type {PropType<ComposePageProps['mediaMeta']>} */ (Object), default: () => ({}) },
    altMax: { type: Number, default: 1000 },
    presets: /** @type {PropType<ComposePageProps['presets']>} */ (Object),
    drivePicker: { type: /** @type {PropType<ComposePageProps['drivePicker']>} */ (Object), default: null },
    giphy: { type: /** @type {PropType<ComposePageProps['giphy']>} */ (Object), default: () => ({ enabled: false }) },
    savedTexts: { type: /** @type {PropType<ComposePageProps['savedTexts']>} */ (Array), default: () => [] },
    campaigns: { type: /** @type {PropType<ComposePageProps['campaigns']>} */ (Array), default: () => [] },
    post: /** @type {PropType<ComposePageProps['post']>} */ (Object),
    initialScheduledAt: {
        type: /** @type {PropType<ComposePageProps['initialScheduledAt']>} */ (String),
        default: null,
    },
    bestTimeNotice: { type: /** @type {PropType<ComposePageProps['bestTimeNotice']>} */ (String), default: null },
    csrfToken: String,
};
