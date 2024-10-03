<?php
namespace BtIpay\Opencart\Sdk;

use BtIpay\Opencart\Card\Encrypt;
use BTransilvania\Api\Config\Config as Conf;

class Config
{
	/**
	 * @var \ModelExtensionPaymentBtIpay $model
	 */
	private $model;

	private string $lang;
	public function __construct($model, string $lang)
	{
		$this->model = $model;
		$this->lang = $lang;
	}
	public function getSdkAuth(): array
	{
		$testMode = $this->isTestMode();
		return array(
			'user' => $this->getAuthKey($testMode),
			'password' => $this->getAuthPassword($testMode),
			'environment' => $testMode ? Conf::TEST_MODE : Conf::PROD_MODE,
			'platformName' => 'Opencart',
			'language' => substr($this->lang, 0, 2),
		);
	}

	private function getAuthKey(bool $testMode): string
	{
		$key = 'authKey';
		if ($testMode) {
			$key = 'testAuthKey';
		}
		$value = $this->getValue($key);

		if ($value === null) {
			throw new \Exception('Missing payment settings');
		}

		return Encrypt::decrypt($value);
	}

	private function getAuthPassword(bool $testMode): string
	{
		$key = 'authPassword';
		if ($testMode) {
			$key = 'testAuthPassword';
		}
		$value = $this->getValue($key);
		if ($value === null) {
			throw new \Exception('Missing payment settings');
		}
		return Encrypt::decrypt($value);
	}

	private function isTestMode(): bool
	{
		return $this->getValue('testMode') === "1";
	}

	public function getValue(string $key): ?string
	{
		$value = $this->model->getConfig($key);
		if (!is_scalar($value) || strlen(trim($value)) === 0) {
			return null;
		}

		return (string) $value;
	}
}