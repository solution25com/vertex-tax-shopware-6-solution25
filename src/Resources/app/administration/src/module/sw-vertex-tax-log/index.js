import './page/vertex-tax-log-list/';

const { Module } = Shopware;

Module.register('sw-vertex-tax-log-module', {
    type: 'plugin',
    title: 'sw-vertex-tax-log-module.general.mainMenuItemList',
    description: 'sw-vertex-tax-log-module.general.descriptionTextModule',

    routes: {
        'list': {
            component: 'vertex-tax-log-list',
            path: 'list',
            meta: {
                parentPath: 'sw.settings.index'
            }
        }
    },

    settingsItem: [
        {
            name: 'sw-vertex-tax-log-module-menu',
            label: 'sw-vertex-tax-log-module.general.mainMenuItemList',
            to: 'sw.vertex.tax.log.module.list',
            group: 'plugins',
            icon: 'regular-cog'
        }
    ],
    navigation: [{
        id: 'sw-vertex-tax-log',
        label: 'sw-vertex-tax-log-module.general.mainMenuItemList',
        color: '#ff68b4',
        icon: 'regular-cog',
        path: 'sw.vertex.tax.log.module.list',
        position: 100,
        parent: 'sw-order',
        privilege: 'order.viewer',
    }]
});

