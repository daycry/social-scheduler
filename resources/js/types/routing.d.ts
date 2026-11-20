// @routes supplies Ziggy's route helper to every Inertia page.
import type { route as ziggyRoute } from 'ziggy-js';

declare global {
    var route: typeof ziggyRoute;
}

declare module 'ziggy-js' {
    interface TypeConfig {
        strictRouteNames: true;
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        route: typeof ziggyRoute;
    }
}
