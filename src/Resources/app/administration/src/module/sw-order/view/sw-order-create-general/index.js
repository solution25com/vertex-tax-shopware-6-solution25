const { Component } = Shopware;

Component.override('sw-order-create-general', {
    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            if (!this.customer) {
                void this.$nextTick(() => {
                    void this.$router.push({ name: 'sw.order.create.initial' });
                });
                return;
            }

            this.isLoading = true;

            void this.loadCart()
                .then(() => {
                    const originalTc = this.$tc;

                    this.$tc = (key, count = 1, params = {}) => {
                        switch (key) {
                            case 'sw-order.createBase.summaryLabelAmountTotal':
                                return 'Total including tax';
                            case 'sw-order.createBase.summaryLabelAmountWithoutTaxes':
                                return 'Total excluding tax';
                            case 'sw-order.createBase.summaryLabelAmountTotalRounded':
                                return 'Rounded total including tax';
                            case 'sw-order.createBase.summaryLabelTaxes':
                                return 'Tax';
                            default:
                                return originalTc(key, count, params);
                        }
                    };
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
    },
});
