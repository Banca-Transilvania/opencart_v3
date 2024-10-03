<?php
namespace BtIpay\Opencart\Payment;

use ModelExtensionPaymentBtIpay;
use BtIpay\Opencart\Order\Message;
use BtIpay\Opencart\Payment\Response;
use BtIpay\Opencart\Order\StatusService;

class Handler
{
    /** @var \ModelExtensionPaymentBtIpay */
    protected $paymentModel;

    protected StatusService $statusService;

    protected int $orderId;

    public function __construct(
        $paymentModel,
        StatusService $statusService,
        int $orderId
    )
    {
        $this->paymentModel = $paymentModel;
        $this->statusService = $statusService;
        $this->orderId = $orderId;
    }


    public function handle(Response $response)
    {
        if ($response->getIpayId() !== null) {
            $this->updateOrderStatus($response->getIpayId());
            if ($response->isSuccess()) {
                $this->savePayment($response->getIpayId());
                return ["redirect" => $response->getRedirectUrl()];
            }
        }
        return ["error" => true, "message" => $response->getErrorMessage()];
    }

    private function updateOrderStatus(string $ipayId)
    {
        $this->statusService->update(
            StatusService::STATUS_CREATED,
            new Message("started_payment_with_id", [$ipayId])
        );
    }

    private function savePayment(string $ipayId)
    {
        $this->paymentModel->createPayment(
            $ipayId,
            $this->orderId
        );
    }

}