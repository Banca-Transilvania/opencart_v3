<?php
namespace BtIpay\Opencart\Cancel;

use BtIpay\Opencart\Order\Message;
use BtIpay\Opencart\Cancel\Result;
use BtIpay\Opencart\Order\StatusService;


class Handler
{
    /** @var \ModelExtensionPaymentBtIpay */
    protected $paymentModel;

    protected StatusService $statusService;

    protected int $orderId;

    protected string $ipayId;

    public function __construct(
        $paymentModel,
        StatusService $statusService,
        int $orderId,
        string $ipayId
    ) {
        $this->paymentModel = $paymentModel;
        $this->statusService = $statusService;
        $this->orderId = $orderId;
        $this->ipayId = $ipayId;
    }

    public function handle(Result $result): array
    {
        $this->updateStateAndAmount($result, StatusService::STATUS_REVERSED);

        if ($result->isPartial()) {
            $this->statusService->addMessage(
                new Message(
                    'partial_cancel',
                    [$result->getLoyId()]
                )
            );
        }

        if ($result->hasInternalError() || $result->hasErrorMessage()) {
            return $this->error(new Message($result->getErrorMessage()));
        }

        $message = new Message(
            'fully_cancel',
        );

        $this->updateOrderStatus($message, $result->hasPayment());
        return $this->notice($message);
    }

    private function updateOrderStatus(Message $message, bool $hasPayment)
    {
        if ($hasPayment) {
            $this->statusService->update(StatusService::STATUS_REVERSED, $message);
            return;
        }
        $this->statusService->addMessage($message);
    }

    private function updateStateAndAmount(Result $result, string $status)
    {
        if ($result->hasPayment()) {
            $this->paymentModel->updatePaymentStatus(
                $this->ipayId,
                $status
            );
        }

        if ($result->hasLoy()) {
            $this->paymentModel->updateLoyStatus(
                $this->ipayId,
                $status
            );
        }
    }
    private function error(Message $message)
    {
        return $this->notice($message, true);
    }

    private function notice(Message $message, $error = false): array
    {
        return [
            "error" => $error,
            "message" => $this->statusService->translateMessage($message)
        ];
    }
}