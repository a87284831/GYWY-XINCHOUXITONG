<?php

namespace App\Invoice\Issuers;

interface InvoiceIssuer
{
    public function name(): string;

    /**
     * @return array{ok:bool,invoice_no?:string,pdf_path?:string,ofd_path?:string,order_no?:string,fail_code?:string,fail_msg?:string,simulated?:bool,raw?:mixed}
     */
    public function issue(array $request, object $accountSet): array;

    /**
     * @return array{ok:bool,red_confirm_no?:string,red_invoice_no?:string,fail_code?:string,fail_msg?:string,simulated?:bool,raw?:mixed}
     */
    public function redFlush(array $request, object $accountSet, string $reason): array;
}
