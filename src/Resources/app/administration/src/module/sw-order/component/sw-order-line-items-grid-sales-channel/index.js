const { Component } = Shopware;

Component.override('sw-order-line-items-grid-sales-channel', {
    computed: {
        getLineItemColumns() {
            const columnDefinitions = [
                {
                    property: 'quantity',
                    dataIndex: 'quantity',
                    label: this.$tc('sw-order.createBase.columnQuantity'),
                    allowResize: false,
                    align: 'right',
                    inlineEdit: true,
                    width: '80px',
                },
                {
                    property: 'label',
                    dataIndex: 'label',
                    label: this.$tc('sw-order.createBase.columnProductName'),
                    allowResize: false,
                    primary: true,
                    inlineEdit: true,
                    multiLine: true,
                },
                {
                    property: 'unitPrice',
                    dataIndex: 'unitPrice',
                    label: this.unitPriceLabel,
                    allowResize: false,
                    align: 'right',
                    inlineEdit: true,
                    width: '120px',
                },
            ];

            if (this.taxStatus !== 'tax-free') {
                columnDefinitions.push({
                    property: 'tax',
                    label: this.$tc('Tax'),
                    allowResize: false,
                    align: 'right',
                    inlineEdit: true,
                    width: '100px',
                });
            }

            return [
                ...columnDefinitions,
                {
                    property: 'totalPrice',
                    dataIndex: 'totalPrice',
                    label:
                        this.taxStatus === 'gross'
                            ? this.$tc(
                                  'sw-order.createBase.columnTotalPriceGross'
                              )
                            : this.$tc(
                                  'sw-order.createBase.columnTotalPriceNet'
                              ),
                    allowResize: false,
                    align: 'right',
                    width: '80px',
                },
            ];
        },
    },
});
