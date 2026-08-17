<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "My Responsibility People": seats in a Group Equb held for someone who has
 * no Niya account of their own — a child, a parent, a neighbour without a
 * phone.
 *
 * The seat is a full membership. It counts towards the head-count, it owes a
 * contribution every round, and it can be drawn as a winner like anybody else.
 * What it does not have is a member_id, because there is no account behind it.
 * Every obligation on the seat — paying it, being reminded about it, receiving
 * its payout — belongs to the member named in sponsor_member_id, the person who
 * added them.
 *
 * Storing these as memberships rather than as a separate table is deliberate:
 * the ledger, the draw engine, the payment schedule and the pot arithmetic all
 * count memberships, so a responsibility seat is counted correctly everywhere
 * without a single one of those places having to learn a new concept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equb_memberships', function (Blueprint $table) {
            // A responsibility seat has no account behind it. The existing
            // unique(equb_group_id, member_id) still holds for real members:
            // NULLs are distinct in a unique index, so one sponsor can hold
            // several seats in the same group.
            $table->unsignedBigInteger('member_id')->nullable()->change();

            // Who is answerable for this seat. Set only for responsibility
            // seats; a normal membership answers for itself.
            $table->foreignId('sponsor_member_id')
                ->nullable()
                ->after('member_id')
                ->constrained('members')
                ->nullOnDelete();

            // The name the sponsor gave them, shown everywhere a member name
            // would be. Phone is optional — most of these people do not have
            // one, which is the whole reason the feature exists.
            $table->string('responsibility_name')->nullable()->after('sponsor_member_id');
            $table->string('responsibility_phone', 20)->nullable()->after('responsibility_name');
            $table->string('responsibility_relation', 40)->nullable()->after('responsibility_phone');
            $table->text('responsibility_note')->nullable()->after('responsibility_relation');

            // "All the seats I am carrying in this group" is the single most
            // common read: the sponsor's own dashboard, the ledger grouping and
            // the per-sponsor cap all ask it.
            $table->index(['equb_group_id', 'sponsor_member_id'], 'equb_memberships_sponsor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('equb_memberships', function (Blueprint $table) {
            $table->dropIndex('equb_memberships_sponsor_idx');
            $table->dropForeign(['sponsor_member_id']);
            $table->dropColumn([
                'sponsor_member_id',
                'responsibility_name',
                'responsibility_phone',
                'responsibility_relation',
                'responsibility_note',
            ]);
        });

        // Only safe once the seats that need a null member_id are gone, which
        // dropping the columns above has just done.
        Schema::table('equb_memberships', function (Blueprint $table) {
            $table->unsignedBigInteger('member_id')->nullable(false)->change();
        });
    }
};
