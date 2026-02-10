<?php

declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\TestCase\Support\HttpIntegrationTestCase;

/**
 * WorkflowsController authorization integration tests.
 *
 * @uses \App\Controller\WorkflowsController
 */
class WorkflowsControllerTest extends HttpIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
    }

    // =====================================================
    // Unauthenticated → redirect
    // =====================================================

    public function testUnauthenticatedIndexRedirects(): void
    {
        $this->get('/workflows');
        $this->assertRedirectContains('/login');
    }

    public function testUnauthenticatedDesignerRedirects(): void
    {
        $this->get('/workflows/designer');
        $this->assertRedirectContains('/login');
    }

    public function testUnauthenticatedSaveRedirects(): void
    {
        $this->post('/workflows/save', []);
        $this->assertRedirectContains('/login');
    }

    public function testUnauthenticatedPublishRedirects(): void
    {
        $this->post('/workflows/publish', []);
        $this->assertRedirectContains('/login');
    }

    public function testUnauthenticatedAddRedirects(): void
    {
        $this->get('/workflows/add');
        $this->assertRedirectContains('/login');
    }

    public function testUnauthenticatedInstancesRedirects(): void
    {
        $this->get('/workflows/instances');
        $this->assertRedirectContains('/login');
    }

    public function testUnauthenticatedApprovalsRedirects(): void
    {
        $this->get('/workflows/approvals');
        $this->assertRedirectContains('/login');
    }

    public function testUnauthenticatedVersionsRedirects(): void
    {
        $this->get('/workflows/versions/1');
        $this->assertRedirectContains('/login');
    }

    public function testUnauthenticatedToggleActiveRedirects(): void
    {
        $this->post('/workflows/toggle-active/1');
        $this->assertRedirectContains('/login');
    }

    public function testUnauthenticatedCreateDraftRedirects(): void
    {
        $this->post('/workflows/create-draft', []);
        $this->assertRedirectContains('/login');
    }

    // =====================================================
    // Super user can access admin actions
    // =====================================================

    public function testSuperUserCanAccessIndex(): void
    {
        $this->authenticateAsSuperUser();
        $this->get('/workflows');
        $this->assertResponseOk();
    }

    public function testSuperUserCanAccessDesigner(): void
    {
        $this->authenticateAsSuperUser();
        $this->get('/workflows/designer');
        $this->assertResponseOk();
    }

    public function testSuperUserCanAccessAdd(): void
    {
        $this->authenticateAsSuperUser();
        $this->get('/workflows/add');
        $this->assertResponseOk();
    }

    public function testSuperUserCanAccessInstances(): void
    {
        $this->authenticateAsSuperUser();
        $this->get('/workflows/instances');
        $this->assertResponseOk();
    }

    public function testSuperUserCanAccessApprovals(): void
    {
        $this->authenticateAsSuperUser();
        $this->get('/workflows/approvals');
        $this->assertResponseOk();
    }

    // =====================================================
    // Non-super user blocked from admin actions
    // =====================================================

    public function testNonSuperUserBlockedFromIndex(): void
    {
        $this->authenticateAsMember(self::TEST_MEMBER_AGATHA_ID);
        $this->get('/workflows');
        $this->assertRedirect();
    }

    public function testNonSuperUserBlockedFromDesigner(): void
    {
        $this->authenticateAsMember(self::TEST_MEMBER_AGATHA_ID);
        $this->get('/workflows/designer');
        $this->assertRedirect();
    }

    public function testNonSuperUserBlockedFromAdd(): void
    {
        $this->authenticateAsMember(self::TEST_MEMBER_AGATHA_ID);
        $this->get('/workflows/add');
        $this->assertRedirect();
    }

    public function testNonSuperUserBlockedFromInstances(): void
    {
        $this->authenticateAsMember(self::TEST_MEMBER_AGATHA_ID);
        $this->get('/workflows/instances');
        $this->assertRedirect();
    }

    public function testNonSuperUserBlockedFromSave(): void
    {
        $this->authenticateAsMember(self::TEST_MEMBER_AGATHA_ID);
        $this->post('/workflows/save', ['name' => 'Test']);
        $this->assertRedirect();
    }

    public function testNonSuperUserBlockedFromPublish(): void
    {
        $this->authenticateAsMember(self::TEST_MEMBER_AGATHA_ID);
        $this->post('/workflows/publish', ['versionId' => 1]);
        $this->assertRedirect();
    }

    public function testNonSuperUserBlockedFromToggleActive(): void
    {
        $this->authenticateAsMember(self::TEST_MEMBER_AGATHA_ID);
        $this->post('/workflows/toggle-active/1');
        $this->assertRedirect();
    }

    public function testNonSuperUserBlockedFromCreateDraft(): void
    {
        $this->authenticateAsMember(self::TEST_MEMBER_AGATHA_ID);
        $this->post('/workflows/create-draft', ['workflowId' => 1]);
        $this->assertRedirect();
    }

    // =====================================================
    // Authenticated users CAN access approvals
    // =====================================================

    public function testAuthenticatedUserCanAccessApprovals(): void
    {
        $this->authenticateAsMember(self::TEST_MEMBER_AGATHA_ID);
        $this->get('/workflows/approvals');
        $this->assertResponseOk();
    }
}
