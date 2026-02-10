<?php
declare(strict_types=1);

namespace App\Queue\Task;

use App\Model\Entity\WorkflowApproval;
use App\Services\WorkflowEngine\WorkflowEngineInterface;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Queue\Queue\Task;
use Queue\Queue\ServicesTrait;

/**
 * Checks for expired workflow approvals and resumes their workflows.
 *
 * Finds all PENDING approvals past their deadline, marks them EXPIRED,
 * and resumes the workflow on the 'expired' output port.
 *
 * Schedule via cron: bin/cake queue add WorkflowApprovalDeadline
 */
class WorkflowApprovalDeadlineTask extends Task {

	use ServicesTrait;

	/**
	 * Timeout for run, after which the Task is reassigned to a new worker.
	 */
	public ?int $timeout = 300;

	/**
	 * Prevent parallel execution of this task.
	 */
	public bool $unique = true;

	/**
	 * Scan for expired approvals and resume their workflows.
	 *
	 * @param array<string, mixed> $data Unused for scheduled runs
	 * @param int $jobId The id of the QueuedJob entity
	 *
	 * @return void
	 */
	public function run(array $data, int $jobId): void {
		$approvalsTable = $this->getTableLocator()->get('WorkflowApprovals');

		$expiredApprovals = $approvalsTable->find()
			->where([
				'status' => WorkflowApproval::STATUS_PENDING,
				'deadline IS NOT' => null,
				'deadline <' => DateTime::now(),
			])
			->all();

		$count = $expiredApprovals->count();
		if ($count === 0) {
			Log::info('WorkflowApprovalDeadlineTask: No expired approvals found');

			return;
		}

		Log::info("WorkflowApprovalDeadlineTask: Found {$count} expired approval(s)");

		$engine = $this->getService(WorkflowEngineInterface::class);
		$processed = 0;
		$errors = 0;

		foreach ($expiredApprovals as $approval) {
			try {
				// Mark approval as expired
				$approval->status = WorkflowApproval::STATUS_EXPIRED;
				if (!$approvalsTable->save($approval)) {
					Log::error("WorkflowApprovalDeadlineTask: Failed to save expired status for approval {$approval->id}");
					$errors++;

					continue;
				}

				// Resume the workflow on the expired output port
				$result = $engine->resumeWorkflow(
					$approval->workflow_instance_id,
					$approval->node_id,
					'expired',
					['expiredApprovalId' => $approval->id],
				);

				if ($result->isSuccess()) {
					$processed++;
					Log::info("WorkflowApprovalDeadlineTask: Expired approval {$approval->id}, resumed instance {$approval->workflow_instance_id}");
				} else {
					$errors++;
					Log::error("WorkflowApprovalDeadlineTask: Failed to resume instance {$approval->workflow_instance_id}: " . $result->getError());
				}
			} catch (\Throwable $e) {
				$errors++;
				Log::error("WorkflowApprovalDeadlineTask: Exception for approval {$approval->id}: " . $e->getMessage());
			}
		}

		Log::info("WorkflowApprovalDeadlineTask: Processed {$processed}, errors {$errors}");
	}

}
