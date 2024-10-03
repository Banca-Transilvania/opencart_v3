<?php
namespace BtIpay\Opencart\Refund;

class Result
{
	protected float $loyAmount = 0.0;
	protected float $payAmount = 0.0;
	protected ?string $errorMessage = null;
	protected bool $hasInternalError = false;
	protected bool $paymentFullyRefunded = false;
	protected bool $loyFullyRefunded = false;
	protected array $refunds = [];
	protected ?string $loyId = null;

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

	public function setIsPaymentFullyRefunded(bool $fullyRefunded)
	{
		$this->paymentFullyRefunded = $fullyRefunded;
	}

	public function isPaymentFullyRefunded(): bool
	{
		return $this->paymentFullyRefunded;
	}


	public function setIsLoyFullyRefunded(bool $fullyRefunded)
	{
		$this->loyFullyRefunded = $fullyRefunded;
	}

	public function isLoyFullyRefunded(): bool
	{
		return $this->loyFullyRefunded;
	}

	public function addRefunds(array $refunds)
	{
		$this->refunds = array_merge($this->refunds, $refunds);
	}


	public function getRefunds(): array
	{
		return $this->refunds;
	}
	

	public function getLoyId(): ?string
	{
		return $this->loyId;
	}

	public function setLoyId(?string $loyId)
	{
		$this->loyId = $loyId;
	}
}
