# Changelog (EN)

## [2.5.2] - 2026-07-30 — Updates no longer abort when legacy field names are present

> **Deployment:** `php bin/console plugin:update RcColorPicker && php bin/console cache:clear`.

### Fixed
- **Renaming the custom field names could abort a running update.** If the new names already existed — for example after an uninstall that kept user data, followed by a fresh install — the rename hit the database's unique index: `plugin:update` failed and left the shop half-migrated. For the field set the opposite risk applied: no unique index there, so two identically named sets would have been created silently.
- Occupied target names are now skipped instead of overwritten. Nothing is deleted; the legacy row stays behind and the new name is authoritative.

## [2.5.1] - 2026-07-29 — Light swatches are reliably recognisable

> **Deployment:** `php bin/console plugin:update RcColorPicker`, then `theme:compile`. Presentation only.

### Fixed

- **Light colours were barely recognisable as a control on a light background.** The thin outline around the colour tile was so faint that RAL 9010 Pure White on a white background was almost invisible — measured 1.41:1 against the required 3:1. The line is now clearer (measured 3.35:1) while staying unobtrusive.
- **Error colours follow the theme again.** The error message and the invalid field marker set their own colour. In the light appearance this matched the shop's own value, but it would have overridden a later switch to a dark appearance. Both now take the colour from the theme.

## [2.5.0] - 2026-07-29 — Colour selection with a real selected state

> **Deployment:** `php bin/console plugin:update RcColorPicker && php bin/console cache:clear`, then `bin/build-storefront.sh` and `theme:compile`. No schema break, no new migration.

### Changed

- **The colour selection is now a real selection.** The swatches used to be individual buttons, and which one was selected lived only in a styling attribute — visible as a border, but with no equivalent for screen reader users. Anyone navigating back to the colour selection heard the same announcement on each of up to 80 swatches without learning which colour was active. The swatches are now form radio controls: selected state, the "selected" announcement, the position within the group ("5 of 8") and arrow key navigation all come from the browser. Appearance and mouse operation are unchanged.
- **The buy button is no longer disabled while no colour is selected.** A disabled button drops out of the tab order and is no longer announced by screen readers — anyone skipping the colour selection could not find it again and was given no reason why. Submitting is now possible and is intercepted with the message "Please select a colour."; focus moves to the colour selection. This makes the documented error message reachable at all — previously it could not appear in any configuration.

### Fixed

- **Unknown RAL codes went into the order without comment.** The free text field accepted any input, including values that match no colour. The only feedback was the missing colour preview — purely visual. Unknown codes are now treated as an input error: the field is marked invalid, the reason sits next to it and stays associated with the field, and checkout is intercepted until it is corrected. Valid codes behave as before.
- **Selection now visible in forced colours mode.** Windows forced colours mode replaced the selection border with system colours, making the selection invisible.

## [2.4.2] - 2026-07-17 — Line item splitting, config stays current, less idle work

> **Deployment:** `php bin/console plugin:update RcColorPicker && php bin/console cache:clear`. No schema break, no new migration.

### Fixed

- **Changed plugin configuration sometimes only took effect after a restart.** The plugin kept its
  own configuration cache that never learned about changes made in the admin — under worker mode,
  stale values could keep being used. The cache is removed entirely; Shopware already caches the
  configuration itself and invalidates it correctly on change.

- **Reloading the order confirmation caused pointless writes.** The colour values were written to
  the order on every `/checkout/finish` request, even when they were already identical there.
  Pressing F5 on the order confirmation no longer triggers an idle write.

- **The colour could silently go missing from the order confirmation email.** The migration that
  inserts the colour block into the mail template only applies to an unmodified Shopware default
  template. With a customised template it was skipped silently — the operator only noticed once an
  email arrived without the colour. The skip is now logged as a warning naming the affected
  template, so it can be applied by hand.

- **Line item splitting by other plugins could be overridden.** RcColorPicker may only assign the
  line item ID itself when no higher-priority plugin is attached to the buy form. The check only
  detected such a plugin when its marker sat **inside** the form — a marker on the form element
  itself went unnoticed, and RcColorPicker overwrote the foreign line item ID. In practice, line
  items meant to stay separate in the cart could collapse into one. Both marker variants are now
  checked.

  Found while verifying an equivalent defect in RcDynamicPrice — both had inherited the same check
  from the same protocol template.

### Removed

- **A check for a marker no plugin ever sets.** It could never match and implied a safeguard that
  did not exist. A second check was redundant and is dropped as well — the remaining generic marker
  covers the same case.

## [2.4.1] - 2026-06-27 — Security/robustness hardening

> **Deployment:** `php bin/console plugin:update RcColorPicker && php bin/console cache:clear`.

### Fixed

- **CSS injection protection for colour values:** the hex value is now strictly validated server-side before it reaches the inline styles of the cart and the order confirmation email (`|e('html_attr')` does **not** escape CSS inside a `style` attribute). New central `ColorValidator`; invalid values are cleared. Also covers headless/Store-API clients that bypass the storefront JS.
- **Checkout can no longer be blocked:** `OrderColorSubscriber` no longer throws on a DB error while writing the colour custom fields (display-only data) — it only logs. Previously a 500 could occur **after** the order was placed.
- **Headless-friendly:** the active flag now also accepts `1`/`true` (not only the string `'1'`).
- **Length limit** for RAL/name free text (max 64) against custom-field bloat.
- **DRY:** hex pattern centralised in `ColorValidator`.

## [2.4.0] - 2026-05-19

> **Deployment:** `php bin/console plugin:update RcColorPicker` (new migration patches the default `order_confirmation_mail` template) + `php bin/console cache:clear`.

### Added
- **Order confirmation email now shows the selected colour per line item** in HTML (with hex swatch) and plaintext. Primary source `lineItem.payload.rcColorPicker*`, fallback `lineItem.customFields.ruhrcoder_color_picker_*`. Works with `RcCartSplitter` splits (colour repeated per child line item) and `RcDynamicPrice` (length and colour rendered side by side).
- Migration `Migration1779600000UpdateOrderConfirmationMailColorBlock`: forward-only, idempotent (marker detection), preserves customised templates (anchor detection — no patch without the exact default label anchor).
- README section "Customising the order confirmation manually" with a ready-to-paste Twig snippet for templates that diverge from the Shopware default.
- The existing `rcColorPicker.cartLabel` snippet is reused inside mail templates (no new snippet key required).

## [2.3.0] - 2026-05-11

> **Deployment:** `bin/build-storefront.sh` (Twig + JS changed) + `php bin/console cache:clear`. No database migration.

### Added
- **Stable error codes** on `RcColorPickerException`: `CODE_CONTAINER_NOT_AVAILABLE = 1001`, `CODE_CUSTOM_FIELD_SET_REPOSITORY_MISSING = 1002`, `CODE_ORDER_LINE_ITEM_UPDATE_FAILED = 1003`. Logging/monitoring can now classify failures unambiguously — previously all plugin exceptions carried the generic code `0`. Pinning test `RcColorPickerExceptionTest` locks the constants as a contract.
- **Hex-code validation** in `ConfigService::getStandardColors()`. Inputs like `RAL 9010;Test;notahex` or `RAL 7016;Foo;rgb(255,0,0)` are now rejected instead of emitting invalid `background-color: notahex;` to the DOM. Accepted patterns: `#rgb`, `#rrggbb`, `#rgba`, `#rrggbbaa` (Bootstrap/CSS-compatible). Data provider with 7 invalid and 7 valid examples.
- **A11y: `role="group"` + `aria-label`** on the swatch container (`rc-color-picker.html.twig:48`). Screen readers now hear a grouping hint when entering the standard colour list. New snippet keys `rcColorPicker.swatchGroupLabel` in both locales.

### Fixed
- **Stability bug in `RcColorPickerPlugin.destroy()`**: When `init()` returned via the early-return path (no form parent, no product ID), `this._abortController` had not yet been initialised. A later `destroy()` call threw `TypeError: Cannot read property 'abort' of undefined`. Null-check added; happy-path behaviour unchanged.

## [2.2.1] - 2026-04-30

> **Deployment:** no database migration, no build step required — test and tooling change.

### Added
- Contract test `tests/Js/rc-color-picker.suffix-event.test.mjs` locks in the static constant `RcColorPickerPlugin.SUFFIX_CHANGED_EVENT` (= `rcSuffixChanged`). A value drift (typo, refactor) now fails the test instead of slipping through silently.
- `composer test:js` script and inclusion in `composer quality`.

## [2.2.0] - 2026-04-30

> **Deployment:** `bin/build-storefront.sh` (JS changed). No database migration, no `plugin:update` required.

### Added
- **Generic suffix event** `rcSuffixChanged` is now dispatched alongside the existing `rcColorPickerChanged` after every color change. RcColorPicker thus implements the updated plugin interaction protocol: ID-computing sibling plugins (RcCartSplitter from v2.0.0) only listen on the neutral event name — no plugin owns the namespace. The event name is exposed as a static `RcColorPickerPlugin.SUFFIX_CHANGED_EVENT`.

### Changed
- `_setPayload`/`_clearPayload` route through the new private helper `_dispatchSuffixChanged(detail)`, which fires both events in one place (DRY). The plugin-specific `rcColorPickerChanged` remains available for existing internal listeners.

### Note
- Standalone setups (RcColorPicker without RcCartSplitter) are unaffected: the additional event is a no-op without a listener.

## [2.1.1] - 2026-04-27

### Changed (internal refactoring — no behavior change)
- `OrderColorSubscriber`: `onOrderPlaced` and `onCheckoutFinish` delegate to a private `processOrder` helper. Removes a 99 % duplicate.
- `RcColorPicker`: hard-coded version threshold `'2.0.0'` replaced by `MIGRATION_INTRODUCED_IN` constant with explanatory PHPDoc.
- `ProductPageSubscriber`: redundant `readonly` on the constructor parameter removed (`final readonly class` already implies it).

## [2.1.0] - 2026-04-27

### Added
- Plugin configuration: new toggle "Show label for custom RAL input" (`customRalLabelVisible`, default `false`). When enabled, the custom RAL input label is shown permanently instead of for screen readers only (BFSG recommendation, WCAG 3.3.2 — sighted users with cognitive constraints lose context when the placeholder disappears during typing).
- README: note on the CS Fixer workaround under Windows without `ext-intl`.

### Changed
- Storefront JS: live-region cooldown (50 ms) before writing to `__selected-name` and `__ral-name`. Prevents screen-reader announcements from stacking on fast swatch navigation or custom RAL input (WCAG 4.1.3).
- `ConfigService` parses at most 80 standard colors (`MAX_STANDARD_COLORS`) — guards against DOM bloat from misconfiguration. Plugin-config help text states the limit; vendors with large color sets use the custom RAL input.

## [2.0.0] - 2026-04-27

> **Breaking change.** Custom-field names migrated to the vendor scheme `ruhrcoder_color_picker_*`. The bundled migration moves the existing set, field definitions and values in `product.custom_fields` and `order_line_item.custom_fields` automatically on `plugin:update`.

### Changed
- Custom-field set renamed: `rc_color_picker` → `ruhrcoder_color_picker`
- Custom field renamed: `rc_color_picker_enabled` → `ruhrcoder_color_picker_enabled`
- Order line-item custom fields renamed: `rc_color_picker_ral|name|hex` → `ruhrcoder_color_picker_ral|name|hex`
- Structured logging context of `OrderColorSubscriber` errors: `ruhrcoder_color_picker.order_color_subscriber`
- `CustomFieldInstaller` is idempotent on re-runs (looks up the existing set by name and upserts by id).

### Added
- `Migration1777248000RenameCustomFields` — forward-only and idempotent. Moves JSON values in `custom_fields` via `JSON_SET` + `JSON_REMOVE` (product custom fields live translation-side in `product_translation`, order line-item custom fields in `order_line_item`).
- Integration test `Migration1777248000RenameCustomFieldsTest` covers set/field rename, idempotency, the JSON path and a smoke test against the real column names.
- Integration test `CustomFieldInstallerIntegrationTest::testInstallIstIdempotentBeimZweitenAufruf` guards against duplicate set/field creation on repeated installer runs.

### Fixed (during the v2.0.0 release test against real shop data)
- `RcColorPicker::update()` skips the installer when jumping from v1.x to v2.0 — the migration owns the set/field rename. Without this guard, the installer ran before the migration and tried to rename via DAL, which Shopware rejects with "name is immutable".
- Migration uses `product_translation` (instead of `product`) for the JSON rename of the product custom field — matches the Shopware convention that translatable properties live in the translation table.
- `CustomFieldInstaller` also fetches the ID of the existing `*_enabled` field on the idempotency lookup via `addAssociation('customFields')` and passes it into the nested upsert — prevents duplicate-constraint violations on reinstalls.

### Accessibility (BFSG visual polish)
- Visible keyboard focus on standard swatches via `:focus-visible` (2 px outline + 2 px offset, Bootstrap primary). Mouse clicks stay ring-free (WCAG 2.4.7).
- Mode toggle (Standard / Custom RAL) marked up as `role="radiogroup"` with `aria-label` — screen readers announce the group semantically (WCAG 1.3.1).
- Swatch touch targets enlarged from 36 × 36 to 40 × 40 px, gap from 6 to 8 px (WCAG 2.5.8).
- Snippet `modeLabel` (DE + EN) added.
- Custom RAL input live preview now renders the colour name in the active storefront locale (DE/EN, fallback DE). All 214 RAL Classic colours carry both the German name and the official RAL English name — screen readers pronounce the name in the matching engine (WCAG 3.1.1 / 3.1.2).

### Upgrade notes
- Run `bin/console plugin:update RcColorPicker` — the migration runs automatically.
- Downgrade to v1.x is not supported (forward-only migration).
- Take a database backup before upgrading.

## [1.0.1] - 2026-04-27

### Accessibility (WCAG 2.2 AA / BFSG)
- Color-picker template marked up as `<fieldset>`/`<legend>` — screen readers announce the group semantically.
- Standard swatches use `aria-label` (RAL code + name) instead of only `title`.
- Custom RAL input wired up with `<label for>` (visually hidden) and `aria-describedby` to the live hint.
- Error message uses `role="alert"` + `aria-live="assertive"`; live preview / selected name use `aria-live="polite"`.
- Required-field asterisk marked `aria-hidden="true"`; additional visually-hidden "required" hint for screen readers.
- Snippets `chooseColor`, `customRalLabel`, `requiredHint` (DE + EN) added.

### Changed
- `PayloadSanitizerSubscriber` now also covers `store-api.checkout.*` (previously only `frontend.checkout.*`) — closes XSS gap for headless / PWA / mobile frontends.
- Sanitizer only strips markup (`strip_tags + trim`); HTML encoding stays with Twig (`|e('html')`). Fixes double-escape on special characters in RAL input.

## [1.0.0] - 2026-03-31

> **Deployment:** `bin/build-storefront.sh` required (first install)

### Added
- Backend configuration: standard colors (textarea), RAL-input toggle, required toggle
- Custom field `rc_color_picker_enabled` to activate per product / variant (renamed to `ruhrcoder_color_picker_enabled` in v2.0.0)
- Storefront: color picker with clickable swatches and optional RAL free-text input
- Cart line item shows the chosen color (swatch + RAL code / name)
- OrderColorSubscriber: copies color data to order line-item custom fields (priority -500, TMMS-safe)
- Generic suffix protocol (`rcColorSuffix`) for plugin interaction
- Bilingual: de-DE + en-GB complete
