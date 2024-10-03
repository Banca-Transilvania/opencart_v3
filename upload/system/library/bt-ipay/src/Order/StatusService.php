<?php
namespace BtIpay\Opencart\Order;

use BtIpay\Opencart\Language;
use BtIpay\Opencart\Sdk\Config;
use BtIpay\Opencart\Order\Message;

class StatusService
{
    public const STATUS_DEPOSITED = 'DEPOSITED';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_DECLINED = 'DECLINED';
    public const STATUS_REVERSED = 'REVERSED';
    public const STATUS_PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';
    public const STATUS_REFUNDED = 'REFUNDED';
    public const STATUS_CREATED = 'CREATED';
    public const STATUS_VALIDATION_FINISHED = 'VALIDATION_FINISHED';

    protected int $orderId;

    /** @var \ModelExtensionPaymentBtIpay */
    protected $paymentModel;

    protected Config $config;

    protected Language $languageService;

    public function __construct(
        $paymentModel,
        Config $config,
        Language $languageService,
        int $orderId
    ) {
        $this->config = $config;
        $this->paymentModel = $paymentModel;
        $this->languageService = $languageService;
        $this->orderId = $orderId;
    }

    /**
     * Update order status based on payment status
     *
     * @param string $paymentStatus
     * @param Message $message
     *
     * @return void
     */
    public function update(string $paymentStatus, Message $message): void
    {
        $orderStatus = $this->getOrderStatus($paymentStatus);
        if (is_scalar($orderStatus)) {
            $this->paymentModel->addOrderHistory(
                $this->orderId,
                (int) $orderStatus,
                $this->translateMessage($message)
            );
        } else {
            $this->addMessage($message);
        }
    }

    public function addMessage(Message $message): void
    {
        $order = $this->paymentModel->getOrder($this->orderId);
        if (isset($order['order_status_id'])) {
            $this->paymentModel->addOrderHistory(
                $this->orderId,
                (int) $order['order_status_id'],
                $this->translateMessage($message)
            );
        }
    }

    public function getOrderStatus(string $paymentStatus): ?string
    {
        $mapping = array(
            self::STATUS_DEPOSITED => $this->config->getValue('statusDeposited'),
            self::STATUS_APPROVED => $this->config->getValue('statusApproved'),
            self::STATUS_REVERSED => $this->config->getValue('statusReversed'),
            self::STATUS_DECLINED => $this->config->getValue('statusDeclined'),
            self::STATUS_REFUNDED => $this->config->getValue('statusRefunded'),
            self::STATUS_CREATED => $this->config->getValue('statusCreated'),
            self::STATUS_PARTIALLY_REFUNDED => $this->config->getValue('statusPartiallyRefunded')
        );

        return $mapping[$paymentStatus] ?? null;
    }
    public function translateMessage(Message $message): string
    {
        return $this->languageService->get($message->getMessage(), $message->getParams());
    }

}