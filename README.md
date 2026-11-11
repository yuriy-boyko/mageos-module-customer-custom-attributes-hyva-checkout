# MageOS_CustomerCustomAttributesHyvaCheckout

Hyvä Checkout integration for [mage-os/module-customer-custom-attributes](https://github.com/yuriy-boyko/mageos-module-customer-custom-attributes).

Version 0.1.0, initial version. **The version always follows the main module**: both packages are tagged with the same version
and should be installed on the same version.

Install it together with Hyvä Checkout. The main module works without it (the custom address attributes are then simply not
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
the containers of the one-page checkout). It stores the typed values on the quote (never on the customer account), blocks placing
the order while a required value is missing and refreshes the other sections when a rule may show or hide their fields. The logic
is in the main module's `Model/Checkout/CustomerCheckoutAttributes`.

The browser script, the saved-address notice and the field templates stay in the main module.

## Installation

```bash
composer require mage-os/module-customer-custom-attributes-hyva-checkout
bin/magento module:enable MageOS_CustomerCustomAttributesHyvaCheckout
bin/magento setup:upgrade
```

## License

OSL-3.0 / AFL-3.0, see `LICENSE.txt`.
