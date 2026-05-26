<?php

namespace Espo\Modules\Xero\Hooks\Invoice;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\Xero\Services\XeroService;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

use Throwable;

/**
 * @implements AfterSave<\Espo\Modules\QuickBooks\Entities\Invoice>
 */
class XeroSync implements AfterSave
{
    public static int $order = 21;

    public function __construct(
        private InjectableFactory $injectableFactory,
        private Log $log
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get('skipXeroSync')) {
            return;
        }

        $service = $this->injectableFactory->create(XeroService::class);

        if ($entity->get('status') === 'Voided') {
            if (!$entity->get('xeroInvoiceId')) {
                return;
            }

            try {
                $service->voidInvoice($entity);
            } catch (Throwable $e) {
                $this->log->warning(
                    "Xero Invoice void failed for '{$entity->getId()}': " . $e->getMessage()
                );
            }

            return;
        }

        try {
            $service->upsertInvoice($entity);
        } catch (Throwable $e) {
            $this->log->warning(
                "Xero Invoice sync failed for '{$entity->getId()}': " . $e->getMessage()
            );
        }
    }
}
