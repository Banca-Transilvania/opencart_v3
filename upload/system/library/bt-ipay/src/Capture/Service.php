<?php
namespace BtIpay\Opencart\Capture;

use BtIpay\Opencart\Capture\Result;
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
	public function capture(
		string $ipayId,
		float $amount
	) {

		$payment = $this->getPaymentDetails($ipayId);
		$loyAmount = $payment->getLoyAmount();
		$this->result->addPreviouslyCaptured($payment->getTotalAvailable());
		try {
			$loyAmountCaptured = 0;
			if ($loyAmount > 0) {
				$loyAmountCaptured = $this->cancelLoy(
					$payment,
					$amount,
					$loyAmount
				);
				$this->result->setLoyAmount($loyAmountCaptured);
			}

			$amount -= $loyAmountCaptured;

			if ($amount <= 0) {
				$this->cancelPayment($ipayId, $payment->isAuthorized());
				return;
			}

			if (!$payment->isAuthorized()) {
				return $this->result->setErrorMessage('invalid_payment_status');
			}

			$response = $this->capturePart($ipayId, $amount);
			if (!$response->isSuccess()) {
				return $this->result->setErrorMessage($response->getErrorMessage());
			}
			$this->result->setPayAmount($amount);
		} catch (\Throwable $th) {
			$this->logger->write((string) $th);
			$this->result->internalError();
		}
	}

	private function cancelPayment(string $ipayId, bool $isAuthorized)
	{
		if (!$isAuthorized) {
			return;
		}
		$response =  $this->client->cancel($ipayId);

		if (!$response->isSuccess()) {
			return $this->result->setErrorMessage($response->getErrorMessage());
		}
		$this->result->paymentReversed();
	}


	private function cancelLoy(
		DetailResponse $payment,
		float $amount,
		float $loyAmount
	): float {
		$loy = $this->getPaymentDetails($payment->getLoyId());
		$this->result->addPreviouslyCaptured($loy->getTotalAvailable());
		if ($loy->isAuthorized()) {
			$totalLoy = $this->determineAmount($amount, $loyAmount);
			$response = $this->capturePart($payment->getLoyId(), $totalLoy);

			if (!$response->isSuccess()) {
				$this->result->setErrorMessage($response->getErrorMessage());
				return 0;
			}

			return $totalLoy;
		}
		return 0.0;
	}


	private function capturePart(
		string $ipayId,
		float $amount
	) {
		return $this->client->capture($ipayId, intval(round($amount,2) * 100));
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
	 * Determine amount to be captured for loy
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
