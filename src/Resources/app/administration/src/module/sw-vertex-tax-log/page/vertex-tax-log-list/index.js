import template from './vertex-tax-log-list.html.twig';
import './vertex-tax-log-list.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('vertex-tax-log-list', {
    template,

    inject: ['repositoryFactory', 'filterFactory', 'acl'],

    mixins: [Mixin.getByName('listing'), Mixin.getByName('notification')],

    data() {
        return {
            logs: null,
            isLoading: false,
            sortBy: 'createdAt',
            sortDirection: 'DESC',
            naturalSorting: false,
            showDeleteModal: false,
            cleanupRange: '30d',
            cleanupFrom: null,
            cleanupTo: null,
            removeAllConfirmed: false,
            deleteCount: null,
            isCountLoading: false,
            isDeleting: false,
            showJsonModal: false,
            isModalLoading: false,
            jsonModalTitle: '',
            jsonModalContent: '',
            filterCriteria: [],
            defaultFilters: [
                'type-filter',
                'order-number-filter',
                'customer-name-filter',
                'customer-email-filter',
                'created-at-filter',
            ],
            storeKey: 'grid.filter.vertex_tax_log',
            activeFilterNumber: 0,
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

        logCriteria() {
            const criteria = new Criteria(this.page, this.limit);

            criteria.addSorting(
                Criteria.sort(
                    this.sortBy,
                    this.sortDirection,
                    this.naturalSorting
                )
            );
            criteria.addFields(
                'id',
                'createdAt',
                'orderNumber',
                'type',
                'customerName',
                'customerEmail'
            );

            this.filterCriteria.forEach((filter) => {
                criteria.addFilter(filter);
            });

            if (this.term && this.term.length) {
                criteria.addFilter(
                    Criteria.multi('or', [
                        Criteria.contains('orderNumber', this.term),
                        Criteria.contains('customerName', this.term),
                        Criteria.contains('customerEmail', this.term),
                        Criteria.contains('type', this.term),
                    ])
                );
            }

            return criteria;
        },

        listFilterOptions() {
            return {
                'type-filter': {
                    property: 'type',
                    type: 'string-filter',
                    label: this.$tc(
                        'sw-vertex-tax-log.filters.typeFilter.label'
                    ),
                    placeholder: this.$tc(
                        'sw-vertex-tax-log.filters.typeFilter.placeholder'
                    ),
                    criteriaFilterType: 'contains',
                },
                'order-number-filter': {
                    property: 'orderNumber',
                    type: 'string-filter',
                    label: this.$tc(
                        'sw-vertex-tax-log.filters.orderNumberFilter.label'
                    ),
                    placeholder: this.$tc(
                        'sw-vertex-tax-log.filters.orderNumberFilter.placeholder'
                    ),
                    criteriaFilterType: 'contains',
                },
                'customer-name-filter': {
                    property: 'customerName',
                    type: 'string-filter',
                    label: this.$tc(
                        'sw-vertex-tax-log.filters.customerNameFilter.label'
                    ),
                    placeholder: this.$tc(
                        'sw-vertex-tax-log.filters.customerNameFilter.placeholder'
                    ),
                    criteriaFilterType: 'contains',
                },
                'customer-email-filter': {
                    property: 'customerEmail',
                    type: 'string-filter',
                    label: this.$tc(
                        'sw-vertex-tax-log.filters.customerEmailFilter.label'
                    ),
                    placeholder: this.$tc(
                        'sw-vertex-tax-log.filters.customerEmailFilter.placeholder'
                    ),
                    criteriaFilterType: 'contains',
                },
                'created-at-filter': {
                    property: 'createdAt',
                    type: 'date-filter',
                    label: this.$tc(
                        'sw-vertex-tax-log.filters.createdAtFilter.label'
                    ),
                    dateType: 'date',
                    fromFieldLabel: null,
                    toFieldLabel: null,
                    showTimeframe: true,
                },
            };
        },

        listFilters() {
            return this.filterFactory.create(
                'vertex_tax_log',
                this.listFilterOptions
            );
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
                    property: 'orderNumber',
                    dataIndex: 'orderNumber',
                    label: this.$tc('sw-vertex-tax-log.list.columnOrderNumber'),
                    allowResize: true,
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
                    label: this.$tc(
                        'sw-vertex-tax-log.list.columnCustomerName'
                    ),
                    allowResize: true,
                },
                {
                    property: 'customerEmail',
                    dataIndex: 'customerEmail',
                    label: this.$tc(
                        'sw-vertex-tax-log.list.columnCustomerEmail'
                    ),
                    allowResize: true,
                },
                {
                    property: 'request',
                    dataIndex: 'request',
                    label: this.$tc('sw-vertex-tax-log.list.columnRequest'),
                    allowResize: true,
                },
                {
                    property: 'response',
                    dataIndex: 'response',
                    label: this.$tc('sw-vertex-tax-log.list.columnResponse'),
                    allowResize: true,
                },
            ];
        },

        cleanupOptions() {
            return [
                {
                    value: '30d',
                    label: this.$tc('sw-vertex-tax-log.cleanup.range30d'),
                },
                {
                    value: '3m',
                    label: this.$tc('sw-vertex-tax-log.cleanup.range3m'),
                },
                {
                    value: '6m',
                    label: this.$tc('sw-vertex-tax-log.cleanup.range6m'),
                },
                {
                    value: 'custom',
                    label: this.$tc('sw-vertex-tax-log.cleanup.rangeCustom'),
                },
                {
                    value: 'all',
                    label: this.$tc('sw-vertex-tax-log.cleanup.rangeAll'),
                },
            ];
        },

        isCustomRange() {
            return this.cleanupRange === 'custom';
        },

        isRemoveAll() {
            return this.cleanupRange === 'all';
        },

        canConfirmDelete() {
            if (this.isDeleting || this.isCountLoading) {
                return false;
            }
            if (this.isCustomRange && (!this.cleanupFrom || !this.cleanupTo)) {
                return false;
            }
            if (this.isRemoveAll && !this.removeAllConfirmed) {
                return false;
            }

            return this.deleteCount !== null && this.deleteCount > 0;
        },
    },

    watch: {
        cleanupRange() {
            this.fetchDeleteCount();
        },

        cleanupFrom() {
            if (this.isCustomRange) {
                this.fetchDeleteCount();
            }
        },

        cleanupTo() {
            if (this.isCustomRange) {
                this.fetchDeleteCount();
            }
        },
    },

    methods: {
        formatDate(dateString) {
            if (!dateString) {
                return '';
            }

            const date = new Date(dateString);
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            let hours = date.getHours();
            const minutes = String(date.getMinutes()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            const hoursStr = String(hours).padStart(2, '0');

            return `${year}-${month}-${day} ${hoursStr}:${minutes} ${ampm}`;
        },
        async getList() {
            this.isLoading = true;

            const criteria = await Shopware.Service(
                'filterService'
            ).mergeWithStoredFilters(this.storeKey, this.logCriteria);

            this.activeFilterNumber = criteria.filters.length;

            try {
                const result = await this.logRepository.search(
                    criteria,
                    Shopware.Context.api
                );
                this.total = result.total;
                this.logs = result;
            } catch {
                this.createNotificationError({
                    message: this.$tc(
                        'global.notification.unspecifiedSaveErrorMessage'
                    ),
                });
            } finally {
                this.isLoading = false;
            }
        },

        updateCriteria(criteria) {
            this.page = 1;
            this.filterCriteria = criteria;
            this.getList();
        },

        onRefresh() {
            this.getList();
        },

        basicHeaders() {
            return {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                Authorization: `Bearer ${Shopware.Service('loginService').getToken()}`,
            };
        },

        openCleanupModal() {
            this.cleanupRange = '30d';
            this.cleanupFrom = null;
            this.cleanupTo = null;
            this.removeAllConfirmed = false;
            this.deleteCount = null;
            this.showDeleteModal = true;
            this.fetchDeleteCount();
        },

        closeCleanupModal() {
            this.showDeleteModal = false;
            this.isDeleting = false;
        },

        cleanupParams() {
            const params = { range: this.cleanupRange };
            if (this.isCustomRange) {
                params.from = this.cleanupFrom;
                params.to = this.cleanupTo;
            }
            return params;
        },

        async fetchDeleteCount() {
            // A custom range only makes sense once both dates are chosen.
            if (this.isCustomRange && (!this.cleanupFrom || !this.cleanupTo)) {
                this.deleteCount = null;
                return;
            }

            this.isCountLoading = true;
            const query = new URLSearchParams(this.cleanupParams()).toString();
            const url = `${Shopware.Context.api.basePath}/api/_action/vertex-tax/log/count?${query}`;

            try {
                const response = await fetch(url, {
                    method: 'GET',
                    headers: this.basicHeaders(),
                });
                const result = await response.json();
                this.deleteCount = response.ok ? (result.count ?? 0) : null;
                if (!response.ok) {
                    this.createNotificationError({
                        message:
                            result.message ||
                            this.$tc('sw-vertex-tax-log.cleanup.deleteError'),
                    });
                }
            } catch (error) {
                this.deleteCount = null;
                this.createNotificationError({ message: error.message });
            } finally {
                this.isCountLoading = false;
            }
        },

        async confirmDelete() {
            this.isDeleting = true;
            const url = `${Shopware.Context.api.basePath}/api/_action/vertex-tax/log/delete`;

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: this.basicHeaders(),
                    body: JSON.stringify(this.cleanupParams()),
                });
                const result = await response.json();

                if (response.ok && result.success) {
                    this.createNotificationSuccess({
                        message: this.$tc(
                            'sw-vertex-tax-log.cleanup.deleteSuccess',
                            result.deleted ?? 0,
                            {
                                count: result.deleted ?? 0,
                            }
                        ),
                    });
                    this.closeCleanupModal();
                    this.getList();
                } else {
                    this.createNotificationError({
                        message:
                            result.message ||
                            this.$tc('sw-vertex-tax-log.cleanup.deleteError'),
                    });
                }
            } catch (error) {
                this.createNotificationError({ message: error.message });
            } finally {
                this.isDeleting = false;
            }
        },

        getLogDetail(log) {
            return {
                request: log.request ? JSON.parse(log.request) : {},
                response: log.response ? JSON.parse(log.response) : {},
            };
        },

        openJsonModal(item, field) {
            this.jsonModalTitle =
                field === 'request'
                    ? this.$tc('sw-vertex-tax-log.list.columnRequest')
                    : this.$tc('sw-vertex-tax-log.list.columnResponse');
            this.jsonModalContent = '';
            this.isModalLoading = true;
            this.showJsonModal = true;

            this.logRepository
                .get(item.id, Shopware.Context.api)
                .then((entity) => {
                    const raw = entity[field];
                    if (!raw) {
                        this.jsonModalContent = '{}';
                    } else {
                        try {
                            const parsed =
                                typeof raw === 'string' ? JSON.parse(raw) : raw;
                            this.jsonModalContent = JSON.stringify(
                                parsed,
                                null,
                                2
                            );
                        } catch {
                            this.jsonModalContent =
                                typeof raw === 'string'
                                    ? raw
                                    : JSON.stringify(raw);
                        }
                    }
                    this.isModalLoading = false;
                });
        },

        closeJsonModal() {
            this.showJsonModal = false;
            this.isModalLoading = false;
            this.jsonModalContent = '';
            this.jsonModalTitle = '';
        },

        copyJsonToClipboard() {
            if (!navigator.clipboard) {
                this.fallbackCopy(this.jsonModalContent);
                return;
            }

            navigator.clipboard.writeText(this.jsonModalContent).then(() => {
                this.createNotificationSuccess({
                    message: this.$tc('sw-vertex-tax-log.list.copySuccess'),
                });
            });
        },

        fallbackCopy(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);

            this.createNotificationSuccess({
                message: this.$tc('sw-vertex-tax-log.list.copySuccess'),
            });
        },
    },
});
