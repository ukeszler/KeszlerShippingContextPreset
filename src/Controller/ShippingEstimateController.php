<?php declare(strict_types=1);

namespace KeszlerShippingContextPreset\Controller;

use KeszlerShippingContextPreset\Util\ShippingOverrideSessionKeys;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class ShippingEstimateController extends StorefrontController
{
    #[Route(path: '/shipping/estimate', name: 'frontend.shipping.estimate', methods: ['GET'], defaults: ['XmlHttpRequest' => true])]
    public function estimate(Request $request): JsonResponse
    {
        $countryIso = strtoupper((string) $request->query->get('countryIso', ''));
        $zipcode = trim((string) $request->query->get('zipcode', ''));

        if ($countryIso === '' || $zipcode === '') {
            return new JsonResponse(
                ['success' => false, 'message' => 'countryIso and zipcode are required'],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if (!$session instanceof SessionInterface) {
            return new JsonResponse(
                ['success' => false, 'message' => 'Session is not available'],
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $session->set(ShippingOverrideSessionKeys::COUNTRY, $countryIso);
        $session->set(ShippingOverrideSessionKeys::ZIPCODE, $zipcode);

        return new JsonResponse([
            'success' => true,
            'countryIso' => $countryIso,
            'zipcode' => $zipcode,
        ]);
    }
}
