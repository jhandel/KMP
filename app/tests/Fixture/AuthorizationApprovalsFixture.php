<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * AuthorizationApprovalsFixture
 */
class AuthorizationApprovalsFixture extends TestFixture
{
    /**
     * Init method
     *
     * @return void
     */
    public function init(): void
    {
        $this->records = [
            [
                "id" => 1,
                "authorization_id" => 1,
                "approver_id" => 1,
                "authorization_token" => "Lorem ipsum dolor sit amet",
                "requested_on" => "2024-05-21",
                "responded_on" => "2024-05-21",
                "approved" => 1,
                "approver_notes" => "Lorem ipsum dolor sit amet",
            ],
        ];
        parent::init();
    }
}
