<?php

declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\TestCase\Support\HttpIntegrationTestCase;

/**
 * Unified Approvals route integration tests.
 *
 * Covers the /approvals scope and legacy redirect routes
 * defined in config/routes.php.
 *
 * @uses \App\Controller\ApprovalsController
 */
class UnifiedApprovalsControllerTest extends HttpIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfPostgres();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
    }

    // =====================================================
    // Unauthenticated → redirect to login
    // =====================================================

    public function testUnauthenticatedApprovalsRedirects(): void
    {
        $this->get('/approvals');
        $this->assertRedirectContains('/login');
    }

    public function testUnauthenticatedGridDataRedirects(): void
    {
        $this->get('/approvals/grid-data');
        $this->assertRedirectContains('/login');
    }

    public function testUnauthenticatedRespondRedirects(): void
    {
        $this->get('/approvals/respond/some-token');
        $this->assertRedirectContains('/login');
    }

    // =====================================================
    // Authenticated access
    // =====================================================

    public function testAuthenticatedApprovalsReturnsOk(): void
    {
        $this->authenticateAsSuperUser();
        $this->get('/approvals');
        $this->assertResponseOk();
    }

    public function testAuthenticatedNonSuperUserCanAccessApprovals(): void
    {
        $this->authenticateAsMember(self::TEST_MEMBER_AGATHA_ID);
        $this->get('/approvals');
        $this->assertResponseOk();
    }

    public function testAuthenticatedGridDataReturnsOk(): void
    {
        $this->authenticateAsSuperUser();
        $this->get('/approvals/grid-data');
        $this->assertResponseOk();
    }

    // =====================================================
    // Token deep-link handling
    // =====================================================

    public function testTokenDeepLinkWithInvalidToken(): void
    {
        $this->authenticateAsSuperUser();
        $this->get('/approvals/respond/invalid-token');
        // Invalid token should not cause a 500; expect redirect or 4xx
        $code = $this->_response->getStatusCode();
        $this->assertTrue(
            $code >= 200 && $code < 500,
            "Expected a non-server-error response for invalid token, got {$code}",
        );
    }

    // =====================================================
    // Legacy redirect: /authorization-approvals/my-queue
    // =====================================================

    public function testOldMyQueueRedirects(): void
    {
        $this->get('/authorization-approvals/my-queue');
        $this->assertRedirectContains('/approvals');
        $this->assertResponseCode(302);
    }

    public function testOldMyQueueWithIdRedirects(): void
    {
        $this->get('/authorization-approvals/my-queue/123');
        $this->assertRedirectContains('/approvals');
        $this->assertResponseCode(302);
    }

    // =====================================================
    // Workflow-scoped approval routes still work
    // =====================================================

    public function testWorkflowApprovalsRouteStillWorks(): void
    {
        $this->authenticateAsSuperUser();
        $this->get('/workflows/approvals');
        $this->assertResponseOk();
    }
}
