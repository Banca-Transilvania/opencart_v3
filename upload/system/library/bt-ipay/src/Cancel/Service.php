<?php

namespace BtIpay\Opencart\Cancel;

use BtIpay\Opencart\Sdk\Client;
use BtIpay\Opencart\Cancel\Result;
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

	public function cancel(
		string $ipayId
	) {
		$payment = $this->getPaymentDetails($ipayId);
		$loyId = $payment->getLoyId();

		try {
			if (is_string($loyId)) {
				$this->result->setLoyId($loyId);
				$this->cancelLoy($payment);
			}

			if (!$payment->isAuthorized()) {
				return $this->result->setErrorMessage('invalid_payment_status');
			}
			$response = $this->cancelPart($ipayId);
			if (!$response->isSuccess()) {
				return $this->result->setErrorMessage($response->getErrorMessage() ?? '');
			}
			$this->result->isPayment();
		} catch (\Throwable $th) {
			$this->logger->write((string) $th);
			$this->result->internalError();
		}
	}


	private function cancelLoy(
		DetailResponse $payment
	) {
		$loy = $this->getPaymentDetails($payment->getLoyId());
		if ($loy->isAuthorized()) {
			$response = $this->cancelPart($payment->getLoyId());

			if (!$response->isSuccess()) {
				return $this->result->setErrorMessage($response->getErrorMessage() ?? '');
			}
			$this->result->isLoy();
		}
	}


	private function cancelPart(
		string $ipayId
	) {
		return $this->client->cancel($ipayId);
	}

	private function getPaymentDetails(string $ipayId): DetailResponse
	{
		$details = $this->client->getPayment($ipayId);
		if (!$details->isSuccess()) {
			throw new \Exception($details->getErrorMessage() ?? '');
		}
		return $details;
	}


}
