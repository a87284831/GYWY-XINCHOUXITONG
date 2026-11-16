<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_account_sets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->string('seller_name', 160);
            $table->string('seller_tax_no', 32)->unique();
            $table->string('seller_address', 255)->nullable();
            $table->string('seller_phone', 32)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_account', 64)->nullable();
            $table->string('invoice_kind', 20)->default('digital');
            $table->string('default_goods_code', 64)->nullable();
            $table->string('default_goods_name', 160)->nullable();
            $table->decimal('default_tax_rate', 5, 4)->default(0.0600);
            $table->decimal('amount_limit', 12, 2)->default(50000);
            $table->string('email_from', 120)->nullable();
            $table->string('email_from_name', 120)->nullable();
            $table->string('email_reply_to', 120)->nullable();
            $table->string('email_provider', 16)->default('smtp');
            $table->string('smtp_host', 120)->nullable();
            $table->unsignedInteger('smtp_port')->nullable();
            $table->string('smtp_username', 120)->nullable();
            $table->text('smtp_password_encrypted')->nullable();
            $table->string('smtp_encryption', 16)->nullable();
            $table->string('nuonuo_app_key', 64)->nullable();
            $table->text('nuonuo_app_secret_encrypted')->nullable();
            $table->text('nuonuo_app_secret_prev_encrypted')->nullable();
            $table->timestamp('nuonuo_secret_prev_expires_at')->nullable();
            $table->timestamp('nuonuo_secret_rotated_at')->nullable();
            $table->string('nuonuo_org_id', 64)->nullable();
            $table->string('nuonuo_env', 16)->default('sandbox');
            $table->string('status', 16)->default('enabled');
            $table->string('remark', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_project_bindings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->unique();
            $table->string('project_name', 120);
            $table->unsignedBigInteger('account_set_id');
            $table->string('entry_token', 64)->unique();
            $table->tinyInteger('enabled')->default(1);
            $table->timestamps();
            $table->index('account_set_id');
        });

        Schema::create('invoice_account_set_reviewers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_set_id');
            $table->unsignedBigInteger('account_id');
            $table->tinyInteger('is_primary')->default(0);
            $table->tinyInteger('can_view')->default(1);
            $table->tinyInteger('can_review')->default(1);
            $table->tinyInteger('can_issue')->default(0);
            $table->tinyInteger('can_stats')->default(0);
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->tinyInteger('enabled')->default(1);
            $table->timestamps();
            $table->unique(['account_set_id', 'account_id']);
        });

        Schema::create('invoice_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 32)->unique();
            $table->unsignedBigInteger('account_set_id');
            $table->string('account_set_code', 32);
            $table->string('seller_name_snapshot', 160);
            $table->string('seller_tax_no_snapshot', 32);
            $table->decimal('tax_rate_snapshot', 5, 4)->default(0.0600);
            $table->string('goods_code_snapshot', 64)->nullable();
            $table->unsignedBigInteger('project_id');
            $table->string('project_name', 120);
            $table->string('house_no', 64);
            $table->string('payer_name', 120);
            $table->string('fee_period', 64)->nullable();
            $table->decimal('declared_amount', 12, 2);
            $table->decimal('verified_amount', 12, 2)->nullable();
            $table->decimal('amount_ex_tax', 12, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->string('title_type', 10)->default('personal');
            $table->string('title', 200);
            $table->string('buyer_tax_no', 32)->nullable();
            $table->string('buyer_email', 120);
            $table->string('buyer_phone', 32)->nullable();
            $table->string('status', 24)->default('submitted');
            $table->unsignedInteger('version')->default(1);
            $table->string('idempotency_key', 100)->unique();
            $table->string('query_key_hash', 64);
            $table->string('reject_reason', 255)->nullable();
            $table->string('review_note', 255)->nullable();
            $table->string('amount_flag', 40)->nullable();
            $table->unsignedTinyInteger('attachment_count')->default(0);
            $table->string('issuer', 32)->nullable();
            $table->tinyInteger('simulated')->default(0);
            $table->string('nuonuo_order_no', 64)->nullable();
            $table->string('digital_invoice_no', 32)->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('pdf_path', 512)->nullable();
            $table->string('ofd_path', 512)->nullable();
            $table->string('xml_path', 512)->nullable();
            $table->string('red_confirm_no', 64)->nullable();
            $table->string('red_invoice_no', 64)->nullable();
            $table->string('fail_code', 64)->nullable();
            $table->string('fail_msg', 500)->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedBigInteger('red_flush_of')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedTinyInteger('deliver_attempts')->default(0);
            $table->timestamp('next_delivery_at')->nullable();
            $table->string('remark', 500)->nullable();
            $table->timestamps();

            $table->index(['account_set_id', 'status', 'created_at']);
            $table->index(['project_id', 'status']);
            $table->index('house_no');
            $table->index('buyer_tax_no');
        });

        Schema::create('invoice_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('account_set_id');
            $table->string('file_name', 255);
            $table->string('file_path', 512);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->string('md5', 32);
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();
            $table->index('request_id');
            $table->index('md5');
        });

        Schema::create('invoice_request_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id')->nullable();
            $table->unsignedBigInteger('account_set_id')->nullable();
            $table->string('action', 64);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->string('operator_type', 16)->default('system');
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('operator_name', 120)->nullable();
            $table->text('payload')->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('ua', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['request_id', 'created_at']);
            $table->index(['account_set_id', 'action']);
        });

        Schema::create('invoice_delivery_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('account_set_id');
            $table->string('channel', 16)->default('email');
            $table->string('target', 120);
            $table->string('result', 16);
            $table->string('provider_msg_id', 120)->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->timestamp('created_at')->nullable();
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_delivery_logs');
        Schema::dropIfExists('invoice_request_logs');
        Schema::dropIfExists('invoice_attachments');
        Schema::dropIfExists('invoice_requests');
        Schema::dropIfExists('invoice_account_set_reviewers');
        Schema::dropIfExists('invoice_project_bindings');
        Schema::dropIfExists('invoice_account_sets');
    }
};
