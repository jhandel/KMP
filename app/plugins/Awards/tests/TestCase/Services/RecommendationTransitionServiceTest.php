<?php
declare(strict_types=1);

namespace Awards\Test\TestCase\Services;

use App\Test\TestCase\BaseTestCase;
use Awards\Model\Entity\Recommendation;
use Awards\Services\RecommendationGroupingService;
use Awards\Services\RecommendationTransitionService;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use DateTimeZone;

class RecommendationTransitionServiceTest extends BaseTestCase
{
    private Table $recommendationsTable;
    private RecommendationTransitionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfPostgres();

        Recommendation::clearCache();
        $this->recommendationsTable = $this->getTableLocator()->get('Awards.Recommendations');
        $this->service = new RecommendationTransitionService();
    }

    protected function tearDown(): void
    {
        Recommendation::clearCache();
        parent::tearDown();
    }

    public function testTransitionAppliesSingleRecommendationSemantics(): void
    {
        $recommendationId = $this->createRecommendation('Submitted');
        $gatheringId = $this->getFirstGatheringId();
        $before = $this->recommendationsTable->get($recommendationId);

        $result = $this->service->transition(
            $this->recommendationsTable,
            $recommendationId,
            [
                'targetState' => 'Given',
                'gathering_id' => (string)$gatheringId,
                'given' => '2026-04-01',
                'close_reason' => 'Ignore this override',
                'note' => 'Presented in court',
            ],
            self::ADMIN_MEMBER_ID,
        );

        $this->assertTrue($result['success']);
        $this->assertSame($recommendationId, $result['data']['recommendationId']);
        $this->assertSame('Submitted', $result['data']['result']['previousState']);
        $this->assertSame('Given', $result['data']['result']['newState']);
        $this->assertSame('Closed', $result['data']['result']['newStatus']);
        $this->assertSame('Given', $result['data']['result']['appliedSetRules']['close_reason']);

        $updated = $this->recommendationsTable->get($recommendationId);
        $this->assertSame('Given', $updated->state);
        $this->assertSame('Closed', $updated->status);
        $this->assertSame($gatheringId, $updated->gathering_id);
        $this->assertNotNull($updated->state_date);
        $this->assertNotSame(
            $before->state_date?->format(DATE_ATOM),
            $updated->state_date?->format(DATE_ATOM),
        );
        $this->assertSame('Given', $updated->close_reason);
        $this->assertNotNull($updated->given);
        $this->assertSame('UTC', $updated->given?->getTimezone()->getName());
        $this->assertSame(
            '2026-04-01 00:00:00',
            $updated->given?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );

        $note = $this->recommendationsTable->Notes->find()
            ->where([
                'entity_id' => $recommendationId,
                'entity_type' => 'Awards.Recommendations',
                'subject' => 'Recommendation Updated',
            ])
            ->orderBy(['id' => 'DESC'])
            ->first();
        $this->assertNotNull($note);
        $this->assertSame('Presented in court', $note->body);
        $this->assertSame(self::ADMIN_MEMBER_ID, $note->author_id);

        $latestLog = $this->getLatestStateLog($recommendationId);
        $this->assertNotNull($latestLog);
        $this->assertSame('Submitted', $latestLog->from_state);
        $this->assertSame('Given', $latestLog->to_state);
        $this->assertSame('In Progress', $latestLog->from_status);
        $this->assertSame('Closed', $latestLog->to_status);
        $this->assertSame(self::ADMIN_MEMBER_ID, $latestLog->created_by);

        $this->assertSame('Given', $result['data']['result']['changes']['close_reason']['after']);
        $this->assertSame((string)$gatheringId, (string)$result['data']['result']['changes']['gathering_id']['after']);
    }

    public function testTransitionManyClearsGatheringAndCreatesBulkNotes(): void
    {
        $gatheringId = $this->getFirstGatheringId();
        $firstId = $this->createRecommendation('Scheduled', ['gathering_id' => $gatheringId]);
        $secondId = $this->createRecommendation('Scheduled', ['gathering_id' => $gatheringId]);

        $result = $this->service->transitionMany(
            $this->recommendationsTable,
            [
                'ids' => [(string)$firstId, (string)$secondId],
                'newState' => 'No Action',
                'close_reason' => 'Already recognized elsewhere',
                'note' => 'Bulk closure note',
            ],
            self::ADMIN_MEMBER_ID,
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['processedCount']);
        $this->assertSame('No Action', $result['data']['targetState']);
        $this->assertCount(2, $result['data']['results']);

        foreach ([$firstId, $secondId] as $recommendationId) {
            $updated = $this->recommendationsTable->get($recommendationId);
            $this->assertSame('No Action', $updated->state);
            $this->assertSame('Closed', $updated->status);
            $this->assertNull($updated->gathering_id);
            $this->assertSame('Already recognized elsewhere', $updated->close_reason);

            $note = $this->recommendationsTable->Notes->find()
                ->where([
                    'entity_id' => $recommendationId,
                    'entity_type' => 'Awards.Recommendations',
                    'subject' => 'Recommendation Bulk Updated',
                ])
                ->orderBy(['id' => 'DESC'])
                ->first();
            $this->assertNotNull($note);
            $this->assertSame('Bulk closure note', $note->body);
            $this->assertSame(self::ADMIN_MEMBER_ID, $note->author_id);

            $latestLog = $this->getLatestStateLog($recommendationId);
            $this->assertNotNull($latestLog);
            $this->assertSame('Scheduled', $latestLog->from_state);
            $this->assertSame('No Action', $latestLog->to_state);
            $this->assertSame('To Give', $latestLog->from_status);
            $this->assertSame('Closed', $latestLog->to_status);
            $this->assertSame(self::ADMIN_MEMBER_ID, $latestLog->created_by);
        }

        $firstResult = $result['data']['results'][0];
        $this->assertSame('Already recognized elsewhere', $firstResult['closeReason']);
        $this->assertNull($firstResult['gatheringId']);
        $this->assertTrue($firstResult['noteCreated']);
        $this->assertArrayHasKey('gathering_id', $firstResult['changes']);
    }

    public function testTransitionManyPreservesOptionalFieldsWhenNullValuesArePassed(): void
    {
        $gatheringId = $this->getFirstGatheringId();
        $existingGiven = new DateTime('2025-02-02 00:00:00', new DateTimeZone('UTC'));
        $recommendationId = $this->createRecommendation('Scheduled', [
            'gathering_id' => $gatheringId,
            'given' => $existingGiven,
        ]);

        $result = $this->service->transitionMany(
            $this->recommendationsTable,
            [
                'ids' => [(string)$recommendationId],
                'newState' => 'Given',
                'gathering_id' => null,
                'given' => null,
                'close_reason' => null,
            ],
            self::ADMIN_MEMBER_ID,
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['processedCount']);

        $updated = $this->recommendationsTable->get($recommendationId);
        $this->assertSame('Given', $updated->state);
        $this->assertSame('Closed', $updated->status);
        $this->assertSame($gatheringId, $updated->gathering_id);
        $this->assertNotNull($updated->given);
        $this->assertSame(
            '2025-02-02 00:00:00',
            $updated->given?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
        $this->assertSame('Given', $updated->close_reason);

        $this->assertRecordCount('Notes', 0, [
            'entity_id' => $recommendationId,
            'entity_type' => 'Awards.Recommendations',
        ]);

        $transitionResult = $result['data']['results'][0];
        $this->assertFalse($transitionResult['noteCreated']);
        $this->assertSame('Given', $transitionResult['closeReason']);
        $this->assertArrayNotHasKey('gathering_id', $transitionResult['changes']);
        $this->assertArrayNotHasKey('given', $transitionResult['changes']);
    }

    public function testTransitionSyncsLinkedChildrenWhenGroupHeadCloses(): void
    {
        $headState = $this->stateForStatus('In Progress', ['Linked']);
        $childOriginState = $this->differentNonLinkedState($headState);
        $headId = $this->createRecommendation($headState);
        $childId = $this->createRecommendation($childOriginState);

        $groupingService = new RecommendationGroupingService($this->recommendationsTable);
        $groupingService->groupRecommendations([$headId, $childId], self::ADMIN_MEMBER_ID);

        $closedState = $this->stateForStatus('Closed', ['Linked - Closed']);
        $result = $this->service->transition(
            $this->recommendationsTable,
            $headId,
            ['targetState' => $closedState],
            self::ADMIN_MEMBER_ID,
        );

        $this->assertTrue($result['success']);

        $freshChild = $this->recommendationsTable->get($childId);
        $this->assertSame('Linked - Closed', $freshChild->state);
        $this->assertSame('Closed', $freshChild->status);

        $latestLog = $this->getLatestStateLog($childId);
        $this->assertNotNull($latestLog);
        $this->assertSame('Linked', $latestLog->from_state);
        $this->assertSame('Linked - Closed', $latestLog->to_state);
        $this->assertSame(self::ADMIN_MEMBER_ID, $latestLog->created_by);
    }

    private function createRecommendation(string $state, array $overrides = []): int
    {
        $entity = $this->recommendationsTable->newEntity([
            'member_id' => self::ADMIN_MEMBER_ID,
            'requester_id' => self::ADMIN_MEMBER_ID,
            'award_id' => $this->getFirstAwardId(),
            'reason' => 'Test recommendation transition',
            'requester_sca_name' => 'Admin von Admin',
            'member_sca_name' => 'Admin von Admin',
            'contact_email' => 'admin@test.com',
            'status' => 'In Progress',
            'state' => $state,
            'state_date' => new DateTime('2024-01-01 00:00:00'),
            'call_into_court' => 'Not Set',
            'court_availability' => 'Not Set',
            'person_to_notify' => '',
            'branch_id' => self::KINGDOM_BRANCH_ID,
        ]);

        foreach ($overrides as $field => $value) {
            $entity->set($field, $value);
        }

        $saved = $this->recommendationsTable->saveOrFail($entity);

        return (int)$saved->id;
    }

    private function getFirstAwardId(): int
    {
        $award = $this->getTableLocator()->get('Awards.Awards')
            ->find()
            ->select(['id'])
            ->first();

        $this->assertNotNull($award, 'Expected seeded awards data for transition tests.');

        return (int)$award->id;
    }

    private function getFirstGatheringId(): int
    {
        $gathering = $this->getTableLocator()->get('Gatherings')
            ->find()
            ->select(['id'])
            ->first();

        $this->assertNotNull($gathering, 'Expected seeded gatherings data for transition tests.');

        return (int)$gathering->id;
    }

    private function getLatestStateLog(int $recommendationId): mixed
    {
        return $this->getTableLocator()->get('Awards.RecommendationsStatesLogs')
            ->find()
            ->where(['recommendation_id' => $recommendationId])
            ->orderBy(['id' => 'DESC'])
            ->first();
    }

    private function stateForStatus(string $status, array $exclude = []): string
    {
        $states = Recommendation::getStatuses()[$status] ?? [];
        foreach ($states as $state) {
            if (!in_array($state, $exclude, true)) {
                return $state;
            }
        }

        $this->markTestSkipped("No usable {$status} state available");
    }

    private function differentNonLinkedState(string $excludeState): string
    {
        foreach (Recommendation::getStates() as $state) {
            if (!in_array($state, ['Linked', 'Linked - Closed', $excludeState], true)) {
                return $state;
            }
        }

        $this->markTestSkipped('Need a non-linked state for grouping tests');
    }
}
