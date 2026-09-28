<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * 人员档案扩展字段配置：字段定义、枚举字典、必填项设置。
 * 所有扩展字段统一存放于 payroll_staff.data JSON 中（键见 FIELDS）。
 */
class StaffProfile
{
    /** 新增档案字段定义：key => [label, type, enumKey?, builtin?]；builtin=true 表示已有独立列 */
    public const FIELDS = [
        // key        => [label, type, enumKey]
        'gender'           => ['性别', 'enum', 'gender'],
        'phone'            => ['本人联系方式', 'text', null],
        'birth_date'       => ['出生日期', 'date', null],
        'nation'           => ['民族', 'text', 'nation'],
        'marital'          => ['婚姻状况', 'enum', 'marital'],
        'school'           => ['毕业院校', 'text', null],
        'major'            => ['所学专业', 'text', null],
        'education'        => ['学历', 'enum', 'education'],
        'grad_date'        => ['毕业时间', 'date', null],
        'certificate'      => ['资格证书', 'text', null],
        'politics'         => ['政治面貌', 'enum', 'politics'],
        'home_addr'        => ['家庭住址', 'text', null],
        'emergency_contact' => ['紧急联系人', 'text', null],
        'emergency_phone'  => ['紧急联系人电话', 'text', null],
        'recruit_channel'  => ['招聘渠道', 'enum', 'recruit'],
        'hometown'         => ['籍贯', 'text', null],
        'level'            => ['层级', 'enum', 'level'],
        // 薪酬档位：钉钉智能人事花名册「薪酬档位」单选字段同步写入，本地仅查看（同步会覆盖）
        'pay_grade'        => ['薪酬档位(钉钉同步)', 'enum', 'pay_grade'],
        'contract_start'   => ['劳动合同开始日期', 'date', null],
        'contract_end'     => ['劳动合同结束日期', 'date', null],
    ];

    /** 可被管理员设为必填的字段（key => 中文名） */
    public const REQUIRABLE = [
        'name' => '姓名', 'project' => '所属项目', 'dept' => '所属部门', 'position' => '岗位',
        'fixed' => '固定工资', 'base' => '基本工资',
        'phone' => '本人联系方式', 'hire_date' => '入职日期', 'id_card' => '身份证号',
        'birth_date' => '出生日期', 'gender' => '性别', 'nation' => '民族', 'marital' => '婚姻状况',
        'education' => '学历', 'politics' => '政治面貌', 'recruit_channel' => '招聘渠道',
        'bank_card' => '银行卡号', 'emergency_contact' => '紧急联系人', 'emergency_phone' => '紧急联系人电话',
        'leader' => '直属上级', 'level' => '层级',
        'contract_start' => '劳动合同开始日期', 'contract_end' => '劳动合同结束日期',
    ];

    public const GENDERS = ['男', '女'];
    public const MARITAL = ['未婚', '已婚', '离异', '丧偶'];
    public const EDUCATS = ['博士', '硕士', '本科', '大专', '中专', '高中及以下'];
    public const POLITICS = ['群众', '共青团员', '预备党员', '中共党员', '民主党派'];
    public const RECRUITS = ['网络招聘', '校园招聘', '内部推荐', '劳务派遣', '人才市场', '猎头', '其他'];
    public const NATIONS = ['汉族', '蒙古族', '回族', '藏族', '维吾尔族', '苗族', '彝族', '壮族', '布依族', '朝鲜族',
        '满族', '侗族', '瑶族', '白族', '土家族', '哈尼族', '哈萨克族', '傣族', '黎族', '傈僳族', '佤族', '畲族',
        '高山族', '拉祜族', '水族', '东乡族', '纳西族', '景颇族', '柯尔克孜族', '土族', '达斡尔族', '仫佬族',
        '羌族', '布朗族', '撒拉族', '毛南族', '仡佬族', '锡伯族', '阿昌族', '普米族', '塔吉克族', '怒族', '乌孜别克族',
        '俄罗斯族', '鄂温克族', '德昂族', '保安族', '裕固族', '京族', '塔塔尔族', '独龙族', '鄂伦春族', '赫哲族',
        '门巴族', '珞巴族', '基诺族'];
    public const LEVELS = ['经理级以上', '主管级以上', '主管级', '专员级', '普通员工'];
    /** 薪酬档位（与 CalcRules::PAY_GRADES 及钉钉花名册选项逐字一致） */
    public const PAY_GRADES = ['专员级', '主管级', '经理级'];

    /** 枚举字典（供前端下拉） */
    public static function enums(): array
    {
        return [
            'gender' => self::GENDERS,
            'marital' => self::MARITAL,
            'education' => self::EDUCATS,
            'politics' => self::POLITICS,
            'recruit' => self::RECRUITS,
            'nation' => self::NATIONS,
            'level' => self::LEVELS,
            'pay_grade' => self::PAY_GRADES,
        ];
    }

    /** 读取当前必填项配置（存 legacy_json_snapshots） */
    public static function requiredFields(): array
    {
        $snap = DB::table('legacy_json_snapshots')->where('file_name', 'staff_field_config')->first();
        $cfg = $snap ? (json_decode((string) $snap->payload, true) ?: []) : [];
        $list = $cfg['required'] ?? [];
        // 默认必填：姓名、所属项目
        $base = array_values(array_unique(array_merge(['name', 'project'], is_array($list) ? $list : [])));
        return array_values(array_intersect($base, array_keys(self::REQUIRABLE)));
    }

    public static function saveRequiredFields(array $list): void
    {
        $clean = array_values(array_unique(array_filter(array_map('strval', $list))));
        $clean = array_values(array_intersect($clean, array_keys(self::REQUIRABLE)));
        DB::table('legacy_json_snapshots')->updateOrInsert(['file_name' => 'staff_field_config'],
            ['payload' => json_encode(['required' => $clean], JSON_UNESCAPED_UNICODE), 'imported_at' => now()]);
    }

    /** 由身份证推算出生日期 YYYY-MM-DD（18 位取第7-14位，15 位取第7-12位） */
    public static function birthFromIdCard(string $id): ?string
    {
        $id = trim($id);
        if (strlen($id) === 18 && preg_match('/^\d{17}[\dXx]$/', $id)) {
            $ymd = substr($id, 6, 8);
        } elseif (strlen($id) === 15 && preg_match('/^\d{15}$/', $id)) {
            $ymd = '19' . substr($id, 6, 6);
        } else {
            return null;
        }
        return preg_match('/^\d{8}$/', $ymd) ? substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2) : null;
    }

    /** 按身份证校验位校验 18 位身份证是否合法 */
    public static function validIdCard(string $id): bool
    {
        $id = strtoupper(trim($id));
        if (strlen($id) !== 18 || !preg_match('/^\d{17}[\dX]$/', $id)) return false;
        $w = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
        $map = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];
        $sum = 0;
        for ($i = 0; $i < 17; $i++) $sum += (int) $id[$i] * $w[$i];
        return $map[$sum % 11] === $id[17];
    }

    /** 手机号 11 位校验 */
    public static function validPhone(string $p): bool
    {
        return preg_match('/^1[3-9]\d{9}$/', trim($p)) === 1;
    }
}
