<?php declare(strict_types=1);

namespace KeszlerShippingContextPreset\Util;

final class ShippingOverrideSessionKeys
{
    public const COUNTRY = 'keszler_shipping_context_preset.countryIso';
    public const ZIPCODE = 'keszler_shipping_context_preset.zipcode';

    private function __construct()
    {
    }
}
