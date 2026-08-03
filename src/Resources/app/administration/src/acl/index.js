/**
 * ACL privilege for deleting Vertex tax logs.
 *
 * Registers the `vertex_tax_log.deleter` role (privilege string `vertex_tax_log:delete`),
 * which gates both the cleanup UI (`acl.can('vertex_tax_log.deleter')`) and the admin-API
 * delete/count routes (`_acl: ['vertex_tax_log:delete']`).
 */
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: null,
    key: 'vertex_tax_log',
    roles: {
        deleter: {
            privileges: ['vertex_tax_log:delete'],
            dependencies: [],
        },
    },
});
