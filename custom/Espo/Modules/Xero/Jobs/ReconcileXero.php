<?php

namespace Espo\Modules\Xero\Jobs;

use Espo\Core\InjectableFactory;
use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\Modules\Xero\Services\XeroService;
use Espo\ORM\EntityManager;

use Throwable;

/**
 * Nightly bidirectional reconciliation.
 * Runs after SyncFromXero has pulled latest Xero state.
 * Pushes any EspoCRM-side changes that Xero doesn't yet have.
 *
 * Batch size is conservative (25) to stay within Xero's 60 req/min limit.
 */
class ReconcileXero implements JobDataLess
{
    private const BATCH_SIZE = 25;

    public function __construct(
        private InjectableFactory $injectableFactory,
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function run(): void
    {
        /** @var ?Integration $integration */
        $integration = $this->entityManager->getEntityById(Integration::ENTITY_TYPE, 'Xero');

        if (!$integration || !$integration->isEnabled()) {
            return;
        }

        $service = $this->injectableFactory->create(XeroService::class);

        $errors = array_merge(
            $this->reconcileAccounts($service),
            $this->reconcileInvoices($service)
        );

        $integration->set('lastSyncError', empty($errors) ? null : implode('; ', $errors));
        $this->entityManager->saveEntity($integration);

        $this->log->info("Xero ReconcileXero completed.");
    }

    /**
     * @return list<string>
     */
    private function reconcileAccounts(XeroService $service): array
    {
        $errors = [];

        // Push accounts that never reached Xero (initial sync failed).
        $unsynced = $this->entityManager
            ->getRDBRepository('Account')
            ->where(['xeroContactId=' => null])
            ->limit(0, self::BATCH_SIZE)
            ->find();

        foreach ($unsynced as $account) {
            try {
                $service->upsertContact('Account', $account);
            } catch (Throwable $e) {
                $this->log->warning(
                    "Xero reconcile Account '{$account->getId()}': " . $e->getMessage()
                );
                $errors[] = "Account {$account->getId()}: " . $e->getMessage();
            }
        }

        // Re-push accounts modified since their last successful sync.
        $collection = $this->entityManager
            ->getRDBRepository('Account')
            ->where(['xeroContactId!=' => null])
            ->limit(0, self::BATCH_SIZE)
            ->find();

        foreach ($collection as $account) {
            $syncedAt = $account->get('xeroSyncedAt');
            $modifiedAt = $account->get('modifiedAt');

            if (!$syncedAt || !$modifiedAt) {
                continue;
            }

            if (strtotime($modifiedAt) <= strtotime($syncedAt)) {
                continue;
            }

            try {
                $service->upsertContact('Account', $account);
            } catch (Throwable $e) {
                $this->log->warning(
                    "Xero reconcile Account '{$account->getId()}': " . $e->getMessage()
                );
                $errors[] = "Account {$account->getId()}: " . $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function reconcileInvoices(XeroService $service): array
    {
        $collection = $this->entityManager
            ->getRDBRepository('Invoice')
            ->where([
                'xeroInvoiceId!=' => null,
                'status!=' => 'Paid',
                'status!=' => 'Voided',
            ])
            ->limit(0, self::BATCH_SIZE)
            ->find();

        $errors = [];

        foreach ($collection as $invoice) {
            $syncedAt = $invoice->get('xeroSyncedAt');
            $modifiedAt = $invoice->get('modifiedAt');

            if (!$syncedAt || !$modifiedAt) {
                continue;
            }

            if (strtotime($modifiedAt) <= strtotime($syncedAt)) {
                continue;
            }

            try {
                $service->upsertInvoice($invoice);
            } catch (Throwable $e) {
                $this->log->warning(
                    "Xero reconcile Invoice '{$invoice->getId()}': " . $e->getMessage()
                );
                $errors[] = "Invoice {$invoice->getId()}: " . $e->getMessage();
            }
        }

        return $errors;
    }
}
