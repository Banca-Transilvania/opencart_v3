<?php
namespace BtIpay\Opencart\Capture;

class Result
{
	protected float $loyAmount = 0.0;
	protected float $payAmount = 0.0;
	protected float $previouslyCaptured = 0.0;

	protected ?string $errorMessage = null;

	protected bool $hasInternalError = false;

	protected bool $paymentWasReversed = false;

	public function setLoyAmount(float $loyAmount)
	{
		$this->loyAmount = $loyAmount;
	}

	public function setPayAmount(float $payAmount)
	{
		$this->payAmount = $payAmount;
	}

	public function getLoyAmount(): float
	{
		return $this->loyAmount;
	}

	public function getPayAmount(): float
	{
		return $this->payAmount;
	}

	public function setErrorMessage(?string $errorMessage)
	{
		$this->errorMessage = $errorMessage;
	}

	public function hasError(): bool
	{
		return !is_null($this->errorMessage);
	}

	public function hasLoy(): bool
	{
		return $this->loyAmount > 0;
	}

	public function hasPayment(): bool
	{
		return $this->payAmount > 0;
	}

	public function isPartial(): bool
	{
		return $this->hasLoy() && ($this->hasErrorMessage() || $this->hasInternalError());
	}

	public function internalError()
	{
		$this->hasInternalError = true;
	}

	public function hasErrorMessage(): bool
	{
		return !is_null($this->errorMessage);
	}

	public function hasInternalError(): bool
	{
		return $this->hasInternalError;
	}

	/**
	 * Get total amount captured in this request
	 *
	 * @return float
	 */
	public function getTotal(): float
	{
		return $this->payAmount + $this->loyAmount;
	}

	public function getErrorMessage(): string
	{
		if ($this->hasInternalError()) {
			return 'Could not process request, check the logs for errors';
		}
		return $this->errorMessage ?? '';
	}

	public function addPreviouslyCaptured(float $amount)
	{
		$this->previouslyCaptured += $amount;
	}

	/**
	 * Get total captured in this request and previous requests
	 *
	 * @return float
	 */
	public function getTotalCaptured(): float
	{
		return $this->getTotal() + $this->previouslyCaptured;
	}

	public function paymentReversed()
	{
		$this->paymentWasReversed = true;
	}

	public function isPaymentReversed(): bool
	{
		return $this->paymentWasReversed;
	}
}
