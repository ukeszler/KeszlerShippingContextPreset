<?php declare(strict_types=1);

namespace KeszlerShippingContextPreset\Subscriber;

use KeszlerShippingContextPreset\Util\ShippingOverrideSessionKeys;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextCreatedEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class ShippingContextPresetSubscriber implements EventSubscriberInterface
{
    private const DEFAULT_ZIP = '00000';
    private const DEFAULT_COUNTRY_ISO = 'DE';

    public function __construct(
        private readonly EntityRepository $countryRepository,
        private readonly RequestStack $requestStack
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SalesChannelContextCreatedEvent::class => ['onSalesChannelContextCreated', 200],
        ];
    }

    public function onSalesChannelContextCreated(SalesChannelContextCreatedEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();

        if ($salesChannelContext->getCustomer() !== null) {
            return;
        }

        $override = $this->getSessionOverride();
        $targetCountryIso = $override['countryIso'] ?? self::DEFAULT_COUNTRY_ISO;
        $targetZip = $override['zipcode'] ?? self::DEFAULT_ZIP;

        $currentCountryIso = strtoupper((string) $salesChannelContext->getShippingLocation()->getCountry()?->getIso());
        $currentZip = $salesChannelContext->getShippingLocation()->getAddress()?->getZipcode();

        if ($currentCountryIso === $targetCountryIso && $currentZip === $targetZip) {
            return;
        }

        $country = $this->loadCountry($salesChannelContext, $targetCountryIso);
        if (!$country instanceof CountryEntity) {
            return;
        }

        $address = $this->createGuestAddress($country, $targetZip);
        $salesChannelContext->assign([
            'shippingLocation' => ShippingLocation::createFromAddress($address),
        ]);
    }

    private function loadCountry(SalesChannelContext $context, string $countryIso): ?CountryEntity
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('iso', $countryIso))
            ->setLimit(1);

        return $this->countryRepository
            ->search($criteria, $context->getContext())
            ->first();
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

    /**
     * @return array{countryIso: string, zipcode: string}|null
     */
    private function getSessionOverride(): ?array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();
        $countryIso = $session->get(ShippingOverrideSessionKeys::COUNTRY);
        $zipcode = $session->get(ShippingOverrideSessionKeys::ZIPCODE);

        if (!\is_string($countryIso) || $countryIso === '') {
            return null;
        }

        if (!\is_string($zipcode) || $zipcode === '') {
            return null;
        }

        return [
            'countryIso' => strtoupper($countryIso),
            'zipcode' => trim($zipcode),
        ];
    }
}
