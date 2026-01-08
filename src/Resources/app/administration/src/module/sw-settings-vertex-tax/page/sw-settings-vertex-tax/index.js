import template from './sw-settings-vertex-tax.html.twig';

const { Component, Mixin } = Shopware;

Component.register('sw-settings-vertex-tax', {
    template,

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },

    methods: {
        saveFinish() {
            this.isSaveSuccessful = false;
        },

        basicHeaders() {
            return {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                Authorization: `Bearer ${Shopware.Service('loginService').getToken()}`
            };
        },

        async testConnection() {
            this.isLoading = true;
            const inputData = this.$refs.systemConfig.actualConfigData[null];
            const apiBasePath = Shopware.Context.api.basePath;
            const url = `${apiBasePath}/api/_action/vertex-tax/test-connection`;
            
            const salesChannelId = inputData['VertexTax.config.salesChannelId'] || null;
            
            const requestData = {
                salesChannelId: salesChannelId
            };

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: this.basicHeaders(),
                    body: JSON.stringify(requestData),
                });

                const result = await response.json();

                if (result.success) {
                    this.createNotificationSuccess({
                        message: this.$tc('sw-settings-vertex-tax.testConnection.success')
                    });
                } else {
                    this.createNotificationError({
                        message: result.message || this.$tc('sw-settings-vertex-tax.testConnection.error')
                    });
                }
            } catch (error) {
                this.createNotificationError({
                    message: `${error.message}. ${this.$tc('sw-settings-vertex-tax.testConnection.errorDetail')}`
                });
            } finally {
                this.isLoading = false;
            }
        },

        validateInput() {
            const inputData = this.$refs.systemConfig.actualConfigData[null];
            let hasError = false;

            if (!inputData['VertexTax.config.clientId']) {
                this.createNotificationError({
                    message: this.$tc('sw-settings-vertex-tax.validation.clientIdRequired')
                });
                hasError = true;
            }

            if (!inputData['VertexTax.config.clientSecret']) {
                this.createNotificationError({
                    message: this.$tc('sw-settings-vertex-tax.validation.clientSecretRequired')
                });
                hasError = true;
            }

            if (!inputData['VertexTax.config.username']) {
                this.createNotificationError({
                    message: this.$tc('sw-settings-vertex-tax.validation.usernameRequired')
                });
                hasError = true;
            }

            if (!inputData['VertexTax.config.password']) {
                this.createNotificationError({
                    message: this.$tc('sw-settings-vertex-tax.validation.passwordRequired')
                });
                hasError = true;
            }

            if (!inputData['VertexTax.config.companyCode']) {
                this.createNotificationError({
                    message: this.$tc('sw-settings-vertex-tax.validation.companyCodeRequired')
                });
                hasError = true;
            }

            if (!inputData['VertexTax.config.originStreet1']) {
                this.createNotificationError({
                    message: this.$tc('sw-settings-vertex-tax.validation.originStreetRequired')
                });
                hasError = true;
            }

            if (!inputData['VertexTax.config.originCity']) {
                this.createNotificationError({
                    message: this.$tc('sw-settings-vertex-tax.validation.originCityRequired')
                });
                hasError = true;
            }

            if (!inputData['VertexTax.config.originState']) {
                this.createNotificationError({
                    message: this.$tc('sw-settings-vertex-tax.validation.originStateRequired')
                });
                hasError = true;
            } else if (inputData['VertexTax.config.originState'].length > 3) {
                this.createNotificationError({
                    message: this.$tc('sw-settings-vertex-tax.validation.originStateInvalid')
                });
                hasError = true;
            }

            if (!inputData['VertexTax.config.originPostalCode']) {
                this.createNotificationError({
                    message: this.$tc('sw-settings-vertex-tax.validation.originPostalCodeRequired')
                });
                hasError = true;
            }

            if (!inputData['VertexTax.config.originCountry']) {
                this.createNotificationError({
                    message: this.$tc('sw-settings-vertex-tax.validation.originCountryRequired')
                });
                hasError = true;
            }

            if (hasError) {
                return false;
            }

            return true;
        },

        onSave() {
            this.isSaveSuccessful = false;
            if (!this.validateInput()) {
                this.isLoading = false;
                return;
            }
            this.isLoading = true;
            this.$refs.systemConfig.saveAll().then(() => {
                this.isLoading = false;
                this.isSaveSuccessful = true;
            }).catch((err) => {
                this.isLoading = false;
                this.createNotificationError({
                    message: err,
                });
            });
        },

        onLoadingChanged(loading) {
            this.isLoading = loading;
        }
    },
});

