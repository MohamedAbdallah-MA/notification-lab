import { arabic } from './translations.js';

const root = document.documentElement;
const bindings = new WeakMap();

function read(key, fallback) {
    try {
        return localStorage.getItem('notification-lab.' + key) || fallback;
    } catch {
        return fallback;
    }
}

function write(key, value) {
    try {
        localStorage.setItem('notification-lab.' + key, value);
    } catch {
        // The selection still applies to the current page.
    }
}

let language = read('language', 'en') === 'ar' ? 'ar' : 'en';
let theme = read('theme', 'light') === 'dark' ? 'dark' : 'light';

export function locale() {
    return language === 'ar' ? 'ar-EG' : 'en';
}

export function t(key, values = {}) {
    const text = (language === 'ar' ? arabic[key] : null) ?? key;

    return text.replace(/:([a-z]+)/g, (placeholder, name) => {
        const value = values[name];
        if (value === undefined) return placeholder;
        return typeof value === 'function' ? value() : value;
    });
}

export function copy(element, key, values = {}) {
    element.dataset.i18n = key;
    bindings.set(element, values);
    element.textContent = t(key, values);
}

function applyLanguage() {
    root.lang = language;
    root.dir = language === 'ar' ? 'rtl' : 'ltr';
    document.title = t('Notification Lab');

    for (const element of document.querySelectorAll('[data-i18n]')) {
        const key = element.dataset.i18n || element.textContent.trim();
        copy(element, key, bindings.get(element));
    }

    const attributes = { i18nAria: 'aria-label', i18nPlaceholder: 'placeholder', i18nTitle: 'title' };
    for (const [key, attribute] of Object.entries(attributes)) {
        for (const element of document.querySelectorAll('[data-i18n-' + key.slice(4).toLowerCase() + ']')) {
            const value = element.dataset[key] || element.getAttribute(attribute);
            element.dataset[key] = value;
            element.setAttribute(attribute, t(value));
        }
    }

    document.dispatchEvent(new Event('notification-lab:language'));
}

export function setupPreferences() {
    const languageToggle = document.getElementById('language-toggle');
    const themeToggle = document.getElementById('theme-toggle');
    const themeLabel = document.getElementById('theme-label');
    const themeSymbol = document.getElementById('theme-symbol');

    function updateLanguage() {
        applyLanguage();
        languageToggle.lang = language === 'ar' ? 'en' : 'ar';
        languageToggle.textContent = language === 'ar' ? 'English' : 'العربية';
        languageToggle.setAttribute('aria-label', t(language === 'ar' ? 'Switch to English' : 'Switch to Arabic'));
    }

    function updateTheme() {
        root.dataset.theme = theme;
        const dark = theme === 'dark';
        themeToggle.setAttribute('aria-pressed', String(dark));
        themeSymbol.textContent = dark ? '☀' : '☾';
        copy(themeLabel, dark ? 'Light' : 'Dark');
    }

    languageToggle.addEventListener('click', () => {
        language = language === 'ar' ? 'en' : 'ar';
        write('language', language);
        updateLanguage();
    });

    themeToggle.addEventListener('click', () => {
        theme = theme === 'dark' ? 'light' : 'dark';
        write('theme', theme);
        updateTheme();
    });

    updateLanguage();
    updateTheme();
}
