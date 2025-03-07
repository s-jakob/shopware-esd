<?php declare(strict_types=1);

namespace Sas\Esd\Service;

use Sas\Esd\Content\Product\Extension\Esd\Aggregate\EsdMedia\EsdMediaEntity;
use Sas\Esd\Content\Product\Extension\Esd\Aggregate\EsdOrder\EsdOrderEntity;
use Sas\Esd\Content\Product\Extension\Esd\EsdEntity;
use Sas\Esd\Utils\EsdMailTemplate;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class EsdOrderService
{
    /**
     * @var EntityRepositoryInterface
     */
    private $esdOrderRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $esdSerialRepository;

    /**
     * @var EsdService
     */
    private $esdService;

    public function __construct(
        EntityRepositoryInterface $esdOrderRepository,
        EntityRepositoryInterface $esdSerialRepository,
        EsdService $esdService

    ) {
        $this->esdOrderRepository = $esdOrderRepository;
        $this->esdSerialRepository = $esdSerialRepository;
        $this->esdService = $esdService;
    }

    public function addNewEsdOrders(
        OrderEntity $order,
        Context $context,
        ?ProductCollection $products = null
    ): void {
        $newEsdOrders = [];
        foreach ($order->getLineItems() as $orderLineItem) {
            if ($products instanceof ProductCollection) {
                $product = $products->get($orderLineItem->getProductId());
                if (!$product) {
                    continue;
                }

                /** @var EsdEntity $esd */
                $esd = $product->getExtension('esd');
            } else {
                /** @var EsdEntity $esd */
                $esd = $orderLineItem->getProduct()->getExtension('esd');
            }

            if ($esd === null
                || $esd->hasSerial() === false
                && $esd->getEsdMedia() === null
            ) {
                continue;
            }

            $fetchSerials = $this->fetchSerials($esd, $context);
            $fetchSerialIds = [];
            if ($fetchSerials !== null) {
                $fetchSerialIds = $fetchSerials->getIds();
            }

            for ($q = 0; $q < $orderLineItem->getQuantity(); ++$q) {
                $serialId = null;
                if (!empty($fetchSerialIds)) {
                    $serialId = current($fetchSerialIds);
                    unset($fetchSerialIds[$serialId]);
                }

                $newEsdOrders[] = [
                    'id' => Uuid::randomHex(),
                    'esdId' => $esd->getId(),
                    'orderLineItemId' => $orderLineItem->getId(),
                    'serialId' => $serialId,
                ];
            }
        }

        if (!empty($newEsdOrders)) {
            $this->esdOrderRepository->create($newEsdOrders, $context);
        }
    }

    public function mailTemplateData(OrderEntity $order, Context $context): array
    {
        $esdOrderListIds = [];
        $esdOrderLineItems = [];

        $criteria = new Criteria();
        $criteria->addAssociation('orderLineItem');
        $criteria->addAssociation('serial');
        $criteria->addAssociation('esd.esdMedia');
        $criteria->addFilter(
            new EqualsAnyFilter('orderLineItemId', array_values($order->getLineItems()->getIds()))
        );

        $esdOrders = $this->esdOrderRepository->search($criteria, $context);

        $esdByLineItemIds = [];
        $esdIds = [];
        /** @var EsdOrderEntity $esdOrder */
        foreach ($esdOrders->getEntities() as $esdOrder) {
            $esd = $esdOrder->getEsd();
            if ($esd === null || $esd->getEsdMedia() === null) {
                continue;
            }

            $esdOrderLineItems[$esdOrder->getOrderLineItemId()] = $esdOrder->getOrderLineItem();
            $esdByLineItemIds[$esdOrder->getOrderLineItemId()] = $esd;
            $esdIds[] = $esdOrder->getEsdId();
        }

        $templateData['esdMediaFiles'] = [];
        $esdMedias = $this->esdService->getEsdMediaByEsdIds($esdIds, $context);
        foreach ($esdOrders->getEntities() as $esdOrder) {
            if (empty($esdMedias[$esdOrder->getEsdId()])) {
                continue;
            }

            /** @var EsdMediaEntity $esdMedia */
            foreach ($esdMedias[$esdOrder->getEsdId()] as $esdMedia) {
                $templateData['esdMediaFiles'][$esdOrder->getId()][$esdMedia->getId()] = $esdMedia;
            }
        }

        /** @var OrderLineItemEntity $orderLineItem */
        foreach ($order->getLineItems() as $orderLineItem) {
            if (empty($esdByLineItemIds[$orderLineItem->getId()])) {
                continue;
            }

            $esd = $esdByLineItemIds[$orderLineItem->getId()];
            if ($esd === null || $esd->getEsdMedia() === null) {
                continue;
            }

            $esdOrder = $esdOrders->filter(function (EsdOrderEntity $esdOrderEntity) use ($orderLineItem) {
                return $esdOrderEntity->getOrderLineItemId() === $orderLineItem->getId();
            })->first();

            for ($q = 0; $q < $orderLineItem->getQuantity(); ++$q) {
                $esdOrderListIds[$orderLineItem->getId()][] = $esdOrder->getId();
            }
        }

        $templateData['esdOrderLineItems'] = $esdOrderLineItems;
        $templateData['esdOrderListIds'] = $esdOrderListIds;

        if (!$this->esdService->getSystemConfig(EsdMailTemplate::TEMPLATE_DOWNLOAD_DISABLED_ZIP_SYSTEM_CONFIG_NAME)) {
            /** @var OrderLineItemEntity $lineItem */
            foreach ($esdOrderLineItems as $lineItem) {
                $templateData['esdFiles'][$lineItem->getProductId()] = $this->esdService->getFileSize($lineItem->getProductId());
            }
        }

        $serialOfEsdOrders = $esdOrders->filter(function (EsdOrderEntity $esdOrderEntity) {
            return $esdOrderEntity->getSerialId() !== null;
        });

        $templateData['esdSerials'] = [];
        /** @var EsdOrderEntity $serialOfEsdOrder */
        foreach ($serialOfEsdOrders as $serialOfEsdOrder) {
            $templateData['esdSerials'][] = [
                'serial' => $serialOfEsdOrder->getSerial()->getSerial(),
                'productName' => $serialOfEsdOrder->getOrderLineItem()->getLabel(),
            ];
        }

        return $templateData;
    }

    public function fetchSerials(EsdEntity $esd, Context $context): ?EntitySearchResult
    {
        if (!$esd->hasSerial()) {
            return null;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('esdId', $esd->getId()));
        $criteria->addFilter(new EqualsFilter('esdOrder.id', null));

        $esdSerial = $this->esdSerialRepository->search($criteria, $context);
        if ($esdSerial->getTotal() === 0) {
            return null;
        }

        return $esdSerial;
    }

    public function isEsdOrder(OrderEntity $order): bool
    {
        foreach ($order->getLineItems() as $lineItem) {
            /** @var EsdEntity $esd */
            $esd = $lineItem->getProduct()->getExtension('esd');
            if (empty($esd)) {
                continue;
            }

            $esdMedias = $esd->getEsdMedia()->filter(function (EsdMediaEntity $esdMedia) {
                return $esdMedia->getMediaId() !== null;
            });

            if (!empty($esdMedias->getElements())) {
                return true;
            }
        }

        return false;
    }
}
