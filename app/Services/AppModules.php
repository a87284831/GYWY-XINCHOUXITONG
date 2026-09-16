<?php

namespace App\Services;

/**
 * 应用模块权限树：供前端“系统设置→权限”页渲染、以及菜单权限点展示。
 * 权限点 key 与前端 canPerm / ALL_MENUS 的 perm 一致。
 * 覆盖系统全部 12 个应用模块（与前端 APPS 菜单一一对应）。
 */
class AppModules
{
    public static function tree(): array
    {
        return [
            ['key' => 'hr', 'name' => '人力资源', 'icon' => '👥', 'color' => '#2563eb', 'perms' => [
                ['payroll', '薪资核算与汇总'],
                ['attendance', '考勤管理'],
                ['staff', '人员档案与调薪'],
                ['projects', '组织架构'],
                ['budget', '预算管理'],
                ['adjust', '调薪与记录'],
                ['export', '数据导出'],
                ['perf', '绩效考核（发起/审批/填报）'],
                ['perf_admin', '考核记录管理'],
            ]],
            ['key' => 'contract', 'name' => '合同管理', 'icon' => '📄', 'color' => '#e74c3c', 'perms' => [
                ['contract_view', '合同台账查看'],
                ['contract_manage', '合同录入与管理'],
            ]],
            ['key' => 'admin', 'name' => '行政管理', 'icon' => '🏢', 'color' => '#8b5cf6', 'perms' => [
                ['admin_view', '行政事务查看'],
                ['admin_manage', '行政管理'],
            ]],
            ['key' => 'oa', 'name' => 'OA审批', 'icon' => '✅', 'color' => '#3b82f6', 'perms' => [
                ['approval', '审批中心（发起/审批）'],
                ['approval_manage', '流程管理'],
            ]],
            ['key' => 'property', 'name' => '房产管理', 'icon' => '🏠', 'color' => '#1e40af', 'perms' => [
                ['property_view', '房产台账查看'],
                ['property_manage', '房产管理'],
            ]],
            ['key' => 'maintain', 'name' => '工程维保', 'icon' => '🔧', 'color' => '#f59e0b', 'perms' => [
                ['maint_fire', '消防维保台账与报表'],
                ['maint_elev', '电梯维保台账与报表'],
                ['maint_partners', '签约方维护'],
            ]],
            ['key' => 'security', 'name' => '安保管理', 'icon' => '🛡️', 'color' => '#16a34a', 'perms' => [
                ['security_view', '安保台账查看'],
                ['security_manage', '安保管理'],
            ]],
            ['key' => 'cleaning', 'name' => '保洁绿化', 'icon' => '🧹', 'color' => '#14b8a6', 'perms' => [
                ['cleaning_view', '保洁绿化查看'],
                ['cleaning_manage', '保洁绿化管理'],
            ]],
            ['key' => 'purchase', 'name' => '采购管理', 'icon' => '📦', 'color' => '#6366f1', 'perms' => [
                ['purchase_view', '采购台账查看'],
                ['purchase_manage', '采购管理'],
            ]],
            ['key' => 'finance', 'name' => '财务管理', 'icon' => '💰', 'color' => '#0d9488', 'perms' => [
                ['finance_view', '财务数据查看'],
                ['finance_admin', '财务数据管理'],
            ]],
            ['key' => 'report', 'name' => '报表中心', 'icon' => '📊', 'color' => '#0ea5e9', 'perms' => [
                ['hr_report', '人力资源报表'],
                ['salary_report', '薪酬报表'],
                ['attendance_report', '考勤报表'],
            ]],
            ['key' => 'notice', 'name' => '公告通知', 'icon' => '📢', 'color' => '#ef4444', 'perms' => [
                ['notice_view', '公告查看'],
                ['notice_manage', '公告发布与管理'],
            ]],
            ['key' => 'setting', 'name' => '系统设置', 'icon' => '⚙️', 'color' => '#64748b', 'perms' => [
                ['settings_manage', '系统设置（仅管理员）'],
            ]],
        ];
    }
}
