import RcColorPickerPlugin from './rc-color-picker/rc-color-picker.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('RcColorPicker', RcColorPickerPlugin, '[data-rc-color-picker]');

// Eine Anmeldung auf `onVariantChange` braucht es nicht: Das Ereignis gibt es in Shopware 6.7
// nirgends (im gesamten Kern 6.7.12.1 kein einziger Treffer). Als vermeintliche Absicherung nach
// einem Variantenwechsel täte sie nichts und verdeckte, ob der Kaufbereich auf CMS-Seiten die
// Farbauswahl behält. Der Kern veröffentlicht `updateBuyWidget`, und `BuyBoxPlugin` ruft danach
// von sich aus `initializePlugins()` auf. Dafür, dass die Farbauswahl den Wechsel übersteht,
// sorgt der SwitchBuyBoxVariantSubscriber.
