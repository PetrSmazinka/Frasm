/**
 * @file frasm-phone.js
 * @brief Telephone fields: every <input type="tel"> gets a picker of the country with its calling code
 *        (intl-tel-input from cdnjs, loaded only on pages with such a field) and is sent in the international form
 *        (E.164, "+420777123456"); a number that is not valid stops the form with a message at the field.
 *
 * Options on the field: data-frasm-phone="off" leaves it as it is, data-country="sk" its default country (otherwise
 * <meta name="frasm-phone-country">, otherwise cz). The server normalizes the value too (Core\Support\Phone), so the
 * field works without this script. API (window.Frasm.phone): init(root = document) → enhances new fields.
 */
(() => {
    'use strict';

    const Frasm = (window.Frasm = window.Frasm || {});
    if (Frasm.phone) {
        return;
    }

    const CDN = 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/18.2.1/';
    const FIELDS = 'input[type="tel"]:not([data-frasm-phone="off"])';
    let library = null;

    /** Loads the library once (the stylesheet stays across pages of frasm-nav). */
    const load = () => {
        library ??= new Promise((resolve, reject) => {
            if (!document.querySelector('link[data-frasm-phone-css]')) {
                const css = Object.assign(document.createElement('link'), { rel: 'stylesheet', href: `${CDN}css/intlTelInput.css` });
                css.setAttribute('data-frasm-phone-css', '');
                css.setAttribute('data-frasm-permanent', '');
                document.head.appendChild(css);
                const style = document.createElement('style');
                style.setAttribute('data-frasm-permanent', '');
                // The field keeps the width of the layout; the list of countries above the page
                style.textContent = '.iti{display:block;width:100%}.iti__country-list{z-index:1060;max-width:min(22rem,90vw);white-space:normal}';
                document.head.appendChild(style);
            }
            if (window.intlTelInput) {
                resolve();
                return;
            }
            const script = Object.assign(document.createElement('script'), { src: `${CDN}js/intlTelInput.min.js` });
            script.setAttribute('data-frasm-permanent', '');
            script.onload = () => resolve();
            script.onerror = () => {
                library = null;
                reject(new Error('intl-tel-input not loaded'));
            };
            document.head.appendChild(script);
        });

        return library;
    };

    const country = (input) => (input.dataset.country
        || document.querySelector('meta[name="frasm-phone-country"]')?.getAttribute('content') || 'cz').toLowerCase();

    /**
     * @brief Enhances the telephone fields of a part of the page.
     * @param {ParentNode} root
     */
    const init = async (root = document) => {
        const inputs = [...root.querySelectorAll(FIELDS)].filter((input) => !input.frasmPhone);
        if (inputs.length === 0) {
            return;
        }
        try {
            await load();
        } catch (error) {
            return; // without the library the field stays a plain one (the server normalizes)
        }
        inputs.forEach((input) => {
            if (input.frasmPhone || !input.isConnected) {
                return;
            }
            input.frasmPhone = window.intlTelInput(input, {
                initialCountry: country(input),
                preferredCountries: ['cz', 'sk', 'de', 'at', 'pl'],
                separateDialCode: false,
                nationalMode: false,
                formatOnDisplay: true,
                autoPlaceholder: 'aggressive',
                utilsScript: `${CDN}js/utils.min.js`,
            });
            input.addEventListener('input', () => input.setCustomValidity(''));
        });
    };

    // Captured before frasm-nav sends the form: the numbers in the international form, a wrong one stops it
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        for (const input of form.querySelectorAll(FIELDS)) {
            const picker = input.frasmPhone;
            if (!picker || input.value.trim() === '' || !window.intlTelInputUtils) {
                continue;
            }
            if (!picker.isValidNumber()) {
                input.setCustomValidity(input.dataset.invalid || 'Zadejte platné telefonní číslo (i s předvolbou státu).');
                input.reportValidity();
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }
            input.setCustomValidity('');
            input.value = picker.getNumber();
        }
    }, true);

    Frasm.phone = { init };

    // Pages come through frasm-nav (frasm:load for every page, the first one too) or without it
    document.addEventListener('frasm:load', () => init());
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => init());
    } else {
        init();
    }
})();
