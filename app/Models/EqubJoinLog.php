<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One person joining one Equb. Written once, by EqubMembershipObserver, and
 * never changed afterwards. See the migration for why the details are copied
 * in rather than referenced.
 */
class EqubJoinLog extends Model
{
    public const SOURCES = [
        'mobile_app' => 'Mobile app',
        'mini_app' => 'Mini app (Dashen)',
        'agent' => 'Agent',
        'admin' => 'Admin',
        'system' => 'System',
    ];

    protected $fillable = [
        'member_id',
        'member_name',
        'member_phone',
        'sponsor_member_id',
        'sponsor_name',
        'sponsor_phone',
        'equb_membership_id',
        'equb_group_id',
        'equb_name',
        'equb_package_id',
        'package_name',
        'is_group_equb',
        'contribution_amount',
        'contribution_frequency_days',
        'role',
        'source',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'is_group_equb' => 'boolean',
            'contribution_amount' => 'decimal:2',
            'contribution_frequency_days' => 'integer',
            'joined_at' => 'datetime',
        ];
    }

    /**
     * The row for a place that has just been taken.
     */
    public static function recordFor(EqubMembership $membership): self
    {
        $membership->loadMissing(['equbGroup.package', 'member.user', 'sponsor.user']);

        $group = $membership->equbGroup;
        $member = $membership->member;
        $sponsor = $membership->sponsor;
        $isSeat = $membership->member_id === null;

        $role = $membership->role;

        return static::create([
            'member_id' => $membership->member_id,
            'member_name' => $isSeat
                ? (trim((string) $membership->responsibility_name) ?: null)
                : ($member?->full_name ?: $member?->user?->name),
            'member_phone' => $isSeat ? $membership->responsibility_phone : $member?->user?->phone,
            'sponsor_member_id' => $membership->sponsor_member_id,
            'sponsor_name' => $sponsor?->full_name ?: $sponsor?->user?->name,
            'sponsor_phone' => $sponsor?->user?->phone,
            'equb_membership_id' => $membership->id,
            'equb_group_id' => $membership->equb_group_id,
            'equb_name' => $group?->name,
            'equb_package_id' => $group?->equb_package_id,
            'package_name' => $group?->package?->name,
            'is_group_equb' => $group?->owner_member_id !== null,
            'contribution_amount' => $membership->contribution_amount,
            'contribution_frequency_days' => $membership->contribution_frequency_days,
            'role' => $role instanceof \BackedEnum ? $role->value : $role,
            'source' => static::currentSource(),
            'joined_at' => $membership->created_at ?? Carbon::now(),
        ]);
    }

    /**
     * Where the join came from, read off the request that made it.
     *
     * The mobile app and the Dashen mini app call the same API; the mobile
     * app's HTTP client identifies itself as Dart, a web view as a browser.
     * Admin joins arrive through the panel (Livewire), not the API.
     */
    public static function currentSource(): string
    {
        if (app()->runningInConsole()) {
            return 'system';
        }

        $request = request();

        if ($request->is('api/agent/*')) {
            return 'agent';
        }

        if ($request->is('api/admin/*')) {
            return 'admin';
        }

        if ($request->is('api/*')) {
            return str_starts_with((string) $request->userAgent(), 'Dart/')
                ? 'mobile_app'
                : 'mini_app';
        }

        return 'admin';
    }

    /** "Daily", "Weekly", "Monthly" or "Every 14 days". */
    public function cycleLabel(): string
    {
        return match ($this->contribution_frequency_days) {
            null, 0 => '—',
            1 => 'Daily',
            7 => 'Weekly',
            30, 31 => 'Monthly',
            default => "Every {$this->contribution_frequency_days} days",
        };
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? (string) $this->source;
    }
}
