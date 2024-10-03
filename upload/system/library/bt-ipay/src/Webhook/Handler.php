<?php
namespace BtIpay\Opencart\Webhook;

use BtIpay\Opencart\Language;
use BtIpay\Opencart\Sdk\Client;
use BtIpay\Opencart\Sdk\Config;
use BtIpay\Opencart\Order\Message;
use BtIpay\Opencart\Order\StatusService;

class Handler
{

	private \stdClass $payload;

	/** @var \ModelExtensionPaymentBtIpay */
	protected $paymentModel;

	protected Client $client;

	protected StatusService $statusService;

	protected Config $config;

	protected string $lang;

	public function __construct(\stdClass $jwt, $paymentModel, string $lang)
	{
		$this->config = new Config($paymentModel, $lang);
		$this->payload = $this->getPayload($jwt);
		$this->paymentModel = $paymentModel;
		$this->client = new Client($this->config);
		$this->lang = $lang;
	}


	public function handle()
	{
		$ipayId = $this->getIpayId();
		if ($ipayId === null) {
			throw new \Exception('Cannot find payment id');
		}

		$paymentStatus = $this->getPaymentStatus();
		if ($paymentStatus === null) {
			throw new \Exception('Cannot find payment status');
		}

		$payment = $this->getPaymentByiPayId();

		if ($payment === null) {
			throw new \Exception('Cannot not find payment data in the database');
		}

		$orderId = isset($payment['order_id']) ? (int) $payment['order_id'] : null;
		$isLoy = isset($payment['loy_id']) && $payment['loy_id'] === $this->getIpayId();
		

		if ($orderId === null) {
			throw new \Exception('Cannot not determine order id');
		}

		if ($paymentStatus === StatusService::STATUS_REFUNDED) {
			$isFullRefunded = $this->addRefund($payment);
			if (!$isFullRefunded) {
				$paymentStatus = StatusService::STATUS_PARTIALLY_REFUNDED;
			}
		}

		if ($paymentStatus === StatusService::STATUS_DEPOSITED && !$this->hasFailed()) {
			$this->capture($ipayId, $isLoy);
		}

		$this->updatePaymentStatus($ipayId, $paymentStatus, $isLoy);

		$statusService = $this->getStatusService($orderId);
		if ($isLoy) {
			$this->addLoyStatus($paymentStatus, $statusService);
			return;
		}
		$this->updateOrderStatus($paymentStatus, $statusService);
	}

	private function getPayload(\stdClass $jwt)
	{
		if (
			property_exists($jwt, 'payload') &&
			$jwt->payload instanceof \stdClass
		) {
			return $jwt->payload;
		}
		throw new \Exception('Cannot find jwt payload');
	}

	private function getStatusService(int $orderId): StatusService
	{
		return new StatusService(
			$this->paymentModel,
			$this->config,
			new Language($this->lang),
			$orderId
		);
	}

	private function addLoyStatus(string $paymentStatus, StatusService $statusService)
	{
		$statusService->addMessage(
			new Message('updated_loy_status_via_callback', [$paymentStatus])
		);
	}

	private function updateOrderStatus(string $paymentStatus, StatusService $statusService)
	{
		$statusService->update(
			$paymentStatus,
			new Message('updated_status_via_callback', [$paymentStatus])
		);
	}


	private function capture(string $ipayId, bool $isLoy)
	{
		$paymentDetails = $this->client->getPayment($ipayId);
		$totalCaptured = $paymentDetails->getTotalAvailable();
		if ($totalCaptured > 0) {
			if ($isLoy) {
				$this->paymentModel->updateLoyStatusAndAmount(
					$ipayId,
					StatusService::STATUS_APPROVED,
					$totalCaptured
				);
			} else {
				$this->paymentModel->updatePaymentStatusAndAmount(
					$ipayId,
					StatusService::STATUS_APPROVED,
					$totalCaptured
				);
			}
		}
	}

	/**
	 * Refund any missing amount, returns true if full refund
	 *
	 * @param array $payment
	 *
	 * @return boolean
	 */
	private function addRefund(array $payment): bool
	{
		$ipayId = $payment['ipay_id'];
		$paymentDetails = $this->client->getPayment($ipayId);
		$refunds = $paymentDetails->getRefunds($ipayId);

		$available = 0;

		if (count($refunds))
		{
			$this->paymentModel->addRefunds($payment['order_id'], $refunds, $ipayId);
			$available = $paymentDetails->getTotalAvailable();
		}

		if (strlen($payment['loy_id'])) {
			$paymentDetails = $this->client->getPayment($payment['loy_id']);
			$refunds = $paymentDetails->getRefunds($ipayId);
			if (count($refunds))
			{
				$this->paymentModel->addRefunds($payment['order_id'], $refunds, $ipayId);
				$available += $paymentDetails->getTotalAvailable();
			}
		}

		return abs($available) < 0.001;
	}

	/**
	 * Update payment status
	 *
	 * @return void
	 */
	private function updatePaymentStatus(string $ipayId, string $paymentStatus, bool $isLoy)
	{
		if (
			in_array(
				$paymentStatus,
				array(
					StatusService::STATUS_DEPOSITED,
					StatusService::STATUS_APPROVED,
				)
			) &&
			$this->hasFailed()
		) {
			$paymentStatus = StatusService::STATUS_DECLINED;
		}

		if ($isLoy) {
			$this->paymentModel->updateLoyStatus(
				$ipayId,
				$paymentStatus
			);
		} else {
			$this->paymentModel->updatePaymentStatus(
				$ipayId,
				$paymentStatus
			);
		}
	}

	/**
	 * Get payment id from the jwt
	 *
	 * @return string|null
	 */
	private function getIpayId(): ?string
	{
		if (
			property_exists($this->payload, 'mdOrder') &&
			is_string($this->payload->mdOrder)
		) {
			return $this->payload->mdOrder;
		}
		return null;
	}

	/**
	 * Payment/Authorization request has failed
	 *
	 * @return bool
	 */
	private function hasFailed(): bool
	{
		if (property_exists($this->payload, 'status') && is_scalar($this->payload->status)) {
			return (int) $this->payload->status !== 1;
		}
		return false;
	}


	/**
	 * Get payment status from the jwt
	 *
	 * @return string|null
	 */
	private function getPaymentStatus(): ?string
	{
		if (
			property_exists($this->payload, 'operation') &&
			is_string($this->payload->operation)
		) {
			return strtoupper($this->payload->operation);
		}
		return null;
	}

	private function getPaymentByiPayId(): ?array {
		return $this->paymentModel->getPaymentByIpayId($this->getIpayId());
	}
}
