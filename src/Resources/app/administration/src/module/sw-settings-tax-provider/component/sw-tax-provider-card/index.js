import template from './sw-tax-provider-card.html.twig';
import './sw-tax-provider-card.scss';

const { Component, Context } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('sw-tax-provider-card', {
    template,
    inject: ['repositoryFactory'],
    props: {
        tax: {
            type: Object,
            required: true,
        }
    },
    data() {
        return {
            taxProvider: null,
            currentTaxProvider: null,
        };
    },
    computed: {
        taxRepository() {
            return this.repositoryFactory.create('tax');
        },
        taxProviderRepository() {
            return this.repositoryFactory.create('vertex_tax_provider');
        },
        taxMappingRepository() {
            return this.repositoryFactory.create('vertex_tax_mapping');
        },
        taxProviderCriteria() {
            const criteria = new Criteria();
            return criteria;
        }
    },

    watch: {
        'tax.id': {
            immediate: true,
            handler() {
                if (this.tax?.id) {
                    this.loadTaxExtension();
                }
            }
        }
    },

    created() {
        this.createdComponent();
    },

    methods: {
        changeTaxProvider(id) {
            if (!id) {
                if (this.tax.extensions?.taxExtension) {
                    this.taxMappingRepository.delete(this.tax.extensions.taxExtension.id, Context.api).then(() => {
                        this.taxProvider.id = null;
                        this.currentTaxProvider = null;
                        if (this.tax.extensions) {
                            delete this.tax.extensions.taxExtension;
                        }
                        this.$emit('tax-provider-changed');
                    });
                }
                return;
            }

            this.taxProviderRepository.get(id, Context.api).then((item) => {
                this.currentTaxProvider = item;
                if (this.currentTaxProvider) {
                    if (this.tax.extensions?.taxExtension) {
                        this.taxExtension = this.tax.extensions.taxExtension;
                        this.taxExtension.providerId = this.currentTaxProvider.id;
                        this.taxMappingRepository.save(this.taxExtension, Context.api).then(() => {
                            this.loadTaxExtension();
                            this.$emit('tax-provider-changed');
                        });
                    } else {
                        this.taxExtension = this.taxMappingRepository.create(Shopware.Context.api);
                        this.taxExtension.taxId = this.tax.id;
                        this.taxExtension.providerId = this.currentTaxProvider.id;
                        this.taxMappingRepository.save(this.taxExtension, Context.api).then(() => {
                            this.loadTaxExtension();
                            this.$emit('tax-provider-changed');
                        });
                    }
                }
            });
        },
        createdComponent() {
            this.taxProvider = this.taxProviderRepository.create();
            this.loadTaxExtension();
        },
        
        loadTaxExtension() {
            if (!this.tax?.id) {
                return;
            }
            
            if (this.tax.extensions?.taxExtension) {
                this.setTaxProviderFromExtension(this.tax.extensions.taxExtension);
                return;
            }
            
            const criteria = new Criteria();
            criteria.addFilter(Criteria.equals('taxId', this.tax.id));
            criteria.addAssociation('taxProvider');
            
            this.taxMappingRepository.search(criteria, Context.api).then((result) => {
                if (result.length > 0) {
                    const extension = result.first();
                    this.setTaxProviderFromExtension(extension);
                }
            });
        },
        
        setTaxProviderFromExtension(extension) {
            const providerId = extension.taxProvider?.id || extension.providerId;
            if (providerId) {
                this.taxProvider.id = providerId;
                if (extension.taxProvider) {
                    this.currentTaxProvider = extension.taxProvider;
                } else if (extension.providerId) {
                    this.taxProviderRepository.get(extension.providerId, Context.api).then((provider) => {
                        this.currentTaxProvider = provider;
                    });
                }
            }
        }
    },
});

