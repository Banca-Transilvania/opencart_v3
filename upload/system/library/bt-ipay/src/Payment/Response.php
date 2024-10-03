<?php
namespace BtIpay\Opencart\Payment;
use BtIpay\Opencart\Sdk\Response as SdkResponse;

class Response extends SdkResponse
{
    public function getRedirectUrl(): string
    {
        $url = $this->response->getRedirectUrl();
        if ($url === null) {
            throw new \Exception("Cannot redirect to payment");
        }
        return $url;
    }


    public function getIpayId(): ?string
    {
        if (is_string($this->response->orderId))
        {
            return $this->response->orderId;
        }
        return null;
    }
}