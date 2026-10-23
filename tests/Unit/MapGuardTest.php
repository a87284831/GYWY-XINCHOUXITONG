<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Purchase/Support.php';

/**
 * 清单外「归入标准商品」前置校验纯函数 map_guard：
 *  - 单位不一致必须拦下（要求管理员先改）
 *  - 同规格已存在必须返回 spec_exists（前端让管理员选删除记录 / 改规格）
 *  - 全角、多余空格经 norm_spec 归一化后不影响判定
 */
class MapGuardTest extends TestCase
{
    private array $item = ['spec' => '12 包/箱', 'unit' => '箱'];
    private array $prod = ['name' => 'a4 纸', 'spec' => '8 包/箱', 'unit' => '箱'];

    public function test_单位一致且规格不冲突时放行(): void
    {
        $r = \map_guard($this->item, $this->prod, null);
        $this->assertTrue($r['ok']);
        $this->assertSame('', $r['code']);
    }

    public function test_单位不一致时拦下并给出双方单位(): void
    {
        $item = ['spec' => '12', 'unit' => '包'];
        $r = \map_guard($item, $this->prod, null);
        $this->assertFalse($r['ok']);
        $this->assertSame('unit_mismatch', $r['code']);
        $this->assertSame('包', $r['item_unit']);
        $this->assertSame('箱', $r['product_unit']);
        $this->assertStringContainsString('不一致', $r['msg']);
    }

    public function test_全角空格与多余空白归一化后视为一致(): void
    {
        $item = ['spec' => '12 包/箱', 'unit' => '　箱 '];
        $r = \map_guard($item, $this->prod, null);
        $this->assertTrue($r['ok'], '全角空格/首尾空格不应误判为单位不一致');
    }

    public function test_同规格已存在时返回spec_exists与已存在行信息(): void
    {
        $existing = ['id' => 88, 'spec' => '12 包/箱', 'unit' => '箱'];
        $r = \map_guard($this->item, $this->prod, $existing);
        $this->assertFalse($r['ok']);
        $this->assertSame('spec_exists', $r['code']);
        $this->assertSame(88, $r['existing']['id']);
        $this->assertSame('12 包/箱', $r['existing']['spec']);
        $this->assertStringContainsString('a4 纸', $r['msg']);
    }

    public function test_单位不一致优先于规格冲突(): void
    {
        $item = ['spec' => '12 包/箱', 'unit' => '包'];
        $existing = ['id' => 88, 'spec' => '12 包/箱', 'unit' => '箱'];
        $r = \map_guard($item, $this->prod, $existing);
        $this->assertSame('unit_mismatch', $r['code'], '先修单位再谈规格');
    }
}
