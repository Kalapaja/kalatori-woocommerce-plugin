(function () {
    'use strict';

    if (!document.getElementById('wc-kalatori-styles')) {
        const style = document.createElement('style');
        style.id = 'wc-kalatori-styles';
        style.textContent = `
.wc-kalatori-label { display: inline-flex; align-items: center; font-weight: 600; }
.wc-kalatori-label img { flex-shrink: 0; margin-right: 12px; }
.wc-kalatori-content { display: flex; align-items: flex-start; }`;
        document.head.appendChild(style);
    }

    const {registerPaymentMethod} = window.wc.wcBlocksRegistry;
    const {__} = window.wp.i18n;
    const {getSetting} = window.wc.wcSettings;
    const {decodeEntities} = window.wp.htmlEntities;
    const {createElement, RawHTML} = window.wp.element;

    const settings = getSetting('paymentMethodData', {})['kalatori'] || {};
    const defaultLabel = __('Crypto (Kalatori)', 'kalatori-payment-gateway');
    const label = decodeEntities(settings?.title || '') || defaultLabel;

    const icon = settings?.icon
        ? createElement('img', {
            src: settings.icon,
            alt: __('Crypto (Kalatori)', 'kalatori-payment-gateway'),
            width: 24,
            height: 24,
        })
        : null;

    const Label = ({components}) => {
        const {PaymentMethodLabel} = components;
        return createElement(
            'span',
            {className: 'wc-kalatori-label'},
            createElement(PaymentMethodLabel, {text: label, icon})
        );
    };

    const Content = () =>
        createElement(
            'div',
            {className: 'wc-kalatori-content'},
            createElement(RawHTML, null, settings.description || '')
        );

    registerPaymentMethod({
        name: 'kalatori',
        label: createElement(Label, null),
        content: createElement(Content, null),
        edit: createElement(Content, null),
        canMakePayment: () => true,
        ariaLabel: label,
        supports: {
            features: settings?.supports || [],
        },
    });
})();
