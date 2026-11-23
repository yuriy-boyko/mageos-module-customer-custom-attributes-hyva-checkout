# MageOS_CustomerCustomAttributesHyvaCheckout

Hyvä Checkout integration for [mage-os/module-customer-custom-attributes](https://github.com/yuriy-boyko/mageos-module-customer-custom-attributes).

Version 0.1.0, initial version. **The version always follows the main module**: both packages are tagged with the same version
and should be installed on the same version.

Install it together with Hyvä Checkout **1.3.15 or newer** (the first version with the EAV address attributes the main module hooks
into); 1.3.15 is the minimum. The same code runs on Hyvä Checkout 1.3 (Magewire 1) and 3.x (Magewire 3): see "Hyvä Checkout
versions" below. It is tested in stores on 1.3.15 and 3.0.2. The main module works without it (the custom address attributes are then simply not
changed in the checkout by rules); the main module cannot contain this code because its classes implement Hyvä Checkout
interfaces, which would break `setup:di:compile` on stores without Hyvä Checkout.

## What it does

`Model/AttributeVisibilityModifier` is registered on the Hyvä Checkout shipping and billing address forms (`etc/frontend/di.xml`):

- sets the starting visibility of the custom address attributes from the dependency rules and the customer group;
- renders attributes hidden by a rule with a hidden wrapper, so the main module's browser script shows and hides them
  instantly with no request;
- does not render attributes the customer group may not see;
- clears the values of hidden attributes;
- applies the "Display As" setting (radio buttons, checkboxes).

`Magewire/CustomerAttributes` renders the customer attributes ticked for "Hyvä Checkout" in sections at seven positions (one virtual
type per position in `etc/frontend/di.xml`, one block per position in `view/frontend/layout/hyva_checkout_index_index.xml`, placed in
Hyvä Checkout's shared component containers, which every checkout type moves into its steps). On the multi-step checkout the order
summary section is shown on both steps, so a required attribute placed there already blocks leaving the shipping step; on the mobile
checkout it is on the last step only. It stores the typed values on the quote (never on the customer account), blocks placing
the order while a required value is missing and refreshes the other sections when a rule may show or hide their fields. The logic
is in the main module's `Model/Checkout/CustomerCheckoutAttributes`.

The browser script, the saved-address notice and the field templates stay in the main module.

## Hyvä Checkout versions

- **Form hooks.** The modifier listens on `form:boot:magewire` and `form:updated:magewire`, which the Form API components
  dispatch on both lines. On 1.x it also listens on the bare `form:boot` and `form:updated`, the only hooks of the default 1.x
  address components. Hyvä Checkout 3 re-sends the bare hooks itself as deprecated copies, so there they are not used and
  nothing appears in "Log Deprecated Form Hook Usage". A theme on 3.x that still renders the deprecated 1.x address components
  gets no server-side visibility from this package; the browser script and the checks at order placement in the main module
  still apply.
- **Magewire component.** `CustomerAttributes` uses only what Magewire 1 and 3 both have (`$listeners`, `emit()`, `mount()` and
  the property hook `updatedValues()`, whose arguments come in the same order on both). On Checkout 3 it carries
  `#[HandleBackwardsCompatibility(enabled: false)]`, so it runs in v3 mode whether `Hyva_CheckoutV1bc` is enabled or not.
- **Required fields.** Checkout 3 refuses an empty required field it sees as visible when a form is submitted. A required
  attribute hidden by a rule is therefore not required while it is hidden.
- **Block keys.** Magewire 3 keys a nested component by its block's cache key, which by default comes from the template. The
  seven section blocks share one template, so each has a `cache_key` argument of its own in the layout (no block cache: there is
  no cache lifetime). Without it a re-render of the checkout (an order refused at placement with `Hyva_CheckoutV1bc` disabled)
  showed one section in every position.

## Installation

```bash
composer require mage-os/module-customer-custom-attributes-hyva-checkout
bin/magento module:enable MageOS_CustomerCustomAttributesHyvaCheckout
bin/magento setup:upgrade
```

## License

OSL-3.0, see `LICENSE.txt`.
