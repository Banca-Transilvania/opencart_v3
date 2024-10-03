<?php
namespace BtIpay\Opencart\Payment;

class CofPayload
{
	private int $customerId;

	private ?string $ipayCardId;

	public function __construct(int $customerId, ?string $ipayCardId = null)
	{
		$this->customerId = $customerId;
		$this->ipayCardId = $ipayCardId;
	}

	public function toArray(): array
	{
		$payload = [
			"clientId" => $this->customerId,
		];

		if ($this->ipayCardId !== null) {
			$payload["bindingId"] = $this->ipayCardId;
		}

		return $payload;
	}
}