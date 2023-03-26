<?php

namespace MovaviShopBundle\Tests\Functional\Controller;

use AppBundle\Client\SberbankProxyClient;
use AppBundle\Entity\AvangateProduct;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Movavi\UserBundle\DataFixtures\ORM\UserFixtures;
use MovaviCMSBundle\DataFixtures\FixtureAwareTestCase;
use MovaviShopBundle\DataFixtures\ORM\CouponFixtures;
use MovaviShopBundle\DataFixtures\ORM\CurrencyExceptionFixtures;
use MovaviShopBundle\DataFixtures\ORM\CurrencyFixtures;
use MovaviShopBundle\DataFixtures\ORM\LocalizationFixtures;
use MovaviShopBundle\DataFixtures\ORM\PageFixtures;
use MovaviShopBundle\DataFixtures\ORM\PriceFixtures;
use MovaviShopBundle\DataFixtures\ORM\ProductFixtures;
use MovaviShopBundle\DataFixtures\ORM\RouteFixtures;
use MovaviShopBundle\DataFixtures\ORM\SiteFixtures;
use MovaviShopBundle\DataFixtures\ORM\WidgetFixtures;
use MovaviShopBundle\Entity\Currency;
use MovaviShopBundle\Entity\ExternalIdReference;
use MovaviShopBundle\Entity\PaymentMethod;
use MovaviShopBundle\Entity\PaymentProcessor;
use MovaviShopBundle\Entity\PriceOption;
use MovaviShopBundle\Entity\Product;
use MovaviShopBundle\Entity\ProductBuyLink;
use MovaviShopBundle\WebUid\WebUid;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @group   functional
 *
 * @covers  \AppBundle\Payment\Avangate\Processor\TwoPayJsByAvangatePaymentProcessor
 * @covers  \MovaviShopBundle\Form\Mapper\Checkout\TwoPayJsFormToCartMapper
 * @covers  \MovaviShopBundle\Form\Type\Checkout\BillingDetails2PayJsType
 * @covers  \MovaviShopBundle\Form\Type\Checkout\TwoPayJsCartType
 */
class PaymentControllerTest extends FixtureAwareTestCase
{
    private KernelBrowser $client;
    private ?int $priceOptionId = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->loadAdditionalFixtures();

        $this->client = $this->getKernelClient();
    }

    /**
     * @dataProvider requestTo2PayJsCheckoutData
     */
    public function test2PayJsCheckoutAction(callable $getContent, array $expectedResponse, bool $isException): void
    {
        $avangateProxy = $this->createMock(Client::class);
        $mockResponse  = $this->createMock(ResponseInterface::class);
        if ($isException) {
            $exception     = $this->createMock(ClientException::class);
            $exceptionData = $this->createMock(StreamInterface::class);
            $exceptionData->method('getContents')->willReturn(
                json_encode([
                    'error_code' => 'INVALID_EES_TOKEN',
                    'message'    => 'The token is not valid. In order to proceed with the place order a valid token is required',
                ])
            );
            $mockResponse->method('getBody')->willReturn($exceptionData);
            $exception->method('getResponse')->willReturn($mockResponse);
            $avangateProxy->method('request')->willThrowException($exception);
        } else {
            $mockData = $this->createMock(StreamInterface::class);
            $mockData->method('getContents')->willReturn(
                json_encode([
                    'RefNo'         => '206206829',
                    'Status'        => 'AUTHRECEIVED',
                    'ApproveStatus' => 'WAITING',
                ])
            );
            $mockResponse->method('getBody')->willReturn($mockData);
            $avangateProxy->method('request')->willReturn($mockResponse);
        }
        static::$kernel->getContainer()->set('movavi_shop.avangate.checkout.client', $avangateProxy);

        $data    = json_encode($getContent($this->priceOptionId));
        $content = $this->makeRequestToCheckout($data, 'us');
        $this->assertEquals($expectedResponse, $content);
    }

    private function makeRequestToCheckout(string $content, string $locale): mixed
    {
        $this->client->request(
            Request::METHOD_POST,
            'test-url/?test=' . $locale,
            [],
            [],
            [
                'HTTP_ACCEPT'       => 'application/json',
                'HTTP_CONTENT_TYPE' => 'application/json',
            ],
            $content
        );
        $response = $this->client->getResponse();

        return json_decode($response->getContent(), true);
    }

    private function requestTo2PayJsCheckoutData(): array
    {
        return [
            [
                static fn (int $priceOptionId) => [
                    'emailSubscription' => true,
                    'collectionMethod'  => 'send_invoice',
                    'jsToken'           => '8849864e-a0b0-460a-982d-ca01e6f2df17',
                    'billingDetails'    => [
                        'country'     => 'US',
                        'zip'         => '70403-900',
                        'firstName'   => 'testName',
                        'lastName'    => 'testLastName',
                        'email'       => 't.test@movavi.com',
                        'phone'       => '79999999999',
                    ],
                    'order'             => [
                        'items' => [
                            [
                                'priceOptionId' => $priceOptionId,
                                'count'         => 2,
                            ],
                        ],
                    ],
                ],
                [
                    'RefNo'  => '206206829',
                    'Status' => [
                        'Name'          => 'AUTHRECEIVED',
                        'ApproveStatus' => 'WAITING',
                    ],
                ],
                false,
            ],
            [
                static fn (int $priceOptionId) => [
                    'collection'     => 'send_invoice',
                    'billingDetails' => [
                        'phone' => '79999999999',
                    ],
                    'order'             => [
                        'items' => [
                            [
                                'priceOptionId' => $priceOptionId,
                                'count'         => 2,
                            ],
                        ],
                    ],
                ],
                [
                    'type'    => 'INVALID_EES_TOKEN',
                    'message' => 'The token is not valid. In order to proceed with the place order a valid token is required',
                ],
                true,
            ],
        ];
    }
}
