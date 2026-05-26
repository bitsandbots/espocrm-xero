<?php

namespace Espo\Modules\Xero\Hooks\Account;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\Xero\Services\XeroService;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

use Throwable;

/**
 * @implements AfterSave<\Espo\Modules\Crm\Entities\Account>
 */
class XeroSync implements AfterSave
{
    public static int $order = 20;

    public function __construct(
        private InjectableFactory $injectableFactory,
        private Log $log
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get('skipXeroSync')) {
            return;
        }

        try {
            $service = $this->injectableFactory->create(XeroService::class);
            $service->upsertContact('Account', $entity);
        } catch (Throwable $e) {
            $this->log->warning("Xero Account sync failed for '{$entity->getId()}': " . $e->getMessage());
        }
    }
}
