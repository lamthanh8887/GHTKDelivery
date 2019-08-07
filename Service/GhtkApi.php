<?php 

namespace Plugin\GHTKDelivery\Service;

use GuzzleHttp\Client;
use Plugin\GHTKDelivery\Repository\ConfigRepository;

class GhtkApi {
    
    CONST API_URL_GHTK = 'https://services.giaohangtietkiem.vn';
    CONST API_URL_GHTK_SANDBOX = 'https://dev.ghtk.vn';

	/**
	* @var $configRepo /
	*/
	protected $configRepo;

	/**
	* @var $config /
	*/
	protected $config;

    /**
     * @var string
     */
	protected $apiUrl;

    /**
     * @var Client
     */
	protected $client;

	/**
	* GhtkApi Constructor
	* @param ConfigRepository $configRepo
	*/
	public function __construct(ConfigRepository $configRepo)
	{
		$this->config = $configRepo->get();
		$this->apiUrl = self::API_URL_GHTK;
		if ( $this->config->getIsSandbox() )
		{
			$this->apiUrl = self::API_URL_GHTK_SANDBOX;
		}
		$this->client = new Client([
			 'headers' => [
                'Content-Type' => 'application/pdf',
                'Token' => $this->config->getToken(), 
            ]
		]);
    }
    /**
     * Estimate shipping fee
     *
     * @param [type] $pick_province
     * @param [type] $pick_district
     * @param [type] $province
     * @param [type] $district
     * @param [type] $address
     * @param [type] $weight
     * @return string
     */
    public function shipmentFee($pick_province, $pick_district, $province, $district, $address, $weight)
    {
        $response = $this->client->get($this->apiUrl . '/services/shipment/fee',  [
            'query' => [
                "pick_province" => $pick_province,
                "pick_district" => $pick_district,
                "province" => $province,
                "district" => $district,
                "address" => $address,
                "weight" => $weight
            ]
        ]);
        $result = json_decode($response->getBody()->getContents());
        return $result;
    }

    /**
     * S1.A1.17373471 : GHTK order id
     * eccube order id (optional) : /services/shipment/v2/partner_id:1234567
     *
     * @param $label
     * @return mixed
     */
    public function shipmentStatus($label)
    {
        $response = $this->client->get($this->apiUrl . '/services/shipment/v2/'. $label);
        $result = json_decode($response->getBody()->getContents());
        return $result;
    }

    /**
     * use ghtk order id : /services/shipment/cancel/S1.17373471
     * use eccube id : /services/shipment/cancel/partner_id:1234567
     *
     * @param $label
     * @return mixed
     */
    public function shipmentCancel($label)
    {
        $response = $this->client->post($this->apiUrl . '/services/shipment/cancel/' . $label);
        $result = json_decode($response->getBody()->getContents());
        return $result;
    }

    /**
     * @param $data
     * @return mixed
     */
    public function createShipment($data)
    {
        $body = ['form_params' => $data];
        $response = $this->client->post($this->apiUrl . '/services/shipment/order/?ver=1.5', $body);
        $result = $response->getBody()->getContents();
        $d = json_decode($result);
        return $d;
    }

    /**
     * @param $trackingId
     * @return string
     */
    public function getInvoicePdf($trackingId)
    {
        $response = $this->client->get($this->apiUrl . '/services/label/' . $trackingId);
        return $response->getBody()->getContents();
    }
}