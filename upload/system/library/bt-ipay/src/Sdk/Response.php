<?php
namespace BtIpay\Opencart\Sdk;

use BTransilvania\Api\Model\Response\ResponseModel;

class Response
{
    protected ResponseModel $response;
    
    public function __construct(ResponseModel $response) {
        $this->response = $response;
    }

    public function getErrorMessage(): string
    {
        return $this->response->getErrorMessage() ?? 'Unknown error, error code: '.$this->response->getErrorCode();
    }

    public function isSuccess(): bool
    {
        return $this->response->isSuccess();
    }
}