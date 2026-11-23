<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributesHyvaCheckout\Magewire;

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use MageOS\CustomerCustomAttributes\Model\Checkout\CustomerCheckoutAttributes;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magewirephp\Magewire\Component;
use Magewirephp\Magewire\Features\SupportMagewireBackwardsCompatibility\HandleBackwardsCompatibility;

/**
 * One section of the checkout with the customer attributes of one position (above or below the shipping address, the shipping
 * methods or the payment methods, or the order summary). The typed values are stored on the quote right away, never on the
 * customer account, and the section blocks placing the order while a required value is missing. One instance per position,
 * declared as virtual types in etc/frontend/di.xml.
 *
 * Runs on Magewire 1 (Hyvä Checkout 1.3) and on Magewire 3 (Hyvä Checkout 3). On Checkout 3 the attribute keeps it in v3 mode,
 * so it works the same whether or not Hyvä's v1 compatibility module (Hyva_CheckoutV1bc) is enabled; Magewire 1 ignores it.
 * Only APIs both versions have are used: `$listeners`, `emit()`, `mount()` and the property hook `updatedValues()`. Property
 * names must not clash with Magewire 3's own (`$attributes`, `$loader`, `$id`, ...), which would be a fatal error on every page.
 */
#[HandleBackwardsCompatibility(enabled: false)]
class CustomerAttributes extends Component implements EvaluationInterface
{
    private const PREFIX = 'values.';

    /** @var array<string, string|list<string>> attribute code => value */
    public array $values = [];

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerCheckoutAttributes $checkoutAttributes,
        private readonly string $position
    ) {
        // Other sections listen when a value of another section may hide or show their fields.
        if ($this->checkoutAttributes->hasPosition($this->position)) {
            $this->listeners = ['mageos_cca_values_changed' => 'reload'];
        }
    }

    public function mount(): void
    {
        $this->checkoutAttributes->initialize($this->checkoutSession->getQuote());
        $this->reload();
    }

    public function getPosition(): string
    {
        return $this->position;
    }

    /**
     * @return list<array<string, mixed>> the fields of this position for the template
     */
    public function getFields(): array
    {
        $fields = $this->checkoutAttributes->getFields($this->checkoutSession->getQuote(), $this->position);
        foreach ($fields as &$field) {
            $field['wire'] = self::PREFIX . $field['code'];
        }
        return $fields;
    }

    public function reload(): void
    {
        $values = [];
        foreach ($this->checkoutAttributes->getFields($this->checkoutSession->getQuote(), $this->position) as $field) {
            $values[$field['code']] = $field['value'];
        }
        $this->values = $values;
    }

    /**
     * Called by Magewire after the values changed: stores the values of this section, lets the rules drop what became hidden
     * and tells the other sections. The property hook has the same argument order on both versions; only the key differs:
     * - Magewire 1 passes the whole array and the property name "values" (also for an update of one value);
     * - Magewire 3 passes one value and its attribute code for an update of "values.<code>", the item and "<code>.<n>" for an item
     *   of a list (a checkbox group), or the whole array and null.
     */
    public function updatedValues(mixed $value, mixed $key = null): mixed
    {
        $own = $this->checkoutAttributes->getCodesForPosition($this->position);
        // Magewire 3 sends a changed list item on its own ("e2e_multi.0" for a ticked checkbox of a group): the property already
        // holds the whole list, so the attribute's value is read from it.
        if (is_string($key) && str_contains($key, '.')) {
            $key = strstr($key, '.', true);
            $value = $this->values[$key] ?? $value;
        }
        if (($key === null || $key === 'values') && is_array($value)) {
            $submitted = array_intersect_key($value, array_flip($own));
        } elseif (is_string($key) && in_array($key, $own, true)) {
            $submitted = [$key => $value];
        } else {
            return $this->values;
        }
        // An unticked box of a list arrives as the marker "__rm__" if Magewire has not removed it yet: it is not a value.
        foreach ($submitted as $code => $submittedValue) {
            if (is_array($submittedValue)) {
                $submitted[$code] = array_values(array_filter($submittedValue, static fn ($item): bool => $item !== '__rm__'));
            }
        }
        $this->checkoutAttributes->saveValues($this->checkoutSession->getQuote(), $submitted);
        $this->reload();
        $this->emit('mageos_cca_values_changed');
        // Magewire 1 assigns what the hook returns to the property; Magewire 3 ignores it and keeps what reload() set.
        return $this->values;
    }

    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        $codes = $this->checkoutAttributes->getCodesForPosition($this->position);
        $messages = $codes === [] ? [] : $this->checkoutAttributes->validate($this->checkoutSession->getQuote(), $codes);
        if ($messages !== []) {
            return $resultFactory->createErrorMessage()->withMessage(implode(' ', array_unique($messages)))->asError();
        }
        return $resultFactory->createSuccess();
    }
}
