<?php

use App\Models\AdminMasterDataGroup;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AdminMasterDataGroup::query()->updateOrCreate(
            ['key' => 'payment_channels'],
            [
                'label' => 'Channel & Metode Penerimaan Pembayaran',
                'items' => [
                    ['name' => 'Transfer Kantor (BCA)', 'accountNumber' => '1234567890', 'accountHolder' => 'PT ISP EONET', 'type' => 'bank_transfer'],
                    ['name' => 'Transfer Kantor (Mandiri)', 'accountNumber' => '0987654321', 'accountHolder' => 'PT ISP EONET', 'type' => 'bank_transfer'],
                    ['name' => 'Tunai / Cash Kantor', 'accountNumber' => '-', 'accountHolder' => 'Kasir Kantor', 'type' => 'cash'],
                    ['name' => 'MMS', 'accountNumber' => 'MMS-PAY', 'accountHolder' => 'MMS Channel', 'type' => 'digital_channel'],
                    ['name' => 'SIS BRO', 'accountNumber' => 'SISBRO-PAY', 'accountHolder' => 'SIS BRO Channel', 'type' => 'digital_channel'],
                    ['name' => 'QRIS Kantor', 'accountNumber' => 'NMID12345678', 'accountHolder' => 'PT ISP EONET', 'type' => 'qris'],
                ],
                'editable_fields' => ['name', 'accountNumber', 'accountHolder', 'type'],
            ]
        );
    }

    public function down(): void
    {
        AdminMasterDataGroup::query()->where('key', 'payment_channels')->delete();
    }
};
