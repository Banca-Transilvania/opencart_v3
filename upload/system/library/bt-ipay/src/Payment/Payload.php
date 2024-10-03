<?php
namespace BtIpay\Opencart\Payment;

class Payload
{
    private array $order;

    private string $returnUrl;

    private $cofPayload;

    private $description;

    public function __construct(array $order, string $returnUrl, $cofPayload = null, ?string $description = null)
    {
        $this->order = $order;
        $this->returnUrl = $returnUrl;
        $this->cofPayload = $cofPayload;
        $this->description = $description;
    }
    public function toArray()
    {
        $orderNumber = $this->getOrderAttr('order_id');
        $payload = array(
            'orderNumber' => preg_replace( '/\s+/', '_',$orderNumber . microtime(false)),
            'amount' => intval(floatval($this->getOrderAttr('total')) * 100),
            'currency' => $this->getOrderAttr('currency_code'),
            'description' => $this->getFinalDescription($orderNumber),
            'returnUrl' => $this->returnUrl,
            'email' => $this->getEmail(),
            'orderBundle' => array(
                'orderCreationDate' => (new \DateTime('now', new \DateTimeZone('Europe/Bucharest')))->format('Y-m-d'),
                'customerDetails' => array(
                    'email' => $this->getEmail(),
                    'phone' => $this->getPhone(),
                    'contact' => $this->getFullName(),
                    'deliveryInfo' => $this->getDeliveryInfo(),
                    'billingInfo' => $this->getBillingInfo(),
                )
            )
        );

        if ($this->cofPayload instanceof CofPayload) {
            $payload = array_merge($payload, $this->cofPayload->toArray());
        }
        return $payload;
    }

    private function getFinalDescription(string $orderNumber)
    {
        $default = sprintf('Order: %1$s - %2$s ', $orderNumber, $this->getOrderAttr('store_name'));
        if (!is_string($this->description) || strlen($this->description) === 0) {
            return $default;
        }

        $description = $this->description;
        $description = preg_replace('/\{order_number\}/', $orderNumber, $description);
        $description = preg_replace('/\{shop_name\}/', $this->getOrderAttr('store_name'), (string)$description);

        if (!is_string($this->description) || strlen($this->description) === 0) {
            return $default;
        }
        
        return $description;
    }

    private function getPhone(): string
    {
        $phone = $this->getOrderAttr('telephone');
        if (substr($phone, 0, 2) === '07') {
            $phone = '4' . $phone;
        }
        return $phone;
    }


    private function getShippingMethod(): string
    {
        if (
            isset($this->order['shipping_method']['name']) &&
            is_string($this->order['shipping_method']['name'])
        ) {
            return $this->cleanString($this->order['shipping_method']['name']);
        }
        return 'comanda';
    }

    private function getFullName(): string
    {
        return substr($this->getOrderAttr('payment_firstname') . " " . $this->getOrderAttr('payment_lastname'), 0, 40);
    }

    private function getDeliveryInfo(): array
    {
        if (empty($this->getOrderAttr('shipping_iso_code_2')))
        {
            return $this->getBillingInfo();
        }

        $data = array(
            'deliveryType' => $this->getShippingMethod(),
            'country' => $this->getOrderAttr('shipping_iso_code_2'),
            'city' => $this->getOrderAttr('shipping_city'),
            'postalCode' => $this->getOrderAttr('shipping_postcode'),
        );

        return array_merge(
            $data,
            $this->getAddressChunks(
                $this->getOrderAttr('shipping_address_1') . $this->getOrderAttr('shipping_address_2')
            )
        );
    }

    private function getBillingInfo(): array
    {
        if (empty($this->getOrderAttr('payment_iso_code_2')))
        {
            return $this->getDeliveryInfo();
        }
        
        $data = array(
            'deliveryType' => $this->getShippingMethod(),
            'country' => $this->getOrderAttr('payment_iso_code_2'),
            'city' => substr($this->getOrderAttr('payment_city'), 0, 40),
            'postalCode' => $this->getOrderAttr('payment_postcode'),
        );

        return array_merge(
            $data,
            $this->getAddressChunks(
                $this->getOrderAttr('payment_address_1') . $this->getOrderAttr('payment_address_2')
            )
        );
    }

    private function getAddressChunks(string $address): array
    {
        $parts = str_split($address, 50);
        $parts = array_slice($parts, 0, 3);

        $data = array();
        foreach ($parts as $index => $part) {
            $ending = $index + 1;
            if ($ending === 1) {
                $ending = '';
            }
            $data['postAddress' . $ending] = $part;
        }
        return $data;
    }

    private function getEmail(): string
    {
        if (!isset($this->order['email']) || !is_scalar($this->order['email'])) {
            return '';
        }
        return (string) $this->order['email'];
    }

    private function getOrderAttr(string $key): string
    {
        if (!isset($this->order[$key]) || !is_scalar($this->order[$key])) {
            return '';
        }
        return $this->cleanString((string) $this->order[$key]);
    }

    private function cleanString(string $text): string
    {
        $normalize_chars = array(
            '&icirc;' => 'i',
            '&Icirc;' => 'I',
            '&acirc;' => 'a',
            '&Acirc;' => 'A',
            'Š' => 'S',
            'š' => 's',
            'Ð' => 'Dj',
            'Ž' => 'Z',
            'ž' => 'z',
            'À' => 'A',
            'Á' => 'A',
            'Â' => 'A',
            'Ã' => 'A',
            'Ä' => 'A',
            'Å' => 'A',
            'Æ' => 'A',
            'Ç' => 'C',
            'È' => 'E',
            'É' => 'E',
            'Ê' => 'E',
            'Ë' => 'E',
            'Ì' => 'I',
            'Í' => 'I',
            'Î' => 'I',
            'Ï' => 'I',
            'Ñ' => 'N',
            'Ò' => 'O',
            'Ó' => 'O',
            'Ô' => 'O',
            'Õ' => 'O',
            'Ö' => 'O',
            'Ø' => 'O',
            'Ù' => 'U',
            'Ú' => 'U',
            'Û' => 'U',
            'Ü' => 'U',
            'Ý' => 'Y',
            'Þ' => 'B',
            'ß' => 'Ss',
            'à' => 'a',
            'á' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'å' => 'a',
            'æ' => 'a',
            'ç' => 'c',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'ì' => 'i',
            'í' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ð' => 'o',
            'ñ' => 'n',
            'ò' => 'o',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ø' => 'o',
            'ù' => 'u',
            'ú' => 'u',
            'û' => 'u',
            'ý' => 'y',
            'þ' => 'b',
            'ÿ' => 'y',
            'ƒ' => 'f',
            'ü' => 'u',
            'ţ' => 't',
            'Ţ' => 'T',
            'ă' => 'a',
            'Ă' => 'A',
            'ş' => 's',
            'Ş' => 'S',
            'ț' => 't',
            'ș' => 's',
            'Ș' => 's',
            'Ț' => 'T',
        );

        foreach ($normalize_chars as $ch1 => $ch2) {
            $text = preg_replace('/' . $ch1 . '/i', $ch2, $text);
        }

        return (string) preg_replace('/[^-a-zA-Z0-9  .:;()]/', '', $text);
    }
}