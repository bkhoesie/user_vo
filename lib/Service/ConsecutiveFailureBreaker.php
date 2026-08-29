<?php
declare(strict_types=1);

namespace OCA\UserVO\Service;

/**
 * Shared circuit-breaker logic for a batch group-sync loop (the sweep job,
 * syncAllManagedGroups(), the two blocking syncGroupsByIds() callers, and the
 * bulk group-creation loop) - one small class instead of five hand-rolled
 * copies of the same "count consecutive VO-unavailable failures, break at 2,
 * reset on anything else" logic.
 *
 * Only VoApiUnavailableException (a transport/HTTP-level failure - VO
 * plausibly unreachable) should ever be recorded via recordVoUnavailable().
 * VoGroupDataUnusableException (a per-group data problem) and any unrelated
 * failure must go through reset() instead, same as a success - a group that
 * permanently produces a malformed response must never be able to trip "stop
 * the whole batch", or the same starvation this class exists to prevent
 * recurs regardless of the threshold.
 *
 * A fresh instance belongs to one batch run - not shared across runs or
 * injected as a service.
 */
class ConsecutiveFailureBreaker {
    private const THRESHOLD = 2;

    private int $consecutiveVoUnavailable = 0;

    /** @return bool True if the caller should stop its batch loop now. */
    public function recordVoUnavailable(): bool {
        $this->consecutiveVoUnavailable++;
        return $this->consecutiveVoUnavailable >= self::THRESHOLD;
    }

    /** Call on a success, or any failure unrelated to VO reachability - resets the counter. */
    public function reset(): void {
        $this->consecutiveVoUnavailable = 0;
    }
}
