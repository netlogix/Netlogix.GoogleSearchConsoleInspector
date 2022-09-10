import InspectorView from './Inspector/index';

import manifest from '@neos-project/neos-ui-extensibility';

manifest('Netlogix.GoogleSearchConsoleInspector', {}, globalRegistry => {
    const viewsRegistry = globalRegistry.get('inspector').get('views');

    viewsRegistry.set('Netlogix.GoogleSearchConsoleInspector/InspectorView/Inspector', {
        component: InspectorView,
        hasOwnLabel: true
    });
});
