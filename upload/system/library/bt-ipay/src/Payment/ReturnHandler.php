<?php
namespace BtIpay\Opencart\Payment;

use ModelExtensionPaymentBtIpay;
use BtIpay\Opencart\Order\Message;
use BtIpay\Opencart\Sdk\DetailResponse;
use BtIpay\Opencart\Order\StatusService;
use BtIpay\Opencart\Payment\PaymentErrorException;
use BtIpay\Opencart\Card\Encrypt;

class ReturnHandler
{
    /** @var \ModelExtensionPaymentBtIpay */
    protected $paymentModel;

    protected string $ipayId;

    protected StatusService $statusService;

    public function __construct(
        $paymentModel,
        StatusService $statusService,
        string $ipayId
    ) {
        $this->paymentModel = $paymentModel;
        $this->statusService = $statusService;
        $this->ipayId = $ipayId;
    }


    public function handle(DetailResponse $response)
    {
        $this->updatePayment($response);
        $message = new Message("received_payment_with_error", [$response->getCustomerError()]);
        if ($this->paymentAccepted($response)) {
            $this->updateCardData($response);
            $message = new Message("successful_created_payment", [$this->ipayId]);
        }
        $this->statusService->update($response->getStatus(), $message);

        if (!$this->paymentAccepted($response)) {
            throw new PaymentErrorException($this->getConsumerMessage($response));
        }
    }


    private function getConsumerMessage(DetailResponse $response) {
		//if insufficient loy get correct message
		if (
			$response->getStatus() === StatusService::STATUS_REVERSED &&
			$response->getCustomerError() !== null
		) {
			return $this->statusService->translateMessage(new Message('insufficient_funds'));
		}

		return $response->getCustomerError();
	}

    private function paymentAccepted(DetailResponse $response)
    {
        return in_array(
            $response->getStatus(),
            array(
                StatusService::STATUS_DEPOSITED,
                StatusService::STATUS_APPROVED,
            )
        );
    }

    private function updateCardData(DetailResponse $response)
    {
        $cardInfo = $response->getCardInfo();
        if (is_array($cardInfo)) {
            $this->paymentModel->createCard(Encrypt::encryptCard($cardInfo));
        }
    }

    private function updatePayment(DetailResponse $response)
    {
        $status = $response->getStatus();

        $this->paymentModel->updatePayment(
            $this->ipayId,
            [
                'status' => $status,
                'amount' => $response->getAmount(),
                'loy_id' => $response->getLoyId(),
                'loy_amount' => $response->getLoyAmount(),
                'loy_status' => $status === StatusService::STATUS_REVERSED ? StatusService::STATUS_DECLINED : $status,
            ]
        );
    }

}