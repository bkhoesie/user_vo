<?php
declare(strict_types=1);

namespace OCA\UserVO\Controller;

use OCA\UserVO\Service\UserAccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for user account management operations
 *
 * Handles:
 * - Duplicate user detection and management
 * - User account exposure/hiding (visibility control)
 */
class UserAccountController extends Controller {
	private UserAccountService $userAccountService;
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		UserAccountService $userAccountService,
		LoggerInterface $logger
	) {
		parent::__construct($appName, $request);
		$this->userAccountService = $userAccountService;
		$this->logger = $logger;
	}

	/**
	 * Scan for duplicate user accounts
	 *
	 * Analyzes all plugin-managed users and groups them by normalized username
	 * to identify duplicates (case-insensitive duplicates).
	 *
	 * @NoCSRFRequired
	 * @return JSONResponse
	 */
	public function scanDuplicates(): JSONResponse {
		try {
			$result = $this->userAccountService->scanDuplicates();
			return new JSONResponse($result);
		} catch (\Exception $e) {
			$this->logger->error('Error in scanDuplicates: ' . $e->getMessage(), ['app' => 'user_vo']);
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()]);
		}
	}

	/**
	 * Expose a user account
	 *
	 * Makes a hidden user visible by adding them to the user_vo table
	 * with a !duplicate marker. This allows duplicate users to be managed
	 * separately.
	 *
	 * @return JSONResponse
	 */
	public function exposeUser(): JSONResponse {
		$data = $this->request->getParams();
		$uid = $data['uid'] ?? null;

		if (!$uid) {
			return new JSONResponse(['success' => false, 'error' => 'No uid provided']);
		}

		$result = $this->userAccountService->exposeUser($uid);
		return new JSONResponse($result);
	}

	/**
	 * Hide a user account
	 *
	 * Makes a user hidden by removing them from the user_vo table
	 * (unless they are the canonical user for that normalized username).
	 * Hidden users cannot log in.
	 *
	 * @return JSONResponse
	 */
	public function hideUser(): JSONResponse {
		$data = $this->request->getParams();
		$uid = $data['uid'] ?? null;

		if (!$uid) {
			return new JSONResponse(['success' => false, 'error' => 'No uid provided']);
		}

		$result = $this->userAccountService->hideUser($uid);
		return new JSONResponse($result);
	}
}
