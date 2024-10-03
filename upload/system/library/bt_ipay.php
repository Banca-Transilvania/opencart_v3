<?php

use BtIpay\Opencart\Language;
use BtIpay\Opencart\Sdk\Client;
use BtIpay\Opencart\Sdk\Config;
use BtIpay\Opencart\Webhook\JWT;
use BtIpay\Opencart\Payment\Handler;
use BtIpay\Opencart\Payment\Payload;
use BtIpay\Opencart\Payment\CofPayload;
use BtIpay\Opencart\Order\StatusService;
use BtIpay\Opencart\Payment\ReturnHandler;
use BtIpay\Opencart\Cancel\Result as CancelResult;
use BtIpay\Opencart\Refund\Result as RefundResult;
use BtIpay\Opencart\Cancel\Handler as CancelHandler;

use BtIpay\Opencart\Cancel\Service as CancelService;
use BtIpay\Opencart\Capture\Result as CaptureResult;
use BtIpay\Opencart\Refund\Handler as RefundHandler;

use BtIpay\Opencart\Refund\Service as RefundService;
use BtIpay\Opencart\Capture\Handler as CaptureHandler;
use BtIpay\Opencart\Capture\Service as CaptureService;

use BtIpay\Opencart\Webhook\Handler as WebhookHandler;
use BtIpay\Opencart\Card\ReturnHandler as CardReturnHandler;
use BtIpay\Opencart\Card\Encrypt;

require_once DIR_SYSTEM . 'library/bt-ipay/vendor/autoload.php';
class Bt_Ipay
{
    protected Log $logger;
    public function __construct()
    {
        $this->logger = new Log('bt-ipay.log');
    }
    /**
     * Start payment
     *
     * @param array $post
     * @param ModelCheckoutOrder $orderModel
     * @param Cart\Customer $customer
     * @param ModelExtensionPaymentBtIpay $model
     * @param integer $orderId
     *
     * @return array
     */
    public function startPayment(
        array $post,
        Cart\Customer $customer,
        $paymentModel,
        int $orderId,
        string $returnUrl,
        string $language
    ): array {
        try {
            $order = $paymentModel->getOrder($orderId);

            $config = new Config($paymentModel, $order['accept_language'] ?? 'en');
            $languageService = new Language($language);


            $statusService = new StatusService(
                $paymentModel,
                $config,
                $languageService,
                $orderId
            );

            $client = new Client($config);
            return (new Handler($paymentModel, $statusService, $orderId))
                ->handle(
                    $client->startPayment(
                        new Payload(
                            $order, 
                            $returnUrl, 
                            $this->getCof($post, $customer, $paymentModel),
                            $config->getValue('description')
                        ),
                        $paymentModel->isAuthorize()
                    )
                );
        } catch (\Throwable $th) {
            $this->logger->write((string) $th);
            return ["error" => true, "message" => "Could not process payment request"];
        }
    }


    public function finishPayment(
        $paymentModel,
        string $ipayId,
        string $language
    ) {
        $orderId = $paymentModel->getOrderId($ipayId);

        if ($orderId === null) {
            throw new \Exception("Could not find payment");
        }
        try {
            $languageService = new Language($language);
            $config = new Config($paymentModel, $language);

            $statusService = new StatusService($paymentModel, $config, $languageService, $orderId);
            $client = new Client($config);
            return (new ReturnHandler($paymentModel, $statusService, $ipayId))
                ->handle(
                    $client->getPayment($ipayId)
                );

        } catch (\Throwable $th) {
            $this->logger->write((string) $th);
            throw $th;
        }
    }

    public function capture(
        $paymentModel,
        string $language,
        int $orderId,
        float $amount
    ) {

        try {
            $languageService = new Language($language);
            $config = new Config($paymentModel, $language);

            $statusService = new StatusService($paymentModel, $config, $languageService, $orderId);
            $client = new Client($config);
            $captureResult = new CaptureResult();

            $paymentData = $paymentModel->getPaymentByOrderId($orderId);

            if ($paymentData === null) {
                return ["error" => true, "message" => $languageService->get('missing_payment_data')];
            }

            (new CaptureService($captureResult, $this->logger, $client))->capture((string)$paymentData['ipay_id'], $amount);
            return (new CaptureHandler($paymentModel, $statusService, $orderId, (string)$paymentData['ipay_id']))->handle($captureResult);

        } catch (\Throwable $th) {
            $this->logger->write((string) $th);
            return ["error" => true, "message" => $th->getMessage()];
        }
    }


    public function cancel(
        $paymentModel,
        string $language,
        int $orderId
    ) {

        try {
            $languageService = new Language($language);
            $config = new Config($paymentModel, $language);

            $statusService = new StatusService($paymentModel, $config, $languageService, $orderId);
            $client = new Client($config);
            $cancelResult = new CancelResult();

            $paymentData = $paymentModel->getPaymentByOrderId($orderId);

            if ($paymentData === null) {
                return ["error" => true, "message" => $languageService->get('missing_payment_data')];
            }

            (new CancelService($cancelResult, $this->logger, $client))->cancel((string)$paymentData['ipay_id']);
            return (new CancelHandler($paymentModel, $statusService, $orderId, (string)$paymentData['ipay_id']))->handle($cancelResult);

        } catch (\Throwable $th) {
            $this->logger->write((string) $th);
            return ["error" => true, "message" => $th->getMessage()];
        }
    }


    public function refund(
        $paymentModel,
        string $language,
        int $orderId,
        float $amount
    ) {

        try {
            $languageService = new Language($language);
            $config = new Config($paymentModel, $language);

            $statusService = new StatusService($paymentModel, $config, $languageService, $orderId);
            $client = new Client($config);
            $refundResult = new RefundResult();

            $paymentData = $paymentModel->getPaymentByOrderId($orderId);

            if ($paymentData === null) {
                return ["error" => true, "message" => $languageService->get('missing_payment_data')];
            }

            $ipayId = (string)$paymentData['ipay_id'];

            $refundService = new RefundService($refundResult, $this->logger, $client);

            $refundService->refund($ipayId, $amount);
            $refundService->getRefundState($ipayId, $paymentData['loy_id']);

            return (new RefundHandler($paymentModel, $statusService, $orderId, $ipayId))->handle($refundResult);

        } catch (\Throwable $th) {
            $this->logger->write((string) $th);
            return ["error" => true, "message" => $th->getMessage()];
        }
    }

    public function createCard(
        $paymentModel,
        string $language,
        string $returnUrl,
        int $customerId
    ): ?string
    {
        try {
            $config = new Config($paymentModel, $language);
            $client = new Client($config);

            $response = $client->verifyCard(
                array(
                    'orderNumber' => preg_replace( '/\s+/', '_','CARD' . microtime( false )),
                    'amount'      => 0,
                    'currency'    => 'RON',
                    'returnUrl'   => $returnUrl,
                    'clientId'    => $customerId,
                    'description' => 'Save card for later use',
                )
            );

            if ($response->isSuccess()) {
                return $response->getRedirectUrl();
            }
        } catch (\Throwable $th) {
            $this->logger->write((string) $th);
        }
        
        return null;
    }

    public function finishCardCreate(
        $paymentModel,
        string $language,
        string $ipayId
    )
    {
        try {
            $config = new Config($paymentModel, $language);
            $client = new Client($config);

            $response = $client->getPayment($ipayId);
            $languageService = new Language($language);

            return (new CardReturnHandler($paymentModel, $languageService))->handle($response);
        } catch (\Throwable $th) {
            $this->logger->write((string) $th);
            return ["error"=> true,"message"=> "could_not_save_card"];
        }
    }

    /**
     * Enable or disable saved card
     *
     * @param ModelExtensionPaymentBtIpay $paymentModel
     * @param string $language
     * @param string $ipayCardId
     * @param boolean $enable
     *
     * @return boolean
     */
    public function toggleCard(
        $paymentModel,
        string $language,
        string $ipayCardId,
        bool $enable
    ): bool
    {
        try {
            $config = new Config($paymentModel, $language);
            $client = new Client($config);
            return ($client->toggleCardStatus($ipayCardId, $enable))->isSuccess();
        } catch (\Throwable $th) {
            $this->logger->write((string) $th);
            return false;
        }
    }

    /**
     * Process callback call
     *
     * @param ModelExtensionPaymentBtIpay $paymentModel
     * @param string $language
     *
     * @return bool
     */
    public function callback(
        $paymentModel,
        string $language
    )
    {
        try {
            $jwt = $this->getJWT($paymentModel);
            (new WebhookHandler($jwt, $paymentModel, $language))->handle();
            return true;
        } catch (\Throwable $th) {
            $this->logger->write((string) $th);
            return false;
        }
    }


    private function getJWT($paymentModel): \stdClass {
		return JWT::decode(
			file_get_contents( 'php://input' ),
			JWT::urlsafeB64Decode(
				$paymentModel->getConfig('callbackKey')
			)
		);
	}

    private function getCof(
        array $post,
        Cart\Customer $customer,
        $paymentModel
    ) {

        if (!$customer->isLogged()) {
            return null;
        }


        $customerId = intval($customer->getId());

        if (isset($post['saveCard']) && $post['saveCard'] === "true") {
            return new CofPayload($customerId);
        }

        if (!isset($post['selectedCard']) || !is_scalar($post['selectedCard'])) {
            return null;
        }

        $ipayId = $paymentModel->getCardIpayId((int) $post['selectedCard'], $customerId);
        return new CofPayload($customerId, $ipayId);
    }

    public function decryptCard(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, Encrypt::TO_ENCRYPT)) {
                $data[$key] = Encrypt::decrypt($value);
            }
        }
        return $data;
    }

    public function decryptCardList($list): array
    {
        return array_map(function ($row) {
            return self::decryptCard($row);
        }, $list);
    }
}