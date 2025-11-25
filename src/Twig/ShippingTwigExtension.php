<?php declare(strict_types=1);

namespace KeszlerShippingContextPreset\Twig;

use KeszlerShippingContextPreset\Service\PerItemShippingCalculator;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Shopware\Core\Content\Product\ProductEntity;

class ShippingTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly PerItemShippingCalculator $calculator,
        private readonly CachedSalesChannelContextFactory $contextFactory,
        private readonly RequestStack $requestStack
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('perItemShipping', [$this, 'perItemShipping']),
        ];
    }

    /**
     * @param ProductEntity|array|string $product Product entity or id or array with ['id' => ..]
     * @param string|null $zipcode Optional zipcode override for the shipping calculation
     */
    public function perItemShipping($product, ?string $salesChannelId = null, ?string $zipcode = null): ?float
    {
        $prod = null;
        if ($product instanceof ProductEntity) {
            $prod = $product;
        } elseif (is_array($product) && isset($product['id'])) {
            $prod = (new ProductEntity());
            $prod->setUniqueIdentifier($product['id']);
            $prod->setId($product['id']);
        } elseif (is_string($product)) {
            $prod = (new ProductEntity());
            $prod->setUniqueIdentifier($product);
            $prod->setId($product);
        }

        if (!$prod) {
            return null;
        }

        $scContext = $this->resolveSalesChannelContext($salesChannelId);
        if (!$scContext) {
            return null;
        }

        return $this->calculator->calculateForProduct($prod, $scContext, $zipcode);
    }

    private function resolveSalesChannelContext(?string $salesChannelId): ?SalesChannelContext
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $sc = $request->attributes->get('sw-sales-channel-context');
            if ($sc instanceof SalesChannelContext) {
                return $sc;
            }
            $sc = $request->attributes->get('sw-sales-channel-context-object');
            if ($sc instanceof SalesChannelContext) {
                return $sc;
            }
        }

        if (!$salesChannelId) {
            return null;
        }

        $token = 'keszler_ship_twig_' . Uuid::randomHex();
        return $this->contextFactory->create($token, $salesChannelId, []);
    }
}
