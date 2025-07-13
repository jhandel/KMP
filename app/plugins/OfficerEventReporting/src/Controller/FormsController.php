<?php

declare(strict_types=1);

namespace OfficerEventReporting\Controller;

use Cake\ORM\TableRegistry;
use Cake\I18n\DateTime;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Exception\ForbiddenException;

/**
 * Forms Controller
 *
 * @property \OfficerEventReporting\Model\Table\FormsTable $Forms
 */
class FormsController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->Authorization->authorizeModel("index", "add");
    }

    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $query = $this->Forms->find()
            ->contain(['CreatedBy', 'ModifiedBy'])
            ->order(['Forms.created' => 'DESC']);

        $forms = $this->paginate($query);

        $this->set(compact('forms'));
    }

    /**
     * View method
     *
     * @param string|null $id Form id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $form = $this->Forms->get($id, contain: [
            'FormFields' => function ($q) {
                return $q->order(['sort_order' => 'ASC']);
            },
            'CreatedBy',
            'ModifiedBy',
        ]);

        $this->Authorization->authorize($form);

        $this->set(compact('form'));
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        $form = $this->Forms->newEmptyEntity();
        $this->Authorization->authorize($form);

        if ($this->request->is('post')) {
            $form = $this->Forms->patchEntity($form, $this->request->getData());
            
            // Process form fields
            if (!empty($this->request->getData('form_fields'))) {
                $fieldsData = $this->request->getData('form_fields');
                $formFields = [];
                
                foreach ($fieldsData as $index => $fieldData) {
                    if (!empty($fieldData['field_name']) && !empty($fieldData['field_label'])) {
                        $fieldData['sort_order'] = $index + 1;
                        
                        // Process field options for select/radio fields
                        if (in_array($fieldData['field_type'], ['select', 'radio', 'checkbox']) && !empty($fieldData['options_text'])) {
                            $options = array_filter(array_map('trim', explode("\n", $fieldData['options_text'])));
                            $fieldData['field_options'] = json_encode(['choices' => $options]);
                        }
                        
                        unset($fieldData['options_text']); // Remove temporary field
                        $formFields[] = $fieldData;
                    }
                }
                
                $form->form_fields = $this->Forms->FormFields->newEntities($formFields);
            }

            if ($this->Forms->save($form)) {
                $this->Flash->success(__('The form has been saved.'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The form could not be saved. Please, try again.'));
        }

        $formTypes = [
            'ad-hoc' => 'Ad-hoc Report',
            'event' => 'Event Report',
            'injury' => 'Injury Report',
            'equipment-failure' => 'Equipment Failure Report',
        ];

        $assignmentTypes = [
            'open' => 'Open to all members',
            'assigned' => 'Assigned to specific members',
            'office-specific' => 'Assigned to specific offices',
        ];

        $fieldTypes = [
            'text' => 'Text',
            'textarea' => 'Textarea',
            'select' => 'Select Dropdown',
            'radio' => 'Radio Buttons',
            'checkbox' => 'Checkbox',
            'date' => 'Date',
            'datetime' => 'Date & Time',
            'file' => 'File Upload',
            'email' => 'Email',
            'number' => 'Number',
        ];

        $this->set(compact('form', 'formTypes', 'assignmentTypes', 'fieldTypes'));
    }

    /**
     * Edit method
     *
     * @param string|null $id Form id.
     * @return \Cake\Http\Response|null|void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null)
    {
        $form = $this->Forms->get($id, contain: [
            'FormFields' => function ($q) {
                return $q->order(['sort_order' => 'ASC']);
            }
        ]);

        $this->Authorization->authorize($form);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $form = $this->Forms->patchEntity($form, $this->request->getData());
            
            // Process form fields
            if (!empty($this->request->getData('form_fields'))) {
                $fieldsData = $this->request->getData('form_fields');
                $formFields = [];
                
                foreach ($fieldsData as $index => $fieldData) {
                    if (!empty($fieldData['field_name']) && !empty($fieldData['field_label'])) {
                        $fieldData['sort_order'] = $index + 1;
                        
                        // Process field options for select/radio fields
                        if (in_array($fieldData['field_type'], ['select', 'radio', 'checkbox']) && !empty($fieldData['options_text'])) {
                            $options = array_filter(array_map('trim', explode("\n", $fieldData['options_text'])));
                            $fieldData['field_options'] = json_encode(['choices' => $options]);
                        }
                        
                        unset($fieldData['options_text']); // Remove temporary field
                        $formFields[] = $fieldData;
                    }
                }
                
                $form->form_fields = $this->Forms->FormFields->newEntities($formFields);
            }

            if ($this->Forms->save($form)) {
                $this->Flash->success(__('The form has been saved.'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The form could not be saved. Please, try again.'));
        }

        $formTypes = [
            'ad-hoc' => 'Ad-hoc Report',
            'event' => 'Event Report',
            'injury' => 'Injury Report',
            'equipment-failure' => 'Equipment Failure Report',
        ];

        $assignmentTypes = [
            'open' => 'Open to all members',
            'assigned' => 'Assigned to specific members',
            'office-specific' => 'Assigned to specific offices',
        ];

        $fieldTypes = [
            'text' => 'Text',
            'textarea' => 'Textarea',
            'select' => 'Select Dropdown',
            'radio' => 'Radio Buttons',
            'checkbox' => 'Checkbox',
            'date' => 'Date',
            'datetime' => 'Date & Time',
            'file' => 'File Upload',
            'email' => 'Email',
            'number' => 'Number',
        ];

        $this->set(compact('form', 'formTypes', 'assignmentTypes', 'fieldTypes'));
    }

    /**
     * Delete method
     *
     * @param string|null $id Form id.
     * @return \Cake\Http\Response|null Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $form = $this->Forms->get($id);
        $this->Authorization->authorize($form);

        // Check if form has submissions
        if ($this->Forms->Submissions->exists(['form_id' => $id])) {
            $this->Flash->error(__('Cannot delete form with existing submissions.'));
            return $this->redirect(['action' => 'index']);
        }

        if ($this->Forms->delete($form)) {
            $this->Flash->success(__('The form has been deleted.'));
        } else {
            $this->Flash->error(__('The form could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}