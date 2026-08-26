<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerStatusRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\WorkflowService;
class CustomerController extends Controller
{
    public function __construct(private readonly WorkflowService $workflow) {}

    public function index()
    {
        return CustomerResource::collection(Customer::query()->with('assignedTechnician')->latest()->get());
    }

    public function store(StoreCustomerRequest $request)
    {
        return CustomerResource::make($this->workflow->registerCustomer($request->validated(), $request->user()));
    }

    public function updateStatus(UpdateCustomerStatusRequest $request, Customer $customer)
    {
        return CustomerResource::make(
            $this->workflow->updateCustomerStatus($customer, $request->string('status')->toString(), $request->user(), $request->string('notes')->toString() ?: null)
        );
    }

    public function recordPayment(Customer $customer)
    {
        $payload = request()->validate([
            'notes' => ['nullable', 'string'],
            'paid_at' => ['nullable', 'date'],
            'payment_channel' => ['nullable', 'string'],
        ]);

        return CustomerResource::make(
            $this->workflow->recordCustomerPayment(
                $customer,
                request()->user(),
                $payload['notes'] ?? null,
                $payload['paid_at'] ?? null,
                $payload['payment_channel'] ?? null
            )
        );
    }

    public function previewImport()
    {
        $file = request()->file('file');
        $rows = request()->input('rows', []);
        if (is_string($rows)) {
            $rows = json_decode($rows, true) ?? [];
        }

        abort_if(! $file && empty($rows), 422, 'Unggah file Excel/CSV atau sertakan data baris pelanggan.');

        $result = $this->workflow->previewCustomerImport(is_array($rows) ? $rows : [], $file, request()->user());

        return response()->json($result);
    }

    public function confirmImport()
    {
        $rows = request()->input('rows', []);
        if (is_string($rows)) {
            $rows = json_decode($rows, true) ?? [];
        }

        abort_if(empty($rows) || ! is_array($rows), 422, 'Data baris yang akan diimpor tidak boleh kosong.');

        $result = $this->workflow->executeCustomerImport($rows, request()->user());

        return response()->json($result);
    }

    public function downloadTemplate()
    {
        $csv = "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
        $csv .= "Nama Pelanggan,NIK,Nomor HP,Alamat,Wilayah,Paket Layanan,Tarif Bulanan,ODP ID,Status Pembayaran\n";
        $csv .= "Budi Santoso,3201123456780001,081234567890,Jl. Mawar No. 12 RT 01 RW 02,Denpasar,Home 50 Mbps,300000,ODP-DPS-01,Lunas\n";
        $csv .= "Siti Aminah,3201987654320002,081987654321,Jl. Melati No. 45,Badung,Home 30 Mbps,200000,ODP-BDG-01,Lunas\n";
        $csv .= "I Wayan Koster,5101012345670003,082134567899,Jl. Gatot Subroto No. 88,Denpasar,Business 100 Mbps,500000,,Lunas\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="template_import_pelanggan.csv"',
        ]);
    }
}
