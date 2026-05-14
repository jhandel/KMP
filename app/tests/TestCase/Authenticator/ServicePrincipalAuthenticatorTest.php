<?php
declare(strict_types=1);

namespace App\Test\TestCase\Authenticator;

use App\Authenticator\ServicePrincipalAuthenticator;
use Authentication\Authenticator\Result;
use Authentication\Identifier\IdentifierInterface;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;

class ServicePrincipalAuthenticatorTest extends TestCase
{
    public function testAuthenticateRequiresTenantContextBeforeTokenLookup(): void
    {
        $identifier = $this->createMock(IdentifierInterface::class);
        $identifier->expects($this->never())->method('identify');
        $authenticator = new ServicePrincipalAuthenticator($identifier);

        $result = $authenticator->authenticate(
            (new ServerRequest(['url' => '/api/v1/members']))
                ->withHeader('Authorization', 'Bearer test-token'),
        );

        $this->assertSame(Result::FAILURE_OTHER, $result->getStatus());
        $this->assertSame(['Tenant context is required for API authentication'], $result->getErrors());
    }
}
