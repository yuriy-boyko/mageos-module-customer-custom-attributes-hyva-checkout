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

/**
 * One section of the checkout with the customer attributes of one position (above or below the shipping address, the shipping
 * methods or the payment methods, or the order summary). The typed values are stored on the quote right away, never on the
 * customer account, and the section blocks placing the order while a required value is missing. One instance per position,
 * declared as virtual types in etc/frontend/di.xml.
 */
class CustomerAttributes extends Component implements EvaluationInterface
{
    private const PREFIX = 'values.';

    /** @var array<string, string|list<string>> attribute code => value */
    public array $values = [];

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerCheckoutAttributes $attributes,
        private readonly string $position
    ) {
        // Other sections listen when a value of another section may hide or show their fields.
        if ($this->attributes->hasPosition($this->position)) {
            $this->listeners = ['mageos_cca_values_changed' => 'reload'];
        }
    }

    public function mount(): void
    {
        $this->attributes->initialize($this->checkoutSession->getQuote());
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
        $fields = $this->attributes->getFields($this->checkoutSession->getQuote(), $this->position);
        foreach ($fields as &$field) {
            $field['wire'] = self::PREFIX . $field['code'];
        }
        return $fields;
    }

    public function reload(): void
    {
        $values = [];
        foreach ($this->attributes->getFields($this->checkoutSession->getQuote(), $this->position) as $field) {
            $values[$field['code']] = $field['value'];
        }
        $this->values = $values;
    }

    /**
     * Called by Magewire after a property changed, either one value ("values.<code>") or the whole array ("values"): stores the
     * values of this section, lets the rules drop what became hidden and tells the other sections.
     */
    public function updated(mixed $value, string $name): mixed
    {
        $own = $this->attributes->getCodesForPosition($this->position);
        if ($name === 'values' && is_array($value)) {
            $submitted = array_intersect_key($value, array_flip($own));
        } elseif (strpos($name, self::PREFIX) === 0 && in_array(substr($name, strlen(self::PREFIX)), $own, true)) {
            $submitted = [substr($name, strlen(self::PREFIX)) => $value];
        } else {
            return $value;
        }
        $this->attributes->saveValues($this->checkoutSession->getQuote(), $submitted);
        $this->reload();
        $this->emit('mageos_cca_values_changed');
        return $name === 'values' ? $this->values : ($this->values[substr($name, strlen(self::PREFIX))] ?? $value);
    }

    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        $codes = $this->attributes->getCodesForPosition($this->position);
        $messages = $codes === [] ? [] : $this->attributes->validate($this->checkoutSession->getQuote(), $codes);
        if ($messages !== []) {
            return $resultFactory->createErrorMessage()->withMessage(implode(' ', array_unique($messages)))->asError();
        }
        return $resultFactory->createSuccess();
    }
}
