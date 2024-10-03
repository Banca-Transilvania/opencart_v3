<?php
namespace BtIpay\Opencart\Refund;

use BtIpay\Opencart\Order\Message;
use BtIpay\Opencart\Refund\Result;
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
        $this->updateStatus($result);
        $this->updateRefunds($result);
        if ($result->isPartial()) {
            $this->statusService->addMessage(
                new Message(
                    'partial_refunded_amount',
                    [$this->paymentModel->formatCurrency($result->getTotal())]
                )
            );
        }

        if ($result->hasInternalError() || $result->hasErrorMessage()) {
            return $this->error(new Message($result->getErrorMessage()));
        }

        $message = new Message(
            'fully_refunded_amount',
            [$this->paymentModel->formatCurrency($result->getTotal())]
        );

        $this->updateOrderStatus($message, $result);
        return $this->notice($message);
    }

    private function updateOrderStatus(Message $message, Result $result): void
    {
        if ($result->isPaymentFullyRefunded() && $result->isLoyFullyRefunded()) {
            $this->statusService->update(StatusService::STATUS_REFUNDED, $message);
            return;
        }
        $this->statusService->update(StatusService::STATUS_PARTIALLY_REFUNDED, $message);
    }

    private function updateRefunds(Result $result): void
    {
        $this->paymentModel->addRefunds($this->orderId, $result->getRefunds());
    }

    private function updateStatus(Result $result)
    {
        if ($result->hasPayment()) {
            $this->paymentModel->updatePaymentStatus(
                $this->ipayId,
                $result->isPaymentFullyRefunded() ? StatusService::STATUS_REFUNDED : StatusService::STATUS_PARTIALLY_REFUNDED
            );
        }

        if ($result->hasLoy()) {
            $this->paymentModel->updateLoyStatus(
                $this->ipayId,
                $result->isLoyFullyRefunded() ? StatusService::STATUS_REFUNDED : StatusService::STATUS_PARTIALLY_REFUNDED
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