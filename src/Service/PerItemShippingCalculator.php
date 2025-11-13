<?php declare(strict_types=1);

namespace KeszlerShippingContextPreset\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Content\Product\ProductEntity;

class PerItemShippingCalculator
{
    /** @var array<string,float> */
    private array $cache = [];

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly CachedSalesChannelContextFactory $contextFactory,
        private readonly CartService $cartService,
        private readonly EntityRepository $countryRepository
    ) {
    }

    public function calculateForProduct(ProductEntity $product, SalesChannelContext $baseContext): ?float
    {
        $salesChannelId = $baseContext->getSalesChannelId();

        /** @var string|null $countryId */
        $countryId = $this->config->get('KeszlerShippingContextPreset.config.countryId', $salesChannelId);
        /** @var string|null $countryIso */
        $countryIso = $this->config->get('KeszlerShippingContextPreset.config.countryIso', $salesChannelId);
        /** @var string|int|null $configuredZipcode */
        $configuredZipcode = $this->config->get('KeszlerShippingContextPreset.config.zipcode', $salesChannelId);
        $zipcode = $configuredZipcode !== null ? trim((string) $configuredZipcode) : null;
        $zipcode = $zipcode === '' ? null : $zipcode;
        
        if (!$countryId && !$countryIso) {
            return null;
        }
        
        $cacheKey = implode('|', [
            $product->getId(),
            $salesChannelId,
            (string) ($countryId ?? ''),
            strtoupper((string) ($countryIso ?? '')),
            (string) ($zipcode ?? ''),
        ]);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        if ($countryId) {
            $criteria = new Criteria([$countryId]);
            /** @var ?\Shopware\Core\System\Country\CountryEntity $country */
            $country = $this->countryRepository->search($criteria, $baseContext->getContext())->first();
        } else {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('iso', strtoupper((string) $countryIso)));
            /** @var ?\Shopware\Core\System\Country\CountryEntity $country */
            $country = $this->countryRepository->search($criteria, $baseContext->getContext())->first();
        }

        if (!$country) {
            return null;
        }

        $shippingLocation = ShippingLocation::createFromCountry($country);
        if ($zipcode) {
            $address = new CustomerAddressEntity();
            $address->setId(Uuid::randomHex());
            $address->setFirstName('Shipping');
            $address->setLastName('Estimator');
            $address->setZipcode($zipcode);
            $address->setCity('Estimator City');
            $address->setStreet('Estimator Street 1');
            $address->setCountry($country);
            $address->setCountryId($country->getId());

            $shippingLocation = ShippingLocation::createFromAddress($address);
        }

        $token = 'keszler_ship_' . Uuid::randomHex();

        $options = [
            SalesChannelContextService::CURRENCY_ID => $baseContext->getCurrencyId(),
            SalesChannelContextService::LANGUAGE_ID => $baseContext->getLanguageId(),
            SalesChannelContextService::PAYMENT_METHOD_ID => $baseContext->getPaymentMethod()->getId(),
            SalesChannelContextService::COUNTRY_ID => $country->getId(),
            //SalesChannelContextService::SHIPPING_METHOD_ID => $baseContext->getShippingMethod()->getId(),
        ];

        $calcContext = $this->contextFactory->create($token, $salesChannelId, $options);
        $calcContext->assign(['shippingLocation' => $shippingLocation]);

        $cart = $this->cartService->createNew($token);
        
        $lineItem = new LineItem($product->getId(), LineItem::PRODUCT_LINE_ITEM_TYPE, $product->getId(), 1);
        $lineItem->setStackable(true);
        $lineItem->setRemovable(false);

        $cart->add($lineItem);
        $cart->add($lineItem);

        $cart = $this->cartService->recalculate($cart, $calcContext);
        $total = 0.0;
        foreach ($cart->getDeliveries() as $delivery) {
            $total += $delivery->getShippingCosts()->getTotalPrice();
        }
        
        return $this->cache[$cacheKey] = $total;
    }
}
