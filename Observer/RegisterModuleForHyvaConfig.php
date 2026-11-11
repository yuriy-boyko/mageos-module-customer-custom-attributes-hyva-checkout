<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributesHyvaCheckout\Observer;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Registers the module in the Hyvä modules configuration (app/etc/hyva-themes.json, written by `bin/magento
 * hyva:config:generate`), so the Tailwind build of a Hyvä theme includes the module's templates.
 */
class RegisterModuleForHyvaConfig implements ObserverInterface
{
    public function __construct(private readonly ComponentRegistrar $componentRegistrar)
    {
    }

    public function execute(Observer $observer): void
    {
        $config = $observer->getData('config');
        $extensions = $config->hasData('extensions') ? $config->getData('extensions') : [];
        $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, 'MageOS_CustomerCustomAttributesHyvaCheckout');
        if ($path === null) {
            return;
        }
        // Only the path relative to the Magento base directory
        $extensions[] = ['src' => substr($path, strlen(BP) + 1)];
        $config->setData('extensions', $extensions);
    }
}
