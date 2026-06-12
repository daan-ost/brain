<?php

namespace App\Http\Controllers\Profile;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    /**
     * Display the user's invoices list
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        // Get user's own orders with invoices
        $userOrders = Order::where('payer_type', 'user')
            ->where('payer_id', $user->id)
            ->whereNotNull('invoice_number')
            ->orderBy('invoice_date', 'desc')
            ->with('license')
            ->get();

        // Get organization orders where user is admin
        $orgOrders = collect();
        $adminOrganizations = $user->organizations()
            ->wherePivot('role', OrganizationRole::Owner)
            ->get();

        if ($adminOrganizations->isNotEmpty()) {
            $orgIds = $adminOrganizations->pluck('id');

            $orgOrders = Order::where('payer_type', 'organization')
                ->whereIn('payer_id', $orgIds)
                ->whereNotNull('invoice_number')
                ->orderBy('invoice_date', 'desc')
                ->with(['organizationPayer', 'license'])
                ->get();
        }

        // Combine and sort all invoices
        $allOrders = $userOrders->merge($orgOrders)->sortByDesc('invoice_date');

        // Track page view
        AnalyticsService::log('invoice_list_view', [
            'user_id' => $user->id,
            'total_invoices' => $allOrders->count(),
        ]);

        return view('profile.invoice', [
            'user' => $user,
            'orders' => $allOrders,
        ]);
    }

    /**
     * Download invoice PDF
     */
    public function download(Request $request, Order $order): Response
    {
        // Authorize using InvoicePolicy
        $this->authorize('download', $order);

        // Determine invoice source and file path
        $invoiceNumber = $order->invoice_number;
        $invoiceFilePath = $order->invoice_file_path;

        // For invoice payment (trusted organizations), check OrganizationLicense table
        if (! $invoiceNumber && isset($order->meta['invoice_license_id'])) {
            $orgLicense = \App\Models\OrganizationLicense::find($order->meta['invoice_license_id']);
            if ($orgLicense) {
                $invoiceNumber = $orgLicense->invoice_number;
                $invoiceFilePath = $orgLicense->invoice_file_path;
            }
        }

        // Check if invoice exists
        if (! $invoiceNumber) {
            abort(404, 'Invoice not found');
        }

        // If invoice file doesn't exist, generate it on-the-fly
        if (! $invoiceFilePath || ! Storage::exists($invoiceFilePath)) {
            try {
                // Clear file_path if it exists but file is missing (forces regeneration)
                if ($order->invoice_file_path) {
                    $order->update(['invoice_file_path' => null]);
                    $order->refresh();
                }

                $invoiceService = app(\App\Services\InvoiceGenerationService::class);
                // Don't send emails in test environment to avoid Postmark errors
                $sendEmail = config('app.env') !== 'testing';
                $result = $invoiceService->generateInvoice($order, $sendEmail);
                $invoiceFilePath = $result['invoice_file_path'];

                // Update OrganizationLicense if needed
                if (isset($order->meta['invoice_license_id'])) {
                    $orgLicense = \App\Models\OrganizationLicense::find($order->meta['invoice_license_id']);
                    if ($orgLicense && ! $orgLicense->invoice_file_path) {
                        $orgLicense->update(['invoice_file_path' => $invoiceFilePath]);
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to generate invoice on download', [
                    'order_id' => $order->id,
                    'invoice_number' => $invoiceNumber,
                    'error' => $e->getMessage(),
                ]);
                abort(500, 'Failed to generate invoice');
            }
        }

        // Final check
        if (! $invoiceFilePath || ! Storage::exists($invoiceFilePath)) {
            abort(404, 'Invoice file could not be generated');
        }

        // Track download
        AnalyticsService::log('invoice_download', [
            'user_id' => $request->user()->id,
            'order_id' => $order->id,
            'invoice_number' => $invoiceNumber,
        ]);

        // Return PDF file as download
        return response(Storage::get($invoiceFilePath))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$invoiceNumber.'.pdf"');
    }
}
