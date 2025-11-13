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
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryTime;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

class PerItemShippingCalculator
{
    private const DEFAULT_COUNTRY_ISO = 'DE';
    private const DEFAULT_ZIP = '00000';

    /** @var array<string,float> */
    private array $cache = [];

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly CachedSalesChannelContextFactory $contextFactory,
        private readonly CartService $cartService,
        private readonly EntityRepository $countryRepository,
        private readonly EntityRepository $shippingMethodRepository
    ) {
    }

    public function calculateForProduct(ProductEntity $product, SalesChannelContext $baseContext): ?float
    {
        $salesChannelId = $baseContext->getSalesChannelId();
        $shippingTarget = $this->resolveShippingTarget($baseContext);
        if ($shippingTarget === null) {
            return null;
        }

        $cacheKey = implode('|', [
            $product->getId(),
            $salesChannelId,
            $shippingTarget['cacheKey'],
        ]);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        $address = $this->createGuestAddress($shippingTarget['country'], $shippingTarget['zipcode']);
        $shippingLocation = ShippingLocation::createFromAddress($address);

        $token = 'keszler_ship_' . Uuid::randomHex();

        $options = [
            SalesChannelContextService::CURRENCY_ID => $baseContext->getCurrencyId(),
            SalesChannelContextService::LANGUAGE_ID => $baseContext->getLanguageId(),
            SalesChannelContextService::PAYMENT_METHOD_ID => $baseContext->getPaymentMethod()->getId(),
            SalesChannelContextService::COUNTRY_ID => $shippingTarget['country']->getId(),
        ];

        $calcContext = $this->contextFactory->create($token, $salesChannelId, $options);
        $calcContext->assign(['shippingLocation' => $shippingLocation]);

        $cart = $this->cartService->createNew($token);

        $lineItem = new LineItem($product->getId(), LineItem::PRODUCT_LINE_ITEM_TYPE, $product->getId(), 1);
        $lineItem->setStackable(true);
        $lineItem->setRemovable(false);
        $this->enrichLineItem($lineItem, $product);

        $cart->add($lineItem);

        $cart = $this->cartService->recalculate($cart, $calcContext);

        $shippingMethodBeforeValidation = $calcContext->getShippingMethod()?->getId();
        if (!$this->ensureValidShippingMethod($calcContext)) {
            return null;
        }

        if ($calcContext->getShippingMethod()?->getId() !== $shippingMethodBeforeValidation) {
            $cart = $this->cartService->recalculate($cart, $calcContext);
        }
        $total = 0.0;
        foreach ($cart->getDeliveries() as $delivery) {
            $total += $delivery->getShippingCosts()->getTotalPrice();
        }
        
        return $this->cache[$cacheKey] = $total;
    }

    /**
     * @return array{country: CountryEntity, zipcode: string, cacheKey: string}|null
     */
    private function resolveShippingTarget(SalesChannelContext $baseContext): ?array
    {
        $salesChannelId = $baseContext->getSalesChannelId();
        /** @var string|null $countryId */
        $countryId = $this->config->get('KeszlerShippingContextPreset.config.countryId', $salesChannelId);
        /** @var string|null $countryIso */
        $countryIso = $this->config->get('KeszlerShippingContextPreset.config.countryIso', $salesChannelId);
        /** @var string|int|null $configuredZipcode */
        $configuredZipcode = $this->config->get('KeszlerShippingContextPreset.config.zipcode', $salesChannelId);

        $zipcode = $configuredZipcode !== null ? trim((string) $configuredZipcode) : '';
        $zipcode = $zipcode === '' ? self::DEFAULT_ZIP : $zipcode;

        $countryIso = $countryIso !== null ? strtoupper((string) $countryIso) : '';
        $countryIso = $countryIso === '' ? self::DEFAULT_COUNTRY_ISO : $countryIso;

        $country = null;
        if (\is_string($countryId) && $countryId !== '') {
            $country = $this->loadCountryById($countryId, $baseContext);
        }

        if (!$country) {
            $country = $this->loadCountryByIso($countryIso, $baseContext);
        }

        if (!$country) {
            return null;
        }

        return [
            'country' => $country,
            'zipcode' => $zipcode,
            'cacheKey' => ($country->getId() ?? $countryIso) . ':' . $zipcode,
        ];
    }

    private function enrichLineItem(LineItem $lineItem, ProductEntity $product): void
    {
        $name = $product->getTranslation('name') ?? $product->getName();
        if ($name) {
            $lineItem->setLabel($name);
        }

        if ($product->getStates() !== null) {
            $lineItem->setStates($product->getStates());
        }

        $deliveryTime = null;
        if ($product->getDeliveryTime() !== null) {
            $deliveryTime = DeliveryTime::createFromEntity($product->getDeliveryTime());
        }

        // For our purposes, we always assume the product is in stock
        $stock = 1;

        $lineItem->setDeliveryInformation(
            new DeliveryInformation(
                $stock,
                $product->getWeight(),
                $product->getShippingFree() ?? false,
                $product->getRestockTime(),
                $deliveryTime,
                $product->getHeight(),
                $product->getWidth(),
                $product->getLength()
            )
        );
    }

    private function createGuestAddress(CountryEntity $country, string $zipcode): CustomerAddressEntity
    {
        $address = new CustomerAddressEntity();
        $address->setId('keszler-shipping-context-preset-address');
        $address->setFirstName('Guest');
        $address->setLastName('Checkout');
        $address->setZipcode($zipcode);
        $address->setCity('Default City');
        $address->setStreet('Default Street 1');
        $address->setCountry($country);
        $address->setCountryId($country->getId());

        return $address;
    }

    private function loadCountryById(string $countryId, SalesChannelContext $baseContext): ?CountryEntity
    {
        $criteria = new Criteria([$countryId]);

        /** @var ?CountryEntity $country */
        $country = $this->countryRepository->search($criteria, $baseContext->getContext())->first();

        return $country;
    }

    private function loadCountryByIso(string $countryIso, SalesChannelContext $baseContext): ?CountryEntity
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('iso', $countryIso))
            ->setLimit(1);

        /** @var ?CountryEntity $country */
        $country = $this->countryRepository->search($criteria, $baseContext->getContext())->first();

        return $country;
    }

    private function ensureValidShippingMethod(SalesChannelContext $context): bool
    {
        $current = $context->getShippingMethod();
        if ($current && $this->shippingMethodMatchesRules($current, $context)) {
            return true;
        }

        $replacement = $this->findFirstAvailableShippingMethod($context);
        if ($replacement === null) {
            return false;
        }

        $context->assign(['shippingMethod' => $replacement]);

        return true;
    }

    private function findFirstAvailableShippingMethod(SalesChannelContext $context): ?ShippingMethodEntity
    {
        $criteria = (new Criteria())
            ->addAssociation('prices')
            ->addFilter(new EqualsFilter('active', true))
            ->addFilter(new EqualsFilter('salesChannels.id', $context->getSalesChannelId()))
            ->addSorting(new FieldSorting('position'));

        $methods = $this->shippingMethodRepository->search($criteria, $context->getContext());

        /** @var ShippingMethodEntity $method */
        foreach ($methods->getEntities() as $method) {
            if ($this->shippingMethodMatchesRules($method, $context)) {
                return $method;
            }
        }

        return null;
    }

    private function shippingMethodMatchesRules(ShippingMethodEntity $method, SalesChannelContext $context): bool
    {
        $availabilityRuleId = $method->getAvailabilityRuleId();
        if ($availabilityRuleId === null) {
            return true;
        }

        return in_array($availabilityRuleId, $context->getRuleIds(), true);
    }
}
