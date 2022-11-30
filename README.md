Dark Mode for Shopware 6 Storefront
=====

### Note This plugin is still in early development and will be released for Shopware 6.5

This plugin for [Shopware 6](https://www.shopware.de) adds a dark mode with auto-detection and/or toggle to the storefront.

The plugin offers the following features:

* Compatible with all themes
* Threshold for colors to be altered by saturation
* Set a minimum level of lightness for reduced contrast
* Exclude colors from being inverted
* Auto-detect preferred color scheme
* Toggle between light and dark mode within storefront

Requirements
-----
* 1.0.0
    * Shopware >= 6.5

Pre-release requirements
-----
The plugin can be previewed on the current `6.4` branch of `shopware/platform`.
Additionally, a feature flag must be enabled.

In your `.env` set the following before the installation of the plugin:
```
FEATURE_NEXT_15381=1
```
