<?php

namespace Tests\Feature\Ui;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GoodsReceiptFormLabelsTest extends TestCase
{
    public function test_grn_from_po_form_labels_do_not_render_raw_purchase_order_ids(): void
    {
        $source = File::get(resource_path('js/solastock/pages/GoodsReceiptFormPage.jsx'));

        $this->assertStringNotContainsString('From PO #${poId}', $source);
        $this->assertStringNotContainsString('`#${header.purchase_order_id}`', $source);
        $this->assertStringContainsString('sourcePoLabel', $source);
        $this->assertStringContainsString('api.purchaseOrder(poId)', $source);
    }
}
