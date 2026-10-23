import { __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';

const settings = getSetting('checkview_data', {});

const defaultLabel = __( 'CheckView Testing', 'checkview' );

// Custom escapeHTML function for sanitizing
function escapeHTML(str) {
    return str.replace(/[&<>"'`=\/]/g, function(s) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
            '/': '&#x2F;',
            '`': '&#x60;',
            '=': '&#x3D;'
        }[s];
    });
}

// Sanitize the title and description before decoding them
const sanitizedTitle = escapeHTML(settings.title) || defaultLabel;
const sanitizedDescription = escapeHTML(settings.description || '');

const label = decodeEntities(sanitizedTitle);
/**
 * Content component
 */
const Content = () => {
	return decodeEntities(sanitizedDescription);
};
/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = (props) => {
	const { PaymentMethodLabel } = props.components;
	return <PaymentMethodLabel text={label} />;
};

/**
 * Checkview payment method config object.
 */
const Checkview = {
	name: "checkview",
	label: <Label />,
	content: <Content />,
	edit: <Content />,
	paymentMethodId: 'checkview',
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: settings.supports,
	},
};

registerPaymentMethod(Checkview);