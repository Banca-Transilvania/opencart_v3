<?php
namespace BtIpay\Opencart\Capture;

use BtIpay\Opencart\Order\Message;
use BtIpay\Opencart\Capture\Result;
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
        $this->updateStateAndAmount($result, StatusService::STATUS_DEPOSITED);

        if ($result->isPartial()) {
            $this->statusService->addMessage(
                new Message(
                    'partial_capture_amount',
                    [$this->paymentModel->formatCurrency($result->getTotal())]
                )
            );
        }

        if ($result->hasInternalError() || $result->hasErrorMessage()) {
            return $this->error(new Message($result->getErrorMessage()));
        }

        $message = new Message(
            'fully_captured_amount',
            [$this->paymentModel->formatCurrency($result->getTotal())]
        );

        $this->updateOrderStatus($message);
        return $this->notice($message);
    }

    private function updateOrderStatus(Message $message)
    {
        $this->statusService->update(StatusService::STATUS_DEPOSITED, $message);
    }

    private function updateStateAndAmount(Result $result, string $status)
    {
        if ($result->isPaymentReversed()) {
            $this->paymentModel->updatePaymentStatus($this->ipayId, StatusService::STATUS_REVERSED);
        }
        
        if ($result->hasPayment()) {
            $this->paymentModel->updatePaymentStatusAndAmount(
                $this->ipayId,
                $status,
                $result->getPayAmount()
            );
        }

        if ($result->hasLoy()) {
            $this->paymentModel->updateLoyStatusAndAmount(
                $this->ipayId,
                $status,
                $result->getLoyAmount()
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