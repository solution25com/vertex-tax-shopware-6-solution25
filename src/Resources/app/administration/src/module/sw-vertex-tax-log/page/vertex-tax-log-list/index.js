import template from './vertex-tax-log-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('vertex-tax-log-list', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('listing'),
    ],

    data() {
        return {
            logs: null,
            isLoading: false,
            sortBy: 'createdAt',
            sortDirection: 'DESC',
            naturalSorting: false,
            showDeleteModal: false,
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },

    computed: {
        logRepository() {
            return this.repositoryFactory.create('vertex_tax_log');
        },

        logColumns() {
            return [
                {
                    property: 'createdAt',
                    dataIndex: 'createdAt',
                    label: this.$tc('sw-vertex-tax-log.list.columnCreatedAt'),
                    allowResize: true,
                    primary: true,
                },
                {
                    property: 'type',
                    dataIndex: 'type',
                    label: this.$tc('sw-vertex-tax-log.list.columnType'),
                    allowResize: true,
                },
                {
                    property: 'customerName',
                    dataIndex: 'customerName',
                    label: this.$tc('sw-vertex-tax-log.list.columnCustomerName'),
                    allowResize: true,
                },
                {
                    property: 'customerEmail',
                    dataIndex: 'customerEmail',
                    label: this.$tc('sw-vertex-tax-log.list.columnCustomerEmail'),
                    allowResize: true,
                },
                {
                    property: 'orderNumber',
                    dataIndex: 'orderNumber',
                    label: this.$tc('sw-vertex-tax-log.list.columnOrderNumber'),
                    allowResize: true,
                },
            ];
        },
    },

    methods: {
        getList() {
            this.isLoading = true;

            const criteria = new Criteria(this.page, this.limit);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            this.logRepository
                .search(criteria, Shopware.Context.api)
                .then((result) => {
                    this.total = result.total;
                    this.logs = result;
                    this.isLoading = false;
                });
        },

        getLogDetail(log) {
            return {
                request: log.request ? JSON.parse(log.request) : {},
                response: log.response ? JSON.parse(log.response) : {},
            };
        },
    },
});

