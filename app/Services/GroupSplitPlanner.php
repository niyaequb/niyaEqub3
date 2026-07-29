<?php

namespace App\Services;

/**
 * Turns "we are 7 in this group" into a plan of winner-group sizes.
 *
 *   7 members, sizes between 2 and 5  ->  [3, 4]  or  [2, 5]  or  [2, 2, 3] …
 *
 * The plan is drawn once and frozen on the group when it starts, so every
 * member can see up-front how many people win in each round.
 */
class GroupSplitPlanner
{
    /**
     * @param  int       $total   Number of participating members.
     * @param  int       $min     Smallest allowed winner group.
     * @param  int       $max     Largest allowed winner group.
     * @param  int|null  $rounds  Force an exact number of rounds (optional).
     * @return int[]     e.g. [3, 4]
     */
    public function plan(int $total, int $min = 1, int $max = 1, ?int $rounds = null): array
    {
        if ($total <= 0) {
            return [];
        }

        $min = max(1, min($min, $total));
        $max = max($min, min($max, $total));

        return $rounds !== null && $rounds > 0
            ? $this->planWithFixedRounds($total, $min, $max, $rounds)
            : $this->planFreeform($total, $min, $max);
    }

    /**
     * A plan of exactly N winners every round (last round takes the remainder).
     *
     * @return int[]
     */
    public function fixedSizePlan(int $total, int $winnersPerRound): array
    {
        $winnersPerRound = max(1, $winnersPerRound);
        $plan = [];

        for ($left = $total; $left > 0; $left -= $winnersPerRound) {
            $plan[] = min($winnersPerRound, $left);
        }

        return $plan;
    }

    /** One winner per round — the classic Equb. */
    public function singlePlan(int $total): array
    {
        return array_fill(0, max(0, $total), 1);
    }

    /**
     * Split $total into exactly $rounds parts, each within [$min, $max].
     *
     * @return int[]
     */
    protected function planWithFixedRounds(int $total, int $min, int $max, int $rounds): array
    {
        // Infeasible request: fall back to the freeform planner rather than
        // throwing, so a mis-configured group can still run its draws.
        if ($rounds * $min > $total || $rounds * $max < $total) {
            return $this->planFreeform($total, $min, $max);
        }

        $parts = array_fill(0, $rounds, $min);
        $remaining = $total - ($rounds * $min);

        while ($remaining > 0) {
            $i = random_int(0, $rounds - 1);

            if ($parts[$i] < $max) {
                $parts[$i]++;
                $remaining--;
            }
        }

        shuffle($parts);

        return $parts;
    }

    /**
     * Keep taking a random slice within [$min, $max], only choosing sizes that
     * leave a remainder which can itself still be split legally. Without that
     * look-ahead, 12 members split 3–4 at a time can dead-end on a leftover of 5.
     *
     * @return int[]
     */
    protected function planFreeform(int $total, int $min, int $max): array
    {
        $parts = [];
        $left = $total;

        while ($left > 0) {
            $upper = min($max, $left);
            $candidates = [];

            for ($size = $min; $size <= $upper; $size++) {
                if ($this->isSplittable($left - $size, $min, $max)) {
                    $candidates[] = $size;
                }
            }

            if ($candidates !== []) {
                $size = $candidates[random_int(0, count($candidates) - 1)];
                $parts[] = $size;
                $left -= $size;

                continue;
            }

            // The remaining head-count cannot be split evenly under these bounds
            // (9 members in groups of exactly 4, say). Take the largest legal
            // group and let the final round be a short one.
            $size = $left < $min ? $left : $upper;
            $parts[] = $size;
            $left -= $size;

            if ($left > 0 && $left < $min) {
                $parts[] = $left;
                $left = 0;
            }
        }

        return $parts;
    }

    /**
     * Can $rest be written as a sum of k groups each within [$min, $max]?
     * True when some k satisfies k * $min <= $rest <= k * $max.
     */
    protected function isSplittable(int $rest, int $min, int $max): bool
    {
        if ($rest === 0) {
            return true;
        }

        if ($rest < $min) {
            return false;
        }

        $groups = (int) ceil($rest / $max);

        return $groups * $min <= $rest;
    }
}
