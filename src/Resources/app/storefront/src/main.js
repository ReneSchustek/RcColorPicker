import RcColorPickerPlugin from './rc-color-picker/rc-color-picker.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('RcColorPicker', RcColorPickerPlugin, '[data-rc-color-picker]');

// Hier stand eine Anmeldung auf `onVariantChange` — ein Ereignis, das es in Shopware 6.7
// nirgends gibt (im gesamten Kern 6.7.12.1 kein einziger Treffer). Sie war als Absicherung nach
// einem Variantenwechsel kommentiert und tat nichts; schlimmer, sie verdeckte, dass der
// Kaufbereich auf CMS-Seiten die Farbauswahl tatsächlich verlor. Der Kern veröffentlicht
// `updateBuyWidget`, und `BuyBoxPlugin` ruft danach von sich aus `initializePlugins()` auf —
// es braucht hier also nichts. Das eigentliche Problem löst der SwitchBuyBoxVariantSubscriber.
