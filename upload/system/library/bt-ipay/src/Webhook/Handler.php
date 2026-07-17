<?php
namespace BtIpay\Opencart\Webhook;

use BtIpay\Opencart\Language;
use BtIpay\Opencart\Sdk\Client;
use BtIpay\Opencart\Sdk\Config;
use BtIpay\Opencart\Order\Message;
use BtIpay\Opencart\Order\StatusService;
use BTransilvania\Api\Model\IPayStatuses;

class Handler
{

	private \stdClass $payload;

	/** @var \ModelExtensionPaymentBtIpay */
	protected $paymentModel;

	protected Client $client;

	protected StatusService $statusService;

	protected Config $config;

	protected string $lang;

	/** @var \Log|null */
	protected $logger;

	public function __construct(\stdClass $jwt, $paymentModel, string $lang, ?Client $client = null, ?\Log $logger = null)
	{
		$this->config = new Config($paymentModel, $lang);
		$this->payload = $this->getPayload($jwt);
		$this->paymentModel = $paymentModel;
		$this->client = $client ?? new Client($this->config);
		$this->lang = $lang;
		$this->logger = $logger;
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

		// Rows are keyed by the main (card) ipay_id even for loyalty callbacks;
		// write via the row's own id so the loyalty leg still matches a row.
		$rowIpayId = (isset($payment['ipay_id']) && is_string($payment['ipay_id']))
			? $payment['ipay_id']
			: $ipayId;

		if ($orderId === null) {
			throw new \Exception('Cannot not determine order id');
		}

		if ($paymentStatus === StatusService::STATUS_REFUNDED) {
			$isFullRefunded = $this->addRefund($payment);
			if (!$isFullRefunded) {
				$paymentStatus = StatusService::STATUS_PARTIALLY_REFUNDED;
			}
		}

		// Ignore a late/duplicate APPROVED once the leg is already DEPOSITED —
		// replaying it would revert the status and overwrite the captured amount.
		$currentLegStatus = $isLoy
			? (string) ($payment['loy_status'] ?? '')
			: (string) ($payment['status'] ?? '');
		if (
			$paymentStatus === StatusService::STATUS_APPROVED &&
			$currentLegStatus === StatusService::STATUS_DEPOSITED
		) {
			return;
		}

		// Amount enrichment is best-effort; the status change below must still be
		// recorded even if the re-fetch fails, or BT keeps retrying the callback.
		try {
			if ($paymentStatus === StatusService::STATUS_DEPOSITED && !$this->hasFailed()) {
				$this->capture($ipayId, $rowIpayId, $isLoy);
			}

			if ($paymentStatus === StatusService::STATUS_APPROVED && !$this->hasFailed()) {
				$this->authorize($ipayId, $rowIpayId, $isLoy);
			}
		} catch (\Throwable $e) {
			$this->logEnrichmentFailure($ipayId, $e);
		}

		$this->updatePaymentStatus($rowIpayId, $paymentStatus, $isLoy);

		$statusService = $this->getStatusService($orderId);

		// Keep the per-leg note for loyalty callbacks.
		if ($isLoy) {
			$this->addLoyStatus($paymentStatus, $statusService);
		}

		// Drive order status from both legs combined (a single leg resolves to itself).
		$this->updateOrderStatus($this->getCombinedOrderStatus(), $statusService);
	}

	/**
	 * Combine the (post-update) statuses of the card and loyalty legs into a
	 * single order status. Re-reads the row so every status transformation that
	 * was just persisted (capture, decline, partial refund) is reflected.
	 *
	 * @return string
	 */
	private function getCombinedOrderStatus(): string
	{
		$payment = $this->getPaymentByiPayId();
		$mainStatus = isset($payment['status']) && strlen($payment['status']) ? $payment['status'] : null;
		$loyStatus = isset($payment['loy_status']) && strlen($payment['loy_status']) ? $payment['loy_status'] : null;

		return IPayStatuses::getCombinedStatus($mainStatus, $loyStatus) ?? '';
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


	private function capture(string $ipayId, string $rowIpayId, bool $isLoy)
	{
		$paymentDetails = $this->client->getPayment($ipayId);
		$totalCaptured = $paymentDetails->getTotalAvailable();
		if ($totalCaptured <= 0) {
			return;
		}

		if ($isLoy) {
			$this->paymentModel->updateLoyStatusAndAmount(
				$rowIpayId,
				StatusService::STATUS_DEPOSITED,
				$totalCaptured
			);
			return;
		}

		$this->persistMainLeg($rowIpayId, $paymentDetails, StatusService::STATUS_DEPOSITED, $totalCaptured);
	}

	/**
	 * Record the authorized amount on APPROVED. If the customer closes the
	 * browser before finishPayment runs, this callback is the only chance to
	 * store the amount so the merchant can capture it, so re-fetch and persist.
	 *
	 * @return void
	 */
	private function authorize(string $ipayId, string $rowIpayId, bool $isLoy)
	{
		$paymentDetails = $this->client->getPayment($ipayId);
		$authorized = $paymentDetails->getAmount();
		if ($authorized <= 0) {
			return;
		}

		if ($isLoy) {
			$this->paymentModel->updateLoyStatusAndAmount(
				$rowIpayId,
				StatusService::STATUS_APPROVED,
				$authorized
			);
			return;
		}

		$this->persistMainLeg($rowIpayId, $paymentDetails, StatusService::STATUS_APPROVED, $authorized);
	}

	/**
	 * Persist the card (main) leg. On a split payment, also record the loyalty
	 * leg's id/amount so the row stays matchable by loy_id; its status is fetched
	 * from the loyalty payment itself rather than copied from the card leg.
	 *
	 * @param \BtIpay\Opencart\Sdk\DetailResponse $paymentDetails
	 * @return void
	 */
	private function persistMainLeg(string $rowIpayId, $paymentDetails, string $status, float $amount)
	{
		$data = [
			'status' => $status,
			'amount' => $amount,
		];

		$loyId = $paymentDetails->getLoyId();
		if ($loyId !== null && $loyId !== '') {
			$data['loy_id']     = $loyId;
			$data['loy_amount'] = $paymentDetails->getLoyAmount();
			$data['loy_status'] = $this->client->getPayment($loyId)->getStatus();
		}

		$this->paymentModel->updatePayment($rowIpayId, $data);
	}

	/**
	 * Log an amount-enrichment failure without aborting the callback.
	 *
	 * @return void
	 */
	private function logEnrichmentFailure(string $ipayId, \Throwable $e)
	{
		if ($this->logger !== null) {
			$this->logger->write('BT iPay: could not enrich amount for payment ' . $ipayId . ': ' . (string) $e);
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

		if (($payment['loy_id'] ?? '') !== '') {
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
		return $this->paymentModel->getPaymentByiPayId($this->getIpayId());
	}
}
