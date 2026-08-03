import template from './sw-order-detail-general.html.twig';

const { Component } = Shopware;

Component.override('sw-order-detail-general', {
    template,
});
