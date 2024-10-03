<?php
namespace BtIpay\Opencart\Card;

use BtIpay\Opencart\Language;
use ModelExtensionPaymentBtIpay;
use BtIpay\Opencart\Sdk\DetailResponse;
use BtIpay\Opencart\Card\Encrypt;

class ReturnHandler
{
    /** @var \ModelExtensionPaymentBtIpay */
    protected $paymentModel;

    protected Language $languageService;
    public function __construct(
        $paymentModel,
        Language $languageService
    ) {
        $this->paymentModel = $paymentModel;
        $this->languageService = $languageService;
    }

    public function handle(DetailResponse $response)
    {
        if ($response->isSuccess() && $response->canSaveCard()) {
            if ($this->cardExists($response)) {
                return ["error" => true, "message" => "card_already_saved"];
            }
            $this->updateCardData($response);
            return ["error" => false, "message" => "card_successfully_saved"];
        } else {
            return ["error" => true, "message" => $this->languageService->get("could_not_save_card", [$response->getCustomerError()])];
        }
    }

    private function updateCardData(DetailResponse $response)
    {
        $cardInfo = $response->getCardInfo();
        if (is_array($cardInfo)) {
            $this->paymentModel->createCard(Encrypt::encryptCard($cardInfo));
        }
    }

    private function cardExists(DetailResponse $response)
    {
        $cardInfo = $response->getCardInfo();
        return is_array($cardInfo) &&
            isset($cardInfo['ipay_id']) &&
            $this->paymentModel->cardExists($cardInfo['ipay_id']);
    }
}