<?php

declare(strict_types=1);

namespace OfficerEventReporting\Test\TestCase\Model\Table;

use Cake\TestSuite\TestCase;
use OfficerEventReporting\Model\Table\FormsTable;

/**
 * OfficerEventReporting\Model\Table\FormsTable Test Case
 */
class FormsTableTest extends TestCase
{
    /**
     * Test subject
     *
     * @var \OfficerEventReporting\Model\Table\FormsTable
     */
    protected $Forms;

    /**
     * Fixtures
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.OfficerEventReporting.Forms',
        'plugin.OfficerEventReporting.FormFields',
        'plugin.OfficerEventReporting.Submissions',
        'plugin.OfficerEventReporting.SubmissionValues',
    ];

    /**
     * setUp method
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('OfficerEventReporting.Forms') ? [] : ['className' => FormsTable::class];
        $this->Forms = $this->getTableLocator()->get('OfficerEventReporting.Forms', $config);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    public function tearDown(): void
    {
        unset($this->Forms);

        parent::tearDown();
    }

    /**
     * Test validationDefault method
     *
     * @return void
     */
    public function testValidationDefault(): void
    {
        $validator = $this->Forms->validationDefault($this->Forms->getValidator());
        
        $this->assertTrue($validator->hasField('title'));
        $this->assertTrue($validator->hasField('form_type'));
        $this->assertTrue($validator->hasField('status'));
        $this->assertTrue($validator->hasField('assignment_type'));
    }

    /**
     * Test basic form creation
     *
     * @return void
     */
    public function testCreateBasicForm(): void
    {
        $formData = [
            'title' => 'Test Event Report',
            'description' => 'A test form for events',
            'form_type' => 'event',
            'status' => 'active',
            'assignment_type' => 'open',
            'created_by' => 1,
        ];

        $form = $this->Forms->newEntity($formData);
        $this->assertEmpty($form->getErrors());
        
        $savedForm = $this->Forms->save($form);
        $this->assertNotFalse($savedForm);
        $this->assertEquals('Test Event Report', $savedForm->title);
        $this->assertEquals('event', $savedForm->form_type);
    }

    /**
     * Test form with invalid data
     *
     * @return void
     */
    public function testCreateFormWithInvalidData(): void
    {
        $formData = [
            'title' => '', // Required field empty
            'form_type' => 'invalid-type', // Invalid type
            'status' => 'invalid-status', // Invalid status
            'assignment_type' => 'invalid-assignment', // Invalid assignment
        ];

        $form = $this->Forms->newEntity($formData);
        $this->assertNotEmpty($form->getErrors());
        
        $this->assertArrayHasKey('title', $form->getErrors());
        $this->assertArrayHasKey('form_type', $form->getErrors());
        $this->assertArrayHasKey('status', $form->getErrors());
        $this->assertArrayHasKey('assignment_type', $form->getErrors());
    }
}