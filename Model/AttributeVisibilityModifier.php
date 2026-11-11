<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributesHyvaCheckout\Model;

use Hyva\Checkout\Model\Form\EntityFieldInterface;
use Hyva\Checkout\Model\Form\EntityFormElementInterface;
use Hyva\Checkout\Model\Form\EntityFormInterface;
use Hyva\Checkout\Model\Form\EntityFormModifierInterface;
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
 * - After the form is filled (form:boot) and after every update (form:updated) the visible attributes are worked out from the
 *   current values with the shared VisibilityResolver and the value of a hidden attribute is cleared.
 * - An attribute the customer group may not see is not rendered at all (that cannot change while the customer edits).
 * - An attribute hidden only by a dependency rule is rendered with a "hidden" wrapper class, so the browser script
 *   (MageOS_CustomerCustomAttributes::hyva-checkout/visibility.phtml) can show and hide it instantly in both directions with no request.
 *   The server only sets this starting state and clears the values.
 */
class AttributeVisibilityModifier implements EntityFormModifierInterface
{
    public function __construct(
        private readonly RuleProvider $ruleProvider,
        private readonly VisibilityResolver $resolver,
        private readonly AddressAttributeProvider $attributeProvider,
        private readonly ValueNormalizer $normalizer,
        private readonly CheckoutSession $checkoutSession,
        private readonly ValidateRulesBuilder $rulesBuilder
    ) {
    }

    public function apply(EntityFormInterface $form): EntityFormInterface
    {
        // The form is always the first argument of a modification listener.
        $resolve = fn (EntityFormInterface $form): EntityFormInterface => $this->resolve($form);
        // Dropdown, yes/no and multiple select attributes set to display as radio buttons or checkboxes use our group renderer.
        $form->registerModificationListener(
            'mageos_cca_choice_display',
            'form:build',
            fn (EntityFormInterface $form): EntityFormInterface => $this->applyChoiceDisplay($form)
        );
        $form->registerModificationListener('mageos_cca_visibility_boot', 'form:boot', $resolve);
        $form->registerModificationListener('mageos_cca_visibility_updated', 'form:updated', $resolve);
        return $form;
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
        foreach ($this->attributeProvider->getUserDefined() as $code => $attribute) {
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
            if (!isset($allowed[$code])) {
                $field->hide();
                $field->setValue(null);
                continue;
            }
            $field->show();
            $this->setHiddenClass($field, !isset($visible[$code]));
            if (!isset($visible[$code])) {
                $field->setValue(null);
            }
        }
        return $form;
    }

}
