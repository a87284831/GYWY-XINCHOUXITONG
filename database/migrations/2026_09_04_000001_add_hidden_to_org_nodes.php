<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 组织架构节点：隐藏标记（隐藏的部门在组织架构图与人事档案选部门中不显示） */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('org_nodes', 'hidden')) {
            Schema::table('org_nodes', function (Blueprint $table) {
                $table->boolean('hidden')->default(false)->after('enabled');
            });
        }
    }

    public function down(): void
    {
        Schema::table('org_nodes', function (Blueprint $table) {
            $table->dropColumn('hidden');
        });
    }
};
