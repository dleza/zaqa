<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('awarding_institutions', 'accreditation_statement')) {
            Schema::table('awarding_institutions', function (Blueprint $table) {
                $table->text('accreditation_statement')->nullable()->after('consent_form_path');
            });
        }

        if (! Schema::hasColumn('awarding_institutions', 'accreditation_statement_source')) {
            Schema::table('awarding_institutions', function (Blueprint $table) {
                $table->string('accreditation_statement_source', 50)->nullable()->after('accreditation_statement');
            });
        }

        if (! Schema::hasColumn('awarding_institutions', 'accreditation_statement_updated_by_user_id')) {
            Schema::table('awarding_institutions', function (Blueprint $table) {
                $table->unsignedBigInteger('accreditation_statement_updated_by_user_id')->nullable()->after('accreditation_statement_source');
            });
        }

        if (! Schema::hasColumn('awarding_institutions', 'accreditation_statement_updated_at')) {
            Schema::table('awarding_institutions', function (Blueprint $table) {
                $table->timestamp('accreditation_statement_updated_at')->nullable()->after('accreditation_statement_updated_by_user_id');
            });
        }

        if (! $this->foreignKeyExists('awarding_institutions', 'ai_accred_stmt_updated_by_fk')) {
            Schema::table('awarding_institutions', function (Blueprint $table) {
                $table->foreign('accreditation_statement_updated_by_user_id', 'ai_accred_stmt_updated_by_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->foreignKeyExists('awarding_institutions', 'ai_accred_stmt_updated_by_fk')) {
            Schema::table('awarding_institutions', function (Blueprint $table) {
                $table->dropForeign('ai_accred_stmt_updated_by_fk');
            });
        }

        $columns = [
            'accreditation_statement_updated_at',
            'accreditation_statement_updated_by_user_id',
            'accreditation_statement_source',
            'accreditation_statement',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('awarding_institutions', $column)) {
                Schema::table('awarding_institutions', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $result = $connection->select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ? LIMIT 1',
            [$database, $table, $name, 'FOREIGN KEY'],
        );

        return count($result) > 0;
    }
};
