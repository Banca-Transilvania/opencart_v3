<?php
namespace BtIpay\Opencart\Order;

class Message
{
    protected $message;

    protected array $params;
    public function __construct(string $message, array $params = [])
    {
        $this->message = $message;
        $this->params = $params;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
    public function getParams(): array
    {
        return $this->params;
    }

    public function hasParams(): bool
    {
        return count($this->params) > 0;
    }
}