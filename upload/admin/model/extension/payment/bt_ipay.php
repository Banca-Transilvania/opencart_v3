<?php

use BtIpay\Opencart\Order\StatusService;

class ModelExtensionPaymentBtIpay extends Model
{

    protected const ORDER_AREA_LISTENER_NAME = "bt_ipay_order_area";
    protected const ACCOUNT_COF_LISTENER_NAME = "bt_ipay_cof_display";
    public const CONFIG_KEY = "payment_bt_ipay";
    protected const TABLE_PAYMENTS = 'bt_ipay_payments';
    protected const TABLE_CARDS = 'bt_ipay_cards';
    protected const TABLE_REFUNDS = 'bt_ipay_refunds';
    public function install()
    {
        $this->addOrderArea();
        $this->addCofArea();
        $this->createDatabaseTables();
        if ($this->getPartialRefundStatus() === 2) {
            $this->addCustomStatus();
        }
    }

    public function uninstall()
    {
        $this->removeOrderArea();
        $this->removeCofArea();
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

    private function addCustomStatus()
    {
        $this->db->query(
            "INSERT INTO `" . DB_PREFIX . "order_status` (`order_status_id`, `language_id`, `name`) VALUES (NULL, '".(int)$this->config->get('config_language_id')."', 'Partially Refunded')"
        );
		$sql = "SELECT * FROM `" . DB_PREFIX . "order_status` WHERE `language_id` = '" . (int)$this->config->get('config_language_id') . "' ORDER BY `name` ASC";
        $this->cache->delete('order_status.' . md5($sql));
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

    /**
     * Create tables for payment data, cards and refunds
     *
     * @return void
     */
    private function createDatabaseTables()
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . self::TABLE_PAYMENTS . "` (
                `id` INT NOT NULL AUTO_INCREMENT,
				`order_id` BIGINT NOT NULL,
				`ipay_id` VARCHAR(255) NOT NULL,
				`amount` DECIMAL(15,2) NOT NULL,
				`status` VARCHAR(255) NOT NULL,
				`loy_id` VARCHAR(255) DEFAULT NULL,
				`loy_amount` DECIMAL(15,2) NOT NULL,
				`loy_status` VARCHAR(255) NOT NULL,
				`data` TEXT DEFAULT NULL,
				`created_at` TIMESTAMP NOT NULL,
				PRIMARY KEY `id` (`id`),
				INDEX `order_id` (`order_id`),
				INDEX `loy_id` (`loy_id`),
				UNIQUE KEY `ipay_id` (`ipay_id`)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );


        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . self::TABLE_CARDS . "` (
                `id` INT NOT NULL AUTO_INCREMENT,
				`customer_id` BIGINT NOT NULL,
				`ipay_id` VARCHAR(255) NOT NULL,
				`expiration` VARCHAR(255) NOT NULL,
				`cardholderName` VARCHAR(255) NOT NULL,
				`pan` VARCHAR(255) NOT NULL,
				`status` VARCHAR(255) NOT NULL,
				`created_at` TIMESTAMP NOT NULL,
				PRIMARY KEY (`id`),
				INDEX (`customer_id`),
				INDEX (`customer_id`, `pan`),
				UNIQUE KEY (`ipay_id`)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );


        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . self::TABLE_REFUNDS . "` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `order_id` BIGINT NOT NULL,
            `ipay_id` VARCHAR(255) NOT NULL,
            `amount` DECIMAL(15,2) NOT NULL,
            PRIMARY KEY (`id`),
            INDEX (`order_id`)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );
    }

    /**
     * Add bt ipay order admin tab
     *
     * @return void
     */
    private function addCofArea()
    {
        $this->load->model('setting/event');
        $this->model_setting_event->addEvent(
            self::ACCOUNT_COF_LISTENER_NAME,
            'catalog/controller/extension/module/account/after',
            'extension/payment/bt_ipay/renderCof'
        );
    }

    /**
     * Remove bt ipay order admin tab
     *
     * @return void
     */
    private function removeCofArea()
    {
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode(self::ACCOUNT_COF_LISTENER_NAME);
    }

    /**
     * Add bt ipay order admin tab
     *
     * @return void
     */
    private function addOrderArea()
    {
        $this->load->model('setting/event');
        $this->model_setting_event->addEvent(
            self::ORDER_AREA_LISTENER_NAME,
            'admin/view/sale/order_info/before',
            'extension/payment/bt_ipay/renderOrderArea'
        );
    }

    /**
     * Remove bt ipay order admin tab
     *
     * @return void
     */
    private function removeOrderArea()
    {
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode(self::ORDER_AREA_LISTENER_NAME);
    }

    public function getPayments(int $orderId): array
    {
        $qry = $this->db->query(
            "SELECT `ipay_id`, `amount`, `status`, `loy_amount`, `loy_id`, `loy_status` FROM `" . DB_PREFIX . "bt_ipay_payments` WHERE `order_id` = '" . $orderId . "' ORDER BY `created_at` DESC"
        );

        if ($qry->num_rows > 0) {
            return $qry->rows;
        }
        return [];
    }

    public function getPaymentByOrderId(int $orderId): ?array
    {
        $qry = $this->db->query(
            "SELECT * FROM `" . DB_PREFIX . "bt_ipay_payments` WHERE `order_id` = '" . $orderId . "' ORDER BY `created_at` DESC LIMIT 1"
        );

        if ($qry->num_rows === 0) {
            return null;
        }
        return $qry->row;
    }


    public function getAuthorizedAmount(int $orderId): float
    {
        $qry = $this->db->query(
            "SELECT `amount`, `loy_amount`, `loy_status`, `status` FROM `" . DB_PREFIX . "bt_ipay_payments` WHERE `order_id` = '" . $orderId . "' ORDER BY `created_at` DESC LIMIT 1"
        );

        if ($qry->num_rows === 0) {
            return 0.0;
        }
        $total = 0.0;

        if ($qry->row["status"] === StatusService::STATUS_APPROVED) {
            $total += floatval($qry->row["amount"]);
        }

        $loyAmount = floatval($qry->row["loy_amount"]);
        if ($loyAmount > 0 && $qry->row["loy_status"] === StatusService::STATUS_APPROVED) {
            $total += $loyAmount;
        }

        return $total;
    }

    public function getCapturedAmount(int $orderId): float
    {
        $qry = $this->db->query(
            "SELECT `amount`, `loy_amount`, `loy_status`, `status` FROM `" . DB_PREFIX . "bt_ipay_payments` WHERE `order_id` = '" . $orderId . "' ORDER BY `created_at` DESC LIMIT 1"
        );

        if ($qry->num_rows === 0) {
            return 0.0;
        }
        $total = 0.0;

        if (in_array($qry->row["status"], [StatusService::STATUS_DEPOSITED, StatusService::STATUS_PARTIALLY_REFUNDED, StatusService::STATUS_REFUNDED])) {
            $total += floatval($qry->row["amount"]);
        }

        $loyAmount = floatval($qry->row["loy_amount"]);
        if ($loyAmount > 0 && in_array($qry->row["loy_status"], [StatusService::STATUS_DEPOSITED, StatusService::STATUS_PARTIALLY_REFUNDED, StatusService::STATUS_REFUNDED])) {
            $total += $loyAmount;
        }

        return $total;
    }


    public function getRefundedAmount(int $orderId): float
    {
        $qry = $this->db->query(
            "SELECT SUM(`amount`) as `refunded` FROM `" . DB_PREFIX . "bt_ipay_refunds` WHERE `order_id` = '" . $orderId . "'"
        );

        if ($qry->num_rows === 0 || !isset($qry->row["refunded"])) {
            return 0.0;
        }

        return floatval($qry->row["refunded"]);
    }

    public function getRefunds(int $orderId): array
    {
        $qry = $this->db->query(
            "SELECT * FROM `" . DB_PREFIX . "bt_ipay_refunds` WHERE `order_id` = '" . $orderId . "'"
        );

        if ($qry->num_rows > 0) {
            return $qry->rows;
        }
        return [];
    }

    public function addRefunds(int $orderId, array $refunds)
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

        $this->db->query("DELETE FROM `" . DB_PREFIX . self::TABLE_REFUNDS."` WHERE `order_id` = '" . $orderId . "'");
		
        $dataString = implode("," , $newRefunds);
		$this->db->query("INSERT INTO `" . DB_PREFIX . self::TABLE_REFUNDS."` ( " . $keys . ") VALUES ".$dataString);

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

    public function updatePayment(string $ipayId, array $data)
    {
        $this->db->query("UPDATE `" . DB_PREFIX . "bt_ipay_payments` SET " . $this->formatUpdateValues($data) . " WHERE `ipay_id` = '" . $this->db->escape($ipayId) . "'");
    }

    /**
     * Get order from database
     *
     * @param integer $orderId
     *
     * @return void
     */
    public function getOrder(int $orderId)
    {
        $this->load->model('sale/order');
        return $this->model_sale_order->getOrder($orderId);
    }

    /**
     * Format float to currency
     *
     * @param float $amount
     *
     * @return void
     */
    public function formatCurrency(float $amount)
    {
        return  $this->currency->format($amount, $this->config->get('config_currency'));
    }

    /**
     * Add order history
     *
     * @param int $order_id
     * @param int $order_status_id
     * @param string $comment
     *
     * @return string
     */
    public function addOrderHistory($order_id, $order_status_id, $comment = '')
    {
        $log = new Log('bt-ipay-status-messages.log');
        $json = array();

        $data = array(
            'order_status_id' => $order_status_id,
            'notify' => 0,
            'comment' => $comment
        );

        $store_id = $this->config->get('config_store_id');

        $this->load->model('setting/store');

        $store_info = $this->model_setting_store->getStore($store_id);

        if ($store_info) {
            $url = $store_info['ssl'];
        } else {
            $url = HTTPS_CATALOG;
        }

        $session = $this->apiSession();
        $curl = curl_init();

        if ($session === null) {
            $log->write('Api session is null, cannot change status');
            return '';
        }

        // Set SSL if required
        if (substr($url, 0, 5) == 'https') {
            curl_setopt($curl, CURLOPT_PORT, 443);
        }
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_USERAGENT, $this->request->server['HTTP_USER_AGENT']);
        curl_setopt($curl, CURLOPT_FORBID_REUSE, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_URL, $url . 'index.php?route=api/order/history&order_id=' . $order_id);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($curl, CURLOPT_COOKIE, $this->config->get('session_name') . '=' . $session->getId() . ';');

        $json = curl_exec($curl);

        $data = json_decode($json, true);
        if ($data === null || !isset($data["success"])) {
            $log->write("Change status response: ".$json);
        }
        curl_close($curl);
        return $json;
    }
    
    /**
     * Create a session with api credentials for the add order history
     * call
     *
     * @return Session|null
     */
	private function apiSession()
	{
        $this->load->model('user/api');
		$api_info = $this->model_user_api->getApi($this->config->get('config_api_id'));
        if ($api_info && $this->user->hasPermission('modify', 'sale/order')) {
            $session = new Session($this->config->get('session_engine'), $this->registry);
            
            $session->start();
                    
            $this->model_user_api->deleteApiSessionBySessionId($session->getId());
            
            $this->model_user_api->addApiSession($api_info['api_id'], $session->getId(), $this->request->server['REMOTE_ADDR']);
            
            $session->data['api_id'] = $api_info['api_id'];
            $session->close();
			return $session;
        }
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
                $data[] = "`" . $key . "` = '" . $this->db->escape($value) . "'";
            }
        }
        return implode(",", $data);
    }
}