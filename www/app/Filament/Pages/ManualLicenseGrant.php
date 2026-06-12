<?php

namespace App\Filament\Pages;

use App\Models\License;
use App\Models\Organization;
use App\Models\OrganizationCreditLedger;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManualLicenseGrant extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Manual License Grant';

    protected static string $view = 'filament.pages.manual-license-grant';

    public ?string $grantType = 'user';

    public ?int $userId = null;

    public ?int $organizationId = null;

    public ?int $licenseId = null;

    public ?string $status = 'active';

    public ?string $startsAt = null;

    public ?string $endsAt = null;

    public ?string $source = 'admin_grant';

    public ?string $externalRef = null;

    public ?string $notes = null;

    public ?int $initialCredits = null;

    public function mount(): void
    {
        $this->startsAt = now()->format('Y-m-d');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Grant Type')
                    ->schema([
                        Forms\Components\Radio::make('grantType')
                            ->label('Grant License To')
                            ->options([
                                'user' => 'Individual User',
                                'organization' => 'Organization',
                            ])
                            ->default('user')
                            ->inline()
                            ->live(),
                    ]),

                Forms\Components\Section::make('Recipient')
                    ->schema([
                        Forms\Components\Select::make('userId')
                            ->label('User')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => User::where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->limit(50)
                                ->pluck('name', 'id'))
                            ->getOptionLabelUsing(fn ($value) => User::find($value)?->name)
                            ->visible(fn (Forms\Get $get) => $get('grantType') === 'user')
                            ->required(fn (Forms\Get $get) => $get('grantType') === 'user'),

                        Forms\Components\Select::make('organizationId')
                            ->label('Organization')
                            ->options(fn () => Organization::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->visible(fn (Forms\Get $get) => $get('grantType') === 'organization')
                            ->required(fn (Forms\Get $get) => $get('grantType') === 'organization'),
                    ]),

                Forms\Components\Section::make('License Details')
                    ->schema([
                        Forms\Components\Select::make('licenseId')
                            ->label('License')
                            ->options(fn () => License::active()->orderBy('name')->get()->mapWithKeys(fn ($license) => [
                                $license->id => "{$license->name} ({$license->tier}) — {$license->currency}",
                            ]))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, ?int $state) {
                                if ($state) {
                                    $license = License::find($state);
                                    if ($license) {
                                        $set('initialCredits', $license->credits);
                                        if ($license->period) {
                                            $startsAt = $get('startsAt') ? \Carbon\Carbon::parse($get('startsAt')) : now();
                                            $set('endsAt', $startsAt->addDays($license->period)->format('Y-m-d'));
                                        }
                                    }
                                }
                            }),

                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                'active' => 'Active',
                                'trial' => 'Trial',
                                'pending' => 'Pending',
                                'inactive' => 'Inactive',
                            ])
                            ->default('active')
                            ->required(),

                        Forms\Components\DatePicker::make('startsAt')
                            ->label('Starts At')
                            ->default(now())
                            ->required(),

                        Forms\Components\DatePicker::make('endsAt')
                            ->label('Ends At')
                            ->helperText('Leave empty for no expiration'),

                        Forms\Components\TextInput::make('initialCredits')
                            ->label('Initial Credits')
                            ->numeric()
                            ->helperText('Credits to grant immediately (defaults to license monthly credits)'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Administrative')
                    ->schema([
                        Forms\Components\Select::make('source')
                            ->label('Source')
                            ->options([
                                'admin_grant' => 'Admin Grant',
                                'promotional' => 'Promotional',
                                'partner' => 'Partner Deal',
                                'compensation' => 'Compensation',
                                'trial_extension' => 'Trial Extension',
                                'other' => 'Other',
                            ])
                            ->default('admin_grant')
                            ->required(),

                        Forms\Components\TextInput::make('externalRef')
                            ->label('External Reference')
                            ->helperText('Optional: ticket number, deal ID, etc.'),

                        Forms\Components\Textarea::make('notes')
                            ->label('Internal Notes')
                            ->rows(3)
                            ->helperText('Only visible to admins'),
                    ])
                    ->columns(2),
            ]);
    }

    public function grantLicense(): void
    {
        $data = $this->form->getState();

        try {
            if ($this->grantType === 'user') {
                $this->grantUserLicense();
            } else {
                $this->grantOrganizationLicense();
            }

            Notification::make()
                ->title('License granted successfully')
                ->success()
                ->send();

            // Reset form
            $this->reset(['userId', 'organizationId', 'licenseId', 'externalRef', 'notes', 'initialCredits']);
            $this->status = 'active';
            $this->source = 'admin_grant';
            $this->startsAt = now()->format('Y-m-d');
            $this->endsAt = null;

        } catch (\Exception $e) {
            Log::error('Manual license grant failed', [
                'error' => $e->getMessage(),
                'grant_type' => $this->grantType,
                'user_id' => $this->userId,
                'organization_id' => $this->organizationId,
                'license_id' => $this->licenseId,
                'admin_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Failed to grant license')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function grantUserLicense(): void
    {
        $user = User::findOrFail($this->userId);
        $license = License::findOrFail($this->licenseId);

        DB::transaction(function () use ($user, $license) {
            // Deactivate existing current licenses for this user
            UserLicense::where('user_id', $user->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            // Create new license
            $userLicense = UserLicense::create([
                'user_id' => $user->id,
                'license_id' => $license->id,
                'status' => $this->status,
                'starts_at' => $this->startsAt,
                'ends_at' => $this->endsAt,
                'source' => $this->source,
                'external_ref' => $this->externalRef,
                'is_current' => true,
                'last_credit_reset_at' => now(),
            ]);

            // Grant initial credits if specified
            if ($this->initialCredits && $this->initialCredits > 0) {
                $user->increment('credits', $this->initialCredits);
                $user->refresh();

                // Log the credit grant
                \App\Models\CreditLedger::create([
                    'user_id' => $user->id,
                    'delta' => $this->initialCredits,
                    'reason' => 'bonus',
                    'balance_after' => $user->credits,
                    'meta' => [
                        'source' => 'manual_license_grant',
                        'license_id' => $license->id,
                        'license_assignment_id' => $userLicense->id,
                        'admin_id' => auth()->id(),
                        'notes' => $this->notes,
                    ],
                ]);
            }

            Log::info('Manual user license granted', [
                'user_id' => $user->id,
                'license_id' => $license->id,
                'user_license_id' => $userLicense->id,
                'credits_granted' => $this->initialCredits,
                'admin_id' => auth()->id(),
                'source' => $this->source,
            ]);
        });
    }

    protected function grantOrganizationLicense(): void
    {
        $organization = Organization::findOrFail($this->organizationId);
        $license = License::findOrFail($this->licenseId);

        DB::transaction(function () use ($organization, $license) {
            // Deactivate existing current licenses for this organization
            OrganizationLicense::where('organization_id', $organization->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            // Create new license
            $orgLicense = OrganizationLicense::create([
                'organization_id' => $organization->id,
                'license_id' => $license->id,
                'status' => $this->status,
                'starts_at' => $this->startsAt,
                'ends_at' => $this->endsAt,
                'source' => $this->source,
                'external_ref' => $this->externalRef,
                'is_current' => true,
                'last_credit_reset_at' => now(),
                'billing_method' => 'manual',
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);

            // Grant initial credits if specified
            if ($this->initialCredits && $this->initialCredits > 0) {
                $pool = $organization->creditPool;
                if ($pool) {
                    $pool->increment('balance_credits', $this->initialCredits);
                    $pool->refresh();
                } else {
                    $pool = $organization->creditPool()->create([
                        'organization_id' => $organization->id,
                        'balance_credits' => $this->initialCredits,
                        'updated_at' => now(),
                    ]);
                }

                // Log the credit grant in the organization ledger
                OrganizationCreditLedger::create([
                    'organization_id' => $organization->id,
                    'user_id' => auth()->id(),
                    'delta' => $this->initialCredits,
                    'reason' => 'bonus',
                    'balance_after' => $pool->balance_credits,
                    'meta' => [
                        'source' => 'manual_license_grant',
                        'license_id' => $license->id,
                        'license_assignment_id' => $orgLicense->id,
                        'admin_id' => auth()->id(),
                        'notes' => $this->notes,
                    ],
                ]);
            }

            Log::info('Manual organization license granted', [
                'organization_id' => $organization->id,
                'license_id' => $license->id,
                'org_license_id' => $orgLicense->id,
                'credits_granted' => $this->initialCredits,
                'admin_id' => auth()->id(),
                'source' => $this->source,
            ]);
        });
    }
}
