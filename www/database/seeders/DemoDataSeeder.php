<?php

namespace Database\Seeders;

use App\Models\AnalyticsEvent;
use App\Models\CreditLedger;
use App\Models\FileUpload;
use App\Models\License;
use App\Models\Order;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding demo data for Admin Dashboard...');

        // Seed licenses first (required)
        $this->call(LicenseSeeder::class);

        // Clear existing demo data
        $this->command->info('Clearing existing demo data...');
        User::where('email', 'like', 'demo.user.%')->delete();
        WorkflowExecution::where('error_message', 'Demo error message')->delete();
        FileUpload::where('original_filename', 'like', 'demo_file_%')->delete();
        CreditLedger::whereJsonContains('meta', ['seeded' => true])->delete();
        AnalyticsEvent::whereIn('event', ['page_view', 'file_upload', 'conversion_start', 'conversion_complete', 'download'])->delete();
        Order::whereIn('country', ['NL', 'US'])->where('payer_type', 'user')->delete();

        // Create some demo workflows if they don't exist
        $workflows = [
            ['name' => 'Word to PDF', 'credits_per_document' => 1],
            ['name' => 'Excel to PDF', 'credits_per_document' => 1],
            ['name' => 'PowerPoint to PDF', 'credits_per_document' => 1],
            ['name' => 'Image to PDF', 'credits_per_document' => 1],
            ['name' => 'Merge PDFs', 'credits_per_document' => 1],
        ];

        foreach ($workflows as $workflowData) {
            Workflow::firstOrCreate(
                ['name' => $workflowData['name']],
                [
                    'credits_per_document' => $workflowData['credits_per_document'],
                    'steps_json' => json_encode([]),
                    'is_default' => false,
                ]
            );
        }

        $workflowIds = Workflow::pluck('id')->toArray();
        $licenseIds = License::pluck('id')->toArray();

        // Create demo users (120-150 over last 90 days)
        $this->command->info('Creating demo users...');
        for ($i = 0; $i < 130; $i++) {
            $createdAt = Carbon::now()->subDays(rand(0, 90));

            User::create([
                'name' => 'Demo User '.($i + 1),
                'email' => "demo.user.{$i}@example.com",
                'email_verified_at' => $createdAt->copy()->addHours(rand(1, 24)),
                'password' => bcrypt('password'),
                'credits' => rand(0, 100),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'billing_country_code' => collect(['NL', 'DE', 'FR', 'BE', 'US', 'UK'])->random(),
                'currency_preference' => collect(['EUR', 'USD'])->random(),
            ]);
        }

        $userIds = User::where('email', 'like', 'demo.user.%')->pluck('id')->toArray();

        // Create workflow executions (600-900 with 70-78% success rate)
        $this->command->info('Creating workflow executions...');
        for ($i = 0; $i < 750; $i++) {
            $createdAt = Carbon::now()->subDays(rand(0, 90));
            $status = rand(1, 100) <= 75 ? 'done' : 'error'; // 75% success rate

            WorkflowExecution::create([
                'workflow_id' => collect($workflowIds)->random(),
                'user_id' => collect($userIds)->random(),
                'status' => $status,
                'result_path' => $status === 'done' ? 'results/demo_'.uniqid().'.pdf' : null,
                'error_message' => $status === 'error' ? 'Demo error message' : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt->copy()->addMinutes(rand(1, 30)),
            ]);
        }

        // Create file uploads (300-500)
        $this->command->info('Creating file uploads...');
        $extensions = ['docx', 'pdf', 'xlsx', 'pptx', 'jpg', 'png'];
        for ($i = 0; $i < 400; $i++) {
            $createdAt = Carbon::now()->subDays(rand(0, 90));
            $ext = collect($extensions)->random();

            FileUpload::create([
                'user_id' => collect($userIds)->random(),
                'original_filename' => "demo_file_{$i}.{$ext}",
                'stored_filename' => "stored_demo_file_{$i}.{$ext}",
                'file_extension' => $ext,
                'file_size_kb' => rand(50, 5000), // 50KB to 5MB
                'status' => collect(['uploaded', 'processed', 'failed'])->random(),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        // Create credit ledger entries (200-300 over 90 days)
        $this->command->info('Creating credit ledger entries...');
        $reasons = ['purchase', 'spend', 'refund'];
        for ($i = 0; $i < 250; $i++) {
            $createdAt = Carbon::now()->subDays(rand(0, 90));
            $reason = collect($reasons)->random();

            // Determine amount based on reason
            $amount = match ($reason) {
                'purchase', 'refund' => rand(10, 100),
                'spend' => -rand(1, 5),
                default => rand(1, 10)
            };

            CreditLedger::create([
                'user_id' => collect($userIds)->random(),
                'delta' => $amount,
                'reason' => $reason,
                'balance_after' => rand(50, 200), // Random balance after transaction
                'created_at' => $createdAt,
                'meta' => ['seeded' => true],
            ]);
        }

        // Create orders (60-120 paid orders over last 60-90 days)
        $this->command->info('Creating orders...');
        for ($i = 0; $i < 90; $i++) {
            $createdAt = Carbon::now()->subDays(rand(0, 90));
            $currency = collect(['EUR', 'USD'])->random();
            $grossAmount = rand(999, 9999) / 100; // €9.99 to €99.99
            $taxRate = $currency === 'EUR' ? 0.21 : 0.08; // 21% VAT for EUR, 8% for USD
            $taxAmount = round($grossAmount * $taxRate, 2);
            $netAmount = $grossAmount - $taxAmount;

            Order::create([
                'id' => fake()->uuid(),
                'payer_type' => 'user',
                'payer_id' => collect($userIds)->random(),
                'license_id' => collect($licenseIds)->random(),
                'type' => collect(['onetime', 'subscription'])->random(),
                'status' => collect(['paid', 'paid', 'paid', 'paid', 'failed'])->random(), // 80% paid
                'currency' => $currency,
                'gross_amount' => $grossAmount,
                'net_amount' => $netAmount,
                'tax_amount' => $taxAmount,
                'country' => $currency === 'EUR' ? 'NL' : 'US',
                'created_at' => $createdAt,
                'updated_at' => $createdAt->copy()->addMinutes(rand(1, 60)),
            ]);
        }

        // Create analytics events
        $this->command->info('Creating analytics events...');
        $events = ['page_view', 'file_upload', 'conversion_start', 'conversion_complete', 'download'];
        for ($i = 0; $i < 500; $i++) {
            $createdAt = Carbon::now()->subDays(rand(0, 90));

            AnalyticsEvent::create([
                'user_id' => rand(0, 10) === 0 ? null : collect($userIds)->random(), // 10% guest events
                'event' => collect($events)->random(),
                'created_at' => $createdAt,
            ]);
        }

        $this->command->info('Demo data seeding completed successfully!');
        $this->command->info('You can now access the Filament admin panel at /beheer with admin@example.com / admin123');
    }
}
