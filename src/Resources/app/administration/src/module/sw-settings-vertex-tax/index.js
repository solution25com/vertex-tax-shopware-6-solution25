import './page/sw-settings-vertex-tax';

const { Module } = Shopware;

Module.register('sw-settings-vertex-tax', {
    type: 'core',
    name: 'settings-vertex-tax',
    title: 'Vertex Tax Settings',
    description: 'Vertex Tax Integration Settings',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#9AA8B5',
    icon: 'regular-cog',
    favicon: 'icon-module-settings.png',

    routes: {
        index: {
            component: 'sw-settings-vertex-tax',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index',
                privilege: 'system.system_config',
            },
        },
    },

    settingsItem: {
        group: 'shop',
        to: 'sw.settings.vertex.tax.index',
        icon: 'regular-briefcase',
        privilege: 'system.system_config',
    },
});

