<?php
namespace BtIpay\Opencart\Cancel;

class Result
{

	protected ?string $errorMessage = null;

	protected bool $hasInternalError = false;

	protected $loy = false;

	protected $loyId = null;

	protected $payment = false;

	public function setErrorMessage(?string $errorMessage)
	{
		$this->errorMessage = $errorMessage;
	}

	public function hasError(): bool
	{
		return !is_null($this->errorMessage);
	}

	public function isLoy()
	{
		$this->loy = true;
	}

	public function isPayment()
	{
		$this->payment = true;
	}

	public function hasLoy(): bool
	{
		return $this->loy;
	}

	public function hasPayment(): bool
	{
		return $this->payment;
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

	public function getErrorMessage(): string
	{
		if ($this->hasInternalError()) {
			return 'Could not process request, check opencart logs for errors';
		}
		return $this->errorMessage ?? '';
	}

	public function setLoyId(string $loyId)
	{
		$this->loyId = $loyId;
	}

	public function getLoyId(): string
	{
		return $this->loyId;
	}
}
