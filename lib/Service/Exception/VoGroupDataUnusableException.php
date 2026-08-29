<?php
declare(strict_types=1);

namespace OCA\UserVO\Service\Exception;

/**
 * Thrown when VO answered a group-members request (HTTP 200) but with a
 * payload that isn't a real member list - an error envelope scoped to that
 * one filtered request, not evidence VO itself is unreachable. Deliberately
 * never counted by this app's circuit breakers: a group that permanently
 * produces this response must never be mistaken for a VO-wide outage and
 * abort an otherwise-healthy batch sync.
 */
class VoGroupDataUnusableException extends \Exception {
}
