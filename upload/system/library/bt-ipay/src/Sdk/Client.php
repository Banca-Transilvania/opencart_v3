<?php
namespace BtIpay\Opencart\Sdk;

use BtIpay\Opencart\Sdk\Config;
use BTransilvania\Api\IPayClient;
use BtIpay\Opencart\Payment\Payload;
use BtIpay\Opencart\Payment\Response;
use BtIpay\Opencart\Sdk\DetailResponse;

class Client
{
    private Config $config;
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Start payment
     *
     * @param Payload $payload
     * @param boolean $authorize
     *
     * @return Response
     */
    public function startPayment(Payload $payload, bool $authorize = false): Response
    {
        if ($authorize) {
            return new Response(
                $this->getClient()->registerPreAuth($payload->toArray()),
            );
        }

        return new Response(
            $this->getClient()->register($payload->toArray()),
        );
    }

    /**
     * Capture payment
     *
     * @param string $ipayId
     * @param integer $amount
     *
     * @return Response
     */
    public function capture(string $ipayId, int $amount): Response
    {
        return new Response(
            $this->getClient()->deposit(["orderId" => $ipayId, "amount" => $amount]),
        );
    }


    /**
     * Cancel payment
     *
     * @param string $ipayId
     *
     * @return Response
     */
    public function cancel(string $ipayId): Response
    {
        return new Response(
            $this->getClient()->reverse(["orderId" => $ipayId]),
        );
    }


    /**
     * Refund payment
     *
     * @param string $ipayId
     * @param integer $amount
     *
     * @return Response
     */
    public function refund(string $ipayId, int $amount): Response
    {
        return new Response(
            $this->getClient()->refund(["orderId" => $ipayId, "amount" => $amount]),
        );
    }

    /**
     * Enable or disable card
     *
     * @param string $ipayCardId
     * @param boolean $enable
     *
     * @return Response
     */
    public function toggleCardStatus(string $ipayCardId, bool $enable): Response
    {
        $client = $this->getClient();
        if ($enable) {
            return new Response($client->bindCard(["bindingId" => $ipayCardId]));
        }
        return new Response($client->unBindCard(["bindingId" => $ipayCardId]));
    }

    public function verifyCard(array $data): Response
    {
        return new Response(
            $this->getClient()->registerPreAuth($data),
        );
    }

    /**
     * Get payment details
     *
     * @param string $ipayId
     *
     * @return DetailResponse
     */
    public function getPayment(string $ipayId): DetailResponse
    {
        return new DetailResponse(
            $this->getClient()->getOrderStatusExtended(["orderId" => $ipayId])
        );
    }


    /**
     * Get sdk client
     *
     * @return IPayClient
     */
    private function getClient(): IPayClient
    {
        return new IPayClient($this->config->getSdkAuth());
    }
}