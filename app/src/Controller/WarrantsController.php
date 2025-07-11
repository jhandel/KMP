<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\Warrant;
use App\Services\CsvExportService;
use App\Services\WarrantManager\WarrantManagerInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\I18n\DateTime;

/**
 * Warrants Controller
 *
 * @property \App\Model\Table\WarrantsTable $Warrants
 * @property \Authorization\Controller\Component\AuthorizationComponent $Authorization
 */
class WarrantsController extends AppController
{
    /**
     * @var \App\Services\CsvExportService
     */
    public static array $inject = [CsvExportService::class];
    protected CsvExportService $csvExportService;

    /**
     * Initialize controller
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Authorization.Authorization');

        $this->Authorization->authorizeModel('index');
    }

    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index() {}

    public function allWarrants(CsvExportService $csvExportService, $state)
    {
        if ($state != 'current' && $state == 'pending' && $state == 'previous') {
            throw new NotFoundException();
        }
        $securityWarrant = $this->Warrants->newEmptyEntity();
        $this->Authorization->authorize($securityWarrant);
        $warrantsQuery = $this->Warrants->find()
            ->contain(['Members', 'WarrantRosters', 'MemberRoles']);

        $today = new DateTime();
        switch ($state) {
            case 'current':
                $warrantsQuery = $warrantsQuery->where(['Warrants.expires_on >=' => $today, 'Warrants.start_on <=' => $today, 'Warrants.status' => Warrant::CURRENT_STATUS]);
                break;
            case 'upcoming':
                $warrantsQuery = $warrantsQuery->where(['Warrants.start_on >' => $today, 'Warrants.status' => Warrant::CURRENT_STATUS]);
                break;
            case 'pending':
                $warrantsQuery = $warrantsQuery->where(['Warrants.status' => Warrant::PENDING_STATUS]);
                break;
            case 'previous':
                $warrantsQuery = $warrantsQuery->where(['OR' => ['Warrants.expires_on <' => $today, 'Warrants.status IN ' => [Warrant::DEACTIVATED_STATUS, Warrant::EXPIRED_STATUS]]]);
                break;
        }
        $warrantsQuery = $this->addConditions($warrantsQuery);

        // CSV export for all warrants in the filtered set
        if ($this->isCsvRequest()) {
            return $csvExportService->outputCsv(
                $warrantsQuery->order(['Members.sca_name' => 'asc']),
                'warrants.csv',
            );
        }

        $warrants = $this->paginate($warrantsQuery);
        $this->set(compact('warrants', 'state'));
    }

    protected function addConditions($query)
    {
        return $query
            ->select(['id', 'name', 'member_id', 'entity_type', 'start_on', 'expires_on', 'revoker_id', 'warrant_roster_id', 'status', 'revoked_reason'])
            ->contain([
                'Members' => function ($q) {
                    return $q->select(['id', 'sca_name']);
                },
                'RevokedBy' => function ($q) {
                    return $q->select(['id', 'sca_name']);
                },
            ]);
    }

    public function deactivate(WarrantManagerInterface $wService, $id = null)
    {
        $this->request->allowMethod(['post']);
        if (!$id) {
            $id = $this->request->getData('id');
        }
        $warrant = $this->Warrants->find()
            ->where(['Warrants.id' => $id])
            ->contain(['Members'])
            ->first();
        if (!$warrant) {
            throw new NotFoundException(__('The warrant does not exist.'));
        }
        $this->Authorization->authorize($warrant);

        $wResult = $wService->cancel((int)$id, 'Deactivated from Warrant List', $this->Authentication->getIdentity()->get('id'), DateTime::now());
        if (!$wResult->success) {
            $this->Flash->error($wResult->reason);

            return $this->redirect($this->referer());
        }

        $this->Flash->success(__('The warrant has been deactivated.'));

        return $this->redirect($this->referer());
    }
}