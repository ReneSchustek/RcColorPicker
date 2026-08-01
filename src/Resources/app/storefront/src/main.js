import RcColorPickerPlugin from './rc-color-picker/rc-color-picker.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('RcColorPicker', RcColorPickerPlugin, '[data-rc-color-picker]');

// Re-Initialisierung nach Variantenwechsel
document.$emitter.subscribe('onVariantChange', () => {
    window.PluginManager.initializePlugins();
});
