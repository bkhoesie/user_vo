<?php
declare(strict_types=1);

namespace OCA\UserVO\Controller;

use OCA\UserVO\Service\AuditLogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for the admin-facing audit log view (see AuditLogService for
 * what gets logged and why). Mostly read-only - the only mutation is the
 * explicit clear() action; entries otherwise only expire via
 * AuditLogCleanupJob's own retention policy.
 */
class AuditLogController extends Controller {
	private AuditLogService $auditLogService;

	public function __construct(
		string $appName,
		IRequest $request,
		AuditLogService $auditLogService
	) {
		parent::__construct($appName, $request);
		$this->auditLogService = $auditLogService;
	}

	/**
	 * Fetch recent audit log entries for display in the admin UI
	 *
	 * @NoCSRFRequired
	 * @return JSONResponse
	 */
	public function fetchRecent(): JSONResponse {
		return new JSONResponse([
			'success' => true,
			'entries' => $this->auditLogService->getRecentEntries(500),
		]);
	}

	/**
	 * Download the full audit log as a plain-text file
	 *
	 * @NoCSRFRequired
	 * @return DataDownloadResponse
	 */
	public function download(): DataDownloadResponse {
		$text = $this->auditLogService->getAllEntriesAsText();
		$filename = 'user_vo_audit_log_' . date('Y-m-d_His') . '.txt';

		return new DataDownloadResponse($text, $filename, 'text/plain');
	}

	/**
	 * Clear the entire audit log - an explicit, irreversible admin action
	 *
	 * @return JSONResponse
	 */
	public function clear(): JSONResponse {
		$deleted = $this->auditLogService->clearAll();

		return new JSONResponse([
			'success' => true,
			'deleted' => $deleted,
		]);
	}
}
