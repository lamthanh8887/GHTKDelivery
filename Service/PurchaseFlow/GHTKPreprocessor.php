<?php
namespace Plugin\GHTKDelivery\Service\PurchaseFlow;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Annotation\ShoppingFlow;
use Eccube\Entity\ItemHolderInterface;
use Eccube\Entity\Order;
use Plugin\GHTKDelivery\Repository\ConfigRepository;
use Eccube\Entity\OrderItem;
use Eccube\Entity\Master\Pref;
use Eccube\Service\PurchaseFlow\ItemHolderPreprocessor;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Entity\BaseInfo;
use Eccube\Repository\BaseInfoRepository;
use Eccube\Entity\Master\OrderItemType;
use Eccube\Entity\Master\TaxDisplayType;
use Eccube\Entity\Master\TaxType;
use Plugin\GHTKDelivery\Service\GhtkApi;
use Eccube\Repository\Master\PrefRepository;
use Symfony\Component\HttpFoundation\Session\Session;
/**
 * Class GHTKPreprocessor
 * @package Plugin\GHTKDelivery\Service\PurchaseFlow
 *
 * @ShoppingFlow()
 */
class GHTKPreprocessor implements ItemHolderPreprocessor
{
    /** @var BaseInfo */
    protected $baseInfo;

    /** @var EntityManagerInterface */
    protected $entityManager;

    /** @var ConfigRepository */
    protected $configRepo;

     /** @var PrefRepository */
    protected $prefRepo;

     /** @var GhtkApi */
    protected $service;

    /**
     * @var null|\Plugin\GHTKDelivery\Entity\Config
     */
    protected $ghtkConfig;

    /**
     * @var Session
     */
    protected $session;

    /**
     * GHTKPreprocessor constructor.
     * @param BaseInfoRepository $baseInfoRepository
     * @param EntityManagerInterface $entityManager
     * @param ConfigRepository $configRepo
     * @param GhtkApi $service
     * @param Session $session
     * @param PrefRepository $prefRepo
     * @throws \Exception
     */
    public function __construct(
        BaseInfoRepository $baseInfoRepository,
        EntityManagerInterface $entityManager,
        ConfigRepository $configRepo,
        GhtkApi $service,
        Session $session,
        PrefRepository $prefRepo)
    {
        $this->baseInfo = $baseInfoRepository->get();
        $this->entityManager = $entityManager;
        $this->configRepo = $configRepo;
        $this->service = $service;
        $this->prefRepo = $prefRepo;
        $this->session = $session;
        $this->ghtkConfig = $this->configRepo->get();
    }

    /**
     * @param ItemHolderInterface $itemHolder
     * @param PurchaseContext $context
     * @throws \Doctrine\ORM\NoResultException
     */
    public function process(ItemHolderInterface $itemHolder, PurchaseContext $context)
    {
        $this->removeDeliveryFeeItem($itemHolder);
        $this->saveDeliveryFeeItem($itemHolder);
    }

    /**
     * @param ItemHolderInterface $itemHolder
     */
    private function removeDeliveryFeeItem(ItemHolderInterface $itemHolder)
    {
        foreach ($itemHolder->getShippings() as $Shipping) {
            foreach ($Shipping->getOrderItems() as $item) {
                if ($item->getProcessorName() == self::class) {
                    $Shipping->removeOrderItem($item);
                    $itemHolder->removeOrderItem($item);
                    $this->entityManager->remove($item);
                }
            }
        }
    }

    /**
     * @param ItemHolderInterface $itemHolder
     *
     * @throws \Doctrine\ORM\NoResultException
     */
    private function saveDeliveryFeeItem(ItemHolderInterface $itemHolder)
    {
        $DeliveryFeeType = $this->entityManager->find(OrderItemType::class, OrderItemType::DELIVERY_FEE);
        $TaxInclude = $this->entityManager->find(TaxDisplayType::class, TaxDisplayType::INCLUDED);
        $Taxation = $this->entityManager->find(TaxType::class, TaxType::TAXATION);
        $Order = $itemHolder;
        foreach ($Order->getShippings() as $shipping) {
            $deliveryFeeProduct = 0;
            if ($this->baseInfo->isOptionProductDeliveryFee()) {
                foreach ($shipping->getOrderItems() as $item) {
                    if (!$item->isProduct()) {
                        continue;
                    }
                    $deliveryFeeProduct += $item->getProductClass()->getDeliveryFee() * $item->getQuantity();
                }
            }

            if ($shipping->getDelivery()->getId() == $this->ghtkConfig->getDeliveryId()) {
                if (empty($Order->getPref()) || empty($this->baseInfo->getPref())) {
                    return;
                }

                //Giao Hang Tiet Kiem shipmentFee params
                $province = $this->getProvince($shipping->getPref());
                $district = $shipping->getAddr01();
                $pick_province = $this->getProvince($this->baseInfo->getPref());
                $pick_district = $this->baseInfo->getAddr01();
                $address = $shipping->getAddr02();
                $weight = 0;
                foreach($shipping->getOrderItems() as $item) {
                    if ($item->isProduct()) {
                        $weight += $item->getProduct()->getWeight();
                    }
                }
                $weight = $weight < 300 ? 300 : $weight;

                $serviceFee = $this->service->shipmentFee($pick_province, $pick_district, $province, $district, $address, $weight);
                if($serviceFee->success != false && (strpos($serviceFee->message, 'pick_district') === false) || strpos($serviceFee->message, 'pick_province' === false)){
                    $deliveryFee = $serviceFee->fee;
                    $DeliveryFee = $deliveryFee->fee;
                    $OrderItem = new OrderItem();
                    $OrderItem->setProductName($DeliveryFeeType->getName())
                        ->setPrice($DeliveryFee + $deliveryFeeProduct)
                        ->setQuantity(1) // 1 package
                        ->setOrderItemType($DeliveryFeeType)
                        ->setShipping($shipping)
                        ->setOrder($itemHolder)
                        ->setTaxDisplayType($TaxInclude)
                        ->setTaxType($Taxation)
                        ->setProcessorName(self::class);
                    $itemHolder->addItem($OrderItem);
                    $shipping->addOrderItem($OrderItem);
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

}