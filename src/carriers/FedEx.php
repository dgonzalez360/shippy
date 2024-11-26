<?php
namespace verbb\shippy\carriers;

use Illuminate\Support\Arr;
use verbb\shippy\exceptions\InvalidRequestException;
use verbb\shippy\helpers\Json;
use verbb\shippy\models\Address;
use verbb\shippy\models\HttpClient;
use verbb\shippy\models\Label;
use verbb\shippy\models\LabelResponse;
use verbb\shippy\models\Package;
use verbb\shippy\models\Rate;
use verbb\shippy\models\RateResponse;
use verbb\shippy\models\Request;
use verbb\shippy\models\Response;
use verbb\shippy\models\Shipment;
use verbb\shippy\models\Tracking;
use verbb\shippy\models\TrackingDetail;
use verbb\shippy\models\TrackingResponse;

class FedEx extends AbstractCarrier
{
    // Static Methods
    // =========================================================================

    public static function getName(): string
    {
        return 'FedEx';
    }

    public static function getWeightUnit(Shipment $shipment): string
    {
        return ($shipment->getFrom()->getCountryCode() === 'US') ? 'lb' : 'kg';
    }

    public static function getDimensionUnit(Shipment $shipment): string
    {
        return ($shipment->getFrom()->getCountryCode() === 'US') ? 'in' : 'cm';
    }

    public static function isDomestic(Shipment $shipment): bool
    {
        return $shipment->getTo()->getCountryCode() === $shipment->getFrom()->getCountryCode();
    }
    
    public static function getTrackingUrl(string $trackingNumber): ?string
    {
        return "https://www.fedex.com/Tracking?action=track&tracknumbers={$trackingNumber}";
    }

    public static function getServiceCodes(): array
    {
        return [
            'US' => [
                'FEDEX_1_DAY_FREIGHT' => 'FedEx 1 Day Freight',
                'FEDEX_2_DAY' => 'FedEx 2 Day',
                'FEDEX_2_DAY_AM' => 'FedEx 2 Day AM',
                'FEDEX_2_DAY_FREIGHT' => 'FedEx 2 DAY Freight',
                'FEDEX_3_DAY_FREIGHT' => 'FedEx 3 Day Freight',
                'FEDEX_EXPRESS_SAVER' => 'FedEx Express Saver',
                'FEDEX_FIRST_FREIGHT' => 'FedEx First Freight',
                'FEDEX_FREIGHT_ECONOMY' => 'FedEx Freight Economy',
                'FEDEX_FREIGHT_PRIORITY' => 'FedEx Freight Priority',
                'FEDEX_GROUND' => 'FedEx Ground',
                'FIRST_OVERNIGHT' => 'FedEx First Overnight',
                'PRIORITY_OVERNIGHT' => 'FedEx Priority Overnight',
                'STANDARD_OVERNIGHT' => 'FedEx Standard Overnight',
                'GROUND_HOME_DELIVERY' => 'FedEx Ground Home Delivery',
                'SAME_DAY' => 'FedEx Same Day',
                'SAME_DAY_CITY' => 'FedEx Same Day City',
                'SMART_POST' => 'FedEx Smart Post',
            ],
            'UK' => [
                'FEDEX_DISTANCE_DEFERRED' => 'FedEx Distance Deferred',
                'FEDEX_NEXT_DAY_EARLY_MORNING' => 'FedEx Next Day Early Morning',
                'FEDEX_NEXT_DAY_MID_MORNING' => 'FedEx Next Day Mid Morning',
                'FEDEX_NEXT_DAY_AFTERNOON' => 'FedEx Next Day Afternoon',
                'FEDEX_NEXT_DAY_END_OF_DAY' => 'FedEx Next Day End of Day',
                'FEDEX_NEXT_DAY_FREIGHT' => 'FedEx Next Day Freight',
            ],
            'international' => [ // International
                'INTERNATIONAL_ECONOMY' => 'FedEx International Economy',
                'INTERNATIONAL_ECONOMY_FREIGHT' => 'FedEx International Economy Freight',
                'INTERNATIONAL_ECONOMY_DISTRIBUTION' => 'FedEx International Economy Distribution',
                'INTERNATIONAL_FIRST' => 'FedEx International First',
                'INTERNATIONAL_PRIORITY' => 'FedEx International Priority',
                'INTERNATIONAL_PRIORITY_FREIGHT' => 'FedEx International Priority Freight',
                'INTERNATIONAL_PRIORITY_DISTRIBUTION' => 'FedEx International Priority Distribution',
                'INTERNATIONAL_PRIORITY_EXPRESS' => 'FedEx International Priority Express',
                'EUROPE_FIRST_INTERNATIONAL_PRIORITY' => 'FedEx Europe First International Priority',
                'INTERNATIONAL_DISTRIBUTION_FREIGHT' => 'FedEx International Distribution',
            ],
        ];
    }


    // Properties
    // =========================================================================

    protected ?string $clientId = null;
    protected ?string $clientSecret = null;
    protected ?string $accountNumber = null;
    protected ?string $pickupType = 'DROPOFF_AT_FEDEX_LOCATION';
    protected ?string $packagingType = 'YOUR_PACKAGING';
    protected ?int $insuranceAmount = null;


    // Public Methods
    // =========================================================================

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function setClientId(?string $clientId): FedEx
    {
        $this->clientId = $clientId;
        return $this;
    }

    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
    }

    public function setClientSecret(?string $clientSecret): FedEx
    {
        $this->clientSecret = $clientSecret;
        return $this;
    }

    public function getAccountNumber(): ?string
    {
        return $this->accountNumber;
    }

    public function setAccountNumber(?string $accountNumber): FedEx
    {
        $this->accountNumber = $accountNumber;
        return $this;
    }

    public function getPickupType(): ?string
    {
        return $this->pickupType;
    }

    public function setPickupType(?string $pickupType): FedEx
    {
        $this->pickupType = $pickupType;
        return $this;
    }

    public function getPackagingType(): ?string
    {
        return $this->packagingType;
    }

    public function setPackagingType(?string $packagingType): FedEx
    {
        $this->packagingType = $packagingType;
        return $this;
    }

    public function getInsuranceAmount(): ?int
    {
        return $this->insuranceAmount;
    }

    public function setInsuranceAmount(?int $insuranceAmount): FedEx
    {
        $this->insuranceAmount = $insuranceAmount;
        return $this;
    }

    /**
     * @throws InvalidRequestException
     */
    public function getRates(Shipment $shipment): ?RateResponse
    {
        $this->validate('clientId', 'clientSecret', 'accountNumber');

        $request = $this->getRequest($shipment);

        $data = $this->fetchRates($request, function(Response $response) {
            return $response->json();
        });

        $rates = [];

        foreach (Arr::get($data, 'output.rateReplyDetails', []) as $shippingRate) {
            $rate = Arr::get($shippingRate, 'ratedShipmentDetails.0.totalNetCharge');
            $currency = Arr::get($shippingRate, 'ratedShipmentDetails.0.shipmentRateDetail.currency');

            $serviceCode = Arr::get($shippingRate, 'serviceType');
            $serviceRegion = Arr::get(self::getServiceCodes(), $shipment->getFrom()->getCountryCode(), Arr::get(self::getServiceCodes(), 'international'));
            $serviceName = Arr::get($serviceRegion, $serviceCode, '');

            $rates[] = new Rate([
                'carrier' => $this,
                'response' => $shippingRate,
                'serviceName' => $serviceName,
                'serviceCode' => $serviceCode,
                'rate' => $rate,
                'currency' => $currency,
                'deliveryDate' => Arr::get($shippingRate, 'commit.dateDetail.dayFormat'),
            ]);
        }

        return new RateResponse([
            'response' => $data,
            'rates' => $rates,
        ]);
    }

    /**
     * @throws InvalidRequestException
     */
    public function getTrackingStatus(array $trackingNumbers, array $options = []): ?TrackingResponse
    {
        $this->validate('clientId', 'clientSecret');

        $tracking = [];

        $request = new Request([
            'endpoint' => 'track/v1/trackingnumbers',
            'payload' => [
                'json' => [
                    'includeDetailedScans' => true,
                    'trackingInfo' => array_map(function($trackingNumber) {
                        return [
                            'trackingNumberInfo' => [
                                'trackingNumber' => str_replace(' ', '', $trackingNumber),
                            ],
                        ];
                    }, $trackingNumbers),
                ],
            ],
        ]);

        $data = $this->fetchTracking($request, function(Response $response) {
            return $response->json();
        });

        foreach (Arr::get($data, 'output.completeTrackResults', []) as $result) {
            $statusCode = Arr::get($result, 'trackResults.0.latestStatusDetail.code', '');
            $status = $this->_mapTrackingStatus($statusCode);

            $tracking[] = new Tracking([
                'carrier' => $this,
                'response' => $result,
                'trackingNumber' => Arr::get($result, 'trackingNumber', ''),
                'status' => $status,
                'estimatedDelivery' => null,
                'details' => array_map(function($detail) {
                    $location = array_filter([
                        Arr::get($detail, 'scanLocation.city', ''),
                        Arr::get($detail, 'scanLocation.postalCode', ''),
                        Arr::get($detail, 'scanLocation.stateOrProvinceCode', ''),
                        Arr::get($detail, 'scanLocation.countryName', ''),
                    ]);

                    return new TrackingDetail([
                        'location' => implode(' ', $location),
                        'description' => Arr::get($detail, 'eventDescription', ''),
                        'date' => Arr::get($detail, 'date', ''),
                    ]);
                }, Arr::get($result, 'trackResults.0.scanEvents', [])),
            ]);
        }

        return new TrackingResponse([
            'response' => $data,
            'tracking' => $tracking,
        ]);
    }

    /**
     * @throws InvalidRequestException
     */
    public function getLabels(Shipment $shipment, Rate $rate, array $options = []): ?LabelResponse
    {
        $this->validate('clientId', 'clientSecret', 'accountNumber');

        $labelRequest = $this->getLabelRequest($shipment, $rate, $options);

        $data = $this->fetchLabels($labelRequest, function(Response $response) {
            return $response->json();
        });

        $labels = [];

        foreach (Arr::get($data, 'output.transactionShipments', []) as $shipmentObject) {
            $labels[] = new Label([
                'carrier' => $this,
                'response' => $shipmentObject,
                'rate' => $rate,
                'trackingNumber' => Arr::get($shipmentObject, 'masterTrackingNumber'),
                'labelData' => Arr::get($shipmentObject, 'pieceResponses.0.packageDocuments.0.encodedLabel', ''),
                'labelMime' => 'application/pdf',
            ]);
        }

        return new LabelResponse([
            'response' => $data,
            'labels' => $labels,
        ]);
    }

    public function getHttpClient(): HttpClient
    {
        if ($this->isProduction()) {
            $url = 'https://apis.fedex.com/';
        } else {
            $url = 'https://apis-sandbox.fedex.com/';
        }

        // Fetch an access token first
        $authResponse = Json::decode((string)(new HttpClient())
            ->request('POST', $url . 'oauth/token', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
            ])->getBody());

        return new HttpClient([
            'base_uri' => $url,
            'headers' => [
                'Authorization' => 'Bearer ' . $authResponse['access_token'] ?? '',
                'Content-Type' => 'application/json',
            ],
        ]);
    }


    // Protected Methods
    // =========================================================================

    protected function getRequest(Shipment $shipment): Request
    {
        $payload = [
            'accountNumber' => [
                'value' => $this->accountNumber,
            ],
            'rateRequestControlParameters' => [
                'returnTransitTimes' => true,
                'servicesNeededOnRateFailure' => true,
            ],
            'requestedShipment' => [
                'shipper' => [
                    'address' => $this->getAddress($shipment->getFrom()),
                ],
                'recipient' => [
                    'address' => $this->getAddress($shipment->getTo()),
                ],
                'preferredCurrency' => $shipment->getCurrency(),
                'rateRequestType' => [
                    'LIST',
                    'ACCOUNT'
                ],
                'pickupType' => $this->pickupType,
                'packagingType' => $this->packagingType,
                'shippingChargesPayment' => [
                    'paymentType' => 'SENDER',
                    'payor' => [
                        'responsibleParty' => [
                            'accountNumber' => [
                                'value' => $this->accountNumber,
                            ],
                        ],
                    ],
                ],
                'packageCount' => count($shipment->getPackages()),
                'requestedPackageLineItems' => $this->getPackages($shipment),
            ],
        ];

        if (!self::isDomestic($shipment)) {
            $payload['requestedShipment']['customsClearanceDetail'] = [
                'commodities' => array_map(function(Package $package) use ($shipment) {
                    return [
                        'description' => 'Products',
                        'quantity' => 1,
                        'quantityUnits' => 'PCS',
                        'weight' => [
                            'units' => strtoupper(self::getWeightUnit($shipment)),
                            'value' => $package->getWeight(),
                        ],
                        'unitPrice' => [
                            'amount' => $package->getPrice(),
                            'currency' => $shipment->getCurrency(),
                        ],
                        'customsValue' => [
                            'amount' => $package->getPrice(),
                            'currency' => $shipment->getCurrency(),
                        ],
                    ];
                }, $shipment->getPackages()),
            ];
        }

        if ($this->insuranceAmount > 0) {
            $payload['requestedShipment']['totalInsuredValue'] = [
                'amount' => $this->insuranceAmount,
                'currency' => $shipment->getCurrency(),
            ];
        }

        return new Request([
            'endpoint' => 'rate/v1/rates/quotes',
            'payload' => [
                'json' => $payload,
            ],
        ]);
    }

    protected function getLabelRequest(Shipment $shipment, Rate $rate, array $options = []): Request
    {
        $payload = [
            'requestedShipment' => [
                'shipper' => [
                    'address' => $this->getAddress($shipment->getFrom()),
                    'contact' => $this->getContact($shipment->getFrom()),
                ],
                'recipients' => [
                    [
                        'address' => $this->getAddress($shipment->getTo()),
                        'contact' => $this->getContact($shipment->getTo()),
                    ],
                ],
                'pickupType' => $this->pickupType,
                'serviceType' => $rate->getServiceCode(),
                'packagingType' => $this->packagingType,
                'shippingChargesPayment' => [
                    'paymentType' => 'SENDER',
                    'payor' => [
                        'responsibleParty' => [
                            'accountNumber' => [
                                'value' => $this->accountNumber,
                            ],
                        ],
                    ],
                ],
                'labelSpecification' => array_filter([
                    'labelFormatType' => Arr::get($options, 'labelFormatType', 'COMMON2D'),
                    'labelOrder' => Arr::get($options, 'labelOrder', 'SHIPPING_LABEL_FIRST'),
                    'customerSpecifiedDetail' => Arr::get($options, 'customerSpecifiedDetail'),
                    'labelStockType' => Arr::get($options, 'labelStockType', 'PAPER_85X11_TOP_HALF_LABEL'),
                    'labelRotation' => Arr::get($options, 'labelRotation', 'UPSIDE_DOWN'),
                    'imageType' => Arr::get($options, 'imageType', 'PDF'),
                    'labelPrintingOrientation' => Arr::get($options, 'labelPrintingOrientation', 'TOP_EDGE_OF_TEXT_FIRST'),
                    'returnedDispositionDetail' => Arr::get($options, 'returnedDispositionDetail', true),
                ]),
                'rateRequestType' => [
                    'LIST',
                    'ACCOUNT'
                ],
                'preferredCurrency' => $shipment->getCurrency(),
                'requestedPackageLineItems' => $this->getPackages($shipment),
            ],
            'labelResponseOptions' => Arr::get($options, 'labelResponseOptions', 'LABEL'),
            'accountNumber' => [
                'value' => $this->accountNumber,
            ],
            'shipAction' => Arr::get($options, 'shipAction', 'CONFIRM'),
            'processingOptionType' => Arr::get($options, 'processingOptionType', 'ALLOW_ASYNCHRONOUS'),
            'oneLabelAtATime' => Arr::get($options, 'oneLabelAtATime', true),
        ];

        if (!self::isDomestic($shipment)) {
            $payload['requestedShipment']['customsClearanceDetail'] = [
                'dutiesPayment' => [
                    'paymentType' => 'SENDER',
                ],
                'commodities' => array_map(function($package) use ($shipment) {
                    return [
                        'description' => 'Products',
                        'countryOfManufacture' => 'US',
                        'quantity' => 1,
                        'quantityUnits' => 'PCS',
                        'weight' => [
                            'units' => strtoupper(self::getWeightUnit($shipment)),
                            'value' => $package->getWeight(),
                        ],
                        'unitPrice' => [
                            'amount' => $package->getPrice(),
                            'currency' => $shipment->getCurrency(),
                        ],
                        'customsValue' => [
                            'amount' => $package->getPrice(),
                            'currency' => $shipment->getCurrency(),
                        ],
                    ];
                }, $shipment->getPackages()),
            ];
        }

        return new Request([
            'method' => 'POST',
            'endpoint' => 'ship/v1/shipments',
            'payload' => [
                'json' => $payload,
            ],
        ]);
    }

    protected function getAddress(Address $address): array
    {
        return [
            'streetLines' => [
                $address->getStreet1(),
                $address->getStreet2(),
            ],
            'city' => $address->getCity(),
            'stateOrProvinceCode' => substr($address->getStateProvince(), 0, 2),
            'postalCode' => $address->getPostalCode(),
            'countryCode' => $address->getCountryCode(),
            'residential' => $address->isResidential(),
        ];
    }

    protected function getContact(Address $address): array
    {
        return [
            'personName' => $address->getFullName(),
            'emailAddress' => $address->getEmail(),
            'phoneNumber' => $address->getPhone(),
        ];
    }

    protected function getPackages(Shipment $shipment): array
    {
        return array_map(function($package) use ($shipment) {
            return [
                'groupPackageCount' => 1,
                'weight' => [
                    'units' => strtoupper(self::getWeightUnit($shipment)),
                    'value' => $package->getWeight(),
                ],
                'dimensions' => [
                    'length' => $package->getLength(0),
                    'width' => $package->getWidth(0),
                    'height' => $package->getHeight(0),
                    'units' => strtoupper(self::getDimensionUnit($shipment)),
                ],
            ];
        }, $shipment->getPackages());
    }


    // Private Methods
    // =========================================================================

    private function _mapTrackingStatus(string $status): string
    {
        return match ($status) {
            'AP' => Tracking::STATUS_AVAILABLE_FOR_PICKUP,
            'IT', 'IX' => Tracking::STATUS_IN_TRANSIT,
            'OD' => Tracking::STATUS_OUT_FOR_DELIVERY,
            'DL' => Tracking::STATUS_DELIVERED,
            'RS' => Tracking::STATUS_RETURN_TO_SENDER,
            'CA' => Tracking::STATUS_CANCELLED,
            'CD', 'DY', 'DE', 'HL', 'CH', 'SE' => Tracking::STATUS_ERROR,
            default => Tracking::STATUS_UNKNOWN,
        };
    }

}
