<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributesHyvaCheckout\Model;

use Hyva\Checkout\Magewire\Component\AbstractAddressForm;
use Hyva\Checkout\Model\Form\AbstractEntityForm;
use Hyva\Checkout\Model\Form\AbstractEntityFormModifier;
use Hyva\Checkout\Model\Form\EntityFieldInterface;
use Hyva\Checkout\Model\Form\EntityFormElementInterface;
use Hyva\Checkout\Model\Form\EntityFormInterface;
use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\Dependency\RuleProvider;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use MageOS\CustomerCustomAttributes\Service\ValueNormalizer;
use MageOS\CustomerCustomAttributes\Service\VisibilityResolver;
use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * Hyvä Checkout shipping and billing address forms: hides the custom address attributes whose dependency rule or customer
 * group restriction does not apply, and shows them again when it does.
 *
 * - After the form is filled (boot) and after every update the visible attributes are worked out from the current values with
 *   the shared VisibilityResolver and the value of a hidden attribute is cleared.
 * - Hooks: the Magewire components of the Form API (`AbstractForm`, the address forms of Hyvä Checkout 3 and the alternative
 *   shipping form of 1.3) dispatch `form:boot:magewire` and `form:updated:magewire` (Checkout 3 syncs field values a listener changes back to
 *   the component data). The 1.x address components (`AbstractMagewireAddressForm`, the default on 1.3) dispatch only the bare
 *   `form:boot` and `form:updated`. On 1.x both are listened to. Hyvä Checkout 3 re-emits the bare hooks itself as deprecated
 *   copies, so there only the `:magewire` hooks are used.
 * - An attribute the customer group may not see is not rendered at all (that cannot change while the customer edits).
 * - An attribute hidden only by a dependency rule is rendered with a "hidden" wrapper class, so the browser script
 *   (MageOS_CustomerCustomAttributes::hyva-checkout/visibility.phtml) can show and hide it instantly in both directions with no request.
 *   The server only sets this starting state and clears the values.
 * - While hidden by a rule a required attribute is not required (Checkout 3 enforces required fields it sees as visible on submit,
 *   and the wrapper class does not make a field invisible to it). A required attribute carries `data-mageos-cca-required`, so the
 *   browser script sets `required` again when it shows the field.
 */
class AttributeVisibilityModifier extends AbstractEntityFormModifier
{
    private const DATA_REQUIRED = 'mageos_cca_required';
    private const REMOVED_ITEM = '__rm__';

    public function __construct(
        private readonly RuleProvider $ruleProvider,
        private readonly VisibilityResolver $resolver,
        private readonly AddressAttributeProvider $attributeProvider,
        private readonly ValueNormalizer $normalizer,
        private readonly CheckoutSession $checkoutSession,
        private readonly ValidateRulesBuilder $rulesBuilder
    ) {
    }

    public function apply(AbstractEntityForm $form): AbstractEntityForm
    {
        // The form is always the first argument of a modification listener. Hooks pass more, also as named arguments (the
        // component, "property" and "value"): the listeners accept and ignore them, otherwise PHP would end the request with
        // "Unknown named parameter".
        $resolve = fn (EntityFormInterface $form, mixed ...$rest): EntityFormInterface => $this->resolve($form);
        // Dropdown, yes/no and multiple select attributes set to display as radio buttons or checkboxes use our group renderer.
        $form->registerModificationListener(
            'mageos_cca_choice_display',
            'form:build',
            fn (EntityFormInterface $form, mixed ...$rest): EntityFormInterface => $this->applyChoiceDisplay($form)
        );
        $form->registerModificationListener('mageos_cca_visibility_boot_magewire', 'form:boot:magewire', $resolve);
        $form->registerModificationListener('mageos_cca_visibility_updated_magewire', 'form:updated:magewire', $resolve);
        // Checkout 3 refuses an empty required field it sees as visible when the form is submitted: resolve once more with the
        // submitted values first, so a field hidden by a rule is not required then.
        $form->registerModificationListener('mageos_cca_visibility_submitting_magewire', 'form:submitting:magewire', $resolve);
        // Render time: also covers forms opened after boot (the new address dialog), whose checkbox groups must start as lists.
        $form->registerModificationListener('mageos_cca_visibility_build_magewire', 'form:build:magewire', $resolve);
        if (!self::reEmitsLegacyHooks()) {
            // Hyvä Checkout 1.x: the 1.x address components dispatch only these. The alternative shipping form dispatches both;
            // resolving twice gives the same result.
            $form->registerModificationListener('mageos_cca_visibility_boot', 'form:boot', $resolve);
            $form->registerModificationListener('mageos_cca_visibility_updated', 'form:updated', $resolve);
        }
        return $form;
    }

    /**
     * Whether Hyvä Checkout dispatches the bare 1.x hooks itself as deprecated copies of the `:magewire` hooks (Checkout 3).
     * A listener on them would then run twice and be reported by "Log Deprecated Form Hook Usage".
     */
    private static function reEmitsLegacyHooks(): bool
    {
        return method_exists(AbstractAddressForm::class, 'dispatchLegacyModificationHook');
    }

    private function applyChoiceDisplay(EntityFormInterface $form): EntityFormInterface
    {
        foreach ($this->attributeProvider->getUserDefined() as $code => $attribute) {
            $field = $form->getField($code);
            if ($field === null) {
                continue;
            }
            $display = $this->rulesBuilder->getDisplay((string) $attribute->getFrontendInput(), (array) $attribute->getValidateRules());
            if ($display !== '') {
                $field->setData(EntityFieldInterface::INPUT_ALIAS, $display === ValidateRulesBuilder::DISPLAY_CHECKBOX ? 'mageos_checkbox_group' : 'mageos_radio_group');
            }
        }
        return $form;
    }

    private function isCheckboxGroup(object $attribute): bool
    {
        return $this->rulesBuilder->getDisplay((string) $attribute->getFrontendInput(), (array) $attribute->getValidateRules())
            === ValidateRulesBuilder::DISPLAY_CHECKBOX;
    }

    private function setHiddenClass(object $field, bool $hidden): void
    {
        $classes = (array) ($field->getData(EntityFormElementInterface::CLASS_WRAPPER) ?? []);
        unset($classes['mageos_cca_hidden']);
        if ($hidden) {
            $classes['mageos_cca_hidden'] = 'hidden';
        }
        $field->setData(EntityFormElementInterface::CLASS_WRAPPER, $classes);
    }

    private function resolve(EntityFormInterface $form): EntityFormInterface
    {
        $codes = [];
        $values = [];
        $attributes = $this->attributeProvider->getUserDefined();
        foreach ($attributes as $code => $attribute) {
            $field = $form->getField($code);
            if ($field === null) {
                continue;
            }
            $codes[] = $code;
            $values[$code] = $this->normalizer->normalizeForInput((string) $attribute->getFrontendInput(), $field->getValue());
        }
        if ($codes === []) {
            return $form;
        }

        $restrictions = $this->ruleProvider->getGroupRestrictions('address');
        $groupId = (int) $this->checkoutSession->getQuote()->getCustomerGroupId();
        // Allowed for the customer group, ignoring the dependency rules, and visible with them.
        $allowed = array_flip($this->resolver->resolve($codes, $values, [], $restrictions, $groupId));
        $visible = array_flip($this->resolver->resolve($codes, $values, $this->ruleProvider->getRules('address'), $restrictions, $groupId));

        foreach ($codes as $code) {
            $field = $form->getField($code);
            // Whether the attribute is required when it is shown, remembered before the first change in this request
            $required = $field->getData(self::DATA_REQUIRED);
            if ($required === null) {
                $required = $field->isRequired();
                $field->setData(self::DATA_REQUIRED, $required);
            }
            $field->setData(EntityFieldInterface::IS_REQUIRED, $required && isset($visible[$code]));
            if ($required) {
                $field->setAttribute('data-mageos-cca-required', '1');
            }
            if (!isset($allowed[$code])) {
                $field->hide();
                $field->setValue(null);
                continue;
            }
            $field->show();
            $this->setHiddenClass($field, !isset($visible[$code]));
            if (!isset($visible[$code])) {
                $field->setValue(null);
            } elseif ($this->isCheckboxGroup($attributes[$code])) {
                // A group of checkboxes needs a list as its starting value: with null the browser sends a single true for a ticked box.
                // Magewire 3 sends an unticked box of a list as the marker "__rm__" (remove this item): it is not a value.
                $field->setValue(array_values(array_filter(
                    $this->normalizer->toList($field->getValue()),
                    static fn ($item): bool => $item !== self::REMOVED_ITEM
                )));
            }
        }
        return $form;
    }

}
