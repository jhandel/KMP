<?php

declare(strict_types=1);

namespace OfficerEventReporting\Controller;

use Cake\ORM\TableRegistry;
use Cake\I18n\DateTime;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Exception\ForbiddenException;

/**
 * Submissions Controller
 *
 * @property \OfficerEventReporting\Model\Table\SubmissionsTable $Submissions
 */
class SubmissionsController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->Authorization->authorizeModel("index", "add");
    }

    /**
     * Index method - List submissions for current user or all if officer
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $user = $this->Authentication->getIdentity();
        $userId = $user->getIdentifier();

        // Check if user can view all submissions (officer role)
        $canViewAll = $this->Authorization->can($this->Submissions->newEmptyEntity(), 'viewAll');

        if ($canViewAll) {
            // Officers can see all submissions
            $query = $this->Submissions->find()
                ->contain(['Forms', 'SubmittedBy', 'Reviewer'])
                ->order(['Submissions.created' => 'DESC']);
        } else {
            // Members can only see their own submissions
            $query = $this->Submissions->find('forUser', ['user_id' => $userId])
                ->order(['Submissions.created' => 'DESC']);
        }

        $submissions = $this->paginate($query);

        $this->set(compact('submissions', 'canViewAll'));
    }

    /**
     * View method
     *
     * @param string|null $id Submission id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $submission = $this->Submissions->get($id, contain: [
            'Forms' => [
                'FormFields' => function ($q) {
                    return $q->order(['sort_order' => 'ASC']);
                }
            ],
            'SubmittedBy',
            'Reviewer',
            'SubmissionValues' => [
                'FormFields'
            ]
        ]);

        $this->Authorization->authorize($submission);

        $this->set(compact('submission'));
    }

    /**
     * Add method - Submit a new form
     *
     * @param string|null $formId Form id.
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function add($formId = null)
    {
        if (!$formId) {
            throw new NotFoundException(__('Form not found.'));
        }

        $formsTable = TableRegistry::getTableLocator()->get('OfficerEventReporting.Forms');
        $form = $formsTable->get($formId, contain: [
            'FormFields' => function ($q) {
                return $q->order(['sort_order' => 'ASC']);
            }
        ]);

        // Check if form is available to current user
        $user = $this->Authentication->getIdentity();
        $userId = $user->getIdentifier();
        
        // Get user's offices for office-specific forms
        $userOffices = []; // TODO: Implement getting user's offices from warrants/roles
        
        if (!$form->isAvailableToUser($userId, $userOffices)) {
            throw new ForbiddenException(__('You are not authorized to submit this form.'));
        }

        $submission = $this->Submissions->newEmptyEntity();
        $submission->form_id = $formId;
        $submission->submitted_by = $userId;

        $this->Authorization->authorize($submission);

        if ($this->request->is('post')) {
            $submissionData = $this->request->getData();
            $submissionData['form_id'] = $formId;
            $submissionData['submitted_by'] = $userId;
            $submissionData['status'] = 'submitted';

            $submission = $this->Submissions->patchEntity($submission, $submissionData);

            // Process submission values
            $submissionValues = [];
            foreach ($form->form_fields as $field) {
                $fieldName = $field->field_name;
                $fieldValue = $this->request->getData($fieldName);

                // Validate required fields
                if ($field->is_required && empty($fieldValue)) {
                    $this->Flash->error(__('Field "{0}" is required.', $field->field_label));
                    $this->set(compact('submission', 'form'));
                    return;
                }

                // Handle file uploads
                if ($field->field_type === 'file' && !empty($fieldValue) && is_array($fieldValue)) {
                    // TODO: Implement file upload handling
                    $fieldValue = $fieldValue['name'] ?? '';
                }

                $submissionValues[] = [
                    'form_field_id' => $field->id,
                    'field_value' => $fieldValue,
                ];
            }

            $submission->submission_values = $this->Submissions->SubmissionValues->newEntities($submissionValues);

            if ($this->Submissions->save($submission)) {
                $this->Flash->success(__('Your submission has been saved.'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The submission could not be saved. Please, try again.'));
        }

        $this->set(compact('submission', 'form'));
    }

    /**
     * Review method - For officers to review submissions
     *
     * @param string|null $id Submission id.
     * @return \Cake\Http\Response|null|void Redirects on successful review, renders view otherwise.
     */
    public function review($id = null)
    {
        $submission = $this->Submissions->get($id, contain: [
            'Forms' => [
                'FormFields' => function ($q) {
                    return $q->order(['sort_order' => 'ASC']);
                }
            ],
            'SubmittedBy',
            'SubmissionValues' => [
                'FormFields'
            ]
        ]);

        $this->Authorization->authorize($submission, 'review');

        if ($this->request->is(['patch', 'post', 'put'])) {
            $user = $this->Authentication->getIdentity();
            $reviewData = $this->request->getData();
            $reviewData['reviewer_id'] = $user->getIdentifier();
            $reviewData['reviewed_at'] = new DateTime();

            $submission = $this->Submissions->patchEntity($submission, $reviewData);

            if ($this->Submissions->save($submission)) {
                $this->Flash->success(__('The submission has been reviewed.'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The submission could not be reviewed. Please, try again.'));
        }

        $statusOptions = [
            'reviewed' => 'Reviewed',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        ];

        $this->set(compact('submission', 'statusOptions'));
    }
}