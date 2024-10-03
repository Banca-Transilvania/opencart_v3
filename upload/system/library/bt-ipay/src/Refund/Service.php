<?php
namespace BtIpay\Opencart\Refund;

use BtIpay\Opencart\Refund\Result;
use BtIpay\Opencart\Sdk\Client;
use BtIpay\Opencart\Sdk\DetailResponse;
use Log;

class Service
{

	protected Result $result;

	protected Log $logger;

	protected Client $client;

	public function __construct(Result $result, Log $logger, Client $client)
	{
		$this->result = $result;
		$this->logger = $logger;
		$this->client = $client;
	}
	public function refund(
		string $ipayId,
		float $amount
	) {

		$payment = $this->getPaymentDetails($ipayId);

		$loyAmount = $payment->getLoyAmount();
		try {
			$loyAmountRefunded = 0;
			if ($loyAmount > 0) {
				$loyAmountRefunded = $this->refundLoy(
					$payment,
					$amount
				);
				$this->result->setLoyAmount($loyAmountRefunded);
			}

			$amount -= $loyAmountRefunded;

			if ($amount <= 0) {
				return;
			}

			if (!$payment->canRefund()) {
				return $this->result->setErrorMessage('invalid_payment_status');
			}

			$response = $this->refundPart($ipayId, $amount);
			if (!$response->isSuccess()) {
				return $this->result->setErrorMessage($response->getErrorMessage());
			}
			$this->result->setPayAmount($amount);
		} catch (\Throwable $th) {
			$this->logger->write((string) $th);
			$this->result->internalError();
		}
	}

	public function getRefundState(string $ipayId, $loy_id)
	{
		if (is_string($loy_id) && strlen($loy_id)) {
			$details = $this->getPaymentDetails($loy_id);
			$this->result->setIsLoyFullyRefunded(abs($details->getTotalAvailable()) < 0.01);
			$this->result->addRefunds($details->getRefunds($loy_id));
		} else {
			$this->result->setIsLoyFullyRefunded(true);
		}
		if ($this->result->hasPayment()) {
			$details = $this->getPaymentDetails($ipayId);
			$this->result->setIsPaymentFullyRefunded(abs($details->getTotalAvailable()) < 0.01);
			$this->result->addRefunds($details->getRefunds($ipayId));
		} else {
			$this->result->setIsPaymentFullyRefunded(true);
		}
	}


	private function refundLoy(
		DetailResponse $payment,
		float $amount
	): float {
		$loyId = $payment->getLoyId();
		$loy = $this->getPaymentDetails($loyId);
		if ($loy->canRefund()) {
			$totalLoy = $this->determineAmount($amount, $loy->getTotalAvailable());
			$response = $this->refundPart($loyId, $totalLoy);

			if (!$response->isSuccess()) {
				$this->result->setErrorMessage($response->getErrorMessage());
				return 0;
			}
			$this->result->setLoyId($loyId);
			return $totalLoy;
		}
		return 0.0;
	}


	private function refundPart(
		string $ipayId,
		float $amount
	) {
		return $this->client->refund($ipayId, intval(round($amount,2) * 100));
	}

	private function getPaymentDetails(string $ipayId): DetailResponse
	{
		$details = $this->client->getPayment($ipayId);
		if (!$details->isSuccess()) {
			throw new \Exception($details->getErrorMessage() ?? '');
		}
		return $details;
	}

	/**
	 * Determine amount to be refunded for loy
	 *
	 * @param float $total
	 * @param float $maxAmount
	 *
	 * @return float
	 */
	private function determineAmount(float $total, float $maxAmount): float
	{
		if ($total > $maxAmount) {
			return $maxAmount;
		}

		return $total;
	}


}
