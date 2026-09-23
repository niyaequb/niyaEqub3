<?php

/*
|--------------------------------------------------------------------------
| Equb business rules
|--------------------------------------------------------------------------
|
| The money rules and the lottery rules, in one file, as numbers rather than
| as code buried in a service. Everything here is a *default*: an admin can
| override any of it from Settings, and EqubRules is the single place that
| merges the two. Nothing outside EqubRules should read this file directly,
| because a value read straight from config silently ignores the override an
| admin set an hour ago — which is how a draw ends up running under rules
| nobody on the team believes are in force.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Service fee — the only money that is ours
    |----------------------------------------------------------------------
    |
    | Every birr a member contributes passes through us on its way to whoever
    | wins the round. We keep a percentage of it and nothing else. That makes
    | the distinction below the most important one in the whole system:
    |
    |   collected      what members paid in           (not revenue)
    |   service fee    our percentage of it           (revenue)
    |   members' money collected minus the fee        (a liability, not ours)
    |
    | Reporting "collected" as income is how a company convinces itself it is
    | twenty times larger than it is, so the Profit report exists to keep the
    | three apart and every screen that shows a total says which one it means.
    |
    | `model` records how the fee is taken, because the arithmetic differs:
    |
    |   deducted  the member pays 100, 95 reaches the pot, 5 is ours.
    |             fee = collected x rate
    |   added     the member pays 105 for a 100 round; the 5 is ours.
    |             fee = collected x rate / (1 + rate)
    |   payout    nothing is taken per contribution; the pot is paid out less
    |             the percentage, and the fee is booked at draw time.
    |
    */
    'fee' => [
        'percent' => (float) env('EQUB_SERVICE_FEE_PERCENT', 5.0),
        'model' => env('EQUB_SERVICE_FEE_MODEL', 'deducted'),
    ],

    /*
    |----------------------------------------------------------------------
    | Arrears — when someone is behind, and how far
    |----------------------------------------------------------------------
    |
    | A missed round is counted against the schedule the membership agreed to
    | (join date, contribution amount, frequency), never against whether a
    | payment row happens to exist. A member who joined and then never paid
    | creates no rows at all, and for a long time that made them invisible to
    | every figure on the reports page — the arrears simply did not exist as
    | far as the system was concerned.
    |
    | `grace_days` gives a round a few days to settle before it counts as
    | missed, because a bank transfer landing on Monday for a Friday round is
    | not a delinquency.
    |
    */
    'arrears' => [
        'grace_days' => (int) env('EQUB_ARREARS_GRACE_DAYS', 2),

        // Missed rounds at which each consequence begins.
        'warn_at' => (int) env('EQUB_ARREARS_WARN_AT', 1),
        'block_at' => (int) env('EQUB_ARREARS_BLOCK_AT', 2),
        'suspend_at' => (int) env('EQUB_ARREARS_SUSPEND_AT', 3),

        // Ageing bands, in days overdue, for the receivables report.
        'ageing_buckets' => [30, 60, 90],
    ],

    /*
    |----------------------------------------------------------------------
    | Lottery
    |----------------------------------------------------------------------
    |
    | A draw where everyone has identical odds rewards the member who pays on
    | the last possible day exactly as much as the one who paid six rounds up
    | front, and punishes nobody for paying late. The weights below are how
    | the Equb pays attention to behaviour:
    |
    |   advance       rounds paid before they were due
    |   streak        consecutive rounds settled on or before their due date
    |   loyalty       rounds waited without ever having won
    |   clean record  never once late, and nothing owed right now
    |
    | Each is capped, and the caps matter more than the rates. Without them a
    | member who prepaid a year would hold most of the pool and the draw would
    | stop being a draw.
    |
    */
    'lottery' => [

        // Everyone starts here. A member with no history and nothing owed is
        // a full, ordinary entrant — the bonuses add on top, they are not
        // a tax on being new.
        'base_weight' => 1.0,

        'advance' => [
            'per_round' => (float) env('EQUB_LOTTERY_ADVANCE_PER_ROUND', 0.25),
            'max' => (float) env('EQUB_LOTTERY_ADVANCE_MAX', 1.50),
        ],

        'streak' => [
            'per_round' => (float) env('EQUB_LOTTERY_STREAK_PER_ROUND', 0.05),
            'max' => (float) env('EQUB_LOTTERY_STREAK_MAX', 0.75),
        ],

        'loyalty' => [
            'per_round' => (float) env('EQUB_LOTTERY_LOYALTY_PER_ROUND', 0.03),
            'max' => (float) env('EQUB_LOTTERY_LOYALTY_MAX', 0.60),
        ],

        'clean_record_bonus' => (float) env('EQUB_LOTTERY_CLEAN_BONUS', 0.25),

        // Applied to someone who is behind but not yet blocked. They stay in
        // the draw — removing a member on the first late payment is how you
        // lose them entirely — at halved odds.
        'late_penalty' => (float) env('EQUB_LOTTERY_LATE_PENALTY', 0.50),

        /*
        | Anti-fraud
        |
        | Three different shapes of abuse, three separate limits:
        |
        |   min_paid_rounds     join, win the first round, disappear. The
        |                       cheapest fraud there is, and it costs the
        |                       circle a whole payout.
        |   min_days_in_equb    the same trick, timed around a draw date.
        |   max_share_per_payer one person holding many places — their own,
        |                       plus every "responsibility" place they took on
        |                       — quietly owning most of the pool. Nothing
        |                       about it breaks a rule, which is what makes it
        |                       worth capping.
        */
        'min_paid_rounds' => (int) env('EQUB_LOTTERY_MIN_PAID_ROUNDS', 1),
        'min_days_in_equb' => (int) env('EQUB_LOTTERY_MIN_DAYS', 0),
        'max_share_per_payer' => (float) env('EQUB_LOTTERY_MAX_PAYER_SHARE', 0.25),

        // Whole Group Equbs win together, so one member in arrears holds up
        // the group. Zero tolerance by default: the alternative is a group
        // collecting a payout while one of its members has paid nothing.
        'group' => [
            'max_members_in_arrears' => (int) env('EQUB_LOTTERY_GROUP_MAX_ARREARS', 0),
            'min_eligible_members' => (int) env('EQUB_LOTTERY_GROUP_MIN_MEMBERS', 1),
        ],

        /*
        | Auditing
        |
        | Every draw stores the seed it was decided by and the weight each
        | entrant held, so a result can be recomputed from scratch months
        | later. A lottery that cannot be re-derived is only trustworthy as
        | long as nobody asks.
        */
        'audit_snapshot_limit' => (int) env('EQUB_LOTTERY_AUDIT_LIMIT', 300),
    ],
];
