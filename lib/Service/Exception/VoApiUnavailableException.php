<?php
declare(strict_types=1);

namespace OCA\UserVO\Service\Exception;

/**
 * Thrown when a VO API call failed at the transport/HTTP level (network
 * error, auth failure, non-200 status, non-array JSON) - genuinely transient
 * and plausibly VO-wide. The only failure this app's circuit breakers treat
 * as a signal that VO itself may be unreachable; see VoGroupDataUnusableException
 * for the per-group counterpart that must never trip a breaker.
 */
class VoApiUnavailableException extends \Exception {
}
