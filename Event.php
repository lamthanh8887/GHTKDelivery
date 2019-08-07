<?php

namespace Plugin\GHTKDelivery;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Eccube\Event\EccubeEvents;
use Eccube\Common\EccubeConfig;
use Eccube\Entity\Order;
use Eccube\Entity\Master\Pref;
use Eccube\Event\TemplateEvent;
use GuzzleHttp\Client;
use Eccube\Entity\BaseInfo;
use Eccube\Event\EventArgs;
use Eccube\Repository\BaseInfoRepository;
use Plugin\GHTKDelivery\Service\PurchaseFlow\GHTKPreprocessor;
use Plugin\GHTKDelivery\Repository\ConfigRepository;
use Plugin\GHTKDelivery\Service\GhtkApi;
use Eccube\Repository\Master\PrefRepository;
use Doctrine\ORM\EntityManager;
use Eccube\Repository\Master\OrderStatusRepository;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class Event implements EventSubscriberInterface
{

    const SERVICE_NAME = 'GHTK';
    const STATUS_REMOVED_ORDER = '3';

    /**
    * @var ConfigRepository
    */
    protected $configRepo;

     /**
    * @var GhtkApi
    */
    protected $service;

    /**
    * @var BaseInfoRepository
    */
    protected $baseInfo;

    /**
    * @var PrefRepository
    */
    protected $prefRepo;

    /**
    * @var EccubeConfig
    */
    protected $eccubeConfig;

    /**
    * @var EntityManager
    */
    protected $entityManager;

    /**
    * @var OrderStatusRepository
    */
    protected $orderStatusRepo;

    /**
     * @var Session
     */
    protected $session;

    public function __construct(ConfigRepository $configRepo, 
        GhtkApi $service, 
        BaseInfoRepository $baseInfoRepository,
        PrefRepository $prefRepo,
        EccubeConfig $eccubeConfig,
        EntityManager $entityManager,
        OrderStatusRepository $orderStatusRepo,
        Session $session)
    {
        $this->configRepo = $configRepo;
        $this->service = $service;
        $this->baseInfo = $baseInfoRepository->get();
        $this->prefRepo = $prefRepo;
        $this->eccubeConfig = $eccubeConfig;
        $this->entityManager = $entityManager;
        $this->orderStatusRepo = $orderStatusRepo;
        $this->session = $session;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
        	'@admin/Setting/Shop/delivery_edit.twig' => 'deliveryEdit',
            '@admin/Order/edit.twig' => 'downloadGhtkOrder',
            '@admin/Product/product.twig' => 'productEditView',
            'Shopping/index.twig' => 'shoppingEdit',
            EccubeEvents::ADMIN_PRODUCT_EDIT_INITIALIZE =>  'productEditEvent', 
            EccubeEvents::FRONT_SHOPPING_COMPLETE_INITIALIZE => 'createGhtkOrder',
            EccubeEvents::ADMIN_ORDER_EDIT_INDEX_PROGRESS => 'orderEdit',
        ];
    }

    /**
     * EccubeEvents::ADMIN_PRODUCT_EDIT_INITIALIZE
     *
     * @param EventArgs $event
     */
    public function productEditEvent(EventArgs $event)
    {
        $arguments = $event->getArguments();
        $arguments['builder']->add('weight', TextType::class, [
            'constraints' => [
                new NotBlank(),
            ],
        ]);
    }

    /**
     * Event: @admin/Product/product.twig
     *
     * @param TemplateEvent $event
     */
    public function productEditView(TemplateEvent $event)
    {
        $event->addSnippet('@GHTKDelivery/admin/product_edit.twig');
    }

    /**
     * Event: @admin/Setting/Shop/delivery_edit.twig
     *
     * @param TemplateEvent $event
     */
    public function deliveryEdit(TemplateEvent $event)
    {
        $config = $this->configRepo->get();
        $parameters = $event->getParameters();
        $deliveryId = $parameters['delivery_id'];
        if ( $deliveryId == $config->getDeliveryId() ) {
            $event->addSnippet('@GHTKDelivery/admin/delivery_edit.twig');
        }
    }

    /**
     * Event: Shopping/index.twig
     *
     * @param TemplateEvent $event
     */
    public function shoppingEdit(TemplateEvent $event)
    {
        $parameters = $event->getParameters();
        $order = $parameters['Order'];
        $shippings = $order->getShippings();
        $shopname = $this->baseInfo->getShopName();
        $isExistGHTK = false ;
        foreach ( $shippings as  $shipping ) {
            if($shipping->getDelivery()->getServiceName() == self::SERVICE_NAME){
                $isExistGHTK = true ;
            }
        }
        if((empty($this->baseInfo->getPref()) || empty($this->baseInfo->getAddr01()) ) && ($isExistGHTK == true) ){
            $event->setParameter('message', trans('ghtk.event.error_shopping_edit', ['%shopname%' => $shopname]));
            $event->addSnippet('@GHTKDelivery/shopping/shopping_edit.twig' );
        }
    }

    /**
     * EccubeEvents::FRONT_SHOPPING_COMPLETE_INITIALIZE
     * @param EventArgs $event
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function createGhtkOrder(EventArgs $event)
    {
        $config = $this->configRepo->get();
        $arguments = $event->getArguments();
        $order = $arguments['Order'];
        $shippings = $order->getShippings();
        $childShippingIDs = [];
        foreach ( $shippings as $index => $shipping ) {
            if ( $shipping->getDelivery()->getId() != $config->getDeliveryId() ) {
                continue;
            }
            $data = [
                'products' => [],
                'order' => []
            ];
            foreach ($shipping->getOrderItems() as $orderItem) {
                if ( $orderItem->isProduct() )
                {
                    $product['name'] = $orderItem->getProductName();
                    $product['weight'] = $orderItem->getProduct()->getWeight();
                    $product['quantity'] = $orderItem->getQuantity();
                    $data['products'][] = $product;
                }

            }
            if($index == count($shippings) - 1){
                $order_data['is_freeship'] = 1;
                $order_data['pick_money'] = $order->getPaymentTotal();
                $order_data['pick_option'] = 'cod';
                if (count($childShippingIDs) >0) {
                    $order_data['note'] = 'Giao cùng: ' . implode(',', $childShippingIDs);
                }
            }else{
                $order_data['is_freeship'] = 1;
                $order_data['pick_money'] = 0;
                $order_data['pick_option'] = 'post'	;               
            }
            $order_data['id'] = $shipping->getId();
            $order_data['pick_name'] = $this->baseInfo->getShopName();
            $order_data['pick_address'] = $this->baseInfo->getAddr02();
            $order_data['pick_province'] = $this->getProvince($this->baseInfo->getPref());
            $order_data['pick_district'] = $this->baseInfo->getAddr01();
            $order_data['pick_tel'] = $this->baseInfo->getPhoneNumber();
            $order_data['name'] =  $order->getName02() . ' ' . $order->getName01();
            $order_data['address'] = $shipping->getAddr02();
            $order_data['province'] = $this->getProvince($shipping->getPref());
            $order_data['district'] = $shipping->getAddr01();
            $order_data['tel'] = $shipping->getPhoneNumber();
            $order_data['email'] = $order->getEmail();
            $order_data['weight_option'] = 'gram';
            $data['order'] = $order_data;
            $serviceCreateOrder = $this->service->createShipment($data);
            if (empty($serviceCreateOrder->order)) {
                $this->session->getFlashBag()->add('eccube.front.error', trans('ghtk.event.error_create_order'));
                return;
            }
            $created_order = $serviceCreateOrder->order;
            $shipping->setTrackingNumber($created_order->label);
            if($index != count($shippings)-1){
                array_push($childShippingIDs,$created_order->label);
            }
            $this->entityManager->flush($shipping);
        }
        $event->setArguments($arguments);
    }

    /**
     * Event: @admin/Order/edit.twig
     *
     * @param TemplateEvent $event
     */
    public function downloadGhtkOrder(TemplateEvent $event)
    {
        $config = $this->configRepo->get();
        $parameters = $event->getParameters();
        $order = $parameters['Order'];
        if ($order->getId()) {
            foreach($order->getShippings() as $shipping) {
                if ($shipping->getDelivery()->getId() == $config->getDeliveryId()) {
                    $ghtkStatus = $this->service->shipmentStatus($shipping->getTrackingNumber());
                    if ( $ghtkStatus->success ) {
                        $shipping->setGhtkStatus($ghtkStatus->order->status_text);
                        $event->setParameters($parameters);
                    }
                    $event->addSnippet('@GHTKDelivery/admin/order_edit.twig');
                }
            }
        }
    }

    /**
     * @param Pref $pref
     * @return mixed
     */
    public function getProvince(Pref $pref)
    {
        $pref = $this->prefRepo->findOneBy(['id' => $pref->getId()]);
        return $pref->getName();
    }

    /**
     * EccubeEvents::ADMIN_ORDER_EDIT_INDEX_PROGRESS
     * @param EventArgs $event
     */
    public function orderEdit(EventArgs $event)
    {
        $args = $event->getArguments();
        $target_order = $args['TargetOrder'];
        $shippings = $target_order->getShippings();
        $config = $this->configRepo->get();
        foreach ( $shippings as $shipping )
        {
            $delivery = $shipping->getDelivery();
            if ($delivery->getId() == $config->getDeliveryId()) {
                $order_status = $target_order->getOrderStatus();
                if ($order_status->getId() == self::STATUS_REMOVED_ORDER) {
                    $serviceCancel = $this->service->shipmentCancel($shipping->getTrackingNumber());
                    if ( $serviceCancel->success ) {
                        $this->session->getFlashBag()->add('eccube.admin.success', trans('ghtk.event.remove_order_success'));
                    }else{
                        $this->session->getFlashBag()->add('eccube.admin.error', $serviceCancel->message);
                    }
                } 
            }
        }
    }
}