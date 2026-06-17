<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('verification_assignment_category_user', 'review_level')) {
            Schema::table('verification_assignment_category_user', function (Blueprint $table) {
                $table->string('review_level', 16)->default('level1')->after('user_id');
            });
        }

        DB::table('verification_assignment_category_user')
            ->whereNull('review_level')
            ->orWhere('review_level', '')
            ->update(['review_level' => 'level1']);

        if ($this->hasIndex('vac_user_unique')) {
            Schema::table('verification_assignment_category_user', function (Blueprint $table) {
                $table->dropForeign('vac_user_cat_fk');
                $table->dropForeign('vac_user_user_fk');
                $table->dropUnique('vac_user_unique');
            });
        }

        if (! $this->hasIndex('vac_user_level_unique')) {
            Schema::table('verification_assignment_category_user', function (Blueprint $table) {
                $table->unique(
                    ['verification_assignment_category_id', 'user_id', 'review_level'],
                    'vac_user_level_unique',
                );
            });
        }

        if (! $this->hasForeignKey('vac_user_cat_fk')) {
            Schema::table('verification_assignment_category_user', function (Blueprint $table) {
                $table->foreign('verification_assignment_category_id', 'vac_user_cat_fk')
                    ->references('id')->on('verification_assignment_categories')
                    ->cascadeOnDelete();
            });
        }

        if (! $this->hasForeignKey('vac_user_user_fk')) {
            Schema::table('verification_assignment_category_user', function (Blueprint $table) {
                $table->foreign('user_id', 'vac_user_user_fk')
                    ->references('id')->on('users')
                    ->cascadeOnDelete();
            });
        }

        if (! $this->hasIndex('vac_user_level_state_idx')) {
            Schema::table('verification_assignment_category_user', function (Blueprint $table) {
                $table->index(
                    ['verification_assignment_category_id', 'review_level', 'is_active', 'is_available'],
                    'vac_user_level_state_idx',
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('verification_assignment_category_user', 'review_level')) {
            return;
        }

        Schema::table('verification_assignment_category_user', function (Blueprint $table) {
            if ($this->hasIndex('vac_user_level_state_idx')) {
                $table->dropIndex('vac_user_level_state_idx');
            }
            if ($this->hasForeignKey('vac_user_cat_fk')) {
                $table->dropForeign('vac_user_cat_fk');
            }
            if ($this->hasForeignKey('vac_user_user_fk')) {
                $table->dropForeign('vac_user_user_fk');
            }
            if ($this->hasIndex('vac_user_level_unique')) {
                $table->dropUnique('vac_user_level_unique');
            }
            if (! $this->hasIndex('vac_user_unique')) {
                $table->unique(['verification_assignment_category_id', 'user_id'], 'vac_user_unique');
            }
            if (! $this->hasForeignKey('vac_user_cat_fk')) {
                $table->foreign('verification_assignment_category_id', 'vac_user_cat_fk')
                    ->references('id')->on('verification_assignment_categories')
                    ->cascadeOnDelete();
            }
            if (! $this->hasForeignKey('vac_user_user_fk')) {
                $table->foreign('user_id', 'vac_user_user_fk')
                    ->references('id')->on('users')
                    ->cascadeOnDelete();
            }
            $table->dropColumn('review_level');
        });
    }

    private function hasIndex(string $name): bool
    {
        return in_array($name, $this->indexNames(), true);
    }

    private function hasForeignKey(string $name): bool
    {
        return in_array($name, $this->foreignKeyNames(), true);
    }

    /**
     * @return array<int, string>
     */
    private function indexNames(): array
    {
        return collect(DB::select('SHOW INDEX FROM verification_assignment_category_user'))
            ->pluck('Key_name')
            ->map(fn ($name) => (string) $name)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function foreignKeyNames(): array
    {
        $rows = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'verification_assignment_category_user'
               AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );

        return array_map(fn ($row) => (string) $row->CONSTRAINT_NAME, $rows);
    }
};
