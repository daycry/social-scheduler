/** @import { PropType } from 'vue' */
/** @import { StudioIndexPageProps } from '@/types/studio-index' */

/** @satisfies {Required<import('vue').ComponentObjectPropsOptions<StudioIndexPageProps>>} */
export const studioIndexProps = {
    brand: /** @type {PropType<StudioIndexPageProps['brand']>} */ (Object),
    designs: /** @type {PropType<StudioIndexPageProps['designs']>} */ (Object),
    kit: /** @type {PropType<StudioIndexPageProps['kit']>} */ (Object),
    assets: /** @type {PropType<StudioIndexPageProps['assets']>} */ (Array),
    folders: /** @type {PropType<StudioIndexPageProps['folders']>} */ (Array),
    filters: /** @type {PropType<StudioIndexPageProps['filters']>} */ (Object),
    fonts: { type: /** @type {PropType<StudioIndexPageProps['fonts']>} */ (Array), default: () => [] },
    shared: { type: /** @type {PropType<StudioIndexPageProps['shared']>} */ (Array), default: () => [] },
};
