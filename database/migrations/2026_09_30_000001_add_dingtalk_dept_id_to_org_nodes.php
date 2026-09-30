<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 钉钉同步使用 org_nodes.dingtalk_dept_id（钉钉部门 ID 与本地组织节点绑定）。
 * 历史上该列为生产/本地手工加列，从未进迁移，导致新环境 migrate 后
 * 钉钉全量同步/回调在 syncOrgTree 处崩溃。幂等补列，生产已存在时跳过。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('org_nodes', 'dingtalk_dept_id')) {
            Schema::table('org_nodes', function (Blueprint $table) {
                $table->string('dingtalk_dept_id', 40)->nullable()->index()->after('code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('org_nodes', 'dingtalk_dept_id')) {
            Schema::table('org_nodes', function (Blueprint $table) {
                $table->dropColumn('dingtalk_dept_id');
            });
        }
    }
};
