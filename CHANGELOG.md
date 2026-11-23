# Changelog

All notable changes to this module are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the module uses [Semantic Versioning](https://semver.org/). The version always follows the main module
`mage-os/module-customer-custom-attributes`: both packages are tagged together.

For what the module does, see the [user guide](https://github.com/yuriy-boyko/mageos-module-customer-custom-attributes/blob/main/docs/guide/README.md) of the main module.

## [0.1.0] - 2026-11-23

Initial version.

### Added
- Hyvä Checkout 3.x: the form modifier uses the `form:*:magewire` hooks (the bare 1.x hooks only on 1.x), and the Magewire
  component runs on Magewire 1 and 3 (`updatedValues()` instead of `updated()`, v3 mode on Checkout 3 with or without
  `Hyva_CheckoutV1bc`). The modifier extends `AbstractEntityFormModifier` instead of the deprecated `EntityFormModifierInterface`.
- Found on a Hyvä Checkout 3.0.2 store and fixed: the component's `$attributes` property clashed with Magewire 3's own (a fatal
  error on every page; now `$checkoutAttributes`); a required attribute hidden by a rule blocked every order (Checkout 3 enforces
  required fields it sees as visible: it is not required while hidden, and the browser script sets `required` again when it shows
  it); a ticked checkbox of a customer section was not saved (Magewire 3 sends `values.<code>.0`); an unticked box of an address
  checkbox group reached the address as the option id `__rm__`; the checkbox groups of the new address dialog started as a single
  value and sent `true`.
- With `Hyva_CheckoutV1bc` disabled, an order refused at placement re-rendered the checkout with the order summary section in every
  position: the seven section blocks shared one Magewire key (from the template). Each block now has its own `cache_key`.

### Fixed before the first release
- The form modification listeners accept the extra arguments some Hyvä Checkout components pass (the alternative shipping form
  re-sends `form:updated` with the named arguments `property` and `value`, which ended the update request with an error).
- `composer.json`: Hyvä Checkout 1.3.15 or newer and Magewire are required, the main package as `^0.1.0`; the licence is OSL-3.0 only,
  like `LICENSE.txt`. Version 0.1.0 in `composer.json` and as `setup_version` in `etc/module.xml`.
