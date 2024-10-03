<?php
namespace BtIpay\Opencart\Sdk;

use BtIpay\Opencart\Order\StatusService;
use BtIpay\Opencart\Sdk\Response;

class DetailResponse extends Response
{
    /**
     * Get loy id for combined payment(loy+currency)
     *
     * @return string|null
     */
    public function getLoyId(): ?string
    {
        if (is_array($this->response->attributes)) {
            foreach ($this->response->attributes as $attribute) {
                if ($attribute instanceof \stdClass) {
                    if ($attribute->name === 'loyalties' && is_string($attribute->value)) {
                        $loy = explode(',', $attribute->value);
                        $loy = explode(':', $loy[0]);

                        if (isset($loy[1]) && is_string($loy[1])) {
                            return $loy[1];
                        }
                    }
                }
            }
            return null;
        }
        return null;
    }

    public function getStatus(): string
    {
        $info = $this->response->paymentAmountInfo;
        if ($info !== null && property_exists($info, 'paymentState')) {
            return $info->paymentState;
        }
        return 'UNKNOWN';
    }

    public function getRefunds(string $ipayId): array
    {
        $refunds = [];
        if (is_array($this->response->refunds)) {
            foreach ($this->response->refunds as $refund) {
                if (
                    $refund->actionCode === "0" &&
                    is_int($refund->amount)
                ) {
                    $refunds[] = [
                        'ipay_id' => $ipayId,
                        'amount' => $refund->amount / 100
                    ];
                }
            }
        }
        return $refunds;
    }

    public function getAmount(): float
    {
        if (is_int($this->response->amount)) {
            return $this->response->amount / 100;
        }
        return 0.0;
    }

    /**
     * Get loy amount for combined payment(loy+currency)
     *
     * @return float
     */
    public function getLoyAmount(): float
    {
        if (is_array($this->response->merchantOrderParams)) {
            foreach ($this->response->merchantOrderParams as $param) {
                if ($param instanceof \stdClass) {
                    if ($param->name === 'loyaltyAmount' && isset($param->value) && is_scalar($param->value)) {
                        return floatval($param->value) / 100;
                    }
                }
            }
            return 0.0;
        }
        return 0.0;
    }

    public function canSaveCard(): bool
    {
        return $this->getStatus() === StatusService::STATUS_VALIDATION_FINISHED;
    }

    public function isAuthorized(): bool
    {
        return $this->getStatus() === StatusService::STATUS_APPROVED;
    }

    /**
     * Can refund order
     *
     * @return boolean
     */
    public function canRefund(): bool
    {
        return in_array(
            $this->getStatus(),
            array(
                StatusService::STATUS_DEPOSITED,
                StatusService::STATUS_PARTIALLY_REFUNDED,
            )
        ) && $this->getTotalAvailable() > 0;
    }


    public function getCardInfo(): ?array
    {
        $cardInfo = $this->response->cardAuthInfo;

        $cardIds = $this->getCardIds();
        if (
            !$cardInfo instanceof \stdClass ||
            !is_array($cardIds)
        ) {
            return null;
        }
        $card = (array) $cardInfo;
        return array_merge($card, $cardIds);
    }

    /**
     * Get total refunded for refund
     *
     * @return float
     */
    public function getTotalRefunded(): float
    {
        $info = $this->response->paymentAmountInfo;
        if (
            $info !== null &&
            property_exists($info, 'refundedAmount') &&
            is_scalar($info->refundedAmount)
        ) {
            return ((int) $info->refundedAmount) / 100;
        }
        return 0.0;
    }

    public function getCardIds(): ?array
    {
        $binding = $this->response->bindingInfo;
        if (!$binding instanceof \stdClass) {
            return null;
        }

        if (
            is_string($binding->bindingId) && is_string($binding->clientId)
        ) {
            return array(
                'ipay_id' => $binding->bindingId,
                'customer_id' => $binding->clientId,
            );
        }
        return null;
    }

    /**
     * Get total available for refund
     *
     * @return float
     */
    public function getTotalAvailable(): float
    {
        $info = $this->response->paymentAmountInfo;
        if (
            $info !== null &&
            property_exists($info, 'depositedAmount') &&
            is_scalar($info->depositedAmount)
        ) {
            return ((int) $info->depositedAmount) / 100;
        }
        return 0.0;
    }

    public function getCustomerError(): string
    {
        return $this->response->getCustomerError() ?? 'Payment has failed for unknown reasons';
    }
}