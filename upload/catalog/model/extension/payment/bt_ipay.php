<?php
class ModelExtensionPaymentBtIpay extends Model
{

	public const CARD_STATUS_ENABLED = "enabled";
	public const CARD_STATUS_DISABLED = "disabled";
	public const CONFIG_KEY = "payment_bt_ipay";
	public function getMethod($address, $total)
	{


		if ($this->getConfig('enabled') === "1") {
			return array(
				'code' => 'bt_ipay',
				'title' => $this->getConfig('title'),
				'terms' => '',
				'sort_order' => $this->config->get('sort_order')
			);
		}
	}

	/**
	 * Get config value,
	 * If no custom config is present for this store use the value from store = 0
	 *
	 * @param string $code
	 *
	 * @return mixed
	 */
	public function getConfig(string $code)
	{
		$storeId = $this->config->get('config_store_id');

		$this->load->model('setting/setting');

		$useMasterConfig = $this->model_setting_setting->getSettingValue(self::CONFIG_KEY . "_customStoreConfig", $storeId) !== "1";

		if ($useMasterConfig && $storeId != 0) {
			$storeId = 0;
		}
		return $this->model_setting_setting->getSettingValue(
			self::CONFIG_KEY . "_" . $code,
			$storeId
		);
	}

	public function isCofEnabled(): bool
	{
		return $this->getConfig("cofEnabled") === "1";
	}

	public function isAuthorize(): bool
	{
		return $this->getConfig('paymentFlow') === 'authorize';
	}

	
	public function createCard(array $cardData)
	{
		unset($cardData["approvalCode"]);
		$this->deleteCardByPan($cardData['pan']);
		$cardData['status'] = self::CARD_STATUS_ENABLED;

		$keys = implode(
			",",
			array_map(
				function ($key) {
					return "`" . $key . "`";
				},
				array_keys($cardData)
			)
		);

		$dataString = implode(
			",",
			array_map(function ($field) {
				return "'" . $this->db->escape($field) . "'";
			}, $cardData)
		);
		$this->db->query("INSERT INTO `" . DB_PREFIX . "bt_ipay_cards` ( " . $keys . ", `created_at`) VALUES (" . $dataString . ", NOW())");
	}

	public function deleteCardById(int $id, int $customerId): bool
	{
		return $this->db->query("DELETE FROM `" . DB_PREFIX . "bt_ipay_cards` WHERE `id` = '" . $this->db->escape($id) . "' AND `customer_id` = '" . $customerId . "'");
	}

	public function cardExists(string $ipayCardId): bool
	{
		$qry = $this->db->query("SELECT `id` FROM `" . DB_PREFIX . "bt_ipay_cards` WHERE `ipay_id` = '" .  $this->db->escape($ipayCardId) . "'");

		return $qry->num_rows > 0;
		
	}

	private function deleteCardByPan(string $pan)
	{
		$this->db->query("DELETE FROM `" . DB_PREFIX . "bt_ipay_cards` WHERE `pan` = '" . $this->db->escape(trim($pan)) . "'");
	}

	public function getPartialRefundStatus(): int
    {
        $qry = $this->db->query(
            "SELECT `order_status_id` FROM `" . DB_PREFIX . "order_status` WHERE `name` = 'Partially Refunded'"
        );

        if ($qry->num_rows === 0 || !isset($qry->row["order_status_id"])) {
            return 2;
        }

        return intval($qry->row["order_status_id"]);
    }

	public function getCustomerCards(int $customerId):array 
	{
		$qry = $this->db->query("SELECT `id`, `expiration`, `cardholderName`, `pan`, `status` FROM `" . DB_PREFIX . "bt_ipay_cards` WHERE `customer_id` = '" .  $this->db->escape($customerId) . "'");

		if ($qry->num_rows > 0) {
			return $qry->rows;
		}
		return [];
	}

	public function getEnabledCustomerCards(int $customerId):array 
	{
		$qry = $this->db->query("SELECT `id`, `expiration`, `cardholderName`, `pan`, `status` FROM `" . DB_PREFIX . "bt_ipay_cards` WHERE `customer_id` = '" .  $this->db->escape($customerId) . "' AND `status` = 'enabled'");

		if ($qry->num_rows > 0) {
			return $qry->rows;
		}
		return [];
	}

	public function getCardById(int $cardId): array
	{
		$qry = $this->db->query("SELECT `ipay_id` FROM `" . DB_PREFIX . "bt_ipay_cards` WHERE `id` = '" .  $this->db->escape($cardId) . "' AND `status` = 'enabled'");

		if ($qry->num_rows > 0) {
			return $qry->row;
		}
		return [];
	}

	public function saveCardStatus(int $cardId, int $customerId, bool $enable): bool
	{
		$status = $enable ? 'enabled': 'disabled';
		return $this->db->query("UPDATE `" . DB_PREFIX . "bt_ipay_cards` SET `status` = '" .  $this->db->escape($status) . "' WHERE `id` = '" . $this->db->escape($cardId) . "' AND `customer_id` = '" .  $this->db->escape($customerId) . "'");
	}


	public function getCardIpayId(int $id, int $customerId): ?string
	{
		$qry = $this->db->query("SELECT `ipay_id` FROM `" . DB_PREFIX . "bt_ipay_cards` WHERE `id` = '" . $id . "' AND `customer_id` = '" . $customerId . "' LIMIT 1");

		if ($qry->num_rows && isset($qry->row["ipay_id"]) && is_string($qry->row["ipay_id"])) {
			return $qry->row["ipay_id"];
		}
		return null;
	}


	public function createPayment(string $ipayId, int $orderId)
	{
		$dataString = implode(",", [
			"'" . $this->db->escape($orderId) . "'",
			"'" . $this->db->escape($ipayId) . "'",
			"'CREATED'"
		]);
		$this->db->query("INSERT INTO `" . DB_PREFIX . "bt_ipay_payments` ( `order_id`, `ipay_id`, `status`, `created_at`) VALUES (" . $dataString . ", NOW())");
	}

	public function updatePayment(string $ipayId, array $data )
	{
		$this->db->query("UPDATE `" . DB_PREFIX . "bt_ipay_payments` SET " . $this->formatUpdateValues($data) . " WHERE `ipay_id` = '" . $this->db->escape($ipayId) . "'");
	}


	public function updatePaymentStatus(string $ipayId, string $status)
    {
        $this->updatePayment($ipayId, ["status" => $status]);
    }

    public function updateLoyStatus(string $ipayId, string $status)
    {
        $this->updatePayment($ipayId, ["loy_status" => $status]);
    }

    public function updatePaymentStatusAndAmount(string $ipayId, string $status, float $amount)
    {
        $this->updatePayment($ipayId, ["status" => $status, "amount" => $amount]);
    }

    public function updateLoyStatusAndAmount(string $ipayId, string $status, float $amount)
    {
        $this->updatePayment($ipayId, ["loy_status" => $status, "loy_amount" => $amount]);
    }

	public function getPaymentByiPayId(string $ipayId): ?array
	{
		$qry = $this->db->query("SELECT `order_id`, `ipay_id`, `loy_id` FROM `" . DB_PREFIX . "bt_ipay_payments` WHERE `ipay_id` = '" . $this->db->escape($ipayId) . "' OR `loy_id` = '" . $this->db->escape($ipayId) . "' LIMIT 1");

		if ($qry->num_rows && isset($qry->row["order_id"]) && is_scalar($qry->row["order_id"])) {
			return$qry->row;
		}
		return null;
	}

	public function getOrderId(string $ipayId): ?int
	{
		$qry = $this->db->query("SELECT `order_id` FROM `" . DB_PREFIX . "bt_ipay_payments` WHERE `ipay_id` = '" . $this->db->escape($ipayId) . "' AND `status` = 'CREATED' LIMIT 1");

		if ($qry->num_rows && isset($qry->row["order_id"]) && is_scalar($qry->row["order_id"])) {
			return (int) $qry->row["order_id"];
		}
		return null;
	}

	public function addRefunds(int $orderId, array $refunds, string $ipayId)
    {

        $newRefunds = [];

        $keys = [];
        foreach ($refunds as $refund) {
            $values = array_merge(
                $refund,
                ["order_id" => $orderId]
            );
            $newRefunds[] = "(".$this->formatValuesForInsert($values).")";

            if (count($keys) === 0 ) {
                $keys = array_keys($values);
            }
        }

        $keys = implode(
			",",
			array_map(
				function ($key) {
					return "`" . $key . "`";
				},
				$keys
			)
		);

        if (count($newRefunds) === 0)
        {
            return;
        }

        $this->db->query("DELETE FROM `" . DB_PREFIX . "bt_ipay_refunds` WHERE `ipay_id` = '" . $ipayId . "'");
		
        $dataString = implode("," , $newRefunds);
		$this->db->query("INSERT INTO `" . DB_PREFIX . "bt_ipay_refunds` ( " . $keys . ") VALUES ".$dataString);

    }

	private function formatValuesForInsert(array $data)
    {
        return implode(
			",",
			array_map(function ($field) {
				return "'" . $this->db->escape($field) . "'";
			}, $data)
		);
    }

	/**
	 * Format array values for update
	 *
	 * @param array $values
	 *
	 * @return string
	 */
	private function formatUpdateValues(array $values): string
	{
		$data = [];
		foreach ($values as $key => $value) {
			if (is_string($key)) {
				$data[] = "`".$key."` = '" . $this->db->escape($value) . "'";
			}
		}
		return implode(",",$data);
	}

	public function getOrder(int $orderId)
	{
        $this->load->model('checkout/order');
		return $this->model_checkout_order->getOrder($orderId);
	}

	public function addOrderHistory($order_id, $order_status_id, $comment = '')
	{
		$this->load->model('checkout/order');
		return $this->model_checkout_order->addOrderHistory($order_id, $order_status_id, $comment);
	}
}
