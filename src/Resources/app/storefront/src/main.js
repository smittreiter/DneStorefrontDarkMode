import Plugin from 'src/plugin-system/plugin.class';

class DneStorefrontDarkModePlugin extends Plugin {
    init() {
        this.el.addEventListener('click', this._onClick.bind(this));
        this.el.addEventListener('touchend', this._onClick.bind(this));
    }

    _onClick(event) {
        event.preventDefault();
        event.stopPropagation();

        const isDetectionDisabled = this.el.getAttribute('data-dne-storefront-dark-mode-detection-disabled') === '1';
        const documentElement = document.documentElement;
        let currentTheme = documentElement.getAttribute('data-theme');

        if (!currentTheme && !isDetectionDisabled) {
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                currentTheme = 'dark';
            } else {
                currentTheme = 'light';
            }
        } else if (!currentTheme) {
            currentTheme = 'light';
        }

        if (currentTheme === 'light') {
            documentElement.setAttribute('data-theme', 'dark');
            localStorage.setItem('dne-storefront-dark-mode-theme', 'dark');
        } else {
            documentElement.setAttribute('data-theme', 'light');
            localStorage.setItem('dne-storefront-dark-mode-theme', 'light');
        }
    }
}

window.PluginManager.register('DneStorefrontDarkModePlugin', DneStorefrontDarkModePlugin, '[data-dne-storefront-dark-mode-toggle]');

document.addEventListener('DOMContentLoaded', () => {
    window.PluginManager.getPluginInstances('OffcanvasMenu').forEach((pluginInstance) => {
        pluginInstance.$emitter.subscribe('openMenu', () => {
            window.PluginManager.initializePlugin('DneStorefrontDarkModePlugin', '[data-dne-storefront-dark-mode-toggle]');
        });
    });
}, false);
