<?php

use BtIpay\Opencart\Card\Encrypt;

class ControllerExtensionPaymentBtIpay extends Controller
{

	public const BT_IPAY_VERSION = "1.0.1";

	public const CONFIG_KEY = "payment_bt_ipay";
	private $error = array();

	/**
	 * Install hook
	 *
	 * @return void
	 */
	public function install()
	{
		$this->model()->install();
	}

	/**
	 * Uninstall hook
	 *
	 * @return void
	 */
	public function uninstall()
	{
		$this->model()->uninstall();
	}

	public function renderOrderArea(&$route, &$data)
	{
		$this->load->language('extension/payment/bt_ipay');
		$this->getLib();
		$orderId = $this->request->get['order_id'] ?? null;
		if (!is_scalar($orderId) || intval($orderId) <= 0) {
			return;
		}

		$orderId = intval($orderId);
		$flashMessage = null;
		if (isset($this->session->data['bt_ipay_message'])) {
			$flashMessage = $this->session->data['bt_ipay_message'];
			unset($this->session->data['bt_ipay_message']);
		}

		$payModel = $this->model();
		$totalAuthorized = $payModel->getAuthorizedAmount($orderId);

		$availableToRefund = $payModel->getCapturedAmount($orderId) - $payModel->getRefundedAmount($orderId);
		$tabContent = $this->load->view(
			'extension/payment/bt_ipay_order',
			[
				"actionCapture" => $this->linkTo("extension/payment/bt_ipay/paymentCapture"),
				"actionCancel" => $this->linkTo("extension/payment/bt_ipay/paymentCancel"),
				"actionRefund" => $this->linkTo("extension/payment/bt_ipay/paymentRefund"),
				"authorizedAmount" => $totalAuthorized,
				"canCapture" => $totalAuthorized > 0,
				"payments" => $payModel->getPayments($orderId),
				"orderId" => $orderId,
				"refundAmount" => round($availableToRefund, 2),
				"canRefund" => $availableToRefund > 0,
				"refunds" => $payModel->getRefunds($orderId),
				"fashMessage" => $flashMessage
			]
		);
		$data['tabs'][] = [
			'title' => 'BT iPay',
			'code' => 'bt_ipay_order_area',
			'content' => $tabContent,
		];
	}

	/**
	 * Get our bt ipay model
	 *
	 * @return ModelExtensionPaymentBtIpay
	 */
	private function getModel()
	{
		if ($this->model_extension_payment_bt_ipay === null) {
			$this->load->model('extension/payment/bt_ipay');
		}
		return $this->model_extension_payment_bt_ipay;
	}

	/**
	 * Process capture
	 *
	 * @return void
	 */
	public function paymentCapture()
	{
		$error = $this->validateFormWithAmount();
		if (is_array($error)) {
			return $this->json($error);
		}
		$this->load->model('sale/order');

		$response = $this->getLib()->capture(
			$this->getModel(),
			$this->config->get('config_admin_language'),
			$this->request->post['bt-ipay-order-id'],
			$this->request->post['bt-ipay-amount'],
		);

		$this->json($response);
	}


	/**
	 * Process refund
	 *
	 * @return void
	 */
	public function paymentRefund()
	{
		$error = $this->validateFormWithAmount("invalid_refund_amount");
		if (is_array($error)) {
			return $this->json($error);
		}
		$this->load->model('sale/order');

		$response = $this->getLib()->refund(
			$this->getModel(),
			$this->config->get('config_admin_language'),
			$this->request->post['bt-ipay-order-id'],
			$this->request->post['bt-ipay-amount'],
		);

		$this->json($response);
	}

	/**
	 * Process cancel
	 *
	 * @return void
	 */
	public function paymentCancel()
	{

		if (!$this->isOrderNumberValid()) {
			return $this->json(["error" => true, "message" => $this->language->get('invalid_order_id')]);
		}

		$this->load->model('sale/order');

		$response = $this->getLib()->cancel(
			$this->getModel(),
			$this->config->get('config_admin_language'),
			$this->request->post['bt-ipay-order-id']
		);

		$this->json($response);
	}

	private function json(array $response)
	{
		$this->response->addHeader('Content-Type: application/json');
		if (isset($response['message'])) {
			$this->session->data['bt_ipay_message'] = $response;
		}
		$this->response->setOutput(json_encode($response));
	}
	private function validateFormWithAmount(
		string $amountMessage = "invalid_capture_amount"
	): ?array {
		$this->load->language('extension/payment/bt_ipay');
		if (!$this->isOrderNumberValid()) {
			return ["error" => true, "message" => $this->language->get('invalid_order_id')];
		}

		if (!$this->isAmountValid()) {
			return ['error' => true, 'message' => $this->language->get($amountMessage)];
		}

		return null;
	}

	private function isAmountValid()
	{
		return isset($this->request->post['bt-ipay-amount']) &&
			is_scalar($this->request->post['bt-ipay-amount']) &&
			floatval($this->request->post['bt-ipay-amount']) > 0;
	}

	private function isOrderNumberValid(): bool
	{
		return isset($this->request->post['bt-ipay-order-id']) &&
			is_scalar($this->request->post['bt-ipay-order-id']) &&
			intval($this->request->post['bt-ipay-order-id']) > 0;
	}




	public function index()
	{
		$this->load->language('extension/payment/bt_ipay');
		$this->getLib();
		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/setting');

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting(
				self::CONFIG_KEY,
				array_merge(
					$this->request->post,
					["payment_bt_ipay_status" => 1],
					$this->handleCredentials()
				),
				$this->getCurrentStore()
			);

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect(
				$this->linkTo("extension/payment/bt_ipay", ["store_id" => $this->getCurrentStore()]),
			);
		}

		if (isset($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];
			unset($this->session->data['success']);
		}

		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

		$data['callbackUrl'] = $this->getCallbackUrl();
		$this->response->setOutput(
			$this->load->view(
				'extension/payment/bt_ipay',
				array_merge(
					$data,
					$this->getTemplateParts(),
					$this->getRoutes(),
					$this->getBreadcrumbs(),
					$this->getConfigValues(),
					$this->getStores(),
					$this->getOrderStatuses(),
					["version" => self::BT_IPAY_VERSION]
				)
			)
		);
	}

	private function handleCredentials()
	{
		$savedValues = $this->model_setting_setting->getSetting(self::CONFIG_KEY, $this->getCurrentStore());

		$hiddenFields = [
			"callbackKey",
			"authPassword",
			"testAuthPassword"
		];

		$data = [];
		foreach ($hiddenFields as $hiddenField) {
			$configKey = self::CONFIG_KEY . "_" . $hiddenField;

			$data[$configKey] = '';
			if (isset($savedValues[$configKey])) {
				$data[$configKey] = $savedValues[$configKey];
			}
			if (
				isset($this->request->post[$configKey]) &&
				is_string($this->request->post[$configKey]) &&
				strlen(trim($this->request->post[$configKey])) > 0
			) {
				$data[$configKey] = Encrypt::encrypt($this->request->post[$configKey]);
			}
		}

		foreach (["authKey", "testAuthKey"] as $field) {
			$configKey = self::CONFIG_KEY . "_" . $field;
			if (
				isset($this->request->post[$configKey]) &&
				is_string($this->request->post[$configKey]) &&
				strlen(trim($this->request->post[$configKey])) > 0
			) {
				$data[$configKey] = Encrypt::encrypt($this->request->post[$configKey]);
			}
		}
		return $data;
	}

	/**
	 * Get list of stores
	 *
	 * @return array
	 */
	private function getStores(): array
	{
		$this->load->model('setting/store');

		$stores = [];
		foreach ($this->model_setting_store->getStores() as $store) {
			$stores[] = [
				'id' => $store['store_id'],
				'name' => $store['name'],
				'url' => $this->linkTo("extension/payment/bt_ipay", ["store_id" => $store["store_id"]]),
			];
		}

		return [
			"stores" => array_merge(
				[
					[
						'id' => 0,
						'name' => $this->language->get('default_store'),
						'url' => $this->linkTo("extension/payment/bt_ipay", ["store_id" => 0]),
					]
				],
				$stores
			),
			"currentStore" => $this->getCurrentStore()
		];
	}

	/**
	 * Get setting value with defaults
	 *
	 * @return array
	 */
	private function getConfigValues(): array
	{
		$savedValues = $this->model_setting_setting->getSetting(self::CONFIG_KEY, $this->getCurrentStore());
		$displayValues = [];
		$defaults = $this->getConfigDefaults();
		foreach ($defaults as $configKey => $defaultValue) {
			$configKey = self::CONFIG_KEY . "_" . $configKey;
			$displayValues[$configKey] = $this->getConfigValue($configKey, $savedValues, $defaultValue);
		}
		return $displayValues;
	}

	/**
	 * Get config value for a single config
	 *
	 * @param string $configKey
	 * @param array $savedValues
	 * @param mixed $defaultValue
	 *
	 * @return mixed
	 */
	private function getConfigValue(string $configKey, array $savedValues, $defaultValue)
	{
		if (isset($this->request->post[$configKey])) {
			return $this->request->post[$configKey];
		}

		if (
			in_array(
				$configKey,
				[
					self::CONFIG_KEY . "_" . "authKey",
					self::CONFIG_KEY . "_" . "testAuthKey"
				]
			) && isset($savedValues[$configKey])
		) {			
			return Encrypt::decrypt($savedValues[$configKey]);
		}

		if (isset($savedValues[$configKey])) {
			return $savedValues[$configKey];
		}

		return $defaultValue;
	}

	private function getConfigDefaults(): array
	{
		return [
			"enabled" => "1",
			"sort_order" => "1",
			"customStoreConfig" => "0",
			"title" => $this->language->get('config_pay_title'),
			"callbackKey" => "",
			"description" => "Order: {order_number} - {shop_name} ",
			"paymentFlow" => "pay",
			"cofEnabled" => "0",
			"testMode" => "1",
			"authKey" => "",
			"authPassword" => "",
			"testAuthKey" => "",
			"testAuthPassword" => "",
			"statusDeposited" => "15",
			"statusApproved" => "2",
			"statusReversed" => "7",
			"statusDeclined" => "10",
			"statusRefunded" => "11",
			"statusCreated" => "1",
			"statusPartiallyRefunded" => $this->getModel()->getPartialRefundStatus()
		];
	}

	/**
	 * Get store id, defaults to 0
	 *
	 * @return integer
	 */
	private function getCurrentStore(): int
	{

		if (
			isset($this->request->get['store_id']) &&
			is_scalar($this->request->get['store_id'])
		) {
			return (int) $this->request->get['store_id'];
		}
		return 0;
	}


	private function getOrderStatuses(): array
	{
		$this->load->model('localisation/order_status');
		return ['order_statuses' => $this->model_localisation_order_status->getOrderStatuses()];
	}

	/**
	 * Get breadcrumb data
	 *
	 * @return array
	 */
	private function getBreadcrumbs(): array
	{
		return [
			"breadcrumbs" => [
				[
					'text' => $this->language->get('text_home'),
					'href' => $this->linkTo('common/dashboard')
				],
				[
					'text' => $this->language->get('text_extension'),
					'href' => $this->linkTo("marketplace/extension", ["type" => "payment"])
				],
				[
					'text' => $this->language->get('heading_title'),
					'href' => $this->linkTo("extension/payment/bt_ipay")
				]
			]
		];
	}

	/**
	 * Get routes for submit/go back
	 *
	 * @return array
	 */
	private function getRoutes(): array
	{
		return [
			"submit" => $this->linkTo("extension/payment/bt_ipay", ["store_id" => $this->getCurrentStore()]),
			"cancel" => $this->linkTo("marketplace/extension", ["type" => "payment"])
		];
	}

	/**
	 * Create ipay callback url
	 *
	 * @return string
	 */
	private function getCallbackUrl(): string
	{

		if ($this->request->server['HTTPS']) {
			$admin = HTTPS_SERVER;
			$catalog = HTTPS_CATALOG;
		} else {
			$admin = HTTP_SERVER;
			$catalog = HTTP_CATALOG;
		}

		return str_replace(
			$admin,
			$catalog,
			$this->url->link('extension/payment/bt_ipay/callback', '', true)
		);
	}

	/**
	 * Create admin link
	 *
	 * @param string $path
	 * @param array $query
	 *
	 * @return string
	 */
	private function linkTo(string $path, array $query = []): string
	{
		$queryString = http_build_query(
			array_merge(
				$query,
				[
					"user_token" => $this->session->data['user_token'] ?? ''
				]
			)
		);

		return $this->url->link($path, $queryString, true);
	}

	/**
	 * Get templates needed to render the config page
	 *
	 * @return array
	 */
	private function getTemplateParts(): array
	{
		return [
			'header' => $this->load->controller('common/header'),
			'column_left' => $this->load->controller('common/column_left'),
			'footer' => $this->load->controller('common/footer')
		];
	}


	private function model()
	{
		if ($this->model_extension_payment_bt_ipay === null) {
			$this->load->model('extension/payment/bt_ipay');
		}
		return $this->model_extension_payment_bt_ipay;
	}

	/**
	 * Validate page save
	 *
	 * @return bool
	 */
	protected function validate()
	{
		if (!$this->user->hasPermission('modify', 'extension/payment/bt_ipay')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		if (!$this->isValidStoreId()) {
			$this->error['warning'] = $this->language->get('unknown_store');
		}

		return !$this->error;
	}

	private function isValidStoreId(): bool
	{
		$currentStoresIds = array_map(
			function ($store) {
				return $store['id'] ?? 0;
			},
			$this->getStores()['stores']
		);

		return in_array($this->getCurrentStore(), $currentStoresIds);
	}

	private function getLib(): Bt_Ipay
	{
		if ($this->bt_ipay === null) {
			$this->load->library('bt_ipay');
		}
		return $this->bt_ipay;
	}

}