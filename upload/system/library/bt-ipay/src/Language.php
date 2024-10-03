<?php
namespace BtIpay\Opencart;

class Language
{
    private $data = [];
    public function __construct(string $language)
    {
        $this->load($language);
    }

    public function load(string $language)
    {
        $file = DIR_SYSTEM . 'library/bt-ipay/language/en_gb.php';
        if (is_file($file)) {
            $this->merge(include_once ($file));
        }
        $file = DIR_SYSTEM . 'library/bt-ipay/language/' . $language . '.php';
        if (is_file($file)) {
            $this->merge(include_once ($file));
        }
    }

    /**
     * Get translation by key, replace params using sprintf
     *
     * @param string $key
     * @param array $params
     *
     * @return string
     */
    public function get(string $key, array $params = []): string
    {
        if (array_key_exists($key, $this->data) && is_string($this->data[$key])) {
            if (count($params) > 0) {
                return sprintf($this->data[$key], ...array_values($params));
            }

            return $this->data[$key];
        }
        return $key;
    }

    private function merge($data)
    {
        if (is_array($data)) {
            $this->data = array_merge($this->data, $data);
        }
    }


}