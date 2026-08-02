<?php
declare(strict_types=1);

namespace OCA\UserVO\Service\Exception;

/**
 * Thrown when a blocking group sync can't acquire the group's sync lease
 * within its bounded wait because another sync is still holding it - as
 * opposed to any other sync failure. Lets callers react differently (e.g.
 * retry once) instead of treating transient lock contention the same as a
 * real error.
 */
class GroupSyncLockContentionException extends \Exception {
}
